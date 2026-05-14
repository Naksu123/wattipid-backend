-- ==========================================
-- WATTIPID FOREIGN KEY CONSTRAINTS
-- Enforces data integrity and cascade rules
-- ==========================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users to Rooms
-- If a room is deleted, the user is NOT deleted, but unassigned.
ALTER TABLE users 
ADD CONSTRAINT fk_users_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE SET NULL;

-- 2. Consumption Logs to Rooms
-- If a room is deleted, ALL associated raw logs are deleted immediately.
ALTER TABLE consumption_logs 
ADD CONSTRAINT fk_logs_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;

-- 3. Hourly Aggregated Logs to Rooms
ALTER TABLE consumption_hourly 
ADD CONSTRAINT fk_hourly_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;

-- 4. Monthly Archives to Rooms
ALTER TABLE monthly_archives 
ADD CONSTRAINT fk_archive_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;

-- 5. Budgets to Rooms
ALTER TABLE budget_settings 
ADD CONSTRAINT fk_budget_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;

-- 6. Notifications
-- Deleted if the associated room OR the specific user is deleted.
ALTER TABLE notifications 
ADD CONSTRAINT fk_notif_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
ADD CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- 7. Invitations to Rooms
ALTER TABLE invitations 
ADD CONSTRAINT fk_invite_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE;

-- 8. Unique Constraint Guarantees (No double budgeting / double logging per hour)
ALTER TABLE consumption_hourly ADD UNIQUE KEY uk_room_hour (room_id, date_hour);
ALTER TABLE monthly_archives ADD UNIQUE KEY uk_room_month (room_id, month_year);
ALTER TABLE budget_settings ADD UNIQUE KEY uk_budget_month (room_id, month, year);

SET FOREIGN_KEY_CHECKS = 1;
