<?php
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class NotificationController {
    private $notifService;

    public function __construct($dbConnection) {
        $this->notifService = new NotificationService($dbConnection);
    }

    public function getNotifications($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'] ?? $data['userId'] ?? null;
        $result = $this->notifService->getNotifications($data['roomId'] ?? null, $userId, $data['limit'] ?? 20);
        ResponseHelper::sendRaw($result);
    }

    public function markAsRead($data) {
        $result = $this->notifService->markAsRead($data['id'] ?? null);
        ResponseHelper::sendRaw($result);
    }

    public function getUnreadCount($authenticatedUser, $data) {
        $userId = $authenticatedUser['id'] ?? $data['userId'] ?? null;
        $result = $this->notifService->getUnreadCount($data['roomId'] ?? null, $userId);
        ResponseHelper::sendRaw($result);
    }

    public function markAllAsRead($authenticatedUser) {
        $result = $this->notifService->markAllAsRead($authenticatedUser['id']);
        ResponseHelper::sendRaw($result);
    }

    public function deleteNotification($authenticatedUser, $data) {
        $result = $this->notifService->deleteNotification($data['notificationId'], $authenticatedUser['id']);
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
}
