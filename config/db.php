<?php
/**
 * Database Connection Helper
 */

// Database Configuration from Environment
$host = config('DB_HOST', 'localhost'); 
$db_name = config('DB_NAME', 'wattipid'); 
$username = config('DB_USER', 'root'); 
$password = config('DB_PASS', ''); 

try {
    $conn = new PDO("mysql:host={$host};dbname={$db_name}", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(["success" => false, "message" => "Database connection failed: " . $e->getMessage()]);
    exit();
}
