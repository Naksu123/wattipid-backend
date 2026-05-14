<?php
class RoomRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getAllRooms() {
        $stmt = $this->conn->prepare("SELECT * FROM rooms ORDER BY room_id ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($roomId) {
        $stmt = $this->conn->prepare("SELECT * FROM rooms WHERE room_id = ?");
        $stmt->execute([$roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByTenantCode($code) {
        $stmt = $this->conn->prepare("SELECT * FROM rooms WHERE tenant_code = ?");
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByUserIdOrTenantName($userId, $tenantName) {
        $stmt = $this->conn->prepare("SELECT r.*, u.id as user_id FROM rooms r LEFT JOIN users u ON r.room_id = u.room_id WHERE u.id = ? OR r.tenant_name = ?");
        $stmt->execute([$userId, $tenantName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBuildingSummary() {
        $stmt = $this->conn->query("SELECT 
            COUNT(*) as totalRooms,
            COALESCE(SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END), 0) as occupiedRooms,
            COALESCE(SUM(CASE WHEN status = 'on_process' THEN 1 ELSE 0 END), 0) as onProcessRooms,
            COALESCE(SUM(CASE WHEN last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE) OR last_seen IS NULL THEN 1 ELSE 0 END), 0) as offlineMeters
            FROM rooms");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getRoomsWithConsumption($currStart, $nextStart, $prevStart) {
        $stmt = $this->conn->prepare("
            SELECT r.*, 
                   COALESCE(curr.totalEnergy, 0) as currEnergy,
                   COALESCE(prev.totalEnergy, 0) as prevEnergy
            FROM rooms r
            LEFT JOIN (
                SELECT room_id, SUM(energy) as totalEnergy 
                FROM consumption_logs 
                WHERE timestamp >= ? AND timestamp < ?
                GROUP BY room_id
            ) curr ON r.room_id = curr.room_id
            LEFT JOIN (
                SELECT room_id, SUM(energy) as totalEnergy 
                FROM consumption_logs 
                WHERE timestamp >= ? AND timestamp < ?
                GROUP BY room_id
            ) prev ON r.room_id = prev.room_id
            ORDER BY r.room_id ASC
        ");
        $stmt->execute([$currStart, $nextStart, $prevStart, $currStart]);
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

    public function updateTenantCode($roomId, $code) {
        $stmt = $this->conn->prepare("UPDATE rooms SET tenant_code = ? WHERE room_id = ?");
        return $stmt->execute([$code, $roomId]);
    }

    public function markAsOccupied($roomId, $tenantName) {
        $stmt = $this->conn->prepare("UPDATE rooms SET status = 'occupied', tenant_name = ?, tenant_start_date = CURDATE() WHERE room_id = ?");
        return $stmt->execute([$tenantName, $roomId]);
    }

    public function markAsVacant($roomId) {
        $stmt = $this->conn->prepare("UPDATE rooms SET status = 'vacant', tenant_name = NULL, tenant_start_date = NULL, tenant_code = NULL WHERE room_id = ?");
        return $stmt->execute([$roomId]);
    }

    public function updateLastSeen($roomId) {
        $stmt = $this->conn->prepare("UPDATE rooms SET last_seen = NOW() WHERE room_id = ?");
        return $stmt->execute([$roomId]);
    }
}
