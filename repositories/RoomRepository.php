<?php
class RoomRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    private function autoExpireRooms() {
        $this->conn->exec("UPDATE invitations SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()");
        $this->conn->exec("
            UPDATE rooms r
            LEFT JOIN invitations i ON r.room_id = i.room_id AND i.status = 'pending'
            SET r.status = 'vacant'
            WHERE r.status = 'on_process' AND i.email IS NULL
        ");
    }

    public function getAllRooms() {
        $this->autoExpireRooms();
        $stmt = $this->conn->prepare("SELECT * FROM rooms ORDER BY room_id ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($roomId) {
        $stmt = $this->conn->prepare("SELECT * FROM rooms WHERE room_id = ?");
        $stmt->execute([$roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByTenantCodeHash($hash) {
        $stmt = $this->conn->prepare("SELECT * FROM rooms WHERE tenant_code_hash = ?");
        $stmt->execute([$hash]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByUserIdOrTenantName($userId, $tenantName) {
        $stmt = $this->conn->prepare("SELECT r.*, u.id as user_id FROM rooms r LEFT JOIN users u ON r.room_id = u.room_id WHERE u.id = ? OR r.tenant_name = ?");
        $stmt->execute([$userId, $tenantName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBuildingSummary() {
        $this->autoExpireRooms();
        $stmt = $this->conn->query("SELECT 
            COUNT(*) as totalRooms,
            COALESCE(SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END), 0) as occupiedRooms,
            COALESCE(SUM(CASE WHEN status = 'on_process' THEN 1 ELSE 0 END), 0) as onProcessRooms,
            COALESCE(SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END), 0) as vacantRooms,
            COALESCE(SUM(CASE WHEN status = 'not_available' THEN 1 ELSE 0 END), 0) as notAvailableRooms,
            COALESCE(SUM(CASE WHEN status = 'under_maintenance' THEN 1 ELSE 0 END), 0) as maintenanceRooms,
            COALESCE(SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END), 0) as archivedRooms,
            COALESCE(SUM(CASE WHEN last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE) OR last_seen IS NULL THEN 1 ELSE 0 END), 0) as offlineMeters
            FROM rooms");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getRoomsWithConsumption($currStart = null, $nextStart = null, $prevStart = null) {
        $this->autoExpireRooms();
        $stmt = $this->conn->prepare("
            SELECT r.*, 
                   COALESCE(curr.totalEnergy, 0) as currEnergy,
                   COALESCE(prev.totalEnergy, 0) as prevEnergy
            FROM rooms r
            LEFT JOIN (
                SELECT c.room_id, SUM(c.energy) as totalEnergy 
                FROM consumption_logs c
                JOIN billing_cycles bc ON c.billing_cycle_id = bc.id AND bc.status = 'active'
                GROUP BY c.room_id
            ) curr ON r.room_id = curr.room_id
            LEFT JOIN (
                SELECT bc1.room_id, bc1.total_kwh as totalEnergy 
                FROM billing_cycles bc1
                WHERE bc1.id = (
                    SELECT MAX(bc2.id) FROM billing_cycles bc2 
                    WHERE bc2.room_id = bc1.room_id AND bc2.status = 'completed'
                )
            ) prev ON r.room_id = prev.room_id
            ORDER BY r.room_id ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus($roomId, $status, $tenantName = null, $startDate = null) {
        $stmt = $this->conn->prepare("UPDATE rooms SET status = ?, tenant_name = ?, tenant_start_date = ? WHERE room_id = ?");
        return $stmt->execute([$status, $tenantName, $startDate, $roomId]);
    }

    public function getVacantRooms() {
        $stmt = $this->conn->prepare("SELECT * FROM rooms WHERE status = 'vacant'");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateTenantCodeSecure($roomId, $hash, $encrypted, $masked) {
        $stmt = $this->conn->prepare("UPDATE rooms SET tenant_code_hash = ?, tenant_code_encrypted = ?, tenant_code_masked = ? WHERE room_id = ?");
        return $stmt->execute([$hash, $encrypted, $masked, $roomId]);
    }

    public function markAsOccupied($roomId, $tenantName) {
        $stmt = $this->conn->prepare("UPDATE rooms SET status = 'occupied', tenant_name = ?, tenant_start_date = CURDATE() WHERE room_id = ?");
        return $stmt->execute([$tenantName, $roomId]);
    }

    public function markAsVacant($roomId) {
        $stmt = $this->conn->prepare("UPDATE rooms SET status = 'vacant', tenant_name = NULL, tenant_start_date = NULL, tenant_code_hash = NULL, tenant_code_encrypted = NULL, tenant_code_masked = NULL WHERE room_id = ?");
        return $stmt->execute([$roomId]);
    }

    public function updateLastSeen($roomId) {
        $stmt = $this->conn->prepare("UPDATE rooms SET last_seen = NOW() WHERE room_id = ?");
        return $stmt->execute([$roomId]);
    }

    public function createRoom($data) {
        $stmt = $this->conn->prepare("
            INSERT INTO rooms (room_id, room_type, max_occupancy, status, tenant_code_hash, tenant_code_encrypted, tenant_code_masked)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $data['room_id'],
            !empty($data['room_type']) ? $data['room_type'] : 'Standard',
            isset($data['max_occupancy']) && $data['max_occupancy'] !== '' ? $data['max_occupancy'] : 1,
            !empty($data['status']) ? $data['status'] : 'vacant',
            $data['tenant_code_hash'] ?? null,
            $data['tenant_code_encrypted'] ?? null,
            $data['tenant_code_masked'] ?? null
        ]);
    }

    public function updateRoom($roomId, $data) {
        $stmt = $this->conn->prepare("
            UPDATE rooms 
            SET room_type = ?, max_occupancy = ?, status = ?
            WHERE room_id = ?
        ");
        return $stmt->execute([
            !empty($data['room_type']) ? $data['room_type'] : 'Standard',
            isset($data['max_occupancy']) && $data['max_occupancy'] !== '' ? $data['max_occupancy'] : 1,
            !empty($data['status']) ? $data['status'] : 'vacant',
            $roomId
        ]);
    }

    public function archiveRoom($roomId, $userId) {
        $stmt = $this->conn->prepare("UPDATE rooms SET status = 'archived' WHERE room_id = ?");
        return $stmt->execute([$roomId]);
    }

    public function restoreRoom($roomId) {
        $stmt = $this->conn->prepare("UPDATE rooms SET status = 'vacant' WHERE room_id = ?");
        return $stmt->execute([$roomId]);
    }
}
