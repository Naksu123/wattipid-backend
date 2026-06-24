<?php
require_once __DIR__ . '/../services/RoomService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class RoomController {
    private $roomService;

    public function __construct($dbConnection) {
        $this->roomService = new RoomService($dbConnection);
    }

    public function getAllRooms($authenticatedUser) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->getAllRooms();
        ResponseHelper::sendRaw($result);
    }

    public function getUserRooms($authenticatedUser, $data) {
        $userId = $data['userId'] ?? 0;
        if ($authenticatedUser['role'] !== 'landlord' && $authenticatedUser['id'] != $userId) {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->getUserRooms($userId, $data['userName'] ?? '');
        ResponseHelper::sendRaw($result);
    }

    public function getBuildingSummary($authenticatedUser) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->getBuildingSummary();
        ResponseHelper::sendRaw($result);
    }

    public function getRoomById($data) {
        $result = $this->roomService->getRoomById($data['roomId'] ?? '');
        ResponseHelper::sendRaw($result);
    }

    public function getRoomByTenantCode($data) {
        $result = $this->roomService->getRoomByTenantCode($data['code'] ?? '');
        ResponseHelper::sendRaw($result);
    }

    public function updateRoomStatus($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->updateRoomStatus($data['roomId'], $data['status'], $data['tenantName'], $data['startDate']);
        ResponseHelper::sendRaw($result);
    }

    public function getVacantRooms($authenticatedUser) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->getVacantRooms();
        ResponseHelper::sendRaw($result);
    }

    public function transferTenant($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->transferTenant($data['fromRoomId'], $data['toRoomId']);
        ResponseHelper::sendRaw($result);
    }

    public function revokeTenant($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->revokeTenant($data['roomId']);
        ResponseHelper::sendRaw($result);
    }

    public function generateNewTenantCode($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->generateNewTenantCode($data['roomId']);
        ResponseHelper::sendRaw($result);
    }

    public function saveTenantInvitation($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->saveTenantInvitation($data['email'], $data['roomId']);
        ResponseHelper::sendRaw($result);
    }

    public function getTenantInvitationByEmail($data) {
        $result = $this->roomService->getTenantInvitationByEmail($data['email'] ?? '');
        ResponseHelper::sendRaw($result);
    }

    public function getTenantHistory($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->getTenantHistory($data['roomId'] ?? '');
        ResponseHelper::sendRaw($result);
    }

    public function addRoom($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        $result = $this->roomService->addRoom($data);
        ResponseHelper::sendRaw($result);
    }

    public function updateRoom($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        if (empty($data['room_id'])) {
            ResponseHelper::error("Room ID is required", 400);
        }
        $result = $this->roomService->updateRoom($data['room_id'], $data);
        ResponseHelper::sendRaw($result);
    }

    public function archiveRoom($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        if (empty($data['room_id'])) {
            ResponseHelper::error("Room ID is required", 400);
        }
        $result = $this->roomService->archiveRoom($data['room_id'], $authenticatedUser['id']);
        ResponseHelper::sendRaw($result);
    }

    public function restoreRoom($authenticatedUser, $data) {
        if ($authenticatedUser['role'] !== 'landlord') {
            ResponseHelper::error("Forbidden", 403);
        }
        if (empty($data['room_id'])) {
            ResponseHelper::error("Room ID is required", 400);
        }
        $result = $this->roomService->restoreRoom($data['room_id']);
        ResponseHelper::sendRaw($result);
    }
}
