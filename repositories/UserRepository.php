<?php
class UserRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    /**
     * Finds a unique user by email. 
     * Enforced by UNIQUE constraint uk_user_email.
     */
    public function findByEmail($email) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function findById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createUser($name, $email, $passwordHash, $role, $roomId = null) {
        $stmt = $this->conn->prepare("INSERT INTO users (name, email, password_hash, role, room_id) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$name, $email, $passwordHash, $role, $roomId])) {
            return $this->conn->lastInsertId();
        }
        return false;
    }
    
    public function markEmailAsVerified($email) {
        $stmt = $this->conn->prepare("UPDATE users SET is_verified = 1 WHERE email = ?");
        return $stmt->execute([$email]);
    }

    public function deleteUser($id) {
        $stmt = $this->conn->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function findTenantByRoom($roomId) {
        $stmt = $this->conn->prepare("SELECT id, email FROM users WHERE room_id = ? AND role = 'tenant' LIMIT 1");
        $stmt->execute([$roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateLastLogin($userId) {
        $stmt = $this->conn->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
    }

    public function updateProfile($userId, $name, $email) {
        try {
            $this->conn->beginTransaction();
            
            $stmt = $this->conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $success = $stmt->execute([$name, $email, $userId]);
            
            if ($success) {
                // Synchronize profile name with the rooms table if user is a tenant
                $userStmt = $this->conn->prepare("SELECT role, room_id FROM users WHERE id = ?");
                $userStmt->execute([$userId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && $user['role'] === 'tenant' && !empty($user['room_id'])) {
                    $roomStmt = $this->conn->prepare("UPDATE rooms SET tenant_name = ? WHERE room_id = ?");
                    $roomStmt->execute([$name, $user['room_id']]);
                }
            }
            
            $this->conn->commit();
            return $success;
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return false;
        }
    }

    public function updatePushToken($userId, $token) {
        $stmt = $this->conn->prepare("UPDATE users SET push_token = ? WHERE id = ?");
        return $stmt->execute([$token, $userId]);
    }
}
