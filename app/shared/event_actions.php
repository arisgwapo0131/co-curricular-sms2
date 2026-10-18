<?php
// ============================================================
//  EVENT_ACTIONS.PHP — Events AJAX handler
//  Workflow:
//    Club Adviser Proposal -> Pending SSC
//    SSC Review & Endorsement -> Pending Admin
//    System Admin Clearance -> Approved (Posted to Calendar)
//    SSC Institutional Event -> Pending Admin -> Approved
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/ph_holidays.php';
require_once __DIR__ . '/security.php';

if (empty($_SESSION['user_id'])) { 
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']); 
    exit; 
}

// CSRF check on mutating requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

// Ensure event proposal documentary columns exist
if ($conn) {
    $ev_doc_cols = [
        'requires_budget'      => "TINYINT(1) NOT NULL DEFAULT 1 AFTER expected_attendees",
        'doc_letter'           => "VARCHAR(255) DEFAULT NULL AFTER attachment",
        'doc_board_resolution' => "VARCHAR(255) DEFAULT NULL AFTER doc_letter",
        'doc_liquidation'      => "VARCHAR(255) DEFAULT NULL AFTER doc_board_resolution",
        'doc_budget_proposal'  => "VARCHAR(255) DEFAULT NULL AFTER doc_liquidation"
    ];
    foreach ($ev_doc_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `events` LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `events` ADD COLUMN `$col` $def");
        }
    }
}

function eRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra)); 
    exit;
}

function upload_event_doc_file(string $input_name, string $prefix): ?string {
    if (empty($_FILES[$input_name]) || $_FILES[$input_name]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $allowed_ext = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'xls', 'xlsx', 'html'];
    $file_info = pathinfo($_FILES[$input_name]['name']);
    $ext = strtolower($file_info['extension'] ?? '');
    if (!in_array($ext, $allowed_ext, true)) {
        eRespond(false, "Invalid file format for {$prefix}. Allowed formats: PDF, Word (DOC/DOCX), Excel (XLS/XLSX), or Image (PNG/JPG).");
    }
    $up_dir = __DIR__ . '/../uploads/events/';
    if (!is_dir($up_dir)) {
        @mkdir($up_dir, 0777, true);
    }
    $safe_base = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $file_info['filename'] ?? 'document');
    $clean_fn = $prefix . '_' . time() . '_' . substr(md5($safe_base . uniqid('', true)), 0, 6) . '_' . $safe_base . '.' . $ext;
    if (move_uploaded_file($_FILES[$input_name]['tmp_name'], $up_dir . $clean_fn)) {
        return $clean_fn;
    }
    return null;
}

