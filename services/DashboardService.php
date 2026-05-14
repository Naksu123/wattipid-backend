<?php
require_once __DIR__ . '/../repositories/DashboardRepository.php';

class DashboardService {
    private $dashboardRepo;

    public function __construct($dbConnection) {
        $this->dashboardRepo = new DashboardRepository($dbConnection);
    }

    private function resolveIdentifier($roomId, $userId, $role) {
        if ($role === 'tenant') {
            return ['column' => 'user_id', 'value' => $userId];
        }
        return ['column' => 'room_id', 'value' => $roomId];
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

    public function getTransactionHistory($roomId, $userId, $role, $limit, $offset) {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $data = $this->dashboardRepo->getTransactionHistory($id['column'], $id['value'], $limit, $offset);
        return ['success' => true, 'data' => $data];
    }
}
