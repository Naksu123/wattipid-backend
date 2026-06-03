<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/DashboardSyncService.php';

$service = new DashboardSyncService($conn);
$result = $service->getLiveOverview(1, 'landlord');

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
