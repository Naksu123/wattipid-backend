-- Phase 5 Penalty Management System Migrations

CREATE TABLE IF NOT EXISTS penalty_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    billing_cycle_id INT NOT NULL,
    room_id VARCHAR(50) NOT NULL,
    tenant_name VARCHAR(255) NOT NULL,
    original_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    penalty_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    penalty_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (billing_cycle_id) REFERENCES billing_cycles(id) ON DELETE CASCADE
);

-- Insert default Penalty Configurations into Settings
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('penalty_grace_period_days', '3');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('penalty_type', 'percentage_monthly');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('penalty_rate', '2.00');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('penalty_fixed_amount', '0.00');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('maximum_penalty_percent', '100');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('last_penalty_run_date', '2000-01-01');

-- Add index on due_date for fast querying of overdue bills
ALTER TABLE billing_cycles ADD INDEX IF NOT EXISTS idx_due_date (due_date);
