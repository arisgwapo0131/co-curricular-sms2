<?php
// ============================================================
//  ROSTER_ACTIONS.PHP — Club Membership AJAX handler
//  Actions: list, list_pending, apply, approve, reject, remove, export_csv
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/security.php';

if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }

// CSRF check on mutating requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function rRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra)); exit;
}

switch ($action) {

    // ── LIST active members ──────────────────────────────────
    case 'list': {
        $club_filter = '';
        $params = [];
        $types  = '';

        if ($user_role === 'club_adviser') {
            $assigned_clubs = get_user_assigned_clubs($conn, $user_id, $user_role);
            $assigned_ids = array_map('intval', array_column($assigned_clubs, 'id'));
            if (!empty($assigned_ids)) {
                $club_filter = 'AND cm.club_id IN (' . implode(',', $assigned_ids) . ')';
            } else {
                $club_filter = 'AND 1=0';
            }
        }

        $sql = "SELECT cm.id, cm.club_id, cm.user_id, cm.role AS member_role,
                       cm.status, cm.joined_at,
                       c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name, u.email
                FROM club_memberships cm
                JOIN clubs c ON c.id = cm.club_id
                JOIN users u ON u.id = cm.user_id
                WHERE cm.status = 'Active' $club_filter
                ORDER BY cm.joined_at DESC";
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        rRespond(true, 'OK', ['members' => $rows]);
    }

    // ── LIST pending applicants ──────────────────────────────
    case 'list_pending': {
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
            rRespond(false, 'Not authorized.');

        $club_filter = '';
        $params = [];
        $types  = '';
        if ($user_role === 'club_adviser') {
            $c_stmt = $conn->prepare("SELECT id FROM clubs WHERE adviser_user_id = ? LIMIT 1");
            $c_stmt->bind_param('i', $user_id);
            $c_stmt->execute();
            $c_res = $c_stmt->get_result()->fetch_assoc();
            $c_stmt->close();
            $club_id = $c_res['id'] ?? 0;
            if (!$club_id) {
                $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND role IN ('Adviser','adviser','Club Adviser') AND status='Active' LIMIT 1");
                $cm->bind_param('i', $user_id);
                $cm->execute();
                $cm->bind_result($club_id);
                $cm->fetch();
                $cm->close();
            }
            if (!empty($club_id)) {
                $club_filter = 'AND cm.club_id = ?';
                $params[] = (int)$club_id;
                $types .= 'i';
            } else {
                $club_filter = 'AND 1=0';
            }
        }

        $sql = "SELECT cm.id, cm.club_id, cm.user_id, cm.joined_at,
                       c.name AS club_name, c.code AS club_code,
                       COALESCE(ca.first_name, u.first_name) AS first_name,
                       COALESCE(ca.last_name, u.last_name) AS last_name,
                       COALESCE(ca.email, u.email) AS email,
                       ca.student_id_no, ca.course, ca.year_level, ca.phone, ca.sex, ca.dob, ca.address, ca.motivation,
                       COALESCE(ca.letter_intent, cm.letter_intent) AS letter_intent,
                       COALESCE(ca.letter_endorsement, cm.letter_endorsement) AS letter_endorsement
                FROM club_memberships cm
                JOIN clubs c ON c.id = cm.club_id
                JOIN users u ON u.id = cm.user_id
                LEFT JOIN club_applications ca ON (ca.club_id = cm.club_id AND ca.user_id = cm.user_id AND ca.status IN ('Pending', 'PENDING_ADVISER', 'PENDING_SSC'))
                WHERE cm.status = 'Pending' $club_filter
                ORDER BY cm.joined_at ASC";
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        rRespond(true, 'OK', ['applicants' => $rows]);
    }

    // ── LIST my memberships (Student) ────────────────────────
    case 'my_memberships': {
        $stmt = $conn->prepare(
            "SELECT cm.id, cm.role AS member_role, cm.status, cm.joined_at,
                    c.name AS club_name, c.code AS club_code, c.category
             FROM club_memberships cm
             JOIN clubs c ON c.id = cm.club_id
             WHERE cm.user_id = ?
             ORDER BY cm.joined_at DESC"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        rRespond(true, 'OK', ['memberships' => $rows]);
    }

    // ── APPLY to join a club (Student) ───────────────────────
    case 'apply': {
        if ($user_role !== 'student') rRespond(false, 'Only students can apply.');
        $club_id = (int)($_POST['club_id'] ?? 0);
        if ($club_id <= 0) {
            $org_acronym = trim($_POST['org_acronym'] ?? '');
            $org_name    = trim($_POST['org_name'] ?? '');
            if (!empty($org_acronym)) {
                $c_find = $conn->prepare("SELECT id FROM clubs WHERE code = ? OR name = ? LIMIT 1");
                $c_find->bind_param('ss', $org_acronym, $org_name);
                $c_find->execute();
                $c_res = $c_find->get_result()->fetch_assoc();
                $c_find->close();
                if ($c_res) {
                    $club_id = (int)$c_res['id'];
                } else {
                    $c_ins = $conn->prepare("INSERT INTO clubs (code, name, status) VALUES (?, ?, 'Active')");
                    $c_ins->bind_param('ss', $org_acronym, $org_name);
                    $c_ins->execute();
                    $club_id = (int)$conn->insert_id;
                    $c_ins->close();
                }
            }
        }
        if ($club_id <= 0) rRespond(false, 'Invalid organization selected.');

        // Check already a member or pending
        $check = $conn->prepare("SELECT status FROM club_memberships WHERE user_id=? AND club_id=?");
        $check->bind_param('ii', $user_id, $club_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if ($existing) {
            if ($existing['status'] === 'Active') rRespond(false, 'You are already a member of this club.');
            if ($existing['status'] === 'Pending') rRespond(false, 'Your application is already pending review.');
        }

        // File uploads for Letter of Intent & Letter of Endorsement
        $upload_dir = __DIR__ . '/../uploads/applications/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $allowed_exts  = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp', 'jfif'];
        $allowed_mimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-word',
            'application/zip',
            'application/x-zip',
            'application/octet-stream',
            'image/jpeg',
            'image/pjpeg',
            'image/png',
            'image/webp'
        ];
        $max_bytes     = 10 * 1024 * 1024; // 10 MB matching UI prompt

        // Pre-check upload errors if files were sent
        if (!empty($_FILES['letter_intent']['error']) && $_FILES['letter_intent']['error'] !== UPLOAD_ERR_OK && $_FILES['letter_intent']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['letter_intent']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['letter_intent']['error'] === UPLOAD_ERR_FORM_SIZE) {
                rRespond(false, 'Letter of Intent exceeds the server upload limit.');
            }
            rRespond(false, 'Failed to upload Letter of Intent. Error code: ' . $_FILES['letter_intent']['error']);
        }
        if (!empty($_FILES['letter_endorsement']['error']) && $_FILES['letter_endorsement']['error'] !== UPLOAD_ERR_OK && $_FILES['letter_endorsement']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['letter_endorsement']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['letter_endorsement']['error'] === UPLOAD_ERR_FORM_SIZE) {
                rRespond(false, 'Letter of Endorsement exceeds the server upload limit.');
            }
            rRespond(false, 'Failed to upload Letter of Endorsement. Error code: ' . $_FILES['letter_endorsement']['error']);
        }

        $letter_intent_path = null;
        if (!empty($_FILES['letter_intent']['name']) && $_FILES['letter_intent']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['letter_intent']['size'] > $max_bytes) {
                rRespond(false, 'Letter of Intent exceeds the 10MB size limit.');
            }
            $ext = strtolower(trim(pathinfo($_FILES['letter_intent']['name'], PATHINFO_EXTENSION)));
            if (!in_array($ext, $allowed_exts, true)) {
                rRespond(false, 'Invalid file type for Letter of Intent. Allowed formats: PDF, DOC, DOCX, JPG, PNG, WEBP.');
            }
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['letter_intent']['tmp_name']);
                finfo_close($finfo);
            } elseif (function_exists('mime_content_type')) {
                $mime = mime_content_type($_FILES['letter_intent']['tmp_name']);
            }
            if (!empty($mime) && !in_array($mime, $allowed_mimes, true)) {
                rRespond(false, 'Invalid file content for Letter of Intent. Only genuine PDF, Word documents, and images are accepted.');
            }

            $fname = 'intent_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['letter_intent']['tmp_name'], $upload_dir . $fname)) {
                $letter_intent_path = $fname;
            }
        }

        $letter_endorsement_path = null;
        if (!empty($_FILES['letter_endorsement']['name']) && $_FILES['letter_endorsement']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['letter_endorsement']['size'] > $max_bytes) {
                rRespond(false, 'Letter of Endorsement exceeds the 10MB size limit.');
            }
            $ext = strtolower(trim(pathinfo($_FILES['letter_endorsement']['name'], PATHINFO_EXTENSION)));
            if (!in_array($ext, $allowed_exts, true)) {
                rRespond(false, 'Invalid file type for Letter of Endorsement. Allowed formats: PDF, DOC, DOCX, JPG, PNG, WEBP.');
            }
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['letter_endorsement']['tmp_name']);
                finfo_close($finfo);
            } elseif (function_exists('mime_content_type')) {
                $mime = mime_content_type($_FILES['letter_endorsement']['tmp_name']);
            }
            if (!empty($mime) && !in_array($mime, $allowed_mimes, true)) {
                rRespond(false, 'Invalid file content for Letter of Endorsement. Only genuine PDF, Word documents, and images are accepted.');
            }

            $fname = 'endorsement_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['letter_endorsement']['tmp_name'], $upload_dir . $fname)) {
                $letter_endorsement_path = $fname;
            }
        }

        // Extract form fields & auto-populate from database
        $first_name    = trim($_POST['first_name'] ?? $_SESSION['first_name'] ?? '');
        $last_name     = trim($_POST['last_name']  ?? $_SESSION['last_name']  ?? '');
        $student_id_no = trim($_POST['student_id_no'] ?? '');
        $course        = trim($_POST['course'] ?? '');
        $year_level    = trim($_POST['year_level'] ?? '');
        $email         = trim($_POST['email'] ?? $_SESSION['email'] ?? '');
        $phone         = trim($_POST['contact'] ?? $_POST['phone'] ?? '');
        $sex           = trim($_POST['sex'] ?? '');
        $dob           = !empty($_POST['dob']) ? date('Y-m-d', strtotime($_POST['dob'])) : null;
        $address       = trim($_POST['address'] ?? '');
        $skills        = trim($_POST['skills'] ?? '');
        $motivation    = trim($_POST['motivation'] ?? '');

        if (!empty($skills)) {
            $motivation = "Skills: " . $skills . ($motivation !== '' ? "\n\nMotivation: " . $motivation : '');
        }

        // Auto-populate student info from users & students tables if not provided
        if (empty($student_id_no) || empty($course) || empty($year_level) || empty($phone) || empty($dob) || empty($email) || empty($first_name) || empty($last_name)) {
            $st_stmt = $conn->prepare("
                SELECT s.student_number, s.course, s.year_level, s.phone, s.birthday, u.email, u.first_name, u.last_name 
                FROM users u 
                LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name)) 
                WHERE u.id = ? LIMIT 1
            ");
            if ($st_stmt) {
                $st_stmt->bind_param('i', $user_id);
                $st_stmt->execute();
                $st_res = $st_stmt->get_result();
                if ($st_res && $row = $st_res->fetch_assoc()) {
                    if (empty($first_name))    $first_name    = $row['first_name'] ?? '';
                    if (empty($last_name))     $last_name     = $row['last_name'] ?? '';
                    if (empty($student_id_no)) $student_id_no = $row['student_number'] ?? '';
                    if (empty($course))        $course        = $row['course'] ?? '';
                    if (empty($year_level))    $year_level    = $row['year_level'] ?? '';
                    if (empty($phone))         $phone         = $row['phone'] ?? '';
                    if (empty($email))         $email         = $row['email'] ?? '';
                    if (empty($dob) && !empty($row['birthday'])) $dob = $row['birthday'];
                }
                $st_stmt->close();
            }
        }

        if (empty($student_id_no)) {
            $student_id_no = '2024-' . (10000 + $user_id);
        }
        if (empty($course)) {
            $course = 'BSIT';
        }
        if (empty($year_level)) {
            $year_level = '1st Year';
        }

        // 1. Insert into dedicated club_applications table
        $app_stmt = $conn->prepare("
            INSERT INTO club_applications
            (club_id, user_id, first_name, last_name, student_id_no, course, year_level, email, phone, sex, dob, address, motivation, letter_intent, letter_endorsement, status, adviser_status, ssc_status, adviser_review, ssc_review, applied_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Pending', 'Pending', 'Pending Adviser', 'Pending SSC', NOW())
        ");
        $app_stmt->bind_param(
            'iisssssssssssss',
            $club_id, $user_id, $first_name, $last_name, $student_id_no, $course, $year_level,
            $email, $phone, $sex, $dob, $address, $motivation, $letter_intent_path, $letter_endorsement_path
        );
        if (!$app_stmt->execute()) {
            rRespond(false, 'Failed to save application: ' . $app_stmt->error);
        }
        $app_id = $conn->insert_id;
        $app_stmt->close();

        // 2. Insert/update club_memberships
        $stmt = $conn->prepare(
            "INSERT INTO club_memberships (club_id, user_id, role, status, letter_intent, letter_endorsement, adviser_review, ssc_review, joined_at) 
             VALUES (?, ?, 'Member', 'Pending', ?, ?, 'Pending Adviser', 'Pending SSC', NOW())
             ON DUPLICATE KEY UPDATE 
                status = 'Pending',
                adviser_review = 'Pending Adviser',
                ssc_review = 'Pending SSC',
                letter_intent = VALUES(letter_intent),
                letter_endorsement = VALUES(letter_endorsement),
                review_notes = NULL,
                joined_at = NOW()"
        );
        $stmt->bind_param('iiss', $club_id, $user_id, $letter_intent_path, $letter_endorsement_path);
        if (!$stmt->execute()) {
            rRespond(false, 'Failed to apply: ' . $stmt->error);
        }
        $stmt->close();

        // Ensure we retrieve the membership ID
        $mem_res = $conn->query("SELECT id FROM club_memberships WHERE club_id = $club_id AND user_id = $user_id LIMIT 1");
        $membership_id = ($mem_res && $mrow = $mem_res->fetch_assoc()) ? (int)$mrow['id'] : 0;

        // 3. Log workflow history
        log_workflow_history($conn, 'club_applications', $app_id, 'DRAFT', 'PENDING_ADVISER', 'apply', $user_id, 'Student application submitted with Letter of Intent');

        // 4. Audit log
        log_audit($conn, $user_id, 'club_apply', 'club_memberships', $membership_id, "Applied to club $club_id");

        // Get club name
        $club_res = $conn->query("SELECT name FROM clubs WHERE id = $club_id LIMIT 1");
        $club = $club_res ? $club_res->fetch_assoc() : null;
        $club_name = $club ? $club['name'] : 'the club';

        // Notify advisers of that club
        $advisers = $conn->query("SELECT cm.user_id FROM club_memberships cm JOIN users u ON u.id=cm.user_id WHERE cm.club_id=$club_id AND cm.status='Active' AND u.role='club_adviser'");
        $applicant_full = trim($first_name . ' ' . $last_name) ?: 'A student';
        if ($advisers) {
            while ($o = $advisers->fetch_assoc()) {
                push_notification(
                    $conn, 
                    (int)$o['user_id'], 
                    'New Club Applicant',
                    "$applicant_full applied to join $club_name with a Letter of Intent. Please review and endorse.", 
                    'info', 
                    'club_membership', 
                    $membership_id, 
                    '../dashboard/roster.php'
                );
            }
        }
        rRespond(true, "Application submitted to $club_name. Awaiting adviser review and endorsement.");
    }

    // ── ENDORSE applicant (Club Adviser & Admin) ──────────────
    case 'endorse': {
        if (!in_array($user_role, ['club_adviser', 'admin']))
            rRespond(false, 'Not authorized. Membership endorsement is conducted exclusively by the Club Adviser.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) rRespond(false, 'Invalid membership ID.');

        // Scope check: If adviser, verify applicant belongs to adviser's club
        $chk = $conn->prepare("SELECT cm.id, cm.club_id, cm.user_id, cm.status, cm.adviser_review, c.name AS club_name, u.first_name, u.last_name FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id JOIN users u ON u.id = cm.user_id WHERE cm.id = ?");
        $chk->bind_param('i', $id);
        $chk->execute();
        $cm = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$cm) rRespond(false, 'Membership record not found.');

        if ($user_role === 'club_adviser') {
            if (!verify_club_adviser_scope($conn, (int)$cm['club_id'], $user_id)) {
                rRespond(false, 'Unauthorized: You can only endorse applicants for your assigned organization.');
            }
            if ($cm['adviser_review'] === 'Endorsed') {
                rRespond(false, 'This application has already been endorsed and is awaiting SSC approval.');
            }
        }

        $endorsement_notes = trim($_POST['review_notes'] ?? $_POST['notes'] ?? 'Application endorsed with official Letter of Endorsement by Adviser.');

        // Handle Letter of Endorsement file upload from Adviser
        $letter_endorsement_path = null;
        $max_bytes = 10 * 1024 * 1024; // 10MB
        $allowed_exts = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'];
        $allowed_mimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/png',
            'image/webp'
        ];
        $upload_dir = __DIR__ . '/../uploads/applications/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }

        if (!empty($_FILES['letter_endorsement']['name']) && $_FILES['letter_endorsement']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['letter_endorsement']['size'] > $max_bytes) {
                rRespond(false, 'Letter of Endorsement exceeds the 10MB size limit.');
            }
            $ext = strtolower(trim(pathinfo($_FILES['letter_endorsement']['name'], PATHINFO_EXTENSION)));
            if (!in_array($ext, $allowed_exts, true)) {
                rRespond(false, 'Invalid file type for Letter of Endorsement. Allowed formats: PDF, DOC, DOCX, JPG, PNG, WEBP.');
            }
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['letter_endorsement']['tmp_name']);
                finfo_close($finfo);
            } elseif (function_exists('mime_content_type')) {
                $mime = mime_content_type($_FILES['letter_endorsement']['tmp_name']);
            }
            if (!empty($mime) && !in_array($mime, $allowed_mimes, true)) {
                rRespond(false, 'Invalid file content for Letter of Endorsement. Only genuine PDF, Word documents, and images are accepted.');
            }

            $fname = 'endorsement_' . $cm['user_id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['letter_endorsement']['tmp_name'], $upload_dir . $fname)) {
                $letter_endorsement_path = $fname;
            }
        }

        // Update club_memberships
        if ($letter_endorsement_path) {
            $stmt = $conn->prepare("UPDATE club_memberships SET adviser_review='Endorsed', letter_endorsement=?, review_notes=? WHERE id=?");
            $stmt->bind_param('ssi', $letter_endorsement_path, $endorsement_notes, $id);
        } else {
            $stmt = $conn->prepare("UPDATE club_memberships SET adviser_review='Endorsed', review_notes=? WHERE id=?");
            $stmt->bind_param('si', $endorsement_notes, $id);
        }
        if (!$stmt->execute()) rRespond(false, 'Could not process endorsement — please try again.');
        $stmt->close();

        // Sync club_applications
        if ($letter_endorsement_path) {
            $app_up = $conn->prepare("UPDATE club_applications SET status='Pending', adviser_status='Endorsed', adviser_review='Endorsed', adviser_reviewed_at=NOW(), letter_endorsement=?, review_notes=? WHERE club_id=? AND user_id=? AND status IN ('Pending', 'Returned')");
            $app_up->bind_param('ssii', $letter_endorsement_path, $endorsement_notes, $cm['club_id'], $cm['user_id']);
        } else {
            $app_up = $conn->prepare("UPDATE club_applications SET status='Pending', adviser_status='Endorsed', adviser_review='Endorsed', adviser_reviewed_at=NOW(), review_notes=? WHERE club_id=? AND user_id=? AND status IN ('Pending', 'Returned')");
            $app_up->bind_param('sii', $endorsement_notes, $cm['club_id'], $cm['user_id']);
        }
        $app_up->execute();
        $app_up->close();

        log_workflow_history($conn, 'club_applications', $id, $cm['status'], 'PENDING_SSC', 'adviser_endorse', $user_id, 'Application endorsed with Letter of Endorsement by Adviser');

        push_notification($conn, (int)$cm['user_id'], 'Application Endorsed',
            "Your application to join {$cm['club_name']} was officially endorsed by your Adviser and forwarded for SSC final review.", 'info', 'club_application', $id, '../dashboard/roster.php');

        // Notify SSC officers of new applicant ready for review
        $ssc_users = $conn->query("SELECT id FROM users WHERE role = 'ssc' AND status = 'Active'");
        if ($ssc_users) {
            while ($su = $ssc_users->fetch_assoc()) {
                push_notification($conn, (int)$su['id'], 'New Endorsed Applicant',
                    "Adviser endorsed {$cm['first_name']} {$cm['last_name']} for {$cm['club_name']}. Ready for SSC final review.", 'info', 'club_application', $id, '../dashboard/roster.php');
            }
        }

        log_audit($conn, $user_id, 'roster_endorse', 'club_memberships', $id, "Endorsed membership #$id for club {$cm['club_id']}");
        rRespond(true, 'Applicant endorsed successfully with Letter of Endorsement and forwarded to SSC.');
    }

    // ── APPROVE applicant (SSC & Admin) ───────────────────────
    case 'approve': {
        if (!in_array($user_role, ['ssc','admin']))
            rRespond(false, 'Not authorized. Student membership applications are approved exclusively by the SSC.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) rRespond(false, 'Invalid membership ID.');

        $chk = $conn->prepare("SELECT cm.id, cm.club_id, cm.user_id, cm.status, cm.adviser_review, c.name AS club_name FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.id = ?");
        $chk->bind_param('i', $id);
        $chk->execute();
        $cm = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$cm) rRespond(false, 'Membership record not found.');

        $old_status = $cm['status'];
        $stmt = $conn->prepare(
            "UPDATE club_memberships SET status='Active', approved_by=?, ssc_review='Approved' WHERE id=?"
        );
        $stmt->bind_param('ii', $user_id, $id);
        if (!$stmt->execute()) rRespond(false, 'Could not process approval — already updated?');
        $stmt->close();

        // Sync club_applications
        $app_up = $conn->prepare("UPDATE club_applications SET status='Approved', ssc_status='Approved', ssc_review='Approved', reviewed_by=?, reviewed_at=NOW(), ssc_reviewed_at=NOW() WHERE club_id=? AND user_id=? AND status IN ('Pending', 'PENDING_ADVISER', 'PENDING_SSC', 'Returned')");
        $app_up->bind_param('iii', $user_id, $cm['club_id'], $cm['user_id']);
        $app_up->execute();
        $app_up->close();

        log_workflow_history($conn, 'club_applications', $id, $old_status, 'APPROVED', 'ssc_approve', $user_id, 'Application approved by SSC / System Administration');

        push_notification($conn, (int)$cm['user_id'], 'Membership Approved!',
            "Your application to join {$cm['club_name']} has been approved! Welcome aboard!", 'success', 'club_membership', $id, '../dashboard/roster.php');
        
        // Auto-sync dedicated organization database
        require_once __DIR__ . '/org_db_manager.php';
        sync_org_data_to_dedicated_db($conn, (int)$cm['club_id']);

        log_audit($conn, $user_id, 'roster_approve', 'club_memberships', $id, "Approved membership #$id");
        rRespond(true, 'Applicant approved successfully into active membership.');
    }

    // ── REJECT applicant ─────────────────────────────────────
    case 'reject': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin']))
            rRespond(false, 'Not authorized.');
        $id   = (int)($_POST['id']     ?? 0);
        if ($id <= 0) rRespond(false, 'Invalid membership ID.');
        $notes = trim($_POST['notes'] ?? $_POST['reason'] ?? 'Application rejected.');

        $chk = $conn->prepare("SELECT cm.id, cm.club_id, cm.user_id, cm.status, c.name AS club_name FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.id = ?");
        $chk->bind_param('i', $id);
        $chk->execute();
        $cm = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$cm) rRespond(false, 'Membership record not found.');

        // Scope check: If adviser, verify applicant belongs to adviser's club
        if ($user_role === 'club_adviser') {
            if (!verify_club_adviser_scope($conn, (int)$cm['club_id'], $user_id)) {
                rRespond(false, 'Unauthorized: You can only review applicants for your assigned organization.');
            }
        }

        $adv_sql = ($user_role === 'club_adviser') ? ", adviser_review='Rejected'" : "";
        $ssc_sql = in_array($user_role, ['ssc', 'admin']) ? ", ssc_review='Rejected'" : "";

        $stmt = $conn->prepare(
            "UPDATE club_memberships SET status='Rejected', review_notes=? $adv_sql $ssc_sql WHERE id=?"
        );
        $stmt->bind_param('si', $notes, $id);
        if (!$stmt->execute()) rRespond(false, 'Could not reject — already processed?');
        $stmt->close();

        $app_up = $conn->prepare("UPDATE club_applications SET status='Rejected', rejection_reason=?, review_notes=?, reviewed_by=?, reviewed_at=NOW() $adv_sql $ssc_sql WHERE club_id=? AND user_id=? AND status IN ('Pending', 'PENDING_ADVISER', 'PENDING_SSC')");
        $app_up->bind_param('ssiii', $notes, $notes, $user_id, $cm['club_id'], $cm['user_id']);
        $app_up->execute();
        $app_up->close();

        log_workflow_history($conn, 'club_applications', $id, $cm['status'], 'REJECTED', 'reject', $user_id, $notes);

        push_notification($conn, (int)$cm['user_id'], 'Membership Update',
            "Your application to join {$cm['club_name']} was not approved: $notes", 'warning', 'club_application', $id, '../dashboard/roster.php');

        // Auto-sync dedicated organization database
        require_once __DIR__ . '/org_db_manager.php';
        sync_org_data_to_dedicated_db($conn, (int)$cm['club_id']);

        log_audit($conn, $user_id, 'roster_reject', 'club_memberships', $id, "Rejected membership #$id");
        rRespond(true, 'Applicant rejected.');
    }

    // ── RETURN applicant for revision ─────────────────────────
    case 'return': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin']))
            rRespond(false, 'Not authorized.');
        $id   = (int)($_POST['id'] ?? 0);
        if ($id <= 0) rRespond(false, 'Invalid membership ID.');
        $notes = trim($_POST['notes'] ?? $_POST['reason'] ?? 'Application returned for document completion or revision.');

        $chk = $conn->prepare("SELECT cm.id, cm.club_id, cm.user_id, cm.status, c.name AS club_name FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.id = ?");
        $chk->bind_param('i', $id);
        $chk->execute();
        $cm = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$cm) rRespond(false, 'Membership record not found.');

        // Scope check: If adviser, verify applicant belongs to adviser's club
        if ($user_role === 'club_adviser') {
            if (!verify_club_adviser_scope($conn, (int)$cm['club_id'], $user_id)) {
                rRespond(false, 'Unauthorized: You can only return applicants for your assigned organization.');
            }
        }

        $adv_state = ($user_role === 'club_adviser') ? 'Returned' : 'Under Review';
        $ssc_state = in_array($user_role, ['ssc', 'admin']) ? 'Returned' : 'Pending SSC';

        $stmt = $conn->prepare(
            "UPDATE club_memberships SET status='Returned', adviser_review=?, ssc_review=?, review_notes=? WHERE id=?"
        );
        $stmt->bind_param('sssi', $adv_state, $ssc_state, $notes, $id);
        if (!$stmt->execute()) rRespond(false, 'Could not return application — already processed?');
        $stmt->close();

        $app_up = $conn->prepare("UPDATE club_applications SET status='Returned', adviser_review=?, ssc_review=?, review_notes=?, reviewed_by=?, reviewed_at=NOW() WHERE club_id=? AND user_id=? AND (status IN ('Pending', 'PENDING_ADVISER', 'PENDING_SSC', 'Returned'))");
        $app_up->bind_param('sssiii', $adv_state, $ssc_state, $notes, $user_id, $cm['club_id'], $cm['user_id']);
        $app_up->execute();
        $app_up->close();

        log_workflow_history($conn, 'club_applications', $id, $cm['status'], 'RETURNED', 'return_for_revision', $user_id, $notes);

        push_notification($conn, (int)$cm['user_id'], 'Application Returned for Revision',
            "Your application to join {$cm['club_name']} has been returned: $notes", 'warning', 'club_application', $id, '../dashboard/roster.php');

        log_audit($conn, $user_id, 'roster_return', 'club_memberships', $id, "Returned membership #$id: $notes");
        rRespond(true, 'Application returned for revision successfully.');
    }

    // ── REMOVE active member ─────────────────────────────────
    case 'remove':
    case 'remove_member': {
        if (!in_array($user_role, ['club_adviser','admin','ssc'])) rRespond(false, 'Not authorized.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) rRespond(false, 'Invalid membership ID.');

        // Scope check: If adviser, verify member belongs to adviser's club
        if ($user_role === 'club_adviser') {
            $sess_user = $_SESSION['username'] ?? '';
            $sess_last = $_SESSION['last_name'] ?? '';
            $chk_adv = $conn->prepare("
                SELECT cm.id FROM club_memberships cm 
                JOIN clubs c ON c.id = cm.club_id
                WHERE cm.id = ? 
                  AND (c.id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active') 
                   OR c.code = UPPER(SUBSTRING_INDEX(?, '.', 1))
                   OR (? != '' AND c.adviser_name LIKE CONCAT('%', ?, '%')))
                LIMIT 1
            ");
            $chk_adv->bind_param('iisss', $id, $user_id, $sess_user, $sess_last, $sess_last);
            $chk_adv->execute();
            if (!$chk_adv->get_result()->fetch_assoc()) {
                $chk_adv->close();
                rRespond(false, 'Unauthorized: You can only remove members from your assigned organization.');
            }
            $chk_adv->close();
        }

        // Fetch membership details to notify & audit
        $cm_info = $conn->query("
            SELECT cm.user_id, cm.club_id, c.name AS club_name, u.first_name, u.last_name 
            FROM club_memberships cm 
            JOIN clubs c ON c.id=cm.club_id 
            JOIN users u ON u.id=cm.user_id 
            WHERE cm.id=$id
        ")->fetch_assoc();

        $stmt = $conn->prepare("UPDATE club_memberships SET status='Rejected' WHERE id=?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            rRespond(false, 'Failed to remove member: ' . $stmt->error);
        }
        $stmt->close();

        // Also sync club_applications status
        if ($cm_info) {
            $app_up = $conn->prepare("UPDATE club_applications SET status='Rejected', reviewed_by=?, reviewed_at=NOW() WHERE club_id=? AND user_id=? AND status IN ('Pending', 'Approved')");
            $app_up->bind_param('iii', $user_id, $cm_info['club_id'], $cm_info['user_id']);
            $app_up->execute();
            $app_up->close();

            push_notification($conn, (int)$cm_info['user_id'], 'Membership Status Update',
                "You have been removed from {$cm_info['club_name']}.", 'warning');

            // Auto-sync dedicated organization database
            require_once __DIR__ . '/org_db_manager.php';
            sync_org_data_to_dedicated_db($conn, (int)$cm_info['club_id']);
        }

        log_audit($conn, $user_id, 'roster_remove', 'club_memberships', $id, "Removed member #$id from club " . ($cm_info['club_id'] ?? 0));
        rRespond(true, 'Member removed from the organization successfully.');
    }

    // ── EXPORT CSV ───────────────────────────────────────────
    case 'export_csv': {
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
            rRespond(false, 'Not authorized.');

        $club_filter = '';
        $params = [];
        $types  = '';

        if ($user_role === 'club_adviser') {
            $assigned_clubs = get_user_assigned_clubs($conn, $user_id, $user_role);
            $assigned_ids = array_map('intval', array_column($assigned_clubs, 'id'));
            if (!empty($assigned_ids)) {
                $req_code = trim($_GET['club_code'] ?? $_POST['club_code'] ?? '');
                if (!empty($req_code) && strtolower($req_code) !== 'all') {
                    $club_filter = 'AND c.code = ? AND cm.club_id IN (' . implode(',', $assigned_ids) . ')';
                    $params[] = $req_code;
                    $types .= 's';
                } else {
                    $club_filter = 'AND cm.club_id IN (' . implode(',', $assigned_ids) . ')';
                }
            } else {
                $club_filter = 'AND 1=0';
            }
        } elseif (!empty($_GET['club_id']) || !empty($_POST['club_id'])) {
            $req_club_id = (int)($_GET['club_id'] ?? $_POST['club_id']);
            if ($req_club_id > 0) {
                $club_filter = 'AND cm.club_id = ?';
                $params[] = $req_club_id;
                $types .= 'i';
            }
        } elseif (!empty($_GET['club_code']) || !empty($_POST['club_code'])) {
            $req_code = trim($_GET['club_code'] ?? $_POST['club_code']);
            if (!empty($req_code) && strtolower($req_code) !== 'all') {
                $club_filter = 'AND c.code = ?';
                $params[] = $req_code;
                $types .= 's';
            }
        }

        // Return JSON of active members for the organization (excluding advisers), ordered alphabetically by name
        $sql = "SELECT 
                    COALESCE(NULLIF(ca.student_id_no, ''), NULLIF(s.student_number, ''), CONCAT('2024-', LPAD(u.id, 5, '0'))) AS student_number,
                    u.first_name, 
                    u.last_name, 
                    u.email, 
                    COALESCE(NULLIF(ca.year_level, ''), NULLIF(s.year_level, ''), 'N/A') AS year_level,
                    COALESCE(NULLIF(s.section, ''), 'N/A') AS section,
                    c.name AS club_name, 
                    c.code, 
                    cm.role AS member_role, 
                    cm.joined_at
                FROM club_memberships cm
                JOIN users u ON u.id = cm.user_id
                JOIN clubs c ON c.id = cm.club_id
                LEFT JOIN club_applications ca ON (ca.id = (SELECT MAX(id) FROM club_applications WHERE club_id = cm.club_id AND user_id = cm.user_id))
                LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
                WHERE cm.status = 'Active'
                  AND u.role NOT IN ('club_adviser', 'admin')
                  AND LOWER(cm.role) != 'adviser'
                  AND cm.role NOT LIKE '%adviser%'
                  $club_filter
                ORDER BY u.first_name ASC, u.last_name ASC";

        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        log_audit($conn, $user_id, 'roster_export', 'club_memberships', 0, 'Exported roster');
        rRespond(true, 'OK', ['csv_data' => $rows]);
    }

    default:
        rRespond(false, 'Unknown action.');
}
$conn->close();
