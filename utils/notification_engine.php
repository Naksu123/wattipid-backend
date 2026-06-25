<?php
/**
 * Wattipid Notification Engine
 * 
 * Intelligent alert detection and push notification delivery system.
 * Detects 7 types of electricity anomalies and sends Expo Push notifications.
 */

require_once __DIR__ . '/forecast_engine.php';

class NotificationEngine {
    private $conn;
    private $forecast;

    // Cooldown: minimum minutes between same alert type
    const COOLDOWN_MINUTES = 30;
    // Maximum notifications per user per day
    const DAILY_CAP = 10;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->forecast = new ForecastEngine($conn);
    }

    /**
     * Run all alert checks for a specific room/user.
     * Called after every consumption log or periodically via cron.
     */
    public function checkAndNotify($roomId, $userId, $currentPower = 0, $tenantName = null) {
        $settings = $this->getAlertSettings($userId, $roomId);
        if (!$settings['notifications_enabled']) return [];

        // ====================================================
        // GHOST FIX: Verify device is ACTUALLY connected
        // before generating ANY alerts.
        // ====================================================
        if (!$this->isDeviceActive($roomId)) {
            return []; // No real IoT data — suppress all alerts
        }

        // GHOST FIX: Validate currentPower is realistic
        if ($currentPower < 0 || $currentPower > 5000) {
            error_log("NotificationEngine: Ignoring unrealistic power reading: {$currentPower}W for room {$roomId}");
            return [];
        }

        $alerts = [];

        // Gather data once
        $todayData = $this->getTodayConsumption($roomId, $tenantName);
        $monthData = $this->getMonthConsumption($roomId, $tenantName);
        $budget = $this->getBudget($roomId);
        $avg7Day = $this->get7DayAverage($roomId, $tenantName);

        // ---- CHECK 1: Daily Budget Exceeded ----
        if ($budget && $budget['daily_allowance'] > 0) {
            $dailyPct = ($todayData['totalCost'] / $budget['daily_allowance']) * 100;
            if ($dailyPct >= 100) {
                $alerts[] = [
                    'type' => 'budget_daily_exceeded',
                    'category' => 'budget',
                    'severity' => 'critical',
                    'title' => '⚠️ Daily Budget Exceeded!',
                    'message' => "Your electricity usage exceeded your ₱" . number_format($budget['daily_allowance'], 2) . " daily budget. Current spending: ₱" . number_format($todayData['totalCost'], 2) . ".",
                    'data' => ['dailyPct' => round($dailyPct, 1), 'spent' => $todayData['totalCost'], 'limit' => $budget['daily_allowance']],
                ];
            } elseif ($dailyPct >= 80) {
                $alerts[] = [
                    'type' => 'budget_daily_warning',
                    'category' => 'budget',
                    'severity' => 'warning',
                    'title' => '💰 Daily Budget Warning',
                    'message' => "You've used " . round($dailyPct) . "% of your daily budget (₱" . number_format($todayData['totalCost'], 2) . " / ₱" . number_format($budget['daily_allowance'], 2) . ").",
                    'data' => ['dailyPct' => round($dailyPct, 1)],
                ];
            }
        }

        // ---- CHECK 2: Monthly Budget Exceeded ----
        if ($budget && $budget['monthly_budget'] > 0) {
            $monthlyPct = ($monthData['totalCost'] / $budget['monthly_budget']) * 100;
            if ($monthlyPct >= 100) {
                $alerts[] = [
                    'type' => 'budget_monthly_exceeded',
                    'category' => 'budget',
                    'severity' => 'critical',
                    'title' => '🚨 Monthly Budget Exceeded!',
                    'message' => "Your monthly spending of ₱" . number_format($monthData['totalCost'], 2) . " has exceeded your budget of ₱" . number_format($budget['monthly_budget'], 2) . ".",
                    'data' => ['monthlyPct' => round($monthlyPct, 1)],
                ];
            }
        }

        // ---- CHECK 3: Abnormal Consumption ----
        // GHOST FIX: Require minimum meaningful average (₱1 daily average) to prevent
        // false abnormal alerts from tiny/zero baseline values
        $abnormalThreshold = $settings['abnormal_threshold_pct'];
        if ($avg7Day['avgDailyCost'] > 1.0 && $todayData['totalCost'] > 1.0) {
            $abovePct = (($todayData['totalCost'] - $avg7Day['avgDailyCost']) / $avg7Day['avgDailyCost']) * 100;
            if ($abovePct >= $abnormalThreshold) {
                $alerts[] = [
                    'type' => 'abnormal_consumption',
                    'category' => 'consumption',
                    'severity' => 'warning',
                    'title' => '📊 Abnormal Electricity Usage',
                    'message' => "Today's usage is " . round($abovePct) . "% higher than your 7-day average. Possible appliance left running.",
                    'data' => ['abovePct' => round($abovePct, 1), 'todayCost' => $todayData['totalCost'], 'avgCost' => $avg7Day['avgDailyCost']],
                ];
            }
        }

        // ---- CHECK 4: Power Spike ----
        $spikeThreshold = $settings['spike_watts'];
        if ($currentPower > $spikeThreshold) {
            $alerts[] = [
                'type' => 'power_spike',
                'category' => 'consumption',
                'severity' => 'warning',
                'title' => '⚡ High Power Spike Detected!',
                'message' => "Current power draw is " . round($currentPower) . "W, exceeding your " . $spikeThreshold . "W threshold. Check for multiple heavy appliances running simultaneously.",
                'data' => ['currentPower' => round($currentPower), 'threshold' => $spikeThreshold],
            ];
        }

        // ---- CHECK 5: Continuous High Usage ----
        $highUsageMinutes = $settings['high_usage_minutes'];
        $continuousHigh = $this->checkContinuousHighUsage($roomId, $spikeThreshold * 0.7, $highUsageMinutes);
        if ($continuousHigh) {
            $alerts[] = [
                'type' => 'continuous_high_usage',
                'category' => 'consumption',
                'severity' => 'warning',
                'title' => '🔌 Prolonged High Consumption',
                'message' => "An appliance may still be running — high consumption detected for over " . ($highUsageMinutes / 60) . " hours.",
                'data' => ['duration_minutes' => $highUsageMinutes],
            ];
        }

        // ---- CHECK 6: Forecast Exceeds Budget ----
        if ($budget && $budget['monthly_budget'] > 0) {
            try {
                $forecastData = $this->forecast->getMonthlyForecast($roomId, $tenantName);
                $forecastPct = $settings['forecast_warning_pct'];
                $projectedPct = $budget['monthly_budget'] > 0
                    ? ($forecastData['projected_monthly_cost'] / $budget['monthly_budget']) * 100
                    : 0;

                if ($projectedPct >= $forecastPct && $forecastData['days_remaining'] > 3) {
                    $alerts[] = [
                        'type' => 'forecast_exceeded',
                        'category' => 'forecast',
                        'severity' => $projectedPct >= 120 ? 'critical' : 'warning',
                        'title' => '📈 Monthly Bill Forecast Alert',
                        'message' => "At current rate, your projected monthly bill is ₱" . number_format($forecastData['projected_monthly_cost'], 2) . " — " . round($projectedPct) . "% of your ₱" . number_format($budget['monthly_budget'], 2) . " budget.",
                        'data' => $forecastData,
                    ];
                }
            } catch (Exception $e) {
                // Forecast engine failure should not block other alerts
                error_log("Forecast check failed: " . $e->getMessage());
            }
        }

        // ---- CHECK 7: Meter Anomaly ----
        $anomaly = $this->checkMeterAnomaly($roomId);
        if ($anomaly) {
            $alerts[] = [
                'type' => 'meter_anomaly',
                'category' => 'system',
                'severity' => 'critical',
                'title' => '🔧 Meter Reading Anomaly',
                'message' => "Possible submeter reading inconsistency detected: " . $anomaly['description'],
                'data' => $anomaly,
            ];
        }

        // ---- PROCESS ALERTS: Apply cooldowns, save, and send push ----
        $sentAlerts = [];
        foreach ($alerts as $alert) {
            if ($this->canSendAlert($userId, $alert['type'])) {
                try {
                    $this->conn->beginTransaction();
                    
                    $notifId = $this->saveNotification($userId, $roomId, $alert);
                    $this->updateCooldown($userId, $alert['type']);
                    
                    $this->conn->commit();

                    $alert['id'] = $notifId;
                    if ($settings['push_enabled']) {
                        require_once __DIR__ . '/QueueService.php';
                        $queue = new QueueService($this->conn);
                        $queue->push('push_notification', [
                            'userId' => $userId,
                            'alert' => $alert
                        ]);
                    }
                    
                    $sentAlerts[] = $alert;
                } catch (Exception $e) {
                    if ($this->conn->inTransaction()) $this->conn->rollBack();
                    error_log("Alert Transaction Failed: " . $e->getMessage());
                }
            }
        }

        return $sentAlerts;
    }

    /**
     * Send an Expo Push Notification to all devices of a user.
     */
    public function sendPushNotification($userId, $alert) {
        $tokens = $this->getActiveTokens($userId);
        if (empty($tokens)) return true; // Successfully skipped because user has no registered devices

        $messages = [];
        foreach ($tokens as $token) {
            $messages[] = [
                'to' => $token['expo_push_token'],
                'title' => $alert['title'],
                'body' => $alert['message'],
                'sound' => 'default',
                'priority' => $alert['severity'] === 'critical' ? 'high' : 'default',
                'channelId' => 'default',
                'data' => [
                    'type' => $alert['type'],
                    'category' => $alert['category'],
                    'severity' => $alert['severity'],
                    'notificationId' => $alert['id'] ?? null,
                    'screen' => 'notifications',
                ],
            ];
        }

        // Send to Expo Push API
        $ch = curl_init('https://exp.host/--/api/v2/push/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Encoding: gzip, deflate',
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($messages),
            CURLOPT_TIMEOUT => 10,
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 200) {
            $decoded = json_decode($result, true);
            // Log any ticket errors for debugging
            if (isset($decoded['data'])) {
                foreach ($decoded['data'] as $i => $ticket) {
                    if (isset($ticket['status']) && $ticket['status'] === 'error') {
                        $errorMsg = $ticket['message'] ?? '';
                        if (strpos($errorMsg, 'DeviceNotRegistered') !== false || strpos($errorMsg, 'PushTokenInvalid') !== false) {
                            $this->invalidateToken($tokens[$i]['expo_push_token']);
                        }
                    }
                }
            }
            return true;
        }

        $curlError = curl_error($ch);
        error_log("Expo Push failed (HTTP $httpCode): $result. cURL Error: $curlError");
        throw new Exception("Expo Push failed (HTTP $httpCode): $result. cURL Error: $curlError");
    }

    // ---- DATA HELPERS ----

    private function getTodayConsumption($roomId, $tenantName = null) {
        $where = $tenantName ? "tenant_name = ?" : "room_id = ?";
        $param = $tenantName ?: $roomId;
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(energy), 0) as totalEnergy, COALESCE(SUM(cost), 0) as totalCost
            FROM consumption_logs 
            WHERE $where 
            AND timestamp >= CURRENT_DATE 
            AND timestamp < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)
        ");
        $stmt->execute([$param]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getMonthConsumption($roomId, $tenantName = null) {
        $where = $tenantName ? "tenant_name = ?" : "room_id = ?";
        $param = $tenantName ?: $roomId;
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(energy), 0) as totalEnergy, COALESCE(SUM(cost), 0) as totalCost
            FROM consumption_logs 
            WHERE $where 
            AND timestamp >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
            AND timestamp < DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00'), INTERVAL 1 MONTH)
        ");
        $stmt->execute([$param]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function get7DayAverage($roomId, $tenantName = null) {
        $where = $tenantName ? "tenant_name = ?" : "room_id = ?";
        $param = $tenantName ?: $roomId;
        $stmt = $this->conn->prepare("
            SELECT AVG(daily_cost) as avgDailyCost, AVG(daily_energy) as avgDailyEnergy
            FROM (
                SELECT DATE(timestamp) as dt, SUM(cost) as daily_cost, SUM(energy) as daily_energy
                FROM consumption_logs
                WHERE $where 
                AND timestamp >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY) 
                AND timestamp < CURRENT_DATE
                GROUP BY DATE(timestamp)
            ) as x
        ");
        $stmt->execute([$param]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['avgDailyCost' => 0, 'avgDailyEnergy' => 0];
    }

    private function getBudget($roomId) {
        $stmt = $this->conn->prepare("
            SELECT monthly_budget, daily_allowance
            FROM budget_settings
            WHERE room_id = ? 
            AND month = MONTH(CURRENT_DATE) 
            AND year = YEAR(CURRENT_DATE)
        ");
        $stmt->execute([$roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * GHOST FIX: Fixed SQL INTERVAL binding — MariaDB emulated prepares
     * treat '?' as string literal in INTERVAL clause. Inline the integer.
     */
    private function checkContinuousHighUsage($roomId, $wattThreshold, $minutes) {
        $minutes = (int) $minutes;
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as high_count, 
                   TIMESTAMPDIFF(MINUTE, MIN(timestamp), MAX(timestamp)) as duration
            FROM consumption_logs
            WHERE room_id = ? AND power > ? AND timestamp >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)
        ");
        $stmt->execute([$roomId, $wattThreshold]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['high_count'] >= 3 && $result['duration'] >= ($minutes * 0.8);
    }

    private function checkMeterAnomaly($roomId) {
        // Check for negative energy values or sudden large drops
        $stmt = $this->conn->prepare("
            SELECT energy, power, timestamp
            FROM consumption_logs
            WHERE room_id = ? AND (energy < 0 OR power < 0)
            AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            LIMIT 1
        ");
        $stmt->execute([$roomId]);
        $bad = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($bad) {
            return [
                'description' => 'Negative energy or power reading detected',
                'energy' => $bad['energy'],
                'power' => $bad['power'],
                'timestamp' => $bad['timestamp'],
            ];
        }
        return null;
    }

    /**
     * GHOST FIX: Check if an ESP32 device has been active (sent data) within the last 5 minutes.
     * If no device is active, we should NOT generate any alerts.
     */
    private function isDeviceActive($roomId, $maxAgeMinutes = 5) {
        $maxAgeMinutes = (int) $maxAgeMinutes;
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM rooms 
            WHERE room_id = ? 
            AND device_secret IS NOT NULL 
            AND last_seen IS NOT NULL 
            AND last_seen >= DATE_SUB(NOW(), INTERVAL {$maxAgeMinutes} MINUTE)
        ");
        $stmt->execute([$roomId]);
        return $stmt->fetchColumn() > 0;
    }

    // ---- COOLDOWN & SPAM PREVENTION ----

    private function canSendAlert($userId, $alertType) {
        // Check cooldown
        $stmt = $this->conn->prepare("
            SELECT last_sent_at, daily_count, count_date
            FROM notification_cooldowns
            WHERE user_id = ? AND alert_type = ?
        ");
        $stmt->execute([$userId, $alertType]);
        $cooldown = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cooldown) {
            // Check time cooldown
            $lastSent = new DateTime($cooldown['last_sent_at']);
            $now = new DateTime();
            $diffMinutes = ($now->getTimestamp() - $lastSent->getTimestamp()) / 60;
            if ($diffMinutes < self::COOLDOWN_MINUTES) return false;
        }

        // Check daily cap (across ALL alert types)
        $dailyStmt = $this->conn->prepare("
            SELECT COALESCE(SUM(daily_count), 0) as total
            FROM notification_cooldowns
            WHERE user_id = ? AND count_date = CURDATE()
        ");
        $dailyStmt->execute([$userId]);
        $dailyTotal = (int) $dailyStmt->fetchColumn();
        if ($dailyTotal >= self::DAILY_CAP) return false;

        return true;
    }

    private function updateCooldown($userId, $alertType) {
        $stmt = $this->conn->prepare("
            INSERT INTO notification_cooldowns (user_id, alert_type, last_sent_at, daily_count, count_date)
            VALUES (?, ?, NOW(), 1, CURDATE())
            ON DUPLICATE KEY UPDATE 
                last_sent_at = NOW(),
                daily_count = IF(count_date = CURDATE(), daily_count + 1, 1),
                count_date = CURDATE()
        ");
        $stmt->execute([$userId, $alertType]);
    }

    // ---- NOTIFICATION PERSISTENCE ----

    private function saveNotification($userId, $roomId, $alert) {
        $stmt = $this->conn->prepare("
            INSERT INTO notification_history (user_id, room_id, type, category, severity, title, message, data_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $roomId,
            $alert['type'],
            $alert['category'],
            $alert['severity'],
            $alert['title'],
            $alert['message'],
            json_encode($alert['data'] ?? []),
        ]);
        return $this->conn->lastInsertId();
    }

    private function markPushSent($notifId) {
        $stmt = $this->conn->prepare("UPDATE notification_history SET push_sent = 1 WHERE id = ?");
        $stmt->execute([$notifId]);
    }

    // ---- TOKEN MANAGEMENT ----

    public function registerToken($userId, $token, $deviceName = null, $platform = 'android') {
        $stmt = $this->conn->prepare("
            UPDATE users SET expo_push_token = ? WHERE id = ?
        ");
        $stmt->execute([$token, $userId]);
    }

    private function getActiveTokens($userId) {
        $stmt = $this->conn->prepare("
            SELECT expo_push_token FROM users
            WHERE id = ? AND expo_push_token IS NOT NULL
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function invalidateToken($token) {
        $stmt = $this->conn->prepare("UPDATE users SET expo_push_token = NULL WHERE expo_push_token = ?");
        $stmt->execute([$token]);
    }

    // ---- ALERT SETTINGS ----

    public function getAlertSettings($userId, $roomId = null) {
        $stmt = $this->conn->prepare("
            SELECT * FROM alert_settings WHERE user_id = ? AND (room_id = ? OR room_id IS NULL) LIMIT 1
        ");
        $stmt->execute([$userId, $roomId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings) {
            // Return defaults
            return [
                'daily_budget_limit' => 150.00,
                'monthly_budget_limit' => 4500.00,
                'abnormal_threshold_pct' => 50,
                'spike_watts' => 1500,
                'high_usage_minutes' => 120,
                'forecast_warning_pct' => 90,
                'notifications_enabled' => 1,
                'push_enabled' => 1,
                'sound_enabled' => 1,
                'quiet_hours_start' => '22:00:00',
                'quiet_hours_end' => '06:00:00',
                'due_date_alerts' => 1,
                'overdue_alerts' => 1,
                'penalty_alerts' => 1,
                'payment_alerts' => 1,
                'budget_50_alerts' => 1,
                'budget_75_alerts' => 1,
                'budget_90_alerts' => 1,
            ];
        }
        return $settings;
    }

    public function updateAlertSettings($userId, $roomId, $settings) {
        $stmt = $this->conn->prepare("
            INSERT INTO alert_settings (user_id, room_id, daily_budget_limit, monthly_budget_limit, 
                abnormal_threshold_pct, spike_watts, high_usage_minutes, forecast_warning_pct,
                notifications_enabled, push_enabled, sound_enabled,
                due_date_alerts, overdue_alerts, penalty_alerts, payment_alerts,
                budget_50_alerts, budget_75_alerts, budget_90_alerts)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                daily_budget_limit = VALUES(daily_budget_limit),
                monthly_budget_limit = VALUES(monthly_budget_limit),
                abnormal_threshold_pct = VALUES(abnormal_threshold_pct),
                spike_watts = VALUES(spike_watts),
                high_usage_minutes = VALUES(high_usage_minutes),
                forecast_warning_pct = VALUES(forecast_warning_pct),
                notifications_enabled = VALUES(notifications_enabled),
                push_enabled = VALUES(push_enabled),
                sound_enabled = VALUES(sound_enabled),
                due_date_alerts = VALUES(due_date_alerts),
                overdue_alerts = VALUES(overdue_alerts),
                penalty_alerts = VALUES(penalty_alerts),
                payment_alerts = VALUES(payment_alerts),
                budget_50_alerts = VALUES(budget_50_alerts),
                budget_75_alerts = VALUES(budget_75_alerts),
                budget_90_alerts = VALUES(budget_90_alerts)
        ");
        $stmt->execute([
            $userId, $roomId,
            $settings['daily_budget_limit'] ?? 150,
            $settings['monthly_budget_limit'] ?? 4500,
            $settings['abnormal_threshold_pct'] ?? 50,
            $settings['spike_watts'] ?? 1500,
            $settings['high_usage_minutes'] ?? 120,
            $settings['forecast_warning_pct'] ?? 90,
            $settings['notifications_enabled'] ?? 1,
            $settings['push_enabled'] ?? 1,
            $settings['sound_enabled'] ?? 1,
            $settings['due_date_alerts'] ?? 1,
            $settings['overdue_alerts'] ?? 1,
            $settings['penalty_alerts'] ?? 1,
            $settings['payment_alerts'] ?? 1,
            $settings['budget_50_alerts'] ?? 1,
            $settings['budget_75_alerts'] ?? 1,
            $settings['budget_90_alerts'] ?? 1,
        ]);
    }

    // ---- NOTIFICATION QUERIES ----

    public function getNotifications($userId, $limit = 50, $offset = 0, $category = null) {
        $sql = "SELECT * FROM notification_history WHERE user_id = ?";
        $params = [$userId];
        
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = (int) $limit;
        $params[] = (int) $offset;
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUnreadCount($userId) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM notification_history WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markAsRead($notificationId, $userId) {
        $stmt = $this->conn->prepare("
            UPDATE notification_history SET is_read = 1 WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notificationId, $userId]);
    }

    public function deleteNotification($notificationId, $userId) {
        $stmt = $this->conn->prepare("
            DELETE FROM notification_history WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notificationId, $userId]);
    }
}
