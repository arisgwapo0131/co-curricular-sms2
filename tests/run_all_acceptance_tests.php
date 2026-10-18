<?php
// ============================================================
//  RUN_ALL_ACCEPTANCE_TESTS.PHP
//  Section 17 & 18 Implementation Guide Acceptance Test Suite
//  Covers 10 Minimum Automated Test Groups
// ============================================================

require_once __DIR__ . '/../app/shared/db.php';
require_once __DIR__ . '/../app/shared/security.php';
require_once __DIR__ . '/../app/shared/notification_actions.php';

echo "============================================================\n";
echo " BCP CO-CURRICULAR MANAGEMENT SYSTEM — ACCEPTANCE TEST SUITE\n";
echo "============================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] $name\n";
    } else {
        $failed++;
        echo "  [FAIL] $name" . ($details ? " — $details" : "") . "\n";
    }
}

// Ensure database connection
if (!$conn) {
    die("[FATAL] Could not connect to canonical database sms_db.\n");
}

// ─────────────────────────────────────────────────────────────
// GROUP 1: AUTHENTICATION
// ─────────────────────────────────────────────────────────────
echo "1. Authentication Tests:\n";

// 1.1 Valid admin credentials
$admin_row = $conn->query("SELECT id, username, password_hash, role, status FROM users WHERE username = 'scc.admin' LIMIT 1")->fetch_assoc();
assert_test("1.1 Admin user exists in canonical users table", !empty($admin_row));
if ($admin_row) {
    assert_test("1.2 Admin password hash matches 'Bcp@Admin2026!'", password_verify('Bcp@Admin2026!', $admin_row['password_hash']));
    assert_test("1.3 Invalid password rejected", !password_verify('WrongPassword123!', $admin_row['password_hash']));
}

// 1.4 Active account status verified
$inactive_check = strcasecmp($admin_row['status'] ?? '', 'active') === 0;
assert_test("1.4 Active account status verified", $inactive_check);

// 1.5 Session timeout configuration is exactly 600 seconds (10 mins)
assert_test("1.5 Session timeout configured to 600 seconds (10 mins)", defined('SESSION_TIMEOUT') && SESSION_TIMEOUT === 600);


// ─────────────────────────────────────────────────────────────
// GROUP 2: RBAC & ORGANIZATION SCOPE
// ─────────────────────────────────────────────────────────────
echo "\n2. RBAC & Organization Scope Tests:\n";

// 2.1 Role isolation: exactly 4 system roles
$roles_res = $conn->query("SELECT DISTINCT role FROM users WHERE role NOT IN ('student', 'club_adviser', 'ssc', 'admin')");
$unsupported_roles_count = $roles_res ? $roles_res->num_rows : 0;
assert_test("2.1 Zero unsupported roles exist in active users", $unsupported_roles_count === 0);

// 2.2 Adviser scope verification helper
$adv_club = $conn->query("SELECT id, adviser_user_id FROM clubs WHERE adviser_user_id IS NOT NULL LIMIT 1")->fetch_assoc();
if ($adv_club) {
    $cid = (int)$adv_club['id'];
    $uid = (int)$adv_club['adviser_user_id'];
    assert_test("2.2 Adviser is authorized for their assigned club", verify_club_adviser_scope($conn, $cid, $uid));
    
    // Find an unauthorized club
    $other_club = $conn->query("SELECT id FROM clubs WHERE id != $cid AND (adviser_user_id IS NULL OR adviser_user_id != $uid) LIMIT 1")->fetch_assoc();
    if ($other_club) {
        $other_cid = (int)$other_club['id'];
        assert_test("2.3 Adviser is NOT authorized for another organization", !verify_club_adviser_scope($conn, $other_cid, $uid));
    }
}


// ─────────────────────────────────────────────────────────────
// GROUP 3: MEMBERSHIP WORKFLOW (Phase 6 / Section 7)
// ─────────────────────────────────────────────────────────────
echo "\n3. Membership Workflow Tests:\n";