switch ($action) {

    // ── CHECK CONFLICT (Holidays, Exam Blackout, Venue Collision) ──
    case 'check_conflict': {
        $event_date = trim($_POST['event_date'] ?? $_GET['event_date'] ?? '');
        $venue      = trim($_POST['venue']      ?? $_GET['venue']      ?? '');
        $exclude_id = (int)($_POST['event_id']  ?? $_GET['event_id']  ?? 0);
        $club_id    = (int)($_POST['club_id']   ?? $_GET['club_id']   ?? 0);
        $start_time = trim($_POST['start_time'] ?? $_GET['start_time'] ?? '');
        $end_time   = trim($_POST['end_time']   ?? $_GET['end_time']   ?? '');

        if (!$event_date) eRespond(false, 'Date is required.');
        $result = detect_event_schedule_conflict($conn, $event_date, $venue, $exclude_id, $club_id, $start_time, $end_time);
        eRespond(true, 'Conflict audit completed.', ['analysis' => $result]);
    }

    // ── GET HOLIDAYS CATALOG ──────────────────────────────────────────
    case 'get_holidays': {
        $year = (int)($_POST['year'] ?? $_GET['year'] ?? date('Y'));
        if ($year < 2020 || $year > 2035) $year = (int)date('Y');
        $holidays = get_ph_holidays($year);
        eRespond(true, 'OK', ['holidays' => $holidays]);
    }

    // ── LIST events ──────────────────────────────────────────
    case 'list': {
        $sql = "SELECT e.id, e.club_id, e.event_type, e.title, e.description, e.event_date, e.start_time, e.end_time, e.venue,
                       e.target_course, e.target_org, e.expected_attendees, e.requires_budget, e.budget_amount,
                       e.attachment, e.doc_letter, e.doc_board_resolution, e.doc_liquidation, e.doc_budget_proposal,
                       e.status, e.endorsement_notes, e.rejection_note, e.created_by, e.created_at,
                       COALESCE(c.name, 'BCP Institutional / Campus-Wide') AS club_name,
                       COALESCE(c.code, 'INSTITUTIONAL') AS club_code,
                       u.first_name, u.last_name, u.role AS creator_role
                FROM events e
                LEFT JOIN clubs c ON c.id = e.club_id
                LEFT JOIN users u ON u.id = e.created_by
                WHERE e.deleted_at IS NULL
                ORDER BY e.event_date ASC";
        $result = $conn->query($sql);
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        eRespond(true, 'OK', ['events' => $rows]);
    }

    // ── CREATE event proposal (Adviser / SSC / Staff / Admin / Super Admin) ─────────
    case 'create': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'])) {
            eRespond(false, 'Only Club Advisers, SSC Officers, and Administrators can create events.');
        }

        $title              = trim($_POST['title'] ?? '');
        $description        = trim($_POST['description'] ?? '');
        $event_date_raw     = trim($_POST['event_date'] ?? '');
        $start_time_raw     = trim($_POST['start_time'] ?? '');
        $end_time_raw       = trim($_POST['end_time'] ?? '');
        $venue              = trim($_POST['venue'] ?? '');
        $target_course      = trim($_POST['target_course'] ?? 'All Courses');
        $target_org         = trim($_POST['target_org'] ?? 'All Organizations');
        $event_type         = trim($_POST['event_type'] ?? 'Club');
        $raw_club_id        = (int)($_POST['club_id'] ?? 0);
        $expected_attendees = (int)($_POST['expected_attendees'] ?? 0);
        $requires_budget    = isset($_POST['requires_budget']) ? (int)$_POST['requires_budget'] : 1;
        $budget_amount      = $requires_budget ? (float)($_POST['budget_amount'] ?? 0.00) : 0.00;

        if (!$title || !$event_date_raw || !$venue) {
            eRespond(false, 'Event title, date, and venue are required.');
        }

        // Process Date & Times
        $dt_part = substr($event_date_raw, 0, 10);
        $start_time = !empty($start_time_raw) ? date('H:i:s', strtotime($start_time_raw)) : (strpos($event_date_raw, 'T') !== false ? date('H:i:s', strtotime($event_date_raw)) : '08:00:00');
        $end_time = !empty($end_time_raw) ? date('H:i:s', strtotime($end_time_raw)) : date('H:i:s', strtotime($start_time . ' + 3 hours'));
        $event_date = $dt_part . ' ' . $start_time;

        // Handle the 4 Event Proposal Documentary Requirements:
        // 1. Formal Letter (doc_letter)
        // 2. Board Resolution (doc_board_resolution)
        // 3. Liquidation (doc_liquidation)
        // 4. Budget Proposal (doc_budget_proposal - optional depending on the event)
        $doc_letter           = upload_event_doc_file('doc_letter', 'letter');
        $doc_board_resolution = upload_event_doc_file('doc_board_resolution', 'board_res');
        $doc_liquidation      = upload_event_doc_file('doc_liquidation', 'liquidation');
        $doc_budget_proposal  = $requires_budget ? upload_event_doc_file('doc_budget_proposal', 'budget_prop') : null;
        $legacy_attachment    = upload_event_doc_file('attachment', 'event');

        if (!$doc_letter && $legacy_attachment) {
            $doc_letter = $legacy_attachment;
        }
        $attachment_path = $doc_letter ?: ($doc_board_resolution ?: ($doc_budget_proposal ?: ($doc_liquidation ?: $legacy_attachment)));

        // Institutional events created by SSC/Admin have NULL club_id
        $club_id = null;
        if ($event_type === 'Club') {
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
                if (!$club_id) {
                    eRespond(false, 'No assigned organization found for your adviser account.');
                }
                if (!verify_club_adviser_scope($conn, (int)$club_id, $user_id)) {
                    eRespond(false, 'Unauthorized: You are not assigned as the adviser for this organization.');
                }
            } else {
                $club_id = $raw_club_id > 0 ? $raw_club_id : 1;
            }
        } else {
            $event_type = 'Institutional';
            $club_id = null;
        }

        // Determine initial status based on role
        // Club Adviser -> Pending SSC
        // SSC Officer  -> Pending Admin
        // Admin        -> Approved
        $initial_status = 'Pending SSC';
        if (in_array($user_role, ['ssc', 'staff'])) {
            $initial_status = 'Pending Admin';
        } elseif (in_array($user_role, ['admin', 'super_admin'])) {
            $initial_status = 'Approved';
        }

        // Run Pre-Flight Conflict Check with time & venue overlap detection
        $conflictAnalysis = detect_event_schedule_conflict($conn, $event_date, $venue, 0, $club_id ?: 0, $start_time, $end_time);

        // If venue & time collision is detected, block submission with warning
        foreach ($conflictAnalysis['conflicts'] as $c) {
            if ($c['category'] === 'venue_time_collision') {
                eRespond(false, strip_tags($c['message']), ['conflict_analysis' => $conflictAnalysis]);
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO events (club_id, event_type, title, description, event_date, start_time, end_time, venue, target_course, target_org, expected_attendees, requires_budget, budget_amount, attachment, doc_letter, doc_board_resolution, doc_liquidation, doc_budget_proposal, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'isssssssssiidssssssi',
            $club_id, $event_type, $title, $description, $event_date, $start_time, $end_time, $venue,
            $target_course, $target_org, $expected_attendees, $requires_budget, $budget_amount,
            $attachment_path, $doc_letter, $doc_board_resolution, $doc_liquidation, $doc_budget_proposal,
            $initial_status, $user_id
        );
        if (!$stmt->execute()) {
            eRespond(false, 'Failed to create event: ' . $stmt->error);
        }
        $new_id = $conn->insert_id;
        $stmt->close();

        log_workflow_history($conn, 'events', $new_id, 'DRAFT', $initial_status, 'event_create', $user_id, "Created $event_type event proposal");

        $holidayNotice = $conflictAnalysis['has_conflict'] ? " [Flagged: {$conflictAnalysis['summary']}]" : "";

        // Notify appropriate role
        if ($initial_status === 'Pending Admin') {
            $admins = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'super_admin')");
            while ($a = $admins->fetch_assoc()) {
                push_notification($conn, (int)$a['id'], 'Event Proposal Awaiting Approval',
                    ($user_role === 'club_adviser' ? 'Club Adviser proposal ' : ($event_type === 'Institutional' ? 'Institutional Event ' : 'Event ')) . "\"$title\" submitted for Admin approval.{$holidayNotice}", 'event', 'event', $new_id, '../dashboard/events.php');
            }

            // Also send informational notice to SSC
            $sscs = $conn->query("SELECT id FROM users WHERE role IN ('ssc', 'staff')");
            while ($o = $sscs->fetch_assoc()) {
                push_notification($conn, (int)$o['id'], 'New Event Proposal Submitted',
                    "New event \"$title\" on " . date('M d, Y', strtotime($event_date)) . " submitted for Admin approval.{$holidayNotice}", 'event', 'event', $new_id, '../dashboard/events.php');
            }
        } elseif ($initial_status === 'Pending SSC') {
            $sscs = $conn->query("SELECT id FROM users WHERE role IN ('ssc', 'staff')");
            while ($o = $sscs->fetch_assoc()) {
                push_notification($conn, (int)$o['id'], 'New Club Event Proposal',
                    "New event \"$title\" on " . date('M d, Y', strtotime($event_date)) . " submitted for SSC endorsement.{$holidayNotice}", 'event', 'event', $new_id, '../dashboard/events.php');
            }
        }

        log_audit($conn, $user_id, 'event_create', 'events', $new_id, "Created $event_type event \"$title\" (Status: $initial_status)$holidayNotice");

        $statusMsg = ($initial_status === 'Pending Admin') 
            ? 'Event proposal submitted and forwarded to System Admin for approval.' 
            : (($initial_status === 'Pending SSC')
                ? 'Event proposal and documentary requirements submitted for SSC review.'
                : 'Event created and published to campus calendar.');

        eRespond(true, $statusMsg, [
            'id' => $new_id,
            'status' => $initial_status,
            'conflict_analysis' => $conflictAnalysis
        ]);
    }

    // ── SSC ENDORSE / FORWARD TO ADMIN (Stage 2) ───────────────
    case 'ssc_endorse':
    case 'forward_to_admin': {
        if (!in_array($user_role, ['ssc', 'staff', 'admin', 'super_admin'])) {
            eRespond(false, 'Only SSC Officers and Admins can endorse events.');
        }

        $id        = (int)($_POST['id'] ?? 0);
        $notes     = trim($_POST['notes'] ?? $_POST['remarks'] ?? '');
        $checklist = $_POST['checklist'] ?? [];
        if (is_array($checklist)) {
            $checklist_str = implode(', ', array_map('htmlspecialchars', $checklist));
        } else {
            $checklist_str = trim($checklist);
        }

        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        $endorse_str = "Endorsed by SSC" . ($notes ? ": $notes" : " for final Admin calendar clearance.");
        if (!empty($checklist_str)) {
            $endorse_str .= " [Checklist verified: $checklist_str]";
        }

        $stmt = $conn->prepare("UPDATE events SET status = 'Pending Admin', endorsement_notes = ?, rejection_note = NULL WHERE id = ?");
        $stmt->bind_param('si', $endorse_str, $id);
        if (!$stmt->execute()) eRespond(false, 'Failed to endorse event: ' . $stmt->error);
        $stmt->close();

        log_workflow_history($conn, 'events', $id, 'Pending SSC', 'Pending Admin', 'ssc_endorse', $user_id, $endorse_str);

        // Notify Admins
        $ev = $conn->query("SELECT title, created_by FROM events WHERE id = $id")->fetch_assoc();
        $admins = $conn->query("SELECT id FROM users WHERE role = 'admin'");
        while ($a = $admins->fetch_assoc()) {
            push_notification($conn, (int)$a['id'], 'Event Endorsed by SSC',
                "Event \"{$ev['title']}\" was endorsed by SSC and awaits Admin final clearance.", 'event', 'event', $id, '../dashboard/events.php');
        }

        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Endorsed by SSC',
                "Your event proposal \"{$ev['title']}\" was endorsed by SSC and forwarded to Admin for calendar posting.", 'info', 'event', $id, '../dashboard/events.php');
        }

        log_audit($conn, $user_id, 'event_ssc_endorse', 'events', $id, "SSC endorsed event #$id to Pending Admin");
        eRespond(true, 'Event endorsed and forwarded to System Admin for final calendar approval.');
    }

    // ── ADMIN FINAL APPROVE (Stage 3 -> Approved / Calendar) ──
    case 'admin_approve':
    case 'approve': {
        if (!in_array($user_role, ['admin', 'super_admin'])) {
            eRespond(false, 'Only System Administrators can grant final calendar approval.');
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        $ev = $conn->query("SELECT id, title, created_by, event_date, venue, status FROM events WHERE id = $id")->fetch_assoc();
        if (!$ev) eRespond(false, 'Event not found.');

        // Enforce venue double-booking check: prevent conflicting approvals without override
        if (!empty($ev['venue']) && !empty($ev['event_date'])) {
            $conf_chk = $conn->prepare("
                SELECT id, title FROM events 
                WHERE venue = ? 
                  AND DATE(event_date) = DATE(?) 
                  AND status IN ('Approved', 'Upcoming') 
                  AND id != ?
                LIMIT 1
            ");
            $conf_chk->bind_param('ssi', $ev['venue'], $ev['event_date'], $id);
            $conf_chk->execute();
            $collision = $conf_chk->get_result()->fetch_assoc();
            $conf_chk->close();
            if ($collision) {
                eRespond(false, "Scheduling collision: Event \"{$collision['title']}\" (#{$collision['id']}) is already confirmed at \"{$ev['venue']}\" on " . date('M d, Y', strtotime($ev['event_date'])) . ". To grant clearance despite this collision, use Administrative Override with explicit justification.");
            }
        }

        $prior_status = $ev['status'] ?? 'Pending Admin';

        $stmt = $conn->prepare("UPDATE events SET status = 'Approved', rejection_note = NULL WHERE id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) eRespond(false, 'Failed to approve event.');
        $stmt->close();

        log_workflow_history($conn, 'events', $id, $prior_status, 'Approved', 'admin_approve', $user_id, 'Official campus calendar clearance granted');

        $ev = $conn->query("SELECT title, created_by, event_date FROM events WHERE id = $id")->fetch_assoc();
        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Officially Approved! 🎉',
                "Your event \"{$ev['title']}\" is approved and now active on the official BCP calendar!", 'success', 'event', $id, '../dashboard/events.php');
        }

        // Notify SSC
        $sscs = $conn->query("SELECT id FROM users WHERE role IN ('ssc', 'staff')");
        while ($s = $sscs->fetch_assoc()) {
            push_notification($conn, (int)$s['id'], 'Event Approved & Published',
                "Event \"{$ev['title']}\" has received Admin clearance and is published on campus calendar.", 'event', 'event', $id, '../dashboard/events.php');
        }

        log_audit($conn, $user_id, 'event_admin_approve', 'events', $id, "Admin granted final clearance for event #$id");
        eRespond(true, 'Event approved and published to the active campus calendar!');
    }

    // ── ADMIN OVERRIDE (Force Clearance / Conflict Override) ──
    case 'override':
    case 'admin_override': {
        if (!in_array($user_role, ['admin', 'super_admin'])) {
            eRespond(false, 'Only System Administrators have override authorization.');
        }

        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? $_POST['note'] ?? $_POST['remarks'] ?? 'Administrative Override Clearance');
        if ($id <= 0) eRespond(false, 'Invalid event ID.');
        if (!$reason) eRespond(false, 'An administrative override justification is required.');

        $override_note = "Admin Override Clearance: " . $reason;

        $stmt = $conn->prepare("UPDATE events SET status = 'Approved', endorsement_notes = CONCAT(COALESCE(endorsement_notes, ''), ' [', ?, ']'), rejection_note = NULL WHERE id = ?");
        $stmt->bind_param('si', $override_note, $id);
        if (!$stmt->execute()) eRespond(false, 'Failed to apply override clearance: ' . $stmt->error);
        $stmt->close();

        log_workflow_history($conn, 'events', $id, 'Under Review', 'Approved', 'admin_override', $user_id, $override_note);

        $ev = $conn->query("SELECT title, created_by FROM events WHERE id = $id")->fetch_assoc();
        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Approved via Admin Override ⚡',
                "Your event \"{$ev['title']}\" was granted administrative clearance by System Admin ($reason).", 'success', 'event', $id, '../dashboard/events.php');
        }

        // Notify SSC
        $sscs = $conn->query("SELECT id FROM users WHERE role IN ('ssc', 'staff')");
        while ($s = $sscs->fetch_assoc()) {
            push_notification($conn, (int)$s['id'], 'Event Override Clearance Granted',
                "Event \"{$ev['title']}\" received Admin Override Clearance ($reason) and is published to campus calendar.", 'event', 'event', $id, '../dashboard/events.php');
        }

        log_audit($conn, $user_id, 'event_admin_override', 'events', $id, "Admin granted override clearance for event #$id: $reason");
        eRespond(true, 'Event approved via administrative override and published to campus calendar!');
    }


    // ── RETURN FOR REVISION (SSC / Admin) ────────────────────
    case 'return':
    case 'return_for_revision': {
        if (!in_array($user_role, ['ssc', 'staff', 'admin', 'super_admin'])) {
            eRespond(false, 'Only SSC Officers and Administrators can return events for revision.');
        }

        $id   = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? $_POST['remarks'] ?? 'Event proposal returned for revision.');
        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        $ret_prefix = in_array($user_role, ['ssc', 'staff']) ? 'SSC Revision Requested: ' : 'Admin Revision Requested: ';
        $full_note  = $ret_prefix . $note;

        $stmt = $conn->prepare("UPDATE events SET status = 'Returned', rejection_note = ? WHERE id = ?");
        $stmt->bind_param('si', $full_note, $id);
        $stmt->execute();
        $stmt->close();

        log_workflow_history($conn, 'events', $id, 'Under Review', 'Returned', 'return_for_revision', $user_id, $full_note);

        $ev = $conn->query("SELECT title, created_by FROM events WHERE id = $id")->fetch_assoc();
        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Proposal Returned for Revision',
                "Your event proposal \"{$ev['title']}\" was returned for revision. Reason: $note", 'warning', 'event', $id, '../dashboard/events.php');
        }

        log_audit($conn, $user_id, 'event_return', 'events', $id, "Returned event #$id for revision by $user_role: $note");
        eRespond(true, 'Event proposal returned for revision and proposer notified.');
    }

    // ── REJECT event (SSC / Admin) ──────────────────
    case 'reject': {
        if (!in_array($user_role, ['ssc', 'staff', 'admin', 'super_admin'])) {
            eRespond(false, 'Only SSC Officers and Administrators can reject events.');
        }

        $id   = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? $_POST['remarks'] ?? 'Event proposal did not meet compliance requirements.');
        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        $rej_prefix = in_array($user_role, ['ssc', 'staff']) ? 'SSC Review Feedback: ' : 'Admin Review Feedback: ';
        $full_note  = $rej_prefix . $note;

        $stmt = $conn->prepare("UPDATE events SET status = 'Rejected', rejection_note = ? WHERE id = ?");
        $stmt->bind_param('si', $full_note, $id);
        $stmt->execute();
        $stmt->close();

        log_workflow_history($conn, 'events', $id, 'Under Review', 'Rejected', 'reject', $user_id, $full_note);

        $ev = $conn->query("SELECT title, created_by FROM events WHERE id = $id")->fetch_assoc();
        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Proposal Feedback',
                "Your event proposal \"{$ev['title']}\" was rejected. Reason: $note", 'warning', 'event', $id, '../dashboard/events.php');
        }

        log_audit($conn, $user_id, 'event_reject', 'events', $id, "Rejected event #$id by $user_role: $note");
        eRespond(true, 'Event proposal rejected and notification sent to proposer.');
    }

    // ── EDIT event (Adviser / SSC / Staff / Admin / Super Admin) ────────────────────
    case 'edit': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'])) {
            eRespond(false, 'Not authorized to edit events.');
        }

        $id             = (int)($_POST['id'] ?? 0);
        $title          = trim($_POST['title'] ?? '');
        $description    = trim($_POST['description'] ?? '');
        $event_date_raw = trim($_POST['event_date'] ?? '');
        $start_time_raw = trim($_POST['start_time'] ?? '');
        $end_time_raw   = trim($_POST['end_time'] ?? '');
        $venue          = trim($_POST['venue'] ?? '');
        $target_course  = trim($_POST['target_course'] ?? 'All Courses');
        $target_org     = trim($_POST['target_org'] ?? 'All Organizations');

        if ($id <= 0 || !$title || !$event_date_raw || !$venue) {
            eRespond(false, 'All required fields must be filled.');
        }

        $dt_part = substr($event_date_raw, 0, 10);
        $start_time = !empty($start_time_raw) ? date('H:i:s', strtotime($start_time_raw)) : (strpos($event_date_raw, 'T') !== false ? date('H:i:s', strtotime($event_date_raw)) : '08:00:00');
        $end_time = !empty($end_time_raw) ? date('H:i:s', strtotime($end_time_raw)) : date('H:i:s', strtotime($start_time . ' + 3 hours'));
        $event_date = $dt_part . ' ' . $start_time;

        // Scope check: If adviser, verify event belongs to their organization or was created by them
        if ($user_role === 'club_adviser') {
            $sess_user = $_SESSION['username'] ?? '';
            $chk_ev = $conn->prepare("
                SELECT e.id FROM events e
                LEFT JOIN clubs c ON c.id = e.club_id
                WHERE e.id = ? AND (
                    e.created_by = ?
                    OR e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active')
                    OR c.code = UPPER(SUBSTRING_INDEX(?, '.', 1))
                ) LIMIT 1
            ");
            $chk_ev->bind_param('iiis', $id, $user_id, $user_id, $sess_user);
            $chk_ev->execute();
            if (!$chk_ev->get_result()->fetch_assoc()) {
                $chk_ev->close();
                eRespond(false, 'Unauthorized: You can only edit events belonging to your assigned organization.');
            }
            $chk_ev->close();
        }

        $curr_res = $conn->query("SELECT club_id, requires_budget, attachment, doc_letter, doc_board_resolution, doc_liquidation, doc_budget_proposal, status FROM events WHERE id = $id LIMIT 1");
        $curr_ev  = $curr_res ? $curr_res->fetch_assoc() : [];
        $c_id     = (int)($curr_ev['club_id'] ?? 0);

        // Run Pre-Flight Conflict Check with time & venue overlap detection (excluding current event id)
        $conflictAnalysis = detect_event_schedule_conflict($conn, $event_date, $venue, $id, $c_id, $start_time, $end_time);

        // If venue & time collision is detected, block submission with warning
        foreach ($conflictAnalysis['conflicts'] as $c) {
            if ($c['category'] === 'venue_time_collision') {
                eRespond(false, strip_tags($c['message']), ['conflict_analysis' => $conflictAnalysis]);
            }
        }

        $requires_budget = isset($_POST['requires_budget']) ? (int)$_POST['requires_budget'] : (int)($curr_ev['requires_budget'] ?? 1);
        $budget_amount   = $requires_budget ? (isset($_POST['budget_amount']) ? (float)$_POST['budget_amount'] : 0.00) : 0.00;

        // Upload any newly replaced documentary requirements
        $new_letter    = upload_event_doc_file('doc_letter', 'letter');
        $new_board_res = upload_event_doc_file('doc_board_resolution', 'board_res');
        $new_liq       = upload_event_doc_file('doc_liquidation', 'liquidation');
        $new_bud_prop  = $requires_budget ? upload_event_doc_file('doc_budget_proposal', 'budget_prop') : null;

        $doc_letter           = $new_letter ?: ($curr_ev['doc_letter'] ?? null);
        $doc_board_resolution = $new_board_res ?: ($curr_ev['doc_board_resolution'] ?? null);
        $doc_liquidation      = $new_liq ?: ($curr_ev['doc_liquidation'] ?? null);
        $doc_budget_proposal  = $requires_budget ? ($new_bud_prop ?: ($curr_ev['doc_budget_proposal'] ?? null)) : null;
        $attachment_path      = $doc_letter ?: ($curr_ev['attachment'] ?? null);

        // If returned event is revised by club adviser, automatically resubmit to Pending SSC
        $new_status = $curr_ev['status'] ?? 'Pending SSC';
        if ($user_role === 'club_adviser' && $new_status === 'Returned') {
            $new_status = 'Pending SSC';
        }

        $stmt = $conn->prepare(
            "UPDATE events
             SET title=?, description=?, event_date=?, start_time=?, end_time=?, venue=?, target_course=?, target_org=?,
                 requires_budget=?, budget_amount=?, attachment=?, doc_letter=?, doc_board_resolution=?, doc_liquidation=?, doc_budget_proposal=?, status=?
             WHERE id=?"
        );
        $stmt->bind_param(
            'ssssssssidssssssi',
            $title, $description, $event_date, $start_time, $end_time, $venue, $target_course, $target_org,
            $requires_budget, $budget_amount, $attachment_path, $doc_letter, $doc_board_resolution, $doc_liquidation, $doc_budget_proposal, $new_status, $id
        );
        if (!$stmt->execute()) eRespond(false, 'Failed to update event: ' . $stmt->error);
        $stmt->close();

        log_audit($conn, $user_id, 'event_edit', 'events', $id, "Edited event #$id: $title");
        eRespond(true, 'Event proposal and documentary requirements updated successfully.');
    }

    // ── REGISTER for event ──────────────────────────────────────
    case 'register': {
        if ($user_role === 'club_adviser') {
            eRespond(false, 'Club Advisers are not eligible to register as event participants.');
        }

        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id <= 0) eRespond(false, 'Invalid event ID.');

        $ev = $conn->query("SELECT id, title, status, event_date, expected_attendees FROM events WHERE id = $event_id")->fetch_assoc();
        if (!$ev) eRespond(false, 'Event not found.');
        if ($ev['status'] !== 'Approved' && $ev['status'] !== 'Upcoming') {
            eRespond(false, 'Registration is only open for officially approved events.');
        }

        // Prevent registration for past events
        if (strtotime($ev['event_date']) < strtotime(date('Y-m-d'))) {
            eRespond(false, 'Registration is closed because this event date has already passed.');
        }

        // Enforce maximum capacity if expected_attendees is specified
        $capacity = (int)($ev['expected_attendees'] ?? 0);
        if ($capacity > 0) {
            $reg_count = (int)($conn->query("SELECT COUNT(*) FROM event_registrations WHERE event_id = $event_id AND status = 'Registered'")->fetch_row()[0] ?? 0);
            $already = (int)($conn->query("SELECT COUNT(*) FROM event_registrations WHERE event_id = $event_id AND user_id = $user_id AND status = 'Registered'")->fetch_row()[0] ?? 0);
            if (!$already && $reg_count >= $capacity) {
                eRespond(false, "Event registration has reached maximum capacity ($capacity attendee limit reached).");
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO event_registrations (event_id, user_id, status) VALUES (?, ?, 'Registered')
             ON DUPLICATE KEY UPDATE status='Registered', registered_at=CURRENT_TIMESTAMP"
        );
        $stmt->bind_param('ii', $event_id, $user_id);
        if (!$stmt->execute()) eRespond(false, 'Failed to register: ' . $stmt->error);
        $stmt->close();

        push_notification($conn, $user_id, 'Event Registration Confirmed 🎉',
            "You have successfully registered for \"{$ev['title']}\" on " . date('M d, Y', strtotime($ev['event_date'])) . ".", 'success');
        log_audit($conn, $user_id, 'event_register', 'event_registrations', $event_id, "Registered for event #$event_id ({$ev['title']})");

        eRespond(true, "Successfully registered for \"{$ev['title']}\"!");
    }

    // ── DELETE event (Adviser / SSC / Admin) ───────────────────
    case 'delete': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
            eRespond(false, 'Not authorized.');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        // Scope check: If adviser, verify event belongs to their organization or was created by them
        if ($user_role === 'club_adviser') {
            $sess_user = $_SESSION['username'] ?? '';
            $chk_ev = $conn->prepare("
                SELECT e.id FROM events e
                LEFT JOIN clubs c ON c.id = e.club_id
                WHERE e.id = ? AND (
                    e.created_by = ?
                    OR e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active')
                    OR c.code = UPPER(SUBSTRING_INDEX(?, '.', 1))
                ) LIMIT 1
            ");
            $chk_ev->bind_param('iiis', $id, $user_id, $user_id, $sess_user);
            $chk_ev->execute();
            if (!$chk_ev->get_result()->fetch_assoc()) {
                $chk_ev->close();
                eRespond(false, 'Unauthorized: You can only delete events belonging to your assigned organization.');
            }
            $chk_ev->close();
        }

        $stmt = $conn->prepare("UPDATE events SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_audit($conn, $user_id, 'event_delete', 'events', $id, "Soft-deleted event #$id");
        eRespond(true, 'Event deleted.');
    }

    // ── LIST REGISTRATIONS for event ──────────────────────────
    case 'list_registrations': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
            eRespond(false, 'Unauthorized.');
        }
        $event_id = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
        if (!$event_id) eRespond(false, 'Invalid event ID.');

        $sql = "SELECT er.id, er.registered_at, er.status,
                       u.first_name, u.last_name, u.email,
                       s.student_number, s.course, s.year_level, s.phone, s.section
                FROM event_registrations er
                JOIN users u ON u.id = er.user_id
                LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
                WHERE er.event_id = ?
                ORDER BY er.registered_at ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $ev = $conn->query("
            SELECT e.title, e.event_date, e.venue, e.status, e.event_type,
                   COALESCE(c.name, 'BCP Institutional / Campus-Wide') AS club_name, 
                   COALESCE(c.code, 'INSTITUTIONAL') AS club_code 
            FROM events e 
            LEFT JOIN clubs c ON c.id = e.club_id 
            WHERE e.id = $event_id
        ")->fetch_assoc();

        eRespond(true, 'OK', ['registrations' => $registrations, 'event' => $ev]);
    }

    default:
        eRespond(false, 'Unknown action.');
}
$conn->close();
