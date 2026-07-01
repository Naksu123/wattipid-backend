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

    // GHOST FIX: Validation constants for IoT data
    const MAX_REALISTIC_POWER_WATTS = 5000;   // Capped to prevent floating analog noise spikes
    const MAX_REALISTIC_VOLTAGE = 260;        
    const MIN_REALISTIC_VOLTAGE = 100;        
    const MAX_REALISTIC_CURRENT = 25;         
    const MAX_ENERGY_DELTA = 0.05;            // Max kWh delta per reading (0.05 kWh = 36000W over 5s). Blocks fake jumps!
    const MIN_LOG_INTERVAL_SECONDS = 0;       // Minimum seconds between logs (anti-spam) - Disabled for instant realtime

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        $this->consumptionRepo = new ConsumptionRepository($dbConnection);
        $this->budgetRepo = new BudgetRepository($dbConnection);
        $this->notifRepo = new NotificationRepository($dbConnection);
        $this->roomRepo = new RoomRepository($dbConnection);
    }

    public function logConsumption($roomId, $voltage, $current, $power, $cumulativeEnergy) {
        // ========================================
        // GHOST FIX: IoT Data Validation Pipeline
        // ========================================

        // 1. Validate room exists
        $stmt = $this->conn->prepare("SELECT room_id, tenant_name, device_secret, last_seen FROM rooms WHERE room_id = ? LIMIT 1");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            return ['success' => false, 'error' => 'Room not found'];
        }

        // 2. Validate device is registered (has a device_secret)
        // if (empty($room['device_secret'])) {
        //     return ['success' => false, 'error' => 'No IoT device registered for this room'];
        // }

        // 3. Validate sensor readings are physically realistic
        $validationErrors = [];
        
        if ($power < 0 || $power > self::MAX_REALISTIC_POWER_WATTS) {
            $validationErrors[] = "Power out of range: {$power}W (max: " . self::MAX_REALISTIC_POWER_WATTS . "W)";
        }
        // TESTING FIX: Allow 0V readings when no load is connected to the CT sensor
        // Uncomment this validation for production when the sensor is properly wired
        // if ($voltage > 0 && ($voltage < self::MIN_REALISTIC_VOLTAGE || $voltage > self::MAX_REALISTIC_VOLTAGE)) {
        //     $validationErrors[] = "Voltage out of range: {$voltage}V";
        // }
        if ($current < 0 || $current > self::MAX_REALISTIC_CURRENT) {
            $validationErrors[] = "Current out of range: {$current}A";
        }
        if ($cumulativeEnergy < 0) {
            $validationErrors[] = "Negative cumulative energy: {$cumulativeEnergy}";
        }

        if (!empty($validationErrors)) {
            error_log("IoT Validation Failed for {$roomId}: " . implode(', ', $validationErrors));
            return ['success' => false, 'error' => 'Invalid sensor data', 'details' => $validationErrors];
        }

        error_log("IoT DEBUG: Validation PASSED for {$roomId} - V:{$voltage} A:{$current} W:{$power}");

        // 4. Anti-duplicate: Check minimum interval between logs
        $lastLog = $this->consumptionRepo->getLastLog($roomId);
        if ($lastLog) {
            $lastTime = strtotime($lastLog['timestamp']);
            $timeDiff = abs(time() - $lastTime); // abs() fixes PHP/MySQL timezone mismatch
            if ($timeDiff < self::MIN_LOG_INTERVAL_SECONDS) {
                return ['success' => true, 'skipped' => true, 'reason' => 'Too frequent'];
            }
        }

        $shouldRunEngine = !$lastLog || (time() - strtotime($lastLog['timestamp']) > 30);

        // --- Calculate Energy Delta & Cost ---
        $lastCumulative = $lastLog ? (float) $lastLog['energy_cumulative'] : 0;
        
        // GHOST FIX: Handle C++ floating point noise. Only assume ESP32 restarted if it drops significantly (> 0.001 kWh)
        if ($lastCumulative - $cumulativeEnergy > 0.001) {
            $energyDelta = $cumulativeEnergy;
        } else {
            $energyDelta = max(0, $cumulativeEnergy - $lastCumulative);
        }
        
        // GHOST FIX: Safety cap for energy delta (prevents fake spikes)
        if ($energyDelta > self::MAX_ENERGY_DELTA) {
            error_log("IoT Safety Cap: Energy delta {$energyDelta} exceeds max " . self::MAX_ENERGY_DELTA . " for room {$roomId}");
            $energyDelta = 0;
            // Prevent the database from syncing to the ESP32's corrupted running total
            $cumulativeEnergy = $lastCumulative;
        }

        $rate = $this->getRatePerKwh();
        $cost = $energyDelta * $rate;

        $tenantName = $room['tenant_name'];

        // --- Update Room Activity ---
        $stmt = $this->conn->prepare("UPDATE rooms SET last_seen = NOW() WHERE room_id = ?");
        $stmt->execute([$roomId]);

        // --- Fetch Active Billing Cycle (Lazy Evaluated) ---
        require_once __DIR__ . '/BillingCycleService.php';
        $billingService = new BillingCycleService($this->conn);
        $billingCycleId = $billingService->getActiveCycleId($roomId);

        // --- Insert the Log ---
        try {
            $this->consumptionRepo->insertLog($roomId, $tenantName, $voltage, $current, $power, $energyDelta, $cumulativeEnergy, $cost, $billingCycleId);
            error_log("IoT DEBUG: INSERT SUCCESS for {$roomId} (Cycle ID: $billingCycleId)");
        } catch (Exception $e) {
            error_log("IoT DEBUG: INSERT FAILED for {$roomId}: " . $e->getMessage());
        }

        // --- Fire Legacy Notification Engine (User Configured Alerts) ---
        // GHOST FIX: Only fire if power > 0 and we have a valid tenant user
        if ($power > 0) {
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
                error_log("NotificationEngine error: " . $ne->getMessage());
            }
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
        // --- TIPSENGINE INTELLIGENCE PIPELINE ---
        // ==========================================
        $totals = $this->consumptionRepo->getConsumptionTotals($roomId);
        $totalDaily = (float) ($totals['total_daily'] ?? 0);
        $totalWeekly = (float) ($totals['total_weekly'] ?? 0);
        $totalMonthly = (float) ($totals['total_monthly'] ?? 0);

        // GHOST FIX: Only run tips engine if we have meaningful consumption data
        $stmtUser = $this->conn->prepare("SELECT id FROM users WHERE room_id = ? AND role = 'tenant' LIMIT 1");
        $stmtUser->execute([$roomId]);
        $tenantUserId = $stmtUser->fetchColumn();
        
        if ($tenantUserId && $power > 0) {
            $this->runTipsEngine($roomId, $tenantUserId, $power, $totalDaily, $totalWeekly, $totalMonthly);
        }

        return [
            'success' => true,
            'delta' => $energyDelta,
            'monthly_cost' => $totalMonthly
        ];
    }

    private function runTipsEngine($roomId, $userId, $power, $totalDaily, $totalWeekly, $totalMonthly) {
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

        // GHOST FIX: Adjusted spike logic for demonstration purposes
        // Triggers instantly when high wattage is detected, bypassing strict trend requirements
        if ($power >= 1200) {
            $alerts[] = ['type' => 'danger', 'title' => '🚨 Critical Power Usage!', 'message' => "Extremely high power detected: " . number_format($power, 0) . "W usage. Unplug high-wattage appliances."];
        } else if ($power >= 700) {
            $alerts[] = ['type' => 'warning', 'title' => '⚠️ High Consumption Alert', 'message' => "Power spike detected: " . number_format($power, 0) . "W usage. Consider turning off unused devices."];
        }

        // GHOST FIX: Only trigger budget alerts when there is REAL spending (totalDaily > 0)
        if ($dailyLimit > 0 && $totalDaily > 0) {
            $pct = ($totalDaily / $dailyLimit) * 100;
            if ($pct >= 100) $alerts[] = ['type' => 'danger', 'title' => '🚨 TipsEngine: Daily Limit Exceeded', 'message' => "Daily allowance of ₱" . number_format($dailyLimit, 2) . " consumed."];
            else if ($pct >= 85) $alerts[] = ['type' => 'warning', 'title' => '⚠️ TipsEngine: Daily Limit Warning', 'message' => "You've used " . number_format($pct, 0) . "% of your daily allowance."];
        }

        if ($weeklyLimit > 0 && $totalWeekly > 0) {
            $pct = ($totalWeekly / $weeklyLimit) * 100;
            if ($pct >= 100) $alerts[] = ['type' => 'danger', 'title' => '🚨 TipsEngine: Weekly Limit Exceeded', 'message' => "Weekly budget of ₱" . number_format($weeklyLimit, 2) . " consumed."];
            else if ($pct >= 85) $alerts[] = ['type' => 'warning', 'title' => '⚠️ TipsEngine: Weekly Limit Warning', 'message' => "You've used " . number_format($pct, 0) . "% of your weekly budget."];
        }

        if ($monthlyLimit > 0 && $totalMonthly > 0) {
            $pct = ($totalMonthly / $monthlyLimit) * 100;
            if ($pct >= 100) $alerts[] = ['type' => 'danger', 'title' => '🚨 TipsEngine: Monthly Budget Exceeded', 'message' => "Monthly budget of ₱" . number_format($monthlyLimit, 2) . " consumed."];
            else if ($pct >= 85) $alerts[] = ['type' => 'warning', 'title' => '⚠️ TipsEngine: Monthly Budget Warning', 'message' => "You've used " . number_format($pct, 0) . "% of your monthly budget."];
        }

        // 3. Dispatch Alerts — with cooldown check (reduced to 1 min for demo testing)
        foreach ($alerts as $alert) {
            if (!$this->notifRepo->hasRecentAlert($roomId, $alert['title'], 1)) {
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
