<?php
// ============================================================
//  ANNOUNCEMENT_ACTIONS.PHP — Announcement & Communication Backend
//  Role-isolated publishing, publication controls & templates
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

// CSRF check on mutating requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function annRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// Helper: Dispatch notifications to target audience
function dispatchAnnouncementNotifications(mysqli $conn, int $author_id, string $title, string $content, string $priority, string $scope, string $target_group, ?int $club_id): void {
    $type = ($priority === 'Urgent') ? 'warning' : 'info';
    $snippet = mb_strimwidth($content, 0, 160, '...');
    $notif_title = ($scope === 'System') ? "System Notice: $title" : (($scope === 'Council') ? "Council Bulletin: $title" : "Announcement: $title");

    $recipient_ids = [];

    if ($target_group === 'All Campus Users' || str_contains($target_group, 'All students of the school') || str_contains($target_group, 'Public')) {
        $q = $conn->query("SELECT id FROM users WHERE status = 'active' AND id != $author_id");
        if ($q) {
            while ($r = $q->fetch_assoc()) { $recipient_ids[] = (int)$r['id']; }
        }
    } elseif ($target_group === 'All Students' || str_contains($target_group, 'Student Body')) {
        $q = $conn->query("SELECT id FROM users WHERE role = 'student' AND status = 'active' AND id != $author_id");
        if ($q) {
            while ($r = $q->fetch_assoc()) { $recipient_ids[] = (int)$r['id']; }
        }
    } elseif ($target_group === 'All Club Advisers') {
        $q = $conn->query("SELECT id FROM users WHERE role = 'club_adviser' AND status = 'active' AND id != $author_id");
        if ($q) {
            while ($r = $q->fetch_assoc()) { $recipient_ids[] = (int)$r['id']; }
        }
    } elseif ($target_group === 'Supreme Student Council (SSC)' || str_contains($target_group, 'Council Officers')) {
        $q = $conn->query("SELECT id FROM users WHERE role = 'ssc' AND status = 'active' AND id != $author_id");
        if ($q) {
            while ($r = $q->fetch_assoc()) { $recipient_ids[] = (int)$r['id']; }
        }
    } elseif (str_contains($target_group, 'Club Presidents') || str_contains($target_group, 'Officers') || str_contains($target_group, 'Leaders')) {
        $q = $conn->query("SELECT DISTINCT user_id FROM club_memberships WHERE role IN ('President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor', 'PRO', 'Officer') AND status = 'Active' AND user_id != $author_id");
        if ($q) {
            while ($r = $q->fetch_assoc()) { $recipient_ids[] = (int)$r['user_id']; }
        }
    } elseif ($club_id && $club_id > 0) {
        $q = $conn->query("SELECT user_id FROM club_memberships WHERE club_id = $club_id AND status = 'Active' AND user_id != $author_id");
        if ($q) {
            while ($r = $q->fetch_assoc()) { $recipient_ids[] = (int)$r['user_id']; }
        }
    }

    if (!empty($recipient_ids)) {
        $ins = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read) VALUES (?, ?, ?, ?, 0)");
        foreach ($recipient_ids as $uid) {
            $ins->bind_param('isss', $uid, $notif_title, $snippet, $type);
            $ins->execute();
        }
        $ins->close();
    }
}

