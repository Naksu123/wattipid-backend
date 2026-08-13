<?php
require_once __DIR__ . '/../repositories/DashboardRepository.php';

require_once __DIR__ . '/../repositories/UserRepository.php';

class DashboardService
{
    private $dashboardRepo;
    private $userRepo;
    private $conn;

    public function __construct($dbConnection)
    {
        $this->conn = $dbConnection;
        $this->dashboardRepo = new DashboardRepository($dbConnection);
        $this->userRepo = new UserRepository($dbConnection);
    }

    private function resolveIdentifier($roomId, $userId, $role)
    {
        return ['column' => 'room_id', 'value' => $roomId];
    }

    /**
     * Fetch the current configured rate_per_kwh from settings.
     * Falls back to 12.50 if not set.
     */
    private function getCurrentRate()
    {
        $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'rate_per_kwh' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val ? floatval($val) : 12.50;
    }

    /**
     * Recalculate totalCost from totalEnergy × current rate
     * so the dashboard always reflects the latest configured rate.
     */
    private function recalculateCost($data)
    {
        $rate = $this->getCurrentRate();
        $data['totalCost'] = round(floatval($data['totalEnergy'] ?? 0) * $rate, 2);
        return $data;
    }

    private function checkAndResetBillingCycle($user)
    {
        if ($user['role'] !== 'tenant')
            return $user;

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

    public function getBillingCycleData($roomId, $userId, $role)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);

        require_once __DIR__ . '/BillingCycleService.php';
        $billingService = new BillingCycleService($this->conn);
        $billingService->advanceCycleIfNeeded($id['value']);

        $stmt = $this->conn->prepare("SELECT cycle_start, cycle_end FROM billing_cycles WHERE room_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$id['value']]);
        $activeCycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activeCycle) {
            return ['success' => true, 'data' => null, 'message' => 'Billing cycle not initialized'];
        }

        $consumption = $this->dashboardRepo->getTotalConsumption($id['column'], $id['value'], $activeCycle['cycle_start'], $activeCycle['cycle_end']);

        require_once __DIR__ . '/BudgetService.php';
        $budgetService = new BudgetService($this->conn);
        $budgetRes = $budgetService->getBudget($id['value'], (int) date('m'), (int) date('Y'));

