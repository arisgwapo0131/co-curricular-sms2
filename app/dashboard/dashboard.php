<?php
// ============================================================
//  DASHBOARD.PHP  (dashboard/)
//  Central Executive & Operational Portal
//  Role-Tailored Views: Student, Club Adviser, SSC Officer, Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_once __DIR__ . '/../shared/notification_actions.php';
require_auth();
require_any_permission(['dashboard.view.system', 'dashboard.view.institutional', 'dashboard.view.org', 'dashboard.view.own']);

// Ensure genuine session role is restored if impersonation was previously active
if (isset($_SESSION['real_role'])) {
    $_SESSION['role'] = $_SESSION['real_role'];
    unset($_SESSION['real_role']);
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// Role Labels
$role_labels = [
    'student'      => 'General Student',
    'club_adviser' => 'Organization Adviser (Faculty Member)',
    'ssc'          => 'Supreme Student Council (SSC Officer)',
    'admin'        => 'System Administrator',
    'super_admin'  => 'Root Super Administrator',
    'staff'        => 'Office of Student Affairs (Staff)'
];
$role_title = $role_labels[$sess_role] ?? 'User';

// Student Profile Details
$student_info = null;
if ($sess_role === 'student') {
    $stmt = $conn->prepare("SELECT student_number, course, year_level, section FROM students WHERE first_name = ? AND last_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ss', $_SESSION['first_name'], $_SESSION['last_name']);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $student_info = $res->fetch_assoc();
        }
        $stmt->close();
    }

    // ── Student Upcoming Event & Joined Clubs (Section 2) ──
    $student_upcoming_event = null;
    $student_clubs_list = [];

    $ev_sql = "
        SELECT e.id, e.title, e.event_date, e.venue, c.name as club_name, c.code as club_code,
               (SELECT status FROM event_registrations er WHERE er.event_id = e.id AND er.user_id = $user_id LIMIT 1) as reg_status
        FROM events e
        LEFT JOIN clubs c ON c.id = e.club_id
        WHERE e.status IN ('Approved', 'Upcoming')
          AND e.event_date >= CURDATE()
        ORDER BY e.event_date ASC
        LIMIT 1
    ";
    $ev_res = $conn->query($ev_sql);
    if ($ev_res && $ev_res->num_rows > 0) {
        $student_upcoming_event = $ev_res->fetch_assoc();
    }

    $c_q = $conn->query("SELECT c.id, c.name, c.code, c.category, cm.role AS role_in_club FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = $user_id AND cm.status = 'Active' AND c.deleted_at IS NULL ORDER BY cm.joined_at DESC LIMIT 4");
    if ($c_q) {
        $student_clubs_list = $c_q->fetch_all(MYSQLI_ASSOC);
    }
}

// ── Global System Metrics ─────────────────────────────────────
$active_clubs_joined = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE user_id = {$user_id} AND status = 'Active'");
if ($r) $active_clubs_joined = (int)$r->fetch_assoc()['c'];

$active_campus_events = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM events WHERE status IN ('Approved', 'Upcoming') AND deleted_at IS NULL");
if ($r) $active_campus_events = (int)$r->fetch_assoc()['c'];

$total_active_clubs = (int)$conn->query("SELECT COUNT(*) AS c FROM clubs WHERE status = 'Active' AND deleted_at IS NULL")->fetch_assoc()['c'];
$total_clubs = $total_active_clubs;
$total_students_in_orgs = (int)$conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM club_memberships WHERE status = 'Active'")->fetch_assoc()['c'];
$total_budgets_disbursed = (float)$conn->query("SELECT COALESCE(SUM(amount), 0) AS s FROM budget_requests WHERE status = 'Disbursed' AND deleted_at IS NULL")->fetch_assoc()['s'];

// ── Dynamic Announcements ─────────────────────────────────────
$announcements = [];
$ann_res = $conn->query("SELECT oa.*, c.name AS club_name, c.code AS club_code FROM org_announcements oa JOIN clubs c ON c.id = oa.club_id WHERE c.deleted_at IS NULL ORDER BY oa.created_at DESC LIMIT 3");
if ($ann_res) {
    while ($row = $ann_res->fetch_assoc()) {
        $announcements[] = $row;
    }
}

// ── SSC Specific Governance Data ──────────────────────────────
// ── SSC Specific Governance Data ──────────────────────────────
$ssc_pending_budgets = [];
$ssc_pending_clubs   = [];
$ssc_pending_events  = [];
$ssc_action_queue    = [];
$club_categories_counts = [];

$ssc_pending_charters_cnt     = 0;
$ssc_pending_events_cnt       = 0;
$ssc_pending_budgets_cnt      = 0;
$ssc_pending_achievements_cnt = 0;
$ssc_pending_apps_cnt         = 0;
$ssc_open_tasks               = 0;
$ssc_attendance_rate          = 0.0;

