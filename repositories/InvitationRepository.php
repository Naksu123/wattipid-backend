<?php
class InvitationRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function findPendingByEmailAndCodeHash($email, $codeHash) {
        $stmt = $this->conn->prepare("SELECT room_id FROM invitations WHERE email = ? AND tenant_code_hash = ? AND status = 'pending' AND (expires_at > NOW() OR expires_at IS NULL)");
        $stmt->execute([$email, $codeHash]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function markAsUsed($email, $codeHash) {
        $stmt = $this->conn->prepare("UPDATE invitations SET status = 'used' WHERE email = ? AND tenant_code_hash = ?");
        return $stmt->execute([$email, $codeHash]);
    }

    public function deleteByRoom($roomId) {
        $stmt = $this->conn->prepare("DELETE FROM invitations WHERE room_id = ?");
        return $stmt->execute([$roomId]);
    }

    public function saveInvitationSecure($email, $roomId, $hash, $encrypted, $masked, $minutesValid = 5) {
        $stmt = $this->conn->prepare("INSERT INTO invitations (email, room_id, tenant_code_hash, tenant_code_encrypted, tenant_code_masked, status, expires_at) 
                                      VALUES (?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? MINUTE)) 
                                      ON DUPLICATE KEY UPDATE room_id = ?, tenant_code_hash = ?, tenant_code_encrypted = ?, tenant_code_masked = ?, status = 'pending', expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)");
        return $stmt->execute([$email, $roomId, $hash, $encrypted, $masked, $minutesValid, $roomId, $hash, $encrypted, $masked, $minutesValid]);
    }

    public function updateExpiry($email, $minutesValid = 5) {
        $stmt = $this->conn->prepare("UPDATE invitations SET expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE email = ?");
        return $stmt->execute([$minutesValid, $email]);
    }
}
