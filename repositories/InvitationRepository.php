<?php
class InvitationRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function findPendingByEmailAndCode($email, $code) {
        $stmt = $this->conn->prepare("SELECT room_id FROM invitations WHERE email = ? AND tenant_code = ? AND status = 'pending' AND (expires_at > NOW() OR expires_at IS NULL)");
        $stmt->execute([$email, $code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function markAsUsed($email, $code) {
        $stmt = $this->conn->prepare("UPDATE invitations SET status = 'used' WHERE email = ? AND tenant_code = ?");
        return $stmt->execute([$email, $code]);
    }

    public function deleteByRoom($roomId) {
        $stmt = $this->conn->prepare("DELETE FROM invitations WHERE room_id = ?");
        return $stmt->execute([$roomId]);
    }

    public function saveInvitation($email, $roomId, $tenantCode, $minutesValid = 5) {
        $stmt = $this->conn->prepare("INSERT INTO invitations (email, room_id, tenant_code, status, expires_at) VALUES (?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? MINUTE)) 
                                        ON DUPLICATE KEY UPDATE room_id = ?, tenant_code = ?, status = 'pending', expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)");
        return $stmt->execute([$email, $roomId, $tenantCode, $minutesValid, $roomId, $tenantCode, $minutesValid]);
    }

    public function updateExpiry($email, $minutesValid = 5) {
        $stmt = $this->conn->prepare("UPDATE invitations SET expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE email = ?");
        return $stmt->execute([$minutesValid, $email]);
    }
}
