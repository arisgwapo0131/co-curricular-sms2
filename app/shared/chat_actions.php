<?php
// ============================================================
//  CHAT_ACTIONS.PHP (app/shared/)
//  Backend AJAX Controller for Inter-Club Communication & Messenger
//  Role-Based Permission Matrix:
//   - Student: can message Club Officers & Advisers
//   - Adviser: can message Club Officers, Advisers, & SSC
//   - SSC:     can message Club Officers, Advisers, & Admin
//   - Admin:   can message Club Officers, Advisers, & SSC
// ============================================================
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notification_actions.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';

// CSRF validation on mutating POST requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (php_sapi_name() !== 'cli' && !empty($_POST)) {
        verify_csrf();
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function chatRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

/**
 * Auto-deduplicates chat_groups so duplicate NULL club_id or (club_id, group_type) rows never appear
 */
function deduplicateChatGroups(mysqli $conn): void {
    static $deduped = false;
    if ($deduped) return;
    $deduped = true;

    try {
        // 1. Merge any duplicate 'advisers_ssc' rows into the lowest ID
        $res = $conn->query("SELECT id FROM chat_groups WHERE group_type = 'advisers_ssc' ORDER BY id ASC");
        $primaryId = null;
        $dupIds = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if ($primaryId === null) {
                    $primaryId = (int)$r['id'];
                } else {
                    $dupIds[] = (int)$r['id'];
                }
            }
        }
        if ($primaryId === null) {
            $conn->query("
                INSERT INTO `chat_groups` (`club_id`, `group_type`, `name`, `description`, `icon`)
                VALUES (NULL, 'advisers_ssc', 'Campus Advisers & Supreme Student Council', 'Official inter-communication channel for all club faculty advisers and the SSC leadership.', 'fa-building-columns')
            ");
        } elseif (!empty($dupIds)) {
            $dupList = implode(',', $dupIds);
            $conn->query("UPDATE chat_messages SET group_id = {$primaryId} WHERE group_id IN ({$dupList})");
            $conn->query("DELETE FROM chat_group_reads WHERE group_id IN ({$dupList})");
            $conn->query("DELETE FROM chat_groups WHERE id IN ({$dupList})");
        }

        // 2. Remove any duplicate (club_id, group_type) rows keeping the lowest ID
        $conn->query("
            DELETE g1 FROM chat_groups g1
            INNER JOIN chat_groups g2
              ON COALESCE(g1.club_id, 0) = COALESCE(g2.club_id, 0)
             AND g1.group_type = g2.group_type
             AND g1.id > g2.id
        ");
    } catch (Throwable $e) {
        // Ignore if table not yet created
    }
}
deduplicateChatGroups($conn);

/**
 * Checks whether senderRole is allowed to initiate/send communication to recipientId
 */
