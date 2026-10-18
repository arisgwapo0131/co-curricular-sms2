<?php
// ============================================================
//  VOLUNTEER_ACTIONS.PHP  (app/shared/)
//  Backend API for Volunteer Hours application, verification,
//  and certificate generation.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/volunteer_helpers.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized session. Please sign in.']);
    exit;
}

// CSRF check on mutating requests
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
}

$user_id   = (int)$_SESSION['user_id'];
$sess_role = $_SESSION['role'] ?? 'student';
$action    = $_REQUEST['action'] ?? '';

header('Content-Type: application/json');

// ── 1. APPLY TO VOLUNTEER (Students) ─────────────────────────────────
if ($action === 'apply_volunteer') {
    if ($sess_role !== 'student' && $sess_role !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Only enrolled students can apply for volunteer roles.']);
        exit;
    }

    if ($sess_role === 'student' && !is_student_organization_member($conn, $user_id)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Volunteer opportunities are exclusively available to approved members of a student organization. Please wait until your Club Adviser approves your application.'
        ]);
        exit;
    }

    $opp_id = (int)($_POST['opportunity_id'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');

    if ($opp_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid volunteer role opportunity.']);
        exit;
    }

    // 1. Fetch opportunity details with event and club info
    $stmt = $conn->prepare("
        SELECT vo.*, e.title as event_title, e.event_date, e.venue, e.status as event_status,
               c.id as club_id, c.name as club_name, c.code as club_code, c.category as club_category, c.program as club_program, c.sub_category as club_sub_category
        FROM volunteer_opportunities vo
        JOIN events e ON e.id = vo.event_id
        JOIN clubs c ON c.id = vo.club_id
        WHERE vo.id = ? AND vo.status = 'Open'
        LIMIT 1
    ");
    $stmt->bind_param('i', $opp_id);
    $stmt->execute();
    $opp = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$opp) {
        echo json_encode(['success' => false, 'message' => 'Volunteer opportunity not found or is no longer open.']);
        exit;
    }

    // Verify student is an approved member of this specific organization (whose adviser approved the application)
    if ($sess_role === 'student') {
        $m_chk = $conn->prepare("
            SELECT 1 FROM club_memberships 
            WHERE user_id = ? AND club_id = ? AND (status = 'Active' OR adviser_review = 'Endorsed' OR adviser_review = 'Approved')
            UNION
            SELECT 1 FROM club_applications 
            WHERE user_id = ? AND club_id = ? AND (adviser_status = 'Endorsed' OR adviser_review = 'Endorsed' OR status = 'Approved')
            LIMIT 1
        ");
        $m_chk->bind_param('iiii', $user_id, $opp['club_id'], $user_id, $opp['club_id']);
        $m_chk->execute();
        $is_club_member = (bool)$m_chk->get_result()->fetch_row();
        $m_chk->close();

        if (!$is_club_member) {
            echo json_encode([
                'success' => false,
                'message' => "You can only volunteer for opportunities hosted by your approved organization ({$opp['club_name']})."
            ]);
            exit;
        }
    }

    // 2. Fetch student details
    $s_stmt = $conn->prepare("
        SELECT u.first_name, u.last_name, u.email, s.student_number, s.course, s.year_level
        FROM users u
        LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
        WHERE u.id = ?
        LIMIT 1
    ");
    $s_stmt->bind_param('i', $user_id);
    $s_stmt->execute();
    $student = $s_stmt->get_result()->fetch_assoc();
    $s_stmt->close();

    $student_name   = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $student_number = $student['student_number'] ?? 'N/A';
    $student_course = $student['course'] ?? 'General Student';
    $student_year   = $student['year_level'] ?? 'Undergraduate';

    // 3. Check Course & Program Eligibility
    $eligibility = checkVolunteerEligibility(
        $student_course,
        $opp['club_category'],
        $opp['club_program'],
        $opp['club_sub_category'],
        $opp['target_program']
    );

    if (!$eligibility['eligible']) {
        echo json_encode([
            'success' => false,
            'message' => 'Eligibility Restriction: ' . $eligibility['reason']
        ]);
        exit;
    }

    // 4. Check for duplicate pending or verified application for this event
    $chk_stmt = $conn->prepare("
        SELECT id, status, volunteer_role 
        FROM volunteer_logs 
        WHERE user_id = ? AND event_id = ? AND status IN ('Pending Verification', 'Verified')
        LIMIT 1
    ");
    $chk_stmt->bind_param('ii', $user_id, $opp['event_id']);
    $chk_stmt->execute();
    $existing = $chk_stmt->get_result()->fetch_assoc();
    $chk_stmt->close();

    if ($existing) {
        echo json_encode([
            'success' => false,
            'message' => "You already have a {$existing['status']} application for this event ({$existing['volunteer_role']})."
        ]);
        exit;
    }

    // 5. Insert volunteer log record
    $ins = $conn->prepare("
        INSERT INTO volunteer_logs (
            user_id, event_id, club_id, opportunity_id,
            student_name, student_number, course, year_level,
            event_title, volunteer_role, notes, hours, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Verification', NOW())
    ");
    $ins->bind_param(
        'iiiisssssssd',
        $user_id,
        $opp['event_id'],
        $opp['club_id'],
        $opp_id,
        $student_name,
        $student_number,
        $student_course,
        $student_year,
        $opp['event_title'],
        $opp['role_name'],
        $notes,
        $opp['hours']
    );

    if ($ins->execute()) {
        $log_id = $ins->insert_id;
        $ins->close();

        // 6. Notify Adviser of that organization
        $adv_stmt = $conn->prepare("
            SELECT COALESCE(c.adviser_user_id, cm.user_id) as adviser_id
            FROM clubs c
            LEFT JOIN club_memberships cm ON cm.club_id = c.id AND (cm.role = 'Adviser' OR cm.status = 'Active')
            WHERE c.id = ? AND (c.adviser_user_id IS NOT NULL OR cm.user_id IS NOT NULL)
            LIMIT 1
        ");
        $adv_stmt->bind_param('i', $opp['club_id']);
        $adv_stmt->execute();
        $adv_res = $adv_stmt->get_result()->fetch_assoc();
        $adv_stmt->close();

        if ($adv_res && !empty($adv_res['adviser_id'])) {
            $adviser_user_id = (int)$adv_res['adviser_id'];
            if (function_exists('push_notification')) {
                push_notification(
                    $conn,
                    $adviser_user_id,
                    "New Volunteer Request: {$student_name}",
                    "{$student_name} ({$student_course}) has volunteered as '{$opp['role_name']}' for {$opp['event_title']}. Pending your adviser verification.",
                    'volunteer'
                );
            }
        }

        // 7. Audit log
        if (function_exists('log_audit')) {
            log_audit($conn, $user_id, 'apply_volunteer', 'volunteer_logs', $log_id, "Applied as {$opp['role_name']} for {$opp['event_title']} ({$opp['hours']} hrs)");
        }

        echo json_encode([
            'success' => true,
            'message' => "Volunteer application submitted! The {$opp['club_name']} adviser will review and verify your role.",
            'log_id'  => $log_id,
            'hours'   => $opp['hours']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

// ── 2. VERIFY VOLUNTEER ROLE & HOURS (Club Adviser, SSC, Admin) ───────
if ($action === 'verify_volunteer') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only organization advisers and administrators can verify volunteer hours.']);
        exit;
    }

    $log_id   = (int)($_POST['log_id'] ?? 0);
    $decision = trim($_POST['decision'] ?? 'Verified'); // 'Verified' or 'Rejected'
    $notes    = trim($_POST['verification_notes'] ?? '');
    $hours_in = isset($_POST['hours']) ? (float)$_POST['hours'] : null;

    if (!in_array($decision, ['Verified', 'Rejected'])) {
        $decision = 'Verified';
    }

    if ($log_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid volunteer record ID.']);
        exit;
    }

    // 1. Fetch current log record
    $l_stmt = $conn->prepare("
        SELECT vl.*, c.name as club_name, c.code as club_code
        FROM volunteer_logs vl
        LEFT JOIN clubs c ON c.id = vl.club_id
        WHERE vl.id = ?
        LIMIT 1
    ");
    $l_stmt->bind_param('i', $log_id);
    $l_stmt->execute();
    $log = $l_stmt->get_result()->fetch_assoc();
    $l_stmt->close();

    if (!$log) {
        echo json_encode(['success' => false, 'message' => 'Volunteer record not found.']);
        exit;
    }

    // 2. Strict Role & Multi-Tenant Club Adviser Validation
    if ($sess_role === 'club_adviser') {
        $adv_chk = $conn->prepare("
            SELECT id FROM club_memberships 
            WHERE user_id = ? AND club_id = ? AND status = 'Active' 
            LIMIT 1
        ");
        $adv_chk->bind_param('ii', $user_id, $log['club_id']);
        $adv_chk->execute();
        $is_adviser = $adv_chk->get_result()->fetch_assoc();
        $adv_chk->close();

        if (!$is_adviser) {
            echo json_encode(['success' => false, 'message' => 'Security Error: You are only authorized to verify volunteer hours for your assigned organization.']);
            exit;
        }
    }

    $hours_credited = ($hours_in !== null && $hours_in >= 0) ? $hours_in : (float)$log['hours'];
    if ($decision === 'Rejected') {
        $hours_credited = 0.00;
    }

    // 3. Update volunteer log record
    $upd = $conn->prepare("
        UPDATE volunteer_logs 
        SET status = ?, hours = ?, verified_by = ?, verified_at = NOW(), verification_notes = ?
        WHERE id = ?
    ");
    $upd->bind_param('sdisi', $decision, $hours_credited, $user_id, $notes, $log_id);

    if ($upd->execute()) {
        $upd->close();

        // 4. Notify student
        $student_uid = (int)$log['user_id'];
        if (function_exists('push_notification')) {
            if ($decision === 'Verified') {
                push_notification(
                    $conn,
                    $student_uid,
                    "Volunteer Hours Verified! (+{$hours_credited} hrs)",
                    "Your role as '{$log['volunteer_role']}' for {$log['event_title']} has been officially verified by your adviser. {$hours_credited} volunteer hours have been credited!",
                    'volunteer'
                );
            } else {
                push_notification(
                    $conn,
                    $student_uid,
                    "Volunteer Request Update",
                    "Your volunteer application for {$log['event_title']} was not approved: " . ($notes ?: 'Please consult your organization adviser.'),
                    'volunteer'
                );
            }
        }

        // 5. Audit log
        if (function_exists('log_audit')) {
            log_audit($conn, $user_id, 'verify_volunteer', 'volunteer_logs', $log_id, "{$decision} volunteer record for {$log['student_name']} ({$hours_credited} hrs)");
        }

        echo json_encode([
            'success'        => true,
            'message'        => $decision === 'Verified' ? "Volunteer role successfully verified! {$hours_credited} hours have been credited." : "Volunteer application marked as rejected.",
            'decision'       => $decision,
            'hours_credited' => $hours_credited
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

// ── 3. GET OFFICIAL VOLUNTEER RECEIPT / CERTIFICATE PAYLOAD ───────────
if ($action === 'get_receipt') {
    $log_id = (int)($_REQUEST['log_id'] ?? 0);

    if ($log_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid volunteer record ID.']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT vl.*, 
               e.venue, e.event_date,
               c.name as club_name, c.code as club_code, c.category as club_category,
               u_adv.first_name as adv_first, u_adv.last_name as adv_last
        FROM volunteer_logs vl
        LEFT JOIN events e ON e.id = vl.event_id
        LEFT JOIN clubs c ON c.id = vl.club_id
        LEFT JOIN users u_adv ON u_adv.id = vl.verified_by
        WHERE vl.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $log_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Volunteer record not found.']);
        exit;
    }

    // Ensure student only accesses their own receipt unless adviser/admin/ssc
    if ($sess_role === 'student' && (int)$row['user_id'] !== $user_id) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $ref_no = sprintf("BCP-VOL-%d-%s-%05d", date('Y', strtotime($row['created_at'])), $row['club_code'] ?: 'GEN', $row['id']);
    $hash = hash('sha256', $ref_no . '|' . $row['user_id'] . '|' . $row['event_title'] . '|' . $row['hours'] . '|' . ($row['verified_at'] ?? ''));

    echo json_encode([
        'success' => true,
        'receipt' => [
            'reference_no'        => $ref_no,
            'log_id'              => (int)$row['id'],
            'student_name'        => $row['student_name'],
            'student_number'      => $row['student_number'] ?: 'N/A',
            'course'              => $row['course'] ?: 'General Program',
            'year_level'          => $row['year_level'] ?: '',
            'event_title'         => $row['event_title'],
            'volunteer_role'      => $row['volunteer_role'],
            'hours'               => number_format((float)$row['hours'], 1),
            'org_name'            => $row['club_name'] ?: 'Bestlink College of the Philippines',
            'org_code'            => $row['club_code'] ?: 'BCP',
            'venue'               => $row['venue'] ?: 'BCP Campus',
            'event_date'          => $row['event_date'] ? date('F d, Y, g:i A', strtotime($row['event_date'])) : 'N/A',
            'applied_at'          => date('F d, Y, g:i A', strtotime($row['created_at'])),
            'verified_at'         => $row['verified_at'] ? date('F d, Y, g:i A', strtotime($row['verified_at'])) : 'Pending',
            'verified_by_name'    => trim(($row['adv_first'] ?? '') . ' ' . ($row['adv_last'] ?? '')) ?: 'Faculty Club Adviser',
            'status'              => $row['status'],
            'verification_notes'  => $row['verification_notes'] ?: 'Officially confirmed and credited co-curricular volunteer service hours.',
            'audit_hash'          => $hash
        ]
    ]);
    exit;
}

// ── 4. CREATE VOLUNTEER OPPORTUNITY (Adviser, SSC, Admin) ─────────────
if ($action === 'create_opportunity') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $event_id       = (int)($_POST['event_id'] ?? 0);
    $club_id        = (int)($_POST['club_id'] ?? 0);
    $title          = trim($_POST['title'] ?? '');
    $role_name      = trim($_POST['role_name'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $hours          = (float)($_POST['hours'] ?? 4.0);
    $slots          = (int)($_POST['slots'] ?? 10);
    $target_program = trim($_POST['target_program'] ?? 'All');

    if ($event_id <= 0 || empty($title) || empty($role_name)) {
        echo json_encode(['success' => false, 'message' => 'Please provide event, title, and role name.']);
        exit;
    }

    // If adviser, lock club_id to adviser's club
    if ($sess_role === 'club_adviser') {
        $adv_stmt = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active' LIMIT 1");
        $adv_stmt->bind_param('i', $user_id);
        $adv_stmt->execute();
        $adv_res = $adv_stmt->get_result()->fetch_assoc();
        $adv_stmt->close();
        if ($adv_res) {
            $club_id = (int)$adv_res['club_id'];
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO volunteer_opportunities (event_id, club_id, title, role_name, description, hours, slots, target_program, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Open')
    ");
    $stmt->bind_param('iisssdis', $event_id, $club_id, $title, $role_name, $description, $hours, $slots, $target_program);

    if ($stmt->execute()) {
        $opp_id = $stmt->insert_id;
        $stmt->close();
        if (function_exists('log_audit')) {
            log_audit($conn, $user_id, 'create_opportunity', 'volunteer_opportunities', $opp_id, "Created volunteer opportunity '$role_name' for event $event_id");
        }
        echo json_encode(['success' => true, 'message' => 'Volunteer opportunity created successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
