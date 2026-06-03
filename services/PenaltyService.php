<?php
class PenaltyService {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getPenaltySettings() {
        $stmt = $this->conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'penalty_%' OR setting_key = 'maximum_penalty_percent'");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function updatePenaltySettings($data, $adminId) {
        try {
            $this->conn->beginTransaction();
            $oldSettings = $this->getPenaltySettings();

            $updateStmt = $this->conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            
            $keys = ['penalty_grace_period_days', 'penalty_type', 'penalty_rate', 'penalty_fixed_amount', 'maximum_penalty_percent'];
            foreach ($keys as $key) {
                if (isset($data[$key])) {
                    $updateStmt->execute([$data[$key], $key]);
                    
                    // Audit log
                    $auditStmt = $this->conn->prepare("INSERT INTO financial_audit_logs (actor_id, actor_role, action_type, table_affected, record_id, old_value, new_value) VALUES (?, 'admin', 'update_penalty_setting', 'settings', 0, ?, ?)");
                    $auditStmt->execute([$adminId, $oldSettings[$key] ?? '', $data[$key]]);
                }
            }
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Penalty settings updated successfully'];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Failed to update settings: ' . $e->getMessage()];
        }
    }

    public function calculateDailyPenalties() {
        // Prevent running multiple times a day
        $settings = $this->getPenaltySettings();
        $stmt = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'last_penalty_run_date'");
        $lastRun = $stmt->fetchColumn();
        $today = date('Y-m-d');
        
        if ($lastRun === $today) {
            return ['success' => true, 'message' => 'Penalties already calculated today.', 'count' => 0];
        }

        $gracePeriod = (int)($settings['penalty_grace_period_days'] ?? 3);
        $penaltyRate = (float)($settings['penalty_rate'] ?? 2.00); // 2% of total billing

        try {
            $this->conn->beginTransaction();

            // Find all unpaid cycles where (due_date + grace_period) is in the past, and NOT already marked as overdue/penalized
            $sql = "SELECT * FROM billing_cycles 
                    WHERE payment_status = 'unpaid' 
                    AND due_date IS NOT NULL 
                    AND DATE_ADD(due_date, INTERVAL ? DAY) < NOW()";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$gracePeriod]);
            $overdueCycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $penaltiesApplied = 0;
            $updateCycleStmt = $this->conn->prepare("UPDATE billing_cycles SET payment_status = 'overdue', penalty_amount = ? WHERE id = ?");
            $logStmt = $this->conn->prepare("INSERT INTO penalty_history (billing_cycle_id, room_id, tenant_name, original_balance, penalty_amount, penalty_type) VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($overdueCycles as $cycle) {
                // Calculate 2% penalty
                $originalBalance = (float)$cycle['total_cost'];
                $penaltyAmount = round($originalBalance * ($penaltyRate / 100), 2);

                if ($penaltyAmount > 0) {
                    $updateCycleStmt->execute([$penaltyAmount, $cycle['id']]);
                    $logStmt->execute([
                        $cycle['id'], 
                        $cycle['room_id'], 
                        $cycle['tenant_name'], 
                        $originalBalance, 
                        $penaltyAmount, 
                        'percentage'
                    ]);
                    $penaltiesApplied++;
                }
            }

            // Update last run date
            $this->conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'last_penalty_run_date'")->execute([$today]);

            $this->conn->commit();
            return ['success' => true, 'message' => "Successfully applied penalties to $penaltiesApplied accounts.", 'count' => $penaltiesApplied];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return ['success' => false, 'message' => 'Failed to calculate penalties: ' . $e->getMessage()];
        }
    }

    public function getOverdueAccounts() {
        $sql = "SELECT b.id, b.room_id, b.tenant_name, b.due_date, b.total_cost as original_balance, b.penalty_amount, 
                (b.total_cost + COALESCE(b.penalty_amount, 0)) as total_amount_due,
                DATEDIFF(NOW(), b.due_date) as days_overdue
                FROM billing_cycles b 
                WHERE b.payment_status = 'overdue' 
                ORDER BY days_overdue DESC";
        $stmt = $this->conn->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getPenaltyAnalytics() {
        $sqlTotalOverdue = "SELECT COUNT(*) FROM billing_cycles WHERE payment_status = 'overdue'";
        $sqlTotalPenalty = "SELECT COALESCE(SUM(penalty_amount), 0) FROM billing_cycles WHERE payment_status = 'overdue'";
        
        return [
            'totalOverdueAccounts' => $this->conn->query($sqlTotalOverdue)->fetchColumn(),
            'totalActivePenalties' => $this->conn->query($sqlTotalPenalty)->fetchColumn(),
        ];
    }
}
