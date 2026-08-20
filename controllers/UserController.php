<?php
require_once __DIR__ . '/../services/UserService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class UserController {
    private $userService;

    public function __construct($dbConnection) {
        $this->userService = new UserService($dbConnection);
    }

    public function getUserByEmail($data) {
        $result = $this->userService->getUserByEmail($data['email'] ?? '');
        ResponseHelper::sendRaw($result);
    }

    public function updateProfile($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            ResponseHelper::error("Unauthorized", 401);
        }
        $result = $this->userService->updateProfile($authenticatedUser['id'], $data['name'], $data['email']);
        ResponseHelper::sendRaw($result);
    }

    public function completeOnboarding($authenticatedUser) {
        if (!$authenticatedUser) {
            ResponseHelper::error("Unauthorized", 401);
        }
        $result = $this->userService->completeOnboarding($authenticatedUser['id']);
        ResponseHelper::sendRaw($result);
    }

    public function updatePushToken($authenticatedUser, $data) {
        if (!$authenticatedUser) {
            ResponseHelper::error("Unauthorized", 401);
        }
        $result = $this->userService->updatePushToken($authenticatedUser['id'], $data['pushToken'] ?? '');
        ResponseHelper::sendRaw($result);
    }
}
