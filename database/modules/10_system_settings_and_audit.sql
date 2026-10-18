-- ============================================================
-- Component: System Settings, Audit Logs & AI Analytics
-- File: 10_system_settings_and_audit.sql
-- Description: System key-value configurations, security audit logs, and AI recommendation telemetry
-- ============================================================

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `target_table` VARCHAR(100) DEFAULT NULL,
  `target_id` INT(10) UNSIGNED DEFAULT NULL,
  `detail` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_recommendation_logs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `request_type` VARCHAR(50) NOT NULL DEFAULT 'recommendation',
  `prompt_summary` TEXT DEFAULT NULL,
  `ai_response` MEDIUMTEXT DEFAULT NULL,
  `model_used` VARCHAR(100) DEFAULT 'gemini-2.0-flash',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_user` (`user_id`),
  KEY `idx_ai_type` (`request_type`),
  CONSTRAINT `fk_ai_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Core Initial Settings Seed
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('gemini_api_key', 'YOUR_GEMINI_API_KEY_HERE'),
('mfa_enabled', '1'),
('mfa_expiry_minutes', '10'),
('mfa_resend_cooldown', '60'),
('mfa_allow_dev_preview', '1'),
('smtp_host', 'smtp.gmail.com'),
('smtp_port', '587'),
('smtp_user', 'cgales923@gmail.com'),
('smtp_pass', 'grmm zqtb worn wxie'),
('smtp_crypto', 'tls'),
('mail_from_name', 'BCP Co-Curricular Management System'),
('mail_from_email', 'cgales923@gmail.com')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);
