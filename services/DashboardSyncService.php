<?php

class DashboardSyncService {
    private $conn;
    private static $overviewCache = [];
    private static $cacheDuration = 3; // 3 seconds transient burst cache

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getLiveOverview($userId, $role, $forceFresh = false) {
        $cacheKey = "{$userId}_{$role}";
        $now = time();
        if (!$forceFresh && isset(self::$overviewCache[$cacheKey]) && (self::$overviewCache[$cacheKey]['expires_at'] > $now)) {
            return self::$overviewCache[$cacheKey]['data'];
        }

        // Aggregate Real-time Dashboard Statistics
        $result = [
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

        self::$overviewCache[$cacheKey] = [
            'expires_at' => $now + self::$cacheDuration,
            'data' => $result
        ];

        return $result;
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

            // Also count rooms with active consumption as a cross-check for occupied (Fast indexed query on rooms)
            $activeQ = "SELECT COUNT(*) as active FROM rooms WHERE status != 'archived' AND (status = 'occupied' OR (last_seen IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 30 DAY)))";
            $activeStmt = $this->conn->query($activeQ);
            $activeRooms = $activeStmt->fetch(PDO::FETCH_ASSOC)['active'];

            // Revenue — strictly calculated from COMPLETED billing cycles (Actual generated bills without double-counting previous_balance)
            $revQ = "SELECT 
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN (COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)) ELSE COALESCE(amount_paid, 0) END), 0) as collected,
                COALESCE(SUM(CASE WHEN payment_status IN ('unpaid', 'overdue', 'partially_paid', 'pending_verification') THEN GREATEST(0.00, (COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)) - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as outstanding,
                COALESCE(SUM(COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)), 0) as totalBilled,
                COALESCE(SUM(CASE WHEN payment_status = 'pending_verification' THEN GREATEST(0.00, (COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)) - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as pendingVerificationAmount,
                COALESCE(SUM(CASE WHEN payment_status = 'overdue' THEN GREATEST(0.00, (COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)) - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as overdueAmount
                FROM billing_cycles WHERE status = 'completed'";
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
                'totalBilled' => (float)$rev['totalBilled'],
                'pendingVerificationRevenue' => (float)$rev['pendingVerificationAmount'],
                'overdueRevenue' => (float)$rev['overdueAmount']
            ];
        } catch (Exception $e) {
            error_log("[DashboardSync] getStatistics error: " . $e->getMessage());
            return [
                'totalRooms' => 0, 'occupiedRooms' => 0, 'vacantRooms' => 0,
                'maintenanceRooms' => 0, 'notAvailableRooms' => 0, 'activeConsumptionRooms' => 0,
                'totalTenants' => 0, 'monthlyRevenue' => 0, 'outstandingRevenue' => 0, 'totalBilled' => 0,
                'pendingVerificationRevenue' => 0, 'overdueRevenue' => 0
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
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $todayEnergy = $row ? ($row['totalEnergy'] ?? 0) : 0;

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
            // Use billing_cycles as the source of truth for payment status (standalone invoice calculation)
            $q = "SELECT 
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN payment_status = 'pending_verification' THEN 1 ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) as verified,
                COALESCE(SUM(CASE WHEN payment_status = 'overdue' THEN 1 ELSE 0 END), 0) as overdue,
                COALESCE(SUM(COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)), 0) as totalAmount,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN (COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)) ELSE COALESCE(amount_paid, 0) END), 0) as collectedAmount,
                COALESCE(SUM(CASE WHEN payment_status IN ('unpaid', 'overdue', 'partially_paid', 'pending_verification') THEN GREATEST(0.00, (COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)) - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as outstandingAmount
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
            $q = "SELECT p.*, 
                         u.name as tenant_name,
                         COALESCE(NULLIF(r.room_name, ''), p.room_id) as room_name,
                         b.invoice_number,
                         b.cycle_start,
                         b.cycle_end,
                         b.due_date,
                         (COALESCE(b.electricity_charge, b.total_cost, 0) + COALESCE(b.miscellaneous_fee, 0) + COALESCE(b.monthly_rent, 0) + COALESCE(b.additional_charges, 0) - COALESCE(b.discounts, 0) + COALESCE(b.penalty_amount, 0)) as expected_amount
                  FROM payments p
                  LEFT JOIN users u ON p.tenant_id = u.id
                  LEFT JOIN rooms r ON p.room_id = r.room_id
                  LEFT JOIN billing_cycles b ON p.billing_cycle_id = b.id
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
            $q = "SELECT b.id, b.room_id, b.invoice_number, b.total_cost, b.penalty_amount, b.due_date, b.payment_status, 
                         COALESCE(NULLIF(b.tenant_name, ''), u.name, r.tenant_name, 'Tenant') as tenant_name,
                         COALESCE(NULLIF(r.room_name, ''), b.room_id) as room_name,
                         u.id as tenant_id,
                         b.grand_total, b.amount_paid, b.miscellaneous_fee, b.electricity_charge, b.monthly_rent,
                         GREATEST(0.00, (COALESCE(b.electricity_charge, b.total_cost, 0) + COALESCE(b.miscellaneous_fee, 0) + COALESCE(b.monthly_rent, 0) + COALESCE(b.additional_charges, 0) - COALESCE(b.discounts, 0) + COALESCE(b.penalty_amount, 0)) - COALESCE(b.amount_paid, 0.00)) as outstanding_balance,
                         GREATEST(0.00, (COALESCE(b.electricity_charge, b.total_cost, 0) + COALESCE(b.miscellaneous_fee, 0) + COALESCE(b.monthly_rent, 0) + COALESCE(b.additional_charges, 0) - COALESCE(discounts, 0)) - COALESCE(b.amount_paid, 0.00)) as original_balance
                  FROM billing_cycles b
                  JOIN rooms r ON b.room_id = r.room_id AND r.status != 'archived'
                  LEFT JOIN users u ON u.room_id = b.room_id AND u.role = 'tenant'
                  WHERE b.status = 'completed' 
                    AND (b.payment_status = 'unpaid' OR b.payment_status = 'overdue' OR b.payment_status = 'partially_paid')
                    AND ((COALESCE(b.electricity_charge, b.total_cost, 0) + COALESCE(b.miscellaneous_fee, 0) + COALESCE(b.monthly_rent, 0) + COALESCE(b.additional_charges, 0) - COALESCE(b.discounts, 0) + COALESCE(b.penalty_amount, 0)) - COALESCE(b.amount_paid, 0.00)) > 0.00
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
            // Highly optimized: 1 single consolidated query replaces 6 separate round-trips
            $q = "SELECT 
                COUNT(CASE WHEN payment_status = 'unpaid' AND DATE(due_date) = CURDATE() AND status = 'completed' THEN 1 END) as billsDueToday,
                COUNT(CASE WHEN payment_status = 'unpaid' AND DATE(due_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND status = 'completed' THEN 1 END) as billsDueTomorrow,
                COUNT(CASE WHEN payment_status = 'overdue' AND status = 'completed' AND ((COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)) - COALESCE(amount_paid, 0.00)) > 0.00 THEN 1 END) as overdueBills,
                COUNT(CASE WHEN penalty_amount > 0 AND payment_status = 'overdue' AND status = 'completed' AND ((COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)) - COALESCE(amount_paid, 0.00)) > 0.00 THEN 1 END) as billsWithPenalties,
                COALESCE(SUM(CASE WHEN payment_status IN ('overdue', 'unpaid', 'partially_paid', 'pending_verification') AND status = 'completed' THEN GREATEST(0.00, (COALESCE(electricity_charge, total_cost, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(monthly_rent, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) + COALESCE(penalty_amount, 0)) - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as totalOutstanding,
                COALESCE(SUM(CASE WHEN penalty_amount > 0 AND payment_status = 'paid' AND status = 'completed' THEN penalty_amount ELSE 0 END), 0) as totalPenaltiesCollected
            FROM billing_cycles";
            $row = $this->conn->query($q)->fetch(PDO::FETCH_ASSOC);

            return [
                'billsDueToday' => (int)($row['billsDueToday'] ?? 0),
                'billsDueTomorrow' => (int)($row['billsDueTomorrow'] ?? 0),
                'overdueBills' => (int)($row['overdueBills'] ?? 0),
                'billsWithPenalties' => (int)($row['billsWithPenalties'] ?? 0),
                'totalOutstanding' => (float)($row['totalOutstanding'] ?? 0),
                'totalPenaltiesCollected' => (float)($row['totalPenaltiesCollected'] ?? 0),
            ];
        } catch (Exception $e) {
            error_log("[DashboardSync] getPenaltyAnalytics error: " . $e->getMessage());
            return null;
        }
    }
}
