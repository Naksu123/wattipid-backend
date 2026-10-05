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
    const OVERDUE_COOLDOWN = 4320; // 3 days

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
            SELECT id, cycle_start, cycle_end, total_cost, penalty_amount, due_date, payment_status, grand_total, amount_paid
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

        $totalDue = max(0.0, (float)(($cycle['grand_total'] ?? 0) > 0 ? (($cycle['grand_total'] ?? 0) - ($cycle['amount_paid'] ?? 0)) : ($cycle['total_cost'] + ($cycle['penalty_amount'] ?? 0))));

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
            SELECT id, cycle_start, cycle_end, total_cost, penalty_amount, due_date, payment_status, grand_total, amount_paid
            FROM billing_cycles 
            WHERE room_id = ? 
              AND status = 'completed' 
              AND payment_status = 'overdue'
            ORDER BY due_date ASC LIMIT 1
        ");
        $stmt->execute([$roomId]);
        $cycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cycle) return [];

        $totalDue = max(0.0, (float)(($cycle['grand_total'] ?? 0) > 0 ? (($cycle['grand_total'] ?? 0) - ($cycle['amount_paid'] ?? 0)) : ($cycle['total_cost'] + ($cycle['penalty_amount'] ?? 0))));
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
    // API TRIGGER: Manual Reminders
    // =========================================================
    public function sendManualReminder($roomId, $userId, $totalDue, $daysOverdue, $isAuto = false) {
        $settings = $this->getPreferences($userId, $roomId);
        if (!$settings['notifications_enabled'] || !($settings['overdue_alerts'] ?? true)) return false;

        $alert = [
            'type' => 'manual_reminder',
            'category' => 'billing',
            'severity' => 'critical',
            'title' => $isAuto ? '🚨 Overdue Bill Reminder' : '🔔 Payment Reminder',
            'message' => "Your electricity bill of ₱" . number_format($totalDue, 2) . " is overdue by $daysOverdue day(s). Please settle it to avoid further penalties.",
            'data' => [
                'days_overdue' => $daysOverdue,
                'total_due' => $totalDue,
            ],
            'cooldown_minutes' => 0, // No cooldown for manually triggered reminders
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

            return true;
        } catch (Exception $e) {
            if (isset($ownsTransaction) && $ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("[BillingNotifSvc] Error saving manual reminder: " . $e->getMessage());
            return false;
        }
    }

    // =========================================================
    // CHECK 4: Payment Verification Alerts (Real-time trigger)
    // =========================================================
    public function sendPaymentVerificationAlert($roomId, $userId, $amountPaid, $status, $paymentMethod, $remainingBalance, $paymentData = [], $force = false) {
        $settings = $this->getPreferences($userId, $roomId);
        if (!$settings['notifications_enabled'] || !($settings['payment_alerts'] ?? true)) return false;

        $isFullyPaid = ($status === 'paid' && $remainingBalance <= 0.00);
        $isPartial = ($status === 'partially_paid' || ($status !== 'paid' && $remainingBalance > 0.00));
        
        $invoiceNum = $paymentData['invoiceNumber'] ?? 'N/A';
        $title = $isPartial ? '💵 Partial Payment Verified' : 'Payment Confirmed — Account Fully Paid';
        $message = $isPartial 
            ? "Your partial payment of ₱" . number_format($amountPaid, 2) . " via " . strtoupper($paymentMethod) . " has been verified. Remaining balance: ₱" . number_format($remainingBalance, 2) . "."
            : "Your payment of ₱" . number_format($amountPaid, 2) . " has been verified. Invoice #{$invoiceNum} is now fully paid and settled.";

        $alert = [
            'type' => $isPartial ? 'payment_partial' : 'payment_verified',
            'category' => 'payment',
            'severity' => $isPartial ? 'info' : 'success',
            'title' => $title,
            'message' => $message,
            'data' => [
                'amount_paid' => $amountPaid,
                'payment_method' => $paymentMethod,
                'remaining_balance' => $remainingBalance,
                'invoice_number' => $invoiceNum,
                'billing_cycle_id' => $paymentData['billingCycleId'] ?? null
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
                    $pushAlert['title'] = "Payment Confirmed — Account Fully Paid";
                    $pushAlert['message'] = "Your payment of ₱" . number_format($amountPaid, 2) . " has been accepted. Invoice #{$invoiceNum} is fully paid.";
                }
                $pushAlert['data']['url'] = '/(tenant)/billing-history';
                $this->queuePush($userId, $pushAlert);
            }

            if (!empty($paymentData)) {
                $tenantEmail = $paymentData['tenantEmail'] ?? null;
                if (!$tenantEmail) {
                    $userStmt = $this->conn->prepare("SELECT email FROM users WHERE id = ?");
                    $userStmt->execute([$userId]);
                    $tenantEmail = $userStmt->fetchColumn();
                }
                
                if ($tenantEmail && filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
                    $this->queueVerificationEmail($tenantEmail, $amountPaid, $paymentData, $isPartial, $remainingBalance, $force);
                } else {
                    error_log("[BillingNotifSvc] Skipping email: No valid email found for tenant user ID {$userId}");
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

    private function queueVerificationEmail($toEmail, $amountPaid, $paymentData, $isPartial = false, $remainingBalance = 0, $force = false) {
        $cycleId = $paymentData['billingCycleId'] ?? null;
        $isFullyPaid = (!$isPartial && $remainingBalance <= 0.00);

        // IDEMPOTENCY CHECK: Prevent duplicate "Fully Paid" emails for the same settled billing cycle (unless explicitly forced)
        if ($isFullyPaid && $cycleId && !$force) {
            try {
                $checkStmt = $this->conn->prepare(
                    "SELECT id FROM email_logs WHERE type = 'payment_fully_paid' AND error_message = ? AND status = 'sent' LIMIT 1"
                );
                $checkStmt->execute(["cycle:{$cycleId}"]);
                if ($checkStmt->fetchColumn()) {
                    error_log("[BillingNotifSvc] Fully-paid email already sent for cycle {$cycleId}. Skipping duplicate.");
                    return true;
                }
            } catch (Exception $checkEx) {
                error_log("[BillingNotifSvc] Idempotency check error: " . $checkEx->getMessage());
            }
        }

        $tenantName = htmlspecialchars($paymentData['tenantName'] ?? 'Tenant');
        $roomNumber = htmlspecialchars($paymentData['roomNumber'] ?? 'N/A');
        $paymentMethod = htmlspecialchars($paymentData['paymentMethod'] ?? 'N/A');
        $refNumber = htmlspecialchars($paymentData['referenceNumber'] ?? 'N/A');
        $invoiceNumber = htmlspecialchars($paymentData['invoiceNumber'] ?? 'N/A');
        
        $cycleStartStr = !empty($paymentData['cycleStart']) ? date('M d, Y', strtotime($paymentData['cycleStart'])) : '';
        $cycleEndStr = !empty($paymentData['cycleEnd']) ? date('M d, Y', strtotime($paymentData['cycleEnd'])) : '';
        $billingPeriod = ($cycleStartStr && $cycleEndStr) ? "{$cycleStartStr} – {$cycleEndStr}" : 'N/A';

        $paymentDateStr = !empty($paymentData['paymentDate']) ? date('M d, Y', strtotime($paymentData['paymentDate'])) : date('M d, Y');
        $dateVerifiedStr = !empty($paymentData['dateVerified']) ? date('M d, Y h:i A', strtotime($paymentData['dateVerified'])) : date('M d, Y h:i A');
        $verifiedBy = htmlspecialchars($paymentData['verifiedBy'] ?? 'Landlord');
        $amountFmt = number_format($amountPaid, 2);
        $remBalFmt = number_format($remainingBalance, 2);

        if ($isFullyPaid) {
            $subject = "Wattipid Payment Confirmed — Account Fully Paid";
            $headerColor = "#10B981"; // Emerald Green
            $statusText = "FULLY PAID / SETTLED";
            $statusBg = "#ECFDF5";
            $statusBorder = "#10B981";
            $statusTextColor = "#065F46";
            $statusMessage = "Your payment of <strong>₱{$amountFmt}</strong> has been verified. Invoice <strong>#{$invoiceNumber}</strong> is completely settled. No outstanding balance remains for this billing period, and no further penalties will accrue.";
        } else {
            $subject = "Partial Payment Verified - Wattipid";
            $headerColor = "#F59E0B"; // Amber
            $statusText = "PARTIALLY PAID";
            $statusBg = "#FEF3C7";
            $statusBorder = "#F59E0B";
            $statusTextColor = "#92400E";
            $statusMessage = "Your partial payment of <strong>₱{$amountFmt}</strong> has been applied. You still have a remaining balance of <strong>₱{$remBalFmt}</strong> for invoice <strong>#{$invoiceNumber}</strong>.";
        }

        $htmlBody = "
            <div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;\">
                <div style=\"background-color: {$headerColor}; padding: 24px; text-align: center;\">
                    <h1 style=\"color: #ffffff; margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;\">" . ($isFullyPaid ? "Payment Confirmed — Account Fully Paid" : "Partial Payment Verified") . "</h1>
                </div>
                <div style=\"padding: 24px;\">
                    <p style=\"font-size: 15px; color: #1f2937; margin-top: 0;\">Dear <strong>{$tenantName}</strong>,</p>
                    <p style=\"font-size: 14px; color: #4b5563; line-height: 1.6;\">" . ($isFullyPaid 
                        ? "We are pleased to inform you that your payment has been reviewed and accepted by your landlord. Your account for invoice <strong>#{$invoiceNumber}</strong> has been <strong>fully settled</strong>." 
                        : "Your recent payment has been reviewed and approved by your landlord. A partial amount has been applied to invoice <strong>#{$invoiceNumber}</strong>.") . "</p>
                    
                    <div style=\"background-color: {$statusBg}; border-left: 4px solid {$statusBorder}; padding: 14px 16px; margin: 20px 0; border-radius: 4px;\">
                        <div style=\"font-size: 14px; font-weight: 700; color: {$statusTextColor}; margin-bottom: 4px;\">Account Status: <span style=\"color: {$headerColor};\">{$statusText}</span></div>
                        <div style=\"font-size: 13px; color: #374151; line-height: 1.5;\">{$statusMessage}</div>
                    </div>

                    <h3 style=\"color: #111827; font-size: 15px; border-bottom: 2px solid #f3f4f6; padding-bottom: 8px; margin-top: 24px; margin-bottom: 12px;\">Payment & Invoice Details</h3>
                    <table style=\"width: 100%; border-collapse: collapse; font-size: 13px;\">
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; width: 40%;\"><strong>Invoice Number:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; font-weight: 600; color: #111827;\">{$invoiceNumber}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Billing Period:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #111827;\">{$billingPeriod}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Tenant Name:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #111827;\">{$tenantName}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Room Number:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #111827;\">{$roomNumber}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Payment Method:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #111827;\">{$paymentMethod}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Reference Number:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #111827;\">{$refNumber}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Paid Amount:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; font-weight: 700; color: {$headerColor}; font-size: 15px;\">₱{$amountFmt}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Payment Date:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #111827;\">{$paymentDateStr}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Verification Date:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #111827;\">{$dateVerifiedStr}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Verified By:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #111827;\">{$verifiedBy}</td></tr>
                        <tr><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280;\"><strong>Remaining Balance:</strong></td><td style=\"padding: 8px 0; border-bottom: 1px solid #f3f4f6; font-weight: 700; color: " . ($isFullyPaid ? "#10B981" : "#EF4444") . "; font-size: 15px;\">₱" . ($isFullyPaid ? "0.00" : $remBalFmt) . "</td></tr>
                    </table>
                    
                    <p style=\"font-size: 13px; color: #6b7280; line-height: 1.5; margin-top: 20px;\">" . ($isFullyPaid 
                        ? "Your official paid invoice / receipt (PDF) is now available. You can view or download it anytime inside the Wattipid app under <strong>Billing History</strong>." 
                        : "Please settle your remaining balance of ₱{$remBalFmt} before the due date to avoid overdue penalties.") . "</p>
                    
                    <div style=\"border-top: 1px solid #e5e7eb; padding-top: 16px; margin-top: 24px;\">
                        <p style=\"margin: 0; font-size: 13px; color: #374151;\">Sincerely,</p>
                        <p style=\"margin: 4px 0 0 0; font-size: 14px; font-weight: 600; color: #111827;\">Wattipid Smart Electricity Monitoring System</p>
                        <p style=\"font-size: 11px; color: #9ca3af; margin-top: 12px; margin-bottom: 0;\">This is an automated notification. Please do not reply directly to this email.</p>
                    </div>
                </div>
            </div>
        ";

        $textBody = ($isFullyPaid ? "Wattipid Payment Confirmed — Account Fully Paid\n\n" : "Partial Payment Verified - Wattipid\n\n")
            . "Dear {$tenantName},\n\n"
            . ($isFullyPaid 
                ? "Your payment of ₱{$amountFmt} via {$paymentMethod} has been approved by your landlord. Invoice #{$invoiceNumber} is completely settled. No outstanding balance remains." 
                : "Your partial payment of ₱{$amountFmt} via {$paymentMethod} has been approved. Remaining balance: ₱{$remBalFmt}.") . "\n\n"
            . "Invoice Number: {$invoiceNumber}\n"
            . "Billing Period: {$billingPeriod}\n"
            . "Tenant: {$tenantName}\n"
            . "Room: {$roomNumber}\n"
            . "Payment Method: {$paymentMethod}\n"
            . "Reference: {$refNumber}\n"
            . "Amount Paid: ₱{$amountFmt}\n"
            . "Payment Date: {$paymentDateStr}\n"
            . "Verification Date: {$dateVerifiedStr}\n"
            . "Verified By: {$verifiedBy}\n"
            . "Remaining Balance: ₱" . ($isFullyPaid ? "0.00" : $remBalFmt) . "\n\n"
            . ($isFullyPaid 
                ? "Your official paid invoice / receipt (PDF) is now available in the Wattipid app under Billing History.\n\n" 
                : "")
            . "Wattipid Smart Electricity Monitoring System";

        $emailType = $isFullyPaid ? 'payment_fully_paid' : 'payment_partial';

        try {
            require_once __DIR__ . '/../utils/email_service.php';
            $result = sendEmail($toEmail, $tenantName, $subject, $htmlBody, $textBody, $emailType);
            
            $success = !empty($result['success']);
            $provider = $result['provider'] ?? (defined('EMAIL_PROVIDER') ? EMAIL_PROVIDER : 'smtp');
            $refMarker = $success ? ($cycleId ? "cycle:{$cycleId}" : null) : ($result['message'] ?? 'Failed');

            if (function_exists('logEmailDelivery')) {
                logEmailDelivery($this->conn, $toEmail, $emailType, $success ? 'sent' : 'failed', $provider, $refMarker);
            }

            if ($success) {
                error_log("[BillingNotifSvc] Email ({$emailType}) successfully delivered to {$toEmail} for cycle {$cycleId}");
            } else {
                error_log("[BillingNotifSvc] Email delivery failed to {$toEmail}: " . ($result['message'] ?? 'Unknown error'));
            }
            return $success;
        } catch (Throwable $mailEx) {
            error_log("[BillingNotifSvc] Exception during verification email delivery: " . $mailEx->getMessage());
            if (function_exists('logEmailDelivery')) {
                logEmailDelivery($this->conn, $toEmail, $emailType, 'failed', defined('EMAIL_PROVIDER') ? EMAIL_PROVIDER : 'smtp', $mailEx->getMessage());
            }
            return false;
        }
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

            $userStmt = $this->conn->prepare("SELECT email, name FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $tenantUser = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($tenantUser && !empty($tenantUser['email'])) {
                require_once __DIR__ . '/../utils/email_service.php';
                $rejectionSubject = "Payment Rejected - Wattipid";
                $rejectionHtml = "<p>Your payment of ₱" . number_format($amount, 2) . " has been rejected.</p><p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p><p>Please log in to Wattipid and upload a new payment proof.</p>";
                $rejectionText = "Your payment of ₱" . number_format($amount, 2) . " has been rejected.\n\nReason: \"$reason\"\n\nPlease log in to Wattipid and upload a new payment proof.";
                sendEmail($tenantUser['email'], $tenantUser['name'] ?? '', $rejectionSubject, $rejectionHtml, $rejectionText, 'payment_rejected');
            }

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
        try {
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
        } catch (Exception $e) {
            // Fallback to legacy notifications table
            $stmt = $this->conn->prepare("INSERT INTO notifications (room_id, user_id, type, title, message) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $roomId, $userId,
                $alert['type'],
                $alert['title'],
                $alert['message']
            ]);
            return $this->conn->lastInsertId();
        }
    }

    // =========================================================
    // LANDLORD NOTIFICATIONS
    // =========================================================
    public function sendPaymentSubmittedAlert($roomId, $tenantId, $tenantName, $amount, $paymentMethod, $referenceNumber, $paymentId, $billingCycleId = null, $invoiceNumber = null) {
        // 1. Defensively resolve tenant name if missing
        if (empty($tenantName)) {
            if ($tenantId) {
                $uStmt = $this->conn->prepare("SELECT name FROM users WHERE id = ?");
                $uStmt->execute([$tenantId]);
                $tenantName = $uStmt->fetchColumn();
            }
            if (empty($tenantName) && $roomId) {
                $rStmt = $this->conn->prepare("SELECT tenant_name FROM rooms WHERE id = ?");
                $rStmt->execute([$roomId]);
                $tenantName = $rStmt->fetchColumn();
            }
            if (empty($tenantName)) {
                $tenantName = "Tenant";
            }
        }

        // 2. Format room display name cleanly (e.g. "Room 1")
        $roomDisplay = (stripos(trim($roomId), 'room') === 0) ? trim($roomId) : "Room " . trim($roomId);

        // 3. Find active landlord(s)
        $stmt = $this->conn->prepare("
            SELECT id, name, email 
            FROM users 
            WHERE role = 'landlord'
        ");
        $stmt->execute();
        $landlords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($landlords)) return false;

        $amountFormatted = number_format((float)$amount, 2);
        $submissionDate = date('Y-m-d H:i:s');
        $lastNotifId = null;

        foreach ($landlords as $landlord) {
            $landlordId = (int)$landlord['id'];

            // Prevent duplicate notification during retries/refresh
            $checkStmt = $this->conn->prepare("
                SELECT id FROM notification_history 
                WHERE user_id = ? AND type = 'payment_submitted' AND JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.paymentId')) = ?
                LIMIT 1
            ");
            $checkStmt->execute([$landlordId, (string)$paymentId]);
            if ($checkStmt->fetchColumn()) {
                continue;
            }

            $alert = [
                'type' => 'payment_submitted',
                'category' => 'payment',
                'severity' => 'info',
                'title' => 'New Payment Submitted',
                'message' => "{$tenantName} from {$roomDisplay} submitted a payment of ₱{$amountFormatted} for verification.",
                'data' => [
                    'paymentId' => (int)$paymentId,
                    'billingCycleId' => $billingCycleId ? (int)$billingCycleId : null,
                    'invoiceNumber' => $invoiceNumber,
                    'roomId' => $roomId,
                    'tenantId' => (int)$tenantId,
                    'tenantName' => $tenantName,
                    'amount' => (float)$amount,
                    'method' => $paymentMethod,
                    'reference' => $referenceNumber,
                    'status' => 'pending',
                    'submissionDate' => $submissionDate
                ]
            ];

            try {
                $notifId = $this->saveNotification($landlordId, $roomId, $alert);
                $lastNotifId = $notifId;
                $this->queuePush($landlordId, $alert);
            } catch (Throwable $e) {
                error_log("[BillingNotifSvc] Error creating landlord payment notification: " . $e->getMessage());
            }
        }

        return $lastNotifId;
    }

    private function queuePush($userId, $alert) {
        try {
            require_once __DIR__ . '/../utils/notification_engine.php';
            $engine = new NotificationEngine($this->conn);
            $engine->sendPushNotification($userId, $alert);
        } catch (Throwable $e) {
            error_log("[BillingNotifSvc] Push direct send error: " . $e->getMessage());
        }
    }
}

