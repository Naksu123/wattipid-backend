-- ==========================================
-- WATTIPID PRODUCTION SCHEMA (setup.sql)
-- Complete, optimized schema for millions of IoT records
-- ==========================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. CORE ENTITIES

CREATE TABLE IF NOT EXISTS rooms (
    room_id VARCHAR(50) PRIMARY KEY, -- e.g., 'Room 1'
    tenant_code VARCHAR(100) UNIQUE DEFAULT NULL,
    status ENUM('vacant', 'on_process', 'occupied') DEFAULT 'vacant',
    tenant_name VARCHAR(255) DEFAULT NULL,
    tenant_start_date DATE DEFAULT NULL,
    device_secret VARCHAR(64) DEFAULT NULL,
    last_seen TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY, -- Can be migrated to CHAR(36) UUID for distributed scaling
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('tenant', 'landlord') NOT NULL DEFAULT 'tenant',
    room_id VARCHAR(50) DEFAULT NULL,
    push_token VARCHAR(255) DEFAULT NULL,
    is_verified TINYINT(1) DEFAULT 0,
    token_version INT DEFAULT 1,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- REFRESH TOKENS (Session Management & Rotation)
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revoked TINYINT(1) DEFAULT 0,
    INDEX (token_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- LOGIN ATTEMPTS (Brute-force protection)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) DEFAULT 0,
    ip_address VARCHAR(45),
    INDEX (identifier, attempt_time)
);

-- 2. SCALABLE IoT DATA TABLES

-- 2.1 Raw Consumption Logs (High frequency, 5-second interval)
-- Uses BIGINT for massive scalability. 
CREATE TABLE IF NOT EXISTS consumption_logs (
    id BIGINT AUTO_INCREMENT,
    room_id VARCHAR(50) NOT NULL,
    voltage DECIMAL(6, 2) DEFAULT 0,
    current_val DECIMAL(6, 3) DEFAULT 0,
    power DECIMAL(8, 2) DEFAULT 0,
    energy DECIMAL(10, 4) DEFAULT 0,
    energy_cumulative DECIMAL(15, 4) DEFAULT 0,
    cost DECIMAL(8, 2) DEFAULT 0,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, timestamp),
    INDEX idx_room_time (room_id, timestamp),
    CONSTRAINT fk_logs_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (UNIX_TIMESTAMP(timestamp)) (
    PARTITION p_initial VALUES LESS THAN (UNIX_TIMESTAMP('2024-01-01 00:00:00')),
    PARTITION p_2024_q1 VALUES LESS THAN (UNIX_TIMESTAMP('2024-04-01 00:00:00')),
    PARTITION p_2024_q2 VALUES LESS THAN (UNIX_TIMESTAMP('2024-07-01 00:00:00')),
    PARTITION p_2024_q3 VALUES LESS THAN (UNIX_TIMESTAMP('2024-10-01 00:00:00')),
    PARTITION p_2024_q4 VALUES LESS THAN (UNIX_TIMESTAMP('2025-01-01 00:00:00')),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- 2.2 Hourly Aggregated Logs (For long-term fast charting)
CREATE TABLE IF NOT EXISTS consumption_hourly (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(50) NOT NULL,
    date_hour DATETIME NOT NULL,
    avg_power DECIMAL(8, 2) DEFAULT 0,
    peak_power DECIMAL(8, 2) DEFAULT 0,
    total_energy DECIMAL(12, 4) DEFAULT 0,
    total_cost DECIMAL(10, 2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_room_hour (room_id, date_hour),
    CONSTRAINT fk_hourly_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.3 Monthly Archives (For billing and historical records)
CREATE TABLE IF NOT EXISTS monthly_archives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(50) NOT NULL,
    tenant_name VARCHAR(255) DEFAULT NULL,
    month_year VARCHAR(7) NOT NULL,
    total_energy DECIMAL(15, 4) DEFAULT 0,
    total_cost DECIMAL(15, 2) DEFAULT 0,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_room_month (room_id, month_year),
    CONSTRAINT fk_archive_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. CONFIGURATION & ALERTS

CREATE TABLE IF NOT EXISTS budget_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(50) NOT NULL,
    monthly_budget DECIMAL(10, 2) NOT NULL,
    daily_allowance DECIMAL(10, 2) DEFAULT 0,
    weekly_allowance DECIMAL(10, 2) DEFAULT 0,
    month INT NOT NULL,
    year INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_budget_month (room_id, month, year),
    CONSTRAINT fk_budget_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(50) DEFAULT NULL,
    user_id INT DEFAULT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read),
    CONSTRAINT fk_notif_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. AUTH & HISTORY

CREATE TABLE IF NOT EXISTS invitations (
    email VARCHAR(255) PRIMARY KEY,
    room_id VARCHAR(50) NOT NULL,
    tenant_code VARCHAR(100) NOT NULL,
    status ENUM('pending', 'used', 'expired') DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invite_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    type ENUM('verification', 'password_reset', 'access_code') NOT NULL,
    status ENUM('pending', 'used', 'expired') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_type (email, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(50) NOT NULL,
    tenant_name VARCHAR(255) DEFAULT NULL,
    tenant_email VARCHAR(255) DEFAULT NULL,
    tenant_start_date DATE DEFAULT NULL,
    move_out_date DATE DEFAULT NULL,
    status ENUM('moved_out', 'transferred') DEFAULT 'moved_out',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(50) NOT NULL, -- 'email', 'push_notification'
    payload_json JSON NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    error_log TEXT,
    available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_time (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- SEED DATA
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('rate_per_kwh', '12.50'), ('db_version', '2.0.0');
INSERT IGNORE INTO rooms (room_id, status) VALUES ('Room 1', 'vacant'), ('Room 2', 'vacant'), ('Room 3', 'vacant'), ('Room 4', 'vacant');
