-- ==========================================
-- Phase 8: Detailed Billing Breakdown
-- ==========================================

-- 1. Extend billing_cycles to act as the single-source-of-truth billing_records table
ALTER TABLE billing_cycles
    ADD COLUMN IF NOT EXISTS previous_reading DECIMAL(10,4) DEFAULT 0.0000 AFTER cycle_end,
    ADD COLUMN IF NOT EXISTS current_reading DECIMAL(10,4) DEFAULT 0.0000 AFTER previous_reading,
    ADD COLUMN IF NOT EXISTS rate_per_kwh DECIMAL(10,2) DEFAULT 0.00 AFTER current_reading,
    ADD COLUMN IF NOT EXISTS monthly_rent DECIMAL(10,2) DEFAULT 0.00 AFTER rate_per_kwh,
    ADD COLUMN IF NOT EXISTS electricity_charge DECIMAL(10,2) DEFAULT 0.00 AFTER monthly_rent,
    ADD COLUMN IF NOT EXISTS previous_balance DECIMAL(10,2) DEFAULT 0.00 AFTER penalty_amount,
    ADD COLUMN IF NOT EXISTS additional_charges DECIMAL(10,2) DEFAULT 0.00 AFTER previous_balance,
    ADD COLUMN IF NOT EXISTS discounts DECIMAL(10,2) DEFAULT 0.00 AFTER additional_charges,
    ADD COLUMN IF NOT EXISTS grand_total DECIMAL(10,2) DEFAULT 0.00 AFTER discounts,
    ADD COLUMN IF NOT EXISTS pdf_url VARCHAR(255) NULL AFTER grand_total,
    ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(50) NULL AFTER id;

-- Add index on invoice_number for quick lookups
CREATE INDEX IF NOT EXISTS idx_bc_invoice_num ON billing_cycles(invoice_number);

-- Ensure backwards compatibility by auto-generating invoice numbers for existing records
UPDATE billing_cycles SET invoice_number = CONCAT('WT-', DATE_FORMAT(cycle_end, '%Y%m'), '-', id) WHERE invoice_number IS NULL;

-- Ensure all existing records have grand_total mapped based on existing cost+penalty
UPDATE billing_cycles SET electricity_charge = total_cost, grand_total = total_cost + penalty_amount WHERE grand_total = 0.00 AND total_cost > 0;
