<?php
/**
 * BillingNotificationService
 * 
 * Lazily invoked for authenticated tenant requests to generate:
 * 1. Budget threshold alerts (50%, 75%, 90%, 100%, exceeded)
 * 2. Due date reminders (7, 3, 1, 0 days before)
 * 3. Overdue & penalty alerts
 * 
 * Uses notification_cooldowns to prevent duplicate alerts.
 */

class BillingNotificationService {
    /** @var \PDO */
    private $conn;

    // Cooldowns per alert type in minutes
    // Budget thresholds: once per cycle (very long cooldown)
    const BUDGET_COOLDOWN = 43200; // 30 days in minutes
    // Due date reminders: once per reminder level
    const DUE_DATE_COOLDOWN = 1440; // 24 hours
    // Overdue: once per day
    const OVERDUE_COOLDOWN = 1440;

    public function __construct(\PDO $dbConnection) {
        $this->conn = $dbConnection;
    }

    /**
     * Run all billing notification checks for a tenant.
     * Called lazily from api.php on authenticated tenant requests.
     */
    public function checkAll($roomId, $userId) {
        if (!$roomId || !$userId) return [];

        $settings = $this->getPreferences($userId, $roomId);
        if (!$settings['notifications_enabled']) return [];

        $alerts = [];

        // 1. Budget threshold alerts
        if ($settings['budget_alerts']) {
            $alerts = array_merge($alerts, $this->checkBudgetThresholds($roomId, $userId, $settings));
        }

        // 2. Due date reminders
        if ($settings['due_date_alerts']) {
            $alerts = array_merge($alerts, $this->checkDueDateReminders($roomId, $userId));
        }

        // 3. Overdue & penalty alerts
        if ($settings['overdue_alerts'] || $settings['penalty_alerts']) {
            $alerts = array_merge($alerts, $this->checkOverdueAlerts($roomId, $userId, $settings));
        }

        // Process: cooldown check, save, queue push
        $sent = [];
        foreach ($alerts as $alert) {
            $cooldown = $alert['cooldown_minutes'] ?? self::BUDGET_COOLDOWN;
            if ($this->canSend($userId, $alert['type'], $cooldown)) {
                try {
                    $this->conn->beginTransaction();
                    $notifId = $this->saveNotification($userId, $roomId, $alert);
                    $this->updateCooldown($userId, $alert['type']);
                    $this->conn->commit();

                    $alert['id'] = $notifId;

                    // Queue push notification
                    if ($settings['push_enabled']) {
                        $this->queuePush($userId, $alert);
                    }

                    $sent[] = $alert;
                } catch (Exception $e) {
                    if ($this->conn->inTransaction()) $this->conn->rollBack();
                    error_log("[BillingNotifSvc] Error: " . $e->getMessage());
                }
            }
        }

        return $sent;
    }

