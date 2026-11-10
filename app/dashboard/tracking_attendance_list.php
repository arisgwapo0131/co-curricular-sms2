<?php
// ============================================================
//  TRACKING_ATTENDANCE_LIST.PHP  (dashboard/)
//  Co-Curricular System — Event Attendance Rosters & Logs
//  Role-Based: Attendance Administration & Control (Admin)
//              System Attendance Monitor & Verification (SSC)
//              Organization Attendance Records (Club Adviser)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_any_permission(['attendance.override', 'attendance.view.analytics', 'attendance.track.org'], null, 'tracking_history.php');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)($_SESSION['user_id'] ?? 0);
$sess_user    = $_SESSION['username'] ?? '';

// Active tab from hash or query
$active_tab = $_GET['tab'] ?? 'ledger';

// User's assigned organizations (for club_adviser and scoping)
$assigned_clubs = get_user_assigned_clubs($conn, $user_id, $sess_role);
$adviser_club_ids = array_map('intval', array_column($assigned_clubs, 'id'));
$adviser_programs = array_filter(array_unique(array_column($assigned_clubs, 'program')));
$adviser_club_name = !empty($assigned_clubs) ? ($assigned_clubs[0]['name'] ?? '') : '';
$adviser_club_code = !empty($assigned_clubs) ? ($assigned_clubs[0]['code'] ?? '') : '';
$adviser_program_code = !empty($adviser_programs) ? reset($adviser_programs) : '';
$is_elevated_admin = in_array($sess_role, ['admin', 'super_admin', 'staff', 'ssc'], true);

// Construct adviser scope SQL clause based on assigned organization and program
$adviser_scope_where = "1=1";
if ($sess_role === 'club_adviser') {
  if (!empty($adviser_club_ids)) {
    $in_c = implode(',', $adviser_club_ids);
    if (!empty($adviser_programs)) {
      $escaped_p = array_map(function($p) use ($conn) { return "'" . $conn->real_escape_string($p) . "'"; }, $adviser_programs);
      $in_p = implode(',', $escaped_p);
      $adviser_scope_where = "(e.club_id IN ($in_c) OR c.program IN ($in_p) OR e.target_course IN ($in_p))";
    } else {
      $adviser_scope_where = "e.club_id IN ($in_c)";
    }
  } else {
    $adviser_scope_where = "e.created_by = $user_id";
  }
}

