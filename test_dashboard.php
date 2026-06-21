<?php
require_once 'config/config.php';
require_once 'config/db.php';
require_once 'controllers/DashboardController.php';
try {
    $c = new DashboardController($conn);
    // Simulate tenant
    $user = ['id' => 1, 'role' => 'tenant', 'room_id' => 'Room 1'];
    ob_start();
    $c->getTotalConsumptionMonth($user, []);
    $output = ob_get_clean();
    echo "OUTPUT:\n" . $output;
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