function isCommunicationAllowed(string $senderRole, int $recipientId, mysqli $conn): bool {
    $stmt = $conn->prepare("SELECT role, status FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $recipientId);
    $stmt->execute();
    $recip = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$recip || $recip['status'] !== 'Active') return false;

    $recipRole = $recip['role'];

    // Check if recipient is an active club officer
    $isOfficer = false;
    if ($recipRole === 'student') {
        $cmStmt = $conn->prepare("SELECT id FROM club_memberships WHERE user_id = ? AND status = 'Active' AND role NOT IN ('Member', 'Adviser') LIMIT 1");
        if ($cmStmt) {
            $cmStmt->bind_param('i', $recipientId);
            $cmStmt->execute();
            $cmRes = $cmStmt->get_result();
            $isOfficer = ($cmRes && $cmRes->num_rows > 0);
            $cmStmt->close();
        }
    }

    // Role-based communication rules
    switch ($senderRole) {
        case 'student':
            // Student can message Club Officers and Advisers
            return ($recipRole === 'club_adviser') || ($recipRole === 'student' && $isOfficer);

        case 'club_adviser':
            // Adviser can message Club Officers, Advisers, SSC, Staff, and Admin
            return in_array($recipRole, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'], true) || ($recipRole === 'student' && $isOfficer);

        case 'ssc':
            // SSC can message Club Officers, Advisers, SSC, Staff, and Admin
            return in_array($recipRole, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'], true) || ($recipRole === 'student' && $isOfficer);

        case 'staff':
        case 'admin':
        case 'super_admin':
            // Admin / Staff / Super Admin can message Club Officers, Advisers, SSC, Staff, and Admin
            return in_array($recipRole, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'], true) || ($recipRole === 'student' && $isOfficer);

        default:
            return false;
    }
}

/**
 * Resolves the authorized chat group IDs for a user based on role and active memberships
 */
function getUserAuthorizedGroupIds(int $userId, string $userRole, mysqli $conn): array {
    $groupIds = [];

    // 1. Campus Advisers & SSC Council GC: eligible for club_adviser, ssc, staff, admin, super_admin
    if (in_array($userRole, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'], true)) {
        $res = $conn->query("SELECT MIN(id) AS id FROM chat_groups WHERE group_type = 'advisers_ssc'");
        if ($res && $r = $res->fetch_assoc()) {
            if (!empty($r['id'])) {
                $groupIds[] = (int)$r['id'];
            }
        }
    }

    $isAdminLike = in_array($userRole, ['admin', 'super_admin', 'staff'], true) ? 1 : 0;

    // 2. Club General GC (Adviser + Officers + All Members of the specific club)
    $sqlGen = "
        SELECT MIN(cg.id) AS id
        FROM chat_groups cg
        JOIN clubs c ON c.id = cg.club_id
        LEFT JOIN club_memberships cm ON cm.club_id = cg.club_id AND cm.user_id = {$userId} AND cm.status = 'Active'
        WHERE cg.group_type = 'club_general'
          AND (
            cm.id IS NOT NULL 
            OR c.adviser_user_id = {$userId}
            OR ('{$userRole}' = 'club_adviser' AND EXISTS (SELECT 1 FROM club_memberships adv_cm WHERE adv_cm.club_id = cg.club_id AND adv_cm.user_id = {$userId} AND adv_cm.role = 'Adviser'))
            OR {$isAdminLike} = 1
          )
        GROUP BY cg.club_id
    ";
    $resGen = $conn->query($sqlGen);
    if ($resGen) {
        while ($r = $resGen->fetch_assoc()) {
            if (!empty($r['id'])) $groupIds[] = (int)$r['id'];
        }
    }

    // 3. Club Executive GC (Club Adviser + Student Officers of that club only)
    $sqlExec = "
        SELECT MIN(cg.id) AS id
        FROM chat_groups cg
        JOIN clubs c ON c.id = cg.club_id
        LEFT JOIN club_memberships cm ON cm.club_id = cg.club_id AND cm.user_id = {$userId} AND cm.status = 'Active' AND cm.role NOT IN ('Member', 'Adviser')
        WHERE cg.group_type = 'club_executive'
          AND (
            cm.id IS NOT NULL 
            OR c.adviser_user_id = {$userId}
            OR ('{$userRole}' = 'club_adviser' AND EXISTS (SELECT 1 FROM club_memberships adv_cm WHERE adv_cm.club_id = cg.club_id AND adv_cm.user_id = {$userId} AND adv_cm.role = 'Adviser'))
            OR {$isAdminLike} = 1
          )
        GROUP BY cg.club_id
    ";
    $resExec = $conn->query($sqlExec);
    if ($resExec) {
        while ($r = $resExec->fetch_assoc()) {
            if (!empty($r['id'])) $groupIds[] = (int)$r['id'];
        }
    }

    return array_values(array_unique($groupIds));
}

/**
 * Checks whether a user has permission to view/participate in a specific chat group
 */
function isUserInGroup(int $userId, string $userRole, int $groupId, mysqli $conn): bool {
    $authorized = getUserAuthorizedGroupIds($userId, $userRole, $conn);
    return in_array($groupId, $authorized, true);
}

/**
 * Retrieves the member roster for a specific chat group
 */
function getGroupMembers(int $groupId, mysqli $conn): array {
    $stmt = $conn->prepare("SELECT cg.*, c.code AS club_code, c.name AS club_name, c.adviser_user_id FROM chat_groups cg LEFT JOIN clubs c ON c.id = cg.club_id WHERE cg.id = ? LIMIT 1");
    if (!$stmt) return [];
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$group) return [];

    $members = [];

    if ($group['group_type'] === 'advisers_ssc') {
        $res = $conn->query("
            SELECT u.id, u.first_name, u.last_name, u.username, u.role, u.profile_pic,
                   CASE 
                     WHEN u.role = 'club_adviser' THEN CONCAT('Faculty Adviser • ', COALESCE(c.code, 'Faculty'))
                     WHEN u.role = 'ssc' THEN 'Supreme Student Council'
                     WHEN u.role = 'staff' THEN 'OSA Staff'
                     WHEN u.role = 'super_admin' THEN 'Super Administrator'
                     WHEN u.role = 'admin' THEN 'System Administrator'
                     ELSE 'Institutional Member'
                   END AS role_badge,
                   COALESCE(c.name, 'Campus Executive Council') AS org_title
            FROM users u
            LEFT JOIN club_memberships cm ON cm.user_id = u.id AND cm.role = 'Adviser' AND cm.status = 'Active'
            LEFT JOIN clubs c ON c.id = cm.club_id
            WHERE u.role IN ('club_adviser', 'ssc', 'staff', 'admin', 'super_admin') AND u.status = 'Active'
            GROUP BY u.id
            ORDER BY 
              CASE WHEN u.role = 'ssc' THEN 1 WHEN u.role = 'club_adviser' THEN 2 ELSE 3 END,
              u.last_name
        ");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $members[] = [
                    'id'             => (int)$r['id'],
                    'name'           => htmlspecialchars($r['first_name'] . ' ' . $r['last_name']),
                    'username'       => htmlspecialchars($r['username']),
                    'role'           => $r['role'],
                    'role_badge'     => $r['role_badge'],
                    'org_title'      => $r['org_title'],
                    'avatar_initial' => strtoupper(substr($r['first_name'] ?: 'U', 0, 1)),
                    'profile_pic'    => $r['profile_pic'],
                ];
            }
        }
    } elseif ($group['group_type'] === 'club_executive') {
        $cid = (int)$group['club_id'];
        $res = $conn->query("
            SELECT u.id, u.first_name, u.last_name, u.username, u.role, u.profile_pic,
                   cm.role AS pos,
                   CASE 
                     WHEN cm.role = 'Adviser' OR c.adviser_user_id = u.id THEN 'Faculty Adviser'
                     ELSE CONCAT('Officer • ', cm.role)
                   END AS role_badge,
                   c.name AS org_title
            FROM club_memberships cm
            JOIN clubs c ON c.id = cm.club_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.club_id = {$cid} AND cm.status = 'Active' AND cm.role NOT IN ('Member') AND u.status = 'Active'
            GROUP BY u.id
            ORDER BY CASE WHEN cm.role = 'Adviser' THEN 1 ELSE 2 END, u.last_name
        ");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $members[] = [
                    'id'             => (int)$r['id'],
                    'name'           => htmlspecialchars($r['first_name'] . ' ' . $r['last_name']),
                    'username'       => htmlspecialchars($r['username']),
                    'role'           => $r['role'],
                    'role_badge'     => $r['role_badge'],
                    'org_title'      => $r['org_title'],
                    'avatar_initial' => strtoupper(substr($r['first_name'] ?: 'U', 0, 1)),
                    'profile_pic'    => $r['profile_pic'],
                ];
            }
        }
    } else { // club_general
        $cid = (int)$group['club_id'];
        $res = $conn->query("
            SELECT u.id, u.first_name, u.last_name, u.username, u.role, u.profile_pic,
                   cm.role AS pos,
                   CASE 
                     WHEN cm.role = 'Adviser' OR c.adviser_user_id = u.id THEN 'Faculty Adviser'
                     WHEN cm.role = 'Member' THEN 'General Member'
                     ELSE CONCAT('Officer • ', cm.role)
                   END AS role_badge,
                   c.name AS org_title
            FROM club_memberships cm
            JOIN clubs c ON c.id = cm.club_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.club_id = {$cid} AND cm.status = 'Active' AND u.status = 'Active'
            GROUP BY u.id
            ORDER BY CASE WHEN cm.role = 'Adviser' THEN 1 WHEN cm.role != 'Member' THEN 2 ELSE 3 END, u.last_name
        ");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $members[] = [
                    'id'             => (int)$r['id'],
                    'name'           => htmlspecialchars($r['first_name'] . ' ' . $r['last_name']),
                    'username'       => htmlspecialchars($r['username']),
                    'role'           => $r['role'],
                    'role_badge'     => $r['role_badge'],
                    'org_title'      => $r['org_title'],
                    'avatar_initial' => strtoupper(substr($r['first_name'] ?: 'U', 0, 1)),
                    'profile_pic'    => $r['profile_pic'],
                ];
            }
        }
    }

    return $members;
}

