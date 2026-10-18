<?php
// ============================================================
//  CLUB_ACTIONS.PHP — Organization & Charters AJAX Handler
//  Actions: list, update_status, update_adviser, create_club
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/security.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// CSRF check on mutating POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function cRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

switch ($action) {

    // ── LIST all clubs with statistics ────────────────────────
    case 'list': {
        $sql = "SELECT c.*, 
                       COUNT(DISTINCT cm.user_id) AS member_count,
                       COUNT(DISTINCT e.id) AS event_count
                FROM clubs c
                LEFT JOIN club_memberships cm ON (cm.club_id = c.id AND cm.status = 'Active')
                LEFT JOIN events e ON (e.club_id = c.id AND e.status IN ('Approved','Upcoming') AND e.deleted_at IS NULL)
                WHERE c.deleted_at IS NULL
                GROUP BY c.id
                ORDER BY c.category, c.name";
        $res = $conn->query($sql);
        $clubs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        cRespond(true, 'OK', ['clubs' => $clubs]);
    }

    // ── UPDATE CLUB STATUS (Admin only) ──────────────────────
    case 'update_status': {
        if ($user_role !== 'admin') {
            cRespond(false, 'Only System Administrators can modify charter status.');
        }

        $club_id = (int)($_POST['club_id'] ?? 0);
        $status  = trim($_POST['status'] ?? '');
        $valid_statuses = ['Active', 'Pending Charter', 'Suspended'];

        if ($club_id <= 0 || !in_array($status, $valid_statuses)) {
            cRespond(false, 'Invalid club ID or status specified.');
        }

        $stmt = $conn->prepare("UPDATE clubs SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $club_id);
        if (!$stmt->execute()) {
            cRespond(false, 'Failed to update club status: ' . $stmt->error);
        }
        $stmt->close();

        $c = $conn->query("SELECT name, code FROM clubs WHERE id = $club_id")->fetch_assoc();
        log_audit($conn, $user_id, 'club_status_update', 'clubs', $club_id, "Updated {$c['code']} charter status to $status");
        cRespond(true, "Charter status for \"{$c['name']}\" updated to \"$status\"!");
    }

    // ── UPDATE ADVISER ASSIGNMENT (Admin only) ────────────────
    case 'update_adviser': {
        if ($user_role !== 'admin') {
            cRespond(false, 'Only System Administrators can assign or change faculty advisers.');
        }

        $club_id      = (int)($_POST['club_id'] ?? 0);
        $adviser_name = trim($_POST['adviser_name'] ?? '');

        if ($club_id <= 0 || empty($adviser_name)) {
            cRespond(false, 'Club and Adviser name are required.');
        }

        $stmt = $conn->prepare("UPDATE clubs SET adviser_name = ? WHERE id = ?");
        $stmt->bind_param('si', $adviser_name, $club_id);
        if (!$stmt->execute()) {
            cRespond(false, 'Failed to update adviser: ' . $stmt->error);
        }
        $stmt->close();

        log_audit($conn, $user_id, 'club_adviser_update', 'clubs', $club_id, "Assigned $adviser_name to club #$club_id");
        cRespond(true, "Faculty adviser assigned successfully!");
    }

    // ── CHARTER NEW ORGANIZATION (SSC Only — Pending Admin Approval) ─────
    case 'create_club': {
        if ($user_role !== 'ssc') {
            cRespond(false, 'Only Supreme Student Council (SSC) Officers can submit new organization charter proposals.');
        }

        $code         = strtoupper(trim($_POST['code'] ?? ''));
        $name         = trim($_POST['name'] ?? '');
        $category     = trim($_POST['category'] ?? 'Academic');
        $sub_category = trim($_POST['sub_category'] ?? '');
        $program      = trim($_POST['program'] ?? 'Institutional');
        $description  = trim($_POST['description'] ?? '');
        $adviser_user_id = !empty($_POST['adviser_user_id']) ? (int)$_POST['adviser_user_id'] : null;
        $adviser_name = trim($_POST['adviser_name'] ?? '');

        if ($adviser_user_id && empty($adviser_name)) {
            $u_stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = ?");
            if ($u_stmt) {
                $u_stmt->bind_param('i', $adviser_user_id);
                $u_stmt->execute();
                $u_row = $u_stmt->get_result()->fetch_assoc();
                if ($u_row) {
                    $adviser_name = $u_row['full_name'];
                }
                $u_stmt->close();
            }
        }

        if (empty($adviser_name)) {
            $adviser_name = 'Unassigned';
        }

        if (empty($sub_category)) {
            if ($category === 'Cultural' || $category === 'Sports') {
                $sub_category = 'Talent Center';
            } elseif ($category === 'Advocacy') {
                $sub_category = 'Campus-Wide Independent Bodies';
            } elseif ($category === 'Religious') {
                $sub_category = 'Spiritual & Faith Formation';
            } else {
                $sub_category = 'Academic Organization';
            }
        }

        $valid_categories = ['Academic', 'Cultural', 'Sports', 'Advocacy', 'Religious'];
        if (!$code || !$name || !in_array($category, $valid_categories)) {
            cRespond(false, 'Please specify valid code, name, and category.');
        }

        // Check if code exists
        $chk = $conn->prepare("SELECT id FROM clubs WHERE code = ? LIMIT 1");
        $chk->bind_param('s', $code);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            cRespond(false, "Organization code \"$code\" is already taken.");
        }
        $chk->close();

        // Organizations created by SSC require Admin approval, so initial status is 'Pending Charter'
        if ($adviser_user_id) {
            $stmt = $conn->prepare("INSERT INTO clubs (code, name, category, sub_category, program, description, adviser_user_id, adviser_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending Charter')");
            $stmt->bind_param('ssssssis', $code, $name, $category, $sub_category, $program, $description, $adviser_user_id, $adviser_name);
        } else {
            $stmt = $conn->prepare("INSERT INTO clubs (code, name, category, sub_category, program, description, adviser_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Charter')");
            $stmt->bind_param('sssssss', $code, $name, $category, $sub_category, $program, $description, $adviser_name);
        }

        if (!$stmt->execute()) {
            cRespond(false, 'Failed to propose organization charter: ' . $stmt->error);
        }
        $new_cid = $stmt->insert_id;
        $stmt->close();

        log_audit($conn, $user_id, 'club_propose', 'clubs', $new_cid, "SSC submitted organization charter proposal for Admin approval: $name ($code)");

        // Notify System Administrators of pending charter proposal
        $adm_res = $conn->query("SELECT id FROM users WHERE role = 'admin'");
        if ($adm_res) {
            while ($adm = $adm_res->fetch_assoc()) {
                push_notification($conn, (int)$adm['id'], 'New Organization Charter Proposal', "The Supreme Student Council (SSC) submitted a charter proposal for \"$name\" ($code) awaiting your review and approval.", 'info', 'clubs', $new_cid, 'club_directory.php', 'High');
            }
        }

        cRespond(true, "Organization charter proposal for \"$name\" ($code) submitted successfully! It is now pending System Administrator review and approval.", ['club_id' => $new_cid]);
    }

    // ── APPROVE ORGANIZATION CHARTER (Admin only) ─────────────
    case 'approve_charter': {
        if ($user_role !== 'admin') {
            cRespond(false, 'Only System Administrators can approve organization charters.');
        }

        $club_id = (int)($_POST['club_id'] ?? 0);
        if ($club_id <= 0) {
            cRespond(false, 'Invalid organization ID.');
        }

        $chk = $conn->query("SELECT id, name, code, status FROM clubs WHERE id = $club_id AND deleted_at IS NULL");
        $c = $chk ? $chk->fetch_assoc() : null;
        if (!$c) {
            cRespond(false, 'Organization record not found.');
        }

        if ($c['status'] === 'Active') {
            cRespond(false, 'This organization is already active and accredited.');
        }

        $stmt = $conn->prepare("UPDATE clubs SET status = 'Active' WHERE id = ?");
        $stmt->bind_param('i', $club_id);
        if (!$stmt->execute()) {
            cRespond(false, 'Failed to approve organization charter: ' . $stmt->error);
        }
        $stmt->close();

        log_audit($conn, $user_id, 'charter_approved', 'clubs', $club_id, "Admin granted official accreditation to {$c['name']} ({$c['code']})");

        // Notify SSC Officers of charter approval
        $ssc_res = $conn->query("SELECT id FROM users WHERE role = 'ssc'");
        if ($ssc_res) {
            while ($s = $ssc_res->fetch_assoc()) {
                push_notification($conn, (int)$s['id'], 'Charter Accreditation Approved', "System Administrator officially approved and granted charter accreditation to \"{$c['name']}\" ({$c['code']}).", 'success', 'clubs', $club_id, 'club_directory.php', 'Normal');
            }
        }

        cRespond(true, "Organization \"{$c['name']}\" ({$c['code']}) has been officially approved and chartered as Active!");
    }

    // ── SOFT DELETE ORGANIZATION (Admin only) ─────────────────
    case 'delete_club': {
        if ($user_role !== 'admin') {
            cRespond(false, 'Only System Administrators can delete or deactivate organizations.');
        }

        $club_id = (int)($_POST['club_id'] ?? 0);
        if ($club_id <= 0) {
            cRespond(false, 'Invalid club ID.');
        }

        $stmt = $conn->prepare("UPDATE clubs SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param('i', $club_id);
        if (!$stmt->execute()) {
            cRespond(false, 'Failed to delete organization: ' . $stmt->error);
        }
        $stmt->close();

        log_audit($conn, $user_id, 'club_delete', 'clubs', $club_id, "Soft-deleted organization #$club_id");
        cRespond(true, "Organization deleted successfully.");
    }

    default:
        cRespond(false, 'Unknown action.');
}
$conn->close();