switch ($action) {

    // ── 1. LIST ANNOUNCEMENTS ──────────────────────────────────
    case 'list': {
        $scope_filter  = trim($_GET['scope'] ?? '');
        $status_filter = trim($_GET['status'] ?? '');
        $cat_filter    = trim($_GET['category'] ?? '');
        $prio_filter   = trim($_GET['priority'] ?? '');
        $search        = trim($_GET['q'] ?? '');

        $where = [];
        $params = [];
        $types = '';

        if ($user_role === 'admin') {
            // Admin can see everything or apply filters
            if (!empty($scope_filter) && $scope_filter !== 'all') {
                $where[] = "a.scope = ?";
                $params[] = $scope_filter;
                $types .= 's';
            }
            if (!empty($status_filter) && $status_filter !== 'all') {
                $where[] = "a.status = ?";
                $params[] = $status_filter;
                $types .= 's';
            }
        } elseif ($user_role === 'ssc') {
            // SSC: Council and Governance communications + System notices
            $where[] = "(a.scope = 'Council' OR a.scope = 'System' OR a.author_id = ?)";
            $params[] = $user_id;
            $types .= 'i';
            $where[] = "a.status = 'Published'";
        } elseif ($user_role === 'club_adviser') {
            // Adviser: Club announcements for assigned clubs + System + Council
            $where[] = "(a.scope = 'Club' OR a.scope = 'System' OR a.scope = 'Council' OR a.author_id = ?)";
            $params[] = $user_id;
            $types .= 'i';
            $where[] = "a.status = 'Published'";
        } else {
            // Student: Only Published announcements
            $where[] = "a.status = 'Published'";
        }

        if (!empty($cat_filter) && $cat_filter !== 'all') {
            $where[] = "a.category = ?";
            $params[] = $cat_filter;
            $types .= 's';
        }
        if (!empty($prio_filter) && $prio_filter !== 'all') {
            $where[] = "a.priority = ?";
            $params[] = $prio_filter;
            $types .= 's';
        }
        if (!empty($search)) {
            $where[] = "(a.title LIKE ? OR a.content LIKE ? OR a.target_group LIKE ?)";
            $s_param = '%' . $search . '%';
            $params[] = $s_param;
            $params[] = $s_param;
            $params[] = $s_param;
            $types .= 'sss';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT a.id, a.club_id, a.scope, a.author_id, a.title, a.category, a.priority, 
                   a.status, a.is_pinned, a.expires_at, a.content, a.target_group, a.channels, a.created_at,
                   c.name AS club_name, c.code AS club_code,
                   u.first_name, u.last_name, u.role AS author_role
            FROM org_announcements a
            LEFT JOIN clubs c ON c.id = a.club_id
            JOIN users u ON u.id = a.author_id
            $where_clause
            ORDER BY a.is_pinned DESC, a.created_at DESC
        ";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        annRespond(true, 'Announcements retrieved.', ['announcements' => $items]);
    }

    // ── 2. CREATE ANNOUNCEMENT ─────────────────────────────────
    case 'create': {
        if (!in_array($user_role, ['admin', 'ssc', 'club_adviser'])) {
            annRespond(false, 'Permission denied: Your role cannot publish announcements.');
        }

        $title        = trim($_POST['title'] ?? '');
        $category     = trim($_POST['category'] ?? 'General');
        $priority     = trim($_POST['priority'] ?? 'Normal');
        $content      = trim($_POST['content'] ?? '');
        $target_group = trim($_POST['target_group'] ?? 'All Campus Users');
        $status       = trim($_POST['status'] ?? 'Published');
        $is_pinned    = !empty($_POST['is_pinned']) ? 1 : 0;
        $expires_at   = !empty($_POST['expires_at']) ? trim($_POST['expires_at']) : null;
        $channels     = trim($_POST['channels'] ?? 'In-App');

        if (empty($title) || empty($content)) {
            annRespond(false, 'Please provide an announcement title and message content.');
        }

        // Determine scope & enforce role isolation
        if ($user_role === 'admin') {
            $scope = trim($_POST['scope'] ?? 'System');
            if (!in_array($scope, ['System', 'Council', 'Club'])) {
                $scope = 'System';
            }
            $club_id = (!empty($_POST['club_id']) && $scope === 'Club') ? (int)$_POST['club_id'] : null;
        } elseif ($user_role === 'ssc') {
            // SSC is strictly for Council & Governance Communications within permitted audience
            $scope   = 'Council';
            $club_id = null;

            $permitted_targets = [
                'Student Body (All Enrolled Students)',
                'Council Officers & Committee Members',
                'Club Presidents & Student Leaders'
            ];
            if (!in_array($target_group, $permitted_targets)) {
                $target_group = 'Student Body (All Enrolled Students)';
            }

            $permitted_cats = [
                'Council Assembly',
                'Governance & Resolutions',
                'Student Elections',
                'Campus Activity',
                'Committee Bulletin',
                'General'
            ];
            if (!in_array($category, $permitted_cats)) {
                $category = 'Council Assembly';
            }
        } else {
            // Club Adviser: strictly organization announcements
            $scope = 'Club';
            $sess_user = $_SESSION['username'] ?? '';
            $cm = $conn->prepare("SELECT id FROM clubs WHERE (id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active') OR code=UPPER(SUBSTRING_INDEX(?, '.', 1))) AND status='Active' LIMIT 1");
            $cm->bind_param('is', $user_id, $sess_user);
            $cm->execute();
            $cm->bind_result($my_cid);
            $cm->fetch();
            $cm->close();
            if (empty($my_cid)) {
                annRespond(false, 'You do not have an active club assignment to publish announcements.');
            }
            $club_id = (int)$my_cid;
        }

        // Publication status check
        if (!in_array($status, ['Published', 'Draft', 'Archived'])) {
            $status = 'Published';
        }

        $stmt = $conn->prepare("
            INSERT INTO org_announcements (club_id, scope, author_id, title, category, priority, status, is_pinned, expires_at, content, target_group, channels)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('isissssissss', $club_id, $scope, $user_id, $title, $category, $priority, $status, $is_pinned, $expires_at, $content, $target_group, $channels);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            // Dispatch in-app notifications if published
            if ($status === 'Published') {
                dispatchAnnouncementNotifications($conn, $user_id, $title, $content, $priority, $scope, $target_group, $club_id);
            }

            annRespond(true, 'Announcement created successfully!', ['id' => $new_id]);
        } else {
            annRespond(false, 'Database error creating announcement: ' . $conn->error);
        }
    }

    // ── 3. UPDATE ANNOUNCEMENT ─────────────────────────────────
    case 'update': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) annRespond(false, 'Announcement ID is required.');

        // Fetch existing
        $chk = $conn->prepare("SELECT author_id, scope FROM org_announcements WHERE id = ?");
        $chk->bind_param('i', $id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$existing) annRespond(false, 'Announcement not found.');

        if ($user_role !== 'admin' && (int)$existing['author_id'] !== $user_id) {
            annRespond(false, 'Permission denied: You can only edit announcements authored by you.');
        }

        $title        = trim($_POST['title'] ?? '');
        $category     = trim($_POST['category'] ?? '');
        $priority     = trim($_POST['priority'] ?? 'Normal');
        $content      = trim($_POST['content'] ?? '');
        $target_group = trim($_POST['target_group'] ?? '');
        $status       = trim($_POST['status'] ?? 'Published');
        $is_pinned    = isset($_POST['is_pinned']) ? (int)$_POST['is_pinned'] : 0;
        $expires_at   = !empty($_POST['expires_at']) ? trim($_POST['expires_at']) : null;
        $channels     = trim($_POST['channels'] ?? 'In-App');

        if (empty($title) || empty($content)) {
            annRespond(false, 'Title and content cannot be blank.');
        }

        $stmt = $conn->prepare("
            UPDATE org_announcements 
            SET title = ?, category = ?, priority = ?, status = ?, is_pinned = ?, expires_at = ?, content = ?, target_group = ?, channels = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssssissssi', $title, $category, $priority, $status, $is_pinned, $expires_at, $content, $target_group, $channels, $id);

        if ($stmt->execute()) {
            $stmt->close();
            annRespond(true, 'Announcement updated successfully.');
        } else {
            annRespond(false, 'Error updating announcement: ' . $conn->error);
        }
    }

    // ── 4. TOGGLE PUBLICATION STATUS (PUBLISH / DRAFT / ARCHIVE)
    case 'toggle_status': {
        $id     = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? 'Published');
        if (!$id) annRespond(false, 'Invalid announcement ID.');
        if (!in_array($status, ['Published', 'Draft', 'Archived'])) {
            annRespond(false, 'Invalid publication status.');
        }

        $chk = $conn->prepare("SELECT author_id FROM org_announcements WHERE id = ?");
        $chk->bind_param('i', $id);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$row) annRespond(false, 'Announcement not found.');
        if ($user_role !== 'admin' && (int)$row['author_id'] !== $user_id) {
            annRespond(false, 'Permission denied: Only administrators or the author can change publication status.');
        }

        $up = $conn->prepare("UPDATE org_announcements SET status = ? WHERE id = ?");
        $up->bind_param('si', $status, $id);
        $up->execute();
        $up->close();

        annRespond(true, "Announcement status updated to $status.");
    }

    // ── 5. TOGGLE PIN TO TOP ────────────────────────────────────
    case 'toggle_pin': {
        if (!in_array($user_role, ['admin', 'ssc'])) {
            annRespond(false, 'Permission denied: Only administrators and council officers can pin announcements.');
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) annRespond(false, 'Invalid announcement ID.');

        $chk = $conn->prepare("SELECT is_pinned FROM org_announcements WHERE id = ?");
        $chk->bind_param('i', $id);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$row) annRespond(false, 'Announcement not found.');
        $new_pin = (int)$row['is_pinned'] === 1 ? 0 : 1;

        $up = $conn->prepare("UPDATE org_announcements SET is_pinned = ? WHERE id = ?");
        $up->bind_param('ii', $new_pin, $id);
        $up->execute();
        $up->close();

        annRespond(true, $new_pin ? 'Announcement pinned to top.' : 'Announcement unpinned.', ['is_pinned' => $new_pin]);
    }

    // ── 6. DELETE ANNOUNCEMENT ─────────────────────────────────
    case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) annRespond(false, 'Invalid announcement ID.');

        $stmt = $conn->prepare("SELECT author_id, scope FROM org_announcements WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) annRespond(false, 'Announcement not found.');

        if ($user_role !== 'admin' && (int)$row['author_id'] !== $user_id) {
            annRespond(false, 'Permission denied: You can only delete announcements authored by you.');
        }

        $del = $conn->prepare("DELETE FROM org_announcements WHERE id = ?");
        $del->bind_param('i', $id);
        if ($del->execute()) {
            $del->close();
            annRespond(true, 'Announcement deleted successfully.');
        } else {
            annRespond(false, 'Failed to delete announcement.');
        }
    }

    // ── 7. LIST NOTIFICATION TEMPLATES ──────────────────────────
    case 'list_templates': {
        $q = $conn->query("SELECT * FROM notification_templates ORDER BY category ASC, title ASC");
        $templates = $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
        annRespond(true, 'Templates loaded.', ['templates' => $templates]);
    }

    // ── 8. SAVE NOTIFICATION TEMPLATE (CREATE / EDIT) ───────────
    case 'save_template': {
        if ($user_role !== 'admin') {
            annRespond(false, 'Permission denied: Only administrators can manage notification templates.');
        }

        $id        = (int)($_POST['id'] ?? 0);
        $code      = strtoupper(trim($_POST['code'] ?? ''));
        $title     = trim($_POST['title'] ?? '');
        $category  = trim($_POST['category'] ?? 'System Notice');
        $subject   = trim($_POST['subject_template'] ?? '');
        $body      = trim($_POST['body_template'] ?? '');
        $priority  = trim($_POST['default_priority'] ?? 'Normal');
        $target    = trim($_POST['default_target'] ?? 'All Campus Users');
        $status    = trim($_POST['status'] ?? 'Active');

        if (empty($title) || empty($subject) || empty($body)) {
            annRespond(false, 'Please provide a template title, subject line, and message body.');
        }

        if (!$id && empty($code)) {
            $code = 'TPL-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $title), 0, 8)) . '-' . rand(100, 999);
        }

        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE notification_templates 
                SET title = ?, category = ?, subject_template = ?, body_template = ?, default_priority = ?, default_target = ?, status = ?
                WHERE id = ?
            ");
            $stmt->bind_param('sssssssi', $title, $category, $subject, $body, $priority, $target, $status, $id);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO notification_templates (code, title, category, subject_template, body_template, default_priority, default_target, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssssssssi', $code, $title, $category, $subject, $body, $priority, $target, $status, $user_id);
        }

        if ($stmt->execute()) {
            $stmt->close();
            annRespond(true, $id ? 'Template updated successfully.' : 'New notification template created.');
        } else {
            annRespond(false, 'Database error saving template: ' . $conn->error);
        }
    }

    // ── 9. DELETE NOTIFICATION TEMPLATE ─────────────────────────
    case 'delete_template': {
        if ($user_role !== 'admin') {
            annRespond(false, 'Permission denied: Only administrators can delete templates.');
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) annRespond(false, 'Invalid template ID.');

        $stmt = $conn->prepare("DELETE FROM notification_templates WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $stmt->close();
            annRespond(true, 'Template deleted successfully.');
        } else {
            annRespond(false, 'Error deleting template: ' . $conn->error);
        }
    }

    default:
        annRespond(false, 'Invalid action specified.');
}
