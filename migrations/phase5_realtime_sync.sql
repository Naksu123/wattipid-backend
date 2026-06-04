-- Phase 5 Real-Time Sync & Notifications Schema Update

-- 1. Add Push Token to users table (if it doesn't exist)
SET @dbname = DATABASE();
SET @tablename = 'users';
SET @columnname = 'expo_push_token';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE users ADD COLUMN expo_push_token VARCHAR(255) DEFAULT NULL;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Create Activity Logs Table
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(50) DEFAULT NULL,
    user_id INT DEFAULT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Index for fast real-time polling
CREATE INDEX idx_activity_logs_room_created ON activity_logs(room_id, created_at);