/**
 * Format relative time (e.g. "Just now", "5m ago", "2h ago", "Yesterday", "Oct 05")
 */
function formatChatTime(string $datetime): string {
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'Yesterday';
    return date('M d', $ts);
}

switch ($action) {

    // ── 0. GET AUTHORIZED GROUP CHATS (Club General, Club Executive, Advisers & SSC) ──
    case 'get_groups': {
        $authGroupIds = getUserAuthorizedGroupIds($user_id, $user_role, $conn);
        if (empty($authGroupIds)) {
            chatRespond(true, 'No groups available.', ['groups' => []]);
        }

        $idList = implode(',', array_map('intval', $authGroupIds));
        $sql = "
            SELECT 
                cg.id,
                cg.club_id,
                cg.group_type,
                cg.name,
                cg.description,
                cg.icon,
                c.code AS club_code,
                c.name AS club_name,
                (SELECT message FROM chat_messages WHERE group_id = cg.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT created_at FROM chat_messages WHERE group_id = cg.id ORDER BY created_at DESC LIMIT 1) AS last_message_time,
                (SELECT CONCAT(u.first_name, ' ', u.last_name) FROM chat_messages m JOIN users u ON u.id = m.sender_id WHERE m.group_id = cg.id ORDER BY m.created_at DESC LIMIT 1) AS last_sender_name,
                (SELECT COUNT(*) FROM chat_messages m
                 LEFT JOIN chat_group_reads r ON r.group_id = cg.id AND r.user_id = {$user_id}
                 WHERE m.group_id = cg.id AND m.sender_id != {$user_id}
                   AND (r.last_read_message_id IS NULL OR m.id > r.last_read_message_id)) AS unread_count
            FROM chat_groups cg
            LEFT JOIN clubs c ON c.id = cg.club_id
            WHERE cg.id IN ({$idList})
            ORDER BY 
              CASE 
                WHEN cg.group_type = 'club_general' THEN 1 
                WHEN cg.group_type = 'club_executive' THEN 2 
                ELSE 3 
              END,
              last_message_time DESC,
              cg.name ASC
        ";

        $res = $conn->query($sql);
        $groups = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $gid = (int)$row['id'];
                $members = getGroupMembers($gid, $conn);
                $member_count = count($members);

                $type_label = match($row['group_type']) {
                    'club_general'   => 'Club General Chat',
                    'club_executive' => 'Executive Board',
                    'advisers_ssc'   => 'Advisers & SSC Council',
                    default          => 'Group Chat',
                };

                $type_badge_class = match($row['group_type']) {
                    'club_general'   => 'gc-type-general',
                    'club_executive' => 'gc-type-exec',
                    'advisers_ssc'   => 'gc-type-ssc',
                    default          => 'gc-type-default',
                };

                $initial = strtoupper(substr($row['club_code'] ?: ($row['name'] ?: 'G'), 0, 1));

                $groups[] = [
                    'id'                 => $gid,
                    'club_id'            => $row['club_id'] ? (int)$row['club_id'] : null,
                    'group_type'         => $row['group_type'],
                    'type_label'         => $type_label,
                    'type_badge_class'   => $type_badge_class,
                    'name'               => htmlspecialchars($row['name']),
                    'description'        => htmlspecialchars($row['description'] ?? ''),
                    'icon'               => $row['icon'] ?: 'fa-users',
                    'club_code'          => $row['club_code'] ?? 'CAMPUS',
                    'club_name'          => $row['club_name'] ?? 'Multi-Club',
                    'avatar_initial'     => $initial,
                    'member_count'       => $member_count,
                    'last_message'       => htmlspecialchars($row['last_message'] ?? ''),
                    'last_sender_name'   => htmlspecialchars($row['last_sender_name'] ?? ''),
                    'last_message_time'  => !empty($row['last_message_time']) ? formatChatTime($row['last_message_time']) : '',
                    'unread_count'       => (int)$row['unread_count'],
                ];
            }
        }

        chatRespond(true, 'Groups loaded.', ['groups' => $groups]);
        break;
    }

    // ── 1. GET CONVERSATIONS ──────────────────────────────────────
    case 'get_conversations': {
        // Query distinct conversation partners for current user
        $sql = "
            SELECT 
                u.id AS partner_id,
                u.first_name,
                u.last_name,
                u.username,
                u.role,
                u.profile_pic,
                (SELECT message FROM chat_messages 
                 WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id) 
                 ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT created_at FROM chat_messages 
                 WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id) 
                 ORDER BY created_at DESC LIMIT 1) AS last_message_time,
                (SELECT COUNT(*) FROM chat_messages 
                 WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0) AS unread_count,
                (SELECT CONCAT(cm.role, ' • ', c.code) 
                 FROM club_memberships cm 
                 JOIN clubs c ON c.id = cm.club_id 
                 WHERE cm.user_id = u.id AND cm.status = 'Active' AND cm.role NOT IN ('Member') 
                 LIMIT 1) AS officer_org_badge
            FROM users u
            WHERE u.id IN (
                SELECT DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END
                FROM chat_messages
                WHERE sender_id = ? OR receiver_id = ?
            )
            ORDER BY last_message_time DESC
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            chatRespond(false, 'Database query error: ' . $conn->error);
        }
        $stmt->bind_param('iiiiiiii', $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result();

        $conversations = [];
        while ($row = $res->fetch_assoc()) {
            $role_badge = match($row['role']) {
                'super_admin'  => 'Super Administrator',
                'admin'        => 'System Administrator',
                'staff'        => 'OSA Staff',
                'ssc'          => 'Supreme Student Council',
                'club_adviser' => (!empty($row['officer_org_badge']) ? $row['officer_org_badge'] : 'Faculty Adviser'),
                default        => (!empty($row['officer_org_badge']) ? $row['officer_org_badge'] : 'Club Officer'),
            };

            $conversations[] = [
                'partner_id'        => (int)$row['partner_id'],
                'full_name'         => htmlspecialchars($row['first_name'] . ' ' . $row['last_name']),
                'username'          => htmlspecialchars($row['username']),
                'role'              => $row['role'],
                'role_badge'        => $role_badge,
                'avatar_initial'    => strtoupper(substr($row['first_name'] ?: 'U', 0, 1)),
                'profile_pic'       => $row['profile_pic'],
                'last_message'      => htmlspecialchars($row['last_message'] ?? ''),
                'last_message_time' => !empty($row['last_message_time']) ? formatChatTime($row['last_message_time']) : '',
                'unread_count'      => (int)$row['unread_count'],
            ];
        }
        $stmt->close();

        chatRespond(true, 'Conversations loaded.', ['conversations' => $conversations]);
        break;
    }

    // ── 2. GET CONTACTS DIRECTORY (Strictly Filtered by Role Matrix) ──
    case 'get_contacts': {
        $contacts = [];

        // Build list based on sender's role
        // 1. Club Officers (Always eligible for all roles)
        $officers_q = $conn->query("
            SELECT u.id, u.first_name, u.last_name, u.username, u.role, u.profile_pic,
                   cm.role AS officer_position, c.name AS club_name, c.code AS club_code
            FROM users u
            JOIN club_memberships cm ON cm.user_id = u.id
            JOIN clubs c ON c.id = cm.club_id
            WHERE u.id != {$user_id}
              AND u.role = 'student'
              AND u.status = 'Active'
              AND cm.status = 'Active'
              AND cm.role NOT IN ('Member', 'Adviser')
            ORDER BY c.code, u.last_name
        ");
        if ($officers_q) {
            while ($r = $officers_q->fetch_assoc()) {
                $contacts[] = [
                    'id'             => (int)$r['id'],
                    'name'           => $r['first_name'] . ' ' . $r['last_name'],
                    'username'       => $r['username'],
                    'category'       => 'Club Officers',
                    'role_label'     => $r['officer_position'] . ' • ' . $r['club_code'],
                    'club_name'      => $r['club_name'],
                    'avatar_initial' => strtoupper(substr($r['first_name'] ?: 'O', 0, 1)),
                    'profile_pic'    => $r['profile_pic'],
                ];
            }
        }

        // 2. Club Advisers (Eligible for all roles)
        $advisers_q = $conn->query("
            SELECT u.id, u.first_name, u.last_name, u.username, u.role, u.profile_pic,
                   c.code AS club_code, c.name AS club_name
            FROM users u
            LEFT JOIN club_memberships cm ON cm.user_id = u.id AND cm.role = 'Adviser' AND cm.status = 'Active'
            LEFT JOIN clubs c ON c.id = cm.club_id
            WHERE u.id != {$user_id}
              AND u.role = 'club_adviser'
              AND u.status = 'Active'
            GROUP BY u.id
            ORDER BY u.last_name
        ");
        if ($advisers_q) {
            while ($r = $advisers_q->fetch_assoc()) {
                $org_tag = !empty($r['club_code']) ? 'Adviser • ' . $r['club_code'] : 'Faculty Adviser';
                $contacts[] = [
                    'id'             => (int)$r['id'],
                    'name'           => 'Prof. ' . $r['first_name'] . ' ' . $r['last_name'],
                    'username'       => $r['username'],
                    'category'       => 'Faculty Advisers',
                    'role_label'     => $org_tag,
                    'club_name'      => $r['club_name'] ?? 'Faculty Board',
                    'avatar_initial' => strtoupper(substr($r['first_name'] ?: 'A', 0, 1)),
                    'profile_pic'    => $r['profile_pic'],
                ];
            }
        }

        // 3. Supreme Student Council (Eligible for Adviser, SSC, Staff, Admin, Super Admin)
        if (in_array($user_role, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'], true)) {
            $ssc_q = $conn->query("
                SELECT id, first_name, last_name, username, role, profile_pic
                FROM users
                WHERE id != {$user_id}
                  AND role = 'ssc'
                  AND status = 'Active'
                ORDER BY last_name
            ");
            if ($ssc_q) {
                while ($r = $ssc_q->fetch_assoc()) {
                    $contacts[] = [
                        'id'             => (int)$r['id'],
                        'name'           => $r['first_name'] . ' ' . $r['last_name'],
                        'username'       => $r['username'],
                        'category'       => 'Supreme Student Council',
                        'role_label'     => 'Council Officer (SSC)',
                        'club_name'      => 'Supreme Student Council',
                        'avatar_initial' => strtoupper(substr($r['first_name'] ?: 'S', 0, 1)),
                        'profile_pic'    => $r['profile_pic'],
                    ];
                }
            }
        }

        // 4. Institutional Administration & Staff (Eligible for Adviser, SSC, Staff, Admin, Super Admin)
        if (in_array($user_role, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'], true)) {
            $admin_q = $conn->query("
                SELECT id, first_name, last_name, username, role, profile_pic
                FROM users
                WHERE id != {$user_id}
                  AND role IN ('super_admin', 'admin', 'staff')
                  AND status = 'Active'
                ORDER BY FIELD(role, 'super_admin', 'admin', 'staff'), last_name
            ");
            if ($admin_q) {
                while ($r = $admin_q->fetch_assoc()) {
                    $roleLabel = match($r['role']) {
                        'super_admin' => 'Super Administrator',
                        'staff'       => 'OSA Staff',
                        default       => 'System & Student Affairs Admin',
                    };
                    $contacts[] = [
                        'id'             => (int)$r['id'],
                        'name'           => $r['first_name'] . ' ' . $r['last_name'],
                        'username'       => $r['username'],
                        'category'       => 'Institutional Administration',
                        'role_label'     => $roleLabel,
                        'club_name'      => 'Office of Student Affairs',
                        'avatar_initial' => strtoupper(substr($r['first_name'] ?: 'A', 0, 1)),
                        'profile_pic'    => $r['profile_pic'],
                    ];
                }
            }
        }

        chatRespond(true, 'Contacts loaded.', ['contacts' => $contacts]);
        break;
    }

    // ── 3. GET MESSAGES IN THREAD (Direct 1-on-1 OR Group Chat) ──
    case 'get_messages': {
        $group_id = (int)($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
        $recipient_id = (int)($_GET['recipient_id'] ?? $_POST['recipient_id'] ?? $_GET['contact_id'] ?? $_POST['contact_id'] ?? 0);

        // A. GROUP CHAT MESSAGES
        if ($group_id > 0) {
            if (!isUserInGroup($user_id, $user_role, $group_id, $conn)) {
                chatRespond(false, 'You do not have institutional authorization to access this group chat.');
            }

            // Fetch group details
            $g_stmt = $conn->prepare("
                SELECT cg.*, c.code AS club_code, c.name AS club_name 
                FROM chat_groups cg 
                LEFT JOIN clubs c ON c.id = cg.club_id 
                WHERE cg.id = ? LIMIT 1
            ");
            $g_stmt->bind_param('i', $group_id);
            $g_stmt->execute();
            $group = $g_stmt->get_result()->fetch_assoc();
            $g_stmt->close();

            if (!$group) {
                chatRespond(false, 'Group chat not found.');
            }

            // Update user's group read tracking
            $maxMsgRow = $conn->query("SELECT MAX(id) FROM chat_messages WHERE group_id = {$group_id}")->fetch_row();
            $maxMsg = (int)($maxMsgRow[0] ?? 0);
            $conn->query("
                INSERT INTO chat_group_reads (group_id, user_id, last_read_message_id, last_read_at) 
                VALUES ({$group_id}, {$user_id}, {$maxMsg}, NOW())
                ON DUPLICATE KEY UPDATE last_read_message_id = {$maxMsg}, last_read_at = NOW()
            ");

            // Fetch messages in this group
            $m_stmt = $conn->prepare("
                SELECT m.id, m.sender_id, m.message, m.created_at,
                       u.first_name, u.last_name, u.username, u.role, u.profile_pic,
                       (SELECT CONCAT(cm.role, ' • ', c.code) 
                        FROM club_memberships cm 
                        JOIN clubs c ON c.id = cm.club_id 
                        WHERE cm.user_id = u.id AND cm.status = 'Active' AND cm.role NOT IN ('Member') 
                        LIMIT 1) AS officer_org_badge
                FROM chat_messages m
                JOIN users u ON u.id = m.sender_id
                WHERE m.group_id = ?
                ORDER BY m.created_at ASC, m.id ASC
            ");
            $m_stmt->bind_param('i', $group_id);
            $m_stmt->execute();
            $res = $m_stmt->get_result();

            $messages = [];
            while ($r = $res->fetch_assoc()) {
                $is_me = ((int)$r['sender_id'] === $user_id);
                $role_badge = match($r['role']) {
                    'super_admin'  => 'Super Administrator',
                    'admin'        => 'System Administrator',
                    'staff'        => 'OSA Staff',
                    'ssc'          => 'Supreme Student Council',
                    'club_adviser' => (!empty($r['officer_org_badge']) ? $r['officer_org_badge'] : 'Faculty Adviser'),
                    default        => (!empty($r['officer_org_badge']) ? $r['officer_org_badge'] : 'Club Member'),
                };

                $messages[] = [
                    'id'             => (int)$r['id'],
                    'sender_id'      => (int)$r['sender_id'],
                    'sender_name'    => htmlspecialchars($r['first_name'] . ' ' . $r['last_name']),
                    'sender_role'    => $r['role'],
                    'sender_badge'   => $role_badge,
                    'avatar_initial' => strtoupper(substr($r['first_name'] ?: 'U', 0, 1)),
                    'profile_pic'    => $r['profile_pic'],
                    'is_me'          => $is_me,
                    'message'        => htmlspecialchars($r['message']),
                    'timestamp'      => date('g:i A', strtotime($r['created_at'])),
                    'date'           => date('M d, Y', strtotime($r['created_at'])),
                    'created_at'     => $r['created_at'],
                ];
            }
            $m_stmt->close();

            $membersList = getGroupMembers($group_id, $conn);
            $type_label = match($group['group_type']) {
                'club_general'   => 'Club General Chat',
                'club_executive' => 'Executive Board',
                'advisers_ssc'   => 'Advisers & SSC Council',
                default          => 'Group Chat',
            };

            chatRespond(true, 'Group messages loaded.', [
                'is_group' => true,
                'group'    => [
                    'id'             => (int)$group['id'],
                    'name'           => htmlspecialchars($group['name']),
                    'description'    => htmlspecialchars($group['description'] ?? ''),
                    'group_type'     => $group['group_type'],
                    'type_label'     => $type_label,
                    'club_code'      => $group['club_code'] ?? 'CAMPUS',
                    'club_name'      => $group['club_name'] ?? 'Multi-Club',
                    'icon'           => $group['icon'] ?: 'fa-users',
                    'avatar_initial' => strtoupper(substr($group['club_code'] ?: ($group['name'] ?: 'G'), 0, 1)),
                    'member_count'   => count($membersList),
                ],
                'messages' => $messages
            ]);
        }

        // B. DIRECT 1-ON-1 MESSAGES
        if ($recipient_id <= 0 || $recipient_id === $user_id) {
            chatRespond(false, 'Invalid recipient specified.');
        }

        // Fetch recipient profile
        $p_stmt = $conn->prepare("
            SELECT u.id, u.first_name, u.last_name, u.username, u.role, u.profile_pic,
                   (SELECT CONCAT(cm.role, ' • ', c.code) 
                    FROM club_memberships cm 
                    JOIN clubs c ON c.id = cm.club_id 
                    WHERE cm.user_id = u.id AND cm.status = 'Active' AND cm.role NOT IN ('Member') 
                    LIMIT 1) AS officer_org_badge
            FROM users u
            WHERE u.id = ? LIMIT 1
        ");
        $p_stmt->bind_param('i', $recipient_id);
        $p_stmt->execute();
        $partner = $p_stmt->get_result()->fetch_assoc();
        $p_stmt->close();

        if (!$partner) {
            chatRespond(false, 'User not found.');
        }

        // Mark unread messages sent by recipient as read
        $upd = $conn->prepare("UPDATE chat_messages SET is_read = 1, read_at = NOW() WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        if ($upd) {
            $upd->bind_param('ii', $recipient_id, $user_id);
            $upd->execute();
            $upd->close();
        }

        // Fetch messages between user and recipient
        $m_stmt = $conn->prepare("
            SELECT id, sender_id, receiver_id, message, is_read, read_at, created_at
            FROM chat_messages
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
            ORDER BY created_at ASC, id ASC
        ");
        $m_stmt->bind_param('iiii', $user_id, $recipient_id, $recipient_id, $user_id);
        $m_stmt->execute();
        $res = $m_stmt->get_result();

        $messages = [];
        while ($r = $res->fetch_assoc()) {
            $is_me = ((int)$r['sender_id'] === $user_id);
            $messages[] = [
                'id'         => (int)$r['id'],
                'sender_id'  => (int)$r['sender_id'],
                'is_me'      => $is_me,
                'message'    => htmlspecialchars($r['message']),
                'is_read'    => (bool)$r['is_read'],
                'timestamp'  => date('g:i A', strtotime($r['created_at'])),
                'date'       => date('M d, Y', strtotime($r['created_at'])),
                'created_at' => $r['created_at'],
            ];
        }
        $m_stmt->close();

        $partner_badge = match($partner['role']) {
            'super_admin'  => 'Super Administrator',
            'admin'        => 'System Administrator',
            'staff'        => 'OSA Staff',
            'ssc'          => 'Supreme Student Council',
            'club_adviser' => (!empty($partner['officer_org_badge']) ? $partner['officer_org_badge'] : 'Faculty Adviser'),
            default        => (!empty($partner['officer_org_badge']) ? $partner['officer_org_badge'] : 'Club Officer'),
        };

        chatRespond(true, 'Messages loaded.', [
            'is_group' => false,
            'partner' => [
                'id'             => (int)$partner['id'],
                'name'           => htmlspecialchars($partner['first_name'] . ' ' . $partner['last_name']),
                'username'       => htmlspecialchars($partner['username']),
                'role'           => $partner['role'],
                'role_badge'     => $partner_badge,
                'avatar_initial' => strtoupper(substr($partner['first_name'] ?: 'U', 0, 1)),
                'profile_pic'    => $partner['profile_pic'],
            ],
            'messages' => $messages
        ]);
        break;
    }

    // ── 4. SEND MESSAGE (Direct 1-on-1 OR Group Chat) ─────────────
    case 'send_message': {
        $group_id     = (int)($_POST['group_id'] ?? 0);
        $recipient_id = (int)($_POST['recipient_id'] ?? $_POST['receiver_id'] ?? 0);
        $message_text = trim($_POST['message'] ?? '');

        if (empty($message_text)) {
            chatRespond(false, 'Message cannot be empty.');
        }

        if (mb_strlen($message_text) > 2000) {
            chatRespond(false, 'Message exceeds 2,000 characters limit.');
        }

        // A. SEND TO GROUP CHAT
        if ($group_id > 0) {
            if (!isUserInGroup($user_id, $user_role, $group_id, $conn)) {
                chatRespond(false, 'You do not have permission to post in this group.');
            }

            $gRow = $conn->query("SELECT club_id, name, group_type FROM chat_groups WHERE id = {$group_id} LIMIT 1")->fetch_assoc();
            if (!$gRow) {
                chatRespond(false, 'Group not found.');
            }
            $club_id = !empty($gRow['club_id']) ? (int)$gRow['club_id'] : null;

            $ins = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, club_id, group_id, message, is_read, created_at) VALUES (?, NULL, ?, ?, ?, 0, NOW())");
            if (!$ins) {
                chatRespond(false, 'Failed to prepare group message.');
            }
            $ins->bind_param('iiis', $user_id, $club_id, $group_id, $message_text);
            $ok = $ins->execute();
            $msg_id = $conn->insert_id;
            $ins->close();

            if (!$ok) {
                chatRespond(false, 'Database error while sending group message.');
            }

            // Update sender's read marker
            $conn->query("
                INSERT INTO chat_group_reads (group_id, user_id, last_read_message_id, last_read_at) 
                VALUES ({$group_id}, {$user_id}, {$msg_id}, NOW())
                ON DUPLICATE KEY UPDATE last_read_message_id = {$msg_id}, last_read_at = NOW()
            ");

            // Push notifications to other members
            $sender_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'User';
            $preview = mb_strimwidth($message_text, 0, 60, '...');
            if (function_exists('push_notification')) {
                $members = getGroupMembers($group_id, $conn);
                foreach ($members as $m) {
                    $mid = (int)$m['id'];
                    if ($mid !== $user_id) {
                        push_notification(
                            $conn,
                            $mid,
                            "💬 {$gRow['name']}",
                            "{$sender_name}: \"{$preview}\"",
                            'info',
                            'chat_messages',
                            $msg_id,
                            'dashboard.php'
                        );
                    }
                }
            }

            // Record in audit_logs for institutional compliance
            if (function_exists('log_audit')) {
                log_audit(
                    $conn,
                    $user_id,
                    'chat_send_group_message',
                    'chat_messages',
                    $msg_id,
                    "Sent message to channel: {$gRow['name']} (ID: {$group_id})",
                    'info',
                    $sender_name
                );
            }

            // Get sender's badge
            $badgeRow = $conn->query("
                SELECT (SELECT CONCAT(cm.role, ' • ', c.code) 
                        FROM club_memberships cm 
                        JOIN clubs c ON c.id = cm.club_id 
                        WHERE cm.user_id = {$user_id} AND cm.status = 'Active' AND cm.role NOT IN ('Member') 
                        LIMIT 1) AS officer_org_badge
            ")->fetch_assoc();
            $my_badge = match($user_role) {
                'super_admin'  => 'Super Administrator',
                'admin'        => 'System Administrator',
                'staff'        => 'OSA Staff',
                'ssc'          => 'Supreme Student Council',
                'club_adviser' => (!empty($badgeRow['officer_org_badge']) ? $badgeRow['officer_org_badge'] : 'Faculty Adviser'),
                default        => (!empty($badgeRow['officer_org_badge']) ? $badgeRow['officer_org_badge'] : 'Club Member'),
            };

            chatRespond(true, 'Message sent to group.', [
                'new_message' => [
                    'id'             => (int)$msg_id,
                    'sender_id'      => $user_id,
                    'sender_name'    => $sender_name,
                    'sender_role'    => $user_role,
                    'sender_badge'   => $my_badge,
                    'avatar_initial' => strtoupper(substr($_SESSION['first_name'] ?: 'U', 0, 1)),
                    'profile_pic'    => $_SESSION['profile_pic'] ?? '',
                    'is_me'          => true,
                    'message'        => htmlspecialchars($message_text),
                    'timestamp'      => date('g:i A'),
                    'date'           => date('M d, Y'),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]
            ]);
            break;
        }

        // B. SEND DIRECT MESSAGE
        if ($recipient_id <= 0 || $recipient_id === $user_id) {
            chatRespond(false, 'Please select a valid recipient.');
        }

        // Validate RBAC permission against communication matrix
        if (!isCommunicationAllowed($user_role, $recipient_id, $conn)) {
            chatRespond(false, 'Institutional policy does not permit communication between your role and this recipient.');
        }

        // Look up club context if any
        $club_id = null;
        $cm_q = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active' LIMIT 1");
        if ($cm_q) {
            $cm_q->bind_param('i', $user_id);
            $cm_q->execute();
            $cm_q->bind_result($cid);
            if ($cm_q->fetch()) {
                $club_id = (int)$cid;
            }
            $cm_q->close();
        }

        $ins = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, club_id, message, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        if (!$ins) {
            chatRespond(false, 'Failed to prepare message insertion.');
        }
        $ins->bind_param('iiis', $user_id, $recipient_id, $club_id, $message_text);
        $ok = $ins->execute();
        $msg_id = $conn->insert_id;
        $ins->close();

        if (!$ok) {
            chatRespond(false, 'Database error while sending message.');
        }

        // Create in-app system notification for recipient
        $sender_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'A user';
        $preview = mb_strimwidth($message_text, 0, 60, '...');
        if (function_exists('push_notification')) {
            push_notification(
                $conn,
                $recipient_id,
                "💬 New message from {$sender_name}",
                "{$sender_name}: \"{$preview}\"",
                'info',
                'chat_messages',
                $msg_id,
                'dashboard.php'
            );
        }

        // Record in audit_logs for institutional compliance
        if (function_exists('log_audit')) {
            log_audit(
                $conn,
                $user_id,
                'chat_send_direct_message',
                'chat_messages',
                $msg_id,
                "Sent 1-on-1 direct message to recipient ID: {$recipient_id}",
                'info',
                $sender_name
            );
        }

        chatRespond(true, 'Message sent successfully.', [
            'new_message' => [
                'id'         => (int)$msg_id,
                'sender_id'  => $user_id,
                'is_me'      => true,
                'message'    => htmlspecialchars($message_text),
                'is_read'    => false,
                'timestamp'  => date('g:i A'),
                'date'       => date('M d, Y'),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ]);
        break;
    }

    // ── 5. GET GROUP MEMBERS ──────────────────────────────────────
    case 'get_group_members': {
        $group_id = (int)($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
        if ($group_id <= 0 || !isUserInGroup($user_id, $user_role, $group_id, $conn)) {
            chatRespond(false, 'Unauthorized or invalid group.');
        }
        $members = getGroupMembers($group_id, $conn);
        chatRespond(true, 'Members loaded.', ['members' => $members]);
        break;
    }

    // ── 6. GET UNREAD MESSAGE COUNT (Direct + Groups for Topbar Badge) ──
    case 'get_unread_count': {
        // Direct messages unread
        $stmt = $conn->prepare("SELECT COUNT(*) FROM chat_messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $direct_count = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();

        // Group messages unread
        $group_count = 0;
        $authGroupIds = getUserAuthorizedGroupIds($user_id, $user_role, $conn);
        if (!empty($authGroupIds)) {
            $gListStr = implode(',', array_map('intval', $authGroupIds));
            $sqlGrpUnread = "
                SELECT COUNT(*)
                FROM chat_messages m
                LEFT JOIN chat_group_reads r ON r.group_id = m.group_id AND r.user_id = {$user_id}
                WHERE m.group_id IN ({$gListStr})
                  AND m.sender_id != {$user_id}
                  AND (r.last_read_message_id IS NULL OR m.id > r.last_read_message_id)
            ";
            $gRes = $conn->query($sqlGrpUnread);
            if ($gRes) {
                $group_count = (int)$gRes->fetch_row()[0];
            }
        }

        $total_count = $direct_count + $group_count;

        chatRespond(true, 'Unread count fetched.', [
            'unread_count'        => $total_count,
            'unread_direct_count' => $direct_count,
            'unread_group_count'  => $group_count,
        ]);
        break;
    }

    case '':
    case 'noop':
        break;

    default:
        chatRespond(false, 'Invalid chat action requested.');
}
