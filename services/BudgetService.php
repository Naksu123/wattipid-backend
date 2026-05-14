<?php
require_once __DIR__ . '/../repositories/BudgetRepository.php';

class BudgetService {
    private $conn;
    private $budgetRepo;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        $this->budgetRepo = new BudgetRepository($dbConnection);
    }

    public function getBudget($roomId, $month, $year) {
        $month = $month ?? (int) date('m');
        $year = $year ?? (int) date('Y');
        
        $stmt = $this->conn->prepare("SELECT * FROM budget_settings WHERE room_id = ? AND month = ? AND year = ?");
        $stmt->execute([$roomId, $month, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $daysInMonth = (int) date('t', strtotime("$year-$month-01"));
            if (!isset($row['weekly_allowance']) || $row['weekly_allowance'] == 0) {
                $row['weekly_allowance'] = $row['monthly_budget'] / ($daysInMonth / 7);
            }
            if (!isset($row['daily_allowance']) || $row['daily_allowance'] == 0) {
                $row['daily_allowance'] = $row['monthly_budget'] / $daysInMonth;
            }
            $row['days_in_month'] = $daysInMonth;
            $today = (int) date('d');
            $row['remaining_days'] = max(0, $daysInMonth - $today + 1);
        }

        return ['success' => true, 'data' => $row];
    }

    public function updateBudget($roomId, $monthlyBudget, $dailyAllowance = null, $weeklyAllowance = null, $month = null, $year = null) {
        $month = $month ?? (int) date('m');
        $year = $year ?? (int) date('Y');
        $daysInMonth = (int) date('t', strtotime("$year-$month-01"));

        $daily = $dailyAllowance ?? ($monthlyBudget / $daysInMonth);
        $weekly = $weeklyAllowance ?? ($monthlyBudget / ($daysInMonth / 7));

        $stmt = $this->conn->prepare("INSERT INTO budget_settings (room_id, monthly_budget, daily_allowance, weekly_allowance, month, year) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE monthly_budget = VALUES(monthly_budget), daily_allowance = VALUES(daily_allowance), weekly_allowance = VALUES(weekly_allowance)");
        $stmt->execute([$roomId, $monthlyBudget, $daily, $weekly, $month, $year]);

        return [
            'success' => true,
            'data' => [
                "dailyAllowance" => $daily,
                "weeklyAllowance" => $weekly,
                "daysInMonth" => $daysInMonth
            ]
        ];
    }

    public function resetBudget($roomId, $month = null, $year = null) {
        $month = $month ?? (int) date('m');
        $year = $year ?? (int) date('Y');
        
        $stmt = $this->conn->prepare("DELETE FROM budget_settings WHERE room_id = ? AND month = ? AND year = ?");
        $stmt->execute([$roomId, $month, $year]);
        
        return ['success' => true, 'message' => 'Budget reset successfully'];
    }
}
