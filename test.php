<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'services/DashboardService.php';

$db = new PDO('mysql:host=localhost;dbname=wattipid', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$service = new DashboardService($db);
try {
    $result = $service->getMonthlyConsumptionFiltered(1, 1, 'tenant', 2026, 8);
    print_r($result);
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
} catch (Error $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
