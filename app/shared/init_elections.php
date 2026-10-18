<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

// Security Guard: Only allow CLI execution or authenticated System Administrator when accessed directly
$is_direct = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'init_elections.php');
$is_cli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));
if ($is_direct && !$is_cli) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (empty($_SESSION['user_id']) || (($_SESSION['real_role'] ?? $_SESSION['role'] ?? '') !== 'admin')) {
        http_response_code(403);
        die("<h3>403 Forbidden</h3><p>Access denied. Schema initialization is restricted to System Administrators.</p>");
    }
}

$sql1 = "CREATE TABLE IF NOT EXISTS elections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_code VARCHAR(50) UNIQUE NOT NULL,
    club_id INT NOT NULL,
    scope VARCHAR(50) DEFAULT 'Club',
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    election_type VARCHAR(100) DEFAULT 'Student Governance',
    starts_at DATETIME NULL,
    closes_at DATETIME NULL,
    status VARCHAR(50) DEFAULT 'active',
    eligible_voters INT DEFAULT 0,
    positions TEXT NULL,
    verified_at DATETIME NULL,
    verified_by INT NULL,
    audit_notes TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$sql2 = "CREATE TABLE IF NOT EXISTS election_candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_id INT NOT NULL,
    user_id INT NULL,
    candidate_code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    position VARCHAR(100) NOT NULL,
    party VARCHAR(150) NULL,
    year_level VARCHAR(50) NULL,
    program VARCHAR(50) NULL,
    gwa VARCHAR(20) NULL,
    platform_tag TEXT NULL,
    achievements TEXT NULL,
    votes_count INT DEFAULT 0,
    is_appointed TINYINT(1) DEFAULT 0,
    status VARCHAR(50) DEFAULT 'Active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$sql3 = "CREATE TABLE IF NOT EXISTS election_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_id INT NOT NULL,
    ballot_token VARCHAR(255) NULL,
    ballot_data TEXT NULL,
    votes_json TEXT NOT NULL,
    cast_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    user_id INT NULL,
    KEY idx_vote_election (election_id),
    KEY idx_ballot_token (ballot_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$sql4 = "CREATE TABLE IF NOT EXISTS election_voters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_id INT NOT NULL,
    user_id INT NOT NULL,
    eligibility_status VARCHAR(50) DEFAULT 'Eligible',
    voted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_election_voter (election_id, user_id),
    KEY idx_ev_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$conn->query($sql1);
$conn->query($sql2);
$conn->query($sql3);
$conn->query($sql4);

// Dynamic Migrations for election_votes table
$vote_cols = [
    'ballot_token' => 'VARCHAR(255) NULL',
    'ballot_data'  => 'TEXT NULL',
    'cast_at'      => 'DATETIME NULL'
];
foreach ($vote_cols as $cName => $cDef) {
    $cChk = $conn->query("SHOW COLUMNS FROM election_votes LIKE '{$cName}'");
    if ($cChk && $cChk->num_rows === 0) {
        $conn->query("ALTER TABLE election_votes ADD COLUMN {$cName} {$cDef}");
    }
}

// Dynamic Migrations for elections table
$cols_to_add = [
    'scope'           => "VARCHAR(50) DEFAULT 'Club'",
    'election_type'   => "VARCHAR(100) DEFAULT 'Student Governance'",
    'starts_at'       => "DATETIME NULL",
    'eligible_voters'  => "INT DEFAULT 0",
    'approved_at'      => "DATETIME NULL",
    'approved_by'      => "INT NULL",
    'rejection_reason' => "TEXT NULL",
    'verified_at'           => "DATETIME NULL",
    'verified_by'           => "INT NULL",
    'poll_verified_at'      => "DATETIME NULL",
    'poll_verified_by'      => "INT NULL",
    'officers_announced_at' => "DATETIME NULL",
    'officers_announced_by' => "INT NULL",
    'officers_verified_at'  => "DATETIME NULL",
    'officers_verified_by'  => "INT NULL",
    'audit_notes'           => "TEXT NULL"
];

foreach ($cols_to_add as $col_name => $col_def) {
    $chk = $conn->query("SHOW COLUMNS FROM elections LIKE '{$col_name}'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE elections ADD COLUMN {$col_name} {$col_def}");
    }
}

// Ensure status column accepts draft, active, open, closed, verified
$conn->query("ALTER TABLE elections MODIFY COLUMN status VARCHAR(50) DEFAULT 'active'");

// Dynamic Migrations for election_candidates
$cand_cols = [
    'user_id'      => 'INT NULL',
    'is_appointed' => 'TINYINT(1) DEFAULT 0',
    'status'       => "VARCHAR(50) DEFAULT 'Active'",
];
foreach ($cand_cols as $cName => $cDef) {
    $cChk = $conn->query("SHOW COLUMNS FROM election_candidates LIKE '{$cName}'");
    if ($cChk && $cChk->num_rows === 0) {
        $conn->query("ALTER TABLE election_candidates ADD COLUMN {$cName} {$cDef}");
    }
}


