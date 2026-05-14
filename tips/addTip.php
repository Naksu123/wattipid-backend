<?php
require_once '../db.php';

// Support JSON input
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) $data = $_POST;

$title = $data['title'] ?? '';
$message = $data['message'] ?? '';
$category = $data['category'] ?? '';
$icon = $data['icon'] ?? 'bulb-outline';

if (empty($title) || empty($message) || empty($category)) {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit();
}

try {
    $query = "INSERT INTO electricity_tips (title, message, category, icon) VALUES (:title, :message, :category, :icon)";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':message', $message);
    $stmt->bindParam(':category', $category);
    $stmt->bindParam(':icon', $icon);
    $stmt->execute();
    
    echo json_encode(["success" => true, "message" => "Tip added successfully", "id" => $conn->lastInsertId()]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
