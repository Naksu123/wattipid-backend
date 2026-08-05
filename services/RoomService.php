<?php
require_once __DIR__ . '/../repositories/RoomRepository.php';
require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../repositories/InvitationRepository.php';
require_once __DIR__ . '/../repositories/HistoryRepository.php';
require_once __DIR__ . '/../repositories/ConsumptionRepository.php';
require_once __DIR__ . '/../utils/email_service.php';
require_once __DIR__ . '/../utils/SecurityMiddleware.php';

/**
 * @property RoomRepository $roomRepo
 * @property UserRepository $userRepo
 * @property InvitationRepository $invitationRepo
 * @property HistoryRepository $historyRepo
 * @property ConsumptionRepository $consumptionRepo
 */
class RoomService {
    /** @var \PDO */
    private $conn;
    private $roomRepo;
    private $userRepo;
    private $invitationRepo;
    private $historyRepo;
    private $consumptionRepo;

    public function __construct(\PDO $dbConnection) {
        $this->conn = $dbConnection;
        $this->roomRepo = new RoomRepository($dbConnection);
        $this->userRepo = new UserRepository($dbConnection);
        $this->invitationRepo = new InvitationRepository($dbConnection);
        $this->historyRepo = new HistoryRepository($dbConnection);
        $this->consumptionRepo = new ConsumptionRepository($dbConnection);
    }

    /**
     * Mask an access code for dashboard display.
     * e.g. "783097" → "78***97"
     */
    private function maskAccessCode($code) {
        $len = strlen($code);
        if ($len <= 3) return str_repeat('*', $len);
        $first = substr($code, 0, 2);
        $last = substr($code, -2);
        return $first . str_repeat('*', $len - 4) . $last;
    }

    /**
     * Decrypt the stored encrypted access code.
     */
    private function decryptAccessCode($encryptedCode) {
        return SecurityMiddleware::decryptAccessCode($encryptedCode);
    }

    private function sanitizeRoom($room) {
        if (!$room) return $room;

        // Look up the latest pending invitation for this room from the invitations table.
        // This is the SINGLE SOURCE OF TRUTH for the access code.
        $invitation = $this->invitationRepo->getPendingInvitationByRoom($room['room_id']);
        if ($invitation && !empty($invitation['access_code_encrypted'])) {
            $plainCode = $this->decryptAccessCode($invitation['access_code_encrypted']);
            $room['tenant_code'] = $plainCode ? $this->maskAccessCode($plainCode) : '—';
        } else {
            $room['tenant_code'] = '—';
        }

        // Remove legacy columns from API response
        unset($room['tenant_code_hash']);
        unset($room['tenant_code_encrypted']);
        unset($room['tenant_code_masked']);
        return $room;
    }

    public function getAllRooms() {
        $rooms = $this->roomRepo->getAllRooms();
        $sanitized = array_map([$this, 'sanitizeRoom'], $rooms);
        return ['success' => true, 'data' => $sanitized];
    }

    public function getUserRooms($userId, $userName) {
        $rooms = $this->roomRepo->findByUserIdOrTenantName($userId, $userName);
        $sanitized = array_map([$this, 'sanitizeRoom'], $rooms);
        return ['success' => true, 'data' => $sanitized];
    }

    public function getBuildingSummary() {
        $currMonthStart = date('Y-m-01');
        $nextMonthStart = date('Y-m-01', strtotime('first day of next month'));

        $stats = $this->roomRepo->getBuildingSummary();
        
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(energy), 0) as totalEnergy, COALESCE(SUM(cost), 0) as totalCost FROM consumption_logs WHERE timestamp >= ? AND timestamp < ?");
        $stmt->execute([$currMonthStart, $nextMonthStart]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $rooms = $this->roomRepo->getRoomsWithConsumption();
        $sanitized = array_map([$this, 'sanitizeRoom'], $rooms);

        return [
            'success' => true,
            'message' => 'Summary retrieved',
            'data' => [
                'stats' => $stats,
                'totals' => $totals,
                'rooms' => $sanitized
            ]
        ];
    }

    public function getRoomById($roomId) {
        $room = $this->roomRepo->findById($roomId);
        return ['success' => true, 'data' => $this->sanitizeRoom($room)];
    }


