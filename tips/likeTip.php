<?php
require_once '../db.php';

$id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid ID"]);
    exit();
}

try {
    $query = "UPDATE electricity_tips SET likesCount = likesCount + 1 WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    echo json_encode(["success" => true, "message" => "Tip liked"]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
