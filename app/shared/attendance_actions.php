<?php
// ============================================================
//  ATTENDANCE_ACTIONS.PHP — Attendance AJAX handler
//  Actions: list_mine, list_event, log_qr, log_manual, analytics
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

function aRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra)); exit;
}

// ── HELPER: Resolve User from any QR / Student Number / ID ──
// Supports: BCP-STUDENT-2024-10001, BCP-STUDENT-1, BCP-STAFF-1, BCP-USER-1, 2024-10001, username, etc.
function resolveUserFromQr(mysqli $conn, string $code): ?array {
    $code = trim($code);
    if (!$code) return null;

    // Check if wrapped in URL
    if (preg_match('/(BCP-(?:STUDENT|STAFF|USER)-[A-Za-z0-9\-_\.]+)/i', $code, $m)) {
        $code = $m[1];
    }

    // Clean identifier (strip prefix if present)
    $cleanId = preg_replace('/^BCP-(?:STUDENT|STAFF|USER)-/i', '', $code);

    // 1. Match students table by student_number (e.g. '2024-10001')
    $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.username,
                                   s.student_number, s.course, s.year_level, s.section
                            FROM students s
                            JOIN users u ON s.user_id = u.id
                            WHERE s.student_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $cleanId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return $row;
    }

    // Also check with original code in case prefix wasn't stripped
    if ($cleanId !== $code) {
        $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.username,
                                       s.student_number, s.course, s.year_level, s.section
                                FROM students s
                                JOIN users u ON s.user_id = u.id
                                WHERE s.student_number = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return $row;
        }
    }

    // 2. If numeric, check users.id
    if (is_numeric($cleanId)) {
        $uid = (int)$cleanId;
        $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.username,
                                       s.student_number, s.course, s.year_level, s.section
                                FROM users u
                                LEFT JOIN students s ON s.user_id = u.id
                                WHERE u.id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return $row;
        }
    }

    // 3. Match users table by username (e.g. 'bsit.student', 'admin', 'cssec.adviser')
    $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.username,
                                   s.student_number, s.course, s.year_level, s.section
                            FROM users u
                            LEFT JOIN students s ON s.user_id = u.id
                            WHERE u.username = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $cleanId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return $row;
    }

    return null;
}

// ── HELPER: Resolve Event from any Event QR payload ───────
function resolveEventFromQr(mysqli $conn, string $code): ?array {
    $code = trim($code);
    if (!$code) return null;

    $targetEventId = 0;
    $sessionId = 0;
    $token = '';

    // 1. Dynamic session format: BCP-ATTEND-E{event_id}-S{session_id}-T{token}
    if (preg_match('/BCP-ATTEND-E(\d+)(?:-S(\d+))?(?:-T([a-zA-Z0-9]+))?/i', $code, $m)) {
        $targetEventId = (int)$m[1];
        $sessionId     = (int)($m[2] ?? 0);
        $token         = $m[3] ?? '';
    } 
    // 2. Event poster format: BCP-EVENT-{id} or BCP-EVENT-LOG-{id}
    elseif (preg_match('/BCP-EVENT(?:-LOG)?-(\d+)(?:-T([a-zA-Z0-9]+))?/i', $code, $m)) {
        $targetEventId = (int)$m[1];
        $token         = $m[2] ?? '';
    } 
    // 3. Generic EVENT-1 or EVENT_1
    elseif (preg_match('/EVENT[_-](\d+)/i', $code, $m)) {
        $targetEventId = (int)$m[1];
    }
    // 4. URL format with event_id or id parameter
    elseif (preg_match('/(?:event_id|id)=(\d+)/i', $code, $m)) {
        $targetEventId = (int)$m[1];
    }
    // 5. JSON format
    elseif (preg_match('/"(?:event_id|id)"\s*:\s*(\d+)/i', $code, $m)) {
        $targetEventId = (int)$m[1];
    }
    // 6. Direct numeric ID
    elseif (ctype_digit($code)) {
        $targetEventId = (int)$code;
    }

    if ($targetEventId <= 0) return null;

    $stmt = $conn->prepare("SELECT e.id, e.title, e.event_date, e.venue, e.status, e.event_type, e.club_id, e.target_course,
                                   c.name as club_name, c.code as club_code
                            FROM events e
                            LEFT JOIN clubs c ON c.id = e.club_id
                            WHERE e.id = ? AND e.status NOT IN ('Rejected', 'Cancelled') LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $targetEventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $row['qr_session_id'] = $sessionId;
            $row['qr_token']      = $token;
            return $row;
        }
    }
    return null;
}

