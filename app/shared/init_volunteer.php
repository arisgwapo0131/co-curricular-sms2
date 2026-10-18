<?php
// ============================================================
//  INIT_VOLUNTEER.PHP  (app/shared/)
//  Database Schema Initializer for Volunteer Hours Module
//  Ensures volunteer_logs & volunteer_opportunities tables exist
//  with required columns, and seeds authentic event volunteer roles.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

// 1. Create volunteer_opportunities table
$conn->query("
    CREATE TABLE IF NOT EXISTS volunteer_opportunities (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id INT UNSIGNED NOT NULL,
        club_id INT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL,
        role_name VARCHAR(150) NOT NULL,
        description TEXT NULL,
        hours DECIMAL(5,2) NOT NULL DEFAULT 4.00,
        slots INT UNSIGNED NOT NULL DEFAULT 10,
        target_program VARCHAR(150) DEFAULT 'All',
        status ENUM('Open', 'Closed', 'Completed') NOT NULL DEFAULT 'Open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event (event_id),
        INDEX idx_club (club_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// 2. Ensure volunteer_logs table exists and has all required columns
$conn->query("
    CREATE TABLE IF NOT EXISTS volunteer_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        event_id INT UNSIGNED NULL,
        club_id INT UNSIGNED NULL,
        opportunity_id INT UNSIGNED NULL,
        student_name VARCHAR(150) NOT NULL,
        student_number VARCHAR(50) DEFAULT NULL,
        course VARCHAR(150) DEFAULT NULL,
        year_level VARCHAR(50) DEFAULT NULL,
        event_title VARCHAR(250) NOT NULL,
        volunteer_role VARCHAR(150) NOT NULL,
        notes TEXT NULL,
        check_in DATETIME DEFAULT NULL,
        check_out DATETIME DEFAULT NULL,
        hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        status ENUM('Verified', 'Pending Verification', 'Rejected') NOT NULL DEFAULT 'Pending Verification',
        verified_by INT UNSIGNED NULL,
        verified_at DATETIME DEFAULT NULL,
        verification_notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_event (event_id),
        INDEX idx_club (club_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// 3. Add any missing columns to existing volunteer_logs table
$existing_cols = [];
$res = $conn->query("DESCRIBE volunteer_logs");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $existing_cols[] = $r['Field'];
    }
}

$cols_to_add = [
    'event_id'           => "INT UNSIGNED NULL AFTER user_id",
    'club_id'            => "INT UNSIGNED NULL AFTER event_id",
    'opportunity_id'     => "INT UNSIGNED NULL AFTER club_id",
    'course'             => "VARCHAR(150) NULL AFTER student_number",
    'year_level'         => "VARCHAR(50) NULL AFTER course",
    'notes'              => "TEXT NULL AFTER volunteer_role",
    'verified_by'        => "INT UNSIGNED NULL AFTER status",
    'verified_at'        => "DATETIME NULL AFTER verified_by",
    'verification_notes' => "TEXT NULL AFTER verified_at"
];

foreach ($cols_to_add as $col => $definition) {
    if (!in_array($col, $existing_cols)) {
        $conn->query("ALTER TABLE volunteer_logs ADD COLUMN $col $definition");
    }
}

// 4. Seed authentic volunteer opportunities if empty
$cnt_opp = $conn->query("SELECT COUNT(*) as c FROM volunteer_opportunities")->fetch_assoc()['c'] ?? 0;
if ($cnt_opp == 0) {
    // Look up real events and clubs
    $ev_cssec = $conn->query("SELECT e.id, e.club_id FROM events e WHERE e.title LIKE '%Tech%' OR e.title LIKE '%Hackathon%' LIMIT 1")->fetch_assoc();
    $ev_rcyc  = $conn->query("SELECT e.id, e.club_id FROM events e WHERE e.title LIKE '%Blood%' OR e.title LIKE '%Donation%' LIMIT 1")->fetch_assoc();
    $ev_acac  = $conn->query("SELECT e.id, e.club_id FROM events e WHERE e.title LIKE '%Cultural%' OR e.title LIKE '%Sining%' LIMIT 1")->fetch_assoc();
    $ev_summit = $conn->query("SELECT e.id, e.club_id FROM events e WHERE e.title LIKE '%Summit%' OR e.title LIKE '%Leadership%' LIMIT 1")->fetch_assoc();

    $opportunities = [];

    if ($ev_cssec) {
        $opportunities[] = [
            'event_id'       => $ev_cssec['id'],
            'club_id'        => $ev_cssec['club_id'],
            'title'          => 'Hackathon Technical & Network Crew',
            'role_name'      => 'Technical Support & Network Crew',
            'description'    => 'Assist participant teams with network cables, projector displays, code repository access, and live presentation audio-visual systems.',
            'hours'          => 6.00,
            'slots'          => 8,
            'target_program' => 'BSIT, BSCS, BSIS'
        ];
        $opportunities[] = [
            'event_id'       => $ev_cssec['id'],
            'club_id'        => $ev_cssec['club_id'],
            'title'          => 'Hackathon Registration & Hacker Check-in Lead',
            'role_name'      => 'Registration Desk & Hacker Check-in',
            'description'    => 'Manage attendee registration rosters, distribute hacker lanyard kits, and verify team eligibility at the entrance terminal.',
            'hours'          => 4.00,
            'slots'          => 5,
            'target_program' => 'BSIT, BSCS, BSIS'
        ];
        $opportunities[] = [
            'event_id'       => $ev_cssec['id'],
            'club_id'        => $ev_cssec['club_id'],
            'title'          => 'Hackathon Media & Photography Coverage',
            'role_name'      => 'Documentation & Photography Specialist',
            'description'    => 'Capture high-resolution photo documentation of pitch rounds, judging sessions, and award ceremonies for co-curricular archives.',
            'hours'          => 4.00,
            'slots'          => 4,
            'target_program' => 'BSIT, BSCS, BSIS'
        ];
    }

    if ($ev_rcyc) {
        $opportunities[] = [
            'event_id'       => $ev_rcyc['id'],
            'club_id'        => $ev_rcyc['club_id'],
            'title'          => 'First Aid & Donor Care Assistant',
            'role_name'      => 'First Aid & Donor Care Assistant',
            'description'    => 'Monitor post-donation recovery lounge, check donor vital signs under medical supervision, and distribute health hydration kits.',
            'hours'          => 8.00,
            'slots'          => 12,
            'target_program' => 'All'
        ];
        $opportunities[] = [
            'event_id'       => $ev_rcyc['id'],
            'club_id'        => $ev_rcyc['club_id'],
            'title'          => 'Blood Drive Donor Triage & Registration',
            'role_name'      => 'Donor Registration & Triage Usher',
            'description'    => 'Screen initial donor health survey forms, organize queuing numbers, and guide volunteers through screening stations.',
            'hours'          => 5.00,
            'slots'          => 8,
            'target_program' => 'All'
        ];
        $opportunities[] = [
            'event_id'       => $ev_rcyc['id'],
            'club_id'        => $ev_rcyc['club_id'],
            'title'          => 'Recovery Nutrition & Logistics Aide',
            'role_name'      => 'Refreshment & Logistics Aide',
            'description'    => 'Manage recovery snack stations, prepare biscuits, juices, and ensure sanitation of resting cots.',
            'hours'          => 4.00,
            'slots'          => 6,
            'target_program' => 'All'
        ];
    }

    if ($ev_acac) {
        $opportunities[] = [
            'event_id'       => $ev_acac['id'],
            'club_id'        => $ev_acac['club_id'],
            'title'          => 'Cultural Gala Stage & Props Crew',
            'role_name'      => 'Stage Management & Backstage Usher',
            'description'    => 'Coordinate performer backstage call-times, move orchestral equipment, and assist stage managers during lighting transitions.',
            'hours'          => 5.00,
            'slots'          => 10,
            'target_program' => 'All'
        ];
        $opportunities[] = [
            'event_id'       => $ev_acac['id'],
            'club_id'        => $ev_acac['club_id'],
            'title'          => 'VIP Reception & Program Usher',
            'role_name'      => 'Guest Reception & Program Usher',
            'description'    => 'Welcome faculty and student audience members, distribute official gala program booklets, and maintain auditorium aisle decorum.',
            'hours'          => 4.00,
            'slots'          => 8,
            'target_program' => 'All'
        ];
    }

    if ($ev_summit) {
        $club_id_summit = $ev_summit['club_id'] ?: 1;
        $opportunities[] = [
            'event_id'       => $ev_summit['id'],
            'club_id'        => $club_id_summit,
            'title'          => 'Leadership Summit Secretariat Aide',
            'role_name'      => 'Summit Secretariat & Materials Logistics',
            'description'    => 'Prepare delegate conference kits, verify session attendance tokens, and coordinate question-and-answer floor microphones.',
            'hours'          => 6.00,
            'slots'          => 10,
            'target_program' => 'All'
        ];
    }

    $stmt = $conn->prepare("
        INSERT INTO volunteer_opportunities (event_id, club_id, title, role_name, description, hours, slots, target_program, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Open')
    ");
    foreach ($opportunities as $o) {
        $stmt->bind_param(
            'iisssdss',
            $o['event_id'],
            $o['club_id'],
            $o['title'],
            $o['role_name'],
            $o['description'],
            $o['hours'],
            $o['slots'],
            $o['target_program']
        );
        $stmt->execute();
    }
    $stmt->close();
}
