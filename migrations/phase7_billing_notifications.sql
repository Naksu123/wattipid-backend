-- ==========================================
-- Phase 7: Billing Notification System
-- ==========================================

-- 1. Add granular notification preference columns to alert_settings
ALTER TABLE alert_settings 
  ADD COLUMN IF NOT EXISTS due_date_alerts TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS overdue_alerts TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS penalty_alerts TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS payment_alerts TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS budget_50_alerts TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS budget_75_alerts TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS budget_90_alerts TINYINT(1) DEFAULT 1;

-- 2. Add index for faster due-date reminder queries on billing_cycles
CREATE INDEX IF NOT EXISTS idx_bc_payment_due 
  ON billing_cycles(payment_status, due_date);

-- 3. Add fulltext index for notification search
ALTER TABLE notification_history 
  ADD FULLTEXT INDEX IF NOT EXISTS ft_notif_search (title, message);
