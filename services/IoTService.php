<?php
require_once __DIR__ . '/../repositories/ConsumptionRepository.php';
require_once __DIR__ . '/../repositories/BudgetRepository.php';
require_once __DIR__ . '/../repositories/NotificationRepository.php';
require_once __DIR__ . '/../repositories/RoomRepository.php';

// Include the legacy notification engine for now, assuming it exists
require_once __DIR__ . '/../utils/notification_engine.php';

class IoTService {
    private $conn;
    private $consumptionRepo;
    private $budgetRepo;
    private $notifRepo;
    private $roomRepo;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        $this->consumptionRepo = new ConsumptionRepository($dbConnection);
        $this->budgetRepo = new BudgetRepository($dbConnection);
        $this->notifRepo = new NotificationRepository($dbConnection);
        $this->roomRepo = new RoomRepository($dbConnection);
    }

    public function logConsumption($roomId, $voltage, $current, $power, $cumulativeEnergy) {
        // --- 1. Cooldown Check ---
        $lastLog = $this->consumptionRepo->getLastLog($roomId);
        $shouldRunEngine = !$lastLog || (time() - strtotime($lastLog['timestamp']) > 30);

        // --- 2. Calculate Energy Delta & Cost ---
        $lastCumulative = $lastLog ? (float) $lastLog['energy_cumulative'] : 0;
        $energyDelta = ($cumulativeEnergy < $lastCumulative) ? $cumulativeEnergy : ($cumulativeEnergy - $lastCumulative);
        if ($energyDelta > 5) $energyDelta = 0; // Safety cap

        $rate = $this->getRatePerKwh();
        $cost = $energyDelta * $rate;

        // --- 3. Get Tenant Info & Update Room Activity ---
        $stmt = $this->conn->prepare("SELECT tenant_name FROM rooms WHERE room_id = ? LIMIT 1");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();
        $tenantName = $room ? $room['tenant_name'] : null;

        $stmt = $this->conn->prepare("UPDATE rooms SET last_seen = NOW() WHERE room_id = ?");
        $stmt->execute([$roomId]);

        // --- 4. Insert the Log ---
        $this->consumptionRepo->insertLog($roomId, $tenantName, $voltage, $current, $power, $energyDelta, $cumulativeEnergy, $cost);

        // --- 5. Fire Legacy Notification Engine (User Configured Alerts) ---
        try {
            $notifEngine = new NotificationEngine($this->conn);
            $stmtUser = $this->conn->prepare("SELECT id FROM users WHERE room_id = ? AND role = 'tenant' LIMIT 1");
            $stmtUser->execute([$roomId]);
            $tenantUserId = $stmtUser->fetchColumn();
            if ($tenantUserId) {
                $notifEngine->checkAndNotify($roomId, $tenantUserId, $power, $tenantName);
            }
        } catch (Exception $ne) {
            // Do not block execution
        }

        // If cooldown hasn't passed, skip the heavy TipsEngine
        if (!$shouldRunEngine) {
            return [
                'success' => true,
                'engine_skipped' => true,
                'delta' => $energyDelta
            ];
        }

        // ==========================================
        // --- 6. TIPSENGINE INTELLIGENCE PIPELINE ---
        // ==========================================
        $totals = $this->consumptionRepo->getConsumptionTotals($roomId);
        $totalDaily = (float) ($totals['total_daily'] ?? 0);
        $totalWeekly = (float) ($totals['total_weekly'] ?? 0);
        $totalMonthly = (float) ($totals['total_monthly'] ?? 0);

        $this->runTipsEngine($roomId, $tenantUserId, $power, $totalDaily, $totalWeekly, $totalMonthly);

        return [
            'success' => true,
            'delta' => $energyDelta,
            'monthly_cost' => $totalMonthly
        ];
    }

    private function runTipsEngine($roomId, $userId, $power, $totalDaily, $totalWeekly, $totalMonthly) {
        // ... (Skipping analytics and budget rules logic as it remains the same)
        // [I will use multi_replace for the actual injection]
        // 1. Analytics
        $avgPower = $this->consumptionRepo->getRollingAveragePower($roomId, 10);
        if ($avgPower == 0) $avgPower = $power;
        
        $trend = $this->consumptionRepo->getTrendReadings($roomId, 3);
        $isIncreasing = count($trend) >= 3 && ($trend[0] > $trend[1]) && ($trend[1] > $trend[2]);

        // 2. Budgets
        $budget = $this->budgetRepo->getBudgetByRoom($roomId);
        $dailyLimit = $budget ? (float) $budget['daily_allowance'] : 0;
        $weeklyLimit = $budget ? (float) $budget['weekly_allowance'] : 0;
        $monthlyLimit = $budget ? (float) $budget['monthly_budget'] : 0;

        $alerts = [];

        // RULE: Confirmed Spike
        if ($power > 100 && $power >= ($avgPower * 1.8) && $isIncreasing) {
            $alerts[] = ['type' => 'alert', 'title' => '⚡ TipsEngine: Electricity Spike', 'message' => "Confirmed power spike detected: " . number_format($power, 0) . "W usage."];
        }

        // RULE: Daily Budget
        if ($dailyLimit > 0) {
            $pct = ($totalDaily / $dailyLimit) * 100;
            if ($pct >= 100) $alerts[] = ['type' => 'danger', 'title' => '🚨 TipsEngine: Daily Limit Exceeded', 'message' => "Daily allowance of ₱" . number_format($dailyLimit, 2) . " consumed."];
            else if ($pct >= 85) $alerts[] = ['type' => 'warning', 'title' => '⚠️ TipsEngine: Daily Limit Warning', 'message' => "You've used " . number_format($pct, 0) . "% of your daily allowance."];
        }

        // RULE: Weekly Budget
        if ($weeklyLimit > 0) {
            $pct = ($totalWeekly / $weeklyLimit) * 100;
            if ($pct >= 100) $alerts[] = ['type' => 'danger', 'title' => '🚨 TipsEngine: Weekly Limit Exceeded', 'message' => "Weekly budget of ₱" . number_format($weeklyLimit, 2) . " consumed."];
            else if ($pct >= 85) $alerts[] = ['type' => 'warning', 'title' => '⚠️ TipsEngine: Weekly Limit Warning', 'message' => "You've used " . number_format($pct, 0) . "% of your weekly budget."];
        }

        // RULE: Monthly Budget
        if ($monthlyLimit > 0) {
            $pct = ($totalMonthly / $monthlyLimit) * 100;
            if ($pct >= 100) $alerts[] = ['type' => 'danger', 'title' => '🚨 TipsEngine: Monthly Budget Exceeded', 'message' => "Monthly budget of ₱" . number_format($monthlyLimit, 2) . " consumed."];
            else if ($pct >= 85) $alerts[] = ['type' => 'warning', 'title' => '⚠️ TipsEngine: Monthly Budget Warning', 'message' => "You've used " . number_format($pct, 0) . "% of your monthly budget."];
        }

        // 3. Dispatch Alerts
        foreach ($alerts as $alert) {
            if (!$this->notifRepo->hasRecentAlert($roomId, $alert['title'], 30)) {
                $this->notifRepo->insertNotification($roomId, $userId, $alert['type'], $alert['title'], $alert['message']);
            }
        }
    }

    public function toggleRelay($roomId, $state) {
        try {
            $stmt = $this->conn->prepare("UPDATE rooms SET relay_state = ? WHERE room_id = ?");
            $success = $stmt->execute([(int)$state, $roomId]);
            
            return [
                'success' => $success,
                'message' => $success ? "Relay " . ($state ? 'ON' : 'OFF') . " command sent to room $roomId." : "Failed to update relay state."
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => "Service Error: " . $e->getMessage()];
        }
    }

    private function getRatePerKwh() {
        $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'rate_per_kwh' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? (float) $row['setting_value'] : 12.5;
    }
}
