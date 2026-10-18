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
  KEY `idx_notif_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_groups` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED DEFAULT NULL,
  `group_type` ENUM('club_general', 'club_executive', 'advisers_ssc') NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `icon` VARCHAR(50) DEFAULT 'fa-users',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_club_group_type` (`club_id`, `group_type`),
  KEY `idx_cg_club` (`club_id`),
  KEY `idx_cg_type` (`group_type`),
  CONSTRAINT `fk_cg_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sender_id` INT(10) UNSIGNED NOT NULL,
  `receiver_id` INT(10) UNSIGNED DEFAULT NULL,
  `club_id` INT(10) UNSIGNED DEFAULT NULL,
  `group_id` INT(10) UNSIGNED DEFAULT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_pair` (`sender_id`, `receiver_id`),
  KEY `idx_chat_receiver_read` (`receiver_id`, `is_read`),
  KEY `idx_chat_group` (`group_id`),
  KEY `idx_chat_created` (`created_at`),
  KEY `idx_chat_group_flow` (`group_id`, `created_at`, `id`),
  KEY `idx_chat_direct_flow` (`receiver_id`, `sender_id`, `created_at`, `id`),
  CONSTRAINT `fk_chat_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_group_reads` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` INT(10) UNSIGNED NOT NULL,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `last_read_message_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_user_read` (`group_id`, `user_id`),
  KEY `idx_cgr_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