    public function updateRoomStatus($roomId, $status, $tenantName, $startDate) {
        $this->roomRepo->updateStatus($roomId, $status, $tenantName, $startDate);
        return ['success' => true, 'data' => ['status' => $status]];
    }

    public function getVacantRooms() {
        $rooms = $this->roomRepo->getVacantRooms();
        $sanitized = array_map([$this, 'sanitizeRoom'], $rooms);
        return ['success' => true, 'data' => $sanitized];
    }

    public function transferTenant($fromRoomId, $toRoomId) {
        $tenant = $this->roomRepo->findById($fromRoomId);
        if (!$tenant || !$tenant['tenant_name']) {
            return ['success' => false, 'message' => 'Source room not found or no tenant assigned'];
        }

        try {
            $this->conn->beginTransaction();

            $today = date('Y-m-d');
            $nextMonth = date('Y-m-d', strtotime('+1 month'));

            // 1. Close any active billing cycle in the OLD room
            $stmt = $this->conn->prepare("UPDATE billing_cycles SET status = 'completed', cycle_end = NOW(), due_date = DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE room_id = ? AND status = 'active'");
            $stmt->execute([$fromRoomId]);

            // 2. Close any lingering active billing cycle in the NEW room
            $stmt = $this->conn->prepare("UPDATE billing_cycles SET status = 'completed', cycle_end = NOW(), due_date = DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE room_id = ? AND status = 'active'");
            $stmt->execute([$toRoomId]);

            // 3. Create a BRAND NEW billing cycle for the tenant in the NEW room
            $checkActive = $this->conn->prepare("SELECT id FROM billing_cycles WHERE room_id = ? AND DATE_FORMAT(cycle_start, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')");
            $checkActive->execute([$toRoomId]);
            if (!$checkActive->fetch()) {
                $stmt = $this->conn->prepare("INSERT INTO billing_cycles (room_id, cycle_start, cycle_end, status) VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active')");
                $stmt->execute([$toRoomId]);
            }

            // 4. Mark New Room as Occupied (starting today)
            if (!$this->roomRepo->updateStatus($toRoomId, 'occupied', $tenant['tenant_name'], $today)) {
                throw new Exception("Transfer failed: Could not occupy new room.");
            }

            // 5. Mark Old Room as Vacant
            if (!$this->roomRepo->markAsVacant($fromRoomId)) {
                throw new Exception("Transfer failed: Could not vacate old room.");
            }

            // 6. Update User Record & Archive History
            $user = $this->userRepo->findTenantByRoom($fromRoomId);
            if ($user) {
                // Archive their stay in the old room
                $this->historyRepo->archiveTenant($fromRoomId, $tenant['tenant_name'], $user['email'] ?? 'unknown', $tenant['tenant_start_date'], 'transferred');
                
                // Move them to the new room and reset their billing dates
                $stmt = $this->conn->prepare("UPDATE users SET room_id = ?, move_in_date = ?, billing_start_date = ?, billing_end_date = ? WHERE id = ?");
                $stmt->execute([$toRoomId, $today, $today, $nextMonth, $user['id']]);
            }

            $this->conn->commit();
            return [
                'success' => true,
                'data' => [
                    'tenantName' => $tenant['tenant_name'],
                    'fromRoomId' => $fromRoomId,
                    'toRoomId' => $toRoomId
                ]
            ];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("Transfer Transaction Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Transfer failed due to a system error.'];
        }
    }

    public function revokeTenant($roomId) {
        $room = $this->roomRepo->findById($roomId);
        if (!$room || !$room['tenant_name']) {
            return ['success' => false, 'message' => 'No tenant assigned to this room'];
        }

        $tenantName = $room['tenant_name'];
        $user = $this->userRepo->findTenantByRoom($roomId);
        
        try {
            $this->conn->beginTransaction();
            
            // 1. Archive to History
            $this->historyRepo->archiveTenant($roomId, $tenantName, $user['email'] ?? 'unknown', $room['tenant_start_date']);

            // 2. Delete User & Sessions
            if ($user) {
                $this->userRepo->deleteUser($user['id']);
                $this->conn->prepare("DELETE FROM refresh_tokens WHERE user_id = ?")->execute([$user['id']]);
                $this->conn->prepare("DELETE FROM notification_history WHERE user_id = ?")->execute([$user['id']]);
            }

            // 3. Delete Invitations
            $this->invitationRepo->deleteByRoom($roomId);

            // 4. Cleanup Consumption (Optional: some prefer to keep logs but move them to a different ID)
            $stmt = $this->conn->prepare("DELETE FROM consumption_logs WHERE room_id = ? AND tenant_name = ?");
            $stmt->execute([$roomId, $tenantName]);

            // 5. Vacate Room
            $this->roomRepo->markAsVacant($roomId);

            $this->conn->commit();
            return ['success' => true, 'data' => ['tenantName' => $tenantName]];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("Revoke Transaction Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Revocation failed due to a system error.'];
        }
    }

    /**
     * Generate a 6-digit numeric access code.
     */
    private function generateAccessCode() {
        return (string)random_int(100000, 999999);
    }

    private function logAccessCodeAction($roomId, $action, $userId = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        try {
            $stmt = $this->conn->prepare("INSERT INTO access_code_audits (room_id, action, ip_address, user_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$roomId, $action, $ip, $userId]);
        } catch (Exception $e) {}
    }

    /**
     * Generate a new access code for a room.
     * Cancels any existing pending invitation and creates a new one.
     * Called when the landlord clicks "Generate New Access Code".
     */
    public function generateNewTenantCode($roomId, $userId = null) {
        $room = $this->roomRepo->findById($roomId);
        if (!$room) return ['success' => false, 'message' => 'Room not found'];

        // Cancel existing pending invitations for this room
        $this->invitationRepo->cancelPendingByRoom($roomId);

        // Generate ONE new code
        $accessCode = $this->generateAccessCode();
        $codeHash = hash('sha256', $accessCode);
        $codeEncrypted = SecurityMiddleware::encryptAccessCode($accessCode);

        // Get the email from the last invitation for this room (if any)
        $lastInvitation = $this->invitationRepo->getPendingInvitationByRoom($roomId);
        $email = $lastInvitation ? $lastInvitation['email'] : null;

        if ($email) {
            $this->invitationRepo->createInvitation($email, $roomId, $codeHash, $codeEncrypted, $userId);
        }

        $this->logAccessCodeAction($roomId, 'Generated', $userId);

        return ['success' => true, 'message' => 'New code generated', 'data' => ['tenant_code' => $accessCode]];
    }

    public function addRoom($data) {
        if (empty($data['room_id'])) {
            return ['success' => false, 'message' => 'Room number/ID is required.'];
        }
        
        $existing = $this->roomRepo->findById($data['room_id']);
        if ($existing) {
            return ['success' => false, 'message' => 'Room number already exists.'];
        }

        // No longer generate legacy tenant_code_* columns.
        // Access codes are created only when the landlord sends an invitation.
        $this->roomRepo->createRoom($data);

        return ['success' => true, 'message' => 'Room added successfully.'];
    }

    public function updateRoom($roomId, $data) {
        $existing = $this->roomRepo->findById($roomId);
        if (!$existing) {
            return ['success' => false, 'message' => 'Room not found.'];
        }

        $this->roomRepo->updateRoom($roomId, $data);
        return ['success' => true, 'message' => 'Room updated successfully.'];
    }

    public function archiveRoom($roomId, $userId) {
        $room = $this->roomRepo->findById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Room not found.'];
        }

        if ($room['status'] === 'occupied' || !empty($room['tenant_name'])) {
            return ['success' => false, 'message' => 'Cannot archive a room with an active tenant.'];
        }

        $this->roomRepo->archiveRoom($roomId, $userId);
        return ['success' => true, 'message' => 'Room archived successfully.'];
    }

    public function restoreRoom($roomId) {
        $room = $this->roomRepo->findById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Room not found.'];
        }

        $this->roomRepo->restoreRoom($roomId);
        return ['success' => true, 'message' => 'Room restored successfully.'];
    }

    /**
     * Create a tenant invitation: generate ONE access code, store it, and email it.
     * The same code is stored (encrypted) in the invitations table, displayed
     * (masked) on the landlord dashboard, and sent via Brevo email.
     */
    public function saveTenantInvitation($email, $roomId, $landlordId = null) {
        $room = $this->roomRepo->findById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Room not found.'];
        }

        // Generate ONE access code — this is the single source of truth
        $accessCode = $this->generateAccessCode();
        $codeHash = hash('sha256', $accessCode);
        $codeEncrypted = SecurityMiddleware::encryptAccessCode($accessCode);

        // Store in invitations table (cancels any previous pending invitations)
        $this->invitationRepo->createInvitation($email, $roomId, $codeHash, $codeEncrypted, $landlordId);
        $expiresAt = date('Y-m-d H:i:s', time() + (24 * 3600));

        // Send the SAME code via email — no new code generated here
        $emailResult = queueInvitationEmail($this->conn, $email, 'Tenant', $roomId, $accessCode, $expiresAt);

        if ($emailResult) {
            if ($room['status'] === 'vacant') {
                $this->roomRepo->updateStatus($roomId, 'on_process', null, null);
            }
            return ['success' => true, 'message' => 'Invitation created and email sent successfully.'];
        } else {
            return ['success' => false, 'message' => 'Invitation saved, but failed to queue email.'];
        }
    }