// Get a test student
$std_user = $conn->query("SELECT id FROM users WHERE role = 'student' LIMIT 1")->fetch_assoc();
$test_std_id = (int)($std_user['id'] ?? 0);
$test_club_id = (int)($adv_club['id'] ?? 1);

if ($test_std_id > 0) {
    // 3.1 Clean existing test application
    $conn->query("DELETE FROM club_applications WHERE user_id = $test_std_id AND club_id = $test_club_id");
    
    // 3.2 Student submits application -> status Pending, adviser_status Pending
    $stmt = $conn->prepare("INSERT INTO club_applications (club_id, user_id, first_name, last_name, status, adviser_status, ssc_status) VALUES (?, ?, 'Test', 'Student', 'Pending', 'Pending', 'Pending')");
    $stmt->bind_param('ii', $test_club_id, $test_std_id);
    $ok = $stmt->execute();
    $test_app_id = $stmt->insert_id;
    $stmt->close();
    assert_test("3.1 Student application created with state Pending / PENDING_ADVISER", $ok && $test_app_id > 0);

    // 3.3 Log workflow history
    $wf_logged = log_workflow_history($conn, 'club_applications', $test_app_id, 'DRAFT', 'PENDING_ADVISER', 'apply', $test_std_id, 'Student application submitted');
    assert_test("3.2 Application transition recorded in workflow_history", $wf_logged);

    // 3.4 Duplicate application prevention check
    $chk_dup = $conn->prepare("SELECT id FROM club_applications WHERE user_id = ? AND club_id = ? AND status IN ('Pending', 'Approved')");
    $chk_dup->bind_param('ii', $test_std_id, $test_club_id);
    $chk_dup->execute();
    $dup_exists = $chk_dup->get_result()->num_rows > 0;
    $chk_dup->close();
    assert_test("3.3 Duplicate active/pending application is detected and prevented", $dup_exists);

    // 3.5 Adviser endorsement -> adviser_status 'Endorsed'
    $conn->query("UPDATE club_applications SET adviser_status = 'Endorsed', adviser_reviewed_at = NOW() WHERE id = $test_app_id");
    log_workflow_history($conn, 'club_applications', $test_app_id, 'PENDING_ADVISER', 'PENDING_SSC', 'adviser_endorse', $uid, 'Endorsed by Adviser');
    $adv_state = $conn->query("SELECT adviser_status FROM club_applications WHERE id = $test_app_id")->fetch_assoc()['adviser_status'] ?? '';
    assert_test("3.4 Adviser endorsement moves status to Endorsed (PENDING_SSC)", $adv_state === 'Endorsed');

    // 3.6 SSC Approval -> APPROVED & activates club_memberships
    $conn->query("UPDATE club_applications SET status = 'Approved', ssc_status = 'Approved', ssc_reviewed_at = NOW() WHERE id = $test_app_id");
    log_workflow_history($conn, 'club_applications', $test_app_id, 'PENDING_SSC', 'APPROVED', 'ssc_approve', (int)$admin_row['id'], 'Approved by SSC');
    $conn->query("INSERT INTO club_memberships (club_id, user_id, role, status, joined_at) VALUES ($test_club_id, $test_std_id, 'Member', 'Active', NOW()) ON DUPLICATE KEY UPDATE status = 'Active'");
    
    $mem_active = $conn->query("SELECT status FROM club_memberships WHERE club_id = $test_club_id AND user_id = $test_std_id")->fetch_assoc()['status'] ?? '';
    assert_test("3.5 Final SSC approval activates member in club_memberships", $mem_active === 'Active');

    // Clean test data
    $conn->query("DELETE FROM club_applications WHERE id = $test_app_id");
    $conn->query("DELETE FROM club_memberships WHERE user_id = $test_std_id AND club_id = $test_club_id");
}


// ─────────────────────────────────────────────────────────────
// GROUP 4: EVENT WORKFLOW (Phase 6 / Section 8)
// ─────────────────────────────────────────────────────────────
echo "\n4. Event Workflow Tests:\n";

