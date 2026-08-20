<?php
class BillingCycleService {
    /** @var \PDO */
    private $db;

    public function __construct(\PDO $dbConnection) {
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
            
            // Calculate exact final energy total for the cycle before closing
            $stmtTotals = $this->db->prepare("SELECT SUM(energy) as e FROM consumption_logs WHERE billing_cycle_id = ?");
            $stmtTotals->execute([$activeCycle['id']]);
            $totals = $stmtTotals->fetch(PDO::FETCH_ASSOC);
            $finalKwh = (float)($totals['e'] ?? 0);

            // Fetch settings and room info for detailed breakdown
            $rateQuery = $this->db->query("SELECT setting_value FROM settings WHERE setting_key = 'rate_per_kwh'");
            $rateRow = $rateQuery->fetch(PDO::FETCH_ASSOC);
            $rate = $rateRow ? (float)$rateRow['setting_value'] : 12.50;

            $roomQuery = $this->db->prepare("SELECT monthly_rent FROM rooms WHERE room_id = ?");
            $roomQuery->execute([$roomId]);
            $roomRow = $roomQuery->fetch(PDO::FETCH_ASSOC);
            $monthlyRent = $roomRow ? (float)$roomRow['monthly_rent'] : 0.00;

            // Fetch previous cycle to get previous reading and previous balance
            $prevQuery = $this->db->prepare("SELECT current_reading, grand_total, amount_paid, payment_status FROM billing_cycles WHERE room_id = ? AND id < ? ORDER BY id DESC LIMIT 1");
            $prevQuery->execute([$roomId, $activeCycle['id']]);
            $prevCycle = $prevQuery->fetch(PDO::FETCH_ASSOC);
            
            $previousReading = $prevCycle ? (float)$prevCycle['current_reading'] : 0.00;
            $currentReading = $previousReading + $finalKwh;
            
            // Check previous balance if unpaid
            $previousBalance = 0.00;
            if ($prevCycle && in_array($prevCycle['payment_status'], ['unpaid', 'overdue', 'rejected', 'partially_paid'])) {
                $previousBalance = max(0.00, (float)$prevCycle['grand_total'] - (float)($prevCycle['amount_paid'] ?? 0));
            }

            // ================================================================
            // CENECO-Style Billing Breakdown (Proportional splits of rate)
            // ================================================================
            // CORRECT FORMULA: electricity_charge = total_kwh × rate_per_kwh
            // NOT SUM(cost) which suffers from micro-rounding loss
            $electricityCharge = round($finalKwh * $rate, 2);

            // CENECO proportional breakdown of the rate per kWh
            // Distribution: 15%, Generation: 50%, Transmission: 10%
            // System Loss: 5%, Metering: 5%, Supply: 5%, VAT: 10%
            $distributionCharge = round($finalKwh * ($rate * 0.15), 2);
            $generationCharge   = round($finalKwh * ($rate * 0.50), 2);
            $transmissionCharge = round($finalKwh * ($rate * 0.10), 2);
            $systemLossCharge   = round($finalKwh * ($rate * 0.05), 2);
            $meteringCharge     = round($finalKwh * ($rate * 0.05), 2);
            $supplyCharge       = round($finalKwh * ($rate * 0.05), 2);
            $vatAmount          = round($finalKwh * ($rate * 0.10), 2);

            // Miscellaneous Fee: 2% of electricity charge (per Terms §5)
            $miscellaneousFee = round($electricityCharge * 0.02, 2);

            // Additional Charges (configurable by landlord, default 0)
            $additionalCharges = 0.00;
            $discounts = 0.00;
            
            // Re-apply existing penalty if any (though usually 0 at closing time)
            $penaltyAmount = (float)($activeCycle['penalty_amount'] ?? 0);

            // TOTAL = electricity + misc fee + rent + previous balance + additional + penalty - discounts
            $grandTotal = $electricityCharge + $miscellaneousFee + $monthlyRent + $previousBalance + $additionalCharges + $penaltyAmount - $discounts;
            
            $invoiceNumber = 'WT-' . date('Ym', strtotime($activeCycle['cycle_end'])) . '-' . $activeCycle['id'];

            $update = $this->db->prepare("UPDATE billing_cycles SET 
                status = 'completed', 
                total_kwh = ?, 
                total_cost = ?, 
                due_date = DATE_ADD(cycle_end, INTERVAL 3 DAY),
                previous_reading = ?,
                current_reading = ?,
                rate_per_kwh = ?,
                monthly_rent = ?,
                electricity_charge = ?,
                distribution_charge = ?,
                generation_charge = ?,
                transmission_charge = ?,
                system_loss_charge = ?,
                metering_charge = ?,
                supply_charge = ?,
                vat_amount = ?,
                miscellaneous_fee = ?,
                previous_balance = ?,
                additional_charges = ?,
                discounts = ?,
                grand_total = ?,
                invoice_number = ?
                WHERE id = ?");
                
            $update->execute([
                $finalKwh, 
                $electricityCharge,   // total_cost = correct electricity charge
                $previousReading, 
                $currentReading, 
                $rate, 
                $monthlyRent, 
                $electricityCharge,   // electricity_charge = total_kwh × rate
                $distributionCharge,
                $generationCharge,
                $transmissionCharge,
                $systemLossCharge,
                $meteringCharge,
                $supplyCharge,
                $vatAmount,
                $miscellaneousFee,
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
                
                $checkEmpty = $this->db->prepare("SELECT id FROM billing_cycles WHERE room_id = ? AND DATE_FORMAT(cycle_start, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')");
                $checkEmpty->execute([$roomId, $nextCycleStart]);
                if (!$checkEmpty->fetch()) {
                    $insert = $this->db->prepare("INSERT INTO billing_cycles (room_id, tenant_name, cycle_start, cycle_end, total_kwh, total_cost, status, due_date, invoice_number, current_reading, previous_reading) VALUES (?, ?, ?, ?, 0, 0, 'completed', DATE_ADD(?, INTERVAL 3 DAY), ?, ?, ?)");
                    $insert->execute([$roomId, $activeCycle['tenant_name'], $nextCycleStart, $nextCycleEnd, $nextCycleEnd, $emptyInvoice, $currentReading, $currentReading]);
                }
                
                $nextCycleStart = $this->getSafeNextMonth($nextCycleStart);
                $nextCycleEnd = date('Y-m-d 23:59:59', strtotime($this->getSafeNextMonth($nextCycleStart) . ' -1 day'));
            }

            // Insert the true active cycle
            $checkActive = $this->db->prepare("SELECT id FROM billing_cycles WHERE room_id = ? AND DATE_FORMAT(cycle_start, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')");
            $checkActive->execute([$roomId, $nextCycleStart]);
            
            // Also ensure no other active cycle exists for this room
            $checkAnyActive = $this->db->prepare("SELECT id FROM billing_cycles WHERE room_id = ? AND status = 'active'");
            $checkAnyActive->execute([$roomId]);
            
            if (!$checkActive->fetch() && !$checkAnyActive->fetch()) {
                $insertActive = $this->db->prepare("INSERT INTO billing_cycles (room_id, tenant_name, cycle_start, cycle_end, status) VALUES (?, ?, ?, ?, 'active')");
                $insertActive->execute([$roomId, $activeCycle['tenant_name'], $nextCycleStart, $nextCycleEnd]);
            }
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
            
            $checkMonth = $this->db->prepare("SELECT id FROM billing_cycles WHERE room_id = ? AND DATE_FORMAT(cycle_start, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')");
            $checkMonth->execute([$roomId, $startDate]);
            
            if (!$check->fetch() && !$checkMonth->fetch()) {
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

    /**
     * Authoritative centralized calculation for a tenant's live bill mid-cycle.
     */
    public function getLiveBillBreakdown($roomId, $cycleId = null) {
        $activeCycleId = $cycleId ?: $this->getActiveCycleId($roomId);

        if (!$activeCycleId) {
            return [
                'consumptionKwh' => 0.00,
                'ratePerKwh' => 12.50,
                'electricityCharge' => 0.00,
                'monthlyRent' => 0.00,
                'previousBalance' => 0.00,
                'additionalCharges' => 0.00,
                'penalty' => 0.00,
                'discounts' => 0.00,
                'totalAmountDue' => 0.00,
                'cycle_start' => null,
                'cycle_end' => null
            ];
        }

        // 1. Authoritative Energy Sum (Using billing_cycle_id foreign key)
        $stmt = $this->db->prepare("SELECT SUM(energy) as totalEnergy, SUM(cost) as totalCost FROM consumption_logs WHERE billing_cycle_id = ?");
        $stmt->execute([$activeCycleId]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);

        $kwh = (float)($usage['totalEnergy'] ?? 0);
        
        $rateQuery = $this->db->query("SELECT setting_value FROM settings WHERE setting_key = 'rate_per_kwh'");
        $globalRate = $rateQuery->fetchColumn() ?: 12.50;

        $roomQuery = $this->db->prepare("SELECT utility_rate, monthly_rent FROM rooms WHERE room_id = ?");
        $roomQuery->execute([$roomId]);
        $roomInfo = $roomQuery->fetch(PDO::FETCH_ASSOC);

        $rate = (!empty($roomInfo['utility_rate']) && $roomInfo['utility_rate'] > 0) ? (float)$roomInfo['utility_rate'] : (float)$globalRate;

        $electricityCharge = round($kwh * $rate, 2);

        // 3. Fetch active cycle details for mid-month fees
        $cycleQuery = $this->db->prepare("SELECT * FROM billing_cycles WHERE id = ?");
        $cycleQuery->execute([$activeCycleId]);
        $activeCycle = $cycleQuery->fetch(PDO::FETCH_ASSOC);

        $additionalCharges = (float)($activeCycle['additional_charges'] ?? 0);
        $penalty = (float)($activeCycle['penalty_amount'] ?? 0);
        $discounts = (float)($activeCycle['discounts'] ?? 0);
        $previousBalance = (float)($activeCycle['previous_balance'] ?? 0);
        $rent = (float)($roomInfo['monthly_rent'] ?? 0);

        // Add 2% miscellaneous fee for live bill consistency
        $miscellaneousFee = round($electricityCharge * 0.02, 2);

        // Compute total live bill
        $total = $electricityCharge + $miscellaneousFee + $rent + $additionalCharges + $penalty + $previousBalance - $discounts;

        return [
            'consumptionKwh' => round($kwh, 4),
            'ratePerKwh' => round($rate, 2),
            'electricityCharge' => round($electricityCharge, 2),
            'miscellaneousFee' => round($miscellaneousFee, 2),
            'monthlyRent' => round($rent, 2),
            'previousBalance' => round($previousBalance, 2),
            'additionalCharges' => round($additionalCharges, 2),
            'penalty' => round($penalty, 2),
            'discounts' => round($discounts, 2),
            'totalAmountDue' => round($total, 2),
            'cycle_start' => $activeCycle['cycle_start'],
            'cycle_end' => $activeCycle['cycle_end']
        ];
    }
}


