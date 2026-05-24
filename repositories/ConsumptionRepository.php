<?php
class ConsumptionRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getLastLog($roomId) {
        $stmt = $this->conn->prepare("SELECT timestamp, energy_cumulative FROM consumption_logs WHERE room_id = ? ORDER BY timestamp DESC LIMIT 1");
        $stmt->execute([$roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function insertLog($roomId, $tenantName, $voltage, $current, $power, $energyDelta, $cumulativeEnergy, $cost, $billingCycleId = null) {
        $stmt = $this->conn->prepare("INSERT INTO consumption_logs (room_id, tenant_name, voltage, current_val, power, energy, energy_cumulative, cost, billing_cycle_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$roomId, $tenantName, $voltage, $current, $power, $energyDelta, $cumulativeEnergy, $cost, $billingCycleId]);
    }

    public function getRollingAveragePower($roomId, $limit = 10) {
        $limit = (int) $limit;
        $stmt = $this->conn->prepare("SELECT AVG(power) as avg_p FROM (SELECT power FROM consumption_logs WHERE room_id = ? ORDER BY timestamp DESC LIMIT {$limit}) as last_readings");
        $stmt->execute([$roomId]);
        $row = $stmt->fetch();
        return $row ? (float) $row['avg_p'] : 0;
    }

    public function getTrendReadings($roomId, $limit = 3) {
        $limit = (int) $limit;
        $stmt = $this->conn->prepare("SELECT power FROM consumption_logs WHERE room_id = ? ORDER BY timestamp DESC LIMIT {$limit}");
        $stmt->execute([$roomId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getConsumptionTotals($roomId) {
        // Optimized using SARGable PHP boundaries
        $startOfToday = date('Y-m-d 00:00:00');
        
        // Find start of current week (Monday)
        $dayOfWeek = date('w');
        $offsetToMonday = ($dayOfWeek == 0 ? 6 : $dayOfWeek - 1);
        $startOfWeek = date('Y-m-d 00:00:00', strtotime("-$offsetToMonday days"));
        
        $startOfMonth = date('Y-m-01 00:00:00');
        
        $stmt = $this->conn->prepare("SELECT 
            SUM(CASE WHEN timestamp >= ? THEN cost ELSE 0 END) as total_daily,
            SUM(CASE WHEN timestamp >= ? THEN cost ELSE 0 END) as total_weekly,
            SUM(CASE WHEN timestamp >= ? THEN cost ELSE 0 END) as total_monthly
            FROM consumption_logs WHERE room_id = ?");
        $stmt->execute([$startOfToday, $startOfWeek, $startOfMonth, $roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
