<?php
/**
 * Wattipid Daily Reminders Cron Job
 * 
 * Usage: php c:/xampp/htdocs/wattipid_backend/cron/daily_reminders.php
 * Setup: Add this script to Windows Task Scheduler or Linux crontab to run daily.
 * Function: Sends push notifications to tenants who are 3, 6, 9, etc. days overdue.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/BillingNotificationService.php';

echo "[*] Starting Wattipid Daily Reminders Job... " . date('Y-m-d H:i:s') . "\n";

try {
    $notifSvc = new BillingNotificationService($conn);
    
    // Find all overdue billing cycles that are multiples of 3 days overdue
    // DATEDIFF(NOW(), due_date) returns positive days if overdue
    $stmt = $conn->prepare("
        SELECT bc.id, bc.room_id, bc.total_cost, bc.penalty_amount, bc.due_date, r.tenant_id
        FROM billing_cycles bc
        JOIN rooms r ON bc.room_id = r.id
        WHERE bc.status = 'completed'
          AND bc.payment_status IN ('unpaid', 'overdue')
          AND bc.due_date IS NOT NULL
          AND r.tenant_id IS NOT NULL
          AND DATEDIFF(NOW(), bc.due_date) > 0
          AND DATEDIFF(NOW(), bc.due_date) % 3 = 0
    ");
    $stmt->execute();
    $overdueCycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "[*] Found " . count($overdueCycles) . " tenant(s) to remind today.\n";

    $remindedCount = 0;
    foreach ($overdueCycles as $cycle) {
        $totalDue = (float)$cycle['total_cost'] + (float)($cycle['penalty_amount'] ?? 0);
        $daysOverdue = (int) (new DateTime())->diff(new DateTime($cycle['due_date']))->format('%a');

        // We use the new sendManualReminder method which can double as an auto-reminder sender
        $success = $notifSvc->sendManualReminder($cycle['room_id'], $cycle['tenant_id'], $totalDue, $daysOverdue, true);
        if ($success) {
            $remindedCount++;
        }
    }

    echo "[+] Successfully sent $remindedCount reminder(s).\n";

} catch (Exception $e) {
    echo "[!] Fatal Error: " . $e->getMessage() . "\n";
}

echo "[*] Completed.\n";
