<?php
class HistoryRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function archiveTenant($roomId, $tenantName, $tenantEmail, $startDate, $status = 'moved_out') {
        $stmt = $this->conn->prepare("INSERT INTO tenant_history (room_id, tenant_name, tenant_email, tenant_start_date, move_out_date, status) VALUES (?, ?, ?, ?, CURDATE(), ?)");
        return $stmt->execute([$roomId, $tenantName, $tenantEmail, $startDate, $status]);
    }

    public function findByRoom($roomId) {
        $stmt = $this->conn->prepare("SELECT * FROM tenant_history WHERE room_id = ? ORDER BY move_out_date DESC");
        $stmt->execute([$roomId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
