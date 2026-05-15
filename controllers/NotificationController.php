<?php
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class NotificationController {
    private $notifService;
    private $conn;

    public function __construct($dbConnection) {
        $this->notifService = new NotificationService($dbConnection);
        $this->conn = $dbConnection;
    }

    /**
     * GHOST FIX: Unified notification fetch that tries notification_history first
     * (used by NotificationEngine) and falls back to legacy notifications table.
     * Also fixes the SQLSTATE[42000] error by using proper integer binding.
     */
    public function getNotifications($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'] ?? $data['userId'] ?? null;
        $roomId = $data['roomId'] ?? null;
        $category = $data['category'] ?? null;
        $limit = (int) ($data['limit'] ?? 20);
        $offset = (int) ($data['offset'] ?? 0);

        try {
            // Try notification_history first (from NotificationEngine)
            $hasHistoryTable = $this->tableExists('notification_history');
            
            if ($hasHistoryTable && $userId) {
                $sql = "SELECT * FROM notification_history WHERE user_id = ?";
                $params = [$userId];
                
                if ($category) {
                    $sql .= " AND category = ?";
                    $params[] = $category;
                }
                
                $sql .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($results)) {
                    ResponseHelper::sendRaw(['success' => true, 'data' => $results]);
                    return;
                }
            }
            
            // Fallback to legacy notifications table
            $result = $this->notifService->getNotifications($roomId, $userId, $limit);
            ResponseHelper::sendRaw($result);
        } catch (Exception $e) {
            error_log("getNotifications error: " . $e->getMessage());
            ResponseHelper::sendRaw(['success' => true, 'data' => []]);
        }
    }

    public function markAsRead($data) {
        $notifId = $data['notificationId'] ?? $data['id'] ?? null;
        if (!$notifId) {
            ResponseHelper::sendRaw(['success' => true]);
            return;
        }
        
        // Try both tables
        try {
            $this->conn->prepare("UPDATE notification_history SET is_read = 1 WHERE id = ?")->execute([$notifId]);
        } catch (Exception $e) {}
        
        $result = $this->notifService->markAsRead($notifId);
        ResponseHelper::sendRaw($result);
    }

    public function getUnreadCount($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'] ?? $data['userId'] ?? null;
        $roomId = $data['roomId'] ?? null;
        
        $count = 0;
        
        // Try notification_history first
        try {
            if ($userId && $this->tableExists('notification_history')) {
                $stmt = $this->conn->prepare("SELECT COUNT(*) FROM notification_history WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$userId]);
                $count += (int) $stmt->fetchColumn();
            }
        } catch (Exception $e) {}
        
        // Also check legacy notifications table
        if ($roomId) {
            try {
                $legacyResult = $this->notifService->getUnreadCount($roomId);
                $count += (int) ($legacyResult['data'] ?? 0);
            } catch (Exception $e) {}
        }
        
        ResponseHelper::sendRaw(['success' => true, 'data' => $count]);
    }

    public function markAllAsRead($authenticatedUser) {
        $userId = $authenticatedUser['id'];
        
        // Mark in both tables
        try {
            $this->conn->prepare("UPDATE notification_history SET is_read = 1 WHERE user_id = ?")->execute([$userId]);
        } catch (Exception $e) {}
        
        $result = $this->notifService->markAllAsRead($userId);
        ResponseHelper::sendRaw($result);
    }

    public function deleteNotification($authenticatedUser, $data) {
        $notifId = $data['notificationId'] ?? null;
        $userId = $authenticatedUser['id'];
        
        // Delete from both tables
        try {
            $this->conn->prepare("DELETE FROM notification_history WHERE id = ? AND user_id = ?")->execute([$notifId, $userId]);
        } catch (Exception $e) {}
        
        $result = $this->notifService->deleteNotification($notifId, $userId);
        ResponseHelper::sendRaw($result);
    }

    public function getAlertSettings($authenticatedUser, $data) {
        $result = $this->notifService->getAlertSettings($authenticatedUser['id'], $data['roomId'] ?? null);
        ResponseHelper::sendRaw($result);
    }

    public function updateAlertSettings($authenticatedUser, $data) {
        $result = $this->notifService->updateAlertSettings($authenticatedUser['id'], $data['roomId'] ?? null, $data['settings'] ?? []);
        ResponseHelper::sendRaw($result);
    }

    /**
     * Helper: Check if a table exists in the database.
     */
    private function tableExists($tableName) {
        try {
            $stmt = $this->conn->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
