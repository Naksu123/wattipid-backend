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

            // Fetch settings and room info for detailed breakdown
            $rateQuery = $this->db->query("SELECT setting_value FROM settings WHERE setting_key = 'rate_per_kwh'");
            $rateRow = $rateQuery->fetch(PDO::FETCH_ASSOC);
            $rate = $rateRow ? (float)$rateRow['setting_value'] : 12.50;

            $roomQuery = $this->db->prepare("SELECT monthly_rent FROM rooms WHERE room_id = ?");
            $roomQuery->execute([$roomId]);
            $roomRow = $roomQuery->fetch(PDO::FETCH_ASSOC);
            $monthlyRent = $roomRow ? (float)$roomRow['monthly_rent'] : 0.00;

            // Fetch previous cycle to get previous reading and previous balance
            $prevQuery = $this->db->prepare("SELECT current_reading, grand_total, payment_status FROM billing_cycles WHERE room_id = ? AND id < ? ORDER BY id DESC LIMIT 1");
            $prevQuery->execute([$roomId, $activeCycle['id']]);
            $prevCycle = $prevQuery->fetch(PDO::FETCH_ASSOC);
            
            $previousReading = $prevCycle ? (float)$prevCycle['current_reading'] : 0.00;
            $currentReading = $previousReading + $finalKwh;
            
            // Check previous balance if unpaid
            $previousBalance = 0.00;
            if ($prevCycle && in_array($prevCycle['payment_status'], ['unpaid', 'overdue', 'rejected'])) {
                $previousBalance = (float)$prevCycle['grand_total'];
            }

            // Defaults for new fields (Can be updated later via Landlord dashboard)
            $additionalCharges = 0.00;
            $discounts = 0.00;
            
            // Re-apply existing penalty if any (though usually 0 at closing time)
            $penaltyAmount = (float)($activeCycle['penalty_amount'] ?? 0);

            $grandTotal = $monthlyRent + $finalCost + $previousBalance + $additionalCharges + $penaltyAmount - $discounts;
            
            $invoiceNumber = 'WT-' . date('Ym', strtotime($activeCycle['cycle_end'])) . '-' . $activeCycle['id'];

            $update = $this->db->prepare("UPDATE billing_cycles SET 
                status = 'completed', 
                total_kwh = ?, 
                total_cost = ?, 
                due_date = DATE_ADD(NOW(), INTERVAL 3 DAY),
                previous_reading = ?,
                current_reading = ?,
                rate_per_kwh = ?,
                monthly_rent = ?,
                electricity_charge = ?,
                previous_balance = ?,
                additional_charges = ?,
                discounts = ?,
                grand_total = ?,
                invoice_number = ?
                WHERE id = ?");
                
            $update->execute([
                $finalKwh, 
                $finalCost, 
                $previousReading, 
                $currentReading, 
                $rate, 
                $monthlyRent, 
                $finalCost, // electricity_charge = total_cost
                $previousBalance, 
                $additionalCharges, 
                $discounts, 
                $grandTotal, 
                $invoiceNumber,
                $activeCycle['id']
            ]);

            // 3. Create the next cycle based on the exact start day of the previous cycle
            // This guarantees the cycle day NEVER drifts.
            $nextCycleStart = $this->getSafeNextMonth($activeCycle['cycle_start']);
            $nextCycleEnd = date('Y-m-d 23:59:59', strtotime($this->getSafeNextMonth($nextCycleStart) . ' -1 day'));

            // Edge case: what if the system was offline for 3 months? Fast-forward until the end date is > now.
            while ($nextCycleEnd < $now) {
                // Insert empty completed cycles to preserve history continuity
                $emptyInvoice = 'WT-' . date('Ym', strtotime($nextCycleEnd)) . '-0';
                $insert = $this->db->prepare("INSERT INTO billing_cycles (room_id, tenant_name, cycle_start, cycle_end, total_kwh, total_cost, status, due_date, invoice_number, current_reading, previous_reading) VALUES (?, ?, ?, ?, 0, 0, 'completed', DATE_ADD(?, INTERVAL 3 DAY), ?, ?, ?)");
                $insert->execute([$roomId, $activeCycle['tenant_name'], $nextCycleStart, $nextCycleEnd, $nextCycleEnd, $emptyInvoice, $currentReading, $currentReading]);
                
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
