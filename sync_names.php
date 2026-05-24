<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

try {
    global $conn;
    
    // Sync all rooms
    $stmt = $conn->query("SELECT id, name, room_id FROM users WHERE role = 'tenant' AND room_id IS NOT NULL");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $u) {
        $update = $conn->prepare("UPDATE rooms SET tenant_name = ? WHERE room_id = ?");
        $update->execute([$u['name'], $u['room_id']]);
        echo "Updated room " . $u['room_id'] . " to tenant_name " . $u['name'] . "\n";
    }
    echo "Done syncing.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
