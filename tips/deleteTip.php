<?php
require_once '../db.php';

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) $data = $_POST;

$id = intval($data['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid ID"]);
    exit();
}

try {
    // We'll do a soft delete by setting isActive = 0, or hard delete if preferred.
    // The user mentioned deleteTip.php, I'll do a hard delete but soft delete is safer.
    // Let's do a hard delete as requested.
    $query = "DELETE FROM electricity_tips WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    echo json_encode(["success" => true, "message" => "Tip deleted successfully"]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
