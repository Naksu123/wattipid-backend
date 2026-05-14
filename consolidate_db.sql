-- ==========================================
-- WATTIPID SCHEMA CONSOLIDATION (v2.1)
-- Merging redundancies and optimizing for production
-- ==========================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. CLEAN UP REDUNDANT TABLES
DROP TABLE IF EXISTS verification_codes;
DROP TABLE IF EXISTS tenant_invitations;
DROP TABLE IF EXISTS notification_history;
DROP TABLE IF EXISTS notification_cooldowns; -- Now handled by logic in IoTService
DROP TABLE IF EXISTS device_tokens; -- Already handled by users.push_token
DROP TABLE IF EXISTS alert_settings; -- Now handled by budget_settings/global settings

-- 2. HARDEN FOREIGN KEYS (Ensure orphans are cleaned on delete)
ALTER TABLE notifications 
    DROP FOREIGN KEY IF EXISTS fk_notif_user,
    ADD CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE budget_settings 
    DROP FOREIGN KEY IF EXISTS fk_budget_room,
    ADD CONSTRAINT fk_budget_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;

-- 3. ADD MISSING INDEXES FOR IoT PERFORMANCE
-- Critical for fast dashboard loading with millions of logs
ALTER TABLE consumption_logs ADD INDEX IF NOT EXISTS idx_room_timestamp (room_id, timestamp);
ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_room_read (room_id, is_read);

SET FOREIGN_KEY_CHECKS = 1;