if ($sess_role === 'ssc' || $sess_role === 'admin' || $sess_role === 'super_admin' || $sess_role === 'staff') {
    // 1. Pending Charters
    $res = $conn->query("SELECT id, name, code, category, created_at FROM clubs WHERE status = 'Pending Charter' AND deleted_at IS NULL ORDER BY created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) { 
            $ssc_pending_clubs[] = $row; 
            $days = max(0, (int)floor((time() - strtotime($row['created_at'])) / 86400));
            $ssc_action_queue[] = [
                'ref'          => 'CHR-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                'type'         => 'Charter',
                'org'          => $row['name'] . ' (' . $row['code'] . ')',
                'title'        => 'New Organization Charter Application',
                'submitted_by' => 'Founding Officers',
                'date'         => $row['created_at'],
                'status'       => 'Pending Review',
                'days'         => $days,
                'priority'     => $days > 3 ? 'Urgent' : 'Normal',
                'action_url'   => 'club_directory.php',
                'action_label' => 'Review Charter'
            ];
        }
    }
    $ssc_pending_charters_cnt = count($ssc_pending_clubs);

    // 2. Pending Events for SSC Review
    $res = $conn->query("SELECT e.id, e.title, e.event_date, e.venue, e.created_at, c.name AS club_name, c.code AS club_code, u.first_name, u.last_name FROM events e LEFT JOIN clubs c ON c.id = e.club_id LEFT JOIN users u ON u.id = e.created_by WHERE e.status = 'Pending SSC' AND e.deleted_at IS NULL ORDER BY e.created_at DESC, e.id DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) { 
            $ssc_pending_events[] = $row; 
            $days = max(0, (int)floor((time() - strtotime($row['created_at'])) / 86400));
            $ssc_action_queue[] = [
                'ref'          => 'EVT-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                'type'         => 'Event',
                'org'          => $row['club_name'] ?? 'Institutional',
                'title'        => $row['title'] . ' (' . date('M d', strtotime($row['event_date'])) . ')',
                'submitted_by' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Club Adviser',
                'date'         => $row['created_at'],
                'status'       => 'Pending SSC',
                'days'         => $days,
                'priority'     => $days > 2 ? 'Urgent' : 'Normal',
                'action_url'   => 'events.php?view=pipeline',
                'action_label' => 'Review & Endorse'
            ];
        }
    }
    $ssc_pending_events_cnt = count($ssc_pending_events);

    // 3. Pending Achievements (SSC Verification)
    $res = $conn->query("SELECT a.id, a.title, a.competition, a.created_at, c.name AS club_name, c.code AS club_code, u.first_name, u.last_name FROM achievements a LEFT JOIN clubs c ON c.id = a.club_id LEFT JOIN users u ON u.id = a.submitted_by WHERE a.status = 'Pending' ORDER BY a.created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $days = max(0, (int)floor((time() - strtotime($row['created_at'])) / 86400));
            $ssc_action_queue[] = [
                'ref'          => 'ACH-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                'type'         => 'Achievement',
                'org'          => $row['club_name'] ?? 'Individual Entry',
                'title'        => $row['title'] . ' - ' . $row['competition'],
                'submitted_by' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Student',
                'date'         => $row['created_at'],
                'status'       => 'Pending Verification',
                'days'         => $days,
                'priority'     => 'Normal',
                'action_url'   => 'achievements.php',
                'action_label' => 'Verify Evidence'
            ];
        }
    }
    $ssc_pending_achievements_cnt = (int)($conn->query("SELECT COUNT(*) FROM achievements WHERE status = 'Pending'")->fetch_row()[0] ?? 0);

    // 4. Pending Membership Applications
    $res = $conn->query("SELECT ca.id, ca.applied_at AS created_at, c.name AS club_name, c.code AS club_code, u.first_name, u.last_name, s.student_number, s.course FROM club_applications ca JOIN clubs c ON c.id = ca.club_id JOIN users u ON u.id = ca.user_id LEFT JOIN students s ON s.user_id = u.id WHERE ca.status = 'Pending' ORDER BY ca.applied_at DESC LIMIT 5");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $days = max(0, (int)floor((time() - strtotime($row['created_at'])) / 86400));
            $ssc_action_queue[] = [
                'ref'          => 'APP-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                'type'         => 'Membership',
                'org'          => $row['club_name'],
                'title'        => 'Application: ' . trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) . ' (' . ($row['course'] ?? 'Student') . ')',
                'submitted_by' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'date'         => $row['created_at'],
                'status'       => 'Pending Review',
                'days'         => $days,
                'priority'     => 'Normal',
                'action_url'   => 'roster.php?view=queue',
                'action_label' => 'Review Roster'
            ];
        }
    }
    $ssc_pending_apps_cnt = (int)($conn->query("SELECT COUNT(*) FROM club_applications WHERE status = 'Pending'")->fetch_row()[0] ?? 0);

    // Total open governance tasks
    $ssc_open_tasks = $ssc_pending_charters_cnt + $ssc_pending_events_cnt + $ssc_pending_achievements_cnt + $ssc_pending_apps_cnt;

    // Attendance stats
    $tot_regs = (int)$conn->query("SELECT COUNT(*) FROM event_registrations")->fetch_row()[0];
    $tot_logs = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs")->fetch_row()[0];
    if ($tot_regs > 0) {
        $ssc_attendance_rate = min(100, round(($tot_logs / $tot_regs) * 100, 1));
    }

    // Sort action queue by priority (Urgent first), then by date DESC
    usort($ssc_action_queue, function($a, $b) {
        if ($a['priority'] === 'Urgent' && $b['priority'] !== 'Urgent') return -1;
        if ($a['priority'] !== 'Urgent' && $b['priority'] === 'Urgent') return 1;
        return strtotime($b['date']) <=> strtotime($a['date']);
    });

    // ── 1. Organization Distribution by Category ─────────────────
    $club_categories_counts = [
        'Academic' => 0,
        'Cultural' => 0,
        'Sports'   => 0,
        'Advocacy' => 0
    ];
    $c_res = $conn->query("SELECT category, COUNT(*) as c FROM clubs WHERE deleted_at IS NULL GROUP BY category");
    if ($c_res) {
        while ($row = $c_res->fetch_assoc()) {
            $club_categories_counts[$row['category']] = (int)$row['c'];
        }
    }
    $total_orgs_count = array_sum($club_categories_counts);

    // ── 2. Membership by Program (Student participation grouped by program) ──
    $prog_abbr_map = [
        'Information Technology' => 'BSIT',
        'Computer Engineering'   => 'BSCpE',
        'Criminology'            => 'BSCrim',
        'Business Administration'=> 'BSBA',
        'Hospitality'            => 'BSHM',
        'Tourism'                => 'BSTM',
        'Accounting Information' => 'BSAIS',
        'Physical Education'     => 'BPED',
        'Psychology'             => 'BSPsych',
        'Secondary Education'    => 'BSEd',
        'Elementary Education'   => 'BEEd',
        'Office Administration'  => 'BSOA',
        'Library'                => 'BLIS',
        'Entrepreneurship'       => 'BSENT'
    ];
    $ssc_prog_participation = [];
    $p_res = $conn->query("
        SELECT s.course, COUNT(DISTINCT er.id) as reg_cnt, COUNT(DISTINCT cm.id) as mem_cnt
        FROM students s
        LEFT JOIN event_registrations er ON er.user_id = s.user_id
        LEFT JOIN club_memberships cm ON cm.user_id = s.user_id AND cm.status = 'Active'
        GROUP BY s.course
    ");
    if ($p_res) {
        while ($row = $p_res->fetch_assoc()) {
            $course = $row['course'] ?? '';
            $code = 'General';
            foreach ($prog_abbr_map as $needle => $abbr) {
                if (stripos($course, $needle) !== false) {
                    $code = $abbr;
                    break;
                }
            }
            $cnt = (int)$row['reg_cnt'] + (int)$row['mem_cnt'];
            if ($cnt > 0) {
                $ssc_prog_participation[$code] = ($ssc_prog_participation[$code] ?? 0) + $cnt;
            }
        }
    }
    arsort($ssc_prog_participation);
    $ssc_prog_participation = array_slice($ssc_prog_participation, 0, 8, true);

    // ── 3. SSC Event Proposals Pipeline (Pending SSC, Pending Admin, Approved, Completed, Rejected) ──
    $ssc_event_pipeline = [
        'pending_ssc'   => 0,
        'pending_admin' => 0,
        'approved'      => 0,
        'completed'     => 0,
        'rejected'      => 0
    ];
    $ep_res = $conn->query("
        SELECT status, COUNT(*) as cnt 
        FROM events 
        WHERE status IN ('Pending SSC', 'Pending Admin', 'Approved', 'Completed', 'Rejected')
        GROUP BY status
    ");
    if ($ep_res) {
        while ($row = $ep_res->fetch_assoc()) {
            $st = $row['status'];
            $cnt = (int)$row['cnt'];
            if ($st === 'Pending SSC') $ssc_event_pipeline['pending_ssc'] = $cnt;
            elseif ($st === 'Pending Admin') $ssc_event_pipeline['pending_admin'] = $cnt;
            elseif ($st === 'Approved') $ssc_event_pipeline['approved'] = $cnt;
            elseif ($st === 'Completed') $ssc_event_pipeline['completed'] = $cnt;
            elseif ($st === 'Rejected') $ssc_event_pipeline['rejected'] = $cnt;
        }
    }

    // ── 4. Attendance Performance (Registered, attended, absent, attendance rate) ──
    $tot_registered = 0;
    $reg_q = $conn->query("SELECT COUNT(*) FROM event_registrations");
    if ($reg_q) {
        $tot_registered = (int)$reg_q->fetch_row()[0];
    }
    $tot_attended = 0;
    $att_q = $conn->query("SELECT COUNT(*) FROM attendance_logs");
    if ($att_q) {
        $tot_attended = (int)$att_q->fetch_row()[0];
    }
    $tot_absent = max(0, $tot_registered - $tot_attended);
    $ssc_attendance_rate = $tot_registered > 0 ? round(($tot_attended / $tot_registered) * 100, 1) : 0;

    // ── 5. Event Activity Trend (Monthly approved / completed / rejected volume) ──
    $trend_months = [];
    $trend_approved = [];
    $trend_completed = [];
    $trend_rejected = [];
    for ($i = 5; $i >= 0; $i--) {
        $m_time = strtotime("-$i months", time());
        $ym = date('Y-m', $m_time);
        $trend_months[$ym] = date('M Y', $m_time);
        $trend_approved[$ym] = 0;
        $trend_completed[$ym] = 0;
        $trend_rejected[$ym] = 0;
    }
    $ev_trend_q = $conn->query("
        SELECT DATE_FORMAT(event_date, '%Y-%m') as ym, status, COUNT(*) as cnt 
        FROM events 
        WHERE deleted_at IS NULL 
        GROUP BY ym, status
    ");
    if ($ev_trend_q) {
        while ($row = $ev_trend_q->fetch_assoc()) {
            $ym = $row['ym'];
            $st = $row['status'];
            if (isset($trend_months[$ym])) {
                if ($st === 'Approved' || $st === 'Upcoming') {
                    $trend_approved[$ym] += (int)$row['cnt'];
                } elseif ($st === 'Completed') {
                    $trend_completed[$ym] += (int)$row['cnt'];
                } elseif ($st === 'Rejected') {
                    $trend_rejected[$ym] += (int)$row['cnt'];
                }
            }
        }
    }
    $trend_labels_json = json_encode(array_values($trend_months));
    $trend_approved_json = json_encode(array_values($trend_approved));
    $trend_completed_json = json_encode(array_values($trend_completed));
    $trend_rejected_json = json_encode(array_values($trend_rejected));
}


// ── Admin Specific Analytics Data ─────────────────────────────
$admin_pending_events = [];
$admin_action_queue = [];
$user_role_counts = [];
$audit_24h_count = 0;
$total_accounts = 0;
$active_orgs_count = 0;
$pending_workflows_count = 0;
$failed_warning_count = 0;
$pending_admin_approvals_count = 0;
$sys_health_status = 'Optimal';
$pipeline_events = ['adviser' => 0, 'ssc' => 0, 'admin' => 0, 'cleared' => 0, 'returned' => 0];
$admin_cat_labels = [];
$admin_cat_orgs = [];
$admin_cat_events = [];

if ($sess_role === 'admin' || $sess_role === 'super_admin') {
    // 1. Total Accounts: All active and inactive user accounts.
    $acc_res = $conn->query("SELECT COUNT(*) FROM users");
    $total_accounts = $acc_res ? (int)$acc_res->fetch_row()[0] : 0;

    // 2. Active Organizations: Recognized organizations.
    $org_res = $conn->query("SELECT COUNT(*) FROM clubs WHERE deleted_at IS NULL AND (status = 'Active' OR status = 'Chartered' OR status IS NULL)");
    $active_orgs_count = $org_res ? (int)$org_res->fetch_row()[0] : 0;

    // 3. Pending Workflows: Event proposals awaiting approval across stages.
    $pending_workflows_count = (int)($conn->query("SELECT COUNT(*) FROM events WHERE status IN ('Pending Adviser', 'Pending SSC', 'Pending Admin') AND deleted_at IS NULL")->fetch_row()[0] ?? 0);

    // 4. System Activities 24h: Recent audit volume.
    $aud_res = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $audit_24h_count = $aud_res ? (int)$aud_res->fetch_row()[0] : 0;

    // 5. Failed / Warning Events: Security and health warning count.
    $warn_res = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%FAILED%' OR action LIKE '%REJECT%' OR action LIKE '%OVERRIDE%' OR action LIKE '%UNAUTHORIZED%' OR action LIKE '%ERROR%' OR action LIKE '%WARN%'");
    $failed_warning_count = $warn_res ? (int)$warn_res->fetch_row()[0] : 0;

    // Events awaiting Admin calendar clearance
    $res = $conn->query("SELECT e.id, e.title, e.event_date, e.venue, e.event_type, e.budget_amount, e.status, e.created_at, COALESCE(c.code, c.name, 'INSTITUTIONAL') AS org_code, COALESCE(c.name, 'BCP Institutional') AS club_name, u.first_name, u.last_name FROM events e LEFT JOIN clubs c ON c.id = e.club_id LEFT JOIN users u ON u.id = e.created_by WHERE e.status IN ('Pending Admin', 'Pending SSC', 'Pending OSA', 'Pending Adviser') AND e.deleted_at IS NULL ORDER BY e.created_at DESC, e.id DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) { 
            $admin_pending_events[] = $row; 
            $year = !empty($row['created_at']) ? date('Y', strtotime($row['created_at'])) : date('Y');
            $admin_action_queue[] = [
                'id'           => (int)$row['id'],
                'raw_type'     => 'event',
                'ref'          => 'EVT-' . $year . '-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                'type'         => 'Event',
                'organization' => !empty($row['org_code']) ? $row['org_code'] : $row['club_name'],
                'description'  => $row['title'],
                'amount_date'  => date('m/d/Y', strtotime($row['event_date'])),
                'ssc_status'   => ($row['status'] === 'Pending Admin') ? 'Pending Admin' : 'Submitted Proposal',
                'action_label' => 'Approve / Return'
            ];
        }
    }

    // 6. Pending Admin Approvals: Items waiting for final admin action.
    $pending_admin_approvals_count = count($admin_action_queue);

    // 7. System Health: Overall health state.
    $sys_health_status = ($db_connected ? 'Optimal' : 'Attention');

    // ── Graph 1: Workflow Pipeline Throughput (Events across stages) ──
    $pe_res = $conn->query("SELECT status, COUNT(*) as cnt FROM events WHERE deleted_at IS NULL GROUP BY status");
    if ($pe_res) {
        while ($r = $pe_res->fetch_assoc()) {
            $st = $r['status'];
            $c  = (int)$r['cnt'];
            if ($st === 'Pending Adviser') $pipeline_events['adviser'] += $c;
            elseif ($st === 'Pending SSC') $pipeline_events['ssc'] += $c;
            elseif ($st === 'Pending Admin') $pipeline_events['admin'] += $c;
            elseif (in_array($st, ['Approved', 'Completed', 'Upcoming'])) $pipeline_events['cleared'] += $c;
            elseif (in_array($st, ['Returned', 'Rejected'])) $pipeline_events['returned'] += $c;
        }
    }

    // ── Graph 2: Organization Distribution & Events by Category ─────────────────
    $admin_cat_stats = [
        'Academic' => ['orgs' => 0, 'events' => 0],
        'Cultural' => ['orgs' => 0, 'events' => 0],
        'Sports'   => ['orgs' => 0, 'events' => 0],
        'Advocacy' => ['orgs' => 0, 'events' => 0],
    ];

    $c_cat_res = $conn->query("SELECT category, COUNT(*) as c FROM clubs WHERE deleted_at IS NULL GROUP BY category");
    if ($c_cat_res) {
        while ($r = $c_cat_res->fetch_assoc()) {
            $cat = $r['category'] ?? 'Academic';
            if (isset($admin_cat_stats[$cat])) {
                $admin_cat_stats[$cat]['orgs'] = (int)$r['c'];
            }
        }
    }

    $e_cat_res = $conn->query("SELECT c.category, COUNT(e.id) as c FROM events e LEFT JOIN clubs c ON c.id = e.club_id WHERE e.deleted_at IS NULL GROUP BY c.category");
    if ($e_cat_res) {
        while ($r = $e_cat_res->fetch_assoc()) {
            $cat = $r['category'] ?? 'Academic';
            if (isset($admin_cat_stats[$cat])) {
                $admin_cat_stats[$cat]['events'] = (int)$r['c'];
            }
        }
    }

    $admin_cat_labels = array_keys($admin_cat_stats);
    $admin_cat_orgs   = array_column($admin_cat_stats, 'orgs');
    $admin_cat_events = array_column($admin_cat_stats, 'events');
}

// ── Adviser Endorsement Queue & Active Members ────────────────
$adviser_event_proposals = [];
$adviser_active_members  = 0;
$adviser_club_name       = '';
$adviser_club_code       = '';
if ($sess_role === 'club_adviser') {
    // 1. Direct adviser assignment on clubs table
    $c_stmt = $conn->prepare("SELECT id, name, code FROM clubs WHERE adviser_user_id = ? AND deleted_at IS NULL LIMIT 1");
    if ($c_stmt) {
        $c_stmt->bind_param('i', $user_id);
        $c_stmt->execute();
        $res = $c_stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $adv_club = $res->fetch_assoc();
            $adv_cid = (int)$adv_club['id'];
            $adviser_club_name = !empty($adv_club['name']) ? $adv_club['name'] : $adv_club['code'];
            $adviser_club_code = !empty($adv_club['code']) ? $adv_club['code'] : $adv_club['name'];
        }
        $c_stmt->close();
    }
    // 2. Active membership check
    if (empty($adv_cid)) {
        $stmt = $conn->prepare("SELECT c.id, c.name, c.code FROM clubs c JOIN club_memberships cm ON cm.club_id = c.id WHERE cm.user_id = ? AND cm.status = 'Active' AND c.deleted_at IS NULL LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $adv_club = $res->fetch_assoc();
                $adv_cid = (int)$adv_club['id'];
                $adviser_club_name = !empty($adv_club['name']) ? $adv_club['name'] : $adv_club['code'];
                $adviser_club_code = !empty($adv_club['code']) ? $adv_club['code'] : $adv_club['name'];
            }
            $stmt->close();
        }
    }
    // 3. Fallback: match by username prefix (e.g. cssec.adviser -> CSSEC)
    if (empty($adv_cid)) {
        $sess_uname = $_SESSION['username'] ?? '';
        $prefix = strtoupper(explode('.', $sess_uname)[0] ?? '');
        if (!empty($prefix)) {
            $c_stmt = $conn->prepare("SELECT id, name, code FROM clubs WHERE (code = ? OR REPLACE(code, '-', '') = ?) AND status = 'Active' AND deleted_at IS NULL LIMIT 1");
            if ($c_stmt) {
                $c_stmt->bind_param('ss', $prefix, $prefix);
                $c_stmt->execute();
                $c_res = $c_stmt->get_result();
                if ($c_res && $c_res->num_rows > 0) {
                    $adv_club = $c_res->fetch_assoc();
                    $adv_cid = (int)$adv_club['id'];
                    $adviser_club_name = !empty($adv_club['name']) ? $adv_club['name'] : $adv_club['code'];
                    $adviser_club_code = !empty($adv_club['code']) ? $adv_club['code'] : $adv_club['name'];
                }
                $c_stmt->close();
            }
        }
    }
    if (!empty($adv_cid)) {
        // Count active student members in adviser's organization
        $m_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM club_memberships WHERE club_id = ? AND status = 'Active' AND role != 'Adviser'");
        if ($m_stmt) {
            $m_stmt->bind_param('i', $adv_cid);
            $m_stmt->execute();
            $adviser_active_members = (int)$m_stmt->get_result()->fetch_assoc()['c'];
            $m_stmt->close();
        }

        $stmt2 = $conn->prepare("SELECT e.id, e.title, e.event_date, e.venue, e.status, e.budget_amount, e.created_at, c.name AS club_name, c.code AS club_code, u.first_name, u.last_name FROM events e JOIN clubs c ON c.id = e.club_id LEFT JOIN users u ON u.id = e.created_by WHERE e.club_id = ? AND e.deleted_at IS NULL AND c.deleted_at IS NULL ORDER BY e.created_at DESC");
        $stmt2->bind_param('i', $adv_cid);
        $stmt2->execute();
        $adviser_event_proposals = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <script src="../js/chart.umd.min.js"></script>
  <script>if(typeof Chart==='undefined'){document.write('<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"><\/script>');}</script>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    .dash-charts-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
      margin-bottom: 24px;
      width: 100%;
      max-width: 100%;
      min-width: 0;
      box-sizing: border-box;
    }
    .kpi-grid-8 {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 24px;
      width: 100%;
      max-width: 100%;
      min-width: 0;
      box-sizing: border-box;
    }
    .chart-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 16px 18px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
      min-width: 0 !important;
      max-width: 100% !important;
      width: 100% !important;
      box-sizing: border-box !important;
      overflow: hidden !important;
    }
    .chart-card-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 14px;
      padding-bottom: 10px;
      border-bottom: 1px solid #f1f5f9;
      gap: 10px;
      flex-wrap: wrap;
      min-width: 0;
    }
    .chart-card-header > div {
      min-width: 0;
      flex: 1;
    }
    .chart-card-header h4 {
      margin: 0;
      font-size: 0.92rem;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .chart-container-wrap {
      position: relative;
      height: 230px;
      width: 100% !important;
      max-width: 100% !important;
      min-width: 0 !important;
      overflow: hidden !important;
      box-sizing: border-box !important;
    }
    .chart-container-wrap canvas {
      max-width: 100% !important;
      display: block !important;
    }
    .badge-institutional { background: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe; padding: 2px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 800; }
    .badge-urgent { background: #fee2e2; color: #991b1b; font-weight: 800; padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; display: inline-flex; align-items: center; gap: 4px; }
    .badge-normal { background: #f1f5f9; color: #475569; font-weight: 700; padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; white-space: nowrap; flex-shrink: 0; }
    
    /* ── SSC Insight & Chart Elements ────────────────────────────── */
    .budget-kpi-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(88px, 1fr));
      gap: 6px;
      margin-bottom: 12px;
      width: 100%;
      max-width: 100%;
      min-width: 0;
      box-sizing: border-box;
    }
    .budget-kpi-chip {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 6px 8px;
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
      overflow: hidden;
      box-sizing: border-box;
    }
    .budget-kpi-chip .b-lbl {
      font-size: 0.64rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      color: #64748b;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .budget-kpi-chip .b-val {
      font-size: 0.82rem;
      font-weight: 800;
      color: #0f172a;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .att-layout-grid {
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 12px;
      align-items: center;
      width: 100%;
      max-width: 100%;
      min-width: 0;
      box-sizing: border-box;
    }
    .att-tiles-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
      width: 100%;
      min-width: 0;
      box-sizing: border-box;
    }
    .att-tile {
      border-radius: 8px;
      padding: 8px 10px;
      display: flex;
      flex-direction: column;
      gap: 2px;
      border: 1px solid #e2e8f0;
      min-width: 0;
      overflow: hidden;
      box-sizing: border-box;
    }
    .att-tile .a-lbl {
      font-size: 0.68rem;
      font-weight: 700;
      color: #64748b;
      display: flex;
      align-items: center;
      gap: 4px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .att-tile .a-val {
      font-size: 1.05rem;
      font-weight: 800;
    }
    .dist-legend-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 6px;
      margin-top: 12px;
      padding-top: 10px;
      border-top: 1px dashed #e2e8f0;
      width: 100%;
      min-width: 0;
      box-sizing: border-box;
    }
    .dist-legend-item {
      text-align: center;
      padding: 5px 3px;
      border-radius: 6px;
      background: #f8fafc;
      border: 1px solid #f1f5f9;
      min-width: 0;
      overflow: hidden;
      box-sizing: border-box;
    }
    .dist-legend-item .d-lbl {
      font-size: 0.65rem;
      font-weight: 700;
      color: #64748b;
      margin-bottom: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .dist-legend-item .d-val {
      font-size: 0.88rem;
      font-weight: 800;
      color: #0f172a;
    }

    @media (max-width: 1100px) {
      .kpi-grid-8 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 992px) {
      .dash-charts-grid {
        grid-template-columns: minmax(0, 1fr) !important;
      }
      .att-layout-grid {
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 16px;
      }
      .dist-legend-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
    }
    @media (max-width: 640px) {
      .kpi-grid-8 {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px;
      }
      .chart-card {
        padding: 12px 14px !important;
        border-radius: 12px !important;
      }
      .budget-kpi-row {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 6px;
      }
      .chart-container-wrap {
        height: 200px !important;
      }
      .chart-card-header h4 {
        font-size: 0.86rem !important;
      }
    }
    @media (max-width: 400px) {
      .kpi-grid-8 {
        grid-template-columns: minmax(0, 1fr) !important;
      }
      .att-tiles-grid {
        grid-template-columns: minmax(0, 1fr) !important;
      }
    }
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'dashboard';
require_once __DIR__ . '/../shared/sidebar.php';
?>

<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
      <i class="fa-solid fa-bars"></i>
    </button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap" id="topbarSearchWrap">
        <i class="fa-solid fa-magnifying-glass search-icon"></i>
        <input type="text" placeholder="Search modules, events, clubs..." autocomplete="off" />
        <button type="button" class="search-clear-btn" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <button class="topbar-chat-btn" id="chatFabBtn" title="Inter-Club Messenger" type="button">
        <i class="fa-solid fa-comment-dots"></i>
        <span class="chat-unread-badge" id="chatUnreadBadge" style="display:none;"></span>
      </button>
      <?php if (($sess_role ?? $_SESSION['role'] ?? '') === 'student'): ?>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button">
        <i class="fa-solid fa-qrcode"></i>
      </button>
      <?php endif; ?>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?php if (!empty($sess_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $sess_pic)): ?>
          <img src="../uploads/avatars/<?= htmlspecialchars($sess_pic) ?>" alt="Profile"/>
        <?php else: ?>
          <?= $sess_initial ?>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <!-- Page Title Bar -->
    <div class="page-title-bar" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
      <h2 class="page-title">
        <i class="fa-solid fa-gauge" style="color:#2563eb;"></i>
        Co-Curricular Executive Dashboard
      </h2>
    </div>

    <!-- Content Body Grid Container -->
    <div class="content-body">


      <!-- ══════════════════════════════════════════════════════════════
           ROLE STAT CARDS ROW
      ══════════════════════════════════════════════════════════════ -->
      <?php if ($sess_role === 'ssc'): ?>
        <!-- SSC Welcome Banner -->
        <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #fff; padding: 20px 24px; border-radius: 12px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.15);">
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div>
              <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.85; font-weight: 700; margin-bottom: 4px;">
                <i class="fa-solid fa-building-columns"></i> Supreme Student Council &bull; Executive Governance
              </div>
              <h3 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #fff;">
                Welcome, <?= htmlspecialchars($sess_first . ' ' . $sess_last) ?>
              </h3>
              <p style="margin: 4px 0 0; font-size: 0.85rem; opacity: 0.9;">
                Institutional oversight of student organizations, calendar clearances, and budget reviews for AY 2025–2026.
              </p>
            </div>
            <div style="display: flex; gap: 10px;">
              <a href="club_directory.php" class="card-btn" style="background: rgba(255,255,255,0.2); color: #fff; border: 1px solid rgba(255,255,255,0.3); backdrop-filter: blur(4px);">
                <i class="fa-solid fa-sitemap"></i> Organizations (<?= $total_clubs ?>)
              </a>
              <a href="events.php?view=pipeline" class="card-btn" style="background: #fff; color: #1e3a8a; font-weight: 700;">
                <i class="fa-solid fa-calendar-check"></i> Event Proposals
              </a>
            </div>
          </div>
        </div>

        <!-- 8 SSC Strategic & Operational KPI Cards -->
        <div class="kpi-grid-8" style="margin-bottom: 24px;">
          <!-- Card 1: Active Organizations -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> Active Orgs</div>
            <div class="card-amount"><?= $total_clubs ?></div>
            <div class="card-detail">Accredited student organizations</div>
          </div>
          <!-- Card 2: Pending Charter Reviews -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-file-signature" style="color:#f59e0b;"></i> Charter Reviews</div>
            <div class="card-amount" style="<?= $ssc_pending_charters_cnt > 0 ? 'color:#b45309;' : '' ?>"><?= $ssc_pending_charters_cnt ?></div>
            <div class="card-detail">Org charters awaiting review</div>
          </div>
          <!-- Card 3: Pending Event Endorsements -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-calendar-plus" style="color:#3b82f6;"></i> Event Reviews</div>
            <div class="card-amount" style="<?= $ssc_pending_events_cnt > 0 ? 'color:#1d4ed8;' : '' ?>"><?= $ssc_pending_events_cnt ?></div>
            <div class="card-detail">Proposals awaiting endorsement</div>
          </div>
          <!-- Card 4: Pending Achievement Reviews -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-trophy" style="color:#f59e0b;"></i> Achievements</div>
            <div class="card-amount" style="<?= $ssc_pending_achievements_cnt > 0 ? 'color:#b45309;' : '' ?>"><?= $ssc_pending_achievements_cnt ?></div>
            <div class="card-detail">Awards awaiting verification</div>
          </div>
          <!-- Card 5: Active Campus Events -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-calendar-days" style="color:#10b981;"></i> Active Events</div>
            <div class="card-amount" style="color:#047857;"><?= $active_campus_events ?></div>
            <div class="card-detail">Scheduled &amp; ongoing activities</div>
          </div>
          <!-- Card 6: Active Org Members -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-users" style="color:#06b6d4;"></i> Org Members</div>
            <div class="card-amount"><?= $total_students_in_orgs ?></div>
            <div class="card-detail">Active student club rosters</div>
          </div>
          <!-- Card 7: Attendance Rate -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-user-check" style="color:#8b5cf6;"></i> Attendance Rate</div>
            <div class="card-amount" style="color:#6d28d9;"><?= $ssc_attendance_rate ?>%</div>
            <div class="card-detail">Overall event check-in compliance</div>
          </div>
          <!-- Card 8: Open Governance Tasks -->
          <div class="info-card" style="<?= $ssc_open_tasks > 0 ? 'background: #fffbeb; border: 1px solid #fde68a;' : '' ?>">
            <div class="card-label"><i class="fa-solid fa-list-check" style="color:#d97706;"></i> Open Tasks</div>
            <div class="card-amount" style="<?= $ssc_open_tasks > 0 ? 'color:#b45309;' : '' ?>"><?= $ssc_open_tasks ?></div>
            <div class="card-detail">Total pending governance actions</div>
          </div>
        </div>

      <?php elseif ($sess_role === 'staff'): ?>
        <!-- Staff Welcome Banner -->
        <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%); color: #fff; padding: 20px 24px; border-radius: 12px; box-shadow: 0 4px 14px rgba(13, 148, 136, 0.2);">
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div>
              <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.9; font-weight: 700; margin-bottom: 4px; color: #ccfbf1;">
                <i class="fa-solid fa-id-card-clip"></i> Office of Student Affairs &bull; Operational Core
              </div>
              <h3 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #fff;">
                Welcome, <?= htmlspecialchars($sess_first . ' ' . $sess_last) ?>
              </h3>
              <p style="margin: 4px 0 0; font-size: 0.85rem; opacity: 0.95; color: #f0fdfa;">
                Institutional oversight of student organizations, activity clearances, roster verification, and attendance tracking.
              </p>
            </div>
            <div style="display: flex; gap: 10px;">
              <a href="events.php?view=pipeline" class="card-btn" style="background: rgba(255,255,255,0.2); color: #fff; border: 1px solid rgba(255,255,255,0.3); backdrop-filter: blur(4px);">
                <i class="fa-solid fa-calendar-check"></i> Event Proposals
              </a>
              <a href="tracking_attendance_list.php" class="card-btn" style="background: #fff; color: #0f766e; font-weight: 700;">
                <i class="fa-solid fa-qrcode"></i> Attendance Monitor
              </a>
            </div>
          </div>
        </div>

        <!-- 8 Staff Operational KPI Cards -->
        <div class="kpi-grid-8" style="margin-bottom: 24px;">
          <!-- Card 1: Active Organizations -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-sitemap" style="color:#0d9488;"></i> Active Orgs</div>
            <div class="card-amount"><?= $total_clubs ?></div>
            <div class="card-detail">Accredited student organizations</div>
          </div>
          <!-- Card 2: Pending Event Proposals -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-calendar-plus" style="color:#2563eb;"></i> Event Clearances</div>
            <div class="card-amount" style="<?= $ssc_pending_events_cnt > 0 ? 'color:#1d4ed8;' : '' ?>"><?= $ssc_pending_events_cnt ?></div>
            <div class="card-detail">Activities in approval pipeline</div>
          </div>
          <!-- Card 3: Pending Achievements -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-trophy" style="color:#f59e0b;"></i> Achievements</div>
            <div class="card-amount" style="<?= $ssc_pending_achievements_cnt > 0 ? 'color:#b45309;' : '' ?>"><?= $ssc_pending_achievements_cnt ?></div>
            <div class="card-detail">Awards awaiting validation</div>
          </div>
          <!-- Card 4: Membership Applications -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-user-plus" style="color:#8b5cf6;"></i> Applications</div>
            <div class="card-amount" style="<?= $ssc_pending_apps_cnt > 0 ? 'color:#6d28d9;' : '' ?>"><?= $ssc_pending_apps_cnt ?></div>
            <div class="card-detail">Membership applications in queue</div>
          </div>
          <!-- Card 5: Active Campus Events -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-calendar-days" style="color:#10b981;"></i> Active Events</div>
            <div class="card-amount" style="color:#047857;"><?= $active_campus_events ?></div>
            <div class="card-detail">Scheduled &amp; ongoing activities</div>
          </div>
          <!-- Card 6: Active Org Members -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-users" style="color:#06b6d4;"></i> Org Members</div>
            <div class="card-amount"><?= $total_students_in_orgs ?></div>
            <div class="card-detail">Students across active rosters</div>
          </div>
          <!-- Card 7: Attendance Rate -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-user-check" style="color:#6366f1;"></i> Attendance Rate</div>
            <div class="card-amount" style="color:#4f46e5;"><?= $ssc_attendance_rate ?>%</div>
            <div class="card-detail">Overall event check-in compliance</div>
          </div>
          <!-- Card 8: Open Operational Tasks -->
          <div class="info-card" style="<?= $ssc_open_tasks > 0 ? 'background: #fffbeb; border: 1px solid #fde68a;' : '' ?>">
            <div class="card-label"><i class="fa-solid fa-list-check" style="color:#d97706;"></i> Open Tasks</div>
            <div class="card-amount" style="<?= $ssc_open_tasks > 0 ? 'color:#b45309;' : '' ?>"><?= $ssc_open_tasks ?></div>
            <div class="card-detail">Total pending operational actions</div>
          </div>
        </div>

      <?php elseif ($sess_role === 'admin' || $sess_role === 'super_admin'): ?>
        <!-- Admin / Super Admin Welcome Banner -->
        <div class="card" style="margin-bottom: 20px; background: <?= ($sess_role === 'super_admin') ? 'linear-gradient(135deg, #1e1b4b 0%, #4338ca 100%)' : 'linear-gradient(135deg, #0f172a 0%, #1e293b 100%)' ?>; color: #fff; padding: 20px 24px; border-radius: 12px; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.2);">
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div>
              <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.85; font-weight: 700; margin-bottom: 4px; color: <?= ($sess_role === 'super_admin') ? '#c7d2fe' : '#94a3b8' ?>;">
                <i class="fa-solid <?= ($sess_role === 'super_admin') ? 'fa-crown' : 'fa-shield-halved' ?>" style="color:<?= ($sess_role === 'super_admin') ? '#a78bfa' : '#38bdf8' ?>;"></i> <?= ($sess_role === 'super_admin') ? 'Super Administrator &bull; Institutional Root Authority' : 'Enterprise Administration &bull; System Master Control' ?>
              </div>
              <h3 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #fff;">
                <?= ($sess_role === 'super_admin') ? 'Super Administrator' : 'Administrator' ?> <?= htmlspecialchars($sess_first . ' ' . $sess_last) ?>
              </h3>
              <p style="margin: 4px 0 0; font-size: 0.85rem; color: #cbd5e1;">
                <?= ($sess_role === 'super_admin') ? 'Highest institutional authority over global RBAC permissions, account lifecycle, database master controls, and security auditing.' : 'Full system authority over RBAC security, user directories, master catalogs, and institutional disbursements.' ?>
              </p>
            </div>
            <div style="display: flex; gap: 10px;">
              <a href="admin_system.php" class="card-btn" style="background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.25);">
                <i class="fa-solid fa-users-gear"></i> User Management
              </a>
              <a href="admin_audit.php" class="card-btn" style="background: <?= ($sess_role === 'super_admin') ? '#a78bfa' : '#38bdf8' ?>; color: #0f172a; font-weight: 700;">
                <i class="fa-solid fa-shield-cat"></i> Audit Logs
              </a>
            </div>
          </div>
        </div>

        <!-- 8 Admin Enterprise & Security KPI Cards -->
        <div class="kpi-grid-8" style="margin-bottom: 24px;">
          <!-- Card 1: Total Accounts -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-users-gear" style="color:#2563eb;"></i> Total Accounts</div>
            <div class="card-amount"><?= $total_accounts ?></div>
            <div class="card-detail">All active and inactive user accounts.</div>
          </div>
          <!-- Card 2: Active Organizations -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-sitemap" style="color:#0ea5e9;"></i> Active Organizations</div>
            <div class="card-amount"><?= $active_orgs_count ?></div>
            <div class="card-detail">Recognized organizations.</div>
          </div>
          <!-- Card 3: Pending Workflows -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-clock-rotate-left" style="color:#f59e0b;"></i> Pending Workflows</div>
            <div class="card-amount" style="<?= $pending_workflows_count > 0 ? 'color:#b45309;' : '' ?>"><?= $pending_workflows_count ?></div>
            <div class="card-detail">Event proposals awaiting review.</div>
          </div>
          <!-- Card 4: Active Campus Events -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-calendar-check" style="color:#10b981;"></i> Active Events</div>
            <div class="card-amount" style="color:#047857;"><?= $active_campus_events ?></div>
            <div class="card-detail">Approved &amp; active activities.</div>
          </div>
          <!-- Card 5: System Activities 24h -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-bolt" style="color:#6366f1;"></i> System Activities 24h</div>
            <div class="card-amount" style="color:#4338ca;"><?= $audit_24h_count ?></div>
            <div class="card-detail">Recent audit volume.</div>
          </div>
          <!-- Card 6: Failed / Warning Events -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i> Failed / Warning Events</div>
            <div class="card-amount" style="<?= $failed_warning_count > 0 ? 'color:#dc2626;' : '' ?>"><?= $failed_warning_count ?></div>
            <div class="card-detail">Security and health warning count.</div>
          </div>
          <!-- Card 7: Pending Admin Approvals -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-stamp" style="color:#8b5cf6;"></i> Pending Approvals</div>
            <div class="card-amount" style="<?= $pending_admin_approvals_count > 0 ? 'color:#6d28d9;' : '' ?>"><?= $pending_admin_approvals_count ?></div>
            <div class="card-detail">Items waiting for administrative action.</div>
          </div>
          <!-- Card 8: System Health -->
          <div class="info-card" style="background:#f0fdf4; border:1px solid #bbf7d0;">
            <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> System Health</div>
            <div class="card-amount" style="color:#15803d; font-size:1.35rem;"><?= $sys_health_status ?></div>
            <div class="card-detail">Overall health state.</div>
          </div>
        </div>

      <?php else: ?>
        <!-- Student & Adviser Standard Info Row -->
        <div class="info-row">
          <!-- Welcome Card -->
          <div class="info-card">
            <div class="card-label">
              <i class="fa-solid fa-user-circle"></i> Welcome Back
            </div>
            <div class="card-name"><?= $sess_first . ' ' . $sess_last ?></div>
            <div class="card-detail">
              <?php if ($sess_role === 'student' && !empty($student_info)): ?>
                <div style="margin-top:6px; font-size:0.78rem; color:#64748b; line-height:1.4;">
                  <?= htmlspecialchars($student_info['student_number'] ?? '2026-STU') ?> &bull; <?= htmlspecialchars($student_info['course']) ?><br/>
                  <?= htmlspecialchars($student_info['year_level']) ?> - Section <?= htmlspecialchars($student_info['section']) ?>
                </div>
              <?php elseif ($sess_role === 'club_adviser' && !empty($adv_club)): ?>
                <div style="margin-top:6px; font-size:0.78rem; color:#64748b; line-height:1.4;">
                  Adviser &bull; <strong style="color:#1a3a8c;"><?= htmlspecialchars($adv_club['code']) ?></strong><br/>
                  <span style="color:#334155; font-weight:600;"><?= htmlspecialchars($adv_club['name']) ?></span>
                </div>
              <?php else: ?>
                <?= htmlspecialchars($role_title) ?>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($sess_role === 'club_adviser'): ?>
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-users"></i> Active Members</div>
              <div class="card-amount"><?= $adviser_active_members ?></div>
              <div class="card-detail"><?= !empty($adviser_club_code) ? htmlspecialchars($adviser_club_code) . ' Members' : 'Organization Members' ?></div>
            </div>
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-calendar-days"></i> Campus Events</div>
              <div class="card-amount"><?= $active_campus_events ?></div>
              <div class="card-detail">Approved Campus Activities</div>
            </div>
          <?php else: ?>
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-sitemap"></i> Active Orgs</div>
              <div class="card-amount"><?= $active_clubs_joined ?></div>
              <div class="card-detail">Joined Accredited Clubs</div>
            </div>
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-calendar-days"></i> Campus Events</div>
              <div class="card-amount"><?= $active_campus_events ?></div>
              <div class="card-detail">Approved Campus Activities</div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════════════
           SSC & STAFF OPERATIONAL DASHBOARD VIEW
      ══════════════════════════════════════════════════════════════ -->
      <?php if ($sess_role === 'ssc' || $sess_role === 'staff'): ?>
        
        <!-- ══════════════════════════════════════════════════════════════
             SSC CHARTS & INSIGHT CARDS (5 Core Components)
        ══════════════════════════════════════════════════════════════ -->
        <div style="margin-bottom: 24px;">

          <!-- Row 1: Organization Distribution & Membership by Program -->
          <div class="dash-charts-grid" style="margin-bottom: 20px;">
            
            <!-- 1. Organization Distribution -->
            <div class="chart-card">
              <div class="chart-card-header">
                <div>
                  <h4><i class="fa-solid fa-chart-pie" style="color:#2563eb;"></i> Organization Distribution</h4>
                  <p style="margin:3px 0 0; font-size:0.75rem; color:#64748b;">Organizations by category such as academic, cultural, sports, advocacy and other configured types.</p>
                </div>
                <span class="badge-normal" style="font-weight:700;">Total: <?= $total_orgs_count ?> Orgs</span>
              </div>
              <div class="chart-container-wrap">
                <canvas id="sscCategoryChart"></canvas>
              </div>
              <div class="dist-legend-grid">
                <div class="dist-legend-item">
                  <div class="d-lbl" style="color:#1e3a8a;"><i class="fa-solid fa-circle" style="font-size:0.5rem; color:#1e3a8a;"></i> Academic</div>
                  <div class="d-val"><?= $club_categories_counts['Academic'] ?? 0 ?></div>
                </div>
                <div class="dist-legend-item">
                  <div class="d-lbl" style="color:#2563eb;"><i class="fa-solid fa-circle" style="font-size:0.5rem; color:#2563eb;"></i> Cultural</div>
                  <div class="d-val"><?= $club_categories_counts['Cultural'] ?? 0 ?></div>
                </div>
                <div class="dist-legend-item">
                  <div class="d-lbl" style="color:#10b981;"><i class="fa-solid fa-circle" style="font-size:0.5rem; color:#10b981;"></i> Sports</div>
                  <div class="d-val"><?= $club_categories_counts['Sports'] ?? 0 ?></div>
                </div>
                <div class="dist-legend-item">
                  <div class="d-lbl" style="color:#f59e0b;"><i class="fa-solid fa-circle" style="font-size:0.5rem; color:#f59e0b;"></i> Advocacy</div>
                  <div class="d-val"><?= $club_categories_counts['Advocacy'] ?? 0 ?></div>
                </div>
              </div>
            </div>

            <!-- 2. Membership by Program -->
            <div class="chart-card">
              <div class="chart-card-header">
                <div>
                  <h4><i class="fa-solid fa-graduation-cap" style="color:#7c3aed;"></i> Membership by Program</h4>
                  <p style="margin:3px 0 0; font-size:0.75rem; color:#64748b;">Student participation grouped by academic program.</p>
                </div>
                <span class="badge-normal" style="font-weight:700; color:#7c3aed; background:#f5f3ff;">Top Programs</span>
              </div>
              <div class="chart-container-wrap">
                <canvas id="sscProgramChart"></canvas>
              </div>
            </div>

          </div>

          <!-- Row 2: Event Proposals Pipeline & Attendance Performance -->
          <div class="dash-charts-grid" style="margin-bottom: 20px;">
            
            <!-- 3. Event Proposals Pipeline -->
            <div class="chart-card">
              <div class="chart-card-header">
                <div>
                  <h4><i class="fa-solid fa-calendar-check" style="color:#2563eb;"></i> Event Proposals Pipeline</h4>
                  <p style="margin:3px 0 0; font-size:0.75rem; color:#64748b;">Review status and lifecycle of collegiate activity proposals.</p>
                </div>
                <span class="badge-normal" style="font-weight:700; color:#2563eb; background:#eff6ff;">Proposals</span>
              </div>
              
              <!-- Lifecycle Stat Chips -->
              <div class="budget-kpi-row">
                <div class="budget-kpi-chip" style="border-left: 3px solid #3b82f6;">
                  <span class="b-lbl">Pending SSC</span>
                  <span class="b-val" style="color:#3b82f6;"><?= $ssc_event_pipeline['pending_ssc'] ?></span>
                </div>
                <div class="budget-kpi-chip" style="border-left: 3px solid #f59e0b;">
                  <span class="b-lbl">Pending Admin</span>
                  <span class="b-val" style="color:#d97706;"><?= $ssc_event_pipeline['pending_admin'] ?></span>
                </div>
                <div class="budget-kpi-chip" style="border-left: 3px solid #10b981;">
                  <span class="b-lbl">Approved</span>
                  <span class="b-val" style="color:#059669;"><?= $ssc_event_pipeline['approved'] ?></span>
                </div>
                <div class="budget-kpi-chip" style="border-left: 3px solid #6366f1;">
                  <span class="b-lbl">Completed</span>
                  <span class="b-val" style="color:#4338ca;"><?= $ssc_event_pipeline['completed'] ?></span>
                </div>
                <div class="budget-kpi-chip" style="border-left: 3px solid #ef4444;">
                  <span class="b-lbl">Rejected</span>
                  <span class="b-val" style="color:#dc2626;"><?= $ssc_event_pipeline['rejected'] ?></span>
                </div>
              </div>

              <div class="chart-container-wrap" style="height: 195px;">
                <canvas id="sscEventPipelineChart"></canvas>
              </div>
            </div>

            <!-- 4. Attendance Performance -->
            <div class="chart-card">
              <div class="chart-card-header">
                <div>
                  <h4><i class="fa-solid fa-clipboard-user" style="color:#0284c7;"></i> Attendance Performance</h4>
                  <p style="margin:3px 0 0; font-size:0.75rem; color:#64748b;">Registered, attended, absent and overall attendance rate.</p>
                </div>
                <span class="badge-normal" style="font-weight:700; color:#0284c7; background:#e0f2fe;"><?= $ssc_attendance_rate ?>% Turnout</span>
              </div>

              <div class="att-layout-grid">
                <div class="att-tiles-grid">
                  <div class="att-tile" style="background:#eff6ff; border-color:#bfdbfe;">
                    <span class="a-lbl"><i class="fa-solid fa-users" style="color:#2563eb;"></i> Registered</span>
                    <span class="a-val" style="color:#1e40af;"><?= number_format($tot_registered) ?></span>
                  </div>
                  <div class="att-tile" style="background:#f0fdf4; border-color:#bbf7d0;">
                    <span class="a-lbl"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Attended</span>
                    <span class="a-val" style="color:#15803d;"><?= number_format($tot_attended) ?></span>
                  </div>
                  <div class="att-tile" style="background:#fffbeb; border-color:#fef3c7;">
                    <span class="a-lbl"><i class="fa-solid fa-circle-xmark" style="color:#d97706;"></i> Absent</span>
                    <span class="a-val" style="color:#b45309;"><?= number_format($tot_absent) ?></span>
                  </div>
                  <div class="att-tile" style="background:#faf5ff; border-color:#e9d5ff;">
                    <span class="a-lbl"><i class="fa-solid fa-percent" style="color:#7c3aed;"></i> Overall Rate</span>
                    <span class="a-val" style="color:#6b21a8;"><?= $ssc_attendance_rate ?>%</span>
                  </div>
                </div>

                <div class="chart-container-wrap" style="height: 195px;">
                  <canvas id="sscAttendanceChart"></canvas>
                </div>
              </div>
            </div>

          </div>

          <!-- Row 3: Event Activity Trend (Full Width) -->
          <div class="chart-card">
            <div class="chart-card-header">
              <div>
                <h4><i class="fa-solid fa-chart-line" style="color:#ea580c;"></i> Event Activity Trend</h4>
                <p style="margin:3px 0 0; font-size:0.75rem; color:#64748b;">Monthly approved / completed / rejected activity volume.</p>
              </div>
              <div style="display:flex; gap:8px;">
                <span class="badge-normal" style="font-weight:700;"><i class="fa-solid fa-calendar-days" style="color:#64748b;"></i> Past 6 Months</span>
              </div>
            </div>
            <div class="chart-container-wrap" style="height: 250px;">
              <canvas id="sscEventTrendChart"></canvas>
            </div>
          </div>

        </div>


        <!-- SSC Executive Council Action Queue Table (Section 5.3) -->
        <div class="card" style="margin-bottom:24px;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:10px;">
            <div>
              <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-inbox" style="color:#2563eb;"></i> Council Action Queue</h3>
            </div>
            <span style="font-size:0.78rem; font-weight:700; background:#eff6ff; color:#1e40af; padding:4px 10px; border-radius:20px;">
              <?= count($ssc_action_queue) ?> Items in Pipeline
            </span>
          </div>

          <div class="table-wrap">
            <table class="table-wide data-table" id="councilActionQueueTable">
              <thead>
                <tr>
                  <th>Ref No.</th>
                  <th>Type</th>
                  <th>Organization</th>
                  <th>Proposal Title</th>
                  <th>Submitted By</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th>Pending</th>
                  <th>Priority</th>
                  <th style="text-align:center; width:155px;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($ssc_action_queue)): ?>
                  <tr><td colspan="10" class="empty-state-cell" style="text-align:center; color:#94a3b8; padding:36px 16px;">
                    <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; width:100%; margin:0 auto;">
                      <i class="fa-solid fa-circle-check" style="font-size:2.2rem; color:#10b981; display:inline-block; margin-bottom:10px;"></i>
                      <span style="font-weight:600; font-size:0.9rem; color:#475569; text-align:center;">All pending council proposals and reviews have been cleared.</span>
                    </div>
                  </td></tr>
                <?php else: ?>
                  <?php foreach ($ssc_action_queue as $item): ?>
                    <tr>
                      <td><code style="font-weight:700; color:#334155;"><?= htmlspecialchars($item['ref']) ?></code></td>
                      <td>
                        <?php if ($item['type'] === 'Charter Application'): ?>
                          <span class="badge-info" style="background:#e0f2fe; color:#0369a1;"><i class="fa-solid fa-sitemap"></i> Charter</span>
                        <?php elseif ($item['type'] === 'Event Proposal'): ?>
                          <span class="badge-active" style="background:#dcfce7; color:#166534;"><i class="fa-solid fa-calendar-day"></i> Event</span>
                        <?php elseif ($item['type'] === 'Budget Request'): ?>
                          <span class="badge-info" style="background:#e0e7ff; color:#3730a3;"><i class="fa-solid fa-hand-holding-dollar"></i> Budget</span>
                        <?php elseif ($item['type'] === 'Achievement Verification'): ?>
                          <span class="badge-institutional" style="background:#fef3c7; color:#92400e;"><i class="fa-solid fa-trophy"></i> Achievement</span>
                        <?php else: ?>
                          <span class="badge-info"><?= htmlspecialchars($item['type']) ?></span>
                        <?php endif; ?>
                      </td>
                      <td><strong><?= htmlspecialchars($item['org']) ?></strong></td>
                      <td><?= htmlspecialchars($item['title']) ?></td>
                      <td><span style="font-size:0.82rem; color:#475569;"><?= htmlspecialchars($item['submitted_by']) ?></span></td>
                      <td><span style="font-size:0.8rem; color:#64748b;"><?= date('M d, Y', strtotime($item['date'])) ?></span></td>
                      <td><span class="badge-warning"><?= htmlspecialchars($item['status']) ?></span></td>
                      <td><span style="font-size:0.82rem; font-weight:600; color:#475569;"><?= $item['days'] ?>d</span></td>
                      <td>
                        <?php if ($item['priority'] === 'Urgent'): ?>
                          <span class="badge-urgent"><i class="fa-solid fa-triangle-exclamation"></i> Urgent</span>
                        <?php else: ?>
                          <span class="badge-normal">Normal</span>
                        <?php endif; ?>
                      </td>
                      <td style="text-align:center; vertical-align:middle;">
                        <a href="<?= htmlspecialchars($item['action_url']) ?>" class="card-btn btn-sm council-action-btn" style="background:#2563eb; color:#fff; width:142px; min-width:142px; max-width:142px; height:34px; display:inline-flex; align-items:center; justify-content:center; text-align:center; box-sizing:border-box; font-size:0.8rem; font-weight:600; border-radius:6px; white-space:nowrap; text-decoration:none; padding:0 10px;">
                          <?= htmlspecialchars($item['action_label']) ?>
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <!-- ══════════════════════════════════════════════════════════════
           ADMIN & SUPER ADMIN ENTERPRISE DASHBOARD VIEW
      ══════════════════════════════════════════════════════════════ -->
      <?php elseif ($sess_role === 'admin' || $sess_role === 'super_admin'): ?>

        <!-- Admin Charts Row: Workflow Pipeline & Org Category Distribution -->
        <div class="dash-charts-grid">
          <div class="chart-card">
            <div class="chart-card-header">
              <h4><i class="fa-solid fa-arrows-split-up-and-left" style="color:#2563eb;"></i> Event Proposal Pipeline</h4>
              <span style="font-size:0.75rem; color:#64748b; font-weight:600;">Multi-Stage Backlog</span>
            </div>
            <div class="chart-container-wrap">
              <canvas id="adminPipelineChart"></canvas>
            </div>
          </div>
          <div class="chart-card">
            <div class="chart-card-header">
              <h4><i class="fa-solid fa-chart-column" style="color:#16a34a;"></i> Organizations &amp; Events by Category</h4>
              <span style="font-size:0.75rem; color:#64748b; font-weight:600;">Category Breakdown</span>
            </div>
            <div class="chart-container-wrap">
              <canvas id="adminCategoryChart"></canvas>
            </div>
          </div>
        </div>

        <!-- Admin Action Queue (Section 6.3) -->
        <div class="card" style="margin-bottom:24px; padding:20px; border-radius:10px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
            <h3 style="margin:0; font-size:1.25rem; font-weight:700; color:#0e4a7b;">Admin action queue</h3>
            <span style="font-size:0.78rem; font-weight:700; background:#f0fdf4; color:#166534; padding:4px 10px; border-radius:20px;">
              <?= count($admin_action_queue) ?> Items Waiting
            </span>
          </div>

          <div class="table-wrap" style="overflow-x:auto;">
            <table class="data-table" id="adminActionQueueTable" style="width:100%; border-collapse:collapse; background:#fff; font-size:0.875rem;">
              <thead>
                <tr style="background:#0e4a7b; color:#ffffff;">
                  <th style="padding:10px 14px; text-align:left; font-weight:700; border:1px solid #0e4a7b;">Reference</th>
                  <th style="padding:10px 14px; text-align:left; font-weight:700; border:1px solid #0e4a7b;">Type</th>
                  <th style="padding:10px 14px; text-align:left; font-weight:700; border:1px solid #0e4a7b;">Organization</th>
                  <th style="padding:10px 14px; text-align:left; font-weight:700; border:1px solid #0e4a7b;">Description</th>
                  <th style="padding:10px 14px; text-align:left; font-weight:700; border:1px solid #0e4a7b;">Event Date</th>
                  <th style="padding:10px 14px; text-align:left; font-weight:700; border:1px solid #0e4a7b;">SSC Status</th>
                  <th style="padding:10px 14px; text-align:left; font-weight:700; border:1px solid #0e4a7b;">Admin Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($admin_action_queue)): ?>
                  <tr>
                    <td colspan="7" style="text-align:center; color:#64748b; padding:32px; background:#f8fafc; border:1px solid #cbd5e1;">
                      <i class="fa-solid fa-circle-check" style="font-size:2rem; color:#10b981; display:block; margin-bottom:8px;"></i>
                      No items currently waiting for final admin action.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($admin_action_queue as $aq): ?>
                    <tr style="border-bottom:1px solid #cbd5e1;">
                      <td style="padding:10px 14px; border:1px solid #cbd5e1; font-family:monospace; font-weight:600; color:#1e293b;">
                        <?= htmlspecialchars($aq['ref']) ?>
                      </td>
                      <td style="padding:10px 14px; border:1px solid #cbd5e1;">
                        <span class="badge-active" style="background:#dcfce7; color:#166534; padding:3px 8px; border-radius:6px; font-weight:700; font-size:0.75rem;">
                          <i class="fa-solid fa-calendar-day"></i> Event
                        </span>
                      </td>
                      <td style="padding:10px 14px; border:1px solid #cbd5e1; font-weight:600; color:#0f172a;">
                        <?= htmlspecialchars($aq['organization']) ?>
                      </td>
                      <td style="padding:10px 14px; border:1px solid #cbd5e1; color:#334155;">
                        <?= htmlspecialchars($aq['description']) ?>
                      </td>
                      <td style="padding:10px 14px; border:1px solid #cbd5e1; font-weight:600; color:#0f172a;">
                        <?= htmlspecialchars($aq['amount_date']) ?>
                      </td>
                      <td style="padding:10px 14px; border:1px solid #cbd5e1; color:#1e293b;">
                        <span style="background:#e0e7ff; color:#3730a3; padding:3px 8px; border-radius:6px; font-weight:700; font-size:0.75rem;">
                          <?= htmlspecialchars($aq['ssc_status']) ?>
                        </span>
                      </td>
                      <td style="padding:10px 14px; border:1px solid #cbd5e1;">
                        <button type="button" onclick="handleAdminEventApprove(<?= $aq['id'] ?>, '<?= htmlspecialchars(addslashes($aq['description'])) ?>')" style="background:none; border:none; color:#0284c7; font-weight:600; cursor:pointer; padding:0; font-size:inherit; text-decoration:none;">Approve</button>
                        <span style="color:#64748b; margin:0 4px;">/</span>
                        <button type="button" onclick="handleAdminEventReturn(<?= $aq['id'] ?>, '<?= htmlspecialchars(addslashes($aq['description'])) ?>')" style="background:none; border:none; color:#dc2626; font-weight:600; cursor:pointer; padding:0; font-size:inherit; text-decoration:none;">Return</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <!-- ══════════════════════════════════════════════════════════════
           STUDENT & ADVISER DASHBOARD VIEW
      ══════════════════════════════════════════════════════════════ -->
      <?php elseif ($sess_role === 'club_adviser'): ?>
        <!-- Adviser View -->
        <div class="card" style="margin-bottom:20px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
            <h3 style="margin:0;"><i class="fa-solid fa-calendar-check" style="color:#2563eb;"></i> Organization Event Proposals &amp; Activities</h3>
            <a href="events.php?view=pipeline" class="card-btn btn-sm" style="background:#2563eb; color:#fff; border:1px solid #1d4ed8; text-decoration:none; font-size:0.8rem; font-weight:600; padding:6px 14px; border-radius:6px; display:inline-flex; align-items:center; gap:6px;">
              <i class="fa-solid fa-list-check"></i> Manage Event Proposals
            </a>
          </div>
          <div class="table-wrap">
            <table class="table-wide">
              <thead>
                <tr>
                  <th style="width:70px;">#</th>
                  <th>Proposal / Event Title</th>
                  <th>Venue &amp; Schedule</th>
                  <th>Possible Budget</th>
                  <th>Stage / Status</th>
                  <th>Submission Date</th>
                  <th style="text-align:right;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($adviser_event_proposals)): ?>
                  <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:32px 16px;">
                    <i class="fa-solid fa-folder-open" style="font-size:2rem; color:#cbd5e1; margin-bottom:8px; display:block;"></i>
                    No event proposals found for your organization.
                  </td></tr>
                <?php else: ?>
                  <?php foreach ($adviser_event_proposals as $ev): ?>
                    <?php
                      $st = $ev['status'] ?? 'Pending Adviser';
                      $ref_no = '#' . str_pad($ev['id'], 4, '0', STR_PAD_LEFT);
                      $sub_by = !empty($ev['first_name']) ? ($ev['first_name'] . ' ' . $ev['last_name']) : '';
                    ?>
                    <tr>
                      <td><span style="font-family:monospace; font-weight:700; color:#2563eb;"><?= htmlspecialchars($ref_no) ?></span></td>
                      <td>
                        <strong><?= htmlspecialchars($ev['title']) ?></strong>
                        <?php if ($sub_by): ?>
                          <div style="font-size:0.75rem; color:#64748b;"><i class="fa-solid fa-user" style="font-size:0.7rem;"></i> <?= htmlspecialchars($sub_by) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span style="font-weight:600; color:#1e293b;"><?= htmlspecialchars($ev['venue'] ?? 'TBA') ?></span>
                        <div style="font-size:0.75rem; color:#64748b;"><i class="fa-solid fa-calendar-day" style="font-size:0.7rem;"></i> <?= !empty($ev['event_date']) ? date('M d, Y', strtotime($ev['event_date'])) : 'TBA' ?></div>
                      </td>
                      <td>
                        <strong style="color:#059669;"><i class="fa-solid fa-coins" style="font-size:0.75rem; margin-right:3px;"></i>₱<?= number_format((float)($ev['budget_amount'] ?? 0), 2) ?></strong>
                      </td>
                      <td>
                        <?php if ($st === 'Pending Adviser'): ?>
                          <span style="background:#fef3c7; color:#92400e; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid fa-clock"></i> Pending Adviser
                          </span>
                        <?php elseif ($st === 'Pending SSC'): ?>
                          <span style="background:#f3e8ff; color:#7e22ce; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid fa-file-signature"></i> Pending SSC
                          </span>
                        <?php elseif ($st === 'Pending Admin'): ?>
                          <span style="background:#e0e7ff; color:#3730a3; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid fa-user-shield"></i> Pending Admin
                          </span>
                        <?php elseif (in_array($st, ['Approved', 'Upcoming', 'Completed'])): ?>
                          <span style="background:#dcfce7; color:#166534; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($st) ?>
                          </span>
                        <?php elseif (in_array($st, ['Rejected', 'Returned'])): ?>
                          <span style="background:#fee2e2; color:#991b1b; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($st) ?>
                          </span>
                        <?php else: ?>
                          <span style="background:#f1f5f9; color:#475569; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><?= htmlspecialchars($st) ?></span>
                        <?php endif; ?>
                      </td>
                      <td style="color:#475569; font-size:0.82rem;"><?= date('M d, Y', strtotime($ev['created_at'] ?? 'now')) ?></td>
                      <td style="text-align:right;">
                        <a href="events.php?view=pipeline" class="card-btn btn-sm" style="background:#2563eb; color:#fff; text-decoration:none; display:inline-flex; align-items:center; gap:5px; font-weight:600;">
                          <i class="fa-solid fa-eye"></i> View Proposal
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <?php else: ?>
        <!-- Student Upcoming Event & Joined Organizations Showcase -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:24px;">
          <!-- 1. UPCOMING EVENT CARD -->
          <div class="card" style="background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:22px; box-shadow:0 2px 6px rgba(0,0,0,0.04); display:flex; flex-direction:column; justify-content:space-between;">
            <div>
              <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                <span style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">
                  <i class="fa-solid fa-calendar-star" style="color:#2563eb; margin-right:6px;"></i> UPCOMING EVENT
                </span>
                <?php if ($student_upcoming_event): ?>
                  <span style="font-size:0.75rem; font-weight:600; padding:3px 10px; border-radius:20px; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe;">
                    <?= htmlspecialchars($student_upcoming_event['reg_status'] ?? 'Open') ?>
                  </span>
                <?php endif; ?>
              </div>
              <?php if ($student_upcoming_event): ?>
                <h3 style="margin:0 0 8px; font-size:1.25rem; font-weight:700; color:#1e293b;">
                  <?= htmlspecialchars($student_upcoming_event['title']) ?>
                </h3>
                <div style="font-size:0.875rem; color:#475569; display:flex; flex-direction:column; gap:6px; margin-bottom:18px;">
                  <div>
                    <i class="fa-regular fa-clock" style="width:16px; color:#64748b;"></i>
                    <?= (date('Y-m-d', strtotime($student_upcoming_event['event_date'])) === date('Y-m-d')) ? 'Today' : date('M d, Y', strtotime($student_upcoming_event['event_date'])) ?> &bull; <?= date('g:i A', strtotime($student_upcoming_event['event_date'])) ?>
                  </div>
                  <div>
                    <i class="fa-solid fa-location-dot" style="width:16px; color:#ef4444;"></i>
                    <?= htmlspecialchars($student_upcoming_event['venue'] ?: 'Campus Grounds') ?>
                  </div>
                  <?php if (!empty($student_upcoming_event['club_name'])): ?>
                    <div style="font-size:0.8rem; color:#64748b;">
                      <i class="fa-solid fa-users" style="width:16px; color:#2563eb;"></i>
                      Hosted by <?= htmlspecialchars($student_upcoming_event['club_name']) ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div style="padding:24px 0; text-align:center; color:#94a3b8;">
                  <i class="fa-regular fa-calendar-xmark" style="font-size:2rem; margin-bottom:8px; display:block; color:#cbd5e1;"></i>
                  <p style="margin:0; font-size:0.95rem; font-weight:600; color:#64748b;">No upcoming events scheduled</p>
                  <span style="font-size:0.8rem;">Check back later or explore the campus calendar</span>
                </div>
              <?php endif; ?>
            </div>
            <div style="margin-top:14px;">
              <a href="events.php?view=calendar" class="card-btn" style="background:#2563eb; color:#fff; padding:10px 18px; border-radius:8px; font-weight:600; text-decoration:none; text-align:center; display:inline-flex; align-items:center; justify-content:center; gap:8px; width:100%; box-sizing:border-box;">
                <i class="fa-solid fa-calendar-days"></i> Explore Events Calendar
              </a>
            </div>
          </div>

          <!-- 2. MY ORGANIZATIONS MEMBERSHIP CARD -->
          <div class="card" style="background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:22px; box-shadow:0 2px 6px rgba(0,0,0,0.04); display:flex; flex-direction:column; justify-content:space-between;">
            <div>
              <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                <span style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">
                  <i class="fa-solid fa-sitemap" style="color:#2563eb; margin-right:6px;"></i> MY ORGANIZATIONS
                </span>
                <span style="font-size:0.75rem; font-weight:600; padding:3px 10px; border-radius:20px; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe;">
                  <?= (int)$active_clubs_joined ?> Joined
                </span>
              </div>
              <?php if (!empty($student_clubs_list)): ?>
                <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:14px;">
                  <?php foreach ($student_clubs_list as $sc): ?>
                    <div style="display:flex; align-items:center; justify-content:space-between; padding:9px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                      <div style="display:flex; align-items:center; gap:8px; min-width:0; overflow:hidden;">
                        <span style="font-weight:700; font-size:0.75rem; background:#e0e7ff; color:#1e40af; padding:3px 8px; border-radius:6px; flex-shrink:0; letter-spacing:0.02em;"><?= htmlspecialchars($sc['code']) ?></span>
                        <div style="min-width:0;">
                          <strong style="display:block; font-size:0.85rem; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($sc['name']) ?>"><?= htmlspecialchars($sc['name']) ?></strong>
                          <span style="font-size:0.72rem; color:#64748b;"><?= htmlspecialchars($sc['category'] ?? 'Academic Organization') ?></span>
                        </div>
                      </div>
                      <span style="font-size:0.7rem; font-weight:600; padding:2px 8px; border-radius:12px; background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; white-space:nowrap; flex-shrink:0; margin-left:8px;">
                        <i class="fa-solid fa-circle-check" style="font-size:0.62rem; margin-right:3px;"></i><?= htmlspecialchars($sc['role_in_club'] ?: 'Member') ?>
                      </span>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div style="margin-top:14px; display:flex; gap:8px;">
                  <a href="roster.php" class="card-btn" style="background:#2563eb; color:#fff; padding:9px 12px; border-radius:8px; font-weight:600; text-decoration:none; text-align:center; display:inline-flex; align-items:center; justify-content:center; gap:6px; flex:1; box-sizing:border-box; font-size:0.82rem;">
                    <i class="fa-solid fa-users"></i> My Memberships
                  </a>
                  <a href="club_directory.php" class="card-btn" style="background:#f1f5f9; color:#1e293b; border:1px solid #cbd5e1; padding:9px 12px; border-radius:8px; font-weight:600; text-decoration:none; text-align:center; display:inline-flex; align-items:center; justify-content:center; gap:6px; flex:1; box-sizing:border-box; font-size:0.82rem;">
                    <i class="fa-solid fa-compass"></i> Explore Clubs
                  </a>
                </div>
              <?php else: ?>
                <div style="padding:20px 0; text-align:center; color:#94a3b8;">
                  <i class="fa-solid fa-users" style="font-size:2rem; margin-bottom:8px; display:block; color:#cbd5e1;"></i>
                  <p style="margin:0; font-size:0.9rem; font-weight:600; color:#64748b;">Not enrolled in any organization yet</p>
                  <span style="font-size:0.78rem;">Discover accredited academic and interest-based clubs.</span>
                </div>
                <div style="margin-top:14px;">
                  <a href="club_directory.php" class="card-btn" style="background:#f1f5f9; color:#1e293b; border:1px solid #cbd5e1; padding:10px 18px; border-radius:8px; font-weight:600; text-decoration:none; text-align:center; display:inline-flex; align-items:center; justify-content:center; gap:8px; width:100%; box-sizing:border-box;">
                    <i class="fa-solid fa-compass"></i> Browse Club Directory
                  </a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Student Feed View -->
        <div class="clubs-feed-card">
          <div class="clubs-feed-header">
            <h3><i class="fa-solid fa-bullhorn" style="color:#2563eb;"></i> Organization Announcements &amp; Events Feed</h3>
            <span style="font-size:0.8rem; color:#64748b;">Latest updates from accredited campus organizations</span>
          </div>
          <div class="clubs-feed-grid">
            <?php if (empty($announcements)): ?>
              <div style="grid-column: 1 / -1; text-align: center; color: #94a3b8; padding: 40px 20px;">
                <i class="fa-solid fa-bullhorn" style="font-size: 2.5rem; display: block; margin-bottom: 12px; color: #cbd5e1;"></i>
                <p style="margin: 0; font-size: 0.9rem; font-weight: 500;">No active organization announcements found.</p>
                <span style="font-size: 0.78rem; color: #a1a1aa;">Check back later for news and upcoming activities.</span>
              </div>
            <?php else: ?>
              <?php foreach ($announcements as $ann): ?>
                <div class="feed-post-card">
                  <div>
                    <div class="feed-post-meta">
                      <span class="feed-org-tag"><?= htmlspecialchars($ann['club_code']) ?></span>
                      <span class="feed-date"><?= date('M d, Y', strtotime($ann['created_at'])) ?></span>
                    </div>
                    <h4 class="feed-post-title"><?= htmlspecialchars($ann['title']) ?></h4>
                    <p class="feed-post-desc"><?= htmlspecialchars($ann['content']) ?></p>
                  </div>
                  <a href="club_directory.php" class="feed-action-btn"><i class="fa-solid fa-eye"></i> View Directory</a>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- end main -->

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
<script src="../js/table-pagination.js?v=<?= filemtime(__DIR__ . '/../js/table-pagination.js') ?>"></script>