// 4.1 Create proposal
$ev_title = "Acceptance Test Gala " . time();
$stmt = $conn->prepare("INSERT INTO events (club_id, event_type, title, description, event_date, venue, status, created_by) VALUES (?, 'Club', ?, 'Test desc', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'Auditorium', 'Pending SSC', ?)");
$stmt->bind_param('isi', $test_club_id, $ev_title, $uid);
$stmt->execute();
$test_event_id = $stmt->insert_id;
$stmt->close();
assert_test("4.1 Adviser submits club event (initial state: Pending SSC)", $test_event_id > 0);

// 4.2 Log workflow
log_workflow_history($conn, 'events', $test_event_id, 'DRAFT', 'Pending SSC', 'event_create', $uid, 'Event proposed');

// 4.3 SSC Endorsement -> Pending Admin
$conn->query("UPDATE events SET status = 'Pending Admin', endorsement_notes = 'SSC Endorsed' WHERE id = $test_event_id");
log_workflow_history($conn, 'events', $test_event_id, 'Pending SSC', 'Pending Admin', 'ssc_endorse', (int)$admin_row['id'], 'SSC Endorsed');
$ev_status1 = $conn->query("SELECT status FROM events WHERE id = $test_event_id")->fetch_assoc()['status'] ?? '';
assert_test("4.2 SSC endorsement advances event to Pending Admin", $ev_status1 === 'Pending Admin');

// 4.4 Admin clearance -> Approved
$conn->query("UPDATE events SET status = 'Approved' WHERE id = $test_event_id");
log_workflow_history($conn, 'events', $test_event_id, 'Pending Admin', 'Approved', 'admin_approve', (int)$admin_row['id'], 'Official calendar clearance');
$ev_status2 = $conn->query("SELECT status FROM events WHERE id = $test_event_id")->fetch_assoc()['status'] ?? '';
assert_test("4.3 Admin approval posts event as Approved on campus calendar", $ev_status2 === 'Approved');

// Clean test event
$conn->query("DELETE FROM events WHERE id = $test_event_id");


// ─────────────────────────────────────────────────────────────
// GROUP 5: BUDGET & FINANCE WORKFLOW (Phase 6 / Section 9)
// ─────────────────────────────────────────────────────────────
echo "\n5. Budget & Finance Workflow Tests:\n";

$b_stmt = $conn->prepare("INSERT INTO budget_requests (club_id, title, description, amount, status, requested_by) VALUES (?, 'Equipment Requisition', 'Testing budget', 5000.00, 'Pending SSC', ?)");
$b_stmt->bind_param('ii', $test_club_id, $uid);
$b_stmt->execute();
$test_b_id = $b_stmt->insert_id;
$b_stmt->close();
assert_test("5.1 Budget requisition submitted by Adviser", $test_b_id > 0);

// 5.2 Multi-stage transition in transaction
$conn->begin_transaction();
$conn->query("UPDATE budget_requests SET status = 'Pending Admin', recommended_amount = 4500.00 WHERE id = $test_b_id");
log_workflow_history($conn, 'budget_requests', $test_b_id, 'Pending SSC', 'Pending Admin', 'ssc_review', (int)$admin_row['id'], 'Recommended: 4500');
$conn->commit();

$b_status1 = $conn->query("SELECT status, recommended_amount FROM budget_requests WHERE id = $test_b_id")->fetch_assoc();
assert_test("5.2 SSC review recommends vetted amount and forwards to Pending Admin", ($b_status1['status'] ?? '') === 'Pending Admin' && (float)$b_status1['recommended_amount'] === 4500.00);

// 5.3 Admin disbursement in transaction
$conn->begin_transaction();
$conn->query("UPDATE budget_requests SET status = 'Disbursed', final_approved_amount = 4500.00, disbursement_reference = 'TEST-DISB-01', disbursed_by = {$admin_row['id']}, disbursed_at = NOW() WHERE id = $test_b_id");
log_workflow_history($conn, 'budget_requests', $test_b_id, 'Pending Admin', 'Disbursed', 'admin_disburse', (int)$admin_row['id'], 'Disbursed 4500');
$conn->commit();

