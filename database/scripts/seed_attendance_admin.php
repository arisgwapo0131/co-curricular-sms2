<?php
// ============================================================
//  SEED_ATTENDANCE_ADMIN.PHP
//  Database migration & realistic data seeder for Attendance Administration
// ============================================================
require_once __DIR__ . '/../../app/shared/db.php';

echo "1. Checking & updating attendance_logs schema..." . PHP_EOL;

// Check if uq_event_user unique index exists; drop it to allow duplicate/exception logs
$idxCheck = $conn->query("SHOW INDEX FROM attendance_logs WHERE Key_name = 'uq_event_user'");
if ($idxCheck && $idxCheck->num_rows > 0) {
    $conn->query("ALTER TABLE attendance_logs DROP INDEX uq_event_user");
    $conn->query("ALTER TABLE attendance_logs ADD KEY idx_event_user (event_id, user_id)");
    echo "  [OK] Converted uq_event_user to standard non-unique index idx_event_user." . PHP_EOL;
}

// Ensure user_id is nullable for invalid/external scan attempts
$conn->query("ALTER TABLE attendance_logs MODIFY user_id INT(10) UNSIGNED DEFAULT NULL");

// Ensure override_reason and status exist
$cols = [
    'override_reason' => "TEXT DEFAULT NULL AFTER logged_by",
    'status'          => "VARCHAR(50) NOT NULL DEFAULT 'Valid' AFTER override_reason"
];
foreach ($cols as $col => $def) {
    $cRes = $conn->query("SHOW COLUMNS FROM attendance_logs LIKE '$col'");
    if ($cRes && $cRes->num_rows === 0) {
        $conn->query("ALTER TABLE attendance_logs ADD COLUMN `$col` $def");
        echo "  [OK] Added column `$col`." . PHP_EOL;
    }
}

echo "2. Seeding official campus events if empty..." . PHP_EOL;
$evCount = (int)$conn->query("SELECT COUNT(*) FROM events")->fetch_row()[0];
if ($evCount === 0) {
    $adminIdRes = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $adminId = ($adminIdRes && $row = $adminIdRes->fetch_assoc()) ? (int)$row['id'] : 1;

    $sampleEvents = [
        [1, 'Institutional', 'Annual University Leadership Summit 2026', 'Leadership development conference for accredited club leaders and student body.', '2026-09-28 08:30:00', 'BCP University Grand Auditorium', 250, 'Approved', $adminId],
        [1, 'Club', 'TechSprint Hackathon & Code Fest', 'Departmental competitive hackathon showcasing emerging software engineering solutions.', '2026-09-28 10:00:00', 'Computer Studies Innovation Lab', 80, 'Approved', $adminId],
        [4, 'Club', 'Financial Literacy & Investment Symposium', 'Guest lecture on personal finance, taxation, and cooperative management.', '2026-09-25 13:00:00', 'Audio-Visual Center 1', 120, 'Completed', $adminId],
        [9, 'Club', 'Campus Sports & Wellness Festival', 'Inter-organization recreational sports games and team building.', '2026-09-29 07:00:00', 'University Gymnasium & Field', 300, 'Upcoming', $adminId],
        [2, 'Club', 'Robotics and Embedded Systems Expo', 'Hardware demonstration of IoT prototypes and robotics projects.', '2026-09-24 09:00:00', 'Engineering Hall', 90, 'Completed', $adminId]
    ];

    $evStmt = $conn->prepare("INSERT INTO events (club_id, event_type, title, description, event_date, venue, expected_attendees, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($sampleEvents as $e) {
        $evStmt->bind_param('isssssisi', $e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $e[6], $e[7], $e[8]);
        $evStmt->execute();
    }
    $evStmt->close();
    echo "  [OK] Seeded 5 official events." . PHP_EOL;
}

