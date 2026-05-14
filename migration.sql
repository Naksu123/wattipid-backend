-- ==========================================
-- WATTIPID PRODUCTION MIGRATION SCRIPT
-- Safely applies production optimizations to an existing database
-- ==========================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. DATA CLEANSING (Prevent Orphaned Records)
-- Deletes any child records that belong to a room or user that no longer exists.
DELETE FROM consumption_logs WHERE room_id NOT IN (SELECT room_id FROM rooms);
DELETE FROM budget_settings WHERE room_id NOT IN (SELECT room_id FROM rooms);
DELETE FROM notifications WHERE room_id NOT IN (SELECT room_id FROM rooms) AND room_id IS NOT NULL;
DELETE FROM notifications WHERE user_id NOT IN (SELECT id FROM users) AND user_id IS NOT NULL;
DELETE FROM users WHERE room_id NOT IN (SELECT room_id FROM rooms) AND room_id IS NOT NULL;

-- 2. CREATE NEW ARCHITECTURE TABLES
-- Hourly Aggregation (Resolves IoT Scaling issues)
CREATE TABLE IF NOT EXISTS consumption_hourly (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(50) NOT NULL,
    date_hour DATETIME NOT NULL,
    avg_power DECIMAL(8, 2) DEFAULT 0,
    peak_power DECIMAL(8, 2) DEFAULT 0,
    total_energy DECIMAL(12, 4) DEFAULT 0,
    total_cost DECIMAL(10, 2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_room_hour (room_id, date_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Unified OTP Table (Consolidates verification_codes & password_resets)
CREATE TABLE IF NOT EXISTS email_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    type ENUM('verification', 'password_reset', 'access_code') NOT NULL,
    status ENUM('pending', 'used', 'expired') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. APPLY COMPOSITE INDEXES (Prevents Full Table Scans)
-- ALTER TABLE consumption_logs ADD INDEX idx_analytics (room_id, timestamp);
-- Safely drop old inefficient index after the new one is created
ALTER TABLE consumption_logs DROP INDEX IF EXISTS idx_room_time; 

ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_user_read (user_id, is_read);
ALTER TABLE email_otps ADD INDEX IF NOT EXISTS idx_email_type (email, type);

-- 4. APPLY FOREIGN KEYS (Enforces Cascade Rules)
ALTER TABLE users ADD CONSTRAINT fk_users_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE SET NULL;
ALTER TABLE consumption_logs ADD CONSTRAINT fk_logs_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;
ALTER TABLE budget_settings ADD CONSTRAINT fk_budget_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;
ALTER TABLE notifications ADD CONSTRAINT fk_notif_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;
ALTER TABLE notifications ADD CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE consumption_hourly ADD CONSTRAINT fk_hourly_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
