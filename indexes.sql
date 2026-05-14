-- ==========================================
-- WATTIPID INDEXING STRATEGY
-- Standalone script to apply performance indexes
-- ==========================================

-- 1. IoT Consumption Analytics (High Priority)
-- Composite index speeds up time-range queries for specific rooms.
-- Prevents full table scans on millions of logs.
CREATE INDEX idx_analytics ON consumption_logs (room_id, timestamp);

-- 2. Notification System
-- Optimizes "get unread count" queries for active users.
CREATE INDEX idx_user_read ON notifications (user_id, is_read);

-- 3. Room Management
-- Speeds up filtering rooms by occupancy status.
CREATE INDEX idx_room_status ON rooms (status);

-- 4. Historical Tracking
-- Fast lookups of past tenants when a landlord clicks on a room.
CREATE INDEX idx_history_room ON tenant_history (room_id);

-- 5. Security & Authentication
-- Fast validation of OTP codes based on email and request type.
CREATE INDEX idx_email_type ON email_otps (email, type);
