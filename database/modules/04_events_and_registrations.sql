-- ============================================================
-- Component: Events & Event Registrations
-- File: 04_events_and_registrations.sql
-- Description: Institutional & Club events, approval lifecycle, and student registrations
-- ============================================================

CREATE TABLE IF NOT EXISTS `events` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED DEFAULT NULL,
  `event_type` ENUM('Club', 'Institutional') NOT NULL DEFAULT 'Club',
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `event_date` DATETIME NOT NULL,
  `start_time` TIME DEFAULT NULL,
  `end_time` TIME DEFAULT NULL,
  `venue` VARCHAR(150) NOT NULL,
  `target_course` VARCHAR(255) NOT NULL DEFAULT 'All Courses',
  `target_org` VARCHAR(255) NOT NULL DEFAULT 'All Organizations',
  `expected_attendees` INT(10) UNSIGNED DEFAULT 0,
  `budget_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `attachment` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('Upcoming', 'Approved', 'Completed', 'Pending SSC', 'Pending Admin', 'Returned', 'Rejected') NOT NULL DEFAULT 'Pending SSC',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(10) UNSIGNED DEFAULT NULL,
  `endorsement_notes` TEXT DEFAULT NULL,
  `rejection_note` TEXT DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_events_club` (`club_id`),
  KEY `idx_events_status` (`status`),
  KEY `idx_events_date` (`event_date`),
  CONSTRAINT `fk_events_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_events_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_registrations` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT(10) UNSIGNED NOT NULL,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `registered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('Registered', 'Attended', 'Cancelled') NOT NULL DEFAULT 'Registered',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_user_reg` (`event_id`, `user_id`),
  KEY `idx_reg_user` (`user_id`),
  KEY `idx_reg_event` (`event_id`),
  CONSTRAINT `fk_er_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_er_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