if (!empty($action)) {
    switch ($action) {

    // ── LIST all attendees for an event (Adviser / above) ────
    case 'list_event': {
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
            aRespond(false, 'Not authorized.');
        $event_id = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
        if ($event_id <= 0) aRespond(false, 'Invalid event ID.');

        $stmt = $conn->prepare(
            "SELECT al.id, al.user_id, al.check_in, al.method,
                    u.first_name, u.last_name, u.email,
                    s.student_number
             FROM attendance_logs al
             JOIN users u ON u.id = al.user_id
             LEFT JOIN students s ON s.id = (
                 SELECT s2.id FROM students s2 
                 WHERE s2.user_id = u.id OR (s2.first_name = u.first_name AND s2.last_name = u.last_name) 
                 LIMIT 1
             )
             WHERE al.event_id = ?
             ORDER BY al.check_in DESC"
        );
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        aRespond(true, 'OK', ['attendees' => $rows, 'count' => count($rows)]);
    }

    // -- LOG via QR scan -------------------------------------------------------
    // Mode A: Student/Staff scans Event Poster QR (BCP-EVENT-{id}) -> Self Check-in
    // Mode B: Staff/Scanner terminal scans User QR Badge (BCP-STUDENT-{num/id} or BCP-STAFF-{id}) for an Event -> Staff Check-in
    // -- LOG via QR scan -------------------------------------------------------
    // Mode A: Student scans Event QR (dynamic session or poster) -> Self Check-in
    case 'log_qr': {
        $qr_data  = trim($_POST['qr_data']  ?? '');
        $event_id = (int)($_POST['event_id'] ?? 0);
        if (!$qr_data) aRespond(false, 'QR data is required.');

        // ── 1. CHECK IF SCANNED DATA IS AN EVENT QR CODE ──────────────
        $scannedEvent = resolveEventFromQr($conn, $qr_data);
        if ($scannedEvent) {
            $target_event_id = (int)$scannedEvent['id'];
            $evTitle = $scannedEvent['title'];
            $evDateFormatted = date('F j, Y', strtotime($scannedEvent['event_date']));

            // Validate Event Status (allow all non-cancelled events)
            if (in_array($scannedEvent['status'], ['Rejected', 'Cancelled'])) {
                $tokenHash = hash('sha256', $qr_data);
                $conn->query("INSERT INTO attendance_scan_attempts (event_id, user_id, scanner_id, token_hash, result, reason, scanned_at) 
                              VALUES ($target_event_id, $user_id, $user_id, '$tokenHash', 'CLOSED', 'Event is cancelled or rejected', NOW())");
                aRespond(false, "Attendance Closed\n\nThis event is not accepting attendance scans.", [
                    'result' => 'CLOSED',
                    'event_title' => $evTitle
                ]);
            }

            // Mode A: Self Check-in (Student or Self-Scanning User)
            if ($user_role === 'student' || empty($event_id) || $event_id === $target_event_id) {

                // 2. Duplicate Check
                $dup = $conn->query("SELECT id, check_in, status FROM attendance_logs WHERE event_id = $target_event_id AND user_id = $user_id LIMIT 1");
                if ($dup && $dup->num_rows > 0) {
                    $dupRow = $dup->fetch_assoc();
                    $timeStr = date('h:i A', strtotime($dupRow['check_in']));
                    $tokenHash = hash('sha256', $qr_data);
                    $conn->query("INSERT INTO attendance_scan_attempts (event_id, user_id, scanner_id, token_hash, result, reason, scanned_at) 
                                  VALUES ($target_event_id, $user_id, $user_id, '$tokenHash', 'ALREADY_SCANNED', 'Duplicate self QR scan attempt', NOW())");
                    aRespond(true, "Already Checked In\n\nYour attendance for this event was already recorded at {$timeStr}.", [
                        'already_logged' => true,
                        'result'         => 'ALREADY_SCANNED',
                        'event_title'    => $evTitle,
                        'check_in_time'  => $timeStr,
                        'status'         => strtoupper($dupRow['status'] ?? 'PRESENT')
                    ]);
                }

                // 3. Determine Status (PRESENT vs LATE)
                $att_status = 'Present';
                $lateCutoffMins = 30;
                $qrs_check = $conn->query("SELECT * FROM qr_sessions WHERE event_id = $target_event_id AND status = 'active' ORDER BY id DESC LIMIT 1");
                if ($qrs_check && $qrs_check->num_rows > 0) {
                    $qrs = $qrs_check->fetch_assoc();
                    if (!empty($qrs['late_after_minutes'])) {
                        $lateCutoffMins = (int)$qrs['late_after_minutes'];
                        $openedTime = strtotime($qrs['opened_at'] ?? 'now');
                        if ((time() - $openedTime) > ($lateCutoffMins * 60)) {
                            $att_status = 'Late';
                        }
                    }
                } elseif (!empty($scannedEvent['event_date'])) {
                    $evStartTime = strtotime($scannedEvent['event_date']);
                    if ($evStartTime && time() > ($evStartTime + ($lateCutoffMins * 60))) {
                        $att_status = 'Late';
                    }
                }

                // 4. Ensure student is registered for the event
                $conn->query("INSERT IGNORE INTO event_registrations (event_id, user_id, registered_at, status) 
                              VALUES ($target_event_id, $user_id, NOW(), 'Attended')");

                $method = 'QR_SELF';
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by, status, check_in) 
                                            VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->bind_param('iisis', $target_event_id, $user_id, $method, $user_id, $att_status);
                    $stmt->execute();
                    $stmt->close();

                    $tokenHash = hash('sha256', $qr_data);
                    $reasonMsg = "Self check-in ({$att_status})";
                    $conn->query("INSERT INTO attendance_scan_attempts (event_id, user_id, scanner_id, token_hash, result, reason, scanned_at) 
                                  VALUES ($target_event_id, $user_id, $user_id, '$tokenHash', 'VALID', '$reasonMsg', NOW())");

                    $conn->commit();
                } catch (Throwable $ex) {
                    $conn->rollback();
                    aRespond(false, 'Failed to record attendance: ' . $ex->getMessage());
                }

                // Push notification & audit
                $timeNow = date('h:i A');
                push_notification($conn, $user_id, 'Attendance Recorded', "Your attendance for \"{$evTitle}\" has been recorded as {$att_status}.", 'success', "tracking_history.php");
                log_audit($conn, $user_id, 'attendance_self_qr', 'attendance_logs', $target_event_id, "Self checked-in to \"{$evTitle}\" via QR ({$att_status})");

                aRespond(true, "✓ Attendance Recorded\nStatus: " . strtoupper($att_status), [
                    'event_title'     => $evTitle,
                    'event_date'      => $evDateFormatted,
                    'check_in_time'   => $timeNow,
                    'status'          => strtoupper($att_status),
                    'result'          => strtoupper($att_status),
                    'is_self_checkin' => true
                ]);
            } else {
                // Mode A-2: Staff terminal scanned an event poster -> Switch active event in terminal
                aRespond(true, "Event selected: \"{$evTitle}\". You can now scan student badges!", [
                    'is_event_switch' => true,
                    'event_id'        => $target_event_id,
                    'event_title'     => $evTitle
                ]);
            }
        }

        // ── 2. CHECK IF SCANNED DATA IS A STUDENT OR STAFF BADGE ─────
        $targetUser = resolveUserFromQr($conn, $qr_data);
        if ($targetUser) {
            $target_user_id = (int)$targetUser['id'];
            $fullName       = trim($targetUser['first_name'] . ' ' . $targetUser['last_name']);
            $stuNum         = $targetUser['student_number'] ?? $targetUser['username'];

            // If a student tries to scan another student's badge
            if ($user_role === 'student') {
                $tokenHash = hash('sha256', $qr_data);
                $conn->query("INSERT INTO attendance_scan_attempts (event_id, user_id, scanner_id, token_hash, result, reason, scanned_at) 
                              VALUES (NULL, $target_user_id, $user_id, '$tokenHash', 'UNAUTHORIZED', 'Student cannot scan other student badges', NOW())");
                aRespond(false, "Attendance Not Allowed\n\nYou scanned a student badge. Please scan the Event QR code displayed at the venue.", [
                    'result' => 'NOT_ELIGIBLE'
                ]);
            }

            // Staff scanner requires an event to be selected
            if ($event_id <= 0) {
                aRespond(false, "Please select an event from the dropdown before scanning student badges. (Scanned: $fullName - $stuNum)", [
                    'need_event'   => true,
                    'student_name' => $fullName
                ]);
            }

            // Check if already checked in to this event
            $dup = $conn->query("SELECT id, check_in, status FROM attendance_logs WHERE event_id = $event_id AND user_id = $target_user_id LIMIT 1");
            if ($dup && $dup->num_rows > 0) {
                $dupRow = $dup->fetch_assoc();
                $timeStr = date('h:i A', strtotime($dupRow['check_in']));
                $tokenHash = hash('sha256', $qr_data);
                $conn->query("INSERT INTO attendance_scan_attempts (event_id, user_id, scanner_id, token_hash, result, reason, scanned_at) 
                              VALUES ($event_id, $target_user_id, $user_id, '$tokenHash', 'ALREADY_SCANNED', 'Duplicate terminal QR scan attempt', NOW())");
                aRespond(true, "Already Recorded\n\n{$fullName} ({$stuNum}) is already checked in (at {$timeStr}).", [
                    'already_logged' => true,
                    'result'         => 'ALREADY_SCANNED',
                    'student_name'   => $fullName,
                    'student_number' => $stuNum
                ]);
            }

            // Auto-register student if not yet registered
            $conn->query("INSERT IGNORE INTO event_registrations (event_id, user_id, registered_at, status) 
                          VALUES ($event_id, $target_user_id, NOW(), 'Attended')");

            $method = 'QR';
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by, status, check_in) 
                                        VALUES (?, ?, ?, ?, 'Present', NOW())");
                $stmt->bind_param('iisi', $event_id, $target_user_id, $method, $user_id);
                $stmt->execute();
                $stmt->close();

                $tokenHash = hash('sha256', $qr_data);
                $conn->query("INSERT INTO attendance_scan_attempts (event_id, user_id, scanner_id, token_hash, result, reason, scanned_at) 
                              VALUES ($event_id, $target_user_id, $user_id, '$tokenHash', 'VALID', 'Successful terminal badge scan', NOW())");

                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                aRespond(false, 'Failed to log attendance: ' . $ex->getMessage());
            }

            // Push notification & audit
            $ev = $conn->query("SELECT title FROM events WHERE id = $event_id")->fetch_assoc();
            $evTitle = $ev['title'] ?? 'Campus Event';
            push_notification($conn, $target_user_id, 'Attendance Logged', "Your attendance for \"{$evTitle}\" was recorded via QR scan.", 'info');
            log_audit($conn, $user_id, 'attendance_qr', 'attendance_logs', $target_user_id, "Checked in {$fullName} (#$target_user_id) for event #$event_id via QR");

            aRespond(true, "✓ Attendance Recorded\n\n{$fullName} ({$stuNum}) checked in successfully!", [
                'student_name'   => $fullName,
                'student_number' => $stuNum,
                'course'         => $targetUser['course'] ?? '',
                'section'        => $targetUser['section'] ?? '',
                'result'         => 'VALID',
                'status'         => 'PRESENT',
                'check_in_time'  => date('h:i:s A')
            ]);
        }

        // Record invalid QR scan attempt
        $safeQr = substr($qr_data, 0, 100);
        $tokenHash = hash('sha256', $qr_data);
        $safeReason = $conn->real_escape_string("Unrecognized QR code payload: {$safeQr}");
        $evParam = $event_id > 0 ? $event_id : "NULL";
        $conn->query("INSERT INTO attendance_scan_attempts (event_id, user_id, scanner_id, token_hash, result, reason, scanned_at) 
                      VALUES ($evParam, NULL, $user_id, '$tokenHash', 'INVALID_TOKEN', '$safeReason', NOW())");
        aRespond(false, "Invalid QR Code\n\nPlease position the official Event QR code inside the camera box.", ['result' => 'INVALID_TOKEN']);
    }

    // ── OPEN DYNAMIC QR ATTENDANCE SESSION (Adviser / Admin / SSC) ──
    case 'open_qr_session': {
        if (!in_array($user_role, ['club_adviser', 'admin', 'ssc'])) {
            aRespond(false, 'Not authorized to open QR attendance sessions.');
        }

        $event_id        = (int)($_POST['event_id'] ?? 0);
        $refresh_seconds = max(15, min(300, (int)($_POST['refresh_seconds'] ?? 60)));
        $late_minutes    = max(0, min(240, (int)($_POST['late_after_minutes'] ?? 15)));

        if ($event_id <= 0) aRespond(false, 'Please select an event.');

        // Adviser can only open for their assigned club events
        if ($user_role === 'club_adviser') {
            $sess_user = $_SESSION['username'] ?? '';
            $checkEv = $conn->query("
                SELECT e.id, e.title, c.name as club_name
                FROM events e
                JOIN clubs c ON c.id = e.club_id
                WHERE e.id = $event_id
                  AND (c.code = UPPER(SUBSTRING_INDEX('$sess_user', '.', 1))
                       OR e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = $user_id AND status = 'Active'))
            ");
            if (!$checkEv || $checkEv->num_rows === 0) {
                aRespond(false, 'You are only authorized to manage attendance for your assigned club events.');
            }
        }

        // Close any existing active sessions for this event
        $conn->query("UPDATE qr_sessions SET status = 'closed', closed_at = NOW(), closed_by = $user_id, close_reason = 'New session opened' WHERE event_id = $event_id AND status = 'active'");

        // Generate initial secure random token
        $initial_token = bin2hex(random_bytes(12));
        $seed          = bin2hex(random_bytes(8));
        $expires_at    = date('Y-m-d H:i:s', time() + $refresh_seconds);

        $stmt = $conn->prepare("INSERT INTO qr_sessions (event_id, created_by, current_token, token_seed, status, refresh_seconds, late_after_minutes, opened_at, expires_at)
                                VALUES (?, ?, ?, ?, 'active', ?, ?, NOW(), ?)");
        $stmt->bind_param('iissiiss', $event_id, $user_id, $initial_token, $seed, $refresh_seconds, $late_minutes, $expires_at);
        if (!$stmt->execute()) {
            aRespond(false, 'Failed to create QR session: ' . $stmt->error);
        }
        $session_id = $stmt->insert_id;
        $stmt->close();

        // QR payload format: BCP-ATTEND-E{event_id}-S{session_id}-T{token}
        $qr_payload = "BCP-ATTEND-E{$event_id}-S{$session_id}-T{$initial_token}";

        log_audit($conn, $user_id, 'open_qr_session', 'qr_sessions', $session_id, "Opened QR attendance session for event #$event_id (Refresh: {$refresh_seconds}s, Late: {$late_minutes}m)");

        aRespond(true, 'Attendance session opened successfully!', [
            'session_id'         => $session_id,
            'event_id'           => $event_id,
            'current_token'      => $initial_token,
            'qr_payload'         => $qr_payload,
            'refresh_seconds'    => $refresh_seconds,
            'late_after_minutes' => $late_minutes,
            'status'             => 'active'
        ]);
    }

    // ── REFRESH QR TOKEN (Dynamic token rotation) ─────────────
    case 'refresh_qr_token': {
        if (!in_array($user_role, ['club_adviser', 'admin', 'ssc'])) {
            aRespond(false, 'Not authorized.');
        }

        $session_id = (int)($_POST['session_id'] ?? 0);
        $event_id   = (int)($_POST['event_id'] ?? 0);

        if ($session_id <= 0) aRespond(false, 'Invalid session ID.');

        $sess = $conn->query("SELECT * FROM qr_sessions WHERE id = $session_id LIMIT 1")->fetch_assoc();
        if (!$sess) aRespond(false, 'QR session not found.');

        if ($sess['status'] === 'terminated') {
            aRespond(false, 'This session was terminated by an administrator.', ['status' => 'terminated']);
        }
        if ($sess['status'] === 'closed') {
            aRespond(false, 'This session is closed.', ['status' => 'closed']);
        }

        $new_token   = bin2hex(random_bytes(12));
        $refresh_sec = (int)$sess['refresh_seconds'];
        $expires_at  = date('Y-m-d H:i:s', time() + $refresh_sec);

        $conn->query("UPDATE qr_sessions SET current_token = '$new_token', expires_at = '$expires_at' WHERE id = $session_id");

        $qr_payload = "BCP-ATTEND-E{$sess['event_id']}-S{$session_id}-T{$new_token}";

        aRespond(true, 'QR token refreshed', [
            'session_id'        => $session_id,
            'current_token'     => $new_token,
            'qr_payload'        => $qr_payload,
            'refresh_seconds'   => $refresh_sec,
            'remaining_seconds' => $refresh_sec,
            'status'            => 'active'
        ]);
    }

    // ── CLOSE QR ATTENDANCE SESSION (Adviser / Admin) ─────────
    case 'close_qr_session': {
        if (!in_array($user_role, ['club_adviser', 'admin', 'ssc'])) {
            aRespond(false, 'Not authorized.');
        }

        $session_id = (int)($_POST['session_id'] ?? 0);
        $event_id   = (int)($_POST['event_id'] ?? 0);

        if ($session_id > 0) {
            $conn->query("UPDATE qr_sessions SET status = 'closed', closed_at = NOW(), closed_by = $user_id, close_reason = 'Closed by operator' WHERE id = $session_id");
            log_audit($conn, $user_id, 'close_qr_session', 'qr_sessions', $session_id, "Closed attendance session #$session_id");
        } elseif ($event_id > 0) {
            $conn->query("UPDATE qr_sessions SET status = 'closed', closed_at = NOW(), closed_by = $user_id, close_reason = 'Closed by operator' WHERE event_id = $event_id AND status = 'active'");
            log_audit($conn, $user_id, 'close_qr_session', 'events', $event_id, "Closed all active QR sessions for event #$event_id");
        }

        aRespond(true, 'Attendance session closed successfully.');
    }

    // ── TERMINATE QR SESSION (Admin Control with Reason) ──────
    case 'terminate_qr_session': {
        if ($user_role !== 'admin') {
            aRespond(false, 'Only Administrators have permission to terminate QR attendance sessions.');
        }

        $session_id = (int)($_POST['session_id'] ?? 0);
        $reason     = trim($_POST['reason'] ?? 'Administrator security termination');

        if ($session_id <= 0) aRespond(false, 'Invalid session ID.');
        if (strlen($reason) < 4) aRespond(false, 'Please provide a valid termination reason for audit compliance.');

        $safeReason = $conn->real_escape_string($reason);
        $conn->query("UPDATE qr_sessions SET status = 'terminated', closed_at = NOW(), closed_by = $user_id, close_reason = '$safeReason' WHERE id = $session_id");

        log_audit($conn, $user_id, 'terminate_qr_session', 'qr_sessions', $session_id, "Admin terminated QR session #$session_id. Reason: $reason");

        aRespond(true, 'Attendance session terminated immediately.', ['session_id' => $session_id]);
    }

    // ── GET QR SESSION STATUS & LIVE STATS ────────────────────
    case 'get_qr_session': {
        $event_id = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
        if ($event_id <= 0) aRespond(false, 'Invalid event ID.');

        $sess = $conn->query("SELECT * FROM qr_sessions WHERE event_id = $event_id AND status = 'active' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        if (!$sess) {
            aRespond(true, 'No active session', ['has_active_session' => false]);
        }

        $remaining_seconds = 0;
        if (!empty($sess['expires_at'])) {
            $rem = strtotime($sess['expires_at']) - time();
            $remaining_seconds = max(0, $rem);
        }

        $qr_payload = "BCP-ATTEND-E{$event_id}-S{$sess['id']}-T{$sess['current_token']}";

        // Fetch counts
        $totalRegistered = (int)$conn->query("SELECT COUNT(*) FROM event_registrations WHERE event_id = $event_id")->fetch_row()[0];
        $presentCount    = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs WHERE event_id = $event_id AND status = 'Present'")->fetch_row()[0];
        $lateCount       = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs WHERE event_id = $event_id AND status = 'Late'")->fetch_row()[0];
        $totalAttendees  = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs WHERE event_id = $event_id")->fetch_row()[0];

        $pendingCount = max(0, $totalRegistered - $totalAttendees);

        aRespond(true, 'Active session found', [
            'has_active_session' => true,
            'session_id'         => (int)$sess['id'],
            'current_token'      => $sess['current_token'],
            'qr_payload'         => $qr_payload,
            'refresh_seconds'    => (int)$sess['refresh_seconds'],
            'remaining_seconds'  => $remaining_seconds,
            'late_after_minutes' => (int)$sess['late_after_minutes'],
            'opened_at'          => $sess['opened_at'],
            'counts'             => [
                'registered' => $totalRegistered,
                'present'    => $presentCount,
                'late'       => $lateCount,
                'total'      => $totalAttendees,
                'pending'    => $pendingCount
            ]
        ]);
    }

    // ── LIVE POLL ATTENDEES FOR AN EVENT ──────────────────────
    case 'live_poll_event': {
        $event_id = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
        if ($event_id <= 0) aRespond(false, 'Invalid event ID.');

        // Live Attendees list
        $stmt = $conn->prepare("
            SELECT al.id, al.user_id, al.check_in, al.method, al.status, al.override_reason,
                   u.first_name, u.last_name, u.email,
                   s.student_number, s.course, s.year_level, s.section
            FROM attendance_logs al
            JOIN users u ON u.id = al.user_id
            LEFT JOIN students s ON (s.user_id = u.id OR s.student_number = u.username)
            WHERE al.event_id = ?
            ORDER BY al.check_in DESC
            LIMIT 50
        ");
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $attendees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Format dates
        foreach ($attendees as &$a) {
            $a['time_formatted'] = date('h:i:s A', strtotime($a['check_in']));
            $a['full_name']      = trim($a['first_name'] . ' ' . $a['last_name']);
        }

        // Summary counts
        $totalRegistered = (int)$conn->query("SELECT COUNT(*) FROM event_registrations WHERE event_id = $event_id")->fetch_row()[0];
        $presentCount    = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs WHERE event_id = $event_id AND status = 'Present'")->fetch_row()[0];
        $lateCount       = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs WHERE event_id = $event_id AND status = 'Late'")->fetch_row()[0];
        $totalAttendees  = count($attendees);
        $pendingCount    = max(0, $totalRegistered - $totalAttendees);

        // Check if QR session is currently active
        $sessRow = $conn->query("SELECT id, status, refresh_seconds, expires_at FROM qr_sessions WHERE event_id = $event_id AND status = 'active' ORDER BY id DESC LIMIT 1")->fetch_assoc();

        aRespond(true, 'OK', [
            'attendees' => $attendees,
            'counts'    => [
                'registered' => $totalRegistered,
                'present'    => $presentCount,
                'late'       => $lateCount,
                'total'      => $totalAttendees,
                'pending'    => $pendingCount
            ],
            'session'   => $sessRow ? [
                'active'            => true,
                'session_id'        => (int)$sessRow['id'],
                'remaining_seconds' => max(0, strtotime($sessRow['expires_at'] ?? 'now') - time())
            ] : ['active' => false]
        ]);
    }

    // ── LIST SCAN ATTEMPTS (SSC & Admin) ──────────────────────
    case 'list_scan_attempts': {
        if (!in_array($user_role, ['ssc', 'admin', 'club_adviser'])) {
            aRespond(false, 'Not authorized.');
        }

        $event_id = (int)($_GET['event_id'] ?? 0);
        $result_filter = trim($_GET['result'] ?? '');
        $limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));

        $where = "WHERE 1=1";
        if ($event_id > 0) {
            $where .= " AND asa.event_id = $event_id";
        }
        if (!empty($result_filter)) {
            $safeRes = $conn->real_escape_string($result_filter);
            $where .= " AND asa.result = '$safeRes'";
        }

        // Adviser can only view attempts for their assigned events
        if ($user_role === 'club_adviser') {
            $sess_user = $_SESSION['username'] ?? '';
            $where .= " AND (asa.event_id IN (
                SELECT e.id FROM events e
                JOIN clubs c ON c.id = e.club_id
                WHERE c.code = UPPER(SUBSTRING_INDEX('$sess_user', '.', 1))
                   OR e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = $user_id AND status = 'Active')
            ))";
        }

        $sql = "
            SELECT asa.id, asa.event_id, asa.user_id, asa.scanner_id, asa.result, asa.reason, asa.scanned_at,
                   e.title as event_title,
                   u.first_name, u.last_name, u.username,
                   s.student_number, s.course,
                   su.first_name as scanner_first, su.last_name as scanner_last, su.role as scanner_role
            FROM attendance_scan_attempts asa
            LEFT JOIN events e ON e.id = asa.event_id
            LEFT JOIN users u ON u.id = asa.user_id
            LEFT JOIN students s ON (s.user_id = u.id OR s.student_number = u.username)
            LEFT JOIN users su ON su.id = asa.scanner_id
            $where
            ORDER BY asa.scanned_at DESC
            LIMIT $limit
        ";

        $res = $conn->query($sql);
        $attempts = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($attempts as &$att) {
            $att['time_formatted'] = date('M d, Y h:i:s A', strtotime($att['scanned_at']));
            $att['student_name']   = !empty($att['first_name']) ? trim($att['first_name'] . ' ' . $att['last_name']) : 'Guest / System';
        }

        aRespond(true, 'OK', ['attempts' => $attempts]);
    }

    // ── LIST ALL QR SESSIONS (Admin & SSC) ────────────────────
    case 'list_qr_sessions': {
        if (!in_array($user_role, ['ssc', 'admin', 'club_adviser'])) {
            aRespond(false, 'Not authorized.');
        }

        $where = "WHERE 1=1";
        if ($user_role === 'club_adviser') {
            $sess_user = $_SESSION['username'] ?? '';
            $where .= " AND (qs.event_id IN (
                SELECT e.id FROM events e
                JOIN clubs c ON c.id = e.club_id
                WHERE c.code = UPPER(SUBSTRING_INDEX('$sess_user', '.', 1))
                   OR e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = $user_id AND status = 'Active')
            ))";
        }

        $sql = "
            SELECT qs.*, e.title as event_title, e.event_date, e.venue,
                   c.name as club_name, c.code as club_code,
                   u.first_name as creator_first, u.last_name as creator_last,
                   (SELECT COUNT(*) FROM attendance_logs al WHERE al.event_id = qs.event_id) as total_scans
            FROM qr_sessions qs
            JOIN events e ON e.id = qs.event_id
            LEFT JOIN clubs c ON c.id = e.club_id
            LEFT JOIN users u ON u.id = qs.created_by
            $where
            ORDER BY qs.id DESC
            LIMIT 40
        ";

        $res = $conn->query($sql);
        $sessions = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($sessions as &$s) {
            $s['opened_at_formatted'] = date('M d, Y h:i A', strtotime($s['opened_at']));
            $s['creator_name'] = trim(($s['creator_first'] ?? '') . ' ' . ($s['creator_last'] ?? ''));
        }

        aRespond(true, 'OK', ['sessions' => $sessions]);
    }

    // ── LOG MANUAL ATTENDANCE (Adviser, SSC, Admin) ───────────
    case 'log_manual': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin']))
            aRespond(false, 'Not authorized to log manual attendance.');

        $target_user_id = (int)($_POST['user_id']   ?? 0);
        $event_id       = (int)($_POST['event_id']  ?? 0);
        $check_in       = trim($_POST['check_in']   ?? date('Y-m-d H:i:s'));
        $reason         = trim($_POST['override_reason'] ?? '');
        $att_status     = in_array($_POST['status'] ?? '', ['Present', 'Late', 'Excused', 'Absent']) ? $_POST['status'] : 'Present';

        if ($target_user_id <= 0 || $event_id <= 0) {
            aRespond(false, 'Please select both a student and an event.');
        }

        if (strlen($reason) < 4) {
            aRespond(false, 'A mandatory reason is required for manual attendance recording.');
        }

        // Adviser authorization check
        if ($user_role === 'club_adviser') {
            $sess_user = $_SESSION['username'] ?? '';
            $checkEv = $conn->query("
                SELECT e.id FROM events e
                JOIN clubs c ON c.id = e.club_id
                WHERE e.id = $event_id
                  AND (c.code = UPPER(SUBSTRING_INDEX('$sess_user', '.', 1))
                       OR e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = $user_id AND status = 'Active'))
            ");
            if (!$checkEv || $checkEv->num_rows === 0) {
                aRespond(false, 'You can only record manual attendance for your assigned club events.');
            }
        }

        $method = 'Manual';

        // Delete existing log if updating/overriding
        $conn->query("DELETE FROM attendance_logs WHERE event_id = $event_id AND user_id = $target_user_id");

        if ($att_status !== 'Absent') {
            $stmt = $conn->prepare(
                "INSERT INTO attendance_logs (event_id, user_id, check_in, method, logged_by, override_reason, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iississ', $event_id, $target_user_id, $check_in, $method, $user_id, $reason, $att_status);
            if (!$stmt->execute()) {
                aRespond(false, 'Failed to log manual attendance: ' . $stmt->error);
            }
            $stmt->close();
        }

        // Fetch student details for response & audit
        $tu = $conn->query("SELECT first_name, last_name FROM users WHERE id = $target_user_id")->fetch_assoc();
        $studentName = trim(($tu['first_name'] ?? '') . ' ' . ($tu['last_name'] ?? ''));

        log_audit($conn, $user_id, 'attendance_manual_override', 'attendance_logs', $target_user_id,
            "Manual attendance ($att_status) recorded for {$studentName} (#$target_user_id) event #$event_id. Reason: $reason");

        aRespond(true, "Manual attendance ($att_status) saved successfully for {$studentName}!", [
            'student_name' => $studentName,
            'status'       => $att_status
        ]);
    }

    // ── ADMIN / SSC ATTENDANCE OVERRIDE ───────────────────────
    case 'admin_override': {
        if (!in_array($user_role, ['admin', 'ssc'])) {
            aRespond(false, 'Only Administrators and SSC Officers can perform attendance overrides.');
        }

        $target_user_id = (int)($_POST['user_id'] ?? 0);
        $event_id       = (int)($_POST['event_id'] ?? 0);
        $new_status     = trim($_POST['status'] ?? 'Present');
        $reason         = trim($_POST['reason'] ?? '');

        if ($target_user_id <= 0 || $event_id <= 0) aRespond(false, 'User and event are required.');
        if (strlen($reason) < 4) aRespond(false, 'A justification reason is required for administrative overrides.');

        if (!in_array($new_status, ['Present', 'Late', 'Excused', 'Absent'])) {
            aRespond(false, 'Invalid attendance status.');
        }

        $conn->query("DELETE FROM attendance_logs WHERE event_id = $event_id AND user_id = $target_user_id");

        if ($new_status !== 'Absent') {
            $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, check_in, method, logged_by, override_reason, status)
                                    VALUES (?, ?, NOW(), 'Manual', ?, ?, ?)");
            $stmt->bind_param('iiiss', $event_id, $target_user_id, $user_id, $reason, $new_status);
            $stmt->execute();
            $stmt->close();
        }

        log_audit($conn, $user_id, 'attendance_override', 'attendance_logs', $target_user_id,
            "Administrative override status to {$new_status} for student #$target_user_id on event #$event_id. Reason: $reason");

        aRespond(true, "Attendance updated to {$new_status} successfully.");
    }

    // ── ANALYTICS summary ────────────────────────────────────
    case 'analytics': {
        if (!in_array($user_role, ['ssc','admin','club_adviser']))
            aRespond(false, 'Not authorized.');

        $total_events  = (int)$conn->query("SELECT COUNT(*) FROM events WHERE status IN ('Approved','Completed')")->fetch_row()[0];
        $total_logs    = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs")->fetch_row()[0];
        $unique_users  = (int)$conn->query("SELECT COUNT(DISTINCT user_id) FROM attendance_logs")->fetch_row()[0];
        $avg_per_event = $total_events > 0 ? round($total_logs / $total_events, 1) : 0;

        // Per-event breakdown
        $breakdown = $conn->query(
            "SELECT e.title, e.event_date,
                    COUNT(al.id) AS attendee_count
             FROM events e
             LEFT JOIN attendance_logs al ON al.event_id = e.id
             WHERE e.status IN ('Approved','Completed')
             GROUP BY e.id
             ORDER BY e.event_date DESC
             LIMIT 10"
        )->fetch_all(MYSQLI_ASSOC);

        aRespond(true, 'OK', [
            'total_events'  => $total_events,
            'total_logs'    => $total_logs,
            'unique_users'  => $unique_users,
            'avg_per_event' => $avg_per_event,
            'breakdown'     => $breakdown,
        ]);
    }

    default:
        aRespond(false, 'Unknown action.');
    }
} elseif (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'attendance_actions.php') {
    aRespond(false, 'No action specified.');
}