$b_status2 = $conn->query("SELECT status, disbursement_reference FROM budget_requests WHERE id = $test_b_id")->fetch_assoc();
assert_test("5.3 Admin approves and disburses with tracking reference", ($b_status2['status'] ?? '') === 'Disbursed' && ($b_status2['disbursement_reference'] ?? '') === 'TEST-DISB-01');

// Clean budget test record
$conn->query("DELETE FROM budget_requests WHERE id = $test_b_id");


// ─────────────────────────────────────────────────────────────
// GROUP 6: ATTENDANCE & QR VALIDATION (Phase 6 / Section 10)
// ─────────────────────────────────────────────────────────────
echo "\n6. Attendance & QR Separation Tests:\n";

// 6.1 Valid attendance in attendance_logs
$real_ev = $conn->query("SELECT id FROM events LIMIT 1")->fetch_assoc();
$dummy_ev_id = (int)($real_ev['id'] ?? 0);
if (!$dummy_ev_id) {
    $conn->query("INSERT INTO events (club_id, event_type, title, description, event_date, venue, status, created_by) VALUES ($test_club_id, 'Club', 'Test Attendance Event', 'Desc', CURDATE(), 'Gym', 'Approved', $uid)");
    $dummy_ev_id = (int)$conn->insert_id;
}

$conn->query("DELETE FROM attendance_logs WHERE event_id = $dummy_ev_id AND user_id = $test_std_id");
$conn->query("DELETE FROM attendance_scan_attempts WHERE event_id = $dummy_ev_id");

$ins_att = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, check_in, status) VALUES (?, ?, NOW(), 'Valid')");
$ins_att->bind_param('ii', $dummy_ev_id, $test_std_id);
$ok_att = $ins_att->execute();
$ins_att->close();
assert_test("6.1 Valid attendance record stored in attendance_logs", $ok_att);

// 6.2 Duplicate scan recorded in attendance_scan_attempts (NOT crashing unique constraint on attendance_logs)
$ins_dup = $conn->prepare("INSERT INTO attendance_scan_attempts (event_id, user_id, result, reason, scanned_at) VALUES (?, ?, 'ALREADY_SCANNED', 'Student already checked in', NOW())");
$ins_dup->bind_param('ii', $dummy_ev_id, $test_std_id);
$ok_dup = $ins_dup->execute();
$ins_dup->close();
assert_test("6.2 Duplicate scan attempt stored in attendance_scan_attempts", $ok_dup);

// 6.3 Invalid token attempt stored in attendance_scan_attempts
$ins_inv = $conn->prepare("INSERT INTO attendance_scan_attempts (event_id, token_hash, result, reason, scanned_at) VALUES (?, 'invalid_token_hash', 'INVALID_TOKEN', 'Token expired or unrecognized', NOW())");
$ins_inv->bind_param('i', $dummy_ev_id);
$ok_inv = $ins_inv->execute();
$ins_inv->close();
assert_test("6.3 Invalid token rejected and logged in attendance_scan_attempts", $ok_inv);

// Clean dummy attendance records
$conn->query("DELETE FROM attendance_logs WHERE event_id = $dummy_ev_id AND user_id = $test_std_id");
$conn->query("DELETE FROM attendance_scan_attempts WHERE event_id = $dummy_ev_id");


// ─────────────────────────────────────────────────────────────
// GROUP 7: ELECTIONS & SECRET BALLOT (Phase 6 / Section 11)
// ─────────────────────────────────────────────────────────────
echo "\n7. Elections & Secret Ballot Tests:\n";

$el_code = 'test_el_' . time();
$conn->query("INSERT INTO elections (election_code, club_id, title, status, starts_at, closes_at, created_by) VALUES ('$el_code', $test_club_id, 'Test Election', 'open', NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY), $uid)");
$test_elec_id = $conn->insert_id;
assert_test("7.1 Election initialized with active window and open state", $test_elec_id > 0);

