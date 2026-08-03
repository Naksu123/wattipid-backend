<?php
class InvitationRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    /**
     * Create a new invitation. Cancels any existing pending invitations for this email first.
     * Stores both the hash (for verification) and encrypted code (for display/resend).
     */
    public function createInvitation($email, $roomId, $codeHash, $codeEncrypted, $createdBy) {
        $this->cancelPendingByEmail($email);
        $this->cancelPendingByRoom($roomId);
        $stmt = $this->conn->prepare(
            "INSERT INTO invitations (email, room_id, access_code_hash, access_code_encrypted, status, created_by, expires_at) 
             VALUES (?, ?, ?, ?, 'pending', ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))"
        );
        return $stmt->execute([$email, $roomId, $codeHash, $codeEncrypted, $createdBy]);
    }

    /**
     * Get the latest pending invitation for a given email.
     */
    public function getPendingInvitationByEmail($email) {
        $stmt = $this->conn->prepare("SELECT * FROM invitations WHERE email = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get the latest pending invitation for a given room.
     */
    public function getPendingInvitationByRoom($roomId) {
        $stmt = $this->conn->prepare("SELECT * FROM invitations WHERE room_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get an invitation by its ID.
     */
    public function getInvitationById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM invitations WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get all invitations (for admin/landlord listing).
     */
    public function getAllInvitations() {
        $stmt = $this->conn->prepare("SELECT i.*, u.name as tenant_name FROM invitations i LEFT JOIN users u ON i.tenant_id = u.id ORDER BY i.created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Resend an invitation: update expiry and resend count WITHOUT changing the code.
     */
    public function resendInvitation($id) {
        $stmt = $this->conn->prepare(
            "UPDATE invitations SET expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR), last_resend_date = NOW(), resend_count = resend_count + 1 WHERE id = ?"
        );
        return $stmt->execute([$id]);
    }

    /**
     * Cancel a specific invitation.
     */
    public function cancelInvitation($id) {
        $stmt = $this->conn->prepare("UPDATE invitations SET status = 'cancelled' WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Cancel all pending invitations for a given email.
     */
    public function cancelPendingByEmail($email) {
        $stmt = $this->conn->prepare("UPDATE invitations SET status = 'cancelled' WHERE email = ? AND status = 'pending'");
        return $stmt->execute([$email]);
    }

    /**
     * Cancel all pending invitations for a given room.
     */
    public function cancelPendingByRoom($roomId) {
        $stmt = $this->conn->prepare("UPDATE invitations SET status = 'cancelled' WHERE room_id = ? AND status = 'pending'");
        return $stmt->execute([$roomId]);
    }

    /**
     * Mark an invitation as registered after tenant completes registration.
     */
    public function markAsRegistered($id, $tenantId) {
        $stmt = $this->conn->prepare("UPDATE invitations SET status = 'registered', date_registered = NOW(), tenant_id = ? WHERE id = ?");
        return $stmt->execute([$tenantId, $id]);
    }

    /**
     * Delete all invitations for a room (used during tenant revocation).
     */
    public function deleteByRoom($roomId) {
        $stmt = $this->conn->prepare("DELETE FROM invitations WHERE room_id = ?");
        return $stmt->execute([$roomId]);
    }
}
?>
