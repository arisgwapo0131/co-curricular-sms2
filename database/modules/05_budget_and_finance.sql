-- ============================================================
-- Component: Budget & Financial Management
-- File: 05_budget_and_finance.sql
-- Description: Multi-tier club budget proposals, financial reviews, and disbursement
-- ============================================================

CREATE TABLE IF NOT EXISTS `budget_requests` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `line_items` JSON DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `recommended_amount` DECIMAL(10,2) DEFAULT NULL,
  `final_approved_amount` DECIMAL(10,2) DEFAULT NULL,
  `status` ENUM('Pending Adviser', 'Pending SSC', 'Pending Admin', 'Approved', 'Disbursed', 'Rejected', 'Returned') NOT NULL DEFAULT 'Pending Adviser',
  `requested_by` INT(10) UNSIGNED NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `disbursed_at` TIMESTAMP NULL DEFAULT NULL,
  `disbursement_reference` VARCHAR(100) DEFAULT NULL,
  `disbursed_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_br_club` (`club_id`),
  KEY `idx_br_status` (`status`),
  KEY `idx_br_requester` (`requested_by`),
  CONSTRAINT `fk_br_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_br_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
