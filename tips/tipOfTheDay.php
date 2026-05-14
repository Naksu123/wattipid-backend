<?php
require_once '../db.php';

try {
    // Get all active tip IDs
    $query = "SELECT id FROM electricity_tips WHERE isActive = 1 ORDER BY id ASC";
    $stmt = $conn->query($query);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($ids) > 0) {
        // Use day of year to pick an index
        $dayOfYear = date('z'); // 0 to 365
        $index = $dayOfYear % count($ids);
        $selectedId = $ids[$index];

        $query = "SELECT * FROM electricity_tips WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $selectedId, PDO::PARAM_INT);
        $stmt->execute();
        
        $tip = $stmt->fetch();
        echo json_encode(["success" => true, "data" => $tip]);
    } else {
        echo json_encode(["success" => false, "message" => "No tips found"]);
    }
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
