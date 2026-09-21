<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/middlewares/AuthMiddleware.php';

header('Content-Type: application/json');

$auth = new AuthMiddleware(SECRET_KEY, $conn);
try {
    $user = $auth->handle();
    $notifs = $conn->query("SELECT id, user_id, title FROM notification_history WHERE user_id = {$user['id']}")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'user' => $user,
        'notifs_count' => count($notifs),
        'notifs' => $notifs
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
