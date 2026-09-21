<?php
/**
 * Diagnostic: Test the exact raw output from submitPayment endpoint
 * This simulates what the frontend receives.
 */
require_once __DIR__ . '/../wattipid_backend/config/db.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Wattipid submitPayment Response Diagnostics</h2>";

// 1. Check for any PHP errors that might be prepended to output
echo "<h3>1. PHP Error Reporting State</h3>";
echo "<pre>";
echo "display_errors: " . ini_get('display_errors') . "\n";
echo "error_reporting: " . error_reporting() . "\n";
echo "log_errors: " . ini_get('log_errors') . "\n";
echo "</pre>";

// 2. Check config.php for ENVIRONMENT setting
echo "<h3>2. Environment Config</h3>";
echo "<pre>";
require_once __DIR__ . '/../wattipid_backend/config/config.php';
echo "ENVIRONMENT: " . (defined('ENVIRONMENT') ? ENVIRONMENT : 'NOT DEFINED') . "\n";
echo "DEBUG_MODE would be: " . (defined('ENVIRONMENT') && ENVIRONMENT === 'development' ? 'true' : 'false') . "\n";
echo "</pre>";

// 3. Simulate the output buffering behavior of api.php
echo "<h3>3. Output Buffer Simulation</h3>";
echo "<pre>";
ob_start();

// Simulate what happens inside submitPayment
// First, check if PenaltyService produces any output
require_once __DIR__ . '/../wattipid_backend/services/PenaltyService.php';
$penaltySvc = new PenaltyService($conn);

// Capture any output from penalty calculation
$beforePenalty = ob_get_contents();
echo "Buffer before penalty calc: [" . strlen($beforePenalty) . " bytes]\n";
if (strlen($beforePenalty) > 0) {
    echo "CONTENT: " . htmlspecialchars(substr($beforePenalty, 0, 500)) . "\n";
}

$result = $penaltySvc->calculateDailyPenalties();

$afterPenalty = ob_get_contents();
$penaltyOutput = substr($afterPenalty, strlen($beforePenalty));
echo "Buffer after penalty calc: [" . strlen($afterPenalty) . " bytes]\n";
if (strlen($penaltyOutput) > 0) {
    echo "PENALTY OUTPUT LEAKED: " . htmlspecialchars(substr($penaltyOutput, 0, 500)) . "\n";
    echo "WARNING: THIS IS THE BUG! Penalty service is outputting data that corrupts JSON.\n";
}

ob_end_clean();
echo "</pre>";

// 4. Check BillingNotificationService for output leaks
echo "<h3>4. BillingNotificationService Output Test</h3>";
echo "<pre>";
ob_start();

require_once __DIR__ . '/../wattipid_backend/services/BillingNotificationService.php';
$notifSvc = new BillingNotificationService($conn);

$beforeNotif = ob_get_contents();
if (strlen($beforeNotif) > 0) {
    echo "NOTIF SERVICE LEAKED OUTPUT ON REQUIRE: " . htmlspecialchars(substr($beforeNotif, 0, 500)) . "\n";
}

ob_end_clean();
echo "No output leaks from BillingNotificationService require.\n";
echo "</pre>";

// 5. Check existing payments to see if previous submission worked
echo "<h3>5. Recent Payment Records</h3>";
echo "<pre>";
$stmt = $conn->query("SELECT id, billing_cycle_id, room_id, amount, status, payment_method, created_at FROM payments ORDER BY id DESC LIMIT 10");
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($payments as $p) {
    echo "Payment #{$p['id']}: BC={$p['billing_cycle_id']}, Room={$p['room_id']}, Amount=P{$p['amount']}, Status={$p['status']}, Method={$p['payment_method']}, Created={$p['created_at']}\n";
}
echo "</pre>";

// 6. Check billing_cycles status
echo "<h3>6. Current Billing Cycles State</h3>";
echo "<pre>";
$stmt = $conn->query("SELECT id, room_id, invoice_number, payment_status, grand_total, amount_paid, electricity_charge, monthly_rent, penalty_amount, previous_balance, due_date FROM billing_cycles WHERE room_id = 'Room 1' ORDER BY id DESC LIMIT 5");
$cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cycles as $c) {
    $elec = (float)($c['electricity_charge'] ?? 0);
    $rent = (float)($c['monthly_rent'] ?? 0);
    $pen = (float)($c['penalty_amount'] ?? 0);
    $paid = (float)($c['amount_paid'] ?? 0);
    $standalone = round($elec + $rent + $pen, 2);
    $remaining = max(0, round($standalone - $paid, 2));
    echo "BC #{$c['id']} ({$c['invoice_number']}): status={$c['payment_status']}, grand_total={$c['grand_total']}, standalone={$standalone}, paid={$paid}, remaining={$remaining}, due={$c['due_date']}\n";
}
echo "</pre>";

// 7. Full simulation: Capture EXACT output from a test call through api.php
echo "<h3>7. Full API Simulation (submitPayment dry-run)</h3>";
echo "<pre>";
echo "This test checks if the PaymentController->submitPayment echoes valid JSON.\n";
echo "We simulate a call with an INVALID billingCycleId to safely test the response format.\n\n";

ob_start();
require_once __DIR__ . '/../wattipid_backend/controllers/PaymentController.php';
$pc = new PaymentController($conn);
$fakeUser = ['id' => 999, 'role' => 'tenant', 'room_id' => 'Room 1', 'name' => 'DiagnosticUser'];
$fakeData = ['billingCycleId' => '99999', 'roomId' => 'Room 1', 'amount' => '1.00', 'paymentMethod' => 'Cash'];
$pc->submitPayment($fakeUser, $fakeData);
$rawOutput = ob_get_clean();

echo "Raw output length: " . strlen($rawOutput) . " bytes\n";
echo "Raw output (hex first 100 bytes): ";
for ($i = 0; $i < min(100, strlen($rawOutput)); $i++) {
    echo sprintf('%02x ', ord($rawOutput[$i]));
}
echo "\n\n";
echo "Raw output (text): " . htmlspecialchars($rawOutput) . "\n\n";

$decoded = json_decode($rawOutput, true);
if ($decoded === null) {
    echo "WARNING: JSON DECODE FAILED! json_last_error: " . json_last_error_msg() . "\n";
    echo "This means the response is NOT valid JSON - likely corrupted by stray output.\n";
} else {
    echo "PASS: Valid JSON response.\n";
    echo "success: " . ($decoded['success'] ? 'true' : 'false') . "\n";
    echo "message: " . ($decoded['message'] ?? 'N/A') . "\n";
}
echo "</pre>";

echo "<h3>8. PHP Error Log (last 20 lines)</h3>";
echo "<pre>";
$logFile = ini_get('error_log');
if ($logFile && file_exists($logFile)) {
    $lines = file($logFile);
    $last20 = array_slice($lines, -20);
    foreach ($last20 as $line) {
        echo htmlspecialchars($line);
    }
} else {
    echo "Error log not found at: " . ($logFile ?: 'not configured') . "\n";
    // Try common XAMPP locations
    $xamppLog = 'C:/xampp/php/logs/php_error_log';
    if (file_exists($xamppLog)) {
        $lines = file($xamppLog);
        $last20 = array_slice($lines, -20);
        foreach ($last20 as $line) {
            echo htmlspecialchars($line);
        }
    } else {
        echo "Also not at: $xamppLog\n";
    }
}
echo "</pre>";