        return [
            'success' => true,
            'data' => [
                'cycle_start' => $activeCycle['cycle_start'],
                'cycle_end' => $activeCycle['cycle_end'],
                'consumption' => $consumption,
                'budget' => $budgetRes['data'] ?? null
            ]
        ];
    }

    public function getTotalConsumptionToday($roomId, $userId, $role)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
        return ['success' => true, 'data' => $this->recalculateCost($this->dashboardRepo->getTotalConsumption($id['column'], $id['value'], $start, $end))];
    }

    public function getTotalConsumptionWeek($roomId, $userId, $role)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);

        // Fetch active cycle start date
        $stmt = $this->conn->prepare("SELECT cycle_start FROM billing_cycles WHERE room_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$id['value']]);
        $activeCycleStart = $stmt->fetchColumn();

        $dayOfWeek = date('w');
        $offset = ($dayOfWeek == 0 ? 6 : $dayOfWeek - 1);
        $calendarStart = date('Y-m-d 00:00:00', strtotime("-$offset days"));

        // Ensure week start does not leak into previous cycle
        $start = ($activeCycleStart && $activeCycleStart > $calendarStart) ? $activeCycleStart : $calendarStart;
        $end = date('Y-m-d 00:00:00', strtotime('+1 day'));

        return ['success' => true, 'data' => $this->recalculateCost($this->dashboardRepo->getTotalConsumption($id['column'], $id['value'], $start, $end))];
    }

    public function getTotalConsumptionMonth($roomId, $userId, $role)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);

        require_once __DIR__ . '/BillingCycleService.php';
        $billingService = new BillingCycleService($this->conn);
        $billingService->advanceCycleIfNeeded($id['value']);

        // Use the new authoritative method for the Live Bill Breakdown
        $liveBill = $billingService->getLiveBillBreakdown($id['value']);

        if (!$liveBill['cycle_start']) {
            return ['success' => false, 'message' => 'No active billing cycle found.'];
        }

        // Map the breakdown to the legacy format expected by the frontend for compatibility,
        // while also exposing the full breakdown
        $data = [
            'totalEnergy' => $liveBill['consumptionKwh'],
            'totalCost' => $liveBill['totalAmountDue'], // "Live Bill" is the total amount due
            'electricityCharge' => $liveBill['electricityCharge'],
            'additionalCharges' => $liveBill['additionalCharges'],
            'monthlyRent' => $liveBill['monthlyRent'],
            'previousBalance' => $liveBill['previousBalance'],
            'penalty' => $liveBill['penalty'],
            'discounts' => $liveBill['discounts'],
            'ratePerKwh' => $liveBill['ratePerKwh'],
            'cycle_start' => $liveBill['cycle_start'],
            'cycle_end' => $liveBill['cycle_end'],
            'next_reset' => date('Y-m-d H:i:s', strtotime($liveBill['cycle_end'] . ' + 1 second'))
        ];

        // Also fetch move_in_date for the UI
        $stmtMoveIn = $this->conn->prepare("SELECT tenant_start_date FROM rooms WHERE room_id = ?");
        $stmtMoveIn->execute([$id['value']]);
        $data['tenant_start_date'] = $stmtMoveIn->fetchColumn();

        return ['success' => true, 'data' => $data];
    }

    private function getCycleBoundsForMonth($roomId, $year, $month)
    {
        $stmt = $this->conn->prepare("SELECT cycle_start, cycle_end FROM billing_cycles WHERE room_id = ? AND YEAR(cycle_start) = ? AND MONTH(cycle_start) = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$roomId, $year, $month]);
        $cycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cycle) {
            return [$cycle['cycle_start'], $cycle['cycle_end']];
        }

        // Fallback calculation for cycles that haven't been created yet or legacy
        $stmtMoveIn = $this->conn->prepare("SELECT tenant_start_date FROM rooms WHERE room_id = ?");
        $stmtMoveIn->execute([$roomId]);
        $moveInDate = $stmtMoveIn->fetchColumn();

        $startDay = $moveInDate ? sprintf('%02d', (int) date('d', strtotime($moveInDate))) : '01';
        $reqDate = sprintf('%04d-%02d-%s 00:00:00', $year, $month, $startDay);

        $start = date('Y-m-d 00:00:00', strtotime("-1 month", strtotime($reqDate)));
        $end = date('Y-m-d 00:00:00', strtotime($reqDate));

        return [$start, $end];
    }

    public function getMonthlyConsumptionFiltered($roomId, $userId, $role, $year, $month)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);

        $stmt = $this->conn->prepare("SELECT id FROM billing_cycles WHERE room_id = ? AND ( (YEAR(cycle_end) = ? AND MONTH(cycle_end) = ?) OR (YEAR(cycle_start) = ? AND MONTH(cycle_start) = ?) ) ORDER BY status ASC, id DESC LIMIT 1");
        $stmt->execute([$id['value'], $year, $month, $year, $month]);
        $cycleId = $stmt->fetchColumn();

        if ($cycleId) {
            require_once __DIR__ . '/BillingCycleService.php';
            $billingService = new BillingCycleService($this->conn);
            $liveBill = $billingService->getLiveBillBreakdown($id['value'], $cycleId);

            return [
                'success' => true,
                'data' => [
                    'totalEnergy' => $liveBill['consumptionKwh'],
                    'totalCost' => $liveBill['totalAmountDue'],
                    'electricityCharge' => $liveBill['electricityCharge'],
                    'additionalCharges' => $liveBill['additionalCharges'],
                    'monthlyRent' => $liveBill['monthlyRent'],
                    'previousBalance' => $liveBill['previousBalance'],
                    'penalty' => $liveBill['penalty'],
                    'discounts' => $liveBill['discounts'],
                    'ratePerKwh' => $liveBill['ratePerKwh']
                ]
            ];
        }

        // Fallback for periods with no cycle
        list($start, $end) = $this->getCycleBoundsForMonth($id['value'], $year, $month);
        $data = $this->dashboardRepo->getTotalConsumption($id['column'], $id['value'], $start, $end);
        return ['success' => true, 'data' => $this->recalculateCost($data)];
    }

    public function getHourlyBreakdown($roomId, $userId, $role, $dateStr = null)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        if ($dateStr) {
            $start = date('Y-m-d 00:00:00', strtotime($dateStr));
            $end = date('Y-m-d 00:00:00', strtotime($start . ' +1 day'));
        } else {
            $start = date('Y-m-d 00:00:00');
            $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
        }
        return ['success' => true, 'data' => $this->dashboardRepo->getHourlyBreakdown($id['column'], $id['value'], $start, $end)];
    }

    public function getWeeklyBreakdown($roomId, $userId, $role, $dateStr = null)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);

        if ($dateStr) {
            $targetDate = strtotime($dateStr);
            $dayOfWeek = date('w', $targetDate);
            $offset = ($dayOfWeek == 0 ? 6 : $dayOfWeek - 1);
            $start = date('Y-m-d 00:00:00', strtotime("-$offset days", $targetDate));
            $end = date('Y-m-d 00:00:00', strtotime($start . ' +7 days'));
        } else {
            $stmt = $this->conn->prepare("SELECT cycle_start FROM billing_cycles WHERE room_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$id['value']]);
            $activeCycleStart = $stmt->fetchColumn();

            $dayOfWeek = date('w');
            $offset = ($dayOfWeek == 0 ? 6 : $dayOfWeek - 1);
            $calendarStart = date('Y-m-d 00:00:00', strtotime("-$offset days"));

            $start = ($activeCycleStart && $activeCycleStart > $calendarStart) ? $activeCycleStart : $calendarStart;
            $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
        }

        $dbData = $this->dashboardRepo->getDailyBreakdown($id['column'], $id['value'], $start, $end);

        // Pad to exactly 7 days (Monday to Sunday)
        $targetDate = $dateStr ? strtotime($dateStr) : time();
        $dayOfWeek = date('w', $targetDate);
        $offset = ($dayOfWeek == 0 ? 6 : $dayOfWeek - 1);
        $mondayStr = date('Y-m-d', strtotime("-$offset days", $targetDate));

        $map = [];
        foreach ($dbData as $row) {
            $map[$row['day']] = $row;
        }

        $result = [];
        for ($i = 0; $i < 7; $i++) {
            $dayStr = date('Y-m-d', strtotime("$mondayStr +$i days"));
            if (isset($map[$dayStr])) {
                $result[] = $map[$dayStr];
            } else {
                $result[] = [
                    'day' => $dayStr,
                    'totalEnergy' => 0,
                    'avgPower' => 0,
                    'peakPower' => 0,
                    'totalCost' => 0,
                    'entries' => 0
                ];
            }
        }

        return ['success' => true, 'data' => $result];
    }

    public function getDailyBreakdownFiltered($roomId, $userId, $role, $year, $month)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end = date('Y-m-d 00:00:00', strtotime("+1 month", strtotime($start)));
        return ['success' => true, 'data' => $this->dashboardRepo->getDailyBreakdown($id['column'], $id['value'], $start, $end)];
    }

    public function getConsumptionComparison($roomId, $userId, $role, $period = 'weekly')
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $col = $id['column'];
        $val = $id['value'];

        $curr = ['totalEnergy' => 0, 'totalCost' => 0];
        $prev = ['totalEnergy' => 0, 'totalCost' => 0];

        if ($period === 'daily') {
            $currStart = date('Y-m-d 00:00:00');
            $currEnd = date('Y-m-d H:i:s');
            $prevStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $prevEnd = date('Y-m-d 23:59:59', strtotime('-1 day'));
        } elseif ($period === 'weekly') {
            $stmt = $this->conn->prepare("SELECT cycle_start FROM billing_cycles WHERE room_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$id['value']]);
            $activeCycleStart = $stmt->fetchColumn();

            $dayOfWeek = date('w');
            $offset = ($dayOfWeek == 0 ? 6 : $dayOfWeek - 1);
            $calendarStart = date('Y-m-d 00:00:00', strtotime("-$offset days"));

            $currStart = ($activeCycleStart && $activeCycleStart > $calendarStart) ? $activeCycleStart : $calendarStart;
            $currEnd = date('Y-m-d H:i:s');

            // Full previous week (Previous Monday 00:00:00 to Previous Sunday 23:59:59)
            $prevStart = date('Y-m-d 00:00:00', strtotime("-$offset days -7 days"));
            $prevEnd = date('Y-m-d 23:59:59', strtotime("-$offset days -1 day"));

            $currData = $this->dashboardRepo->getTotalConsumption($col, $val, $currStart, $currEnd);
            $prevData = $this->dashboardRepo->getTotalConsumption($col, $val, $prevStart, $prevEnd);
        } else {
            $year = date('Y');
            $month = date('n');
            $prevMonth = $month == 1 ? 12 : $month - 1;
            $prevYear = $month == 1 ? $year - 1 : $year;

            $currResult = $this->getMonthlyConsumptionFiltered($roomId, $userId, $role, $year, $month);
            $currData = $currResult['data'];

            $prevResult = $this->getMonthlyConsumptionFiltered($roomId, $userId, $role, $prevYear, $prevMonth);
            $prevData = $prevResult['data'];
        }

        // Calculate anomalies and budget check
        $isAbnormal = false;
        if ($prevData['totalEnergy'] > 0) {
            $pctChange = (($currData['totalEnergy'] - $prevData['totalEnergy']) / $prevData['totalEnergy']) * 100;
            if (abs($pctChange) >= 30)
                $isAbnormal = true;
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

    public function getTransactionHistory($roomId, $userId, $role, $limit, $offset, $filter = 'minute', $startDate = null, $endDate = null)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);

        $grouped = [];

        if ($filter === 'minute') {
            $rawLogs = $this->dashboardRepo->getTransactionHistory($id['column'], $id['value'], $limit, $offset, $startDate, $endDate);
            foreach ($rawLogs as $log) {
                $dateTitle = date('F j, Y', strtotime($log['timestamp']));
                $log['cost'] = abs((float) $log['cost']); // Fix negative bug
                $log['time_label'] = date('g:i A', strtotime($log['timestamp']));
                if (!isset($grouped[$dateTitle])) {
                    $grouped[$dateTitle] = [];
                }
                $grouped[$dateTitle][] = $log;
            }
        } else {
            $rawLogs = $this->dashboardRepo->getGroupedHistory($id['column'], $id['value'], $filter, $limit, $offset, $startDate, $endDate);
            foreach ($rawLogs as $log) {
                $log['cost'] = abs((float) $log['totalCost']);
                $log['energy'] = (float) $log['totalEnergy'];
                $log['power'] = (float) $log['avgPower'];

                if ($filter === 'daily') {
                    $groupDateStr = $log['group_date'] ?? $log['timestamp'] ?? date('Y-m-d');
                    $dateTitle = date('F Y', strtotime($groupDateStr));
                    $log['time_label'] = date('F j', strtotime($groupDateStr));
                } else if ($filter === 'weekly') {
                    $groupDateStr = $log['group_date'] ?? (date('Y') . '-W' . date('W'));
                    $dateTitle = substr((string) $groupDateStr, 0, 4); // Year
                    $log['time_label'] = 'Week ' . substr((string) $groupDateStr, -2);
                } else { // monthly
                    $groupDateStr = $log['group_date'] ?? date('Y-m');
                    $dateTitle = substr((string) $groupDateStr, 0, 4); // Year
                    $log['time_label'] = date('F', strtotime($groupDateStr . '-01'));
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

    public function getAvailableBillingCycles($roomId, $userId, $role)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);

        $stmt = $this->conn->prepare("SELECT * FROM billing_cycles WHERE room_id = ? ORDER BY cycle_start DESC LIMIT 24");
        $stmt->execute([$id['value']]);
        $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['success' => true, 'data' => $cycles];
    }

    /**
     * Get monthly aggregated consumption for an entire year.
     * Returns 12 data points (Jan–Dec) with totalEnergy, avgPower, peakPower.
     */
    public function getYearlyBreakdown($roomId, $userId, $role, $year)
    {
        $id = $this->resolveIdentifier($roomId, $userId, $role);
        $col = $id['column'];
        $val = $id['value'];
        $rate = $this->getCurrentRate();

        $start = "$year-01-01 00:00:00";
        $end = ($year + 1) . "-01-01 00:00:00";

        $sql = "SELECT 
                    MONTH(timestamp) as month,
                    SUM(energy) as totalEnergy,
                    AVG(power) as avgPower,
                    MAX(power) as peakPower,
                    COUNT(*) as entries
                FROM consumption_logs 
                WHERE $col = ? AND timestamp >= ? AND timestamp < ?
                GROUP BY MONTH(timestamp)
                ORDER BY month ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$val, $start, $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build a full 12-month array
        $monthMap = [];
        foreach ($rows as $row) {
            $monthMap[(int) $row['month']] = $row;
        }

        $result = [];
        for ($m = 1; $m <= 12; $m++) {
            $energy = isset($monthMap[$m]) ? floatval($monthMap[$m]['totalEnergy']) : 0;
            $result[] = [
                'month' => $m,
                'label' => $m,
                'totalEnergy' => $energy,
                'energy' => $energy,
                'totalCost' => round($energy * $rate, 2),
                'avgPower' => isset($monthMap[$m]) ? floatval($monthMap[$m]['avgPower']) : 0,
                'peakPower' => isset($monthMap[$m]) ? floatval($monthMap[$m]['peakPower']) : 0,
                'entries' => isset($monthMap[$m]) ? intval($monthMap[$m]['entries']) : 0
            ];
        }

        return ['success' => true, 'data' => $result];
    }
}
