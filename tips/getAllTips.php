<?php
require_once '../db.php';

$category = isset($_GET['category']) ? $_GET['category'] : null;

try {
    $query = "SELECT * FROM electricity_tips WHERE isActive = 1";
    if ($category) {
        $query .= " AND category = :category";
    }
    $query .= " ORDER BY createdAt DESC";
    
    $stmt = $conn->prepare($query);
    if ($category) {
        $stmt->bindParam(':category', $category);
    }
    $stmt->execute();
    
    $tips = $stmt->fetchAll();
    echo json_encode(["success" => true, "data" => $tips]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
