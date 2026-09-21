<?php
/**
 * DatabaseMigrationHelper
 * 
 * Automatically verifies and ensures that all required tables and columns 
 * for Billing, Payments, Overdue Tracking, and Penalties exist in the live database.
 * Completely idempotent and safe for repeated calls.
 */

class DatabaseMigrationHelper {
    private static $migrated = false;

    public static function ensureSchema($conn) {
        if (self::$migrated || !$conn instanceof PDO) {
            return;
        }

        try {
            // 1. Ensure columns on billing_cycles
            $bcColumns = [
                'invoice_number' => "VARCHAR(50) NULL AFTER id",
                'previous_reading' => "DECIMAL(10,4) DEFAULT 0.0000",
                'current_reading' => "DECIMAL(10,4) DEFAULT 0.0000",
                'rate_per_kwh' => "DECIMAL(10,2) DEFAULT 0.00",
                'monthly_rent' => "DECIMAL(10,2) DEFAULT 0.00",
                'electricity_charge' => "DECIMAL(10,2) DEFAULT 0.00",
                'miscellaneous_fee' => "DECIMAL(10,2) DEFAULT 0.00",
                'distribution_charge' => "DECIMAL(10,2) DEFAULT 0.00",
                'generation_charge' => "DECIMAL(10,2) DEFAULT 0.00",
                'transmission_charge' => "DECIMAL(10,2) DEFAULT 0.00",
                'system_loss_charge' => "DECIMAL(10,2) DEFAULT 0.00",
                'metering_charge' => "DECIMAL(10,2) DEFAULT 0.00",
                'supply_charge' => "DECIMAL(10,2) DEFAULT 0.00",
                'vat_amount' => "DECIMAL(10,2) DEFAULT 0.00",
                'penalty_amount' => "DECIMAL(10,2) DEFAULT 0.00",
                'previous_balance' => "DECIMAL(10,2) DEFAULT 0.00",
                'additional_charges' => "DECIMAL(10,2) DEFAULT 0.00",
                'discounts' => "DECIMAL(10,2) DEFAULT 0.00",
                'grand_total' => "DECIMAL(10,2) DEFAULT 0.00",
                'amount_paid' => "DECIMAL(10,2) DEFAULT 0.00",
                'pdf_url' => "VARCHAR(255) NULL",
                'due_date' => "DATETIME NULL"
            ];

            foreach ($bcColumns as $col => $definition) {
                self::addColumnIfMissing($conn, 'billing_cycles', $col, $definition);
            }

            // Ensure payment_status enum supports partially_paid and pending_verification
            try {
                $conn->exec("ALTER TABLE billing_cycles MODIFY COLUMN payment_status ENUM('unpaid', 'pending_verification', 'paid', 'overdue', 'partially_paid') DEFAULT 'unpaid'");
            } catch (Throwable $t) {
                // Ignore if already set or not permitted
            }

            // Backfill grand_total and electricity_charge where missing
            try {
                $conn->exec("UPDATE billing_cycles 
                             SET electricity_charge = total_cost 
                             WHERE (electricity_charge IS NULL OR electricity_charge = 0.00) AND total_cost > 0.00");
                $conn->exec("UPDATE billing_cycles 
                             SET grand_total = electricity_charge + COALESCE(monthly_rent, 0) + COALESCE(miscellaneous_fee, 0) + COALESCE(penalty_amount, 0) + COALESCE(previous_balance, 0) + COALESCE(additional_charges, 0) - COALESCE(discounts, 0) 
                             WHERE (grand_total IS NULL OR grand_total = 0.00) AND (electricity_charge > 0.00 OR total_cost > 0.00)");
            } catch (Throwable $t) {}

            // 2. Ensure penalty_history table
            $conn->exec("CREATE TABLE IF NOT EXISTS penalty_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                billing_cycle_id INT NOT NULL,
                room_id VARCHAR(50) NOT NULL,
                tenant_id INT NULL,
                tenant_name VARCHAR(255) NOT NULL,
                original_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                penalty_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                penalty_type VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                penalty_date DATE NOT NULL DEFAULT '2000-01-01',
                days_overdue INT NOT NULL DEFAULT 1,
                penalty_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                running_total_penalty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                current_outstanding_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                KEY idx_ph_billing_cycle (billing_cycle_id),
                KEY idx_ph_room (room_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $phColumns = [
                'tenant_id' => "INT NULL AFTER room_id",
                'penalty_date' => "DATE NOT NULL DEFAULT '2000-01-01' AFTER created_at",
                'days_overdue' => "INT NOT NULL DEFAULT 1 AFTER penalty_date",
                'penalty_rate' => "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER days_overdue",
                'running_total_penalty' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER penalty_rate",
                'current_outstanding_balance' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER running_total_penalty"
            ];

            foreach ($phColumns as $col => $definition) {
                self::addColumnIfMissing($conn, 'penalty_history', $col, $definition);
            }

            // Backfill penalty_date from created_at if default '2000-01-01'
            try {
                $conn->exec("UPDATE penalty_history SET penalty_date = DATE(created_at) WHERE penalty_date = '2000-01-01'");
            } catch (Throwable $t) {}

            // Ensure unique key on (billing_cycle_id, penalty_date)
            try {
                $checkIndex = $conn->query("SHOW INDEX FROM penalty_history WHERE Key_name = 'uq_billing_penalty_date'");
                if (!$checkIndex || $checkIndex->rowCount() === 0) {
                    // Remove any existing duplicate rows first to prevent key collision
                    $conn->exec("DELETE p1 FROM penalty_history p1
                                 INNER JOIN penalty_history p2 
                                 WHERE p1.id > p2.id 
                                   AND p1.billing_cycle_id = p2.billing_cycle_id 
                                   AND p1.penalty_date = p2.penalty_date");
                    $conn->exec("ALTER TABLE penalty_history ADD UNIQUE KEY uq_billing_penalty_date (billing_cycle_id, penalty_date)");
                }
            } catch (Throwable $t) {
                error_log("[DatabaseMigrationHelper] uq_billing_penalty_date warning: " . $t->getMessage());
            }

            // 3. Ensure financial_audit_logs table exists
            $conn->exec("CREATE TABLE IF NOT EXISTS financial_audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                actor_id INT NOT NULL,
                actor_role ENUM('admin','landlord','tenant') NOT NULL,
                action_type VARCHAR(100) NOT NULL,
                table_affected VARCHAR(100) NOT NULL,
                record_id INT NOT NULL,
                old_value TEXT DEFAULT NULL,
                new_value TEXT DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_audit_action (action_type),
                KEY idx_audit_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // 4. Ensure payments table columns
            $payColumns = [
                'payment_date' => "DATETIME NULL AFTER amount",
                'rejection_reason' => "TEXT DEFAULT NULL AFTER status",
                'verified_by' => "INT(11) DEFAULT NULL AFTER paid_at"
            ];
            foreach ($payColumns as $col => $definition) {
                self::addColumnIfMissing($conn, 'payments', $col, $definition);
            }

            // 5. Ensure default settings exist
            $defaultSettings = [
                'penalty_grace_period_days' => '3',
                'penalty_type' => 'percentage_daily',
                'penalty_rate' => '2.00',
                'penalty_fixed_amount' => '0.00',
                'maximum_penalty_percent' => '100',
                'maximum_penalty_limit' => '1000.00',
                'auto_email_penalties' => '1',
                'auto_push_penalties' => '1',
                'partial_payments_enabled' => 'false',
                'last_penalty_run_date' => '2000-01-01'
            ];

            $stmtCheckSetting = $conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $stmtInsertSetting = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");

            foreach ($defaultSettings as $key => $val) {
                $stmtCheckSetting->execute([$key]);
                if ((int)$stmtCheckSetting->fetchColumn() === 0) {
                    $stmtInsertSetting->execute([$key, $val]);
                }
            }

            self::$migrated = true;
        } catch (Throwable $e) {
            error_log("[DatabaseMigrationHelper] Error ensuring schema: " . $e->getMessage());
        }
    }

    private static function addColumnIfMissing($conn, $table, $column, $definition) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$table, $column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (Throwable $t) {
            error_log("[DatabaseMigrationHelper] Failed to add column {$table}.{$column}: " . $t->getMessage());
        }
    }
}
