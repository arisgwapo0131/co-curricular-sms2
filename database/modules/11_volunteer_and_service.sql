-- ============================================================
-- Component: Student Volunteer Hours & Community Service
-- File: 11_volunteer_and_service.sql
-- Description: Campus volunteer opportunities, shift tracking, and verified service hours
-- ============================================================

CREATE TABLE IF NOT EXISTS `volunteer_opportunities` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT(10) UNSIGNED NOT NULL,
  `club_id` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `role_name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `hours` DECIMAL(5,2) NOT NULL DEFAULT 4.00,
  `slots` INT(10) UNSIGNED NOT NULL DEFAULT 10,
  `target_program` VARCHAR(150) DEFAULT 'All',
  `status` ENUM('Open', 'Closed', 'Completed') NOT NULL DEFAULT 'Open',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event` (`event_id`),
  KEY `idx_club` (`club_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `volunteer_logs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `event_id` INT(10) UNSIGNED DEFAULT NULL,
  `club_id` INT(10) UNSIGNED DEFAULT NULL,
  `opportunity_id` INT(10) UNSIGNED DEFAULT NULL,
  `student_name` VARCHAR(150) NOT NULL,
  `student_number` VARCHAR(50) DEFAULT NULL,
  `course` VARCHAR(150) DEFAULT NULL,
  `year_level` VARCHAR(50) DEFAULT NULL,
  `event_title` VARCHAR(250) NOT NULL,
  `volunteer_role` VARCHAR(150) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `check_in` DATETIME DEFAULT NULL,
  `check_out` DATETIME DEFAULT NULL,
  `hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('Verified', 'Pending Verification', 'Rejected') NOT NULL DEFAULT 'Pending Verification',
  `verified_by` INT(10) UNSIGNED DEFAULT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `verification_notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_event` (`event_id`),
  KEY `idx_club` (`club_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