<!-- Chart.js Initializations for SSC & Admin -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  // Initialize Council Action Queue Table Pagination
  const sscQueue = document.getElementById('councilActionQueueTable');
  if (sscQueue && window.initTablePagination && !sscQueue._paginator) {
    window.initTablePagination(sscQueue, {
      pageSize: 5,
      showInfo: false,
      entityName: 'proposals'
    });
  }

  // Initialize Admin Action Queue Table Pagination
  const adminQueue = document.getElementById('adminActionQueueTable');
  if (adminQueue && window.initTablePagination && !adminQueue._paginator) {
    window.initTablePagination(adminQueue, {
      pageSize: 5,
      showInfo: false,
      entityName: 'requisitions'
    });
  }

  <?php if ($sess_role === 'ssc' || $sess_role === 'staff'): ?>
  // 1. SSC & Staff Organization Distribution Chart (Doughnut)
  const ctxCat = document.getElementById('sscCategoryChart')?.getContext('2d');
  if (ctxCat) {
    new Chart(ctxCat, {
      type: 'doughnut',
      data: {
        labels: ['Academic', 'Cultural', 'Sports', 'Advocacy'],
        datasets: [{
          data: [
            <?= $club_categories_counts['Academic'] ?? 0 ?>,
            <?= $club_categories_counts['Cultural'] ?? 0 ?>,
            <?= $club_categories_counts['Sports'] ?? 0 ?>,
            <?= $club_categories_counts['Advocacy'] ?? 0 ?>
          ],
          backgroundColor: ['#1e3a8a', '#2563eb', '#10b981', '#f59e0b'],
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11, weight: '600' } } }
        },
        cutout: '65%'
      }
    });
  }

  // 2. SSC Membership by Program (Horizontal Bar Chart)
  const ctxProg = document.getElementById('sscProgramChart')?.getContext('2d');
  if (ctxProg) {
    new Chart(ctxProg, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_keys($ssc_prog_participation)) ?>,
        datasets: [{
          label: 'Student Participants',
          data: <?= json_encode(array_values($ssc_prog_participation)) ?>,
          backgroundColor: '#7c3aed',
          borderRadius: 6,
          barPercentage: 0.65
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: ctx => ` ${ctx.raw} Participants`
            }
          }
        },
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0 } },
          y: { grid: { display: false }, ticks: { font: { weight: '600' } } }
        }
      }
    });
  }

  // 3. SSC Event Proposals Pipeline Chart (Bar Chart)
  const ctxEvPipe = document.getElementById('sscEventPipelineChart')?.getContext('2d');
  if (ctxEvPipe) {
    new Chart(ctxEvPipe, {
      type: 'bar',
      data: {
        labels: ['Pending SSC', 'Pending Admin', 'Approved', 'Completed', 'Rejected'],
        datasets: [{
          label: 'Proposals',
          data: [
            <?= (int)$ssc_event_pipeline['pending_ssc'] ?>,
            <?= (int)$ssc_event_pipeline['pending_admin'] ?>,
            <?= (int)$ssc_event_pipeline['approved'] ?>,
            <?= (int)$ssc_event_pipeline['completed'] ?>,
            <?= (int)$ssc_event_pipeline['rejected'] ?>
          ],
          backgroundColor: ['#3b82f6', '#f59e0b', '#10b981', '#6366f1', '#ef4444'],
          borderRadius: 6,
          barPercentage: 0.6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: ctx => ' ' + ctx.raw + ' proposal' + (ctx.raw === 1 ? '' : 's')
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              precision: 0,
              stepSize: 1
            }
          }
        }
      }
    });
  }

  // 4. SSC Attendance Performance Chart (Attended vs Absent Doughnut)
  const ctxAtt = document.getElementById('sscAttendanceChart')?.getContext('2d');
  if (ctxAtt) {
    new Chart(ctxAtt, {
      type: 'doughnut',
      data: {
        labels: ['Attended', 'Absent'],
        datasets: [{
          data: [<?= $tot_attended ?>, <?= $tot_absent ?>],
          backgroundColor: ['#16a34a', '#f59e0b'],
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10, weight: '600' } } }
        },
        cutout: '70%'
      }
    });
  }

  // 5. SSC Event Activity Trend Chart (Multi-line / Smooth Spline)
  const ctxEvTrend = document.getElementById('sscEventTrendChart')?.getContext('2d');
  if (ctxEvTrend) {
    new Chart(ctxEvTrend, {
      type: 'line',
      data: {
        labels: <?= $trend_labels_json ?>,
        datasets: [
          {
            label: 'Approved / Upcoming',
            data: <?= $trend_approved_json ?>,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37, 99, 235, 0.12)',
            fill: true,
            tension: 0.35,
            borderWidth: 2.5,
            pointRadius: 4,
            pointBackgroundColor: '#2563eb'
          },
          {
            label: 'Completed Activities',
            data: <?= $trend_completed_json ?>,
            borderColor: '#16a34a',
            backgroundColor: 'rgba(22, 163, 74, 0.12)',
            fill: true,
            tension: 0.35,
            borderWidth: 2.5,
            pointRadius: 4,
            pointBackgroundColor: '#16a34a'
          },
          {
            label: 'Rejected Proposals',
            data: <?= $trend_rejected_json ?>,
            borderColor: '#ef4444',
            backgroundColor: 'rgba(239, 68, 68, 0.08)',
            fill: true,
            tension: 0.35,
            borderWidth: 2,
            borderDash: [5, 5],
            pointRadius: 4,
            pointBackgroundColor: '#ef4444'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 14, font: { size: 11, weight: '600' } } },
          tooltip: {
            mode: 'index',
            intersect: false
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0, stepSize: 1 }
          },
          x: {
            grid: { display: false }
          }
        }
      }
    });
  }
  <?php endif; ?>

  <?php if ($sess_role === 'admin' || $sess_role === 'super_admin'): ?>
  // 1. Admin Workflow Pipeline Throughput (Grouped Bar Chart)
  const ctxPipe = document.getElementById('adminPipelineChart')?.getContext('2d');
  if (ctxPipe) {
    new Chart(ctxPipe, {
      type: 'bar',
      data: {
        labels: ['Stage 1: Adviser', 'Stage 2: SSC', 'Stage 3: Admin', 'Approved / Cleared', 'Returned / Rejected'],
        datasets: [
          {
            label: 'Event Proposals',
            data: [
              <?= (int)$pipeline_events['adviser'] ?>,
              <?= (int)$pipeline_events['ssc'] ?>,
              <?= (int)$pipeline_events['admin'] ?>,
              <?= (int)$pipeline_events['cleared'] ?>,
              <?= (int)$pipeline_events['returned'] ?>
            ],
            backgroundColor: [
              'rgba(37, 99, 235, 0.85)',
              'rgba(124, 58, 237, 0.85)',
              'rgba(2, 132, 199, 0.85)',
              'rgba(16, 185, 129, 0.85)',
              'rgba(239, 68, 68, 0.85)'
            ],
            borderRadius: 6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11, weight: '600' } } },
          tooltip: {
            callbacks: {
              label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y} item${ctx.parsed.y === 1 ? '' : 's'}`
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 10, weight: '600' } }
          },
          y: {
            beginAtZero: true,
            ticks: {
              precision: 0,
              stepSize: 1
            },
            grid: { color: '#f1f5f9' }
          }
        }
      }
    });
  }

  // 2. Admin Organizations & Events by Category (Grouped Bar Chart)
  const ctxCatChart = document.getElementById('adminCategoryChart')?.getContext('2d');
  if (ctxCatChart) {
    new Chart(ctxCatChart, {
      type: 'bar',
      data: {
        labels: <?= json_encode($admin_cat_labels) ?>,
        datasets: [
          {
            label: 'Recognized Orgs',
            data: <?= json_encode($admin_cat_orgs) ?>,
            backgroundColor: '#2563eb',
            borderRadius: 4
          },
          {
            label: 'Total Events',
            data: <?= json_encode($admin_cat_events) ?>,
            backgroundColor: '#10b981',
            borderRadius: 4
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11, weight: '600' } } },
          tooltip: {
            callbacks: {
              label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y} item${ctx.parsed.y === 1 ? '' : 's'}`
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              font: { size: 9.5, weight: '600' },
              maxRotation: 20,
              minRotation: 0
            }
          },
          y: {
            beginAtZero: true,
            ticks: {
              precision: 0,
              stepSize: 1
            },
            grid: { color: '#f1f5f9' }
          }
        }
      }
    });
  }
  <?php endif; ?>
});
</script>

