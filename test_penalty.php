<?php
require_once 'config/config.php';
require_once 'config/db.php';
require_once 'services/PenaltyService.php';
try {
    $p = new PenaltyService($conn);
    var_dump($p->calculateDailyPenalties());
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
