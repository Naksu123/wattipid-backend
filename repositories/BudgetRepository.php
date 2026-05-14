<?php
class BudgetRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getBudgetByRoom($roomId) {
        $stmt = $this->conn->prepare("SELECT daily_allowance, weekly_allowance, monthly_budget FROM budget_settings WHERE room_id = ? LIMIT 1");
        $stmt->execute([$roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
