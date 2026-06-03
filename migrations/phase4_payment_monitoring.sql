-- Phase 4: Payment Monitoring System Migration

-- 1. Update billing_cycles table to act as the primary invoice record
ALTER TABLE wattipid.billing_cycles
ADD COLUMN payment_status ENUM('unpaid', 'pending_verification', 'paid', 'overdue') DEFAULT 'unpaid' AFTER status,
ADD COLUMN due_date DATETIME NULL AFTER payment_status,
ADD COLUMN penalty_amount DECIMAL(10,2) DEFAULT 0.00 AFTER total_cost;

-- 2. Enhance payments table to support proof uploads and rejections
ALTER TABLE wattipid.payments
ADD COLUMN proof_url VARCHAR(255) NULL AFTER amount,
ADD COLUMN rejection_reason TEXT NULL AFTER status;

-- 3. Create financial audit log table
CREATE TABLE IF NOT EXISTS wattipid.financial_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NOT NULL,
    actor_role ENUM('admin', 'landlord', 'tenant') NOT NULL,
    action_type VARCHAR(100) NOT NULL,
    table_affected VARCHAR(100) NOT NULL,
    record_id INT NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexing for fast dashboard queries
CREATE INDEX idx_payment_status ON wattipid.billing_cycles(payment_status);
CREATE INDEX idx_billing_due_date ON wattipid.billing_cycles(due_date);
CREATE INDEX idx_payments_status ON wattipid.payments(status);
CREATE INDEX idx_audit_action ON wattipid.financial_audit_logs(action_type);
