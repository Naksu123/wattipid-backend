<?php
class NotificationRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getConn() {
        return $this->conn;
    }

    public function hasRecentAlert($roomId, $title, $minutes = 30) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM notifications WHERE room_id = ? AND title = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $stmt->execute([$roomId, $title, $minutes]);
        return $stmt->fetchColumn() > 0;
    }

    public function insertNotification($roomId, $userId, $type, $title, $message) {
        $stmt = $this->conn->prepare("INSERT INTO notifications (room_id, user_id, type, title, message) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$roomId, $userId, $type, $title, $message]);
    }

    public function getNotifications($roomId, $userId, $limit = 20) {
        $where = "WHERE 1=1";
        $params = [];
        if ($roomId) {
            $where .= " AND room_id = ?";
            $params[] = $roomId;
        }
        if ($userId) {
            $where .= " AND user_id = ?";
            $params[] = $userId;
        }
        $stmt = $this->conn->prepare("SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT ?");
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markAsRead($id) {
        $stmt = $this->conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getUnreadCount($roomId) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM notifications WHERE room_id = ? AND is_read = 0");
        $stmt->execute([$roomId]);
        return $stmt->fetchColumn();
    }
}