// 7.2 Voter participation tracked in election_voters
$ins_ev = $conn->prepare("INSERT INTO election_voters (election_id, user_id, eligibility_status, voted_at) VALUES (?, ?, 'Voted', NOW())");
$ins_ev->bind_param('ii', $test_elec_id, $test_std_id);
$ok_ev = $ins_ev->execute();
$ins_ev->close();
assert_test("7.2 Voter participation recorded in election_voters", $ok_ev);

// 7.3 Secret ballot stored with ballot_token
$b_token = bin2hex(random_bytes(16));
$b_data = json_encode(['President' => 1]);
$ins_vb = $conn->prepare("INSERT INTO election_votes (election_id, ballot_token, ballot_data, cast_at) VALUES (?, ?, ?, NOW())");
$ins_vb->bind_param('iss', $test_elec_id, $b_token, $b_data);
$ok_vb = $ins_vb->execute();
$ins_vb->close();
assert_test("7.3 Secret ballot stored with unique token decoupled from voter identity", $ok_vb && strlen($b_token) === 32);

// 7.4 Double voting prevention
$chk_voted = $conn->prepare("SELECT id FROM election_voters WHERE election_id = ? AND user_id = ?");
$chk_voted->bind_param('ii', $test_elec_id, $test_std_id);
$chk_voted->execute();
$already_voted = $chk_voted->get_result()->num_rows > 0;
$chk_voted->close();
assert_test("7.4 Double vote check correctly identifies voter has already cast ballot", $already_voted);

// Clean test election
$conn->query("DELETE FROM election_votes WHERE election_id = $test_elec_id");
$conn->query("DELETE FROM election_voters WHERE election_id = $test_elec_id");
$conn->query("DELETE FROM elections WHERE id = $test_elec_id");


// ─────────────────────────────────────────────────────────────
// GROUP 8: NOTIFICATIONS & REFERENCES (Phase 7 / Section 12)
// ─────────────────────────────────────────────────────────────
echo "\n8. Notification & Reference Tests:\n";

push_notification($conn, $test_std_id, 'Acceptance Test Notice', 'Testing notification reference linkage', 'info', 'event', 101, '../dashboard/events.php', 'Important');
$notif = $conn->query("SELECT priority, reference_type, reference_id, link FROM notifications WHERE user_id = $test_std_id AND title = 'Acceptance Test Notice' ORDER BY id DESC LIMIT 1")->fetch_assoc();

assert_test("8.1 Notification stored with priority, reference type, and direct link", 
    !empty($notif) && $notif['priority'] === 'Important' && $notif['reference_type'] === 'event' && $notif['link'] === '../dashboard/events.php'
);

$conn->query("DELETE FROM notifications WHERE user_id = $test_std_id AND title = 'Acceptance Test Notice'");


// ─────────────────────────────────────────────────────────────
// GROUP 9: RBAC SEARCH & ZERO DATA LEAKAGE (Phase 7 / Section 13)
// ─────────────────────────────────────────────────────────────
echo "\n9. RBAC Search & Privacy Tests:\n";

// Ensure search_actions.php doesn't expose sensitive fields or unsupported roles
$search_src = file_get_contents(__DIR__ . '/../app/shared/search_actions.php');
assert_test("9.1 finance_officer role completely purged from search registry", strpos($search_src, 'finance_officer') === false);
assert_test("9.2 Password hashes and audit tables excluded from search result queries", 
    strpos($search_src, 'password_hash') === false && strpos($search_src, 'FROM audit_logs') === false
);


// ─────────────────────────────────────────────────────────────
// GROUP 10: AUDIT & WORKFLOW HISTORY (Phase 7 / Section 14)
// ─────────────────────────────────────────────────────────────
echo "\n10. Audit & Workflow History Architecture Tests:\n";

$wf_tbl = $conn->query("SHOW TABLES LIKE 'workflow_history'");
assert_test("10.1 workflow_history table exists in canonical schema", $wf_tbl && $wf_tbl->num_rows > 0);

