<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

$indexes = [
    "CREATE INDEX idx_billing_room_status ON billing_cycles (room_id, payment_status)",
    "CREATE INDEX idx_billing_start_end ON billing_cycles (cycle_start, cycle_end)",
    "CREATE INDEX idx_payments_tenant_status ON payments (tenant_id, status)",
    "CREATE INDEX idx_payments_cycle_status ON payments (billing_cycle_id, status)",
    "CREATE INDEX idx_notif_user_read_time ON notifications (user_id, is_read, created_at)",
    "CREATE INDEX idx_readings_room_date ON consumption_logs (room_id, timestamp)",
    "CREATE INDEX idx_activity_actor_time ON activity_logs (user_id, created_at)"
];

foreach ($indexes as $sql) {
    try {
        $conn->exec($sql);
        echo "Successfully executed: $sql\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42000' && strpos($e->getMessage(), 'Duplicate key') !== false) {
            echo "Skipped (Already exists): $sql\n";
        } else {
            echo "Error executing: $sql\n" . $e->getMessage() . "\n";
        }
    }
}
echo "Done.\n";
