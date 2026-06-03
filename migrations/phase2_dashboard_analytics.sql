-- Phase 2: Real-Time Dashboard Analytics & Logs
-- Defines schema for Payments tracking and Activity Logs

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    billing_cycle_id INT NOT NULL,
    room_id VARCHAR(50) NOT NULL,
    tenant_id INT,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'cash',
    reference_number VARCHAR(100),
    status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    paid_at DATETIME,
    verified_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (billing_cycle_id) REFERENCES billing_cycles(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT,
    actor_type ENUM('system', 'landlord', 'tenant', 'esp32') NOT NULL DEFAULT 'system',
    action_type VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    entity_type VARCHAR(50), -- e.g., 'room', 'payment', 'billing'
    entity_id VARCHAR(50),
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Pre-seed some activities for the dashboard demonstration if empty
INSERT INTO activity_logs (actor_type, action_type, description, entity_type, entity_id)
SELECT 'system', 'system_init', 'Real-Time Dashboard Analytics Initialized', 'system', '0'
WHERE NOT EXISTS (SELECT id FROM activity_logs);
