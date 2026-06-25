<?php
/**
 * Wattipid Daily Penalty Cron Job
 * 
 * Usage: php c:/xampp/htdocs/wattipid_backend/cron/daily_penalty.php
 * Setup: Add this script to Windows Task Scheduler or Linux crontab to run at 12:00 AM daily.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PenaltyService.php';

echo "[*] Starting Wattipid Daily Penalty Job... " . date('Y-m-d H:i:s') . "\n";

try {
    $penaltySvc = new PenaltyService($conn);
    // Force calculation to bypass the last_run_date lazy evaluation check
    // since this cron is meant to be explicit
    $result = $penaltySvc->calculateDailyPenalties(true);

    if ($result['success']) {
        echo "[+] Success: " . $result['message'] . "\n";
    } else {
        echo "[-] Error: " . $result['message'] . "\n";
    }
} catch (Exception $e) {
    echo "[!] Fatal Error: " . $e->getMessage() . "\n";
}

echo "[*] Completed.\n";
