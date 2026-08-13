<?php
/**
 * PenaltyService - 3-Day Billing Policy Enforcement
 * 
 * Enforces a strict 3-day payment window. After the due date passes (12:00 AM),
 * the system automatically marks bills as OVERDUE, applies a one-time flat penalty,
 * and triggers notification emails.
 */

require_once __DIR__ . '/../utils/email_service.php';
require_once __DIR__ . '/../utils/QueueService.php';

class PenaltyService {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getPenaltySettings() {
        $stmt = $this->conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'penalty_%' OR setting_key = 'maximum_penalty_percent'");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function updatePenaltySettings($data, $adminId) {
        try {
            $this->conn->beginTransaction();
            $oldSettings = $this->getPenaltySettings();

            $updateStmt = $this->conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            
            $keys = ['penalty_grace_period_days', 'penalty_type', 'penalty_rate', 'penalty_fixed_amount', 'maximum_penalty_percent'];
            foreach ($keys as $key) {
                if (isset($data[$key])) {
                    $updateStmt->execute([$data[$key], $key]);
                    
                    // Audit log
                    $auditStmt = $this->conn->prepare("INSERT INTO financial_audit_logs (actor_id, actor_role, action_type, table_affected, record_id, old_value, new_value) VALUES (?, 'admin', 'update_penalty_setting', 'settings', 0, ?, ?)");
                    $auditStmt->execute([$adminId, $oldSettings[$key] ?? '', $data[$key]]);
                }
            }
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Penalty settings updated successfully'];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Failed to update settings: ' . $e->getMessage()];
        }
    }

    /**
     * Core penalty calculation - runs once daily via lazy evaluation.
     * 
     * NEW POLICY:
     * - No grace period after due date
     * - Penalty activates at 12:00 AM the day after due date
     * - One-time flat penalty (configurable %, default 2%)
     * - Updates billing status, grand_total, and triggers notifications
     */
    public function calculateDailyPenalties($force = false) {
        $settings = $this->getPenaltySettings();
        $today = date('Y-m-d');
        
        if (!$force) {
            $stmt = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'last_penalty_run_date'");
            $lastRun = $stmt->fetchColumn();
            if ($lastRun === $today) {
                return ['success' => true, 'message' => 'Penalties already calculated today.', 'count' => 0];
            }
        }

        $penaltyRate = (float)($settings['penalty_rate'] ?? 2.00); 
        $maxPenalty = (float)($settings['maximum_penalty_limit'] ?? 1000.00);
        $autoEmail = (int)($settings['auto_email_penalties'] ?? 1);
        $autoPush = (int)($settings['auto_push_penalties'] ?? 1);

        try {
            $this->conn->beginTransaction();

            $sql = "SELECT bc.*, u.id as user_id, u.email as tenant_email 
                    FROM billing_cycles bc 
                    LEFT JOIN users u ON u.room_id = bc.room_id AND u.role = 'tenant'
                    WHERE bc.payment_status IN ('unpaid', 'partially_paid', 'overdue')
                    AND bc.due_date IS NOT NULL 
                    AND bc.due_date < NOW()
                    AND bc.status = 'completed'";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $overdueCycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $penaltiesApplied = 0;
            $updateCycleStmt = $this->conn->prepare(
                "UPDATE billing_cycles SET payment_status = 'overdue', penalty_amount = penalty_amount + ?, grand_total = grand_total + ? WHERE id = ?"
            );
            $logStmt = $this->conn->prepare(
                "INSERT INTO penalty_history (billing_cycle_id, room_id, tenant_id, tenant_name, original_balance, penalty_amount, penalty_type, days_overdue, penalty_rate, running_total_penalty, current_outstanding_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $queue = new QueueService($this->conn);

            foreach ($overdueCycles as $cycle) {
                // Ensure exact days calculation (truncating time)
                $daysOverdueStmt = $this->conn->prepare("SELECT DATEDIFF(DATE(NOW()), DATE(?)) as days");
                $daysOverdueStmt->execute([$cycle['due_date']]);
                $daysOverdue = max(1, (int)$daysOverdueStmt->fetchColumn());

                $originalBalance = (float)$cycle['total_cost'];
                
                // Deterministic formula: (Original * Rate) rounded, THEN multiplied by Days
                // This guarantees UI consistency: Daily Penalty * Days Overdue = Total Penalty
                $dailyPenaltyAmount = round($originalBalance * ($penaltyRate / 100), 2);
                $expectedTotalPenalty = $dailyPenaltyAmount * $daysOverdue;
                $currentPenaltyAccumulated = (float)$cycle['penalty_amount'];

                if ($maxPenalty > 0 && $expectedTotalPenalty > $maxPenalty) {
                    $expectedTotalPenalty = $maxPenalty;
                }

                $difference = round($expectedTotalPenalty - $currentPenaltyAccumulated, 2);

                if ($difference > 0) {
                    $updateCycleStmt->execute([$difference, $difference, $cycle['id']]);
                    
                    $newTotalPenalty = $expectedTotalPenalty;
                    $totalOutstanding = $originalBalance + $newTotalPenalty;

                    $logStmt->execute([
                        $cycle['id'], 
                        $cycle['room_id'], 
                        $cycle['user_id'] ?? null,
                        $cycle['tenant_name'], 
                        $originalBalance, 
                        $difference, 
                        'percentage_daily',
                        $daysOverdue,
                        $penaltyRate,
                        $newTotalPenalty,
                        $totalOutstanding
                    ]);
                    $penaltiesApplied++;

                    if ($autoEmail && !empty($cycle['tenant_email'])) {
                        $penaltySubject = "Daily Penalty Notice - Wattipid Account";
                        $penaltyBody = $this->getPenaltyEmailTemplate(
                            $cycle['tenant_name'] ?? 'Tenant',
                            $cycle['room_id'],
                            $originalBalance,
                            $totalOutstanding,
                            $dailyPenaltyAmount,
                            $newTotalPenalty,
                            $daysOverdue
                        );
                        $queue->push('email', [
                            'to' => $cycle['tenant_email'],
                            'name' => $cycle['tenant_name'] ?? '',
                            'subject' => $penaltySubject,
                            'htmlBody' => $penaltyBody,
                            'textBody' => ''
                        ]);
                    }

                    if ($autoPush && !empty($cycle['user_id'])) {
                        $notifStmt = $this->conn->prepare(
                            "INSERT INTO notification_history (user_id, room_id, type, category, severity, title, message, data_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $notifStmt->execute([
                            $cycle['user_id'],
                            $cycle['room_id'],
                            'penalty_applied',
                            'penalty',
                            'critical',
                            '⚠️ Daily Penalty Applied',
                            "Your bill remains overdue by $daysOverdue days. A daily penalty of ₱" . number_format($dailyPenaltyAmount, 2) . " was added. Total outstanding: ₱" . number_format($totalOutstanding, 2) . ".",
                            json_encode([
                                'billing_cycle_id' => $cycle['id'],
                                'original_amount' => $originalBalance,
                                'daily_penalty' => $dailyPenaltyAmount,
                                'total_penalty' => $newTotalPenalty,
                                'total_outstanding' => $totalOutstanding,
                                'days_overdue' => $daysOverdue
                            ])
                        ]);
                    }
                }
            }

            if (!$force) {
                $this->conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'last_penalty_run_date'")->execute([$today]);
            }

            $this->conn->commit();
            return ['success' => true, 'message' => "Successfully applied daily penalties to $penaltiesApplied accounts.", 'count' => $penaltiesApplied];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Failed to calculate penalties: ' . $e->getMessage()];
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
        } catch (PDOException $e) {
            return ["success" => false, "message" => "Database error: " . $e->getMessage()];
        }
    }

    public function getOverdueAccounts() {
        $sql = "SELECT b.id, b.room_id, b.tenant_name, b.due_date, b.total_cost as original_balance, b.penalty_amount, 
                (b.total_cost + COALESCE(b.penalty_amount, 0)) as total_amount_due,
                DATEDIFF(NOW(), b.due_date) as days_overdue
                FROM billing_cycles b 
                WHERE b.payment_status IN ('unpaid', 'partially_paid', 'overdue') 
                AND b.due_date < CURDATE()
                AND b.status = 'completed'
                ORDER BY days_overdue DESC";
        $stmt = $this->conn->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getPenaltyAnalytics() {
        $sqlTotalOverdue = "SELECT COUNT(*) FROM billing_cycles WHERE payment_status IN ('unpaid', 'partially_paid', 'overdue') AND due_date < CURDATE() AND status = 'completed'";
        $sqlTotalPenalty = "SELECT COALESCE(SUM(penalty_amount), 0) FROM billing_cycles WHERE payment_status IN ('unpaid', 'partially_paid', 'overdue') AND due_date < CURDATE() AND status = 'completed'";
        $sqlTotalOutstanding = "SELECT COALESCE(SUM(total_cost + COALESCE(penalty_amount, 0)), 0) FROM billing_cycles WHERE payment_status IN ('unpaid', 'partially_paid', 'overdue') AND due_date < CURDATE() AND status = 'completed'";
        $sqlDueToday = "SELECT COUNT(*) FROM billing_cycles WHERE payment_status = 'unpaid' AND DATE(due_date) = CURDATE() AND status = 'completed'";
        $sqlDueTomorrow = "SELECT COUNT(*) FROM billing_cycles WHERE payment_status = 'unpaid' AND DATE(due_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND status = 'completed'";
        $sqlPenaltiesCollected = "SELECT COALESCE(SUM(penalty_amount), 0) FROM billing_cycles WHERE penalty_amount > 0";
        
        return [
            'totalOverdueAccounts' => $this->conn->query($sqlTotalOverdue)->fetchColumn(),
            'totalActivePenalties' => $this->conn->query($sqlTotalPenalty)->fetchColumn(),
            'totalOutstandingBalance' => $this->conn->query($sqlTotalOutstanding)->fetchColumn(),
            'billsDueToday' => $this->conn->query($sqlDueToday)->fetchColumn(),
            'billsDueTomorrow' => $this->conn->query($sqlDueTomorrow)->fetchColumn(),
            'totalPenaltiesCollected' => $this->conn->query($sqlPenaltiesCollected)->fetchColumn(),
        ];
    }

    public function getRecentActivity($limit = 50) {
        $sql = "SELECT MAX(ph.id) as id, ph.room_id, ph.tenant_name, MIN(ph.penalty_amount) as penalty_amount, 
                       ph.penalty_type, MAX(ph.created_at) as created_at, ph.days_overdue
                FROM penalty_history ph
                JOIN billing_cycles bc ON bc.id = ph.billing_cycle_id
                WHERE bc.payment_status IN ('unpaid', 'partially_paid', 'overdue')
                GROUP BY DATE(ph.created_at), ph.billing_cycle_id, ph.room_id, ph.tenant_name, ph.penalty_type, ph.days_overdue
                ORDER BY MAX(ph.created_at) DESC 
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
