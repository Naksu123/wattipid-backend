<?php
class PasswordResetRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function createResetToken($email, $otp, $minutesValid = 10) {
        $stmt = $this->conn->prepare("INSERT INTO password_resets (email, otp, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))");
        return $stmt->execute([$email, $otp, $minutesValid]);
    }

    public function findValidResetToken($email, $otp) {
        $stmt = $this->conn->prepare("SELECT id FROM password_resets WHERE email = ? AND otp = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email, $otp]);
        return $stmt->fetchColumn();
    }

    public function markAsUsed($resetId) {
        $stmt = $this->conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
        return $stmt->execute([$resetId]);
    }
}
