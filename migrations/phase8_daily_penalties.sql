-- Phase 8: Daily Accumulating Penalty System Migrations

-- 1. Enhance penalty_history table
ALTER TABLE penalty_history 
ADD COLUMN tenant_id INT NULL AFTER room_id,
ADD COLUMN days_overdue INT NOT NULL DEFAULT 1 AFTER created_at,
ADD COLUMN penalty_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER days_overdue,
ADD COLUMN running_total_penalty DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER penalty_rate,
ADD COLUMN current_outstanding_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER running_total_penalty;

-- 2. Insert new settings for penalty automation
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('auto_email_penalties', '1');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('auto_push_penalties', '1');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('maximum_penalty_limit', '1000.00');

-- 3. Modify penalty type to daily (instead of percentage_monthly)
UPDATE settings SET setting_value = 'percentage_daily' WHERE setting_key = 'penalty_type';
