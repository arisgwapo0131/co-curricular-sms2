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

// Fetch all events for dropdown filters
$events = [];
$grouped_events = [];
$ev_res = $conn->query("
  SELECT e.id, e.title, e.event_date, e.venue, e.event_type, e.target_course,
         c.name as club_name, c.code as club_code, c.program as club_program, c.category as club_category
  FROM events e
  LEFT JOIN clubs c ON c.id = e.club_id
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

// ── 1. ADMIN KPI METRICS ──
$kpi_attendance_logs  = (int)($conn->query("SELECT COUNT(*) FROM attendance_logs")->fetch_row()[0] ?? 0);
$kpi_active_sessions  = (int)($conn->query("SELECT COUNT(*) FROM qr_sessions WHERE status = 'active'")->fetch_row()[0] ?? 0);
$kpi_manual_overrides = (int)($conn->query("SELECT COUNT(*) FROM attendance_logs WHERE method = 'Manual' OR override_reason IS NOT NULL")->fetch_row()[0] ?? 0);
$kpi_failed_scans     = (int)($conn->query("SELECT COUNT(*) FROM attendance_scan_attempts WHERE result IN ('ALREADY_SCANNED', 'EXPIRED', 'INVALID_TOKEN', 'UNAUTHORIZED', 'NOT_ELIGIBLE')")->fetch_row()[0] ?? 0);
$kpi_duplicate_scans  = (int)($conn->query("SELECT COUNT(*) FROM attendance_scan_attempts WHERE result = 'ALREADY_SCANNED'")->fetch_row()[0] ?? 0);

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

// ── 4. QR SESSIONS LIST (Admin & SSC - Section 16) ──
$qr_sessions = [];
$qrs_res = $conn->query("
  SELECT qs.*, e.title as event_title, e.event_date, e.venue,
         c.name as club_name, c.code as club_code,
         u.first_name as creator_first, u.last_name as creator_last,
         (SELECT COUNT(*) FROM attendance_logs al WHERE al.event_id = qs.event_id) as total_scans
  FROM qr_sessions qs
  JOIN events e ON e.id = qs.event_id
  LEFT JOIN clubs c ON c.id = e.club_id
  LEFT JOIN users u ON u.id = qs.created_by
  ORDER BY (qs.status = 'active') DESC, qs.id DESC
  LIMIT 40
");
if ($qrs_res) {
  $qr_sessions = $qrs_res->fetch_all(MYSQLI_ASSOC);
}

// ── 5. SCAN ATTEMPTS LOG (SSC & Admin - Section 14) ──
$scan_attempts = [];
$att_res = $conn->query("
  SELECT asa.*, e.title as event_title,
         u.first_name, u.last_name, u.username,
         s.student_number, s.course,
         su.first_name as scanner_first, su.last_name as scanner_last, su.role as scanner_role
  FROM attendance_scan_attempts asa
  LEFT JOIN events e ON e.id = asa.event_id
  LEFT JOIN users u ON u.id = asa.user_id
  LEFT JOIN students s ON (s.user_id = u.id OR s.student_number = u.username)
  LEFT JOIN users su ON su.id = asa.scanner_id
  ORDER BY asa.scanned_at DESC
  LIMIT 100
");
if ($att_res) {
  $scan_attempts = $att_res->fetch_all(MYSQLI_ASSOC);
}

// ── 6. ADMIN MASTER ATTENDANCE LEDGER ──
$admin_logs = [];
if ($sess_role === 'admin') {
  $admin_logs_res = $conn->query("
    SELECT 
        al.id, al.check_in, al.method, al.status, al.override_reason, al.event_id, al.user_id, al.logged_by,
        e.title AS event_title, e.event_type,
        c.code AS club_code, c.name AS club_name,
        u.first_name, u.last_name, u.email, u.username,
        s.student_number, s.course, s.year_level, s.section,
        logger.first_name AS logger_first, logger.last_name AS logger_last, logger.role AS logger_role
    FROM attendance_logs al
    LEFT JOIN events e ON e.id = al.event_id
    LEFT JOIN clubs c ON c.id = e.club_id
    LEFT JOIN users u ON u.id = al.user_id
    LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
    LEFT JOIN users logger ON logger.id = al.logged_by
    ORDER BY al.check_in DESC, al.id DESC
    LIMIT 200
  ");
  if ($admin_logs_res) {
    $admin_logs = $admin_logs_res->fetch_all(MYSQLI_ASSOC);
  }
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

    /* Tab Switcher */
    .att-tabs-nav {
      display: flex;
      align-items: center;
      gap: 6px;
      border-bottom: 1.5px solid #e2e8f0;
      margin-bottom: 20px;
      overflow-x: auto;
      white-space: nowrap;
      padding-bottom: 2px;
    }
    .att-tab-link {
      padding: 10px 18px;
      font-size: 0.85rem;
      font-weight: 700;
      color: #64748b;
      text-decoration: none;
      border-bottom: 2.5px solid transparent;
      transition: all 0.15s ease;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .att-tab-link:hover {
      color: #0f172a;
    }
    .att-tab-link.active {
      color: #2563eb;
      border-bottom-color: #2563eb;
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
             TAB 1: ATTENDANCE LEDGER / SSC ATTENDANCE MONITOR
             ════════════════════════════════════════════════════════════ -->
        <div id="tabLedger" class="att-tab-content">
          <?php if ($sess_role === 'ssc'): ?>
            <!-- SSC ATTENDANCE MONITOR TABLE (Section 12) -->
            <div class="card" style="padding:20px; border-radius:14px; border:1px solid #e2e8f0;">
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

          <?php else: ?>
            <!-- ADMIN MASTER ATTENDANCE LEDGER -->
            <div class="card" style="padding:18px 20px; border-radius:14px; border:1px solid #e2e8f0;">
              <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                <div>
                  <h3 style="margin:0; font-size:1.05rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-table-list" style="color:#2563eb;"></i> System Attendance Ledger
                  </h3>
                  <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
                    Master log of event attendance records, entry methods, recording actors, and verification statuses.
                  </div>
                </div>

                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                  <input type="text" id="adminAttendanceSearch" placeholder="Search student, event, actor..." oninput="filterAdminAttendance()" style="height:34px; padding:0 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.76rem; outline:none; background:#f8fafc;" />
                  <select id="adminStatusFilter" onchange="filterAdminAttendance()" style="height:34px; padding:0 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.76rem; font-weight:600; color:#334155; background:#fff;">
                    <option value="all">All Statuses</option>
                    <option value="present">Present</option>
                    <option value="late">Late</option>
                    <option value="excused">Excused</option>
                    <option value="valid">Valid</option>
                    <option value="manual">Manual</option>
                  </select>
                </div>
              </div>

              <div class="table-wrap">
                <table id="adminAttendanceTable" class="table-wide">
                  <thead>
                    <tr>
                      <th style="padding:12px 14px;">Timestamp</th>
                      <th style="padding:12px 14px;">Student Attendee</th>
                      <th style="padding:12px 14px;">Campus Event</th>
                      <th style="padding:12px 14px;">Method</th>
                      <th style="padding:12px 14px;">Recorded By</th>
                      <th style="padding:12px 14px;">Status</th>
                    </tr>
                  </thead>
                  <tbody id="adminAttendanceTableBody">
                    <?php if (empty($admin_logs)): ?>
                      <tr>
                        <td colspan="6" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                          No attendance records found in the database.
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($admin_logs as $log): ?>
                        <?php
                          $stLower = strtolower($log['status'] ?? 'present');
                          $isLate = ($stLower === 'late');
                          $isManual = ($log['method'] === 'Manual');
                        ?>
                        <tr class="admin-att-row" data-status="<?= htmlspecialchars($stLower) ?>" data-method="<?= htmlspecialchars(strtolower($log['method'] ?? 'qr')) ?>">
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
                            <div style="font-size:0.72rem; color:#94a3b8;"><?= htmlspecialchars($log['club_name'] ?? '') ?></div>
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
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
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
             TAB 4: EVENT ATTENDEES ROSTER (Club Adviser / Non-Admin)
             ════════════════════════════════════════════════════════════ -->
        <?php if ($sess_role !== 'admin'): ?>
        <div id="tabEventRoster" class="att-tab-content" style="display:none;">
          <div class="card" style="padding:20px; border-radius:14px; border:1px solid #e2e8f0;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <div style="display:flex; align-items:center; gap:10px;">
                <label style="font-size:0.85rem; font-weight:700; color:#0f172a;">Select Event:</label>
                <select id="eventFilterSelect" class="event-dropdown-select" onchange="loadEventAttendees(this.value)" style="height:38px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.84rem; font-weight:600;">
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
              </div>
              <div>
                <span class="stat-badge-pill" id="attendeeCountPill" style="padding:6px 14px; border-radius:8px; font-size:0.82rem; font-weight:700; background:#f1f5f9; color:#334155;">
                  <i class="fa-solid fa-check-double" style="color:#16a34a;"></i> Verified: <strong id="attendeeCountNum">0</strong>
                </span>
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
        <?php endif; ?>

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

  <script src="../js/dashboard.js"></script>
  <script src="../js/table-pagination.js"></script>
  <script>
    // Section Switcher (controlled directly from the sidebar)
    function switchAttTab(tabId, updateHash = true) {
      document.querySelectorAll('.att-tab-content').forEach(c => c.style.display = 'none');

      const target = document.getElementById(tabId);
      if (target) target.style.display = 'block';

      if (updateHash) {
        const h = tabId.replace('tab', '').toLowerCase();
        try { history.replaceState(null, '', '#' + h); } catch(e) {}
      }

      // Sync active state in sidebar dropdown
      const activeHash = window.location.hash.toLowerCase().replace('#', '');
      document.querySelectorAll('#dropTracking .dropdown-item').forEach(item => {
        const href = item.getAttribute('href') || '';
        const itemHash = (href.split('#')[1] || '').toLowerCase();
        if (itemHash) {
          item.classList.toggle('active', itemHash === activeHash || (activeHash === 'eventroster' && itemHash === 'records'));
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

    // Filter Admin Ledger Table
    function filterAdminAttendance() {
      const q = (document.getElementById('adminAttendanceSearch')?.value || '').toLowerCase();
      const st = (document.getElementById('adminStatusFilter')?.value || '').toLowerCase();

      document.querySelectorAll('#adminAttendanceTable .admin-att-row').forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const rowStatus = tr.getAttribute('data-status') || '';
        const matchQ = !q || text.includes(q);
        const matchSt = (st === 'all') || rowStatus.includes(st);
        tr.style.display = (matchQ && matchSt) ? '' : 'none';
      });
    }

    // Load Event Attendees for Tab 4
    function loadEventAttendees(eventId) {
      if (!eventId) return;
      const tbody = document.getElementById('eventAttendeeTableBody');
      const pillNum = document.getElementById('attendeeCountNum');
      if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px;">Loading attendees...</td></tr>';

      fetch(`../shared/attendance_actions.php?action=list_event&event_id=${eventId}`)
        .then(r => r.json())
        .then(data => {
          if (data.success && tbody) {
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
