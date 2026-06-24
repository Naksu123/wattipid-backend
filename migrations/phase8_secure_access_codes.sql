ALTER TABLE `rooms` 
  DROP COLUMN `tenant_code`,
  ADD COLUMN `tenant_code_hash` VARCHAR(64) DEFAULT NULL,
  ADD COLUMN `tenant_code_encrypted` TEXT DEFAULT NULL,
  ADD COLUMN `tenant_code_masked` VARCHAR(20) DEFAULT NULL;

ALTER TABLE `invitations`
  DROP COLUMN `tenant_code`,
  ADD COLUMN `tenant_code_hash` VARCHAR(64) DEFAULT NULL,
  ADD COLUMN `tenant_code_encrypted` TEXT DEFAULT NULL,
  ADD COLUMN `tenant_code_masked` VARCHAR(20) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `access_code_audits` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `room_id` VARCHAR(50) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room_id` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
