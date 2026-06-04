-- Phase 6: Final Database Optimization
-- Optimizing indexes for Real-Time Sync, Notifications, Billing, and Payments

-- Since XAMPP MariaDB might not support IF NOT EXISTS for indexes, we use DROP PROCEDURE wrapper or ignore errors in PHP.
-- We'll just run this through a quick PHP script instead to suppress duplicate errors, but here is the raw SQL:

-- 1. BILLING CYCLES
CREATE INDEX idx_billing_room_status ON billing_cycles (room_id, payment_status);
CREATE INDEX idx_billing_start_end ON billing_cycles (cycle_start, cycle_end);

-- 2. PAYMENTS
CREATE INDEX idx_payments_tenant_status ON payments (tenant_id, status);
CREATE INDEX idx_payments_cycle_status ON payments (billing_cycle_id, status);

-- 3. NOTIFICATIONS
CREATE INDEX idx_notif_user_read_time ON notifications (user_id, is_read, created_at);

-- 4. ELECTRICITY READINGS (consumption_logs)
CREATE INDEX idx_readings_room_date ON consumption_logs (room_id, timestamp);

-- 5. ACTIVITY LOGS
CREATE INDEX idx_activity_actor_time ON activity_logs (actor_id, created_at);
