-- ============================================================
-- Component: Announcements & Notifications
-- File: 09_announcements_and_notifications.sql
-- Description: Club-wide announcements and targeted user notifications
-- ============================================================

CREATE TABLE IF NOT EXISTS `org_announcements` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED NULL,
  `scope` ENUM('System', 'Council', 'Club') NOT NULL DEFAULT 'Club',
  `author_id` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(250) NOT NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'General',
  `priority` ENUM('Normal', 'Important', 'Urgent') NOT NULL DEFAULT 'Normal',
  `status` ENUM('Draft', 'Published', 'Active', 'Archived') NOT NULL DEFAULT 'Published',
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME NULL,
  `content` TEXT NOT NULL,
  `target_group` VARCHAR(100) DEFAULT 'All Members',
  `channels` VARCHAR(100) DEFAULT 'In-App',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_oa_club` (`club_id`),
  KEY `idx_oa_author` (`author_id`),
  KEY `idx_oa_scope_status` (`scope`, `status`),
  CONSTRAINT `fk_oa_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_templates` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(50) UNIQUE NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'System Notice',
  `subject_template` VARCHAR(255) NOT NULL,
  `body_template` TEXT NOT NULL,
  `default_priority` ENUM('Normal', 'Important', 'Urgent') DEFAULT 'Normal',
  `default_target` VARCHAR(100) DEFAULT 'All Campus Users',
  `status` ENUM('Active', 'Archived') DEFAULT 'Active',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_tpl_cat` (`category`),
  INDEX `idx_tpl_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) DEFAULT 'info',
  `priority` ENUM('Normal', 'Important', 'Urgent') DEFAULT 'Normal',
  `reference_type` VARCHAR(100) NULL,
  `reference_id` INT(10) UNSIGNED NULL,
  `link` VARCHAR(255) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
