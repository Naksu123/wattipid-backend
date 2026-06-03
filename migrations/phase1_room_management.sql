-- Phase 1 Room Management System Migration
-- Adds necessary columns for Room Management CRUD operations

-- 1. Modify the ENUM for status
ALTER TABLE rooms MODIFY COLUMN status ENUM('vacant', 'on_process', 'occupied', 'not_available', 'under_maintenance', 'archived') DEFAULT 'vacant';

-- 2. Add new columns
ALTER TABLE rooms 
    ADD COLUMN room_name VARCHAR(100) DEFAULT NULL AFTER room_id,
    ADD COLUMN room_type VARCHAR(50) DEFAULT NULL AFTER room_name,
    ADD COLUMN monthly_rent DECIMAL(10,2) DEFAULT 0 AFTER room_type,
    ADD COLUMN utility_rate DECIMAL(10,2) DEFAULT 0 AFTER monthly_rent,
    ADD COLUMN description TEXT DEFAULT NULL AFTER utility_rate,
    ADD COLUMN max_occupancy INT DEFAULT NULL AFTER description,
    ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN archived_by INT DEFAULT NULL;

-- 3. Add foreign key for archived_by if users table exists and is linked
ALTER TABLE rooms ADD CONSTRAINT fk_rooms_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL;
