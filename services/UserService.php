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
        $stmt = $this->userRepo->conn->prepare("
            INSERT INTO device_tokens (user_id, expo_push_token, device_name, platform, is_active, last_active)
            VALUES (?, ?, 'React Native App', 'cross-platform', 1, NOW())
            ON DUPLICATE KEY UPDATE 
                expo_push_token = VALUES(expo_push_token),
                is_active = 1,
                last_active = NOW()
        ");
        $stmt->execute([$userId, $token]);
        return ['success' => true];
    }
}
