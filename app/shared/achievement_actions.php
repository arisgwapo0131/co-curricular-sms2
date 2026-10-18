<?php
// ============================================================
//  ACHIEVEMENT_ACTIONS.PHP — Achievements AJAX handler
//  Actions: list, submit, verify, reject
// ============================================================
header('Content-Type: application/json');
session_start();
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

function achRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra)); exit;
}

switch ($action) {

    // ── LIST achievements ────────────────────────────────────
    case 'list': {
        $where  = '';
        $params = [];
        $types  = '';

        if ($user_role === 'student') {
            $where  = 'WHERE a.submitted_by = ? AND a.status = "Verified"';
            $params = [$user_id];
            $types  = 'i';
        } elseif ($user_role === 'club_adviser') {
            // Adviser sees their club
            $sess_user = $_SESSION['username'] ?? '';
            $cm = $conn->prepare("SELECT id FROM clubs WHERE (id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active') OR code=UPPER(SUBSTRING_INDEX(?, '.', 1))) AND status='Active' LIMIT 1");
            $cm->bind_param('is', $user_id, $sess_user);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
            if (!empty($club_id)) { $where = "WHERE a.club_id = ?"; $params = [(int)$club_id]; $types = 'i'; }
            else { $where = "WHERE 1=0"; }
        }

        $sql = "SELECT a.id, a.title, a.competition, a.award_date, a.status, a.notes, a.proof_file, a.created_at,
                       c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name
                FROM achievements a
                JOIN clubs c ON c.id = a.club_id
                JOIN users u ON u.id = a.submitted_by
                $where
                ORDER BY a.award_date DESC";
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        achRespond(true, 'OK', ['achievements' => $rows]);
    }

    // ── LIST pending for SSC/Admin ────────────────────────────
    case 'list_pending': {
        if (!in_array($user_role, ['ssc','admin'])) achRespond(false, 'Not authorized.');
        $rows = $conn->query(
            "SELECT a.id, a.title, a.competition, a.award_date, a.proof_file, a.created_at,
                    c.name AS club_name, u.first_name, u.last_name
             FROM achievements a
             JOIN clubs c ON c.id = a.club_id
             JOIN users u ON u.id = a.submitted_by
             WHERE a.status = 'Pending'
             ORDER BY a.created_at ASC"
        )->fetch_all(MYSQLI_ASSOC);
        achRespond(true, 'OK', ['pending' => $rows]);
    }

    // ── SUBMIT achievement ───────────────────────────────────
    case 'submit': {
        if (!in_array($user_role, ['student','club_adviser','ssc','admin'])) achRespond(false, 'Not authorized to submit.');

        $title       = trim($_POST['title']       ?? '');
        $competition = trim($_POST['competition'] ?? '');
        $award_date  = trim($_POST['award_date']  ?? '');

        if (!$title || !$competition || !$award_date) achRespond(false, 'Title, competition, and award date are required.');

        // Get club_id — prefer posted value, fall back to membership or adviser club
        $club_id = (int)($_POST['club_id'] ?? 0);
        if (!$club_id) {
            $sess_user = $_SESSION['username'] ?? '';
            $cm = $conn->prepare("SELECT id FROM clubs WHERE (id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active') OR code=UPPER(SUBSTRING_INDEX(?, '.', 1))) AND status='Active' LIMIT 1");
            $cm->bind_param('is', $user_id, $sess_user);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
        }
        if (!$club_id) achRespond(false, 'You must belong to a club to submit an achievement. Please select one.');

        // Handle file upload
        $proof_file = null;
        if (!empty($_FILES['proof_file']['name']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $uploads_dir = __DIR__ . '/../uploads/achievements/';
            if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);
            $ext  = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg','jpeg','png','pdf','webp'];
            if (!in_array($ext, $allowed_exts, true)) achRespond(false, 'Invalid file type. Allowed: JPG, PNG, PDF, WEBP.');
            if ($_FILES['proof_file']['size'] > 5 * 1024 * 1024) achRespond(false, 'File too large (max 5MB).');

            $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['proof_file']['tmp_name']);
                finfo_close($finfo);
            } elseif (function_exists('mime_content_type')) {
                $mime = mime_content_type($_FILES['proof_file']['tmp_name']);
            }
            if (!empty($mime) && !in_array($mime, $allowed_mimes, true)) {
                achRespond(false, 'Invalid file content: only genuine images and PDFs are accepted.');
            }

            $fname = 'ach_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['proof_file']['tmp_name'], $uploads_dir . $fname))
                achRespond(false, 'File upload failed.');
            $proof_file = $fname;
        }

        $stmt = $conn->prepare(
            "INSERT INTO achievements (club_id, submitted_by, title, competition, award_date, proof_file, status)
             VALUES (?, ?, ?, ?, ?, ?, 'Pending')"
        );
        $stmt->bind_param('iissss', $club_id, $user_id, $title, $competition, $award_date, $proof_file);
        if (!$stmt->execute()) achRespond(false, 'Failed to submit: ' . $stmt->error);
        $new_id = $conn->insert_id;
        $stmt->close();

        // Notify SSC officers
        $sscs = $conn->query("SELECT id FROM users WHERE role = 'ssc'");
        while ($o = $sscs->fetch_assoc()) {
            push_notification($conn, (int)$o['id'], 'New Achievement Submission',
                "A new achievement \"$title\" from " . ($_SESSION['first_name']??'') . " was submitted for verification.", 'achievement');
        }
        log_audit($conn, $user_id, 'achievement_submit', 'achievements', $new_id, "Submitted: $title");
        achRespond(true, 'Achievement submitted for SSC verification.', ['id' => $new_id]);
    }

    // ── VERIFY achievement (SSC / Admin) ─────────────────────
    case 'verify': {
        if (!in_array($user_role, ['ssc','admin'])) achRespond(false, 'Only SSC Officers can verify achievements.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');

        $stmt = $conn->prepare("UPDATE achievements SET status='Verified', verified_by=?, notes=? WHERE id=?");
        $note = trim($_POST['notes'] ?? 'Verified and endorsed by SSC.');
        $stmt->bind_param('isi', $user_id, $note, $id);
        if (!$stmt->execute()) achRespond(false, 'Failed to verify.');
        $stmt->close();

        $ach = $conn->query("SELECT submitted_by, title FROM achievements WHERE id=$id")->fetch_assoc();
        if ($ach) {
            push_notification($conn, (int)$ach['submitted_by'], 'Achievement Verified! 🏆',
                "Your achievement \"{$ach['title']}\" has been verified and endorsed by the SSC!", 'success');
        }
        log_audit($conn, $user_id, 'achievement_verify', 'achievements', $id, "Verified #$id");
        achRespond(true, 'Achievement verified and endorsed.');
    }

    // ── REJECT achievement (SSC / Admin) ─────────────────────
    case 'reject': {
        if (!in_array($user_role, ['ssc','admin'])) achRespond(false, 'Not authorized.');
        $id   = (int)($_POST['id']    ?? 0);
        $note = trim($_POST['notes']  ?? 'Please provide additional documentation.');
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');

        $stmt = $conn->prepare("UPDATE achievements SET status='Rejected', verified_by=?, notes=? WHERE id=?");
        $stmt->bind_param('isi', $user_id, $note, $id);
        $stmt->execute();
        $stmt->close();

        $ach = $conn->query("SELECT submitted_by, title FROM achievements WHERE id=$id")->fetch_assoc();
        if ($ach) {
            push_notification($conn, (int)$ach['submitted_by'], 'Achievement Info Requested',
                "Your achievement \"{$ach['title']}\" needs more info. Notes: $note", 'warning');
        }
        log_audit($conn, $user_id, 'achievement_reject', 'achievements', $id, "Rejected #$id: $note");
        achRespond(true, 'Achievement returned for additional info.');
    }

    // ── CLARIFY achievement (SSC / Admin) ────────────────────
    case 'clarify': {
        if (!in_array($user_role, ['ssc','admin'])) achRespond(false, 'Not authorized.');
        $id   = (int)($_POST['id']    ?? 0);
        $note = trim($_POST['notes']  ?? '');
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');
        if (empty($note)) achRespond(false, 'Please provide clarification instructions or remarks.');

        $stmt = $conn->prepare("UPDATE achievements SET notes=?, verified_by=? WHERE id=?");
        $stmt->bind_param('sii', $note, $user_id, $id);
        if (!$stmt->execute()) achRespond(false, 'Failed to update clarification notes.');
        $stmt->close();

        $ach = $conn->query("SELECT submitted_by, title FROM achievements WHERE id=$id")->fetch_assoc();
        if ($ach) {
            push_notification($conn, (int)$ach['submitted_by'], 'Achievement Clarification Requested',
                "SSC requested clarification on \"{$ach['title']}\": $note", 'warning');
        }
        log_audit($conn, $user_id, 'achievement_clarify', 'achievements', $id, "Clarify #$id: $note");
        achRespond(true, 'Clarification request saved and sent to requester.');
    }

    // ── GET DETAILS (View Proof / Modal) ──────────────────────
    case 'get_details': {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');

        $stmt = $conn->prepare(
            "SELECT a.id, a.title, a.competition, a.award_date, a.proof_file, a.status, a.notes, a.created_at,
                    c.name AS club_name, c.code AS club_code,
                    u.first_name AS sub_first, u.last_name AS sub_last, u.role AS sub_role,
                    v.first_name AS ver_first, v.last_name AS ver_last, v.role AS ver_role
             FROM achievements a
             JOIN clubs c ON c.id = a.club_id
             JOIN users u ON u.id = a.submitted_by
             LEFT JOIN users v ON v.id = a.verified_by
             WHERE a.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ach = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ach) achRespond(false, 'Achievement not found.');
        achRespond(true, 'OK', ['achievement' => $ach]);
    }

    default:
        achRespond(false, 'Unknown action.');
}
$conn->close();