<?php if ($sess_role === 'admin' || $sess_role === 'super_admin'): ?>
<script>
async function handleAdminEventApprove(id, title) {
  const confirmed = await window.showConfirmModal(
    'Approve & Grant Calendar Clearance?',
    `Are you sure you want to approve and grant calendar clearance for "${title}"?`,
    { type: 'decision', confirmText: 'Approve & Clear' }
  );
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action', 'admin_approve');
  fd.append('id', id);
  fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
  try {
    const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      await window.showSystemModal({ title: 'Event Approved', message: data.message || 'Event approved successfully.', type: 'success' });
      window.location.reload();
    } else {
      window.alert(data.message || 'Failed to approve event.', 'error');
    }
  } catch (err) {
    window.alert('Network error approving event.', 'error');
  }
}

async function handleAdminEventReturn(id, title) {
  const note = await window.showDecisionModal(
    'Return Event for Revision',
    `Enter specific instructions or revision remarks for "${title}":`,
    { placeholder: 'Provide clear instructions for the organizing club...', confirmText: 'Return Proposal', requireInput: true, warning: true }
  );
  if (note === false || note === null) return;
  if (!note.trim()) { 
    window.alert('Remarks are required to return an event proposal.', 'warning'); 
    return; 
  }
  const fd = new FormData();
  fd.append('action', 'return');
  fd.append('id', id);
  fd.append('note', note.trim());
  fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
  try {
    const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      await window.showSystemModal({ title: 'Proposal Returned', message: data.message || 'Event returned for revision.', type: 'warning' });
      window.location.reload();
    } else {
      window.alert(data.message || 'Failed to return event.', 'error');
    }
  } catch (err) {
    window.alert('Network error returning event.', 'error');
  }
}
</script>
<?php endif; ?>
</body>
</html>
