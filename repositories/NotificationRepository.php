<?php
class NotificationRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getConn() {
        return $this->conn;
    }

    /**
     * GHOST FIX: Fixed SQL parameter binding for INTERVAL clause.
     * MariaDB with emulated prepares treats ? as strings, causing
     * INTERVAL '30' MINUTE syntax error. Now uses explicit integer cast.
     */
    public function hasRecentAlert($roomId, $title, $minutes = 30) {
        $minutes = (int) $minutes;
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM notifications 
             WHERE room_id = ? AND title = ? 
             AND created_at >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
        );
        $stmt->execute([$roomId, $title]);
        return $stmt->fetchColumn() > 0;
    }

    public function insertNotification($roomId, $userId, $type, $title, $message) {
        $stmt = $this->conn->prepare("INSERT INTO notifications (room_id, user_id, type, title, message) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$roomId, $userId, $type, $title, $message]);
    }

    /**
     * GHOST FIX: Fixed SQL parameter binding for LIMIT clause.
     * Uses bindValue with PDO::PARAM_INT to prevent '20' string literal in LIMIT.
     */
    public function getNotifications($roomId, $userId, $limit = 20) {
        $where = "WHERE 1=1";
        $params = [];
        $paramIndex = 1;

        if ($roomId) {
            $where .= " AND room_id = ?";
            $params[] = ['value' => $roomId, 'type' => PDO::PARAM_STR];
        }
        if ($userId) {
            $where .= " AND user_id = ?";
            $params[] = ['value' => $userId, 'type' => PDO::PARAM_INT];
        }

        $stmt = $this->conn->prepare(
            "SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT " . (int) $limit
        );

        foreach ($params as $i => $param) {
            $stmt->bindValue($i + 1, $param['value'], $param['type']);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markAsRead($id, $userId) {
        $stmt = $this->conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }

    public function getUnreadCount($roomId) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM notifications WHERE room_id = ? AND is_read = 0");
        $stmt->execute([$roomId]);
        return $stmt->fetchColumn();
    }
}
