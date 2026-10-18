-- ============================================================
-- Component: Attendance & On-Site Verification
-- File: 06_attendance_and_tracking.sql
-- Description: Automated QR, self-scan, RFID, and manual event check-ins
-- ============================================================

CREATE TABLE IF NOT EXISTS `attendance_logs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT(10) UNSIGNED NOT NULL,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `check_in` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `method` ENUM('QR', 'RFID', 'Manual', 'QR_SELF') NOT NULL DEFAULT 'QR',
  `logged_by` INT(10) UNSIGNED DEFAULT NULL,
  `override_reason` TEXT DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'Valid',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_user` (`event_id`, `user_id`),
  KEY `idx_att_user` (`user_id`),
  KEY `idx_att_event` (`event_id`),
  KEY `idx_att_method` (`method`),
  CONSTRAINT `fk_al_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_al_logger` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_scan_attempts` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT(10) UNSIGNED DEFAULT NULL,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `scanner_id` INT(10) UNSIGNED DEFAULT NULL,
  `token_hash` VARCHAR(64) DEFAULT NULL,
  `result` VARCHAR(50) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `scanned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_asa_event` (`event_id`),
  KEY `idx_asa_user` (`user_id`),
  KEY `idx_asa_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qr_sessions` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT(10) UNSIGNED NOT NULL,
  `created_by` INT(10) UNSIGNED NOT NULL,
  `current_token` VARCHAR(64) NOT NULL,
  `token_seed` VARCHAR(64) DEFAULT NULL,
  `status` ENUM('active', 'paused', 'closed', 'terminated') NOT NULL DEFAULT 'active',
  `refresh_seconds` INT(10) UNSIGNED NOT NULL DEFAULT 60,
  `late_after_minutes` INT(10) UNSIGNED NOT NULL DEFAULT 15,
  `opened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `closed_by` INT(10) UNSIGNED DEFAULT NULL,
  `close_reason` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qrs_event` (`event_id`),
  KEY `idx_qrs_status` (`status`),
  CONSTRAINT `fk_qrs_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
