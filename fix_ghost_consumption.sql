-- ==========================================
-- WATTIPID GHOST CONSUMPTION CLEANUP
-- ==========================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Add device_secret column to rooms (for IoT device registration)
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS device_secret VARCHAR(64) DEFAULT NULL;

-- 2. Create notification_history table
CREATE TABLE IF NOT EXISTS notification_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    room_id VARCHAR(50) DEFAULT NULL,
    type VARCHAR(50) NOT NULL,
    category VARCHAR(50) DEFAULT 'system',
    severity VARCHAR(20) DEFAULT 'warning',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    data_json JSON DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    push_sent TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nh_user_read (user_id, is_read),
    INDEX idx_nh_room (room_id),
    INDEX idx_nh_created (created_at),
    INDEX idx_nh_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create notification_cooldowns table
CREATE TABLE IF NOT EXISTS notification_cooldowns (
    user_id INT NOT NULL,
    alert_type VARCHAR(50) NOT NULL,
    last_sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    daily_count INT DEFAULT 0,
    count_date DATE DEFAULT NULL,
    PRIMARY KEY (user_id, alert_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create alert_settings table
CREATE TABLE IF NOT EXISTS alert_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    room_id VARCHAR(50) DEFAULT NULL,
    daily_budget_limit DECIMAL(10,2) DEFAULT 150.00,
    monthly_budget_limit DECIMAL(10,2) DEFAULT 4500.00,
    abnormal_threshold_pct INT DEFAULT 50,
    spike_watts INT DEFAULT 1500,
    high_usage_minutes INT DEFAULT 120,
    forecast_warning_pct INT DEFAULT 90,
    notifications_enabled TINYINT(1) DEFAULT 1,
    push_enabled TINYINT(1) DEFAULT 1,
    sound_enabled TINYINT(1) DEFAULT 1,
    quiet_hours_start TIME DEFAULT '22:00:00',
    quiet_hours_end TIME DEFAULT '06:00:00',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_alert_user_room (user_id, room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Create device_tokens table
CREATE TABLE IF NOT EXISTS device_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    expo_push_token VARCHAR(255) NOT NULL,
    device_name VARCHAR(255) DEFAULT NULL,
    platform VARCHAR(20) DEFAULT 'android',
    is_active TINYINT(1) DEFAULT 1,
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_push_token (expo_push_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================
-- 6. CLEANUP: Remove all ghost notifications
-- ==========================================
DELETE FROM notifications WHERE 1=1;
DELETE FROM notification_history WHERE 1=1;
DELETE FROM notification_cooldowns WHERE 1=1;

-- ==========================================
-- 7. DIAGNOSTIC: Check for ghost data
-- ==========================================
SELECT 
    r.room_id, 
    r.device_secret IS NOT NULL as has_device,
    r.last_seen,
    COUNT(cl.id) as log_count
FROM rooms r
LEFT JOIN consumption_logs cl ON r.room_id = cl.room_id
GROUP BY r.room_id, r.device_secret, r.last_seen;
