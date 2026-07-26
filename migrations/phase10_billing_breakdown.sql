-- ==========================================
-- Phase 10: CENECO-Style Billing Breakdown
-- ==========================================

-- 1. Add detailed breakdown columns to billing_cycles
ALTER TABLE billing_cycles
    ADD COLUMN IF NOT EXISTS distribution_charge DECIMAL(10,2) DEFAULT 0.00 AFTER electricity_charge,
    ADD COLUMN IF NOT EXISTS generation_charge DECIMAL(10,2) DEFAULT 0.00 AFTER distribution_charge,
    ADD COLUMN IF NOT EXISTS transmission_charge DECIMAL(10,2) DEFAULT 0.00 AFTER generation_charge,
    ADD COLUMN IF NOT EXISTS system_loss_charge DECIMAL(10,2) DEFAULT 0.00 AFTER transmission_charge,
    ADD COLUMN IF NOT EXISTS metering_charge DECIMAL(10,2) DEFAULT 0.00 AFTER system_loss_charge,
    ADD COLUMN IF NOT EXISTS supply_charge DECIMAL(10,2) DEFAULT 0.00 AFTER metering_charge,
    ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(10,2) DEFAULT 0.00 AFTER supply_charge,
    ADD COLUMN IF NOT EXISTS miscellaneous_fee DECIMAL(10,2) DEFAULT 0.00 AFTER vat_amount;

-- 2. Increase consumption_logs.cost precision to prevent micro-rounding loss
ALTER TABLE consumption_logs MODIFY cost DECIMAL(10,6) DEFAULT 0.000000;

-- 3. Fix existing completed billing cycles: recalculate electricity_charge from total_kwh × rate_per_kwh
-- This corrects the ₱0.76 bug where SUM(rounded costs) was used instead of total_kwh × rate
UPDATE billing_cycles 
SET electricity_charge = ROUND(total_kwh * rate_per_kwh, 2)
WHERE status = 'completed' AND rate_per_kwh > 0 AND total_kwh > 0;

-- 4. Recalculate grand_total for corrected cycles (CENECO breakdown will be 0 for historical records)
UPDATE billing_cycles 
SET grand_total = electricity_charge + monthly_rent + COALESCE(previous_balance, 0) + COALESCE(additional_charges, 0) + COALESCE(penalty_amount, 0) - COALESCE(discounts, 0)
WHERE status = 'completed' AND rate_per_kwh > 0 AND total_kwh > 0;

-- 5. Also fix total_cost to match electricity_charge for consistency
UPDATE billing_cycles 
SET total_cost = electricity_charge
WHERE status = 'completed' AND rate_per_kwh > 0 AND total_kwh > 0;