// Fetch events for dropdown filters (role-aware: advisers see only their organization/program events)
$events = [];
$grouped_events = [];
$ev_where = ($sess_role === 'club_adviser') ? $adviser_scope_where : "1=1";
$ev_res = $conn->query("
  SELECT e.id, e.title, e.event_date, e.venue, e.event_type, e.target_course,
         c.name as club_name, c.code as club_code, c.program as club_program, c.category as club_category
  FROM events e
  LEFT JOIN clubs c ON c.id = e.club_id
  WHERE $ev_where
  ORDER BY e.event_date DESC
");
if ($ev_res) {
  $events = $ev_res->fetch_all(MYSQLI_ASSOC);

  // Group events by academic program
  foreach ($events as $ev) {
    $prog = trim($ev['club_program'] ?? '');
    $targetCourse = trim($ev['target_course'] ?? '');
    $evType = $ev['event_type'] ?? 'Club';
    
    if (!empty($prog) && !in_array($prog, ['General', 'All Programs', 'All Courses', 'Non-Academic'])) {
      $groupName = $prog;
    } elseif (!empty($targetCourse) && !in_array($targetCourse, ['All Courses', 'All Programs', 'All Organizations'])) {
      $groupName = $targetCourse;
    } elseif ($evType === 'Institutional' || ($ev['club_category'] ?? '') !== 'Academic') {
      $groupName = 'Institutional & Campus-Wide';
    } else {
      $groupName = 'General & Co-Curricular';
    }
    
    if (str_contains($groupName, 'Information Technology') || $groupName === 'BSIT' || ($ev['club_code'] ?? '') === 'ITS') {
      $optGroup = '💻 BSIT — Information Technology';
    } elseif (str_contains($groupName, 'Hospitality') || $groupName === 'BSHM' || ($ev['club_code'] ?? '') === 'TECHs') {
      $optGroup = '🏨 BSHM — Hospitality Management';
    } elseif (str_contains($groupName, 'Tourism') || $groupName === 'BSTM' || ($ev['club_code'] ?? '') === 'TTS') {
      $optGroup = '✈️ BSTM — Tourism Management';
    } elseif (str_contains($groupName, 'Accounting') || $groupName === 'BSAIS' || ($ev['club_code'] ?? '') === 'AISS') {
      $optGroup = '📊 BSAIS — Accounting Info Systems';
    } elseif (str_contains($groupName, 'Computer Engineering') || $groupName === 'BSCpE' || ($ev['club_code'] ?? '') === 'ACES' || ($ev['club_code'] ?? '') === 'ACADS') {
      $optGroup = '⚙️ BSCpE — Computer Engineering';
    } elseif (str_contains($groupName, 'Computer Science') || $groupName === 'BSCS' || ($ev['club_code'] ?? '') === 'CSSEC') {
      $optGroup = '💻 CSSEC — Computer Science';
    } elseif (str_contains($groupName, 'Criminology') || $groupName === 'BSCrim' || ($ev['club_code'] ?? '') === 'CJSU') {
      $optGroup = '⚖️ BSCrim — Criminology';
    } elseif (str_contains($groupName, 'Psychology') || $groupName === 'BSPsych' || ($ev['club_code'] ?? '') === 'PsychSoc') {
      $optGroup = '🧠 BSPsych — Psychology';
    } elseif (str_contains($groupName, 'Business') || $groupName === 'BSBA' || ($ev['club_code'] ?? '') === 'JMA' || ($ev['club_code'] ?? '') === 'JFINEX') {
      $optGroup = '💼 BSBA — Business Administration';
    } elseif (str_contains($groupName, 'Office') || $groupName === 'BSOA') {
      $optGroup = '🗂️ BSOA — Office Administration';
    } elseif (str_contains($groupName, 'Education') || str_contains($groupName, 'BSEd') || str_contains($groupName, 'BSElEd') || str_contains($groupName, 'TLE')) {
      $optGroup = '📚 Education & Teacher Training';
    } elseif ($groupName === 'Institutional & Campus-Wide') {
      $optGroup = '🏛️ Institutional & Campus-Wide';
    } else {
      $optGroup = '🎓 ' . $groupName;
    }
    
    $grouped_events[$optGroup][] = $ev;
  }
}

// ── 1. KPI METRICS (Role-aware & Scoped) ──
if ($sess_role === 'club_adviser') {
  $kpi_attendance_logs  = (int)($conn->query("SELECT COUNT(*) FROM attendance_logs al JOIN events e ON e.id = al.event_id LEFT JOIN clubs c ON c.id = e.club_id WHERE $adviser_scope_where")->fetch_row()[0] ?? 0);
  $kpi_active_sessions  = (int)($conn->query("SELECT COUNT(*) FROM qr_sessions qs JOIN events e ON e.id = qs.event_id LEFT JOIN clubs c ON c.id = e.club_id WHERE qs.status = 'active' AND $adviser_scope_where")->fetch_row()[0] ?? 0);
  $kpi_manual_overrides = (int)($conn->query("SELECT COUNT(*) FROM attendance_logs al JOIN events e ON e.id = al.event_id LEFT JOIN clubs c ON c.id = e.club_id WHERE (al.method = 'Manual' OR al.override_reason IS NOT NULL) AND $adviser_scope_where")->fetch_row()[0] ?? 0);
  $kpi_failed_scans     = (int)($conn->query("SELECT COUNT(*) FROM attendance_scan_attempts asa JOIN events e ON e.id = asa.event_id LEFT JOIN clubs c ON c.id = e.club_id WHERE asa.result IN ('ALREADY_SCANNED', 'EXPIRED', 'INVALID_TOKEN', 'UNAUTHORIZED', 'NOT_ELIGIBLE') AND $adviser_scope_where")->fetch_row()[0] ?? 0);
  $kpi_duplicate_scans  = (int)($conn->query("SELECT COUNT(*) FROM attendance_scan_attempts asa JOIN events e ON e.id = asa.event_id LEFT JOIN clubs c ON c.id = e.club_id WHERE asa.result = 'ALREADY_SCANNED' AND $adviser_scope_where")->fetch_row()[0] ?? 0);
} else {
  $kpi_attendance_logs  = (int)($conn->query("SELECT COUNT(*) FROM attendance_logs")->fetch_row()[0] ?? 0);
  $kpi_active_sessions  = (int)($conn->query("SELECT COUNT(*) FROM qr_sessions WHERE status = 'active'")->fetch_row()[0] ?? 0);
  $kpi_manual_overrides = (int)($conn->query("SELECT COUNT(*) FROM attendance_logs WHERE method = 'Manual' OR override_reason IS NOT NULL")->fetch_row()[0] ?? 0);
  $kpi_failed_scans     = (int)($conn->query("SELECT COUNT(*) FROM attendance_scan_attempts WHERE result IN ('ALREADY_SCANNED', 'EXPIRED', 'INVALID_TOKEN', 'UNAUTHORIZED', 'NOT_ELIGIBLE')")->fetch_row()[0] ?? 0);
  $kpi_duplicate_scans  = (int)($conn->query("SELECT COUNT(*) FROM attendance_scan_attempts WHERE result = 'ALREADY_SCANNED'")->fetch_row()[0] ?? 0);
}

// ── 2. SSC KPI METRICS (Section 12) ──
$ssc_active_events   = (int)($conn->query("SELECT COUNT(*) FROM events WHERE status IN ('Approved', 'Upcoming')")->fetch_row()[0] ?? 0);
$ssc_present_count   = (int)($conn->query("SELECT COUNT(*) FROM attendance_logs WHERE status = 'Present'")->fetch_row()[0] ?? 0);
$ssc_late_count      = (int)($conn->query("SELECT COUNT(*) FROM attendance_logs WHERE status = 'Late'")->fetch_row()[0] ?? 0);
$ssc_total_reg       = (int)($conn->query("SELECT COUNT(*) FROM event_registrations")->fetch_row()[0] ?? 0);
$ssc_pending_count   = max(0, $ssc_total_reg - $kpi_attendance_logs);

// ── 3. SSC SYSTEM-WIDE EVENTS MONITOR LIST (Section 12 & 13) ──
$ssc_events_monitor = [];
$mon_res = $conn->query("
  SELECT e.id, e.title, e.event_date, e.venue, e.status as event_status,
         c.name as club_name, c.code as club_code,
         (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id) as reg_count,
         (SELECT COUNT(*) FROM attendance_logs al WHERE al.event_id = e.id AND al.status = 'Present') as present_count,
         (SELECT COUNT(*) FROM attendance_logs al WHERE al.event_id = e.id AND al.status = 'Late') as late_count,
         (SELECT COUNT(*) FROM attendance_logs al WHERE al.event_id = e.id) as total_scans,
         (SELECT qs.status FROM qr_sessions qs WHERE qs.event_id = e.id AND qs.status = 'active' ORDER BY qs.id DESC LIMIT 1) as qr_status
  FROM events e
  LEFT JOIN clubs c ON c.id = e.club_id
  WHERE e.status IN ('Approved', 'Upcoming', 'Completed')
  ORDER BY (qr_status = 'active') DESC, e.event_date DESC
  LIMIT 50
");
if ($mon_res) {
  $ssc_events_monitor = $mon_res->fetch_all(MYSQLI_ASSOC);
}

// ── 4. QR SESSIONS LIST (Section 16) ──
$qr_sessions = [];
$qrs_where = ($sess_role === 'club_adviser') ? $adviser_scope_where : "1=1";
$qrs_res = $conn->query("
  SELECT qs.*, e.title as event_title, e.event_date, e.venue,
         c.name as club_name, c.code as club_code,
         u.first_name as creator_first, u.last_name as creator_last,
         (SELECT COUNT(*) FROM attendance_logs al WHERE al.event_id = qs.event_id) as total_scans
  FROM qr_sessions qs
  JOIN events e ON e.id = qs.event_id
  LEFT JOIN clubs c ON c.id = e.club_id
  LEFT JOIN users u ON u.id = qs.created_by
  WHERE $qrs_where
  ORDER BY (qs.status = 'active') DESC, qs.id DESC
  LIMIT 50
");
if ($qrs_res) {
  $qr_sessions = $qrs_res->fetch_all(MYSQLI_ASSOC);
}

// ── 5. SCAN ATTEMPTS LOG (Section 14) ──
$scan_attempts = [];
$att_where = ($sess_role === 'club_adviser') ? $adviser_scope_where : "1=1";
$att_res = $conn->query("
  SELECT asa.*, e.title as event_title,
         c.name as club_name, c.code as club_code,
         u.first_name, u.last_name, u.username,
         s.student_number, s.course,
         su.first_name as scanner_first, su.last_name as scanner_last, su.role as scanner_role
  FROM attendance_scan_attempts asa
  LEFT JOIN events e ON e.id = asa.event_id
  LEFT JOIN clubs c ON c.id = e.club_id
  LEFT JOIN users u ON u.id = asa.user_id
  LEFT JOIN students s ON (s.user_id = u.id OR s.student_number = u.username)
  LEFT JOIN users su ON su.id = asa.scanner_id
  WHERE $att_where
  ORDER BY asa.scanned_at DESC
  LIMIT 100
");
if ($att_res) {
  $scan_attempts = $att_res->fetch_all(MYSQLI_ASSOC);
}

// ── 6. ATTENDANCE MASTER LEDGER LOGS (Role-Scoped: Organization / Program) ──
$admin_logs = [];
$ledger_where = ($sess_role === 'club_adviser') ? $adviser_scope_where : "1=1";

$admin_logs_res = $conn->query("
  SELECT 
      al.id, al.check_in, al.method, al.status, al.override_reason, al.event_id, al.user_id, al.logged_by,
      e.title AS event_title, e.event_type, e.venue AS event_venue, e.target_course,
      c.id AS club_id, c.code AS club_code, c.name AS club_name, c.program AS club_program,
      u.first_name, u.last_name, u.email, u.username,
      s.student_number, s.course, s.year_level, s.section,
      logger.first_name AS logger_first, logger.last_name AS logger_last, logger.role AS logger_role
  FROM attendance_logs al
  LEFT JOIN events e ON e.id = al.event_id
  LEFT JOIN clubs c ON c.id = e.club_id
  LEFT JOIN users u ON u.id = al.user_id
  LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
  LEFT JOIN users logger ON logger.id = al.logged_by
  WHERE $ledger_where
  ORDER BY al.check_in DESC, al.id DESC
  LIMIT 500
");
if ($admin_logs_res) {
  $admin_logs = $admin_logs_res->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= ($sess_role === 'admin') ? 'Attendance Administration & Control' : (($sess_role === 'ssc') ? 'SSC Attendance Monitor' : 'Attendance Records') ?> – BCP Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    /* Metric Cards Grid Consistency */
    .att-kpi-grid {
      display: grid !important;
      grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
      gap: 14px !important;
      margin-bottom: 22px !important;
      align-items: stretch !important;
    }
    @media (max-width: 992px) {
      .att-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
    }
    @media (max-width: 520px) {
      .att-kpi-grid {
        grid-template-columns: 1fr !important;
      }
    }

    /* Card / Button Tab Switcher Grid */
    .att-tabs-nav {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
      margin-bottom: 22px;
      width: 100%;
    }
    @media (max-width: 1024px) {
      .att-tabs-nav {
        grid-template-columns: repeat(2, 1fr);
      }
    }
    @media (max-width: 580px) {
      .att-tabs-nav {
        grid-template-columns: 1fr;
      }
    }
    .att-tab-link {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 16px;
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.18s ease;
      box-shadow: 0 1px 3px rgba(15,23,42,0.04);
      user-select: none;
    }
    .att-tab-link:hover {
      border-color: #cbd5e1;
      background: #f8fafc;
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(15,23,42,0.06);
    }
    .att-tab-link.active {
      border-color: #2563eb;
      background: #eff6ff;
      box-shadow: 0 4px 14px rgba(37,99,235,0.12);
      transform: translateY(-1px);
    }
    .att-tab-lead {
      display: flex;
      align-items: center;
      gap: 11px;
      min-width: 0;
    }
    .att-tab-icon-box {
      width: 36px;
      height: 36px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.95rem;
      flex-shrink: 0;
      transition: all 0.18s ease;
    }
    .att-tab-link.active .att-tab-icon-box {
      background: #2563eb !important;
      color: #ffffff !important;
      box-shadow: 0 2px 6px rgba(37,99,235,0.25);
    }
    .att-tab-info {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
    .att-tab-title {
      font-size: 0.84rem;
      font-weight: 700;
      color: #334155;
      line-height: 1.25;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .att-tab-link.active .att-tab-title {
      color: #1d4ed8;
      font-weight: 800;
    }
    .att-tab-subtitle {
      font-size: 0.70rem;
      color: #94a3b8;
      font-weight: 500;
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .att-tab-link.active .att-tab-subtitle {
      color: #3b82f6;
    }
    .att-tab-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 3px 9px;
      border-radius: 999px;
      font-size: 0.74rem;
      font-weight: 800;
      border: 1px solid #bfdbfe;
      background: #eff6ff;
      color: #2563eb;
      flex-shrink: 0;
      transition: all 0.18s ease;
    }
    .att-tab-link.active .att-tab-pill {
      background: #2563eb !important;
      color: #ffffff !important;
      border-color: #2563eb !important;
      box-shadow: 0 1px 3px rgba(37,99,235,0.3);
    }

    /* Live Progress Bar */
    .att-progress-bar-bg {
      background: #e2e8f0;
      border-radius: 999px;
      height: 7px;
      overflow: hidden;
      width: 100%;
      margin-top: 5px;
    }
    .att-progress-bar-fill {
      background: #16a34a;
      height: 100%;
      border-radius: 999px;
    }

    /* Status Pills */
    .pill-live {
      background: #dcfce7;
      color: #15803d;
      border: 1px solid #bbf7d0;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .pill-closed {
      background: #f1f5f9;
      color: #64748b;
      border: 1px solid #e2e8f0;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .pill-terminated {
      background: #fee2e2;
      color: #dc2626;
      border: 1px solid #fecaca;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
  </style>
  <!-- jsPDF & AutoTable for direct client-side PDF downloads -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
  <style>
    /* Export Dropdown & Action Controls */
    .btn-export-group {
      position: relative;
      display: inline-block;
    }
    .btn-export-main {
      height: 36px;
      padding: 0 15px;
      background: #2563eb;
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      transition: all 0.15s ease;
      box-shadow: 0 1px 3px rgba(37,99,235,0.2);
    }
    .btn-export-main:hover {
      background: #1d4ed8;
      box-shadow: 0 3px 6px rgba(37,99,235,0.25);
    }
    .export-dropdown-menu {
      display: none;
      position: absolute;
      top: calc(100% + 6px);
      right: 0;
      background: #ffffff;
      min-width: 230px;
      border-radius: 10px;
      box-shadow: 0 14px 30px -4px rgba(15,23,42,0.18), 0 6px 12px -4px rgba(15,23,42,0.1);
      border: 1px solid #e2e8f0;
      z-index: 1050;
      overflow: hidden;
      animation: fadeIn 0.14s ease-out;
    }
    .export-dropdown-menu.show {
      display: block;
    }
    .export-menu-header {
      padding: 8px 14px 5px;
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      color: #94a3b8;
      background: #f8fafc;
      border-bottom: 1px solid #f1f5f9;
    }
    .export-menu-item {
      display: flex;
      align-items: center;
      gap: 11px;
      padding: 9px 14px;
      font-size: 0.80rem;
      font-weight: 600;
      color: #334155;
      text-decoration: none;
      cursor: pointer;
      background: transparent;
      border: none;
      width: 100%;
      text-align: left;
      transition: background 0.12s ease;
      box-sizing: border-box;
    }
    .export-menu-item:hover {
      background: #f1f5f9;
      color: #0f172a;
    }
    .export-item-title {
      font-weight: 700;
      line-height: 1.2;
      color: #1e293b;
    }
    .export-item-sub {
      font-size: 0.68rem;
      font-weight: 500;
      color: #64748b;
      margin-top: 1px;
    }
    .export-menu-divider {
      height: 1px;
      background: #e2e8f0;
      margin: 3px 0;
    }
    .count-badge {
      display: inline-flex;
      align-items: center;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 700;
      background: #eff6ff;
      color: #2563eb;
      border: 1px solid #bfdbfe;
    }
    /* Dedicated Filter Toolbar */
    .att-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 10px 14px;
      margin-bottom: 16px;
    }
    .att-toolbar-filters {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      flex: 1;
    }
    .att-search-wrap {
      position: relative;
      min-width: 220px;
      flex: 1;
      max-width: 320px;
    }
    .att-search-wrap i {
      position: absolute;
      left: 11px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 0.8rem;
      color: #94a3b8;
      pointer-events: none;
    }
    .att-search-wrap input {
      width: 100%;
      height: 36px;
      padding: 0 12px 0 32px;
      border: 1.5px solid #cbd5e1;
      border-radius: 7px;
      font-size: 0.80rem;
      outline: none;
      background: #ffffff;
      box-sizing: border-box;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .att-search-wrap input:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
    .att-filter-select {
      height: 36px;
      padding: 0 10px;
      border: 1.5px solid #cbd5e1;
      border-radius: 7px;
      font-size: 0.78rem;
      font-weight: 600;
      color: #334155;
      background: #ffffff;
      outline: none;
      box-sizing: border-box;
      transition: border-color 0.15s ease;
    }
    .att-filter-select:focus {
      border-color: #2563eb;
    }
    .btn-reset-filters {
      height: 36px;
      padding: 0 12px;
      background: #ffffff;
      border: 1.5px solid #cbd5e1;
      border-radius: 7px;
      font-size: 0.76rem;
      font-weight: 600;
      color: #64748b;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all 0.15s ease;
    }
    .btn-reset-filters:hover {
      background: #f1f5f9;
      color: #0f172a;
      border-color: #94a3b8;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @media print {
      body * {
        visibility: hidden;
      }
      #printArea, #printArea * {
        visibility: visible;
      }
      #printArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
      }
      .sidebar, .topbar, .att-tabs-nav, .btn-export-group, input, select, .footer, .btn-sys-secondary, .btn-sys-primary, .page-title-bar {
        display: none !important;
      }
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'attendance';
  $ACTIVE_SUB = 'attendance_list';
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
        <button class="topbar-chat-btn" id="chatFabBtn" title="Inter-Club Messenger" type="button"><i class="fa-solid fa-comment-dots"></i><span class="chat-unread-badge" id="chatUnreadBadge" style="display:none;"></span></button>
        <?php if (($sess_role ?? $_SESSION['role'] ?? '') === 'student'): ?>
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i class="fa-solid fa-qrcode"></i></button>
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

      <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
        <div>
          <h2 class="page-title" style="margin:0; font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:10px;">
            <i class="fa-solid fa-qrcode" style="color:#2563eb;"></i>
            Attendance Tracker
          </h2>
          <div style="font-size:0.80rem; color:#64748b; margin-top:3px;">
            <?= ($sess_role === 'admin' || $sess_role === 'super_admin') ? 'Master Attendance Audit Ledger &bull; Session Controls &bull; Administrative Overrides' : (($sess_role === 'ssc') ? 'Campus-Wide Attendance Monitoring &bull; Active QR Sessions &bull; Scan Verification' : 'Verified Attendee Logs &bull; Real-time QR Check-in Rosters') ?>
          </div>
        </div>

        <div style="display:flex; gap:10px;">
          <a href="tracking_qr_generator.php" class="btn-sys-secondary" style="display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; font-weight:700; text-decoration:none; font-size:0.84rem;">
            <i class="fa-solid fa-qrcode"></i> QR Operator Screen
          </a>
          <?php if (can('attendance.override')): ?>
            <button type="button" class="btn-sys-primary" onclick="openManualOverrideModal()" style="display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; font-weight:700; cursor:pointer;">
              <i class="fa-solid fa-pen-to-square"></i> Manual Override
            </button>
          <?php endif; ?>
        </div>
      </div>

      <div class="content-body">

        <!-- 1. Role-Tailored KPI Metric Cards (Consistent Equal Sizes) -->
        <?php if ($sess_role === 'ssc'): ?>
          <!-- SSC KPI Metrics (Section 12) -->
          <div class="att-kpi-grid">
            <div class="metric-card">
              <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;">
                <i class="fa-solid fa-calendar-check"></i>
              </div>
              <div class="metric-info">
                <div class="metric-val"><?= $ssc_active_events ?></div>
                <div class="metric-lbl">Active Events</div>
              </div>
            </div>

            <div class="metric-card">
              <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;">
                <i class="fa-solid fa-user-check"></i>
              </div>
              <div class="metric-info">
                <div class="metric-val"><?= $ssc_present_count ?></div>
                <div class="metric-lbl">Students Present</div>
              </div>
            </div>

            <div class="metric-card">
              <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;">
                <i class="fa-solid fa-clock-rotate-left"></i>
              </div>
              <div class="metric-info">
                <div class="metric-val"><?= $ssc_late_count ?></div>
                <div class="metric-lbl">Late Arrivals</div>
              </div>
            </div>

            <div class="metric-card">
              <div class="metric-icon-wrap" style="background:#fee2e2; color:#dc2626;">
                <i class="fa-solid fa-hourglass-half"></i>
              </div>
              <div class="metric-info">
                <div class="metric-val"><?= $ssc_pending_count ?></div>
                <div class="metric-lbl">Pending Attendance</div>
              </div>
            </div>
          </div>

        <?php else: ?>
          <!-- Admin KPI Metrics (Section 15) -->
          <div class="att-kpi-grid">
            <div class="metric-card">
              <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;">
                <i class="fa-solid fa-clipboard-check"></i>
              </div>
              <div class="metric-info">
                <div class="metric-val"><?= $kpi_attendance_logs ?></div>
                <div class="metric-lbl">Total Check-Ins</div>
              </div>
            </div>

            <div class="metric-card">
              <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;">
                <i class="fa-solid fa-satellite-dish"></i>
              </div>
              <div class="metric-info">
                <div class="metric-val"><?= $kpi_active_sessions ?></div>
                <div class="metric-lbl">Active QR Sessions</div>
              </div>
            </div>

            <div class="metric-card">
              <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;">
                <i class="fa-solid fa-pen-to-square"></i>
              </div>
              <div class="metric-info">
                <div class="metric-val"><?= $kpi_manual_overrides ?></div>
                <div class="metric-lbl">Manual Overrides</div>
              </div>
            </div>

            <div class="metric-card">
              <div class="metric-icon-wrap" style="background:#fee2e2; color:#dc2626;">
                <i class="fa-solid fa-triangle-exclamation"></i>
              </div>
              <div class="metric-info">
                <div class="metric-val"><?= $kpi_failed_scans ?></div>
                <div class="metric-lbl">Failed / Dup Scans</div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════════
             NAVIGATION TAB SWITCHER (All Roles)
             ════════════════════════════════════════════════════════════ -->
        <div class="att-tabs-nav">
          <a class="att-tab-link active" data-tab="tabLedger" onclick="switchAttTab('tabLedger')">
            <div class="att-tab-lead">
              <div class="att-tab-icon-box" style="background:#dbeafe; color:#2563eb;">
                <i class="fa-solid fa-table-list"></i>
              </div>
              <div class="att-tab-info">
                <span class="att-tab-title"><?= ($sess_role === 'ssc') ? 'SSC Attendance Monitor' : (($sess_role === 'club_adviser') ? 'Organization Ledger' : 'System Attendance Ledger') ?></span>
                <span class="att-tab-subtitle"><?= ($sess_role === 'club_adviser') ? 'Verified org turnout' : 'Master turnout records' ?></span>
              </div>
            </div>
            <span id="tabLedgerCountBadge" class="att-tab-pill"><?= count($admin_logs) ?></span>
          </a>

          <a class="att-tab-link" data-tab="tabSessions" onclick="switchAttTab('tabSessions')">
            <div class="att-tab-lead">
              <div class="att-tab-icon-box" style="background:#e0f2fe; color:#0284c7;">
                <i class="fa-solid fa-qrcode"></i>
              </div>
              <div class="att-tab-info">
                <span class="att-tab-title">QR Sessions</span>
                <span class="att-tab-subtitle">Live check-in rooms</span>
              </div>
            </div>
            <span class="att-tab-pill" style="background:#f0f9ff; color:#0284c7; border-color:#bae6fd;"><?= count($qr_sessions) ?></span>
          </a>

          <a class="att-tab-link" data-tab="tabAttempts" onclick="switchAttTab('tabAttempts')">
            <div class="att-tab-lead">
              <div class="att-tab-icon-box" style="background:#fee2e2; color:#dc2626;">
                <i class="fa-solid fa-shield-halved"></i>
              </div>
              <div class="att-tab-info">
                <span class="att-tab-title">Scan Attempts &amp; Audit</span>
                <span class="att-tab-subtitle">Duplicate &amp; fraud logs</span>
              </div>
            </div>
            <span class="att-tab-pill" style="background:#fef2f2; color:#b91c1c; border-color:#fecaca;"><?= count($scan_attempts) ?></span>
          </a>

          <a class="att-tab-link" data-tab="tabEventRoster" onclick="switchAttTab('tabEventRoster')">
            <div class="att-tab-lead">
              <div class="att-tab-icon-box" style="background:#dcfce7; color:#16a34a;">
                <i class="fa-solid fa-users"></i>
              </div>
              <div class="att-tab-info">
                <span class="att-tab-title">Event Attendance Sheet</span>
                <span class="att-tab-subtitle">Per-event attendee roster</span>
              </div>
            </div>
            <span class="att-tab-pill" style="background:#f0fdf4; color:#16a34a; border-color:#bbf7d0;"><?= count($events) ?></span>
          </a>
        </div>

        <!-- ════════════════════════════════════════════════════════════
             TAB 1: ATTENDANCE LEDGER / SSC ATTENDANCE MONITOR
             ════════════════════════════════════════════════════════════ -->
        <div id="tabLedger" class="att-tab-content">
          <?php if ($sess_role === 'ssc'): ?>
            <!-- SSC ATTENDANCE MONITOR TABLE (Section 12) -->
            <div class="card" style="padding:20px; border-radius:14px; border:1px solid #e2e8f0; margin-bottom:20px;">
              <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                <div>
                  <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#0f172a;">
                    <i class="fa-solid fa-tower-broadcast" style="color:#2563eb;"></i> SSC Event Attendance Monitor
                  </h3>
                  <div style="font-size:0.8rem; color:#64748b; margin-top:2px;">
                    Real-time monitoring across all campus clubs and approved activity sessions.
                  </div>
                </div>

                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                  <input type="text" id="sscEventSearch" placeholder="Search event or club..." onkeyup="filterSscEventsTable()" style="height:36px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.82rem; outline:none; background:#f8fafc;" />
                  <select id="sscStatusFilter" onchange="filterSscEventsTable()" style="height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.82rem; font-weight:600; outline:none; background:#f8fafc;">
                    <option value="">All Sessions</option>
                    <option value="LIVE">● LIVE Only</option>
                    <option value="CLOSED">Closed Sessions</option>
                  </select>
                </div>
              </div>

              <div class="table-wrap">
                <table id="sscEventsMonitorTable">
                  <thead>
                    <tr>
                      <th style="padding:14px 18px;">Event Title</th>
                      <th style="padding:14px 18px;">Host Club</th>
                      <th style="padding:14px 18px;">Date &amp; Time</th>
                      <th style="padding:14px 18px;">Attendance Progress</th>
                      <th style="padding:14px 18px;">QR Session</th>
                      <th style="padding:14px 18px; text-align:center;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($ssc_events_monitor)): ?>
                      <tr>
                        <td colspan="6" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                          <i class="fa-solid fa-calendar-xmark" style="font-size:2rem; color:#cbd5e1; margin-bottom:8px; display:block;"></i>
                          No active or recent events found.
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($ssc_events_monitor as $sev): ?>
                        <?php
                          $isLive = ($sev['qr_status'] === 'active');
                          $reg = (int)$sev['reg_count'];
                          $scans = (int)$sev['total_scans'];
                          $pct = ($reg > 0) ? min(100, round(($scans / $reg) * 100)) : 0;
                        ?>
                        <tr class="ssc-event-row" data-live="<?= $isLive ? 'LIVE' : 'CLOSED' ?>">
                          <td data-label="Event" style="padding:14px 18px;">
                            <strong style="color:#0f172a; font-size:0.9rem;"><?= htmlspecialchars($sev['title']) ?></strong>
                            <div style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($sev['venue'] ?? 'Campus') ?></div>
                          </td>
                          <td data-label="Club" style="padding:14px 18px; font-size:0.85rem; color:#334155;">
                            <?= htmlspecialchars($sev['club_name'] ?? 'College Event') ?>
                            <span style="color:#94a3b8; font-weight:600;">(<?= htmlspecialchars($sev['club_code'] ?? 'BCP') ?>)</span>
                          </td>
                          <td data-label="Date" style="padding:14px 18px; font-size:0.85rem; color:#475569;">
                            <?= date('M d, Y', strtotime($sev['event_date'])) ?>
                            <div style="font-size:0.75rem; color:#94a3b8;"><?= date('h:i A', strtotime($sev['event_date'])) ?></div>
                          </td>
                          <td data-label="Progress" style="padding:14px 18px;">
                            <div style="display:flex; justify-content:space-between; font-size:0.82rem; font-weight:700;">
                              <span style="color:#0f172a;"><?= $scans ?> / <?= $reg ?> Checked In</span>
                              <span style="color:#16a34a;"><?= $pct ?>%</span>
                            </div>
                            <div class="att-progress-bar-bg">
                              <div class="att-progress-bar-fill" style="width: <?= $pct ?>%;"></div>
                            </div>
                          </td>
                          <td data-label="Session" style="padding:14px 18px;">
                            <span class="<?= $isLive ? 'pill-live' : 'pill-closed' ?>">
                              <?= $isLive ? '● LIVE' : 'CLOSED' ?>
                            </span>
                          </td>
                          <td data-label="Actions" style="padding:14px 18px; text-align:center;">
                            <div style="display:inline-flex; gap:6px;">
                              <a href="tracking_qr_generator.php?event_id=<?= $sev['id'] ?>" class="btn-sys-secondary" style="padding:6px 12px; font-size:0.75rem; border-radius:6px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px;" title="View Live QR Operator">
                                <i class="fa-solid fa-qrcode"></i> View QR
                              </a>
                              <button type="button" class="btn-sys-primary" onclick="openMonitorModal(<?= $sev['id'] ?>, '<?= htmlspecialchars(addslashes($sev['title'])) ?>')" style="padding:6px 12px; font-size:0.75rem; border-radius:6px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                <i class="fa-solid fa-eye"></i> Monitor
                              </button>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>

          <!-- SYSTEM / ORGANIZATION ATTENDANCE LEDGER CARD -->
          <div class="card" id="printLedgerArea" style="padding:22px 24px; border-radius:14px; border:1px solid #e2e8f0;">
            <!-- Card Header: Title & Export Group -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:18px;">
              <div>
                <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                  <i class="fa-solid fa-table-list" style="color:#2563eb;"></i> 
                  <?= ($sess_role === 'admin' || $sess_role === 'super_admin' || $sess_role === 'staff') ? 'System Attendance Ledger' : (($sess_role === 'club_adviser') ? 'Organization Attendance Ledger' : 'System Attendance Records') ?>
                  <span id="ledgerCountBadge" class="count-badge"><?= count($admin_logs) ?> Records</span>
                </h3>
                <div style="font-size:0.78rem; color:#64748b; margin-top:4px;">
                  <?= ($sess_role === 'admin' || $sess_role === 'super_admin' || $sess_role === 'staff') 
                      ? 'Master log of event attendance records, entry methods, recording actors, and verification statuses across all college programs.' 
                      : 'Verified attendee records and check-in logs for <strong>' . htmlspecialchars($adviser_club_name ?: 'Your Organization') . '</strong>' . (!empty($adviser_program_code) ? ' &bull; Program: <span style="font-weight:700; color:#2563eb;">' . htmlspecialchars($adviser_program_code) . '</span>' : '') . '.' ?>
                </div>
              </div>

              <!-- Top Right: Export Dropdown Button -->
              <div class="btn-export-group">
                <button type="button" class="btn-export-main" onclick="toggleExportMenu('adminExportMenu')" title="Download attendance records in PDF, Excel, or CSV">
                  <i class="fa-solid fa-cloud-arrow-down"></i>
                  <span>Export Report</span>
                  <i class="fa-solid fa-chevron-down" style="font-size:0.65rem; margin-left:3px;"></i>
                </button>
                <div id="adminExportMenu" class="export-dropdown-menu">
                  <div class="export-menu-header">
                    Attendance Report
                  </div>
                  <button type="button" class="export-menu-item" onclick="exportLedgerPDF()">
                    <i class="fa-solid fa-file-pdf" style="color:#dc2626; font-size:1rem;"></i>
                    <div>
                      <div class="export-item-title">Download PDF (.pdf)</div>
                      <div class="export-item-sub">Formal print-ready report</div>
                    </div>
                  </button>
                  <button type="button" class="export-menu-item" onclick="exportLedgerExcel()">
                    <i class="fa-solid fa-file-excel" style="color:#16a34a; font-size:1rem;"></i>
                    <div>
                      <div class="export-item-title">Download Excel (.xlsx)</div>
                      <div class="export-item-sub">Structured spreadsheet format</div>
                    </div>
                  </button>
                  <button type="button" class="export-menu-item" onclick="exportLedgerCSV()">
                    <i class="fa-solid fa-file-csv" style="color:#2563eb; font-size:1rem;"></i>
                    <div>
                      <div class="export-item-title">Download CSV (.csv)</div>
                      <div class="export-item-sub">Raw UTF-8 delimited dataset</div>
                    </div>
                  </button>
                  <div class="export-menu-divider"></div>
                  <button type="button" class="export-menu-item" onclick="printLedger()">
                    <i class="fa-solid fa-print" style="color:#475569; font-size:1rem;"></i>
                    <div>
                      <div class="export-item-title">Print Attendance Roster</div>
                      <div class="export-item-sub">Paper / browser printout</div>
                    </div>
                  </button>
                </div>
              </div>
            </div>

            <!-- Dedicated Filter Toolbar -->
            <div class="att-toolbar">
              <div class="att-toolbar-filters">
                <!-- Search Box with Icon -->
                <div class="att-search-wrap">
                  <i class="fa-solid fa-magnifying-glass"></i>
                  <input type="text" id="adminAttendanceSearch" placeholder="Search student, event, actor..." oninput="filterAdminAttendance()" />
                </div>
                
                <!-- Event Dropdown Filter -->
                <select id="adminEventFilter" class="att-filter-select" onchange="filterAdminAttendance()" style="max-width:200px;">
                  <option value="all">All Events</option>
                  <?php 
                    $unique_events = [];
                    foreach ($admin_logs as $l) {
                      $evId = (int)($l['event_id'] ?? 0);
                      if ($evId > 0 && !isset($unique_events[$evId])) {
                        $unique_events[$evId] = $l['event_title'] ?? ('Event #' . $evId);
                      }
                    }
                    foreach ($unique_events as $uEid => $uETitle):
                  ?>
                    <option value="<?= $uEid ?>"><?= htmlspecialchars(mb_strimwidth($uETitle, 0, 26, '...')) ?></option>
                  <?php endforeach; ?>
                </select>

                <!-- Status Dropdown Filter -->
                <select id="adminStatusFilter" class="att-filter-select" onchange="filterAdminAttendance()">
                  <option value="all">All Statuses</option>
                  <option value="present">Present</option>
                  <option value="late">Late</option>
                  <option value="excused">Excused</option>
                  <option value="valid">Valid</option>
                </select>

                <!-- Method Dropdown Filter -->
                <select id="adminMethodFilter" class="att-filter-select" onchange="filterAdminAttendance()">
                  <option value="all">All Methods</option>
                  <option value="qr">QR Code</option>
                  <option value="manual">Manual</option>
                </select>

                <!-- Reset Button -->
                <button type="button" class="btn-reset-filters" onclick="resetAdminFilters()" title="Reset all filters">
                  <i class="fa-solid fa-rotate-left"></i>
                  <span>Reset</span>
                </button>
              </div>

              <!-- Active Status Indicator -->
              <div style="font-size:0.75rem; color:#64748b; font-weight:600; white-space:nowrap;">
                Showing <strong id="visibleCountDisplay" style="color:#2563eb;"><?= count($admin_logs) ?></strong> of <?= count($admin_logs) ?> verified logs
              </div>
            </div>

            <div class="table-wrap">
              <table id="adminAttendanceTable" class="table-wide">
                <thead>
                  <tr>
                    <th style="padding:12px 14px; min-width:125px;">Timestamp</th>
                    <th style="padding:12px 14px; min-width:190px;">Student Attendee</th>
                    <th style="padding:12px 14px; min-width:210px;">Campus Event</th>
                    <th style="padding:12px 14px; min-width:90px;">Method</th>
                    <th style="padding:12px 14px; min-width:125px;">Recorded By</th>
                    <th style="padding:12px 14px; min-width:135px;">Status</th>
                    <th style="padding:12px 14px; text-align:center; min-width:85px;">Action</th>
                  </tr>
                </thead>
                <tbody id="adminAttendanceTableBody">
                  <?php if (empty($admin_logs)): ?>
                    <tr id="emptyLedgerRow">
                      <td colspan="7" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                        <i class="fa-solid fa-clipboard-list" style="font-size:2rem; color:#cbd5e1; margin-bottom:8px; display:block;"></i>
                        No attendance records found in the database.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($admin_logs as $log): ?>
                      <?php
                        $stLower = strtolower($log['status'] ?? 'present');
                        $isLate = ($stLower === 'late');
                        $isManual = ($log['method'] === 'Manual');
                        $searchHaystack = strtolower(
                          ($log['first_name'] ?? '') . ' ' .
                          ($log['last_name'] ?? '') . ' ' .
                          ($log['student_number'] ?? '') . ' ' .
                          ($log['username'] ?? '') . ' ' .
                          ($log['event_title'] ?? '') . ' ' .
                          ($log['club_name'] ?? '') . ' ' .
                          ($log['club_code'] ?? '') . ' ' .
                          ($log['course'] ?? '') . ' ' .
                          ($log['logger_first'] ?? '') . ' ' .
                          ($log['logger_last'] ?? '')
                        );
                      ?>
                      <tr class="admin-att-row" 
                          data-status="<?= htmlspecialchars($stLower) ?>" 
                          data-method="<?= htmlspecialchars(strtolower($log['method'] ?? 'qr')) ?>"
                          data-event-id="<?= (int)$log['event_id'] ?>"
                          data-search="<?= htmlspecialchars($searchHaystack) ?>">
                        <td style="padding:12px 14px; font-size:0.82rem; color:#475569;">
                          <?= date('M d, Y', strtotime($log['check_in'])) ?>
                          <div style="font-size:0.72rem; color:#94a3b8;"><?= date('h:i A', strtotime($log['check_in'])) ?></div>
                        </td>
                        <td style="padding:12px 14px;">
                          <strong style="color:#0f172a; font-size:0.88rem;"><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></strong>
                          <div style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($log['student_number'] ?? 'ID#' . $log['user_id']) ?> &bull; <?= htmlspecialchars($log['course'] ?? '') ?></div>
                        </td>
                        <td style="padding:12px 14px; font-size:0.84rem; color:#334155;">
                          <strong><?= htmlspecialchars($log['event_title'] ?? 'Campus Event') ?></strong>
                          <div style="font-size:0.72rem; color:#94a3b8;"><?= htmlspecialchars($log['club_name'] ?? ($log['club_code'] ?? 'Campus-Wide')) ?></div>
                        </td>
                        <td style="padding:12px 14px;">
                          <span style="padding:3px 8px; border-radius:6px; font-size:0.74rem; font-weight:700; background:#f1f5f9; color:#475569; display:inline-flex; align-items:center; gap:4px;">
                            <i class="fa-solid <?= $isManual ? 'fa-pen-to-square' : 'fa-qrcode' ?>"></i>
                            <?= htmlspecialchars($log['method'] ?? 'QR') ?>
                          </span>
                        </td>
                        <td style="padding:12px 14px; font-size:0.82rem; color:#475569;">
                          <?= !empty($log['logger_first']) ? htmlspecialchars($log['logger_first'] . ' ' . $log['logger_last']) : 'Self Check-in' ?>
                        </td>
                        <td style="padding:12px 14px;">
                          <span style="padding:3px 10px; font-size:0.74rem; font-weight:700; border-radius:6px; display:inline-flex; align-items:center; gap:4px; background:<?= $isLate ? '#fef3c7' : '#dcfce7' ?>; color:<?= $isLate ? '#b45309' : '#15803d' ?>;">
                            <i class="fa-solid <?= $isLate ? 'fa-clock-rotate-left' : 'fa-check' ?>"></i>
                            <?= htmlspecialchars($log['status'] ?? 'Present') ?>
                          </span>
                        </td>
                        <td style="padding:12px 14px; text-align:center;">
                          <button type="button" class="btn-sys-secondary" onclick='openLogDetailModal(<?= json_encode([
                            "id" => $log["id"],
                            "name" => ($log["first_name"] . " " . $log["last_name"]),
                            "student_number" => ($log["student_number"] ?? "ID#" . $log["user_id"]),
                            "course" => trim(($log["course"] ?? "") . " " . ($log["year_level"] ?? "") . " " . ($log["section"] ?? "")),
                            "email" => $log["email"] ?? "",
                            "event_title" => $log["event_title"] ?? "Campus Event",
                            "event_venue" => $log["event_venue"] ?? "Campus",
                            "club_name" => $log["club_name"] ?? ($log["club_code"] ?? "College"),
                            "timestamp" => date("M d, Y h:i:s A", strtotime($log["check_in"])),
                            "method" => $log["method"] ?? "QR",
                            "status" => $log["status"] ?? "Present",
                            "recorder" => !empty($log["logger_first"]) ? ($log["logger_first"] . " " . $log["logger_last"]) : "Self Check-in",
                            "override_reason" => $log["override_reason"] ?? ""
                          ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' style="padding:4px 8px; font-size:0.75rem; border-radius:6px; cursor:pointer;" title="View Verification Details">
                            <i class="fa-solid fa-circle-info"></i> Details
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════
             TAB 2: QR SESSIONS MANAGEMENT (Section 16)
             ════════════════════════════════════════════════════════════ -->
        <div id="tabSessions" class="att-tab-content" style="display:none;">
          <div class="card" style="padding:20px; border-radius:14px; border:1px solid #e2e8f0;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <div>
                <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#0f172a;">
                  <i class="fa-solid fa-qrcode" style="color:#2563eb;"></i> QR Attendance Sessions Management
                </h3>
                <div style="font-size:0.8rem; color:#64748b; margin-top:2px;">
                  Active and historical dynamic token sessions operated by Club Advisers.
                </div>
              </div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th style="padding:14px 18px;">Event Title</th>
                    <th style="padding:14px 18px;">Organizer Club</th>
                    <th style="padding:14px 18px;">Opened At</th>
                    <th style="padding:14px 18px;">Status</th>
                    <th style="padding:14px 18px;">Scans Logged</th>
                    <th style="padding:14px 18px; text-align:center;">Action Controls</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($qr_sessions)): ?>
                    <tr>
                      <td colspan="6" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                        <i class="fa-solid fa-qrcode" style="font-size:2rem; color:#cbd5e1; margin-bottom:8px; display:block;"></i>
                        No dynamic QR sessions recorded yet.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($qr_sessions as $qs): ?>
                      <?php
                        $st = $qs['status'];
                        $isActive = ($st === 'active');
                        $isTerminated = ($st === 'terminated');
                      ?>
                      <tr>
                        <td data-label="Event" style="padding:14px 18px;">
                          <strong style="color:#0f172a;"><?= htmlspecialchars($qs['event_title']) ?></strong>
                          <div style="font-size:0.75rem; color:#64748b;">Token Seed: <code><?= htmlspecialchars(substr($qs['current_token'], 0, 10)) ?>...</code></div>
                        </td>
                        <td data-label="Club" style="padding:14px 18px; font-size:0.85rem; color:#334155;">
                          <?= htmlspecialchars($qs['club_name'] ?? 'College') ?>
                          <div style="font-size:0.75rem; color:#94a3b8;">By: <?= htmlspecialchars($qs['creator_first'] . ' ' . $qs['creator_last']) ?></div>
                        </td>
                        <td data-label="Opened" style="padding:14px 18px; font-size:0.85rem; color:#475569;">
                          <?= date('M d, Y h:i A', strtotime($qs['opened_at'])) ?>
                        </td>
                        <td data-label="Status" style="padding:14px 18px;">
                          <?php if ($isActive): ?>
                            <span class="pill-live">● LIVE</span>
                          <?php elseif ($isTerminated): ?>
                            <span class="pill-terminated">✕ TERMINATED</span>
                            <div style="font-size:0.72rem; color:#dc2626; margin-top:2px;"><?= htmlspecialchars($qs['close_reason'] ?? 'Security termination') ?></div>
                          <?php else: ?>
                            <span class="pill-closed">CLOSED</span>
                          <?php endif; ?>
                        </td>
                        <td data-label="Scans" style="padding:14px 18px; font-weight:700; color:#0f172a;">
                          <?= $qs['total_scans'] ?> attendees
                        </td>
                        <td data-label="Actions" style="padding:14px 18px; text-align:center;">
                          <div style="display:inline-flex; gap:6px;">
                            <a href="tracking_qr_generator.php?event_id=<?= $qs['event_id'] ?>" class="btn-sys-secondary" style="padding:6px 12px; font-size:0.75rem; border-radius:6px; font-weight:700; text-decoration:none;" title="View Live Screen">
                              <i class="fa-solid fa-eye"></i> View
                            </a>
                            <?php if ($sess_role === 'admin' && $isActive): ?>
                              <button type="button" class="btn-sys-danger" onclick="openTerminateModal(<?= $qs['id'] ?>, '<?= htmlspecialchars(addslashes($qs['event_title'])) ?>')" style="padding:6px 12px; font-size:0.75rem; border-radius:6px; font-weight:700; cursor:pointer;" title="Terminate Session">
                                <i class="fa-solid fa-ban"></i> Terminate
                              </button>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════
             TAB 3: SCAN ATTEMPTS LOG (Section 14 & 18)
             ════════════════════════════════════════════════════════════ -->
        <div id="tabAttempts" class="att-tab-content" style="display:none;">
          <div class="card" style="padding:20px; border-radius:14px; border:1px solid #e2e8f0;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <div>
                <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#0f172a;">
                  <i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i> QR Scan Attempts &amp; Verification Trail
                </h3>
                <div style="font-size:0.8rem; color:#64748b; margin-top:2px;">
                  Audited check-in attempts, duplicate scans, expired tokens, and rejected scans.
                </div>
              </div>

              <div style="display:flex; align-items:center; gap:8px;">
                <select id="attemptResultFilter" onchange="filterAttemptsTable()" style="height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.82rem; font-weight:600; outline:none; background:#f8fafc;">
                  <option value="">All Scan Results</option>
                  <option value="VALID">VALID / SUCCESS</option>
                  <option value="ALREADY_SCANNED">ALREADY_SCANNED (Duplicate)</option>
                  <option value="EXPIRED">EXPIRED Token</option>
                  <option value="NOT_ELIGIBLE">NOT_ELIGIBLE (Unauthorized)</option>
                  <option value="CLOSED">CLOSED Session</option>
                  <option value="INVALID_TOKEN">INVALID_TOKEN</option>
                </select>
              </div>
            </div>

            <div class="table-wrap">
              <table id="scanAttemptsTable">
                <thead>
                  <tr>
                    <th style="padding:14px 18px;">Student Attendee</th>
                    <th style="padding:14px 18px;">Target Event</th>
                    <th style="padding:14px 18px;">Timestamp</th>
                    <th style="padding:14px 18px;">Scan Result</th>
                    <th style="padding:14px 18px;">Reason / Diagnostics</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($scan_attempts)): ?>
                    <tr>
                      <td colspan="5" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                        <i class="fa-solid fa-shield-check" style="font-size:2rem; color:#cbd5e1; margin-bottom:8px; display:block;"></i>
                        No scan attempts logged in the database yet.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($scan_attempts as $sa): ?>
                      <?php
                        $resCode = strtoupper($sa['result']);
                        $isSuccess = in_array($resCode, ['VALID', 'PRESENT', 'LATE']);
                        $isDup = ($resCode === 'ALREADY_SCANNED');
                        $isExpired = ($resCode === 'EXPIRED');
                        $pillColor = $isSuccess ? '#16a34a' : ($isDup ? '#2563eb' : ($isExpired ? '#d97706' : '#dc2626'));
                        $pillBg = $isSuccess ? '#dcfce7' : ($isDup ? '#eff6ff' : ($isExpired ? '#fef3c7' : '#fee2e2'));
                      ?>
                      <tr class="attempt-data-row" data-result="<?= $resCode ?>">
                        <td data-label="Student" style="padding:14px 18px;">
                          <?php if (!empty($sa['first_name'])): ?>
                            <strong style="color:#0f172a;"><?= htmlspecialchars($sa['first_name'] . ' ' . $sa['last_name']) ?></strong>
                            <div style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($sa['student_number'] ?? 'ID#' . $sa['user_id']) ?> &bull; <?= htmlspecialchars($sa['course'] ?? '') ?></div>
                          <?php else: ?>
                            <span style="color:#94a3b8; font-style:italic;">Guest / Anonymous Scan</span>
                          <?php endif; ?>
                        </td>
                        <td data-label="Event" style="padding:14px 18px; font-size:0.85rem; color:#334155;">
                          <strong><?= htmlspecialchars($sa['event_title'] ?? 'Campus Activity #' . $sa['event_id']) ?></strong>
                        </td>
                        <td data-label="Time" style="padding:14px 18px; font-size:0.82rem; color:#475569;">
                          <?= date('M d, Y', strtotime($sa['scanned_at'])) ?>
                          <div style="font-size:0.72rem; color:#94a3b8;"><?= date('h:i:s A', strtotime($sa['scanned_at'])) ?></div>
                        </td>
                        <td data-label="Result" style="padding:14px 18px;">
                          <span style="padding:4px 10px; border-radius:6px; font-size:0.75rem; font-weight:800; background:<?= $pillBg ?>; color:<?= $pillColor ?>; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid <?= $isSuccess ? 'fa-check' : ($isDup ? 'fa-clone' : 'fa-triangle-exclamation') ?>"></i>
                            <?= $resCode ?>
                          </span>
                        </td>
                        <td data-label="Reason" style="padding:14px 18px; font-size:0.82rem; color:#64748b;">
                          <?= htmlspecialchars($sa['reason'] ?? 'Normal scan check-in') ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════
             TAB 4: EVENT ATTENDEES ROSTER (All Roles)
             ════════════════════════════════════════════════════════════ -->
        <div id="tabEventRoster" class="att-tab-content" style="display:none;">
          <div class="card" id="printEventRosterArea" style="padding:20px; border-radius:14px; border:1px solid #e2e8f0;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <label style="font-size:0.85rem; font-weight:700; color:#0f172a;">Select Event:</label>
                <select id="eventFilterSelect" class="event-dropdown-select" onchange="loadEventAttendees(this.value)" style="height:38px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.84rem; font-weight:600; max-width:320px;">
                  <?php if (empty($grouped_events)): ?>
                    <option value="">No Events Found</option>
                  <?php else: ?>
                    <?php $first_selected = true; ?>
                    <?php foreach ($grouped_events as $progGroup => $groupEvs): ?>
                      <optgroup label="<?= htmlspecialchars($progGroup) ?>">
                        <?php foreach ($groupEvs as $ev): ?>
                          <option value="<?= $ev['id'] ?>" <?= $first_selected ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['title']) ?> (<?= date('M d, Y', strtotime($ev['event_date'])) ?>)
                          </option>
                          <?php $first_selected = false; ?>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>

                <span class="stat-badge-pill" id="attendeeCountPill" style="padding:6px 14px; border-radius:8px; font-size:0.82rem; font-weight:700; background:#f1f5f9; color:#334155;">
                  <i class="fa-solid fa-check-double" style="color:#16a34a;"></i> Verified: <strong id="attendeeCountNum">0</strong>
                </span>
              </div>

              <!-- Export Controls for Selected Event -->
              <div style="display:flex; align-items:center; gap:8px;">
                <div class="btn-export-group">
                  <button type="button" class="btn-export-main" onclick="toggleExportMenu('eventRosterExportMenu')" style="background:#059669;" title="Download this event's attendance roster">
                    <i class="fa-solid fa-download"></i>
                    <span>Export Roster</span>
                    <i class="fa-solid fa-chevron-down" style="font-size:0.65rem; margin-left:2px;"></i>
                  </button>
                  <div id="eventRosterExportMenu" class="export-dropdown-menu">
                    <button type="button" class="export-menu-item" onclick="exportEventRosterPDF()">
                      <i class="fa-solid fa-file-pdf" style="color:#dc2626; font-size:0.95rem;"></i>
                      <span>Download PDF (.pdf)</span>
                    </button>
                    <button type="button" class="export-menu-item" onclick="exportEventRosterExcel()">
                      <i class="fa-solid fa-file-excel" style="color:#16a34a; font-size:0.95rem;"></i>
                      <span>Download Excel (.xlsx)</span>
                    </button>
                    <button type="button" class="export-menu-item" onclick="exportEventRosterCSV()">
                      <i class="fa-solid fa-file-csv" style="color:#2563eb; font-size:0.95rem;"></i>
                      <span>Download CSV (.csv)</span>
                    </button>
                    <div class="export-menu-divider"></div>
                    <button type="button" class="export-menu-item" onclick="printEventRoster()">
                      <i class="fa-solid fa-print" style="color:#475569; font-size:0.95rem;"></i>
                      <span>Print Event Roster</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div class="table-wrap">
              <table id="eventAttendeeTable" class="table-wide">
                <thead>
                  <tr>
                    <th style="width:50px;">#</th>
                    <th>Student Name</th>
                    <th>Student Number / Email</th>
                    <th>Check-In Time</th>
                    <th>Method</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="eventAttendeeTableBody">
                  <tr>
                    <td colspan="6" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                      Loading attendee logs for selected event...
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">eLearning Commons &copy; 2026</div>
  </div><!-- end main -->

  <!-- ── MODAL 1: Admin Terminate Session Modal (Section 16) ───────────── -->
  <div id="terminateModal" class="op-modal-backdrop" style="display:none;" onclick="if(event.target===this)closeTerminateModal()">
    <div class="op-modal-card">
      <h3 style="margin:0 0 6px 0; font-size:1.25rem; font-weight:800; color:#dc2626;">
        <i class="fa-solid fa-ban"></i> Terminate Attendance Session?
      </h3>
      <p style="margin:0 0 16px 0; font-size:0.84rem; color:#64748b;">
        Terminating this session will immediately revoke all active QR tokens for this event. Students will receive an "Attendance Closed" alert.
      </p>

      <form id="terminateForm" onsubmit="event.preventDefault();submitTerminateSession();">
        <input type="hidden" id="termSessionId" value="0" />
        <div style="margin-bottom:14px;">
          <label style="font-size:0.76rem; font-weight:800; text-transform:uppercase; color:#475569; display:block; margin-bottom:4px;">Event</label>
          <input type="text" id="termEventTitle" readonly style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:8px; padding:0 12px; font-size:0.85rem; font-weight:700; background:#f1f5f9; box-sizing:border-box;" />
        </div>

        <div style="margin-bottom:18px;">
          <label style="font-size:0.76rem; font-weight:800; text-transform:uppercase; color:#475569; display:block; margin-bottom:4px;">
            Termination Justification Reason <span style="color:#dc2626;">*</span>
          </label>
          <textarea id="termReasonInput" required placeholder="e.g. Unauthorized QR code photo distribution detected on social media." rows="3" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.84rem; font-family:inherit; box-sizing:border-box; outline:none; resize:none;"></textarea>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" onclick="closeTerminateModal()" style="height:42px; padding:0 18px; background:#f1f5f9; color:#475569; border:none; border-radius:10px; font-weight:700; cursor:pointer;">Cancel</button>
          <button type="submit" style="height:42px; padding:0 22px; background:#dc2626; color:#ffffff; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
            <i class="fa-solid fa-ban"></i> Terminate Session
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── MODAL 2: Manual Attendance Override Modal (Section 17) ────────── -->
  <div class="modal" id="manualOverrideModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:18px; width:90%; max-width:480px; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.2);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;">
          <i class="fa-solid fa-pen-to-square" style="color:#2563eb;"></i> Attendance Override
        </h3>
        <button onclick="closeModal('manualOverrideModal')" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;">&times;</button>
      </div>
      <form id="manualOverrideForm" onsubmit="handleManualOverride(event)">
        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.78rem; font-weight:800; text-transform:uppercase; color:#334155; margin-bottom:4px;">Event *</label>
          <select name="event_id" required class="event-dropdown-select" style="width:100%; max-width:none; height:40px; border-radius:8px; border:1px solid #cbd5e1; padding:0 10px; font-size:0.85rem;">
            <?php if (empty($grouped_events)): ?>
              <option value="">No Events Found</option>
            <?php else: ?>
              <?php foreach ($grouped_events as $progGroup => $groupEvs): ?>
                <optgroup label="<?= htmlspecialchars($progGroup) ?>">
                  <?php foreach ($groupEvs as $ev): ?>
                    <option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['title']) ?> (<?= date('M d, Y', strtotime($ev['event_date'])) ?>)</option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.78rem; font-weight:800; text-transform:uppercase; color:#334155; margin-bottom:4px;">Student ID or User ID *</label>
          <input type="number" name="user_id" required placeholder="Enter student user ID" style="width:100%; height:40px; padding:0 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.85rem; box-sizing:border-box;" />
        </div>
        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.78rem; font-weight:800; text-transform:uppercase; color:#334155; margin-bottom:4px;">New Status *</label>
          <select name="status" style="width:100%; height:40px; padding:0 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.85rem; box-sizing:border-box;">
            <option value="Present">Present (Verified Attendance)</option>
            <option value="Late">Late (Tardy Check-In)</option>
            <option value="Excused">Excused (Official College Representation)</option>
            <option value="Absent">Absent (Remove from Ledger)</option>
          </select>
        </div>
        <div style="margin-bottom:20px;">
          <label style="display:block; font-size:0.78rem; font-weight:800; text-transform:uppercase; color:#334155; margin-bottom:4px;">Justification / Override Reason *</label>
          <textarea name="override_reason" required placeholder="State regulatory reason for manual attendance entry (logged to security audit)..." rows="2" style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid #cbd5e1; font-family:inherit; font-size:0.84rem; box-sizing:border-box; outline:none; resize:none;"></textarea>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" onclick="closeModal('manualOverrideModal')" style="background:#f1f5f9; color:#475569; height:40px; padding:0 16px; font-weight:700; border:none; border-radius:8px; cursor:pointer;">Cancel</button>
          <button type="submit" style="height:40px; padding:0 22px; background:#2563eb; color:#fff; font-weight:700; border:none; border-radius:8px; cursor:pointer;">Save Override</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── MODAL 0: Record Verification Details Modal ──────────────────────── -->
  <div class="modal" id="recordDetailModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:18px; width:92%; max-width:520px; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.25);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
        <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
          <i class="fa-solid fa-clipboard-check" style="color:#2563eb;"></i> Attendance Verification Record
        </h3>
        <button onclick="closeRecordDetailModal()" style="background:none; border:none; font-size:1.3rem; cursor:pointer; color:#64748b;">&times;</button>
      </div>
      <div id="recordDetailBody" style="font-size:0.85rem; color:#334155;">
        <!-- Filled dynamically -->
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #f1f5f9; padding-top:14px;">
        <button type="button" onclick="closeRecordDetailModal()" style="height:38px; padding:0 20px; background:#f1f5f9; color:#475569; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Close</button>
      </div>
    </div>
  </div>

  <script src="../js/dashboard.js"></script>
  <script src="../js/table-pagination.js"></script>
  <script>
    // Global variable for current event roster
    window.currentLoadedAttendees = [];
    window.currentLoadedEventTitle = '';

    // Export Dropdown Menu Toggle
    function toggleExportMenu(menuId) {
      const menu = document.getElementById(menuId);
      if (!menu) return;
      const isOpen = menu.classList.contains('show');
      document.querySelectorAll('.export-dropdown-menu').forEach(m => m.classList.remove('show'));
      if (!isOpen) {
        menu.classList.add('show');
      }
    }

    window.addEventListener('click', (e) => {
      if (!e.target.closest('.btn-export-group')) {
        document.querySelectorAll('.export-dropdown-menu').forEach(m => m.classList.remove('show'));
      }
    });

    // Record Detail Modal
    function openLogDetailModal(data) {
      if (!data) return;
      const b = document.getElementById('recordDetailBody');
      if (!b) return;

      const isLate = (data.status || '').toLowerCase() === 'late';
      const statusPill = `<span style="padding:3px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; background:${isLate ? '#fef3c7' : '#dcfce7'}; color:${isLate ? '#b45309' : '#15803d'}; display:inline-flex; align-items:center; gap:4px;">
        <i class="fa-solid ${isLate ? 'fa-clock-rotate-left' : 'fa-check'}"></i> ${data.status || 'Present'}
      </span>`;

      b.innerHTML = `
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
          <div>
            <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Attendee Name</div>
            <div style="font-size:0.95rem; font-weight:700; color:#0f172a; margin-top:2px;">${data.name}</div>
          </div>
          <div>
            <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Student Number</div>
            <div style="font-size:0.92rem; font-weight:600; color:#1e293b; margin-top:2px;">${data.student_number}</div>
          </div>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
          <div>
            <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Academic Program</div>
            <div style="font-size:0.85rem; color:#334155; margin-top:2px;">${data.course || 'Unassigned'}</div>
          </div>
          <div>
            <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Email Address</div>
            <div style="font-size:0.85rem; color:#334155; margin-top:2px;">${data.email || 'N/A'}</div>
          </div>
        </div>
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; margin-bottom:14px;">
          <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b; margin-bottom:4px;">Campus Event</div>
          <div style="font-size:0.95rem; font-weight:800; color:#1e3a8a;">${data.event_title}</div>
          <div style="font-size:0.76rem; color:#64748b; margin-top:2px;">Host: ${data.club_name} &bull; Venue: ${data.event_venue}</div>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
          <div>
            <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Check-In Timestamp</div>
            <div style="font-size:0.85rem; font-weight:600; color:#0f172a; margin-top:2px;">${data.timestamp}</div>
          </div>
          <div>
            <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Verification Method</div>
            <div style="font-size:0.85rem; font-weight:700; color:#334155; margin-top:2px;">${data.method}</div>
          </div>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
          <div>
            <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Recorded By</div>
            <div style="font-size:0.85rem; color:#334155; margin-top:2px;">${data.recorder}</div>
          </div>
          <div>
            <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Attendance Status</div>
            <div style="margin-top:2px;">${statusPill}</div>
          </div>
        </div>
        ${data.override_reason ? `
          <div style="background:#fffbeb; border:1px solid #fef3c7; border-radius:8px; padding:10px 12px; margin-top:10px;">
            <div style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#92400e;">Override Justification Note</div>
            <div style="font-size:0.82rem; color:#78350f; margin-top:2px;">${data.override_reason}</div>
          </div>
        ` : ''}
      `;
      document.getElementById('recordDetailModal').style.display = 'flex';
    }

    function closeRecordDetailModal() {
      const m = document.getElementById('recordDetailModal');
      if (m) m.style.display = 'none';
    }

    // Section Switcher (controlled directly from the tabs and sidebar)
    function switchAttTab(tabId, updateHash = true) {
      document.querySelectorAll('.att-tab-content').forEach(c => c.style.display = 'none');
      document.querySelectorAll('.att-tab-link').forEach(l => l.classList.remove('active'));

      const target = document.getElementById(tabId);
      if (target) target.style.display = 'block';

      const activeLink = document.querySelector(`.att-tab-link[data-tab="${tabId}"]`);
      if (activeLink) activeLink.classList.add('active');

      if (updateHash) {
        const hashMap = {
          'tabLedger': 'ledger',
          'tabSessions': 'sessions',
          'tabAttempts': 'attempts',
          'tabEventRoster': 'roster'
        };
        const h = hashMap[tabId] || tabId.replace('tab', '').toLowerCase();
        try { history.replaceState(null, '', '#' + h); } catch(e) {}
      }

      // Sync active state in sidebar dropdown
      const activeHash = window.location.hash.toLowerCase().replace('#', '');
      document.querySelectorAll('#dropTracking .dropdown-item').forEach(item => {
        const href = item.getAttribute('href') || '';
        const itemHash = (href.split('#')[1] || '').toLowerCase();
        if (itemHash) {
          item.classList.toggle('active', itemHash === activeHash || (activeHash === 'eventroster' && itemHash === 'records') || (activeHash === 'roster' && itemHash === 'records'));
        } else if (!activeHash || activeHash === 'ledger' || activeHash === 'all' || activeHash === 'monitor') {
          item.classList.toggle('active', href.includes('tracking_attendance_list.php') && !href.includes('#'));
        }
      });
    }

    // Modal helpers
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    function openManualOverrideModal() { openModal('manualOverrideModal'); }

    // Terminate Session Modal
    function openTerminateModal(sessionId, eventTitle) {
      document.getElementById('termSessionId').value = sessionId;
      document.getElementById('termEventTitle').value = eventTitle;
      document.getElementById('termReasonInput').value = '';
      document.getElementById('terminateModal').style.display = 'flex';
    }
    function closeTerminateModal() {
      document.getElementById('terminateModal').style.display = 'none';
    }

    function submitTerminateSession() {
      const sessId = document.getElementById('termSessionId').value;
      const reason = document.getElementById('termReasonInput').value.trim();
      if (!sessId || !reason) return;

      const fd = new FormData();
      fd.append('action', 'terminate_qr_session');
      fd.append('session_id', sessId);
      fd.append('reason', reason);

      fetch('../shared/attendance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            closeTerminateModal();
            alert('Attendance session terminated immediately.');
            window.location.reload();
          } else {
            alert(data.message || 'Failed to terminate session.');
          }
        });
    }

    function handleManualOverride(e) {
      e.preventDefault();
      const form = e.target;
      const fd = new FormData(form);
      fd.append('action', 'log_manual');

      fetch('../shared/attendance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            alert(data.message || 'Override applied successfully.');
            closeModal('manualOverrideModal');
            window.location.reload();
          } else {
            alert(data.message || 'Override failed.');
          }
        });
    }

    // Filter Scan Attempts
    function filterAttemptsTable() {
      const resFilter = document.getElementById('attemptResultFilter')?.value || '';
      document.querySelectorAll('#scanAttemptsTable .attempt-data-row').forEach(tr => {
        const r = tr.getAttribute('data-result') || '';
        tr.style.display = (!resFilter || r === resFilter) ? '' : 'none';
      });
    }

    // Filter SSC Events Table
    function filterSscEventsTable() {
      const q = (document.getElementById('sscEventSearch')?.value || '').toLowerCase();
      const st = document.getElementById('sscStatusFilter')?.value || '';
      document.querySelectorAll('#sscEventsMonitorTable .ssc-event-row').forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const live = tr.getAttribute('data-live') || '';
        const matchQ = !q || text.includes(q);
        const matchSt = !st || (live === st);
        tr.style.display = (matchQ && matchSt) ? '' : 'none';
      });
    }

    // Filter Attendance Ledger Table (with Event, Status, Method, and Search filters)
    // Organization & Program Context for Reports
    const rptOrgName = <?= json_encode($adviser_club_name ?: (($is_elevated_admin) ? 'Campus-Wide Event Administration' : 'Assigned Student Organization')) ?>;
    const rptProgName = <?= json_encode($adviser_program_code ?: (($is_elevated_admin) ? 'All Programs' : 'Assigned Program')) ?>;

    // Filter Admin Attendance Ledger
    function filterAdminAttendance() {
      const q = (document.getElementById('adminAttendanceSearch')?.value || '').toLowerCase().trim();
      const st = (document.getElementById('adminStatusFilter')?.value || '').toLowerCase();
      const evId = document.getElementById('adminEventFilter')?.value || 'all';
      const method = (document.getElementById('adminMethodFilter')?.value || '').toLowerCase();

      let visibleCount = 0;
      const rows = document.querySelectorAll('#adminAttendanceTable .admin-att-row');

      rows.forEach(tr => {
        const rowSearch = tr.getAttribute('data-search') || '';
        const rowStatus = tr.getAttribute('data-status') || '';
        const rowMethod = tr.getAttribute('data-method') || '';
        const rowEvId = tr.getAttribute('data-event-id') || '';

        const matchQ = !q || rowSearch.includes(q);
        const matchSt = (st === 'all') || rowStatus.includes(st);
        const matchEv = (evId === 'all') || (rowEvId === evId);
        const matchMethod = (method === 'all') || rowMethod.includes(method);

        if (matchQ && matchSt && matchEv && matchMethod) {
          tr.style.display = '';
          visibleCount++;
        } else {
          tr.style.display = 'none';
        }
      });

      const badge = document.getElementById('ledgerCountBadge');
      if (badge) badge.textContent = `${visibleCount} Records`;

      const disp = document.getElementById('visibleCountDisplay');
      if (disp) disp.textContent = visibleCount;

      const emptyRow = document.getElementById('emptyLedgerRow');
      if (emptyRow) {
        emptyRow.style.display = (visibleCount === 0) ? '' : 'none';
      }
    }

    // Reset Filters
    function resetAdminFilters() {
      const sInput = document.getElementById('adminAttendanceSearch');
      if (sInput) sInput.value = '';
      const evSel = document.getElementById('adminEventFilter');
      if (evSel) evSel.value = 'all';
      const stSel = document.getElementById('adminStatusFilter');
      if (stSel) stSel.value = 'all';
      const mSel = document.getElementById('adminMethodFilter');
      if (mSel) mSel.value = 'all';
      filterAdminAttendance();
    }

    // Extract Visible Records from Ledger Table
    function getVisibleLedgerData() {
      const rows = document.querySelectorAll('#adminAttendanceTable .admin-att-row');
      const data = [];
      rows.forEach((tr) => {
        if (tr.style.display === 'none') return;
        const tds = tr.querySelectorAll('td');
        if (tds.length < 6) return;

        const timeText = tds[0].innerText.replace(/\s+/g, ' ').trim();
        const studentEl = tds[1];
        const studentName = studentEl.querySelector('strong')?.innerText?.trim() || '';
        const studentSub = studentEl.querySelector('div')?.innerText?.trim() || '';
        const eventEl = tds[2];
        const eventTitle = eventEl.querySelector('strong')?.innerText?.trim() || '';
        const eventOrg = eventEl.querySelector('div')?.innerText?.trim() || '';
        const methodText = tds[3].innerText.replace(/\s+/g, ' ').trim();
        const recordedBy = tds[4].innerText.replace(/\s+/g, ' ').trim();
        const statusText = tds[5].innerText.replace(/\s+/g, ' ').trim();

        data.push({
          num: data.length + 1,
          time: timeText,
          studentName: studentName,
          studentInfo: studentSub,
          eventTitle: eventTitle,
          organization: eventOrg,
          method: methodText,
          recordedBy: recordedBy,
          status: statusText
        });
      });
      return data;
    }

    // ── MULTI-FORMAT EXPORT: LEDGER PDF (Scoped by Organization / Program) ──
    function exportLedgerPDF() {
      const data = getVisibleLedgerData();
      if (!data.length) {
        alert('No attendance records available to export.');
        return;
      }

      const { jsPDF } = window.jspdf || {};
      if (!jsPDF) {
        alert('PDF generator is initializing. Please try again in a few seconds.');
        return;
      }

      const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'landscape' });
      const pageWidth = doc.internal.pageSize.getWidth();

      // BCP Brand Banner
      doc.setFillColor(30, 58, 138); // #1e3a8a
      doc.rect(0, 0, pageWidth, 22, 'F');

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(14);
      doc.setTextColor(255, 255, 255);
      doc.text('BESTLINK COLLEGE OF THE PHILIPPINES', 14, 10);

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8.5);
      doc.setTextColor(226, 232, 240);
      doc.text('Office of Student Affairs & Co-Curricular Management System — Official Attendance Report', 14, 16);

      // Report Subheader with Org & Program Scope
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(11);
      doc.setTextColor(15, 23, 42);
      doc.text(`OFFICIAL ATTENDANCE REPORT — ${rptOrgName.toUpperCase()}`, 14, 29);

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8);
      doc.setTextColor(100, 116, 139);
      const nowStr = new Date().toLocaleString();
      doc.text(`Organization: ${rptOrgName}  |  Academic Program: ${rptProgName}  |  Generated: ${nowStr}  |  Total Verified Records: ${data.length}`, 14, 34);

      const tableHeaders = [['#', 'Timestamp', 'Student Name', 'ID & Course', 'Campus Event', 'Organization', 'Method', 'Recorded By', 'Status']];
      const tableRows = data.map(d => [
        d.num,
        d.time,
        d.studentName,
        d.studentInfo,
        d.eventTitle,
        d.organization,
        d.method,
        d.recordedBy,
        d.status
      ]);

      doc.autoTable({
        head: tableHeaders,
        body: tableRows,
        startY: 37,
        theme: 'striped',
        styles: {
          fontSize: 8,
          cellPadding: 2.5,
          overflow: 'linebreak',
          valign: 'middle'
        },
        headStyles: {
          fillColor: [30, 58, 138],
          textColor: [255, 255, 255],
          fontStyle: 'bold'
        },
        alternateRowStyles: {
          fillColor: [248, 250, 252]
        },
        didDrawPage: function(pData) {
          doc.setFontSize(8);
          doc.setTextColor(148, 163, 184);
          doc.text(`Page ${pData.pageNumber}`, pageWidth - 20, doc.internal.pageSize.getHeight() - 8);
          doc.text(`BCP Attendance Audit — ${rptOrgName} (${rptProgName}) [Confidential]`, 14, doc.internal.pageSize.getHeight() - 8);
        }
      });

      const cleanOrg = rptOrgName.replace(/[^a-zA-Z0-9]/g, '_').slice(0, 20);
      const ts = new Date().toISOString().slice(0, 10);
      doc.save(`BCP_Attendance_Report_${cleanOrg}_${ts}.pdf`);
    }

    // ── MULTI-FORMAT EXPORT: LEDGER EXCEL (Scoped) ─────────────
    function exportLedgerExcel() {
      const data = getVisibleLedgerData();
      if (!data.length) {
        alert('No attendance records available to export.');
        return;
      }

      let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
      html += '<head><meta charset="UTF-8">';
      html += '<style>';
      html += 'table { border-collapse: collapse; width: 100%; font-family: Calibri, Arial, sans-serif; font-size: 11pt; }';
      html += 'th { background-color: #1e3a8a; color: #ffffff; font-weight: bold; border: 1px solid #94a3b8; padding: 8px; text-align: left; }';
      html += 'td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: middle; }';
      html += 'tr:nth-child(even) { background-color: #f8fafc; }';
      html += '.title { font-size: 16pt; font-weight: bold; color: #1e3a8a; }';
      html += '.meta { font-size: 10pt; color: #64748b; margin-bottom: 6px; }';
      html += '</style></head><body>';
      html += '<div class="title">BESTLINK COLLEGE OF THE PHILIPPINES</div>';
      html += '<div class="meta"><b>Official Attendance Report &bull; ' + rptOrgName + ' (' + rptProgName + ')</b></div>';
      html += '<div class="meta">Exported on: ' + new Date().toLocaleString() + ' &bull; Total Verified Records: ' + data.length + '</div>';
      html += '<table>';
      html += '<thead><tr>';
      html += '<th>#</th><th>Timestamp</th><th>Student Name</th><th>Student ID / Course</th><th>Campus Event</th><th>Organization</th><th>Method</th><th>Recorded By</th><th>Status</th>';
      html += '</tr></thead><tbody>';

      data.forEach(d => {
        html += '<tr>';
        html += '<td>' + d.num + '</td>';
        html += '<td>' + d.time + '</td>';
        html += '<td><b>' + d.studentName + '</b></td>';
        html += '<td>' + d.studentInfo + '</td>';
        html += '<td>' + d.eventTitle + '</td>';
        html += '<td>' + d.organization + '</td>';
        html += '<td>' + d.method + '</td>';
        html += '<td>' + d.recordedBy + '</td>';
        html += '<td>' + d.status + '</td>';
        html += '</tr>';
      });

      html += '</tbody></table></body></html>';

      const cleanOrg = rptOrgName.replace(/[^a-zA-Z0-9]/g, '_').slice(0, 20);
      const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `BCP_Attendance_Report_${cleanOrg}_${new Date().toISOString().slice(0, 10)}.xls`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    // ── MULTI-FORMAT EXPORT: LEDGER CSV (Scoped) ───────────────
    function exportLedgerCSV() {
      const data = getVisibleLedgerData();
      if (!data.length) {
        alert('No attendance records available to export.');
        return;
      }

      let csv = [];
      csv.push(['#', 'Timestamp', 'Student Name', 'ID & Course', 'Campus Event', 'Organization', 'Method', 'Recorded By', 'Status'].map(c => `"${c}"`).join(','));

      data.forEach(d => {
        csv.push([
          d.num,
          `"${String(d.time).replace(/"/g, '""')}"`,
          `"${String(d.studentName).replace(/"/g, '""')}"`,
          `"${String(d.studentInfo).replace(/"/g, '""')}"`,
          `"${String(d.eventTitle).replace(/"/g, '""')}"`,
          `"${String(d.organization).replace(/"/g, '""')}"`,
          `"${String(d.method).replace(/"/g, '""')}"`,
          `"${String(d.recordedBy).replace(/"/g, '""')}"`,
          `"${String(d.status).replace(/"/g, '""')}"`
        ].join(','));
      });

      const cleanOrg = rptOrgName.replace(/[^a-zA-Z0-9]/g, '_').slice(0, 20);
      const csvContent = '\uFEFF' + csv.join('\r\n');
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `BCP_Attendance_Report_${cleanOrg}_${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    // Print Ledger
    function printLedger() {
      window.print();
    }

    // Load Event Attendees for Tab 4
    function loadEventAttendees(eventId) {
      if (!eventId) return;
      const tbody = document.getElementById('eventAttendeeTableBody');
      const pillNum = document.getElementById('attendeeCountNum');
      if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px;">Loading attendees...</td></tr>';

      const sel = document.getElementById('eventFilterSelect');
      window.currentLoadedEventTitle = sel ? (sel.options[sel.selectedIndex]?.text || 'Event') : 'Event';

      fetch(`../shared/attendance_actions.php?action=list_event&event_id=${eventId}`)
        .then(r => r.json())
        .then(data => {
          if (data.success && tbody) {
            window.currentLoadedAttendees = data.attendees || [];
            if (pillNum) pillNum.textContent = data.count || 0;
            if (data.count === 0) {
              tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:35px; color:#94a3b8;">No check-ins recorded for this event.</td></tr>';
            } else {
              let html = '';
              data.attendees.forEach((a, idx) => {
                html += `
                  <tr>
                    <td>${idx + 1}</td>
                    <td><strong>${a.first_name} ${a.last_name}</strong></td>
                    <td>${a.student_number || a.email}</td>
                    <td>${new Date(a.check_in).toLocaleString()}</td>
                    <td><span style="padding:2px 8px; border-radius:4px; font-size:0.75rem; background:#f1f5f9;">${a.method || 'QR'}</span></td>
                    <td><span style="padding:3px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#dcfce7; color:#15803d;">✓ Verified Present</span></td>
                  </tr>
                `;
              });
              tbody.innerHTML = html;
            }
          }
        });
    }

    // ── TAB 4 EXPORT: EVENT ROSTER PDF ─────────────────────────
    function exportEventRosterPDF() {
      const attendees = window.currentLoadedAttendees || [];
      const title = window.currentLoadedEventTitle || 'Event Attendance Roster';
      if (!attendees.length) {
        alert('No attendees found for this event to export.');
        return;
      }

      const { jsPDF } = window.jspdf || {};
      if (!jsPDF) {
        alert('PDF generator is initializing. Please try again.');
        return;
      }

      const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
      const pageWidth = doc.internal.pageSize.getWidth();

      doc.setFillColor(30, 58, 138);
      doc.rect(0, 0, pageWidth, 20, 'F');

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(13);
      doc.setTextColor(255, 255, 255);
      doc.text('BESTLINK COLLEGE OF THE PHILIPPINES', 14, 10);

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8.5);
      doc.setTextColor(226, 232, 240);
      doc.text('Event Attendee Verification Roster', 14, 16);

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(11);
      doc.setTextColor(15, 23, 42);
      doc.text(title, 14, 28);

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8);
      doc.setTextColor(100, 116, 139);
      doc.text(`Generated on: ${new Date().toLocaleString()}  |  Total Verified Attendees: ${attendees.length}`, 14, 33);

      const headers = [['#', 'Student Full Name', 'Student # / Email', 'Check-In Timestamp', 'Method', 'Status']];
      const rows = attendees.map((a, idx) => [
        idx + 1,
        `${a.first_name} ${a.last_name}`,
        a.student_number || a.email || 'N/A',
        new Date(a.check_in).toLocaleString(),
        a.method || 'QR',
        'Verified Present'
      ]);

      doc.autoTable({
        head: headers,
        body: rows,
        startY: 36,
        theme: 'striped',
        styles: { fontSize: 8.5, cellPadding: 2.5 },
        headStyles: { fillColor: [5, 150, 105], textColor: [255, 255, 255], fontStyle: 'bold' }
      });

      const clean = title.replace(/[^a-zA-Z0-9]/g, '_').slice(0, 30);
      doc.save(`Attendance_${clean}_${new Date().toISOString().slice(0, 10)}.pdf`);
    }

    // ── TAB 4 EXPORT: EVENT ROSTER EXCEL ───────────────────────
    function exportEventRosterExcel() {
      const attendees = window.currentLoadedAttendees || [];
      const title = window.currentLoadedEventTitle || 'Event Attendance Roster';
      if (!attendees.length) {
        alert('No attendees found for this event to export.');
        return;
      }

      let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
      html += '<head><meta charset="UTF-8"><style>';
      html += 'table { border-collapse: collapse; width: 100%; font-family: Calibri, Arial, sans-serif; }';
      html += 'th { background-color: #059669; color: #ffffff; font-weight: bold; border: 1px solid #cbd5e1; padding: 8px; }';
      html += 'td { border: 1px solid #cbd5e1; padding: 6px 8px; }';
      html += '</style></head><body>';
      html += '<h2>BESTLINK COLLEGE OF THE PHILIPPINES</h2>';
      html += '<h3>' + title + '</h3>';
      html += '<p>Generated: ' + new Date().toLocaleString() + ' | Total: ' + attendees.length + '</p>';
      html += '<table><thead><tr><th>#</th><th>Student Full Name</th><th>Student Number / Email</th><th>Check-in Time</th><th>Method</th><th>Status</th></tr></thead><tbody>';

      attendees.forEach((a, idx) => {
        html += `<tr><td>${idx+1}</td><td><b>${a.first_name} ${a.last_name}</b></td><td>${a.student_number || a.email}</td><td>${new Date(a.check_in).toLocaleString()}</td><td>${a.method || 'QR'}</td><td>Verified Present</td></tr>`;
      });

      html += '</tbody></table></body></html>';

      const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      const clean = title.replace(/[^a-zA-Z0-9]/g, '_').slice(0, 30);
      link.download = `Attendance_${clean}_${new Date().toISOString().slice(0, 10)}.xls`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    }

    // ── TAB 4 EXPORT: EVENT ROSTER CSV ─────────────────────────
    function exportEventRosterCSV() {
      const attendees = window.currentLoadedAttendees || [];
      const title = window.currentLoadedEventTitle || 'Event Attendance Roster';
      if (!attendees.length) {
        alert('No attendees found for this event to export.');
        return;
      }

      let csv = [];
      csv.push(['#', 'Student Full Name', 'Student Number / Email', 'Check-In Timestamp', 'Method', 'Status'].map(c => `"${c}"`).join(','));

      attendees.forEach((a, idx) => {
        csv.push([
          idx + 1,
          `"${a.first_name} ${a.last_name}"`,
          `"${a.student_number || a.email || ''}"`,
          `"${new Date(a.check_in).toLocaleString()}"`,
          `"${a.method || 'QR'}"`,
          `"Verified Present"`
        ].join(','));
      });

      const csvContent = '\uFEFF' + csv.join('\r\n');
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      const clean = title.replace(/[^a-zA-Z0-9]/g, '_').slice(0, 30);
      link.download = `Attendance_${clean}_${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    }

    function printEventRoster() {
      window.print();
    }

    function applyHashSection() {
      const hash = window.location.hash.toLowerCase().replace('#', '');
      if (hash === 'sessions') {
        switchAttTab('tabSessions', false);
      } else if (hash === 'attempts') {
        switchAttTab('tabAttempts', false);
      } else if (hash === 'roster' || hash === 'records' || hash === 'eventroster') {
        switchAttTab('tabEventRoster', false);
      } else {
        switchAttTab('tabLedger', false);
      }
    }

    // Check hash on load and hash change from sidebar
    document.addEventListener('DOMContentLoaded', () => {
      applyHashSection();

      const evSelect = document.getElementById('eventFilterSelect');
      if (evSelect && evSelect.value) {
        loadEventAttendees(evSelect.value);
      }
    });

    window.addEventListener('hashchange', applyHashSection);
  </script>
</body>

</html>
