<?php
/**
 * PenaltyService - Wattipid Daily Penalty & Overdue Management System
 * 
 * Enforces authoritative billing and overdue policy:
 * - When due date passes, unpaid/partially paid bills become OVERDUE
 * - Applies deterministic daily percentage penalty based on original outstanding balance
 * - Guarantees idempotency (locks, daily date stamp, DB unique constraint)
 * - Safe against missing optional columns and null references
 */

require_once __DIR__ . '/../utils/email_service.php';
require_once __DIR__ . '/../utils/QueueService.php';

class PenaltyService {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getPenaltySettings() {
        try {
            $stmt = $this->conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'penalty_%' OR setting_key = 'maximum_penalty_percent' OR setting_key = 'maximum_penalty_limit'");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        } catch (Throwable $e) {
            error_log("[PenaltyService] getPenaltySettings error: " . $e->getMessage());
            return [];
        }
    }

    public function updatePenaltySettings($data, $adminId) {
        try {
            $this->conn->beginTransaction();
            $oldSettings = $this->getPenaltySettings();

            $updateStmt = $this->conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            
            $keys = ['penalty_grace_period_days', 'penalty_type', 'penalty_rate', 'penalty_fixed_amount', 'maximum_penalty_percent', 'maximum_penalty_limit'];
            foreach ($keys as $key) {
                if (isset($data[$key])) {
                    $updateStmt->execute([$data[$key], $key]);
                    
                    // Audit log
                    try {
                        $auditStmt = $this->conn->prepare("INSERT INTO financial_audit_logs (actor_id, actor_role, action_type, table_affected, record_id, old_value, new_value) VALUES (?, 'admin', 'update_penalty_setting', 'settings', 0, ?, ?)");
                        $auditStmt->execute([$adminId, $oldSettings[$key] ?? '', $data[$key]]);
                    } catch (Throwable $auditErr) {}
                }
            }
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Penalty settings updated successfully'];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Failed to update settings: ' . $e->getMessage()];
        }
    }

    /**
     * Core penalty calculation - runs once daily via lazy evaluation or cron.
     * 
     * Idempotent & Concurrency-Safe:
     * - MySQL Advisory Named Lock ensures only 1 execution at any time
     * - Fast pre-check prevents redundant daily runs
     * - Database UNIQUE (billing_cycle_id, penalty_date) prevents duplicate inserts
     * - Deterministic balance assignment in billing_cycles
     * - Decoupled post-commit email & push notification dispatch
     */
    public function calculateDailyPenalties($force = false) {
        $lockAcquired = false;
        try {
            // 1. Concurrency Mutex (Advisory Lock)
            $lockStmt = $this->conn->query("SELECT GET_LOCK('wattipid_daily_penalty_lock', 0)");
            $lockAcquired = ($lockStmt && (int)$lockStmt->fetchColumn() === 1);
            if (!$lockAcquired) {
                return ['success' => true, 'message' => 'Penalty calculation already running in another process.', 'count' => 0];
            }

            $settings = $this->getPenaltySettings();
            $today = date('Y-m-d');
            
            // 2. Fast Pre-Check: Run once per calendar day unless explicitly forced
            if (!$force) {
                $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'last_penalty_run_date'");
                $stmt->execute();
                $lastRun = $stmt->fetchColumn();
                if ($lastRun === $today) {
                    return ['success' => true, 'message' => 'Penalties already calculated today.', 'count' => 0];
                }
            }

            $penaltyRate = (float)($settings['penalty_rate'] ?? 2.00); 
            $maxPenalty = (float)($settings['maximum_penalty_limit'] ?? 1000.00);
            $autoEmail = (int)($settings['auto_email_penalties'] ?? 1);
            $autoPush = (int)($settings['auto_push_penalties'] ?? 1);

            $this->conn->beginTransaction();

            // Query overdue, unpaid completed billing cycles past due date
            $sql = "SELECT bc.*, u.id as user_id, u.email as tenant_email,
                           COALESCE(u.name, r.tenant_name, bc.tenant_name, 'Tenant') as resolved_tenant_name
                    FROM billing_cycles bc 
                    LEFT JOIN rooms r ON r.room_id = bc.room_id
                    LEFT JOIN users u ON u.room_id = bc.room_id AND u.role = 'tenant'
                    WHERE bc.payment_status IN ('unpaid', 'partially_paid', 'overdue')
                    AND bc.due_date IS NOT NULL 
                    AND bc.due_date < NOW()
                    AND bc.status = 'completed'
                    FOR UPDATE";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $overdueCycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $penaltiesApplied = 0;
            $notificationsToSend = [];

            $checkExistingStmt = $this->conn->prepare(
                "SELECT id FROM penalty_history WHERE billing_cycle_id = ? AND penalty_date = ?"
            );

            $updateCycleStmt = $this->conn->prepare(
                "UPDATE billing_cycles 
                 SET payment_status = 'overdue', 
                     penalty_amount = ?, 
                     grand_total = ? 
                 WHERE id = ?"
            );

            $logStmt = $this->conn->prepare(
                "INSERT INTO penalty_history (
                    billing_cycle_id, room_id, tenant_id, tenant_name, original_balance, 
                    penalty_amount, penalty_type, created_at, penalty_date, days_overdue, 
                    penalty_rate, running_total_penalty, current_outstanding_balance
                ) VALUES (?, ?, ?, ?, ?, ?, 'percentage_daily', NOW(), ?, ?, ?, ?, ?)"
            );

            foreach ($overdueCycles as $cycle) {
                $cycleId = $cycle['id'];

                // 3. IDEMPOTENCY CHECK: Did we already generate a penalty for this bill today?
                $checkExistingStmt->execute([$cycleId, $today]);
                if ($checkExistingStmt->fetchColumn()) {
                    continue; // Skip - already penalized today
                }

                // Ensure exact days calculation
                $daysOverdueStmt = $this->conn->prepare("SELECT DATEDIFF(DATE(NOW()), DATE(?)) as days");
                $daysOverdueStmt->execute([$cycle['due_date']]);
                $daysOverdue = max(1, (int)$daysOverdueStmt->fetchColumn());

                // Base original balance before any penalties: standalone charges excluding previous_balance
                $cElec = (float)($cycle['electricity_charge'] ?? $cycle['total_cost'] ?? 0);
                $cMisc = (float)($cycle['miscellaneous_fee'] ?? 0);
                $cRent = (float)($cycle['monthly_rent'] ?? 0);
                $cAdd = (float)($cycle['additional_charges'] ?? 0);
                $cDisc = (float)($cycle['discounts'] ?? 0);
                $cPaid = (float)($cycle['amount_paid'] ?? 0);
                $cPrevBal = (float)($cycle['previous_balance'] ?? 0);
                $cycleCurrentPenalty = (float)($cycle['penalty_amount'] ?? 0);

                // Standalone invoice base amount (strictly excludes previous_balance to avoid compounding penalties)
                $standaloneBase = max(0.00, round($cElec + $cMisc + $cRent + $cAdd - $cDisc, 2));

                // The original balance of THIS individual invoice subject to daily penalty
                $originalBalance = max(0.00, round($standaloneBase - $cPaid, 2));

                // If original bill was already fully settled, skip penalty application
                if ($originalBalance <= 0.00) {
                    continue;
                }

                // Deterministic formula: (Original * Rate) rounded, THEN multiplied by Days
                $dailyPenaltyAmount = round($originalBalance * ($penaltyRate / 100), 2);
                $expectedTotalPenalty = $dailyPenaltyAmount * $daysOverdue;

                if ($maxPenalty > 0 && $expectedTotalPenalty > $maxPenalty) {
                    $expectedTotalPenalty = $maxPenalty;
                }

                $difference = round($expectedTotalPenalty - $cycleCurrentPenalty, 2);

                if ($difference > 0) {
                    $newTotalPenalty = $expectedTotalPenalty;
                    // Invoice total due for this cycle specifically:
                    $invoiceTotalDue = round($standaloneBase + $newTotalPenalty - $cPaid, 2);
                    // Grand total on invoice (incorporates previous_balance for billing statement presentation):
                    $newGrandTotal = round($standaloneBase + $cPrevBal + $newTotalPenalty, 2);

                    // Idempotent deterministic update
                    $updateCycleStmt->execute([$newTotalPenalty, $newGrandTotal, $cycleId]);

                    $tenantDisplayName = $cycle['resolved_tenant_name'] ?: 'Tenant';

                    try {
                        $logStmt->execute([
                            $cycleId, 
                            $cycle['room_id'], 
                            $cycle['user_id'] ?? null,
                            $tenantDisplayName, 
                            $originalBalance, 
                            $difference, 
                            $today,
                            $daysOverdue,
                            $penaltyRate,
                            $newTotalPenalty,
                            $invoiceTotalDue
                        ]);
                        $penaltiesApplied++;

                        $notificationsToSend[] = [
                            'user_id' => $cycle['user_id'] ?? null,
                            'tenant_email' => $cycle['tenant_email'] ?? '',
                            'tenant_name' => $tenantDisplayName,
                            'room_id' => $cycle['room_id'],
                            'cycle_id' => $cycleId,
                            'original_balance' => $originalBalance,
                            'daily_penalty' => $dailyPenaltyAmount,
                            'difference' => $difference,
                            'total_penalty' => $newTotalPenalty,
                            'total_outstanding' => $invoiceTotalDue,
                            'days_overdue' => $daysOverdue
                        ];
                    } catch (PDOException $dupEx) {
                        // Unique constraint caught duplicate insert attempt; safe to ignore
                        if ($dupEx->getCode() != '23000' && ($dupEx->errorInfo[1] ?? 0) != 1062) {
                            throw $dupEx;
                        }
                    }
                }
            }

            // Always stamp last_penalty_run_date inside the transaction
            $this->conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'last_penalty_run_date'")->execute([$today]);

            $this->conn->commit();

            // 4. Post-Commit Network I/O (Email & Push Notifications)
            foreach ($notificationsToSend as $notif) {
                if ($autoEmail && !empty($notif['tenant_email'])) {
                    try {
                        $penaltySubject = "Daily Penalty Notice - Wattipid Account";
                        $penaltyBody = $this->getPenaltyEmailTemplate(
                            $notif['tenant_name'],
                            $notif['room_id'],
                            $notif['original_balance'],
                            $notif['total_outstanding'],
                            $notif['daily_penalty'],
                            $notif['total_penalty'],
                            $notif['days_overdue']
                        );
                        sendEmail(
                            $notif['tenant_email'],
                            $notif['tenant_name'],
                            $penaltySubject,
                            $penaltyBody,
                            '',
                            'penalty_notice'
                        );
                    } catch (Throwable $emailEx) {
                        error_log("Failed to send penalty email: " . $emailEx->getMessage());
                    }
                }

                if ($autoPush && !empty($notif['user_id'])) {
                    try {
                        $notifStmt = $this->conn->prepare(
                            "INSERT INTO notification_history (user_id, room_id, type, category, severity, title, message, data_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $notifStmt->execute([
                            $notif['user_id'],
                            $notif['room_id'],
                            'penalty_applied',
                            'penalty',
                            'critical',
                            '⚠️ Daily Penalty Applied',
                            "Your bill remains overdue by {$notif['days_overdue']} days. A daily penalty of ₱" . number_format($notif['daily_penalty'], 2) . " was added. Total outstanding: ₱" . number_format($notif['total_outstanding'], 2) . ".",
                            json_encode([
                                'billing_cycle_id' => $notif['cycle_id'],
                                'original_amount' => $notif['original_balance'],
                                'daily_penalty' => $notif['daily_penalty'],
                                'total_penalty' => $notif['total_penalty'],
                                'total_outstanding' => $notif['total_outstanding'],
                                'days_overdue' => $notif['days_overdue']
                            ])
                        ]);

                        if (file_exists(__DIR__ . '/../utils/notification_engine.php')) {
                            require_once __DIR__ . '/../utils/notification_engine.php';
                            if (class_exists('NotificationEngine')) {
                                $notifEngine = new NotificationEngine($this->conn);
                                $notifEngine->sendPushNotification($notif['user_id'], [
                                    'title' => '⚠️ Daily Penalty Applied',
                                    'message' => "Your bill is overdue by {$notif['days_overdue']} days. A daily penalty was applied. Total: ₱" . number_format($notif['total_outstanding'], 2)
                                ]);
                            }
                        }
                    } catch (Throwable $pushEx) {
                        error_log("Failed to send penalty push: " . $pushEx->getMessage());
                    }
                }
            }

            return ['success' => true, 'message' => "Successfully evaluated daily penalties ($penaltiesApplied applied).", 'count' => $penaltiesApplied];

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("[PenaltyService] calculateDailyPenalties error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to calculate penalties: ' . $e->getMessage()];
        } finally {
            if ($lockAcquired) {
                try {
                    $this->conn->query("SELECT RELEASE_LOCK('wattipid_daily_penalty_lock')");
                } catch (Throwable $t) {}
            }
        }
    }

    public function waivePenalty($billingCycleId) {
        try {
            $stmt = $this->conn->prepare("UPDATE billing_cycles SET penalty_amount = 0 WHERE id = ? AND payment_status = 'overdue'");
            $stmt->execute([$billingCycleId]);
            if ($stmt->rowCount() > 0) {
                return ["success" => true, "message" => "Penalty waived successfully."];
            }
            return ["success" => false, "message" => "Billing cycle not found or not overdue."];
        } catch (Throwable $e) {
            return ["success" => false, "message" => "Database error: " . $e->getMessage()];
        }
    }

    public function getOverdueAccounts() {
        try {
            $sql = "SELECT b.id, b.room_id, b.invoice_number,
                           COALESCE(u.name, r.tenant_name, b.tenant_name, 'Tenant') as tenant_name,
                           b.due_date,
                           b.previous_balance,
                           COALESCE(b.amount_paid, 0.00) as amount_paid,
                           COALESCE(b.penalty_amount, 0.00) as penalty_amount,
                           GREATEST(0.00, (COALESCE(b.electricity_charge, b.total_cost, 0.00) + COALESCE(b.miscellaneous_fee, 0.00) + COALESCE(b.monthly_rent, 0.00) + COALESCE(b.additional_charges, 0.00) - COALESCE(b.discounts, 0.00)) - COALESCE(b.amount_paid, 0.00)) as original_balance,
                           GREATEST(0.00, (COALESCE(b.electricity_charge, b.total_cost, 0.00) + COALESCE(b.miscellaneous_fee, 0.00) + COALESCE(b.monthly_rent, 0.00) + COALESCE(b.additional_charges, 0.00) - COALESCE(b.discounts, 0.00) + COALESCE(b.penalty_amount, 0.00)) - COALESCE(b.amount_paid, 0.00)) as total_amount_due,
                           DATEDIFF(NOW(), b.due_date) as days_overdue
                    FROM billing_cycles b 
                    LEFT JOIN rooms r ON b.room_id = r.room_id
                    LEFT JOIN users u ON u.room_id = b.room_id AND u.role = 'tenant'
                    WHERE b.payment_status IN ('unpaid', 'partially_paid', 'overdue') 
                      AND b.due_date IS NOT NULL
                      AND b.due_date < NOW()
                      AND b.status = 'completed'
                      AND ((COALESCE(b.electricity_charge, b.total_cost, 0.00) + COALESCE(b.miscellaneous_fee, 0.00) + COALESCE(b.monthly_rent, 0.00) + COALESCE(b.additional_charges, 0.00) - COALESCE(b.discounts, 0.00) + COALESCE(b.penalty_amount, 0.00)) - COALESCE(b.amount_paid, 0.00)) > 0.00
                    ORDER BY days_overdue DESC";
            $stmt = $this->conn->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[PenaltyService] getOverdueAccounts error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getPenaltyAnalytics() {
        try {
            $sqlTotalOverdue = "SELECT COUNT(*) FROM billing_cycles WHERE payment_status IN ('unpaid', 'partially_paid', 'overdue') AND due_date IS NOT NULL AND due_date < NOW() AND status = 'completed' AND ((COALESCE(electricity_charge, total_cost, 0.00) + COALESCE(miscellaneous_fee, 0.00) + COALESCE(monthly_rent, 0.00) + COALESCE(additional_charges, 0.00) - COALESCE(discounts, 0.00) + COALESCE(penalty_amount, 0.00)) - COALESCE(amount_paid, 0.00)) > 0.00";
            $sqlTotalPenalty = "SELECT COALESCE(SUM(penalty_amount), 0) FROM billing_cycles WHERE payment_status IN ('unpaid', 'partially_paid', 'overdue') AND due_date IS NOT NULL AND due_date < NOW() AND status = 'completed'";
            $sqlTotalOutstanding = "SELECT COALESCE(SUM(GREATEST(0.00, (COALESCE(electricity_charge, total_cost, 0.00) + COALESCE(miscellaneous_fee, 0.00) + COALESCE(monthly_rent, 0.00) + COALESCE(additional_charges, 0.00) - COALESCE(discounts, 0.00) + COALESCE(penalty_amount, 0.00)) - COALESCE(amount_paid, 0.00))), 0) FROM billing_cycles WHERE payment_status IN ('unpaid', 'partially_paid', 'overdue') AND due_date IS NOT NULL AND due_date < NOW() AND status = 'completed'";
            $sqlDueToday = "SELECT COUNT(*) FROM billing_cycles WHERE payment_status IN ('unpaid', 'partially_paid') AND DATE(due_date) = CURDATE() AND status = 'completed'";
            $sqlDueTomorrow = "SELECT COUNT(*) FROM billing_cycles WHERE payment_status IN ('unpaid', 'partially_paid') AND DATE(due_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND status = 'completed'";
            $sqlPenaltiesCollected = "SELECT COALESCE(SUM(penalty_amount), 0) FROM billing_cycles WHERE penalty_amount > 0";
            
            return [
                'totalOverdueAccounts' => (int)$this->conn->query($sqlTotalOverdue)->fetchColumn(),
                'totalActivePenalties' => (float)$this->conn->query($sqlTotalPenalty)->fetchColumn(),
                'totalOutstandingBalance' => (float)$this->conn->query($sqlTotalOutstanding)->fetchColumn(),
                'billsDueToday' => (int)$this->conn->query($sqlDueToday)->fetchColumn(),
                'billsDueTomorrow' => (int)$this->conn->query($sqlDueTomorrow)->fetchColumn(),
                'totalPenaltiesCollected' => (float)$this->conn->query($sqlPenaltiesCollected)->fetchColumn(),
            ];
        } catch (Throwable $e) {
            error_log("[PenaltyService] getPenaltyAnalytics error: " . $e->getMessage());
            return [
                'totalOverdueAccounts' => 0,
                'totalActivePenalties' => 0.00,
                'totalOutstandingBalance' => 0.00,
                'billsDueToday' => 0,
                'billsDueTomorrow' => 0,
                'totalPenaltiesCollected' => 0.00,
            ];
        }
    }

    public function getRecentActivity($limit = 50) {
        try {
            $limit = max(1, min(100, (int)$limit));
            $sql = "SELECT ph.id, ph.billing_cycle_id, ph.room_id, bc.invoice_number,
                           COALESCE(ph.tenant_name, u.name, r.tenant_name, 'Tenant') as tenant_name,
                           ph.penalty_amount, 
                           ph.penalty_type, 
                           COALESCE(ph.penalty_date, DATE(ph.created_at)) as penalty_date, 
                           ph.created_at, 
                           COALESCE(ph.days_overdue, 1) as days_overdue,
                           COALESCE(ph.running_total_penalty, ph.penalty_amount) as running_total_penalty, 
                           COALESCE(ph.current_outstanding_balance, 0.00) as current_outstanding_balance
                    FROM penalty_history ph
                    LEFT JOIN billing_cycles bc ON bc.id = ph.billing_cycle_id
                    LEFT JOIN rooms r ON ph.room_id = r.room_id
                    LEFT JOIN users u ON u.room_id = ph.room_id AND u.role = 'tenant'
                    WHERE bc.payment_status IN ('unpaid', 'partially_paid', 'overdue')
                    ORDER BY ph.id DESC 
                    LIMIT $limit";
            $stmt = $this->conn->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[PenaltyService] getRecentActivity error: " . $e->getMessage());
            return [];
        }
    }

    // =========================================================
    // EMAIL TEMPLATES
    // =========================================================

    private function getOverdueEmailTemplate($tenantName, $roomId, $dueDateStr, $amount) {
        $fmtAmount = number_format($amount, 2);
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background-color:#0a0f1a; font-family: sans-serif;">
    <table width="100%" style="background-color:#0a0f1a; padding:40px 20px;">
        <tr><td align="center">
            <table width="100%" style="max-width:480px; background:#111827; border-radius:16px; border:1px solid #EF4444;">
                <tr><td style="padding:32px; text-align:center;">
                    <div style="font-size:48px; margin-bottom:16px;">🚨</div>
                    <h1 style="color:#EF4444; font-size:22px; margin-bottom:8px;">Overdue Notice</h1>
                    <p style="color:#9ca3af; font-size:14px;">Hi {$tenantName},</p>
                    <p style="color:#9ca3af; font-size:14px;">Your electricity bill for <strong style="color:#fff;">{$roomId}</strong> was due on <strong style="color:#EF4444;">{$dueDateStr}</strong> and has not been settled.</p>
                    <div style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:12px; padding:20px; margin:20px 0;">
                        <div style="color:#9ca3af; font-size:12px; text-transform:uppercase; letter-spacing:1px;">Outstanding Amount</div>
                        <div style="color:#EF4444; font-size:32px; font-weight:bold; margin-top:8px;">₱{$fmtAmount}</div>
                    </div>
                    <p style="color:#F59E0B; font-size:13px;">⚠️ A penalty will be applied to your account. Please settle your balance immediately.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
HTML;
    }

    private function getPenaltyEmailTemplate($tenantName, $roomId, $originalAmount, $currentBalance, $penaltyToday, $totalPenalty, $daysOverdue) {
        $fmtOriginal = number_format($originalAmount, 2);
        $fmtCurrent = number_format($currentBalance, 2);
        $fmtPenaltyToday = number_format($penaltyToday, 2);
        $fmtTotalPenalty = number_format($totalPenalty, 2);
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background-color:#0a0f1a; font-family: sans-serif;">
    <table width="100%" style="background-color:#0a0f1a; padding:40px 20px;">
        <tr><td align="center">
            <table width="100%" style="max-width:480px; background:#111827; border-radius:16px; border:1px solid #EF4444;">
                <tr><td style="padding:32px; text-align:center;">
                    <div style="font-size:48px; margin-bottom:16px;">⚠️</div>
                    <h1 style="color:#EF4444; font-size:22px; margin-bottom:8px;">Daily Penalty Notice</h1>
                    <p style="color:#9ca3af; font-size:14px;">Hi {$tenantName},</p>
                    <p style="color:#9ca3af; font-size:14px;">Your electricity bill remains unpaid for <strong style="color:#EF4444;">{$daysOverdue} days</strong>. A daily penalty has been added to your account.</p>
                    <div style="background:rgba(239,68,68,0.05); border:1px solid rgba(239,68,68,0.2); border-radius:12px; padding:20px; margin:20px 0; text-align:left;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:12px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:12px;">
                            <span style="color:#9ca3af; font-size:13px;">Original Amount</span>
                            <span style="color:#fff; font-size:14px; font-weight:600;">₱{$fmtOriginal}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:12px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:12px;">
                            <span style="color:#EF4444; font-size:13px;">Penalty Added Today</span>
                            <span style="color:#EF4444; font-size:14px; font-weight:700;">+ ₱{$fmtPenaltyToday}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:12px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:12px;">
                            <span style="color:#9ca3af; font-size:13px;">Total Penalty Accumulated</span>
                            <span style="color:#EF4444; font-size:14px; font-weight:700;">₱{$fmtTotalPenalty}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#fff; font-size:14px; font-weight:700;">Current Balance</span>
                            <span style="color:#EF4444; font-size:18px; font-weight:800;">₱{$fmtCurrent}</span>
                        </div>
                    </div>
                    <p style="color:#9ca3af; font-size:13px;">Please settle your balance immediately to avoid additional daily charges.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
HTML;
    }
}
