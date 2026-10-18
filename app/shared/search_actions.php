<?php
// ============================================================
//  SEARCH_ACTIONS.PHP (shared/)
//  Role-Based Access Control (RBAC) Search API
//  Strictly searches authorized pages, modules, events, and clubs.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$query     = trim($_GET['q'] ?? '');
$q_lower   = strtolower($query);

// ── 1. ROLE-BASED ACCESS CONTROL (RBAC) MODULE REGISTRY ──────
$all_modules = [
    [
        'title'       => 'Dashboard Overview',
        'url'         => '../dashboard/dashboard.php',
        'icon'        => 'fa-solid fa-gauge-high',
        'description' => 'System metrics, upcoming schedules, and news feeds',
        'keywords'    => 'dashboard home overview metrics analytics feeds schedule notifications',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Organization Directory',
        'url'         => '../dashboard/club_directory.php',
        'icon'        => 'fa-solid fa-sitemap',
        'description' => 'Browse recognized student clubs and organizations',
        'keywords'    => 'organizations clubs directory charter join apply officers leaders',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => ($user_role === 'student') ? 'Membership Roster' : 'Applicant Queue & Member Roster',
        'url'         => '../dashboard/roster.php',
        'icon'        => 'fa-solid fa-users',
        'description' => ($user_role === 'student') ? 'View your joined organizations and fellow members' : 'Manage student applications and active members',
        'keywords'    => 'roster members applicants applications governance approve endorse letter intent',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Elections & Voting',
        'url'         => '../dashboard/elections.php',
        'icon'        => 'fa-solid fa-check-to-slot',
        'description' => 'Official student council and club election ballots',
        'keywords'    => 'elections vote ballot candidate president winners voting results polls',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Events',
        'url'         => '../dashboard/events.php',
        'icon'        => 'fa-solid fa-calendar-days',
        'description' => 'Campus calendar, activity proposals, and schedules',
        'keywords'    => 'events calendar activities schedule seminars workshops campus schedule month',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Achievements & Awards',
        'url'         => '../dashboard/achievements.php',
        'icon'        => 'fa-solid fa-award',
        'description' => 'Accredited organizational achievements and honors',
        'keywords'    => 'achievements awards recognition medals honors competition',
        'roles'       => ['club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Event QR Generator',
        'url'         => '../dashboard/tracking_qr_generator.php',
        'icon'        => 'fa-solid fa-print',
        'description' => 'Generate and print official attendance posters',
        'keywords'    => 'qr generator print poster venue attendance tracking portal',
        'roles'       => ['club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'On-Site Scanner Terminal',
        'url'         => '../dashboard/tracking_scanner.php',
        'icon'        => 'fa-solid fa-camera',
        'description' => 'Live camera scanning terminal for badge check-ins',
        'keywords'    => 'scanner camera attendance verify check in terminal entry badge rfid',
        'roles'       => ['club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Attendance List per Event',
        'url'         => '../dashboard/tracking_attendance_list.php',
        'icon'        => 'fa-solid fa-clipboard-list',
        'description' => 'Real-time attendee rosters and CSV export',
        'keywords'    => 'attendance list records present logs roster participants export csv',
        'roles'       => ['club_adviser', 'ssc', 'admin'],
    ],

    [
        'title'       => 'Intelligent Reports & Analytics',
        'url'         => '../dashboard/reports.php',
        'icon'        => 'fa-solid fa-brain',
        'description' => 'AI-powered co-curricular insights and summaries',
        'keywords'    => 'reports analytics ai insights intelligence audit summary statistics charts',
        'roles'       => ['club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'System Administration & Security',
        'url'         => '../dashboard/admin_system.php',
        'icon'        => 'fa-solid fa-shield-halved',
        'description' => 'User accounts, permissions, and security controls',
        'keywords'    => 'admin system users accounts roles security audit logs reset permissions',
        'roles'       => ['admin'],
    ],
    [
        'title'       => 'Announcements & Broad Communications',
        'url'         => '../dashboard/announcements.php',
        'icon'        => 'fa-solid fa-bullhorn',
        'description' => 'System notices, broad communications, council bulletins, and publication controls',
        'keywords'    => 'announcements news notice bulletins updates broadcast templates publication controls system notices',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Account & Profile Settings',
        'url'         => '../dashboard/account.php',
        'icon'        => 'fa-solid fa-user-gear',
        'description' => 'Update password, profile photo, and preferences',
        'keywords'    => 'account profile settings password avatar picture security student info',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
];

// Filter Modules by strict RBAC and user search query
$matching_modules = [];
foreach ($all_modules as $mod) {
    if (!in_array($user_role, $mod['roles'])) continue;
    if (empty($query) || str_contains(strtolower($mod['title'] . ' ' . $mod['keywords'] . ' ' . $mod['description']), $q_lower)) {
        $matching_modules[] = [
            'title'       => $mod['title'],
            'url'         => $mod['url'],
            'icon'        => $mod['icon'],
            'description' => $mod['description'],
            'type'        => 'Module',
        ];
    }
}

// ── 2. SEARCH EVENTS (ROLE & SCOPE PERMITTED) ─────────────────
$matching_events = [];
$ev_scope_sql = "e.status IN ('Approved','Upcoming','Completed')";
$ev_params = [];
$ev_types = '';

if ($user_role === 'club_adviser') {
    // Adviser sees own club events plus approved campus events
    $c_stmt = $conn->prepare("SELECT id FROM clubs WHERE adviser_user_id = ? LIMIT 1");
    $c_stmt->bind_param('i', $user_id);
    $c_stmt->execute();
    $c_res = $c_stmt->get_result()->fetch_assoc();
    $c_stmt->close();
    $my_adv_club = $c_res['id'] ?? 0;
    if ($my_adv_club) {
        $ev_scope_sql = "(e.club_id = ? OR e.event_type = 'Institutional' OR e.status = 'Approved')";
        $ev_params[] = (int)$my_adv_club;
        $ev_types .= 'i';
    }
} elseif (in_array($user_role, ['ssc', 'admin'])) {
    $ev_scope_sql = "e.deleted_at IS NULL";
}

if (!empty($query)) {
    $searchTerm = '%' . $query . '%';
    $ev_query = "
        SELECT e.id, e.title, e.event_date, e.venue, e.status, c.name as club_name, c.code as club_code
        FROM events e
        LEFT JOIN clubs c ON c.id = e.club_id
        WHERE $ev_scope_sql
          AND (e.title LIKE ? OR e.venue LIKE ? OR c.name LIKE ? OR c.code LIKE ?)
        ORDER BY e.event_date DESC LIMIT 6
    ";
    $ev_params = array_merge($ev_params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $ev_types .= 'ssss';
    $ev_stmt = $conn->prepare($ev_query);
    if ($ev_types && $ev_params) {
        $ev_stmt->bind_param($ev_types, ...$ev_params);
    }
    $ev_stmt->execute();
    $ev_res = $ev_stmt->get_result();
} else {
    $ev_query = "
        SELECT e.id, e.title, e.event_date, e.venue, e.status, c.name as club_name, c.code as club_code
        FROM events e
        LEFT JOIN clubs c ON c.id = e.club_id
        WHERE $ev_scope_sql
        ORDER BY e.event_date DESC LIMIT 6
    ";
    $ev_stmt = $conn->prepare($ev_query);
    if ($ev_types && $ev_params) {
        $ev_stmt->bind_param($ev_types, ...$ev_params);
    }
    $ev_stmt->execute();
    $ev_res = $ev_stmt->get_result();
}
if ($ev_res) {
    while ($ev = $ev_res->fetch_assoc()) {
        $dateStr = date('M d, Y', strtotime($ev['event_date']));
        $matching_events[] = [
            'title'       => $ev['title'],
            'url'         => '../dashboard/events.php?event_id=' . $ev['id'],
            'icon'        => 'fa-solid fa-calendar-check',
            'description' => "{$dateStr} &bull; " . ($ev['venue'] ?? 'Campus Venue') . " (" . ($ev['club_code'] ?? 'BCP') . ")",
            'type'        => 'Event',
        ];
    }
}
if (isset($ev_stmt) && $ev_stmt instanceof mysqli_stmt) {
    try { $ev_stmt->close(); } catch (Throwable $e) {}
    $ev_stmt = null;
}

// ── 3. SEARCH CLUBS (FOR ROLES WITH CLUB DIRECTORY ACCESS) ────
$matching_clubs = [];
if (in_array($user_role, ['student', 'club_adviser', 'ssc', 'admin'])) {
    if (!empty($query)) {
        $searchTerm = '%' . $query . '%';
        $cl_stmt = $conn->prepare("SELECT id, name, code, category FROM clubs WHERE status = 'Active' AND (name LIKE ? OR code LIKE ? OR category LIKE ?) ORDER BY name ASC LIMIT 5");
        $cl_stmt->bind_param('sss', $searchTerm, $searchTerm, $searchTerm);
        $cl_stmt->execute();
        $cl_res = $cl_stmt->get_result();
    } else {
        $cl_res = $conn->query("SELECT id, name, code, category FROM clubs WHERE status = 'Active' ORDER BY name ASC LIMIT 5");
    }
    if ($cl_res) {
        while ($cl = $cl_res->fetch_assoc()) {
            $matching_clubs[] = [
                'title'       => "{$cl['name']} ({$cl['code']})",
                'url'         => '../dashboard/club_directory.php?search=' . urlencode($cl['code']),
                'icon'        => 'fa-solid fa-sitemap',
                'description' => $cl['category'] . ' Organization',
                'type'        => 'Organization',
            ];
        }
    }
    if (isset($cl_stmt) && $cl_stmt instanceof mysqli_stmt) {
        try { $cl_stmt->close(); } catch (Throwable $e) {}
        $cl_stmt = null;
    }
}

// ── 4. SEARCH ANNOUNCEMENTS ──────────────────────────────────
$matching_announcements = [];
if (!empty($query)) {
    $ann_term = '%' . $query . '%';
    $ann_stmt = $conn->prepare("
        SELECT a.id, a.title, a.category, a.priority, a.scope, a.created_at, c.code AS club_code
        FROM org_announcements a
        LEFT JOIN clubs c ON c.id = a.club_id
        WHERE a.status IN ('Active', 'Published')
          AND (a.title LIKE ? OR a.category LIKE ? OR a.content LIKE ?)
        ORDER BY a.created_at DESC LIMIT 5
    ");
    if ($ann_stmt) {
        $ann_stmt->bind_param('sss', $ann_term, $ann_term, $ann_term);
        $ann_stmt->execute();
        $ann_res = $ann_stmt->get_result();
        while ($ann = $ann_res->fetch_assoc()) {
            $dt = date('M d, Y', strtotime($ann['created_at']));
            $matching_announcements[] = [
                'title'       => $ann['title'],
                'url'         => '../dashboard/announcements.php?id=' . $ann['id'],
                'icon'        => 'fa-solid fa-bullhorn',
                'description' => "{$dt} &bull; " . ($ann['club_code'] ?? $ann['scope']) . " ({$ann['category']})",
                'type'        => 'Announcement',
            ];
        }
        $ann_stmt->close();
    }
}

// ── 5. SEARCH STUDENT DIRECTORY (Staff/Adviser/Admin) ─────────
$matching_students = [];
if (!empty($query) && in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
    $stu_term = '%' . $query . '%';
    $stu_stmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, s.student_number, s.course, s.year_level
        FROM users u
        JOIN students s ON s.user_id = u.id
        WHERE u.role = 'student'
          AND (s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR s.course LIKE ?)
        LIMIT 5
    ");
    if ($stu_stmt) {
        $stu_stmt->bind_param('ssss', $stu_term, $stu_term, $stu_term, $stu_term);
        $stu_stmt->execute();
        $stu_res = $stu_stmt->get_result();
        while ($stu = $stu_res->fetch_assoc()) {
            $name = trim($stu['first_name'] . ' ' . $stu['last_name']);
            $matching_students[] = [
                'title'       => "{$name} ({$stu['student_number']})",
                'url'         => '../dashboard/roster.php?search=' . urlencode($stu['student_number']),
                'icon'        => 'fa-solid fa-user-graduate',
                'description' => trim(($stu['course'] ?? '') . ' ' . ($stu['year_level'] ?? '')),
                'type'        => 'Student',
            ];
        }
        $stu_stmt->close();
    }
}

echo json_encode([
    'success' => true,
    'query'   => $query,
    'results' => [
        'modules'       => array_slice($matching_modules, 0, 6),
        'events'        => $matching_events,
        'clubs'         => $matching_clubs,
        'announcements' => $matching_announcements,
        'students'      => $matching_students,
    ],
    'total'   => count($matching_modules) + count($matching_events) + count($matching_clubs) + count($matching_announcements) + count($matching_students),
]);
