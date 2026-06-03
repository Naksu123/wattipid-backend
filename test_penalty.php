<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/PenaltyService.php';

$penaltyService = new PenaltyService($conn);
$result = $penaltyService->calculateDailyPenalties();
echo json_encode($result, JSON_PRETTY_PRINT);
