<?php
/**
 * Database Connection Helper
 */

// Database Configuration from Environment
$host = trim(config('DB_HOST', 'localhost') ?: 'localhost'); 
$db_name = trim(config('DB_NAME', 'wattipid') ?: 'wattipid'); 
$username = trim(config('DB_USER', 'root') ?: 'root'); 
$password = config('DB_PASS', ''); 

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $conn = new PDO("mysql:host={$host};dbname={$db_name};charset=utf8mb4", $username, $password, $options);
} catch(PDOException $e) {
    // If unknown database (code 1049), self-heal by creating wattipid database if server is reachable
    if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
        try {
            $rootConn = new PDO("mysql:host={$host};charset=utf8mb4", $username, $password, $options);
            $rootConn->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $conn = new PDO("mysql:host={$host};dbname={$db_name};charset=utf8mb4", $username, $password, $options);
        } catch (Throwable $healingError) {
            header('Content-Type: application/json');
            echo json_encode(["success" => false, "message" => "Database connection failed: " . $e->getMessage()]);
            exit();
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(["success" => false, "message" => "Database connection failed: " . $e->getMessage()]);
        exit();
    }
}

if (isset($conn) && $conn instanceof PDO) {
    try {
        $conn->exec("SET SESSION max_allowed_packet = 67108864");
    } catch (Throwable $t) {
        // Fallback silently if session variable cannot be set
    }

    // Automatically ensure all required schema columns and tables exist
    require_once __DIR__ . '/../helpers/DatabaseMigrationHelper.php';
    DatabaseMigrationHelper::ensureSchema($conn);
}