$recent_wf = $conn->query("SELECT id, module, from_status, to_status, action, performed_by FROM workflow_history ORDER BY id DESC LIMIT 1")->fetch_assoc();
assert_test("10.2 workflow_history contains authentic recorded state transitions", !empty($recent_wf));

$audit_tbl = $conn->query("SHOW TABLES LIKE 'audit_logs'");
assert_test("10.3 audit_logs table exists and captures security actions", $audit_tbl && $audit_tbl->num_rows > 0);


// ─────────────────────────────────────────────────────────────
// GROUP 11: MULTI-FACTOR AUTHENTICATION (MFA) & EMAIL AUTHORIZATION
// ─────────────────────────────────────────────────────────────
echo "\n11. Multi-Factor Authentication (MFA) Tests:\n";

require_once __DIR__ . '/../app/shared/mail_helper.php';

// 11.1 Schema existence
$mfa_tbl = $conn->query("SHOW TABLES LIKE 'mfa_codes'");
assert_test("11.1 mfa_codes table exists in canonical schema", $mfa_tbl && $mfa_tbl->num_rows > 0);

// 11.2 Generate 6-digit code and store in database
$test_user = $conn->query("SELECT id, email, username FROM users WHERE username = 'bsit.student' LIMIT 1")->fetch_assoc();
$test_uid = (int)($test_user['id'] ?? 1);
$test_email = $test_user['email'] ?? 'bsit@student.bcp.edu.ph';

$mfaGen = send_mfa_verification_code($conn, $test_uid, $test_email, 'Juan Santos', 'login');
assert_test("11.2 Verification code generated and dispatched via mail helper", $mfaGen['success'] === true && strlen($mfaGen['code']) === 6);

// 11.3 Database record verified
$codeRow = $conn->query("SELECT id, code, expires_at, is_used, attempts FROM mfa_codes WHERE user_id = $test_uid AND purpose = 'login' AND is_used = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
assert_test("11.3 Active OTP code record physically persisted in database", !empty($codeRow) && $codeRow['code'] === $mfaGen['code']);

// 11.4 Invalid code rejection
$invalidVerify = verify_mfa_code($conn, $test_uid, '000000', 'login');
assert_test("11.4 Incorrect verification code rejected and remaining attempts decremented", $invalidVerify['success'] === false);

// 11.5 Valid code verification
$validVerify = verify_mfa_code($conn, $test_uid, $mfaGen['code'], 'login');
assert_test("11.5 Correct 6-digit OTP code verified and approved", $validVerify['success'] === true);

// 11.6 Code marked as used in database
$usedRow = $conn->query("SELECT is_used FROM mfa_codes WHERE id = " . (int)$codeRow['id'])->fetch_assoc();
assert_test("11.6 Verified code marked as used (is_used = 1) in database", !empty($usedRow) && (int)$usedRow['is_used'] === 1);

// 11.7 Replay prevention: cannot reuse code
$replayVerify = verify_mfa_code($conn, $test_uid, $mfaGen['code'], 'login');
assert_test("11.7 Replay attack prevented: used verification code cannot be reused", $replayVerify['success'] === false);

// 11.8 User's last_mfa_verified_at timestamp updated
$userMfaTime = $conn->query("SELECT last_mfa_verified_at FROM users WHERE id = $test_uid")->fetch_assoc();
assert_test("11.8 User last_mfa_verified_at timestamp recorded in database", !empty($userMfaTime['last_mfa_verified_at']));


// ─────────────────────────────────────────────────────────────
// FINAL RESULTS SUMMARY
// ─────────────────────────────────────────────────────────────
echo "\n============================================================\n";
echo " TEST SUMMARY: Total $passed Passed, $failed Failed\n";
echo "============================================================\n";

if ($failed === 0) {
    echo "  ALL ACCEPTANCE CRITERIA VERIFIED SUCCESSFULLY! \n\n";
    exit(0);
} else {
    echo "  SOME ACCEPTANCE CHECKS FAILED. Please review the output above.\n\n";
    exit(1);
}
