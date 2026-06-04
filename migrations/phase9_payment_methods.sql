-- Phase 9: Payment Methods & Partial Payments Migration

-- 1. Enhance billing_cycles with partial payment tracking
ALTER TABLE wattipid.billing_cycles
MODIFY COLUMN payment_status ENUM('unpaid', 'pending_verification', 'paid', 'overdue', 'partially_paid') DEFAULT 'unpaid';

ALTER TABLE wattipid.billing_cycles
ADD COLUMN amount_paid DECIMAL(10,2) DEFAULT 0.00 AFTER grand_total;

-- 2. Enhance payments table to support new payment methods and specific payment dates
ALTER TABLE wattipid.payments
MODIFY COLUMN payment_method VARCHAR(50) DEFAULT 'online';

ALTER TABLE wattipid.payments
ADD COLUMN payment_date DATETIME NULL AFTER amount;

-- 3. Enhance settings for landlord to store GCash/Maya details
-- These will just be inserted using the System Settings table. If the settings table doesn't have these, they will be created dynamically when saved.

-- Seed default partial payment setting
INSERT IGNORE INTO wattipid.settings (setting_key, setting_value)
VALUES ('partial_payments_enabled', 'false');

-- Note: The landlord's Maya and GCash settings (gcash_name, gcash_number, gcash_qr, maya_name, maya_number, maya_qr) 
-- will be dynamically created in the system_settings table when the landlord first saves them.