    public function getInvitations() {
        $invitations = $this->invitationRepo->getAllInvitations();
        return ['success' => true, 'data' => $invitations];
    }

    /**
     * Resend an existing invitation. Does NOT generate a new code.
     * Reads the existing code from the database and re-sends the same email.
     * Only resets the expiration timer.
     */
    public function resendInvitation($invitationId, $landlordId) {
        $invitation = $this->invitationRepo->getInvitationById($invitationId);
        if (!$invitation) {
            return ['success' => false, 'message' => 'Invitation not found.'];
        }

        if ($invitation['status'] !== 'pending') {
            return ['success' => false, 'message' => 'This invitation is no longer active.'];
        }

        // Decrypt the EXISTING code from the database — do NOT generate a new one
        $accessCode = $this->decryptAccessCode($invitation['access_code_encrypted']);
        if (!$accessCode) {
            return ['success' => false, 'message' => 'Could not retrieve the access code. Please generate a new invitation.'];
        }

        // Reset expiry timer without changing the code
        $this->invitationRepo->resendInvitation($invitationId);
        $expiresAt = date('Y-m-d H:i:s', time() + (24 * 3600));

        // Re-send the SAME code via email
        queueInvitationEmail($this->conn, $invitation['email'], $invitation['tenant_name'] ?? 'Tenant', $invitation['room_id'], $accessCode, $expiresAt);

        return ['success' => true, 'message' => 'Invitation resent successfully. The same access code has been sent again.'];
    }

