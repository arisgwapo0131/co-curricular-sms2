-- ============================================================
-- Component: Digital Elections & Voting
-- File: 07_elections_and_voting.sql
-- Description: Club and institutional elections, candidacies, secret ballots, and voter participation
-- ============================================================

CREATE TABLE IF NOT EXISTS `elections` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `election_code` VARCHAR(50) NOT NULL,
  `club_id` INT(11) NOT NULL,
  `scope` VARCHAR(50) DEFAULT 'Club',
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `election_type` VARCHAR(100) DEFAULT 'Student Governance',
  `starts_at` DATETIME DEFAULT NULL,
  `closes_at` DATETIME DEFAULT NULL,
  `status` ENUM('open', 'closed', 'counting') DEFAULT 'open',
  `eligible_voters` INT DEFAULT 0,
  `positions` TEXT DEFAULT NULL,
  `created_by` INT(11) NOT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `verified_by` INT(11) DEFAULT NULL,
  `audit_notes` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_election_code` (`election_code`),
  KEY `idx_elec_club` (`club_id`),
  KEY `idx_elec_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `election_candidates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `election_id` INT(11) NOT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `candidate_code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `position` VARCHAR(100) NOT NULL,
  `party` VARCHAR(150) DEFAULT NULL,
  `year_level` VARCHAR(50) DEFAULT NULL,
  `program` VARCHAR(50) DEFAULT NULL,
  `gwa` VARCHAR(20) DEFAULT NULL,
  `platform_tag` TEXT DEFAULT NULL,
  `achievements` TEXT DEFAULT NULL,
  `votes_count` INT(11) DEFAULT 0,
  `is_appointed` TINYINT(1) DEFAULT 0,
  `status` VARCHAR(50) DEFAULT 'Active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cand_election` (`election_id`),
  KEY `idx_cand_position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `election_voters` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `election_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `eligibility_status` VARCHAR(50) DEFAULT 'Eligible',
  `voted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_election_voter` (`election_id`, `user_id`),
  KEY `idx_ev_user` (`user_id`),
  CONSTRAINT `fk_ev_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `election_votes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `election_id` INT(11) NOT NULL,
  `ballot_token` VARCHAR(255) NULL,
  `ballot_data` TEXT NULL,
  `votes_json` TEXT NOT NULL,
  `cast_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `user_id` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vote_election` (`election_id`),
  KEY `idx_ballot_token` (`ballot_token`),
  CONSTRAINT `fk_vote_election` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
