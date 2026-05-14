<?php
/**
 * Wattipid Forecast Engine
 * 
 * Provides consumption forecasting using:
 * 1. Linear Projection — simple days-elapsed extrapolation
 * 2. Weighted Moving Average — recent days weighted more heavily
 * 3. Trend Detection — week-over-week slope analysis
 */

class ForecastEngine {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Generate a full monthly forecast for a room.
     */
    public function getMonthlyForecast($roomId, $tenantName = null) {
        $now = new DateTime();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('m');
        $dayOfMonth = (int) $now->format('j');
        $daysInMonth = (int) $now->format('t');
        $daysRemaining = $daysInMonth - $dayOfMonth;

        // Get current month's total consumption so far
        $current = $this->getMonthTotal($roomId, $year, $month, $tenantName);
        $currentCost = (float) ($current['totalCost'] ?? 0);
        $currentEnergy = (float) ($current['totalEnergy'] ?? 0);

        // Get daily breakdown for trend analysis
        $dailyData = $this->getDailyTotals($roomId, $year, $month, $tenantName);
        
        // Get last month's data for comparison
        $lastMonth = $month === 1 ? 12 : $month - 1;
        $lastYear = $month === 1 ? $year - 1 : $year;
        $previous = $this->getMonthTotal($roomId, $lastYear, $lastMonth, $tenantName);
        $previousCost = (float) ($previous['totalCost'] ?? 0);

        // Get 7-day average for weighted projection
        $last7Days = $this->getLast7DayAverage($roomId, $tenantName);

        // ---- FORECAST CALCULATIONS ----

        // Method 1: Linear Projection
        $linearDailyRate = $dayOfMonth > 0 ? $currentCost / $dayOfMonth : 0;
        $linearProjection = $linearDailyRate * $daysInMonth;

        // Method 2: Weighted Moving Average (recent 7 days weighted 70%, rest 30%)
        $avgDailyLast7 = $last7Days['avgDailyCost'] ?? 0;
        $avgDailyAll = $linearDailyRate;
        $weightedDaily = ($avgDailyLast7 * 0.7) + ($avgDailyAll * 0.3);
        $weightedProjection = $currentCost + ($weightedDaily * $daysRemaining);

        // Method 3: Trend-based — detect if usage is accelerating or decelerating
        $trend = $this->detectTrend($dailyData);
        $trendProjection = $this->applyTrend($currentCost, $daysRemaining, $weightedDaily, $trend);

        // Use the best projection (weighted average of all three)
        $projectedCost = ($linearProjection * 0.2) + ($weightedProjection * 0.5) + ($trendProjection * 0.3);
        
        // Get the rate
        $rateStmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'rate_per_kwh'");
        $rateStmt->execute();
        $rate = (float) ($rateStmt->fetchColumn() ?: 12.50);
        $projectedEnergy = $rate > 0 ? $projectedCost / $rate : 0;

        // Get budget for risk assessment
        $budget = $this->getBudget($roomId, $month, $year);
        $monthlyBudget = (float) ($budget['monthly_budget'] ?? 0);
        
        // Risk level calculation
        $riskLevel = 'low';
        $budgetPct = $monthlyBudget > 0 ? ($projectedCost / $monthlyBudget) * 100 : 0;
        if ($budgetPct >= 120) $riskLevel = 'critical';
        elseif ($budgetPct >= 100) $riskLevel = 'high';
        elseif ($budgetPct >= 80) $riskLevel = 'medium';

        // Confidence based on data availability
        $confidence = 'low';
        if ($dayOfMonth >= 20) $confidence = 'high';
        elseif ($dayOfMonth >= 10) $confidence = 'medium';

        // Daily budget to stay on track
        $dailyBudgetRemaining = $daysRemaining > 0 && $monthlyBudget > 0
            ? max(0, ($monthlyBudget - $currentCost) / $daysRemaining)
            : 0;

        return [
            'projected_monthly_cost' => round($projectedCost, 2),
            'projected_monthly_kwh' => round($projectedEnergy, 2),
            'current_month_cost' => round($currentCost, 2),
            'current_month_kwh' => round($currentEnergy, 2),
            'monthly_budget' => round($monthlyBudget, 2),
            'budget_pct' => round($budgetPct, 1),
            'confidence' => $confidence,
            'trend' => $trend['direction'],
            'trend_pct' => round($trend['change_pct'], 1),
            'days_elapsed' => $dayOfMonth,
            'days_remaining' => $daysRemaining,
            'days_in_month' => $daysInMonth,
            'daily_average' => round($linearDailyRate, 2),
            'daily_average_7d' => round($avgDailyLast7, 2),
            'daily_budget_to_stay_on_track' => round($dailyBudgetRemaining, 2),
            'risk_level' => $riskLevel,
            'previous_month_cost' => round($previousCost, 2),
            'month_over_month_pct' => $previousCost > 0 
                ? round((($projectedCost - $previousCost) / $previousCost) * 100, 1) 
                : 0,
        ];
    }