echo "3. Seeding authentic attendance administration records..." . PHP_EOL;
$attCount = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs")->fetch_row()[0];
if ($attCount === 0) {
    $events = $conn->query("SELECT id FROM events ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
    $students = $conn->query("SELECT id FROM users WHERE role = 'student' ORDER BY id ASC LIMIT 16")->fetch_all(MYSQLI_ASSOC);
    $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
    $ssc = $conn->query("SELECT id FROM users WHERE role = 'ssc' LIMIT 1")->fetch_assoc();

    $e1 = $events[0]['id'] ?? 1;
    $e2 = $events[1]['id'] ?? 2;
    $e3 = $events[2]['id'] ?? 3;

    $adminId = $admin['id'] ?? 58;
    $sscId = $ssc['id'] ?? 59;

    $records = [
        // Valid QR Check-ins
        [$e1, $students[0]['id'] ?? 1, '2026-09-28 08:35:12', 'QR', $students[0]['id'] ?? 1, null, 'Valid'],
        [$e1, $students[1]['id'] ?? 2, '2026-09-28 08:36:44', 'QR', $sscId, null, 'Valid'],
        [$e1, $students[2]['id'] ?? 3, '2026-09-28 08:38:05', 'QR_SELF', $students[2]['id'] ?? 3, null, 'Valid'],
        [$e1, $students[3]['id'] ?? 4, '2026-09-28 08:39:20', 'RFID', $sscId, null, 'Valid'],
        [$e1, $students[4]['id'] ?? 5, '2026-09-28 08:41:15', 'QR', $students[4]['id'] ?? 5, null, 'Valid'],
        [$e1, $students[5]['id'] ?? 6, '2026-09-28 08:42:50', 'QR', $sscId, null, 'Valid'],

        // Valid Late & Excused
        [$e1, $students[6]['id'] ?? 7, '2026-09-28 09:15:30', 'QR', $sscId, 'Admitted with late slip', 'Late'],
        [$e1, $students[7]['id'] ?? 8, '2026-09-28 09:22:10', 'Manual', $adminId, 'Official College Representation conflict approved by Dean', 'Excused'],

        // Manual Overrides (Requested by Admin specs)
        [$e1, $students[8]['id'] ?? 9, '2026-09-28 09:05:00', 'Manual', $adminId, 'Student barcode unreadable on damaged ID; verified via registration ledger', 'Manual Override'],
        [$e1, $students[9]['id'] ?? 10, '2026-09-28 09:12:45', 'Manual', $sscId, 'System scanner terminal offline during power fluctuation; verified manually', 'Manual Override'],
        [$e2, $students[10]['id'] ?? 11, '2026-09-28 10:04:18', 'Manual', $adminId, 'Faculty adviser endorsed late verification', 'Manual Override'],

        // Duplicate Scan Attempts (Requested by Admin specs)
        [$e1, $students[0]['id'] ?? 1, '2026-09-28 08:45:22', 'QR', $sscId, 'Duplicate QR scan rejected: student already verified present at 08:35:12', 'Duplicate Attempt'],
        [$e1, $students[2]['id'] ?? 3, '2026-09-28 08:52:10', 'QR_SELF', $students[2]['id'] ?? 3, 'Duplicate QR scan rejected: student already verified present at 08:38:05', 'Duplicate Attempt'],
        [$e2, $students[4]['id'] ?? 5, '2026-09-28 10:15:40', 'QR', $sscId, 'Duplicate QR scan attempt at Terminal 2', 'Duplicate Attempt'],

        // Invalid QR Scans (Requested by Admin specs)
        [$e1, $students[11]['id'] ?? 12, '2026-09-28 08:58:33', 'QR', $sscId, 'Invalid scan activity: QR format unrecognized or expired badge signature', 'Invalid QR Scan'],
        [$e1, $students[12]['id'] ?? 13, '2026-09-28 09:18:04', 'QR', $sscId, 'Invalid scan activity: checksum mismatch on corrupted badge code', 'Invalid QR Scan'],
        [$e2, null, '2026-09-28 10:20:15', 'QR', $sscId, 'Invalid scan activity: non-university QR code presented to terminal scanner', 'Invalid QR Scan'],

        // Event 3 Past Concluded Records
        [$e3, $students[0]['id'] ?? 1, '2026-09-25 13:02:10', 'QR', $sscId, null, 'Valid'],
        [$e3, $students[1]['id'] ?? 2, '2026-09-25 13:05:44', 'RFID', $sscId, null, 'Valid'],
        [$e3, $students[3]['id'] ?? 4, '2026-09-25 13:08:12', 'QR_SELF', $students[3]['id'] ?? 4, null, 'Valid'],
        [$e3, $students[5]['id'] ?? 6, '2026-09-25 13:12:00', 'Manual', $adminId, 'Attendance list manual confirmation by adviser', 'Manual Override'],
        [$e3, $students[7]['id'] ?? 8, '2026-09-25 13:15:30', 'QR', $sscId, null, 'Valid']
    ];

    $attStmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, check_in, method, logged_by, override_reason, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($records as $r) {
        $attStmt->bind_param('iississ', $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6]);
        $attStmt->execute();
    }
    $attStmt->close();
    echo "  [OK] Successfully seeded " . count($records) . " realistic attendance administration logs." . PHP_EOL;
}

echo "Migration and seeding completed successfully!" . PHP_EOL;
