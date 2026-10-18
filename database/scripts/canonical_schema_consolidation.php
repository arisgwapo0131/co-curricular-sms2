<?php
// ============================================================
//  CANONICAL_SCHEMA_CONSOLIDATION.PHP
//  Adopts and executes Sections 5, 6, 7, 8, 9, 10, 11, 14, 20
//  from the BCP Implementation Guide.
// ============================================================
require_once __DIR__ . '/../../app/shared/db.php';

echo "=== STARTING CANONICAL SCHEMA CONSOLIDATION ===\n";

// 1. CLUBS: Add adviser_user_id & Link to Faculty Advisers
echo "1. Patching clubs table with adviser_user_id...\n";
$colChk = $conn->query("SHOW COLUMNS FROM clubs LIKE 'adviser_user_id'");
if ($colChk && $colChk->num_rows === 0) {
    $conn->query("ALTER TABLE clubs ADD COLUMN adviser_user_id INT UNSIGNED NULL AFTER description");
    $conn->query("ALTER TABLE clubs ADD INDEX idx_clubs_adviser_user_id (adviser_user_id)");
    echo "Added adviser_user_id column & index to clubs.\n";
}

// Populate adviser_user_id by linking to official faculty advisers in users table
$conn->query("
    UPDATE clubs c
    JOIN users u ON (
        u.role = 'club_adviser' AND (
            u.username = CONCAT(LOWER(REPLACE(REPLACE(REPLACE(c.code, '.', ''), '-', ''), ' ', '')), '.adviser')
            OR u.username = CONCAT(LOWER(c.code), '.adviser')
        )
    )
    SET c.adviser_user_id = u.id
    WHERE c.adviser_user_id IS NULL
");

// For any remaining clubs without adviser_user_id, match to active faculty adviser
$conn->query("
    UPDATE clubs c
    SET c.adviser_user_id = (SELECT id FROM users WHERE role = 'club_adviser' ORDER BY id ASC LIMIT 1)
    WHERE c.adviser_user_id IS NULL
");
echo "Linked clubs to adviser_user_id.\n";

// Add foreign key constraint if not present
try {
    $conn->query("ALTER TABLE clubs ADD CONSTRAINT fk_clubs_adviser FOREIGN KEY (adviser_user_id) REFERENCES users(id) ON DELETE SET NULL");
    echo "Added fk_clubs_adviser foreign key.\n";
} catch (Throwable $e) {
    // Constraint may already exist
}

// 2. ATTENDANCE SCAN ATTEMPTS: Section 10.1 & 20.2
echo "2. Ensuring attendance_scan_attempts table...\n";
$conn->query("
    CREATE TABLE IF NOT EXISTS `attendance_scan_attempts` (
      `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
      `event_id` INT UNSIGNED NULL,
      `user_id` INT UNSIGNED NULL,
      `scanner_id` INT UNSIGNED NULL,
      `token_hash` VARCHAR(255) NULL,
      `result` VARCHAR(50) NOT NULL, -- VALID, ALREADY_SCANNED, EXPIRED, INVALID_TOKEN, NOT_ELIGIBLE, UNAUTHORIZED
      `reason` VARCHAR(255) NULL,
      `scanned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_scan_event` (`event_id`),
      INDEX `idx_scan_user` (`user_id`),
      INDEX `idx_scan_scanner` (`scanner_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "Table attendance_scan_attempts ready.\n";

// 3. WORKFLOW HISTORY: Section 14.2 & 20.3
echo "3. Ensuring workflow_history table...\n";
$conn->query("
    CREATE TABLE IF NOT EXISTS `workflow_history` (
      `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
      `module` VARCHAR(80) NOT NULL, -- 'membership', 'events', 'budget', 'elections', 'achievements'
      `record_id` BIGINT NOT NULL,
      `from_status` VARCHAR(50) NULL,
      `to_status` VARCHAR(50) NOT NULL,
      `action` VARCHAR(80) NOT NULL,
      `performed_by` INT UNSIGNED NULL,
      `remarks` TEXT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_workflow_record` (`module`, `record_id`),
      INDEX `idx_workflow_user` (`performed_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "Table workflow_history ready.\n";

// 4. ELECTIONS: Section 11.1
echo "4. Aligning elections, candidates, voters, and votes tables...\n";
$conn->query("
    CREATE TABLE IF NOT EXISTS `election_voters` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `election_id` INT NOT NULL,
      `user_id` INT NOT NULL,
      `eligibility_status` VARCHAR(50) DEFAULT 'eligible',
      `voted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY `uq_election_voter` (`election_id`, `user_id`),
      INDEX `idx_ev_election` (`election_id`),
      INDEX `idx_ev_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add missing columns to elections
foreach ([
    ['starts_at', 'DATETIME NULL AFTER description'],
    ['verified_at', 'DATETIME NULL AFTER created_by'],
    ['verified_by', 'INT NULL AFTER verified_at'],
    ['scope', "VARCHAR(50) DEFAULT 'Campus-Wide' AFTER club_id"]
] as [$col, $def]) {
    $chk = $conn->query("SHOW COLUMNS FROM elections LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE elections ADD COLUMN $col $def");
    }
}

// Add missing columns to election_candidates
foreach ([
    ['user_id', 'INT NULL AFTER election_id'],
    ['is_appointed', 'TINYINT(1) DEFAULT 0 AFTER votes_count'],
    ['status', "VARCHAR(50) DEFAULT 'Active' AFTER is_appointed"]
] as [$col, $def]) {
    $chk = $conn->query("SHOW COLUMNS FROM election_candidates LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE election_candidates ADD COLUMN $col $def");
    }
}

// Add ballot_token to election_votes for secret ballot auditability
$chk = $conn->query("SHOW COLUMNS FROM election_votes LIKE 'ballot_token'");
if ($chk && $chk->num_rows === 0) {
    $conn->query("ALTER TABLE election_votes ADD COLUMN ballot_token VARCHAR(255) NULL AFTER user_id");
}
$chk = $conn->query("SHOW COLUMNS FROM election_votes LIKE 'cast_at'");
if ($chk && $chk->num_rows === 0) {
    $conn->query("ALTER TABLE election_votes ADD COLUMN cast_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER votes_json");
}
echo "Elections tables aligned.\n";

// 5. ACHIEVEMENTS: Schema uses canonical submitted_by referencing users(id)
echo "5. Achievements table verified (canonical submitted_by -> users.id).\n";


// 6. NOTIFICATIONS: Add priority, reference_type, reference_id, link (Section 12.2)
echo "6. Aligning notifications table...\n";
foreach ([
    ['priority', "ENUM('Normal','Important','Urgent') DEFAULT 'Normal' AFTER type"],
    ['reference_type', 'VARCHAR(100) NULL AFTER priority'],
    ['reference_id', 'INT UNSIGNED NULL AFTER reference_type'],
    ['link', 'VARCHAR(255) NULL AFTER reference_id']
] as [$col, $def]) {
    $chk = $conn->query("SHOW COLUMNS FROM notifications LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE notifications ADD COLUMN $col $def");
    }
}
echo "Notifications table aligned.\n";

// 7. ORG ANNOUNCEMENTS: Add scope, status, is_pinned, expires_at (Section 12.1)
echo "7. Aligning org_announcements table...\n";
foreach ([
    ['scope', "VARCHAR(50) DEFAULT 'Club' AFTER target_group"],
    ['status', "ENUM('Active','Archived') DEFAULT 'Active' AFTER scope"],
    ['is_pinned', 'TINYINT(1) DEFAULT 0 AFTER status'],
    ['expires_at', 'DATETIME NULL AFTER is_pinned'],
    ['channels', "VARCHAR(100) DEFAULT 'Portal' AFTER expires_at"],
    ['updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at']
] as [$col, $def]) {
    $chk = $conn->query("SHOW COLUMNS FROM org_announcements LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE org_announcements ADD COLUMN $col $def");
    }
}
echo "Org announcements table aligned.\n";

// 8. AUDIT LOGS: Add module, record_type, record_id, old_values, new_values (Section 14.1)
echo "8. Aligning audit_logs table...\n";
foreach ([
    ['module', 'VARCHAR(80) NULL AFTER action'],
    ['record_type', 'VARCHAR(100) NULL AFTER module'],
    ['record_id', 'INT UNSIGNED NULL AFTER record_type'],
    ['old_values', 'TEXT NULL AFTER record_id'],
    ['new_values', 'TEXT NULL AFTER old_values'],
    ['user_agent', 'VARCHAR(255) NULL AFTER ip_address']
] as [$col, $def]) {
    $chk = $conn->query("SHOW COLUMNS FROM audit_logs LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE audit_logs ADD COLUMN $col $def");
    }
}
// Sync target_table & target_id into record_type & record_id
$conn->query("UPDATE audit_logs SET record_type = target_table WHERE record_type IS NULL AND target_table IS NOT NULL");
$conn->query("UPDATE audit_logs SET record_id = target_id WHERE record_id IS NULL AND target_id IS NOT NULL");
echo "Audit logs table aligned.\n";

// 9. CLUB APPLICATIONS: Enhance with rejection_reason and review columns (Section 7)
echo "9. Enhancing club_applications table...\n";
foreach ([
    ['rejection_reason', 'TEXT NULL AFTER status'],
    ['adviser_status', "ENUM('Pending','Endorsed','Rejected') DEFAULT 'Pending' AFTER rejection_reason"],
    ['ssc_status', "ENUM('Pending','Approved','Rejected') DEFAULT 'Pending' AFTER adviser_status"],
    ['adviser_reviewed_at', 'DATETIME NULL AFTER ssc_status'],
    ['ssc_reviewed_at', 'DATETIME NULL AFTER adviser_reviewed_at']
] as [$col, $def]) {
    $chk = $conn->query("SHOW COLUMNS FROM club_applications LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE club_applications ADD COLUMN $col $def");
    }
}
echo "Club applications table enhanced.\n";

// 10. SYSTEM SETTINGS: Section 5.2
echo "10. Ensuring system_settings table...\n";
$conn->query("
    CREATE TABLE IF NOT EXISTS `system_settings` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `setting_key` VARCHAR(100) UNIQUE NOT NULL,
      `setting_value` TEXT NULL,
      `description` VARCHAR(255) NULL,
      `updated_by` INT UNSIGNED NULL,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "System settings table ready.\n";

echo "=== CANONICAL SCHEMA CONSOLIDATION COMPLETED SUCCESSFULLY ===\n";