    // =========================================================
    // CHECK 1: Budget Threshold Alerts
    // =========================================================
    private function checkBudgetThresholds($roomId, $userId, $settings) {
        $alerts = [];

        // Get monthly budget
        $stmt = $this->conn->prepare("
            SELECT monthly_budget, daily_allowance, weekly_allowance
            FROM budget_settings 
            WHERE room_id = ? AND month = MONTH(CURRENT_DATE) AND year = YEAR(CURRENT_DATE)
        ");
        $stmt->execute([$roomId]);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$budget || !$budget['monthly_budget'] || $budget['monthly_budget'] <= 0) return [];

        // Get current billing cycle consumption
        $cycleStmt = $this->conn->prepare("
            SELECT cycle_start, cycle_end FROM billing_cycles 
            WHERE room_id = ? AND status = 'active' 
            ORDER BY id DESC LIMIT 1
        ");
        $cycleStmt->execute([$roomId]);
        $cycle = $cycleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$cycle) return [];

        $costStmt = $this->conn->prepare("
            SELECT COALESCE(SUM(cost), 0) as totalCost 
            FROM consumption_logs 
            WHERE room_id = ? AND timestamp >= ? AND timestamp < ?
        ");
        $costStmt->execute([$roomId, $cycle['cycle_start'], $cycle['cycle_end']]);
        $currentCost = (float) $costStmt->fetchColumn();

        $monthlyBudget = (float) $budget['monthly_budget'];
        $pct = ($currentCost / $monthlyBudget) * 100;

        // Define thresholds from highest to lowest (fire the highest matched one)
        $thresholds = [
            ['pct' => 100, 'type' => 'budget_monthly_exceeded', 'key' => 'budget_alerts',
             'title' => '🚨 Budget Exceeded!',
             'message' => "You have exceeded your monthly electricity budget of ₱" . number_format($monthlyBudget, 2) . ". Current spending: ₱" . number_format($currentCost, 2) . ".",
             'severity' => 'critical', 'category' => 'budget'],
            ['pct' => 90, 'type' => 'budget_monthly_90pct', 'key' => 'budget_90_alerts',
             'title' => '⚠️ 90% Budget Reached',
             'message' => "Warning: You have reached 90% of your monthly electricity budget (₱" . number_format($currentCost, 2) . " / ₱" . number_format($monthlyBudget, 2) . ").",
             'severity' => 'warning', 'category' => 'budget'],
            ['pct' => 75, 'type' => 'budget_monthly_75pct', 'key' => 'budget_75_alerts',
             'title' => '💰 75% Budget Reached',
             'message' => "You have used 75% of your monthly electricity budget (₱" . number_format($currentCost, 2) . " / ₱" . number_format($monthlyBudget, 2) . ").",
             'severity' => 'warning', 'category' => 'budget'],
            ['pct' => 50, 'type' => 'budget_monthly_50pct', 'key' => 'budget_50_alerts',
             'title' => '📊 50% Budget Reached',
             'message' => "You have used 50% of your monthly electricity budget (₱" . number_format($currentCost, 2) . " / ₱" . number_format($monthlyBudget, 2) . ").",
             'severity' => 'info', 'category' => 'budget'],
        ];

        // Fire only the highest threshold reached
        foreach ($thresholds as $t) {
            if ($pct >= $t['pct'] && ($settings[$t['key']] ?? true)) {
                $alerts[] = [
                    'type' => $t['type'],
                    'category' => $t['category'],
                    'severity' => $t['severity'],
                    'title' => $t['title'],
                    'message' => $t['message'],
                    'data' => ['pct' => round($pct, 1), 'spent' => $currentCost, 'budget' => $monthlyBudget],
                    'cooldown_minutes' => self::BUDGET_COOLDOWN,
                ];
                break; // Only fire the highest threshold
            }
        }

        return $alerts;
    }

    // =========================================================
    // CHECK 2: Due Date Reminders
    // =========================================================
    private function checkDueDateReminders($roomId, $userId) {
        $alerts = [];

        // Find completed but unpaid billing cycles with due dates
        $stmt = $this->conn->prepare("
            SELECT id, cycle_start, cycle_end, total_cost, penalty_amount, due_date, payment_status
            FROM billing_cycles 
            WHERE room_id = ? 
              AND status = 'completed' 
              AND payment_status IN ('unpaid', 'overdue')
              AND due_date IS NOT NULL
            ORDER BY due_date ASC LIMIT 1
        ");
        $stmt->execute([$roomId]);
        $cycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cycle) return [];

        $dueDate = new DateTime($cycle['due_date']);
        $now = new DateTime();
        $daysUntilDue = (int) $now->diff($dueDate)->format('%r%a');

        $totalDue = (float) $cycle['total_cost'] + (float) ($cycle['penalty_amount'] ?? 0);

        $reminders = [
            ['days' => 2, 'type' => 'due_date_2d',
             'title' => '📅 Bill Due in 2 Days',
             'message' => "Reminder: Your electricity bill of ₱" . number_format($totalDue, 2) . " is due in 2 days (" . $dueDate->format('M j, Y') . "). Please settle your payment to avoid penalties.",
             'severity' => 'warning'],
            ['days' => 1, 'type' => 'due_date_1d',
             'title' => '⚠️ Bill Due Tomorrow',
             'message' => "Your electricity bill of ₱" . number_format($totalDue, 2) . " is due tomorrow (" . $dueDate->format('M j, Y') . "). Pay now to avoid automatic penalties.",
             'severity' => 'warning'],
            ['days' => 0, 'type' => 'due_date_today',
             'title' => '🚨 Bill Due Today',
             'message' => "Your electricity bill of ₱" . number_format($totalDue, 2) . " is due today. Please submit payment immediately to avoid penalties.",
             'severity' => 'critical'],
        ];

        foreach ($reminders as $r) {
            if ($daysUntilDue === $r['days']) {
                $alerts[] = [
                    'type' => $r['type'],
                    'category' => 'billing',
                    'severity' => $r['severity'],
                    'title' => $r['title'],
                    'message' => $r['message'],
                    'data' => [
                        'billing_cycle_id' => $cycle['id'],
                        'due_date' => $cycle['due_date'],
                        'total_due' => $totalDue,
                        'days_until_due' => $daysUntilDue,
                    ],
                    'cooldown_minutes' => self::DUE_DATE_COOLDOWN,
                ];
                break;
            }
        }

        return $alerts;
    }

    // =========================================================
    // CHECK 3: Overdue & Penalty Alerts
    // =========================================================
    private function checkOverdueAlerts($roomId, $userId, $settings) {
        $alerts = [];

        // Find overdue billing cycles
        $stmt = $this->conn->prepare("
            SELECT id, cycle_start, cycle_end, total_cost, penalty_amount, due_date, payment_status
            FROM billing_cycles 
            WHERE room_id = ? 
              AND status = 'completed' 
              AND payment_status = 'overdue'
            ORDER BY due_date ASC LIMIT 1
        ");
        $stmt->execute([$roomId]);
        $cycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cycle) return [];

        $totalDue = (float) $cycle['total_cost'] + (float) ($cycle['penalty_amount'] ?? 0);
        $dueDate = new DateTime($cycle['due_date']);
        $now = new DateTime();
        $daysOverdue = (int) $now->diff($dueDate)->format('%a');

        // Overdue alert
        if ($settings['overdue_alerts'] ?? true) {
            $alerts[] = [
                'type' => 'bill_overdue',
                'category' => 'billing',
                'severity' => 'critical',
                'title' => '🚨 Bill Overdue',
                'message' => "Your electricity bill of ₱" . number_format($totalDue, 2) . " is overdue by $daysOverdue day(s). Please settle your payment immediately to avoid further penalties.",
                'data' => [
                    'billing_cycle_id' => $cycle['id'],
                    'days_overdue' => $daysOverdue,
                    'total_due' => $totalDue,
                ],
                'cooldown_minutes' => self::OVERDUE_COOLDOWN,
            ];
        }

        // Penalty applied alert
        $penaltyAmount = (float) ($cycle['penalty_amount'] ?? 0);
        if ($penaltyAmount > 0 && ($settings['penalty_alerts'] ?? true)) {
            $alerts[] = [
                'type' => 'penalty_applied',
                'category' => 'penalty',
                'severity' => 'critical',
                'title' => '⚠️ Penalty Applied',
                'message' => "A penalty of ₱" . number_format($penaltyAmount, 2) . " has been added to your overdue electricity bill. Total outstanding: ₱" . number_format($totalDue, 2) . ".",
                'data' => [
                    'billing_cycle_id' => $cycle['id'],
                    'penalty_amount' => $penaltyAmount,
                    'total_due' => $totalDue,
                ],
                'cooldown_minutes' => self::OVERDUE_COOLDOWN,
            ];
        }

        return $alerts;
    }

    // =========================================================
    // CHECK 4: Payment Verification Alerts (Real-time trigger)
    // =========================================================
    public function sendPaymentVerificationAlert($roomId, $userId, $amountPaid, $status, $paymentMethod, $remainingBalance, $paymentData = []) {
        $settings = $this->getPreferences($userId, $roomId);
        if (!$settings['notifications_enabled'] || !($settings['payment_alerts'] ?? true)) return false;

        $isPartial = $status === 'partially_paid';
        
        $title = $isPartial ? '💵 Partial Payment Verified' : 'Payment Verified';
        $message = $isPartial 
            ? "Your partial payment of ₱" . number_format($amountPaid, 2) . " via " . strtoupper($paymentMethod) . " has been verified. Remaining balance: ₱" . number_format($remainingBalance, 2) . "."
            : "Your payment has been reviewed and approved by your landlord. Your billing status has been updated to Paid.";

        $alert = [
            'type' => $isPartial ? 'payment_partial' : 'payment_verified',
            'category' => 'payment',
            'severity' => $isPartial ? 'info' : 'success',
            'title' => $title,
            'message' => $message,
            'data' => [
                'amount_paid' => $amountPaid,
                'payment_method' => $paymentMethod,
                'remaining_balance' => $remainingBalance
            ]
        ];

        try {
            $ownsTransaction = false;
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $ownsTransaction = true;
            }

            $notifId = $this->saveNotification($userId, $roomId, $alert);
            
            if ($ownsTransaction) {
                $this->conn->commit();
            }

            $alert['id'] = $notifId;

            if ($settings['push_enabled']) {
                $pushAlert = $alert;
                if (!$isPartial) {
                    $pushAlert['title'] = "Payment Successfully Verified";
                    $pushAlert['message'] = "Your payment has been accepted and marked as Paid. Tap to view payment details.";
                }
                $pushAlert['data']['url'] = '/(tenant)/billing-history';
                $this->queuePush($userId, $pushAlert);
            }

            if (!$isPartial && !empty($paymentData)) {
                $userStmt = $this->conn->prepare("SELECT email FROM users WHERE id = ?");
                $userStmt->execute([$userId]);
                $tenantEmail = $userStmt->fetchColumn();
                
                if ($tenantEmail) {
                    $this->queueVerificationEmail($tenantEmail, $amountPaid, $paymentData);
                }
            }

            return true;
        } catch (Exception $e) {
            if (isset($ownsTransaction) && $ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("[BillingNotifSvc] Error saving payment alert: " . $e->getMessage());
            return false;
        }
    }

    private function queueVerificationEmail($toEmail, $amountPaid, $paymentData) {
        $subject = "Payment Successfully Verified";
        
        $tenantName = htmlspecialchars($paymentData['tenantName'] ?? 'Tenant');
        $roomNumber = htmlspecialchars($paymentData['roomNumber'] ?? 'N/A');
        $paymentMethod = htmlspecialchars($paymentData['paymentMethod'] ?? 'N/A');
        $refNumber = htmlspecialchars($paymentData['referenceNumber'] ?? 'N/A');
        $dateSubmitted = htmlspecialchars($paymentData['dateSubmitted'] ?? date('Y-m-d'));
        $dateVerified = htmlspecialchars(date('Y-m-d H:i:s'));
        $verifiedBy = htmlspecialchars($paymentData['verifiedBy'] ?? 'Landlord');
        $amountFmt = number_format($amountPaid, 2);
        
        $htmlBody = "
            <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;\">
                <div style=\"background-color: #10B981; padding: 20px; text-align: center;\">
                    <h1 style=\"color: white; margin: 0; font-size: 24px;\">Your Payment Has Been Successfully Reviewed and Accepted</h1>
                </div>
                <div style=\"padding: 20px; background-color: #f9fafb; border: 1px solid #e5e7eb;\">
                    <p>Dear <strong>{$tenantName}</strong>,</p>
                    <p>We are pleased to inform you that your recent payment has been reviewed and approved by your landlord.</p>
                    
                    <h3 style=\"color: #111827; border-bottom: 2px solid #10B981; padding-bottom: 5px;\">Payment Details:</h3>
                    <table style=\"width: 100%; border-collapse: collapse; margin-bottom: 20px;\">
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\"><strong>Tenant Name:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\">{$tenantName}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\"><strong>Room Number:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\">{$roomNumber}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\"><strong>Payment Method:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\">{$paymentMethod}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\"><strong>Reference Number:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\">{$refNumber}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\"><strong>Amount Paid:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb; font-weight: bold; color: #10B981;\">₱{$amountFmt}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\"><strong>Date Submitted:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\">{$dateSubmitted}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\"><strong>Date Verified:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\">{$dateVerified}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\"><strong>Verified By:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #e5e7eb;\">{$verifiedBy}</td></tr>
                    </table>
                    
                    <div style=\"background-color: #ECFDF5; padding: 15px; border-left: 4px solid #10B981; margin-bottom: 20px;\">
                        <h4 style=\"margin: 0 0 5px 0; color: #065F46;\">Payment Status: <span style=\"font-size: 18px;\">PAID</span></h4>
                        <p style=\"margin: 0; color: #047857;\">Your account has been updated successfully, and no outstanding balance remains for this billing period.</p>
                    </div>
                    
                    <p>Thank you for completing your payment on time.</p>
                    <p>If you have any questions regarding your billing statement, please contact your landlord through the Wattipid Smart Electricity Monitoring System.</p>
                    
                    <br>
                    <p>Sincerely,</p>
                    <p><strong>Wattipid Smart Electricity Monitoring System</strong></p>
                    
                    <p style=\"font-size: 11px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 10px; margin-top: 20px;\">This is an automated message generated by Wattipid. Please do not reply to this email.</p>
                </div>
            </div>
        ";
        
        $textBody = "Payment Successfully Verified\n\nDear {$tenantName},\n\nWe are pleased to inform you that your recent payment of ₱{$amountFmt} via {$paymentMethod} has been reviewed and approved by your landlord.\n\nPayment Status: PAID\n\nYour account has been updated successfully, and no outstanding balance remains for this billing period.\n\nThank you for completing your payment on time.";

        require_once __DIR__ . '/../utils/QueueService.php';
        $queue = new QueueService($this->conn);
        $queue->push('email', [
            'to' => $toEmail,
            'name'  => $tenantName,
            'subject'  => $subject,
            'htmlBody' => $htmlBody,
            'textBody' => $textBody
        ]);
    }

    public function sendPaymentRejectionAlert($roomId, $userId, $amount, $reason) {
        $settings = $this->getPreferences($userId, $roomId);
        if (!$settings['notifications_enabled'] || !($settings['payment_alerts'] ?? true)) return false;

        $alert = [
            'type' => 'payment_rejected',
            'category' => 'payment',
            'severity' => 'critical',
            'title' => '❌ Payment Rejected',
            'message' => "Your payment of ₱" . number_format($amount, 2) . " has been rejected. Reason: \"$reason\". Please upload a new payment proof.",
            'data' => [
                'amount' => $amount,
                'reason' => $reason
            ]
        ];

        try {
            $ownsTransaction = false;
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $ownsTransaction = true;
            }
            
            $notifId = $this->saveNotification($userId, $roomId, $alert);
            
            if ($ownsTransaction) {
                $this->conn->commit();
            }

            $alert['id'] = $notifId;

            if ($settings['push_enabled']) {
                $this->queuePush($userId, $alert);
            }

            require_once __DIR__ . '/../utils/QueueService.php';
            $queue = new QueueService($this->conn);
            $queue->push('email_notification', [
                'userId' => $userId,
                'subject' => 'Payment Rejected',
                'body' => "Your payment of ₱" . number_format($amount, 2) . " has been rejected.\n\nReason: \"$reason\"\n\nPlease log in to Wattipid and upload a new payment proof.",
                'template' => 'payment_rejected'
            ]);

            return true;
        } catch (Exception $e) {
            if (isset($ownsTransaction) && $ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("[BillingNotifSvc] Error saving payment rejection alert: " . $e->getMessage());
            return false;
        }
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private function getPreferences($userId, $roomId) {
        $stmt = $this->conn->prepare("
            SELECT * FROM alert_settings 
            WHERE user_id = ? AND (room_id = ? OR room_id IS NULL) 
            LIMIT 1
        ");
        $stmt->execute([$userId, $roomId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        // Return with defaults
        return [
            'notifications_enabled' => (bool) ($settings['notifications_enabled'] ?? 1),
            'push_enabled' => (bool) ($settings['push_enabled'] ?? 1),
            'budget_alerts' => (bool) ($settings['budget_alerts'] ?? 1),
            'budget_50_alerts' => (bool) ($settings['budget_50_alerts'] ?? 1),
            'budget_75_alerts' => (bool) ($settings['budget_75_alerts'] ?? 1),
            'budget_90_alerts' => (bool) ($settings['budget_90_alerts'] ?? 1),
            'due_date_alerts' => (bool) ($settings['due_date_alerts'] ?? 1),
            'overdue_alerts' => (bool) ($settings['overdue_alerts'] ?? 1),
            'penalty_alerts' => (bool) ($settings['penalty_alerts'] ?? 1),
            'payment_alerts' => (bool) ($settings['payment_alerts'] ?? 1),
        ];
    }

    private function canSend($userId, $alertType, $cooldownMinutes) {
        // Check cooldown
        $stmt = $this->conn->prepare("
            SELECT last_sent_at, daily_count, count_date
            FROM notification_cooldowns
            WHERE user_id = ? AND alert_type = ?
        ");
        $stmt->execute([$userId, $alertType]);
        $cooldown = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cooldown) {
            $lastSent = new DateTime($cooldown['last_sent_at']);
            $now = new DateTime();
            $diffMinutes = ($now->getTimestamp() - $lastSent->getTimestamp()) / 60;
            if ($diffMinutes < $cooldownMinutes) return false;

            // Daily cap check (max 15 billing notifications per day)
            $dailyStmt = $this->conn->prepare("
                SELECT COALESCE(SUM(daily_count), 0) as total
                FROM notification_cooldowns
                WHERE user_id = ? AND count_date = CURDATE()
            ");
            $dailyStmt->execute([$userId]);
            $dailyTotal = (int) $dailyStmt->fetchColumn();
            if ($dailyTotal >= 15) return false;
        }

        return true;
    }

    private function updateCooldown($userId, $alertType) {
        $stmt = $this->conn->prepare("
            INSERT INTO notification_cooldowns (user_id, alert_type, last_sent_at, daily_count, count_date)
            VALUES (?, ?, NOW(), 1, CURDATE())
            ON DUPLICATE KEY UPDATE 
                last_sent_at = NOW(),
                daily_count = IF(count_date = CURDATE(), daily_count + 1, 1),
                count_date = CURDATE()
        ");
        $stmt->execute([$userId, $alertType]);
    }

    private function saveNotification($userId, $roomId, $alert) {
        $stmt = $this->conn->prepare("
            INSERT INTO notification_history (user_id, room_id, type, category, severity, title, message, data_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId, $roomId,
            $alert['type'], $alert['category'], $alert['severity'],
            $alert['title'], $alert['message'],
            json_encode($alert['data'] ?? []),
        ]);
        return $this->conn->lastInsertId();
    }

    private function queuePush($userId, $alert) {
        try {
            require_once __DIR__ . '/../utils/QueueService.php';
            $queue = new QueueService($this->conn);
            $queue->push('push_notification', [
                'userId' => $userId,
                'alert' => $alert,
            ]);
        } catch (Exception $e) {
            error_log("[BillingNotifSvc] Push queue error: " . $e->getMessage());
        }
    }
}
