-- Phase 6: Final Optimization and Consistency Migration

-- 1. Add Foreign Key constraints for billing_cycles to prevent orphaned records if a room is ever truly deleted
ALTER TABLE billing_cycles
ADD CONSTRAINT fk_billing_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;

-- 2. Add high-performance indexes for reporting and sorting
ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_payments_created_at (created_at DESC);
ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_notifications_user_created (user_id, created_at DESC);
ALTER TABLE financial_audit_logs ADD INDEX IF NOT EXISTS idx_audit_created_at (created_at DESC);

-- 3. Ensure users table has index on role for fast RBAC filtering
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_role (role);

-- 4. Notification Settings for all users
ALTER TABLE settings 
ADD COLUMN IF NOT EXISTS tenant_notif_new_bill ENUM('true', 'false') DEFAULT 'true',
ADD COLUMN IF NOT EXISTS tenant_notif_payment_status ENUM('true', 'false') DEFAULT 'true',
ADD COLUMN IF NOT EXISTS tenant_notif_due_reminder ENUM('true', 'false') DEFAULT 'true';

-- End of Phase 6 Migration
