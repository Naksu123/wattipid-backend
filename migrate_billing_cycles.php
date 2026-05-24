<?php
$db = new PDO('mysql:host=localhost;dbname=wattipid', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Create billing_cycles table
$db->exec("
CREATE TABLE IF NOT EXISTS `billing_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` varchar(50) NOT NULL,
  `tenant_name` varchar(255) DEFAULT NULL,
  `cycle_start` datetime NOT NULL,
  `cycle_end` datetime NOT NULL,
  `total_kwh` decimal(10,4) DEFAULT 0.0000,
  `total_cost` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','completed') DEFAULT 'active',
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// 2. Add column to consumption_logs
try {
    $db->exec("ALTER TABLE consumption_logs ADD COLUMN billing_cycle_id INT NULL");
    $db->exec("ALTER TABLE consumption_logs ADD CONSTRAINT fk_billing_cycle FOREIGN KEY (billing_cycle_id) REFERENCES billing_cycles(id) ON DELETE SET NULL");
    echo "Added billing_cycle_id to consumption_logs.\n";
} catch (Exception $e) {
    echo "Column billing_cycle_id already exists or constraint exists.\n";
}

// 3. Migrate Historical Data
$rooms = $db->query("SELECT room_id, tenant_name, tenant_start_date FROM rooms WHERE status = 'occupied' AND tenant_start_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

function getSafeNextMonth($dateStr) {
    $dt = new DateTime($dateStr);
    $day = $dt->format('d');
    $dt->modify('+1 month');
    // If the day changed drastically (e.g. Jan 31 -> Mar 3), it means we skipped February
    if ($dt->format('d') != $day) {
        $dt->modify('last day of last month');
    }
    return $dt->format('Y-m-d H:i:s');
}

foreach ($rooms as $room) {
    $roomId = $room['room_id'];
    $tenantName = $room['tenant_name'];
    $startDateStr = $room['tenant_start_date'] . " 00:00:00";
    
    // Find the earliest log for this room
    $firstLog = $db->query("SELECT MIN(timestamp) as min_ts FROM consumption_logs WHERE room_id = '$roomId'")->fetchColumn();
    if (!$firstLog) $firstLog = $startDateStr; // No logs yet
    
    // We start creating cycles from tenant_start_date.
    $currentCycleStart = $startDateStr;
    $now = date('Y-m-d H:i:s');
    
    // Delete existing cycles for this room to allow clean rerun
    $db->exec("UPDATE consumption_logs SET billing_cycle_id = NULL WHERE room_id = '$roomId'");
    $db->exec("DELETE FROM billing_cycles WHERE room_id = '$roomId'");
    
    while ($currentCycleStart <= $now) {
        // Calculate cycle end
        $nextCycleStart = getSafeNextMonth($currentCycleStart);
        $currentCycleEnd = date('Y-m-d 23:59:59', strtotime($nextCycleStart . ' -1 day'));
        
        $status = ($nextCycleStart > $now) ? 'active' : 'completed';
        
        // Sum logs for this cycle
        $stmt = $db->prepare("SELECT SUM(energy) as e, SUM(cost) as c FROM consumption_logs WHERE room_id = ? AND timestamp >= ? AND timestamp <= ?");
        $stmt->execute([$roomId, $currentCycleStart, $currentCycleEnd]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        $kwh = $totals['e'] ?? 0;
        $cost = $totals['c'] ?? 0;
        
        // Insert Cycle
        $insert = $db->prepare("INSERT INTO billing_cycles (room_id, tenant_name, cycle_start, cycle_end, total_kwh, total_cost, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([$roomId, $tenantName, $currentCycleStart, $currentCycleEnd, $kwh, $cost, $status]);
        $cycleId = $db->lastInsertId();
        
        // Link Logs
        $updateLogs = $db->prepare("UPDATE consumption_logs SET billing_cycle_id = ? WHERE room_id = ? AND timestamp >= ? AND timestamp <= ?");
        $updateLogs->execute([$cycleId, $roomId, $currentCycleStart, $currentCycleEnd]);
        
        echo "Created Cycle: $currentCycleStart to $currentCycleEnd ($status) [ID: $cycleId] - $kwh kWh, ₱$cost\n";
        
        $currentCycleStart = $nextCycleStart;
    }
}

echo "Migration Complete.\n";
?>
