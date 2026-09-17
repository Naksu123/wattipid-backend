-- Phase 11: Penalty Idempotency & Database Constraints Migration
-- Wattipid Billing & Penalty Integrity

-- 1. Remove duplicates from penalty_history before applying unique constraint
DELETE FROM penalty_history WHERE id IN (72, 73, 74, 75);
DELETE FROM penalty_history WHERE id = 2;
DELETE FROM penalty_history WHERE id = 22;

-- 2. Clean duplicate notifications
DELETE FROM notification_history WHERE id IN (593, 594, 595, 596);

-- 3. Restore Cycle 13
UPDATE billing_cycles 
SET penalty_amount = 45.64, 
    grand_total = 127.20 
WHERE id = 13;

-- 4. Add penalty_date column
ALTER TABLE penalty_history 
ADD COLUMN penalty_date DATE NOT NULL DEFAULT '2000-01-01' AFTER created_at;

-- 5. Backfill penalty_date
UPDATE penalty_history SET penalty_date = DATE(created_at);

-- 6. Enforce single penalty per billing cycle per date
ALTER TABLE penalty_history 
ADD UNIQUE KEY uq_billing_penalty_date (billing_cycle_id, penalty_date);
