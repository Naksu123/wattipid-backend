<?php
/**
 * Wattipid Health Check
 * 
 * Used by monitoring tools to verify API and DB status.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

$status = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'services' => [
        'api' => 'up',
        'database' => 'down',
        'storage' => 'down'
    ]
];

// Check Database
try {
    if ($conn->query("SELECT 1")) {
        $status['services']['database'] = 'up';
    }
} catch (Exception $e) {
    $status['status'] = 'error';
    $status['error'] = 'Database unreachable';
}

// Check Write Permissions
if (is_writable(__DIR__ . '/email_debug.log')) {
    $status['services']['storage'] = 'up';
}

if ($status['status'] !== 'ok') {
    http_response_code(503);
}

echo json_encode($status);
