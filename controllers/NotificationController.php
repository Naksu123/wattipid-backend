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
        $userId = $authenticatedUser['id']; // Strictly use JWT token identity
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

    public function createNotification($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'];
        $roomId = $data['roomId'] ?? null;
        $type = $data['type'] ?? 'info';
        $category = $data['category'] ?? 'system';
        $severity = $data['severity'] ?? 'info';
        $title = $data['title'] ?? 'Notification';
        $message = $data['message'] ?? '';
        $dataJson = isset($data['data']) ? json_encode($data['data']) : '{}';

        try {
            if ($this->tableExists('notification_history')) {
                $stmt = $this->conn->prepare("
                    INSERT INTO notification_history (user_id, room_id, type, category, severity, title, message, data_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $roomId, $type, $category, $severity, $title, $message, $dataJson]);
                ResponseHelper::sendRaw(['success' => true, 'data' => ['id' => $this->conn->lastInsertId()]]);
                return;
            }
        } catch (Exception $e) {
            error_log("createNotification error: " . $e->getMessage());
        }
        ResponseHelper::sendRaw(['success' => false, 'message' => 'Failed to create notification']);
    }

    public function markAsRead($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'];
        $notifId = $data['notificationId'] ?? $data['id'] ?? null;
        if (!$notifId) {
            ResponseHelper::sendRaw(['success' => true]);
            return;
        }
        
        // Try both tables
        try {
            $this->conn->prepare("UPDATE notification_history SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notifId, $userId]);
        } catch (Exception $e) {}
        
        $result = $this->notifService->markAsRead($notifId, $userId);
        ResponseHelper::sendRaw($result);
    }

    public function getUnreadCount($authenticatedUser, $data) {
        $userId = $authenticatedUser['id']; // Strictly use JWT token identity
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
                $legacyResult = $this->notifService->getUnreadCount($roomId, $userId);
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

        echo json_encode($result);
    }

    public function deleteAllNotifications($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'];
        
        try {
            $this->conn->prepare("DELETE FROM notification_history WHERE user_id = ?")->execute([$userId]);
        } catch (Exception $e) {}
        
        $result = $this->notifService->deleteAllNotifications($userId);
        echo json_encode($result);
    }

    public function getAlertSettings($authenticatedUser, $data) {
        $result = $this->notifService->getAlertSettings($authenticatedUser['id'], $data['roomId'] ?? null);
        ResponseHelper::sendRaw($result);
    }

    public function updateAlertSettings($authenticatedUser, $data) {
        $result = $this->notifService->updateAlertSettings($authenticatedUser['id'], $data['roomId'] ?? null, $data['settings'] ?? []);
        ResponseHelper::sendRaw($result);
    }

    public function searchNotifications($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'];
        $query = trim($data['query'] ?? '');
        $limit = (int) ($data['limit'] ?? 20);
        $offset = (int) ($data['offset'] ?? 0);

        if (strlen($query) < 2) {
            ResponseHelper::sendRaw(['success' => true, 'data' => []]);
            return;
        }

        try {
            $searchTerm = '%' . $query . '%';
            $sql = "SELECT * FROM notification_history 
                    WHERE user_id = ? AND (title LIKE ? OR message LIKE ?)
                    ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId, $searchTerm, $searchTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ResponseHelper::sendRaw(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            error_log("searchNotifications error: " . $e->getMessage());
            ResponseHelper::sendRaw(['success' => true, 'data' => []]);
        }
    }

    public function getNotificationsByCategory($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'];
        $category = $data['category'] ?? null;
        $limit = (int) ($data['limit'] ?? 30);
        $offset = (int) ($data['offset'] ?? 0);

        try {
            if ($category && $category !== 'all') {
                $sql = "SELECT * FROM notification_history WHERE user_id = ? AND category = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$userId, $category]);
            } else {
                $sql = "SELECT * FROM notification_history WHERE user_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$userId]);
            }
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ResponseHelper::sendRaw(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            error_log("getNotificationsByCategory error: " . $e->getMessage());
            ResponseHelper::sendRaw(['success' => true, 'data' => []]);
        }
    }

    public function sendManualReminder($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Unauthorized access.", 403);
            return;
        }

        $roomId = $data['room_id'] ?? null;
        $tenantId = $data['tenant_id'] ?? null;
        $totalDue = $data['total_due'] ?? 0;
        $daysOverdue = $data['days_overdue'] ?? 0;

        if (!$roomId || !$tenantId) {
            ResponseHelper::error("Missing required parameters.");
            return;
        }

        require_once __DIR__ . '/../services/BillingNotificationService.php';
        $billingNotifSvc = new BillingNotificationService($this->conn);
        
        $success = $billingNotifSvc->sendManualReminder($roomId, $tenantId, $totalDue, $daysOverdue, false);

        if ($success) {
            ResponseHelper::sendRaw(['success' => true, 'message' => 'Reminder sent successfully']);
        } else {
            ResponseHelper::error("Failed to send reminder or tenant notifications are disabled.");
        }
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
