<?php

class DashboardSyncService {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getLiveOverview($userId, $role) {
        // Aggregate Real-time Dashboard Statistics
        return [
            'success' => true,
            'data' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'statistics' => $this->getStatistics($userId, $role),
                'liveElectricity' => $this->getLiveElectricity($userId, $role),
                'recentActivities' => $this->getRecentActivities(5),
                'paymentSummary' => $this->getPaymentSummary($userId, $role),
                'pendingPayments' => $this->getPendingPayments($userId, $role)
            ]
        ];
    }

    private function getStatistics($userId, $role) {
        // Rooms — exclude archived rooms from dashboard statistics
        $roomQ = "SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END), 0) as occupied,
            COALESCE(SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END), 0) as vacant,
            COALESCE(SUM(CASE WHEN status = 'under_maintenance' THEN 1 ELSE 0 END), 0) as maintenance,
            COALESCE(SUM(CASE WHEN status = 'not_available' THEN 1 ELSE 0 END), 0) as not_available
            FROM rooms WHERE status != 'archived'";
        $roomStmt = $this->conn->query($roomQ);
        $rooms = $roomStmt->fetch(PDO::FETCH_ASSOC);

        // Tenants — count users with role 'tenant' who are assigned to a room
        $tenantQ = "SELECT COUNT(*) as total FROM users WHERE role = 'tenant'";
        $tenantStmt = $this->conn->query($tenantQ);
        $tenants = $tenantStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Also count rooms with active consumption as a cross-check for occupied
        $activeQ = "SELECT COUNT(DISTINCT room_id) as active FROM consumption_logs 
                    WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $activeStmt = $this->conn->query($activeQ);
        $activeRooms = $activeStmt->fetch(PDO::FETCH_ASSOC)['active'];

        // Revenue — strictly calculated from COMPLETED billing cycles (Actual generated bills)
        $revQ = "SELECT 
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN (total_cost + penalty_amount) ELSE 0 END), 0) as collected,
            COALESCE(SUM(CASE WHEN payment_status IN ('unpaid', 'overdue') AND status = 'completed' THEN (total_cost + penalty_amount) ELSE 0 END), 0) as outstanding,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN (total_cost + penalty_amount) ELSE 0 END), 0) as totalBilled
            FROM billing_cycles";
        $revStmt = $this->conn->query($revQ);
        $rev = $revStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'totalRooms' => (int)$rooms['total'],
            'occupiedRooms' => (int)$rooms['occupied'],
            'vacantRooms' => (int)$rooms['vacant'],
            'maintenanceRooms' => (int)$rooms['maintenance'],
            'notAvailableRooms' => (int)$rooms['not_available'],
            'activeConsumptionRooms' => (int)$activeRooms,
            'totalTenants' => (int)$tenants,
            'monthlyRevenue' => (float)$rev['collected'],
            'outstandingRevenue' => (float)$rev['outstanding'],
            'totalBilled' => (float)$rev['totalBilled']
        ];
    }

    private function getLiveElectricity($userId, $role) {
        $today = date('Y-m-d');
        // Total electricity consumed today
        $q = "SELECT COALESCE(SUM(energy), 0) as totalEnergy FROM consumption_logs WHERE DATE(timestamp) = :today";
        $stmt = $this->conn->prepare($q);
        $stmt->execute(['today' => $today]);
        $todayEnergy = $stmt->fetch(PDO::FETCH_ASSOC)['totalEnergy'];

        // Get live peak
        $pq = "SELECT COALESCE(MAX(power), 0) as peakPower FROM consumption_logs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
        $pstmt = $this->conn->query($pq);
        $peak = $pstmt->fetch(PDO::FETCH_ASSOC)['peakPower'];

        return [
            'todayEnergyKwh' => (float)$todayEnergy,
            'livePeakPowerW' => (float)$peak
        ];
    }

    private function getRecentActivities($limit = 5) {
        $q = "SELECT a.*, u.name as actor_name 
              FROM activity_logs a 
              LEFT JOIN users u ON a.actor_id = u.id 
              ORDER BY a.created_at DESC LIMIT :limit";
        $stmt = $this->conn->prepare($q);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPaymentSummary($userId, $role) {
        // Use billing_cycles as the source of truth for payment status
        $q = "SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' OR payment_status = 'overdue' THEN 1 ELSE 0 END), 0) as pending,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) as verified,
            COALESCE(SUM(total_cost + COALESCE(penalty_amount, 0)), 0) as totalAmount,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN (total_cost + COALESCE(penalty_amount, 0)) ELSE 0 END), 0) as collectedAmount,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' OR payment_status = 'overdue' THEN (total_cost + COALESCE(penalty_amount, 0)) ELSE 0 END), 0) as outstandingAmount
            FROM billing_cycles WHERE status = 'completed'";
        $stmt = $this->conn->query($q);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $result['rejected'] = 0; // No rejected concept in billing_cycles
        return $result;
    }

    private function getPendingPayments($userId, $role) {
        if ($role !== 'landlord') return [];

        $q = "SELECT p.*, u.name as tenant_name 
              FROM payments p
              LEFT JOIN users u ON p.tenant_id = u.id
              WHERE p.status = 'pending'
              ORDER BY p.created_at ASC";
        $stmt = $this->conn->query($q);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
