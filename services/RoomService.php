<?php
require_once __DIR__ . '/../repositories/RoomRepository.php';
require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../repositories/InvitationRepository.php';
require_once __DIR__ . '/../repositories/HistoryRepository.php';
require_once __DIR__ . '/../repositories/ConsumptionRepository.php';

class RoomService {
    private $conn;
    private $roomRepo;
    private $userRepo;
    private $invitationRepo;
    private $historyRepo;
    private $consumptionRepo;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        $this->roomRepo = new RoomRepository($dbConnection);
        $this->userRepo = new UserRepository($dbConnection);
        $this->invitationRepo = new InvitationRepository($dbConnection);
        $this->historyRepo = new HistoryRepository($dbConnection);
        $this->consumptionRepo = new ConsumptionRepository($dbConnection);
    }

    public function getAllRooms() {
        return ['success' => true, 'data' => $this->roomRepo->getAllRooms()];
    }

    public function getUserRooms($userId, $userName) {
        return ['success' => true, 'data' => $this->roomRepo->findByUserIdOrTenantName($userId, $userName)];
    }

    public function getBuildingSummary() {
        $currMonthStart = date('Y-m-01');
        $nextMonthStart = date('Y-m-01', strtotime('first day of next month'));

        $stats = $this->roomRepo->getBuildingSummary();
        
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(energy), 0) as totalEnergy, COALESCE(SUM(cost), 0) as totalCost FROM consumption_logs WHERE timestamp >= ? AND timestamp < ?");
        $stmt->execute([$currMonthStart, $nextMonthStart]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $rooms = $this->roomRepo->getRoomsWithConsumption();

        return [
            'success' => true,
            'message' => 'Summary retrieved',
            'data' => [
                'stats' => $stats,
                'totals' => $totals,
                'rooms' => $rooms
            ]
        ];
    }

    public function getRoomById($roomId) {
        return ['success' => true, 'data' => $this->roomRepo->findById($roomId)];
    }

    public function getRoomByTenantCode($code) {
        return ['success' => true, 'data' => $this->roomRepo->findByTenantCode($code)];
    }

    public function updateRoomStatus($roomId, $status, $tenantName, $startDate) {
        $this->roomRepo->updateStatus($roomId, $status, $tenantName, $startDate);
        return ['success' => true, 'data' => ['status' => $status]];
    }

    public function getVacantRooms() {
        return ['success' => true, 'data' => $this->roomRepo->getVacantRooms()];
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
            $stmt = $this->conn->prepare("UPDATE billing_cycles SET status = 'completed', cycle_end = NOW() WHERE room_id = ? AND status = 'active'");
            $stmt->execute([$fromRoomId]);

            // 2. Close any lingering active billing cycle in the NEW room
            $stmt = $this->conn->prepare("UPDATE billing_cycles SET status = 'completed', cycle_end = NOW() WHERE room_id = ? AND status = 'active'");
            $stmt->execute([$toRoomId]);

            // 3. Create a BRAND NEW billing cycle for the tenant in the NEW room
            $stmt = $this->conn->prepare("INSERT INTO billing_cycles (room_id, cycle_start, cycle_end, status) VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active')");
            $stmt->execute([$toRoomId]);

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

    public function generateNewTenantCode($roomId) {
        $room = $this->roomRepo->findById($roomId);
        if (!$room) return ['success' => false, 'message' => 'Room not found'];

        $newCode = 'TC-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        $this->roomRepo->updateTenantCode($roomId, $newCode);
        return ['success' => true, 'message' => 'New code generated', 'code' => $newCode];
    }

    public function addRoom($data) {
        if (empty($data['room_id'])) {
            return ['success' => false, 'message' => 'Room number/ID is required.'];
        }
        
        $existing = $this->roomRepo->findById($data['room_id']);
        if ($existing) {
            return ['success' => false, 'message' => 'Room number already exists.'];
        }

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

    public function saveTenantInvitation($email, $roomId, $tenantCode) {
        $this->invitationRepo->saveInvitation($email, $roomId, $tenantCode);
        
        require_once __DIR__ . '/../utils/email_service.php';
        $emailResult = sendAccessCodeEmail($this->conn, $email, $tenantCode, $roomId);

        if ($emailResult['success']) {
            $room = $this->roomRepo->findById($roomId);
            if ($room && $room['status'] === 'vacant') {
                $this->roomRepo->updateStatus($roomId, 'on_process', null, null);
            }
            return [
                'success' => true, 
                'message' => 'Invitation saved. Email sent successfully.',
                'data' => [
                    'emailSent' => true,
                    'emailProvider' => $emailResult['provider'] ?? 'unknown'
                ]
            ];
        } else {
            return ['success' => false, 'message' => "Failed to send email: " . $emailResult['message']];
        }
    }

    public function getTenantInvitationByEmail($email) {
        $invitation = $this->invitationRepo->findPendingByEmailAndCode($email, null); // Code null to find any pending
        // Wait, the findPendingByEmailAndCode requires code. Let's add a generic one.
        $stmt = $this->conn->prepare("SELECT * FROM invitations WHERE email = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$email]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invitation) {
            if ($invitation['expires_at'] && strtotime($invitation['expires_at']) < time()) {
                return ['success' => false, 'message' => "Your access code has expired.", 'data' => ["expired" => true]];
            }

            require_once __DIR__ . '/../utils/email_service.php';
            $emailResult = sendAccessCodeEmail($this->conn, $email, $invitation['tenant_code'], $invitation['room_id']);

            if ($emailResult['success']) {
                $this->invitationRepo->updateExpiry($email, 5);
                $invitation['expires_at'] = date('Y-m-d H:i:s', time() + 300);
                return ['success' => true, 'message' => "Access code sent to your email", 'data' => $invitation];
            } else {
                return ['success' => false, 'message' => "Failed to send email: " . $emailResult['message']];
            }
        }
        return ['success' => false, 'message' => "No access code found for this email."];
    }

    public function getTenantHistory($roomId) {
        $history = $this->historyRepo->findByRoom($roomId);
        return ['success' => true, 'data' => $history];
    }
}
