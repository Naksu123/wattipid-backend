<?php
/**
 * WATTIPID DATABASE CLEANUP SCRIPT
 * Executes the architectural consolidation plan.
 */
require_once __DIR__ . '/config/db.php';

try {
    echo "Starting Wattipid Database Consolidation...\n";
    
    $sql = "
    SET FOREIGN_KEY_CHECKS = 0;

    -- 1. CLEAN UP REDUNDANT TABLES
    DROP TABLE IF EXISTS verification_codes;
    DROP TABLE IF EXISTS tenant_invitations;
    DROP TABLE IF EXISTS notification_history;
    DROP TABLE IF EXISTS notification_cooldowns;
    DROP TABLE IF EXISTS device_tokens;
    DROP TABLE IF EXISTS alert_settings;
    DROP TABLE IF EXISTS email_logs; -- Merged into jobs/notifications logic

    -- 2. HARDEN FOREIGN KEYS
    ALTER TABLE notifications 
        DROP FOREIGN KEY IF EXISTS fk_notif_user,
        ADD CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

    -- 3. ADD MISSING INDEXES
    -- Using a try-catch style for indexes in case they already exist
    try {
        \$conn->exec(\"ALTER TABLE consumption_logs ADD INDEX idx_room_timestamp (room_id, timestamp)\");
    } catch (Exception \$e) { /* Index might exist */ }

    try {
        \$conn->exec(\"ALTER TABLE notifications ADD INDEX idx_room_read (room_id, is_read)\");
    } catch (Exception \$e) { /* Index might exist */ }

    SET FOREIGN_KEY_CHECKS = 1;
    ";

    $conn->exec($sql);
    
    echo "SUCCESS: Redundant tables removed. Architecture consolidated.\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
