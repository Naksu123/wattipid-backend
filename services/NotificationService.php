<?php
require_once __DIR__ . '/../repositories/NotificationRepository.php';

class NotificationService {
    private $notifRepo;

    public function __construct($dbConnection) {
        $this->notifRepo = new NotificationRepository($dbConnection);
    }

    public function getNotifications($roomId, $userId, $limit = 20) {
        return ['success' => true, 'data' => $this->notifRepo->getNotifications($roomId, $userId, $limit)];
    }

    public function markAsRead($id) {
        $this->notifRepo->markAsRead($id);
        return ['success' => true];
    }

    public function getUnreadCount($roomId) {
        return ['success' => true, 'data' => $this->notifRepo->getUnreadCount($roomId)];
    }

    public function markAllAsRead($userId) {
        $stmt = $this->notifRepo->getConn()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
        return ['success' => true];
    }

    public function deleteNotification($id, $userId) {
        $stmt = $this->notifRepo->getConn()->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        return ['success' => true];
    }

    public function getAlertSettings($userId, $roomId) {
        require_once __DIR__ . '/../utils/notification_engine.php';
        $notifEngine = new NotificationEngine($this->notifRepo->getConn());
        $settings = $notifEngine->getAlertSettings($userId, $roomId);
        return ['success' => true, 'data' => $settings];
    }

    public function updateAlertSettings($userId, $roomId, $settings) {
        require_once __DIR__ . '/../utils/notification_engine.php';
        $notifEngine = new NotificationEngine($this->notifRepo->getConn());
        $notifEngine->updateAlertSettings($userId, $roomId, $settings);
        return ['success' => true, 'message' => 'Alert settings updated'];
    }
}
