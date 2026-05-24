<?php
class BillingCycleService {
    private $db;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    /**
     * Safely calculate the next month's start date while preventing day-skipping bugs
     * e.g. Jan 31 + 1 month = Feb 28
     */
    private function getSafeNextMonth($dateStr) {
        $dt = new DateTime($dateStr);
        $day = $dt->format('d');
        $dt->modify('+1 month');
        if ($dt->format('d') != $day) {
            $dt->modify('last day of last month');
        }
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Core method to evaluate if a room's active billing cycle has ended, and dynamically roll it over.
     * Called lazily on IoT payload ingestion and dashboard loads.
     */
    public function advanceCycleIfNeeded($roomId) {
        // 1. Get the current active cycle
        $stmt = $this->db->prepare("SELECT * FROM billing_cycles WHERE room_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$roomId]);
        $activeCycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activeCycle) {
            // No active cycle. Attempt to initialize one based on tenant start date.
            $this->initializeCycleForTenant($roomId);
            return;
        }

        $now = date('Y-m-d H:i:s');

        // 2. Check if the cycle has expired
        if ($now > $activeCycle['cycle_end']) {
            // Cycle has ended. Complete it.
            
            // Calculate exact final totals for the cycle before closing
            $stmtTotals = $this->db->prepare("SELECT SUM(energy) as e, SUM(cost) as c FROM consumption_logs WHERE billing_cycle_id = ?");
            $stmtTotals->execute([$activeCycle['id']]);
            $totals = $stmtTotals->fetch(PDO::FETCH_ASSOC);
            $finalKwh = $totals['e'] ?? 0;
            $finalCost = $totals['c'] ?? 0;

            $update = $this->db->prepare("UPDATE billing_cycles SET status = 'completed', total_kwh = ?, total_cost = ? WHERE id = ?");
            $update->execute([$finalKwh, $finalCost, $activeCycle['id']]);

            // 3. Create the next cycle based on the exact start day of the previous cycle
            // This guarantees the cycle day NEVER drifts.
            $nextCycleStart = $this->getSafeNextMonth($activeCycle['cycle_start']);
            $nextCycleEnd = date('Y-m-d 23:59:59', strtotime($this->getSafeNextMonth($nextCycleStart) . ' -1 day'));

            // Edge case: what if the system was offline for 3 months? Fast-forward until the end date is > now.
            while ($nextCycleEnd < $now) {
                // Insert empty completed cycles to preserve history continuity
                $insert = $this->db->prepare("INSERT INTO billing_cycles (room_id, tenant_name, cycle_start, cycle_end, total_kwh, total_cost, status) VALUES (?, ?, ?, ?, 0, 0, 'completed')");
                $insert->execute([$roomId, $activeCycle['tenant_name'], $nextCycleStart, $nextCycleEnd]);
                
                $nextCycleStart = $this->getSafeNextMonth($nextCycleStart);
                $nextCycleEnd = date('Y-m-d 23:59:59', strtotime($this->getSafeNextMonth($nextCycleStart) . ' -1 day'));
            }

            // Insert the true active cycle
            $insertActive = $this->db->prepare("INSERT INTO billing_cycles (room_id, tenant_name, cycle_start, cycle_end, status) VALUES (?, ?, ?, ?, 'active')");
            $insertActive->execute([$roomId, $activeCycle['tenant_name'], $nextCycleStart, $nextCycleEnd]);
        }
    }

    /**
     * Used when a landlord manually adds a new tenant via the dashboard.
     */
    public function initializeCycleForTenant($roomId) {
        $stmt = $this->db->prepare("SELECT tenant_name, tenant_start_date FROM rooms WHERE room_id = ? AND status = 'occupied' AND tenant_start_date IS NOT NULL");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($room) {
            $startDate = $room['tenant_start_date'] . ' 00:00:00';
            $nextCycleStart = $this->getSafeNextMonth($startDate);
            $endDate = date('Y-m-d 23:59:59', strtotime($nextCycleStart . ' -1 day'));

            // Check if active cycle already exists to prevent duplicates
            $check = $this->db->prepare("SELECT id FROM billing_cycles WHERE room_id = ? AND status = 'active'");
            $check->execute([$roomId]);
            if (!$check->fetch()) {
                $insert = $this->db->prepare("INSERT INTO billing_cycles (room_id, tenant_name, cycle_start, cycle_end, status) VALUES (?, ?, ?, ?, 'active')");
                $insert->execute([$roomId, $room['tenant_name'], $startDate, $endDate]);
            }
        }
    }

    /**
     * Get the ID of the current active cycle to tag incoming IoT payloads.
     */
    public function getActiveCycleId($roomId) {
        $this->advanceCycleIfNeeded($roomId); // Lazy evaluation trigger
        $stmt = $this->db->prepare("SELECT id FROM billing_cycles WHERE room_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$roomId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? $res['id'] : null;
    }
}
?>
