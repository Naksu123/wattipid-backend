<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
try {
    $conn->exec("ALTER TABLE users ADD COLUMN expo_push_token VARCHAR(255) DEFAULT NULL;");
    echo "Added expo_push_token column.\n";
} catch (PDOException $e) {
    echo "expo_push_token: " . $e->getMessage() . "\n";
}

try {
    $conn->exec("DROP TABLE IF EXISTS activity_logs;");
    $conn->exec("CREATE TABLE activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id VARCHAR(50) DEFAULT NULL,
        user_id INT DEFAULT NULL,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );");
    $conn->exec("CREATE INDEX idx_activity_logs_room_created ON activity_logs(room_id, created_at);");
    echo "Created activity_logs and index.\n";
} catch (PDOException $e) {
    echo "activity_logs: " . $e->getMessage() . "\n";
}
