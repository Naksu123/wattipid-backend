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
                'pendingPayments' => $this->getPendingPayments($userId, $role),
                'unpaidBills' => $this->getUnpaidBills($userId, $role),
                'penaltyAnalytics' => $this->getPenaltyAnalytics($role)
            ]
        ];
    }

    private function getStatistics($userId, $role) {
        try {
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
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN grand_total ELSE COALESCE(amount_paid, 0) END), 0) as collected,
                COALESCE(SUM(CASE WHEN payment_status IN ('unpaid', 'overdue', 'partially_paid') AND status = 'completed' THEN GREATEST(0.00, grand_total - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as outstanding,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN grand_total ELSE 0 END), 0) as totalBilled
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
        } catch (Exception $e) {
            error_log("[DashboardSync] getStatistics error: " . $e->getMessage());
            return [
                'totalRooms' => 0, 'occupiedRooms' => 0, 'vacantRooms' => 0,
                'maintenanceRooms' => 0, 'notAvailableRooms' => 0, 'activeConsumptionRooms' => 0,
                'totalTenants' => 0, 'monthlyRevenue' => 0, 'outstandingRevenue' => 0, 'totalBilled' => 0
            ];
        }
    }

    private function getLiveElectricity($userId, $role) {
        try {
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
        } catch (Exception $e) {
            error_log("[DashboardSync] getLiveElectricity error: " . $e->getMessage());
            return ['todayEnergyKwh' => 0, 'livePeakPowerW' => 0];
        }
    }

    private function getRecentActivities($limit = 5) {
        try {
            $q = "SELECT a.id, a.type as action_type, a.message as description, a.created_at, u.name as actor_name 
                  FROM activity_logs a 
                  LEFT JOIN users u ON a.user_id = u.id 
                  ORDER BY a.created_at DESC LIMIT :limit";
            $stmt = $this->conn->prepare($q);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("[DashboardSync] getRecentActivities error: " . $e->getMessage());
            return [];
        }
    }

    private function getPaymentSummary($userId, $role) {
        try {
            // Use billing_cycles as the source of truth for payment status
            $q = "SELECT 
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN payment_status = 'unpaid' OR payment_status = 'overdue' OR payment_status = 'partially_paid' THEN 1 ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) as verified,
                COALESCE(SUM(grand_total), 0) as totalAmount,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN grand_total ELSE COALESCE(amount_paid, 0) END), 0) as collectedAmount,
                COALESCE(SUM(CASE WHEN payment_status IN ('unpaid', 'overdue', 'partially_paid') THEN GREATEST(0.00, grand_total - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as outstandingAmount
                FROM billing_cycles WHERE status = 'completed'";
            $stmt = $this->conn->query($q);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $result['rejected'] = 0; // No rejected concept in billing_cycles
            return $result;
        } catch (Exception $e) {
            error_log("[DashboardSync] getPaymentSummary error: " . $e->getMessage());
            return [
                'total' => 0, 'pending' => 0, 'verified' => 0, 'rejected' => 0,
                'totalAmount' => 0, 'collectedAmount' => 0, 'outstandingAmount' => 0
            ];
        }
    }

    private function getPendingPayments($userId, $role) {
        if ($role !== 'landlord') return [];

        try {
            $q = "SELECT p.*, u.name as tenant_name 
                  FROM payments p
                  LEFT JOIN users u ON p.tenant_id = u.id
                  WHERE p.status = 'pending'
                  ORDER BY p.created_at ASC";
            $stmt = $this->conn->query($q);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("[DashboardSync] getPendingPayments error: " . $e->getMessage());
            return [];
        }
    }

    private function getUnpaidBills($userId, $role) {
        if ($role !== 'landlord') return [];

        try {
            $q = "SELECT b.id, b.room_id, b.total_cost, b.penalty_amount, b.due_date, b.payment_status, 
                         b.tenant_name, r.room_name, u.id as tenant_id,
                         b.grand_total, b.amount_paid, b.miscellaneous_fee, b.electricity_charge,
                         GREATEST(0.00, b.grand_total - COALESCE(b.amount_paid, 0.00)) as outstanding_balance,
                         GREATEST(0.00, b.grand_total - COALESCE(b.penalty_amount, 0) - COALESCE(b.amount_paid, 0.00)) as original_balance
                  FROM billing_cycles b
                  JOIN rooms r ON b.room_id = r.room_id AND r.status = 'occupied'
                  JOIN users u ON u.room_id = b.room_id AND u.role = 'tenant' AND (u.name = b.tenant_name OR r.tenant_name = b.tenant_name)
                  WHERE b.status = 'completed' 
                    AND (b.payment_status = 'unpaid' OR b.payment_status = 'overdue' OR b.payment_status = 'partially_paid')
                    AND (b.grand_total - COALESCE(b.amount_paid, 0.00)) > 0.00
                  ORDER BY b.due_date ASC";
            $stmt = $this->conn->query($q);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("[DashboardSync] getUnpaidBills error: " . $e->getMessage());
            return [];
        }
    }

    private function getPenaltyAnalytics($role) {
        if ($role !== 'landlord') return null;
        
        try {
            $billsDueToday = $this->conn->query("SELECT COUNT(*) FROM billing_cycles WHERE payment_status = 'unpaid' AND DATE(due_date) = CURDATE() AND status = 'completed'")->fetchColumn();
            $billsDueTomorrow = $this->conn->query("SELECT COUNT(*) FROM billing_cycles WHERE payment_status = 'unpaid' AND DATE(due_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND status = 'completed'")->fetchColumn();
            $overdueBills = $this->conn->query("SELECT COUNT(*) FROM billing_cycles WHERE payment_status = 'overdue' AND (grand_total - COALESCE(amount_paid, 0.00)) > 0.00")->fetchColumn();
            $billsWithPenalties = $this->conn->query("SELECT COUNT(*) FROM billing_cycles WHERE penalty_amount > 0 AND payment_status = 'overdue' AND (grand_total - COALESCE(amount_paid, 0.00)) > 0.00")->fetchColumn();
            $totalOutstanding = $this->conn->query("SELECT COALESCE(SUM(GREATEST(0.00, grand_total - COALESCE(amount_paid, 0))), 0) FROM billing_cycles WHERE payment_status IN ('overdue', 'unpaid', 'partially_paid') AND status = 'completed'")->fetchColumn();
            $totalPenaltiesCollected = $this->conn->query("SELECT COALESCE(SUM(penalty_amount), 0) FROM billing_cycles WHERE penalty_amount > 0")->fetchColumn();

            return [
                'billsDueToday' => (int)$billsDueToday,
                'billsDueTomorrow' => (int)$billsDueTomorrow,
                'overdueBills' => (int)$overdueBills,
                'billsWithPenalties' => (int)$billsWithPenalties,
                'totalOutstanding' => (float)$totalOutstanding,
                'totalPenaltiesCollected' => (float)$totalPenaltiesCollected,
            ];
        } catch (Exception $e) {
            error_log("[DashboardSync] getPenaltyAnalytics error: " . $e->getMessage());
            return null;
        }
    }
}