    /**
     * Get daily consumption analytics for a given period.
     */
    public function getDailyAnalytics($roomId, $days = 30, $tenantName = null) {
        $where = $tenantName ? "tenant_name = ?" : "room_id = ?";
        $param = $tenantName ?: $roomId;

        $stmt = $this->conn->prepare("
            SELECT 
                DATE(timestamp) as day,
                SUM(energy) as totalEnergy,
                SUM(cost) as totalCost,
                AVG(power) as avgPower,
                MAX(power) as peakPower,
                COUNT(*) as entries
            FROM consumption_logs
            WHERE $where AND timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(timestamp)
            ORDER BY day ASC
        ");
        $stmt->execute([$param, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Predict peak consumption hours based on historical patterns.
     */
    public function getPeakHourPrediction($roomId, $tenantName = null) {
        $where = $tenantName ? "tenant_name = ?" : "room_id = ?";
        $param = $tenantName ?: $roomId;

        $stmt = $this->conn->prepare("
            SELECT 
                HOUR(timestamp) as hour,
                AVG(power) as avgPower,
                AVG(cost) as avgCost,
                COUNT(*) as frequency
            FROM consumption_logs
            WHERE $where AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY HOUR(timestamp)
            ORDER BY avgPower DESC
        ");
        $stmt->execute([$param]);
        $hours = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $peakHours = array_slice($hours, 0, 3);
        $offPeakHours = array_slice(array_reverse($hours), 0, 3);

        return [
            'peak_hours' => $peakHours,
            'off_peak_hours' => $offPeakHours,
            'all_hours' => $hours,
        ];
    }

    // ---- PRIVATE HELPERS ----

    private function getMonthTotal($roomId, $year, $month, $tenantName = null) {
        $where = $tenantName ? "tenant_name = ?" : "room_id = ?";
        $param = $tenantName ?: $roomId;

        $startDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01 00:00:00";
        $stmt = $this->conn->prepare("
            SELECT SUM(energy) as totalEnergy, SUM(cost) as totalCost
            FROM consumption_logs
            WHERE $where 
            AND timestamp >= ? 
            AND timestamp < DATE_ADD(?, INTERVAL 1 MONTH)
        ");
        $stmt->execute([$param, $startDate, $startDate]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['totalEnergy' => 0, 'totalCost' => 0];
    }

    private function getDailyTotals($roomId, $year, $month, $tenantName = null) {
        $where = $tenantName ? "tenant_name = ?" : "room_id = ?";
        $param = $tenantName ?: $roomId;

        $startDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01 00:00:00";
        $stmt = $this->conn->prepare("
            SELECT DATE(timestamp) as day, SUM(cost) as dailyCost, SUM(energy) as dailyEnergy
            FROM consumption_logs
            WHERE $where 
            AND timestamp >= ? 
            AND timestamp < DATE_ADD(?, INTERVAL 1 MONTH)
            GROUP BY DATE(timestamp)
            ORDER BY day ASC
        ");
        $stmt->execute([$param, $startDate, $startDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getLast7DayAverage($roomId, $tenantName = null) {
        $where = $tenantName ? "tenant_name = ?" : "room_id = ?";
        $param = $tenantName ?: $roomId;

        $stmt = $this->conn->prepare("
            SELECT 
                AVG(daily_cost) as avgDailyCost,
                AVG(daily_energy) as avgDailyEnergy
            FROM (
                SELECT DATE(timestamp) as dt, SUM(cost) as daily_cost, SUM(energy) as daily_energy
                FROM consumption_logs
                WHERE $where AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(timestamp)
            ) as daily_totals
        ");
        $stmt->execute([$param]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['avgDailyCost' => 0, 'avgDailyEnergy' => 0];
    }

    private function detectTrend($dailyData) {
        $count = count($dailyData);
        if ($count < 3) {
            return ['direction' => 'stable', 'change_pct' => 0];
        }

        // Compare last 3 days vs first 3 days (or half/half)
        $half = max(1, intdiv($count, 2));
        $firstHalf = array_slice($dailyData, 0, $half);
        $secondHalf = array_slice($dailyData, $half);

        $avgFirst = 0;
        $avgSecond = 0;
        foreach ($firstHalf as $d) $avgFirst += (float) $d['dailyCost'];
        foreach ($secondHalf as $d) $avgSecond += (float) $d['dailyCost'];

        $avgFirst = count($firstHalf) > 0 ? $avgFirst / count($firstHalf) : 0;
        $avgSecond = count($secondHalf) > 0 ? $avgSecond / count($secondHalf) : 0;

        $changePct = $avgFirst > 0 ? (($avgSecond - $avgFirst) / $avgFirst) * 100 : 0;

        if ($changePct > 15) return ['direction' => 'increasing', 'change_pct' => $changePct];
        if ($changePct < -15) return ['direction' => 'decreasing', 'change_pct' => $changePct];
        return ['direction' => 'stable', 'change_pct' => $changePct];
    }

    private function applyTrend($currentCost, $daysRemaining, $dailyRate, $trend) {
        $factor = 1.0;
        if ($trend['direction'] === 'increasing') {
            $factor = 1 + (min($trend['change_pct'], 50) / 200); // Cap at 25% adjustment
        } elseif ($trend['direction'] === 'decreasing') {
            $factor = 1 + (max($trend['change_pct'], -50) / 200);
        }
        return $currentCost + ($dailyRate * $daysRemaining * $factor);
    }

    private function getBudget($roomId, $month, $year) {
        $stmt = $this->conn->prepare("
            SELECT monthly_budget, daily_allowance, weekly_allowance
            FROM budget_settings
            WHERE room_id = ? AND month = ? AND year = ?
        ");
        $stmt->execute([$roomId, $month, $year]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['monthly_budget' => 0, 'daily_allowance' => 0];
    }
}
