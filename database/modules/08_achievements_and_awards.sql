-- ============================================================
-- Component: Student Achievements & Awards
-- File: 08_achievements_and_awards.sql
-- Description: External competition recognition, awards, and portfolio verification
-- ============================================================

CREATE TABLE IF NOT EXISTS `achievements` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED NOT NULL,
  `submitted_by` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(250) NOT NULL,
  `competition` VARCHAR(250) NOT NULL,
  `award_date` DATE NOT NULL,
  `proof_file` VARCHAR(300) DEFAULT NULL,
  `status` ENUM('Pending', 'Verified', 'Rejected') NOT NULL DEFAULT 'Pending',
  `verified_by` INT(10) UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ach_club` (`club_id`),
  KEY `idx_ach_submitter` (`submitted_by`),
  KEY `idx_ach_status` (`status`),
  CONSTRAINT `fk_ach_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ach_user` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ach_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
