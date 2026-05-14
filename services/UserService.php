<?php
require_once __DIR__ . '/../repositories/UserRepository.php';

class UserService {
    private $userRepo;

    public function __construct($dbConnection) {
        $this->userRepo = new UserRepository($dbConnection);
    }

    public function getUserByEmail($email) {
        $user = $this->userRepo->findByEmail($email);
        if ($user) {
            unset($user['password_hash']);
            return ['success' => true, 'data' => $user];
        }
        return ['success' => false, 'message' => 'User not found'];
    }

    public function updateProfile($userId, $name, $email) {
        $this->userRepo->updateProfile($userId, $name, $email);
        return ['success' => true, 'message' => 'Profile updated successfully'];
    }

    public function updatePushToken($userId, $token) {
        $this->userRepo->updatePushToken($userId, $token);
        return ['success' => true];
    }
}
