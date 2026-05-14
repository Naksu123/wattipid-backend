<?php
require_once '../db.php';

$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

try {
    // Get a random tip, excluding the last one shown
    $query = "SELECT * FROM electricity_tips WHERE isActive = 1 AND id != :last_id ORDER BY RAND() LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':last_id', $last_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $tip = $stmt->fetch();

    if ($tip) {
        // Increment viewsCount
        $updateQuery = "UPDATE electricity_tips SET viewsCount = viewsCount + 1 WHERE id = :id";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bindParam(':id', $tip['id'], PDO::PARAM_INT);
        $updateStmt->execute();

        echo json_encode(["success" => true, "data" => $tip]);
    } else {
        // If no other tip found, try getting any active tip
        $query = "SELECT * FROM electricity_tips WHERE isActive = 1 ORDER BY RAND() LIMIT 1";
        $stmt = $conn->query($query);
        $tip = $stmt->fetch();
        
        if ($tip) {
            echo json_encode(["success" => true, "data" => $tip]);
        } else {
            echo json_encode(["success" => false, "message" => "No tips found"]);
        }
    }
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
