<?php
require_once '../db.php';

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) $data = $_POST;

$id = intval($data['id'] ?? 0);
$title = $data['title'] ?? '';
$message = $data['message'] ?? '';
$category = $data['category'] ?? '';
$icon = $data['icon'] ?? '';
$isActive = isset($data['isActive']) ? intval($data['isActive']) : 1;

if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid ID"]);
    exit();
}

try {
    $query = "UPDATE electricity_tips SET 
              title = :title, 
              message = :message, 
              category = :category, 
              icon = :icon,
              isActive = :isActive
              WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':message', $message);
    $stmt->bindParam(':category', $category);
    $stmt->bindParam(':icon', $icon);
    $stmt->bindParam(':isActive', $isActive, PDO::PARAM_INT);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    echo json_encode(["success" => true, "message" => "Tip updated successfully"]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