    public function cancelInvitation($invitationId) {
        $invitation = $this->invitationRepo->getInvitationById($invitationId);
        if (!$invitation) {
            return ['success' => false, 'message' => 'Invitation not found.'];
        }
        $this->invitationRepo->cancelInvitation($invitationId);
        return ['success' => true, 'message' => 'Invitation cancelled successfully.'];
    }

    /**
     * Verify a tenant's access code against the stored invitation.
     * Compares the hash of the entered code against the stored hash.
     */
    public function verifyAccessCode($email, $accessCode) {
        $invitation = $this->invitationRepo->getPendingInvitationByEmail($email);

        if (!$invitation) {
            return ['success' => false, 'message' => 'No invitation exists for this email.'];
        }

        if (strtotime($invitation['expires_at']) < time()) {
            return ['success' => false, 'message' => 'Your Access Code has expired. Please contact your landlord to request a new invitation.'];
        }

        $enteredHash = hash('sha256', $accessCode);
        if ($enteredHash !== $invitation['access_code_hash']) {
            return ['success' => false, 'message' => 'The Access Code you entered is incorrect.'];
        }

        // Don't expose internal data — return only what the frontend needs
        return [
            'success' => true, 
            'message' => 'Access code verified.', 
            'data' => [
                'invitation_id' => $invitation['id'],
                'room_id' => $invitation['room_id'],
                'email' => $invitation['email']
            ]
        ];
    }

    public function getTenantInvitationByEmail($email) {
        $invitation = $this->invitationRepo->getPendingInvitationByEmail($email);
        if (!$invitation) {
            return ['success' => false, 'message' => 'No pending invitation found.'];
        }
        return ['success' => true, 'data' => $invitation];
    }

    public function getTenantHistory($roomId) {
        $history = $this->historyRepo->findByRoom($roomId);
        return ['success' => true, 'data' => $history];
    }
}
