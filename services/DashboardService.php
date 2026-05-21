<?php
require_once __DIR__ . '/../repositories/DashboardRepository.php';

require_once __DIR__ . '/../repositories/UserRepository.php';

class DashboardService {
    private $dashboardRepo;
    private $userRepo;
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        $this->dashboardRepo = new DashboardRepository($dbConnection);
        $this->userRepo = new UserRepository($dbConnection);
    }

    private function resolveIdentifier($roomId, $userId, $role) {
        return ['column' => 'room_id', 'value' => $roomId];
    }

    private function checkAndResetBillingCycle($user) {
        if ($user['role'] !== 'tenant') return $user;
        
        $currentDate = date('Y-m-d');
        $endDate = $user['billing_end_date'];
        
        if ($endDate && $currentDate >= $endDate) {
            // Cycle has ended, reset to new cycle
            $stmt = $this->conn->prepare("UPDATE users SET billing_start_date = billing_end_date, billing_end_date = DATE_ADD(billing_end_date, INTERVAL 1 MONTH) WHERE id = ?");
            $stmt->execute([$user['id']]);
            // Re-fetch user
            return $this->userRepo->findById($user['id']);
        }
        return $user;
    }

    public function getBillingCycleData($roomId, $userId, $role) {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        
        // Landlords querying a room need to find the tenant for that room to get their billing dates
        if ($role === 'landlord') {
            $tenant = $this->userRepo->findTenantByRoom($roomId);
            if (!$tenant) return ['success' => false, 'message' => 'No tenant found for this room'];
            $fullUser = $this->userRepo->findById($tenant['id']);
        } else {
            $fullUser = $this->userRepo->findById($userId);
        }
        
        if (!$fullUser || !$fullUser['billing_start_date'] || !$fullUser['billing_end_date']) {
            return ['success' => false, 'message' => 'Billing cycle not initialized'];
        }

        $fullUser = $this->checkAndResetBillingCycle($fullUser);
        
        $start = $fullUser['billing_start_date'] . ' 00:00:00';
        $end = $fullUser['billing_end_date'] . ' 00:00:00';
        
        $consumption = $this->dashboardRepo->getTotalConsumption($id['column'], $id['value'], $start, $end);
        
        return [
            'success' => true,
            'data' => [
                'billing_start_date' => $fullUser['billing_start_date'],
                'billing_end_date' => $fullUser['billing_end_date'],
                'move_in_date' => $fullUser['move_in_date'],
                'consumption' => $consumption
            ]
        ];
    }

    public function getTotalConsumptionToday($roomId, $userId, $role) {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
        return ['success' => true, 'data' => $this->dashboardRepo->getTotalConsumption($id['column'], $id['value'], $start, $end)];
    }

    public function getTotalConsumptionWeek($roomId, $userId, $role) {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $dayOfWeek = date('w');
        $offset = ($dayOfWeek == 0 ? 6 : $dayOfWeek - 1);
        $start = date('Y-m-d 00:00:00', strtotime("-$offset days"));
        $end = date('Y-m-d 00:00:00', strtotime('+1 day')); // Up to right now essentially
        return ['success' => true, 'data' => $this->dashboardRepo->getTotalConsumption($id['column'], $id['value'], $start, $end)];
    }

    public function getTotalConsumptionMonth($roomId, $userId, $role) {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $start = date('Y-m-01 00:00:00');
        $end = date('Y-m-d 00:00:00', strtotime('first day of next month'));
        return ['success' => true, 'data' => $this->dashboardRepo->getTotalConsumption($id['column'], $id['value'], $start, $end)];
    }

    public function getMonthlyConsumptionFiltered($roomId, $userId, $role, $year, $month) {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end = date('Y-m-d 00:00:00', strtotime('+1 month', strtotime($start)));
        return ['success' => true, 'data' => $this->dashboardRepo->getTotalConsumption($id['column'], $id['value'], $start, $end)];
    }

    public function getHourlyBreakdown($roomId, $userId, $role) {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
        return ['success' => true, 'data' => $this->dashboardRepo->getHourlyBreakdown($id['column'], $id['value'], $start, $end)];
    }

    public function getDailyBreakdownFiltered($roomId, $userId, $role, $year, $month) {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end = date('Y-m-d 00:00:00', strtotime('+1 month', strtotime($start)));
        return ['success' => true, 'data' => $this->dashboardRepo->getDailyBreakdown($id['column'], $id['value'], $start, $end)];
    }

    public function getConsumptionComparison($roomId, $userId, $role, $period = 'weekly') {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $col = $id['column'];
        $val = $id['value'];

        $curr = ['totalEnergy' => 0, 'totalCost' => 0];
        $prev = ['totalEnergy' => 0, 'totalCost' => 0];

        if ($period === 'daily') {
            $currStart = date('Y-m-d 00:00:00');
            $currEnd = date('Y-m-d H:i:s');
            $prevStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $prevEnd = date('Y-m-d H:i:s', strtotime('-1 day'));
        } elseif ($period === 'weekly') {
            $dayOfWeek = date('w');
            $offset = ($dayOfWeek == 0 ? 6 : $dayOfWeek - 1);
            $currStart = date('Y-m-d 00:00:00', strtotime("-$offset days"));
            $currEnd = date('Y-m-d H:i:s');
            $prevStart = date('Y-m-d 00:00:00', strtotime("-".($offset + 7)." days"));
            $prevEnd = date('Y-m-d H:i:s', strtotime("-7 days"));
        } else {
            $currStart = date('Y-m-01 00:00:00');
            $currEnd = date('Y-m-d H:i:s');
            $prevStart = date('Y-m-01 00:00:00', strtotime('-1 month'));
            $prevEnd = date('Y-m-d H:i:s', strtotime('-1 month'));
        }

        $currData = $this->dashboardRepo->getTotalConsumption($col, $val, $currStart, $currEnd);
        $prevData = $this->dashboardRepo->getTotalConsumption($col, $val, $prevStart, $prevEnd);

        // Calculate anomalies and budget check
        $isAbnormal = false;
        if ($prevData['totalEnergy'] > 0) {
            $pctChange = (($currData['totalEnergy'] - $prevData['totalEnergy']) / $prevData['totalEnergy']) * 100;
            if (abs($pctChange) >= 30) $isAbnormal = true;
        }

        return [
            'success' => true,
            'data' => [
                'current' => $currData,
                'previous' => $prevData,
                'isAbnormal' => $isAbnormal,
                'energyPctChange' => ($prevData['totalEnergy'] > 0) ? (($currData['totalEnergy'] - $prevData['totalEnergy']) / $prevData['totalEnergy']) * 100 : 0,
                'costPctChange' => ($prevData['totalCost'] > 0) ? (($currData['totalCost'] - $prevData['totalCost']) / $prevData['totalCost']) * 100 : 0
            ]
        ];
    }

    public function getTransactionHistory($roomId, $userId, $role, $limit, $offset, $filter = 'minute', $dateString = null) {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        
        $grouped = [];

        if ($filter === 'minute') {
            $rawLogs = $this->dashboardRepo->getTransactionHistory($id['column'], $id['value'], $limit, $offset, $dateString);
            foreach ($rawLogs as $log) {
                $dateTitle = date('F j, Y', strtotime($log['timestamp']));
                $log['cost'] = abs((float)$log['cost']); // Fix negative bug
                $log['time_label'] = date('g:i A', strtotime($log['timestamp']));
                if (!isset($grouped[$dateTitle])) {
                    $grouped[$dateTitle] = [];
                }
                $grouped[$dateTitle][] = $log;
            }
        } else {
            $rawLogs = $this->dashboardRepo->getGroupedHistory($id['column'], $id['value'], $filter, $limit, $offset);
            foreach ($rawLogs as $log) {
                $log['cost'] = abs((float)$log['totalCost']);
                $log['energy'] = (float)$log['totalEnergy'];
                $log['power'] = (float)$log['avgPower'];
                
                if ($filter === 'daily') {
                    $dateTitle = date('F Y', strtotime($log['group_date']));
                    $log['time_label'] = date('F j', strtotime($log['group_date']));
                } else if ($filter === 'weekly') {
                    $dateTitle = substr($log['group_date'], 0, 4); // Year
                    $log['time_label'] = 'Week ' . substr($log['group_date'], -2);
                } else { // monthly
                    $dateTitle = substr($log['group_date'], 0, 4); // Year
                    $log['time_label'] = date('F', strtotime($log['group_date'] . '-01'));
                }

                if (!isset($grouped[$dateTitle])) {
                    $grouped[$dateTitle] = [];
                }
                $grouped[$dateTitle][] = $log;
            }
        }

        $formattedResponse = [];
        foreach ($grouped as $title => $records) {
            $formattedResponse[] = [
                'title' => $title,
                'data' => $records
            ];
        }

        return ['success' => true, 'data' => $formattedResponse];
    }
}
