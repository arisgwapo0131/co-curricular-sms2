<?php
// ============================================================
//  TRACKING_QR_GENERATOR.PHP  (dashboard/)
//  Co-Curricular System — Live Attendance Operator & QR Screen
//  Role-Tailored for Club Advisers, SSC Monitors, and Admins
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

// Fetch active & upcoming events authorized for this user
$active_events = [];
$grouped_active_events = [];
$adviser_clubs = [];
$adviser_club_ids = [];
if ($sess_role === 'club_adviser') {
  $adviser_clubs = get_user_assigned_clubs($conn, $user_id, $sess_role);
  $adviser_club_ids = array_map('intval', array_column($adviser_clubs, 'id'));
  if (!empty($adviser_club_ids)) {
    $in_clubs = implode(',', $adviser_club_ids);
    $ev_res = $conn->query("
      SELECT e.id, e.title, e.event_date, e.venue, e.status, e.event_type, e.target_course,
             c.name as club_name, c.code as club_code, c.program as club_program, c.category as club_category
      FROM events e
      LEFT JOIN clubs c ON c.id = e.club_id
      WHERE e.status IN ('Approved','Upcoming','Completed','Active','Ongoing')
        AND e.club_id IN ($in_clubs)
      ORDER BY e.event_date DESC
    ");
    $active_events = $ev_res ? $ev_res->fetch_all(MYSQLI_ASSOC) : [];
  }
  if (empty($active_events)) {
    $ev_res = $conn->query("
      SELECT e.id, e.title, e.event_date, e.venue, e.status, e.event_type, e.target_course,
             c.name as club_name, c.code as club_code, c.program as club_program, c.category as club_category
      FROM events e
      LEFT JOIN clubs c ON c.id = e.club_id
      WHERE e.status IN ('Approved','Upcoming','Completed','Active','Ongoing')
      ORDER BY e.event_date DESC
    ");
    if ($ev_res) {
      $active_events = $ev_res->fetch_all(MYSQLI_ASSOC);
    }
  }
} else {
  $ev_res = $conn->query("
    SELECT e.id, e.title, e.event_date, e.venue, e.status, e.event_type, e.target_course,
           c.name as club_name, c.code as club_code, c.program as club_program, c.category as club_category
    FROM events e
    LEFT JOIN clubs c ON c.id = e.club_id
    WHERE e.status IN ('Approved','Upcoming','Completed','Active','Ongoing')
    ORDER BY e.event_date DESC
  ");
  if ($ev_res) {
    $active_events = $ev_res->fetch_all(MYSQLI_ASSOC);
  }
}

// Group events by academic program
foreach ($active_events as $ev) {
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
  
  $grouped_active_events[$optGroup][] = $ev;
}

// Target event
$selected_event_id = (int)($_GET['event_id'] ?? (!empty($active_events) ? $active_events[0]['id'] : 0));
$current_event = null;
foreach ($active_events as $ev) {
  if ((int)$ev['id'] === $selected_event_id) {
    $current_event = $ev;
    break;
  }
}
if (!$current_event && !empty($active_events)) {
  $current_event = $active_events[0];
  $selected_event_id = (int)$current_event['id'];
}

// Fetch enrolled students for manual attendance modal
$students_list = [];
if ($sess_role === 'club_adviser') {
  if (!empty($adviser_club_ids)) {
    $in_clubs = implode(',', $adviser_club_ids);
    $st_res = $conn->query("
      SELECT u.id, u.first_name, u.last_name, s.student_number, s.course, s.section
      FROM users u
      JOIN club_memberships cm ON cm.user_id = u.id AND cm.status = 'Active'
      LEFT JOIN students s ON (s.user_id = u.id OR s.student_number = u.username)
      WHERE cm.club_id IN ($in_clubs)
      GROUP BY u.id
      ORDER BY u.last_name, u.first_name
    ");
    $students_list = $st_res ? $st_res->fetch_all(MYSQLI_ASSOC) : [];
  } else {
    $students_list = [];
  }
} else {
  $st_res = $conn->query("
    SELECT u.id, u.first_name, u.last_name, s.student_number, s.course, s.section
    FROM users u
    LEFT JOIN students s ON (s.user_id = u.id OR s.student_number = u.username)
    WHERE u.role = 'student'
    ORDER BY u.last_name, u.first_name
    LIMIT 200
  ");
  if ($st_res) {
    $students_list = $st_res->fetch_all(MYSQLI_ASSOC);
  }
}

// Initial counts for target event
$init_registered = 0;
$init_present = 0;
$init_late = 0;
$init_total = 0;
$init_pending = 0;
$has_active_session = false;
$active_session_data = null;

if ($selected_event_id > 0) {
  $init_registered = (int)$conn->query("SELECT COUNT(*) FROM event_registrations WHERE event_id = $selected_event_id")->fetch_row()[0];
  $init_present    = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs WHERE event_id = $selected_event_id AND status = 'Present'")->fetch_row()[0];
  $init_late       = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs WHERE event_id = $selected_event_id AND status = 'Late'")->fetch_row()[0];
  $init_total      = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs WHERE event_id = $selected_event_id")->fetch_row()[0];
  $init_pending    = max(0, $init_registered - $init_total);

  $sess_check = $conn->query("SELECT * FROM qr_sessions WHERE event_id = $selected_event_id AND status = 'active' ORDER BY id DESC LIMIT 1");
  if ($sess_check && $sess_check->num_rows > 0) {
    $has_active_session = true;
    $active_session_data = $sess_check->fetch_assoc();
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Live Attendance &amp; QR Operator – Tracking Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <script src="../js/qrcode.min.js"></script>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    /* Metric Cards Grid Consistency */
    .adviser-metrics-grid {
      display: grid !important;
      grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
      gap: 14px !important;
      margin-bottom: 22px !important;
      align-items: stretch !important;
    }
    @media (max-width: 900px) {
      .adviser-metrics-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
    }
    @media (max-width: 480px) {
      .adviser-metrics-grid {
        grid-template-columns: 1fr !important;
      }
    }

    /* Live QR Stage Layout */
    .qr-stage-layout {
      display: grid;
      grid-template-columns: 1.15fr 1fr;
      gap: 24px;
      align-items: start;
      margin-bottom: 24px;
    }
    @media (max-width: 992px) {
      .qr-stage-layout {
        grid-template-columns: 1fr;
      }
    }

    .qr-display-box {
      background: #0f172a;
      border-radius: 20px;
      padding: 32px 24px;
      text-align: center;
      color: #ffffff;
      box-shadow: 0 12px 30px rgba(15, 23, 42, 0.25);
      position: relative;
      transition: all 0.3s ease;
    }
    .qr-canvas-holder {
      background: #ffffff;
      padding: 18px;
      border-radius: 16px;
      display: inline-block;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
      margin: 16px auto;
    }
    .qr-canvas-holder img,
    .qr-canvas-holder canvas {
      display: block;
      margin: 0 auto;
    }

    .qr-countdown-tag {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(30, 41, 59, 0.85);
      border: 1px solid rgba(255, 255, 255, 0.15);
      padding: 8px 18px;
      border-radius: 999px;
      font-size: 0.9rem;
      font-weight: 700;
      color: #38bdf8;
      margin-top: 10px;
    }

    .live-status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 14px;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .live-pill-active {
      background: #dcfce7;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }
    .live-pill-active::before {
      content: '';
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #16a34a;
      box-shadow: 0 0 8px #16a34a;
      animation: pulseGreen 1.5s infinite;
    }
    @keyframes pulseGreen {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(1.3); }
    }
    .live-pill-closed {
      background: #f1f5f9;
      color: #64748b;
      border: 1px solid #e2e8f0;
    }

    /* Modal Backdrop */
    .op-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.7);
      backdrop-filter: blur(6px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 9999;
      animation: modalFadeIn 0.2s ease-out;
    }
    .op-modal-card {
      background: #ffffff;
      border-radius: 20px;
      width: 100%;
      max-width: 480px;
      padding: 28px;
      box-shadow: 0 20px 45px rgba(0,0,0,0.25);
      border: 1px solid #e2e8f0;
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'attendance';
  $ACTIVE_SUB = 'generator';
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

      <!-- Event Header with Live Badge -->
      <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px; margin-bottom:18px;">
        <div style="flex:1; min-width:280px;">
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h2 class="page-title" style="margin:0;" id="eventHeaderTitle">
              <i class="fa-solid fa-qrcode" style="color:#2563eb;"></i>
              <?= $current_event ? htmlspecialchars($current_event['title']) : 'No Active Events' ?>
            </h2>
            <span class="live-status-pill <?= $has_active_session ? 'live-pill-active' : 'live-pill-closed' ?>" id="liveStatusBadge">
              <?= $has_active_session ? 'LIVE' : 'CLOSED' ?>
            </span>
          </div>
          <div style="font-size:0.85rem; color:#64748b; margin-top:6px;" id="eventHeaderMeta">
            <?php if ($current_event): ?>
              <?= date('F j, Y', strtotime($current_event['event_date'])) ?> &bull; <?= date('g:i A', strtotime($current_event['event_date'])) ?> &bull; <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> <?= htmlspecialchars($current_event['venue'] ?? 'Campus Venue') ?> &bull; <?= htmlspecialchars($current_event['club_name'] ?? 'College') ?>
            <?php else: ?>
              Please select or create an approved event to operate attendance sessions.
            <?php endif; ?>
          </div>
        </div>

        <!-- Event Selector -->
        <div style="min-width:240px;">
          <select id="eventSwitchSelect" onchange="switchEvent(this.value)" style="height:40px; border:1px solid #cbd5e1; border-radius:10px; padding:0 14px; font-size:0.85rem; font-weight:700; color:#0f172a; background:#f8fafc; outline:none; width:100%;">
            <?php if (empty($grouped_active_events)): ?>
              <option value="">No Active Events Found</option>
            <?php else: ?>
              <?php foreach ($grouped_active_events as $progGroup => $groupEvs): ?>
                <optgroup label="<?= htmlspecialchars($progGroup) ?>">
                  <?php foreach ($groupEvs as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= ((int)$ev['id'] === $selected_event_id) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($ev['title']) ?> (<?= date('M d, Y', strtotime($ev['event_date'])) ?>)
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
      </div>

      <div class="content-body">

        <!-- 1. Attendance Summary KPI Cards (Section 7) -->
        <div class="adviser-metrics-grid">
          <div class="metric-card">
            <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;">
              <i class="fa-solid fa-users"></i>
            </div>
            <div class="metric-info">
              <div class="metric-val" id="kpiRegistered"><?= $init_registered ?></div>
              <div class="metric-lbl">Registered</div>
            </div>
          </div>

          <div class="metric-card">
            <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;">
              <i class="fa-solid fa-check"></i>
            </div>
            <div class="metric-info">
              <div class="metric-val" id="kpiPresent"><?= $init_present ?></div>
              <div class="metric-lbl">Present</div>
            </div>
          </div>

          <div class="metric-card">
            <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;">
              <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div class="metric-info">
              <div class="metric-val" id="kpiLate"><?= $init_late ?></div>
              <div class="metric-lbl">Late</div>
            </div>
          </div>

          <div class="metric-card">
            <div class="metric-icon-wrap" style="background:#fee2e2; color:#dc2626;">
              <i class="fa-solid fa-user-xmark"></i>
            </div>
            <div class="metric-info">
              <div class="metric-val" id="kpiPending"><?= $init_pending ?></div>
              <div class="metric-lbl">Pending / Absent</div>
            </div>
          </div>
        </div>

        <!-- 2. Action Toolbar -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:20px;">
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn-sys-primary" onclick="openGenerateModal()" style="display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:10px; font-weight:700; cursor:pointer;">
              <i class="fa-solid fa-qrcode"></i> <?= $has_active_session ? 'Configure Session' : 'Open Attendance / Generate QR' ?>
            </button>
            <button type="button" class="btn-sys-secondary" onclick="toggleFullScreenQR()" style="display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; font-weight:700; cursor:pointer;">
              <i class="fa-solid fa-expand"></i> Full Screen
            </button>
            <button type="button" class="btn-sys-secondary" onclick="manualRefreshToken()" style="display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; font-weight:700; cursor:pointer;">
              <i class="fa-solid fa-rotate"></i> Refresh QR
            </button>
            <?php if ($has_active_session): ?>
              <button type="button" class="btn-sys-danger" id="btnCloseSessionBtn" onclick="closeCurrentSession()" style="display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; font-weight:700; cursor:pointer;">
                <i class="fa-solid fa-power-off"></i> Close Attendance
              </button>
            <?php endif; ?>
          </div>

          <div>
            <button type="button" onclick="openManualAttendanceModal()" style="display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; font-weight:700; background:#f8fafc; border:1.5px solid #cbd5e1; color:#0f172a; cursor:pointer;">
              <i class="fa-solid fa-user-plus" style="color:#2563eb;"></i> + Manual Attendance
            </button>
          </div>
        </div>

        <!-- 3. Live QR Screen (Section 9) -->
        <div class="qr-stage-layout">

          <!-- Left: Big Live Dynamic QR Screen -->
          <div class="qr-display-box" id="qrFullscreenContainer">
            <div style="display:flex; justify-content:space-between; align-items:center; padding:0 8px; margin-bottom:8px;">
              <span style="font-size:0.78rem; font-weight:800; text-transform:uppercase; letter-spacing:0.06em; color:#94a3b8;">
                <i class="fa-solid fa-satellite-dish" style="color:#38bdf8;"></i> LIVE ATTENDANCE SCREEN
              </span>
              <span style="font-size:0.75rem; color:#64748b;">Auto-refresh token active</span>
            </div>

            <h3 style="margin:4px 0 2px 0; font-size:1.25rem; font-weight:800; color:#f8fafc;" id="fsEventTitle">
              <?= $current_event ? htmlspecialchars($current_event['title']) : 'Campus Event' ?>
            </h3>
            <div style="font-size:0.82rem; color:#94a3b8;" id="fsEventMeta">
              <?= $current_event ? date('F j, Y', strtotime($current_event['event_date'])) : '' ?>
            </div>

            <!-- Dynamic QR Canvas -->
            <div class="qr-canvas-holder" id="qrCanvasContainer">
              <div id="dynamicQROutput"></div>
            </div>

            <div style="font-size:0.95rem; font-weight:800; letter-spacing:0.04em; color:#e2e8f0; margin-top:4px;">
              SCAN TO ATTEND
            </div>

            <!-- Countdown Timer -->
            <div class="qr-countdown-tag" id="countdownTag">
              <i class="fa-solid fa-clock-rotate-left"></i>
              <span id="countdownLabel">QR refreshes in 00:60</span>
            </div>
          </div>

          <!-- Right: Mini Quick Summary & Guidelines -->
          <div class="card" style="margin-bottom:0;">
            <h3 style="margin:0 0 12px 0; font-size:1.05rem; font-weight:700; color:#0f172a;">
              <i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i> QR Attendance Protection
            </h3>
            <p style="font-size:0.84rem; color:#64748b; line-height:1.5; margin-bottom:14px;">
              Dynamic attendance tokens rotate automatically to prevent photo sharing and proxy attendance. Students must scan directly from this screen.
            </p>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:16px;">
              <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:0.84rem;">
                <span style="color:#64748b;">Session Status:</span>
                <strong id="sideSessionStatus" style="color:<?= $has_active_session ? '#16a34a' : '#64748b' ?>;">
                  <?= $has_active_session ? 'Active &bull; Accepting Scans' : 'Closed' ?>
                </strong>
              </div>
              <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:0.84rem;">
                <span style="color:#64748b;">Late Cutoff:</span>
                <strong id="sideLateCutoff">15 minutes after start</strong>
              </div>
              <div style="display:flex; justify-content:space-between; font-size:0.84rem;">
                <span style="color:#64748b;">Token Rotation:</span>
                <strong id="sideRefreshSec">Every 60 seconds</strong>
              </div>
            </div>

            <div style="display:flex; gap:10px;">
              <a href="tracking_scanner.php" target="_blank" style="flex:1; height:38px; background:#eff6ff; color:#2563eb; font-weight:700; font-size:0.82rem; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; gap:6px; text-decoration:none;">
                <i class="fa-solid fa-camera"></i> Test Student Scanner
              </a>
              <a href="reports.php" style="flex:1; height:38px; background:#f1f5f9; color:#475569; font-weight:700; font-size:0.82rem; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; gap:6px; text-decoration:none;">
                <i class="fa-solid fa-chart-column"></i> Attendance Report
              </a>
            </div>
          </div>

        </div>

        <!-- 4. Live Attendance Table (Section 10) -->
        <div class="card">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <div>
              <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#0f172a;">
                <i class="fa-solid fa-list-check" style="color:#2563eb;"></i> Live Attendance Feed
              </h3>
              <div style="font-size:0.8rem; color:#64748b; margin-top:2px;">
                Auto-updates every 6 seconds with newly verified student arrivals.
              </div>
            </div>

            <div style="display:flex; align-items:center; gap:10px;">
              <input type="text" id="liveSearchInput" placeholder="Search student name or ID..." onkeyup="filterLiveTable()" style="height:36px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.82rem; outline:none; background:#f8fafc;" />
              <select id="liveStatusFilter" onchange="filterLiveTable()" style="height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.82rem; font-weight:600; background:#f8fafc; outline:none;">
                <option value="">All Status</option>
                <option value="Present">Present</option>
                <option value="Late">Late</option>
              </select>
              <button type="button" onclick="pollLiveAttendees()" title="Force Refresh" style="width:36px; height:36px; border:1px solid #cbd5e1; border-radius:8px; background:#f8fafc; cursor:pointer; color:#475569;">
                <i class="fa-solid fa-rotate-right" id="livePollSpin"></i>
              </button>
            </div>
          </div>

          <div class="table-wrap">
            <table id="liveAttendeesTable">
              <thead>
                <tr>
                  <th style="padding:14px 18px;">Student Attendee</th>
                  <th style="padding:14px 18px;">Student Number</th>
                  <th style="padding:14px 18px;">Course &amp; Section</th>
                  <th style="padding:14px 18px;">Check-In Time</th>
                  <th style="padding:14px 18px;">Method</th>
                  <th style="padding:14px 18px;">Status</th>
                </tr>
              </thead>
              <tbody id="liveAttendeesTbody">
                <tr>
                  <td colspan="6" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:1.8rem; margin-bottom:8px; display:block; color:#cbd5e1;"></i>
                    Loading live attendees stream...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">eLearning Commons &copy; 2026</div>
  </div><!-- end main -->

  <!-- ── MODAL 1: Open / Configure Attendance Session (Section 8) ──────── -->
  <div id="generateModal" class="op-modal-backdrop" style="display:none;" onclick="if(event.target===this)closeGenerateModal()">
    <div class="op-modal-card">
      <h3 style="margin:0 0 6px 0; font-size:1.25rem; font-weight:800; color:#0f172a;">
        <i class="fa-solid fa-qrcode" style="color:#2563eb;"></i> Configure Attendance Session
      </h3>
      <p style="margin:0 0 18px 0; font-size:0.82rem; color:#64748b;">
        Define opening window, dynamic QR refresh interval, and late threshold.
      </p>

      <form id="openSessionForm" onsubmit="event.preventDefault();submitOpenSession();">
        <div style="margin-bottom:14px;">
          <label style="font-size:0.76rem; font-weight:800; text-transform:uppercase; color:#475569; display:block; margin-bottom:4px;">
            Target Event
          </label>
          <input type="text" value="<?= $current_event ? htmlspecialchars($current_event['title']) : '' ?>" readonly style="width:100%; height:40px; border:1px solid #cbd5e1; border-radius:8px; padding:0 12px; font-size:0.85rem; font-weight:700; color:#0f172a; background:#f1f5f9; box-sizing:border-box;" />
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
          <div>
            <label style="font-size:0.76rem; font-weight:800; text-transform:uppercase; color:#475569; display:block; margin-bottom:4px;">
              Late Cutoff After
            </label>
            <select id="modalLateMins" style="width:100%; height:40px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-size:0.85rem; font-weight:600; color:#0f172a; background:#f8fafc; box-sizing:border-box;">
              <option value="10">10 minutes</option>
              <option value="15" selected>15 minutes</option>
              <option value="30">30 minutes</option>
              <option value="45">45 minutes</option>
              <option value="60">60 minutes</option>
            </select>
          </div>
          <div>
            <label style="font-size:0.76rem; font-weight:800; text-transform:uppercase; color:#475569; display:block; margin-bottom:4px;">
              QR Code Validity
            </label>
            <select id="modalRefreshSec" style="width:100%; height:40px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-size:0.85rem; font-weight:600; color:#0f172a; background:#f8fafc; box-sizing:border-box;">
              <option value="30">30 seconds</option>
              <option value="45">45 seconds</option>
              <option value="60" selected>60 seconds</option>
              <option value="90">90 seconds</option>
              <option value="120">120 seconds</option>
            </select>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
          <button type="button" onclick="closeGenerateModal()" style="height:42px; padding:0 18px; background:#f1f5f9; color:#475569; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
            Cancel
          </button>
          <button type="submit" style="height:42px; padding:0 22px; background:#2563eb; color:#ffffff; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
            <i class="fa-solid fa-play"></i> Activate Attendance
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── MODAL 2: Manual Attendance with Mandatory Reason (Section 11) ── -->
  <div id="manualAttendanceModal" class="op-modal-backdrop" style="display:none;" onclick="if(event.target===this)closeManualAttendanceModal()">
    <div class="op-modal-card">
      <h3 style="margin:0 0 6px 0; font-size:1.2rem; font-weight:800; color:#0f172a;">
        <i class="fa-solid fa-user-check" style="color:#2563eb;"></i> Manual Attendance Entry
      </h3>
      <p style="margin:0 0 16px 0; font-size:0.82rem; color:#64748b;">
        Record attendance for a student whose camera is unavailable. A mandatory reason is required for audit logs.
      </p>

      <form id="manualAttForm" onsubmit="event.preventDefault();submitManualAttendance();">
        <div style="margin-bottom:12px;">
          <label style="font-size:0.76rem; font-weight:800; text-transform:uppercase; color:#475569; display:block; margin-bottom:4px;">
            Student Attendee <span style="color:#dc2626;">*</span>
          </label>
          <select id="manStudentSelect" required style="width:100%; height:40px; border:1px solid #cbd5e1; border-radius:8px; padding:0 12px; font-size:0.85rem; font-weight:600; color:#0f172a; background:#f8fafc; box-sizing:border-box;">
            <option value="">-- Choose student --</option>
            <?php foreach ($students_list as $st): ?>
              <option value="<?= $st['id'] ?>">
                <?= htmlspecialchars($st['last_name'] . ', ' . $st['first_name']) ?> (<?= htmlspecialchars($st['student_number'] ?? 'ID#' . $st['id']) ?> &bull; <?= htmlspecialchars($st['course'] ?? '') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="margin-bottom:12px;">
          <label style="font-size:0.76rem; font-weight:800; text-transform:uppercase; color:#475569; display:block; margin-bottom:4px;">
            Verification Status
          </label>
          <select id="manStatusSelect" style="width:100%; height:40px; border:1px solid #cbd5e1; border-radius:8px; padding:0 12px; font-size:0.85rem; font-weight:600; color:#0f172a; background:#f8fafc; box-sizing:border-box;">
            <option value="Present" selected>Present</option>
            <option value="Late">Late</option>
            <option value="Excused">Excused</option>
          </select>
        </div>

        <div style="margin-bottom:16px;">
          <label style="font-size:0.76rem; font-weight:800; text-transform:uppercase; color:#475569; display:block; margin-bottom:4px;">
            Override Reason <span style="color:#dc2626;">*</span>
          </label>
          <textarea id="manReasonInput" required placeholder="e.g. Student attended but QR could not be scanned due to phone camera issue." rows="3" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.84rem; font-family:inherit; color:#0f172a; background:#f8fafc; box-sizing:border-box; outline:none; resize:none;"></textarea>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" onclick="closeManualAttendanceModal()" style="height:42px; padding:0 18px; background:#f1f5f9; color:#475569; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
            Cancel
          </button>
          <button type="submit" style="height:42px; padding:0 22px; background:#16a34a; color:#ffffff; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
            <i class="fa-solid fa-floppy-disk"></i> Save Attendance
          </button>
        </div>
      </form>
    </div>
  </div>

  <script src="../js/dashboard.js"></script>
  <script>
    const currentEventId = <?= $selected_event_id ?>;
    let activeSessionId = <?= $active_session_data ? (int)$active_session_data['id'] : 0 ?>;
    let currentToken = "<?= $active_session_data ? htmlspecialchars($active_session_data['current_token']) : '' ?>";
    let refreshSeconds = <?= $active_session_data ? (int)$active_session_data['refresh_seconds'] : 60 ?>;
    let remainingSeconds = <?= $active_session_data ? max(0, strtotime($active_session_data['expires_at']) - time()) : 60 ?>;
    let countdownTimer = null;
    let livePollTimer = null;
    let qrGeneratorObj = null;

    // Initialize QR Code render
    function renderDynamicQR(payload) {
      const container = document.getElementById('dynamicQROutput');
      if (!container) return;
      container.innerHTML = '';
      qrGeneratorObj = new QRCode(container, {
        text: payload,
        width: 240,
        height: 240,
        colorDark: "#0f172a",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.M
      });
    }

    // Update countdown timer
    function startCountdown() {
      if (countdownTimer) clearInterval(countdownTimer);
      const label = document.getElementById('countdownLabel');

      countdownTimer = setInterval(() => {
        remainingSeconds--;
        if (remainingSeconds <= 0) {
          remainingSeconds = refreshSeconds;
          manualRefreshToken();
        }
        if (label) {
          const s = remainingSeconds < 10 ? '0' + remainingSeconds : remainingSeconds;
          label.textContent = `QR refreshes in 00:${s}`;
        }
      }, 1000);
    }

    // Refresh token from backend
    function manualRefreshToken() {
      if (!activeSessionId) return;
      const fd = new FormData();
      fd.append('action', 'refresh_qr_token');
      fd.append('session_id', activeSessionId);
      fd.append('event_id', currentEventId);

      fetch('../shared/attendance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            currentToken = data.current_token;
            remainingSeconds = data.refresh_seconds || refreshSeconds;
            renderDynamicQR(data.qr_payload);
          }
        });
    }

    // Open/Activate Session
    function submitOpenSession() {
      const lateMins = document.getElementById('modalLateMins').value;
      const refreshSec = document.getElementById('modalRefreshSec').value;

      const fd = new FormData();
      fd.append('action', 'open_qr_session');
      fd.append('event_id', currentEventId);
      fd.append('late_after_minutes', lateMins);
      fd.append('refresh_seconds', refreshSec);

      fetch('../shared/attendance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            activeSessionId = data.session_id;
            currentToken = data.current_token;
            refreshSeconds = data.refresh_seconds;
            remainingSeconds = data.refresh_seconds;

            closeGenerateModal();
            renderDynamicQR(data.qr_payload);
            startCountdown();

            const badge = document.getElementById('liveStatusBadge');
            if (badge) {
              badge.className = 'live-status-pill live-pill-active';
              badge.textContent = 'LIVE';
            }
            const sideStat = document.getElementById('sideSessionStatus');
            if (sideStat) {
              sideStat.textContent = 'Active • Accepting Scans';
              sideStat.style.color = '#16a34a';
            }

            pollLiveAttendees();
          } else {
            alert(data.message || 'Failed to open session.');
          }
        });
    }

    // Close Attendance Session
    function closeCurrentSession() {
      if (!confirm('Are you sure you want to close this attendance session? Students will no longer be able to scan.')) return;

      const fd = new FormData();
      fd.append('action', 'close_qr_session');
      fd.append('session_id', activeSessionId);
      fd.append('event_id', currentEventId);

      fetch('../shared/attendance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            if (countdownTimer) clearInterval(countdownTimer);
            const badge = document.getElementById('liveStatusBadge');
            if (badge) {
              badge.className = 'live-status-pill live-pill-closed';
              badge.textContent = 'CLOSED';
            }
            const sideStat = document.getElementById('sideSessionStatus');
            if (sideStat) {
              sideStat.textContent = 'Closed';
              sideStat.style.color = '#64748b';
            }
            const lbl = document.getElementById('countdownLabel');
            if (lbl) lbl.textContent = 'Session Closed';
            const closeBtn = document.getElementById('btnCloseSessionBtn');
            if (closeBtn) closeBtn.style.display = 'none';
          }
        });
    }

    // Submit Manual Attendance
    function submitManualAttendance() {
      const studentId = document.getElementById('manStudentSelect').value;
      const status = document.getElementById('manStatusSelect').value;
      const reason = document.getElementById('manReasonInput').value.trim();

      if (!studentId || !reason) {
        alert('Please select a student and provide an override reason.');
        return;
      }

      const fd = new FormData();
      fd.append('action', 'log_manual');
      fd.append('event_id', currentEventId);
      fd.append('user_id', studentId);
      fd.append('status', status);
      fd.append('override_reason', reason);

      fetch('../shared/attendance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            closeManualAttendanceModal();
            pollLiveAttendees();
            alert(data.message || 'Manual attendance saved successfully.');
          } else {
            alert(data.message || 'Failed to save attendance.');
          }
        });
    }

    // Poll live attendees from backend
    function pollLiveAttendees() {
      if (!currentEventId) return;
      const icon = document.getElementById('livePollSpin');
      if (icon) icon.classList.add('fa-spin');

      fetch(`../shared/attendance_actions.php?action=live_poll_event&event_id=${currentEventId}`)
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            // Update KPI numbers
            if (data.counts) {
              document.getElementById('kpiRegistered').textContent = data.counts.registered || 0;
              document.getElementById('kpiPresent').textContent = data.counts.present || 0;
              document.getElementById('kpiLate').textContent = data.counts.late || 0;
              document.getElementById('kpiPending').textContent = data.counts.pending || 0;
            }

            // Render rows
            const tbody = document.getElementById('liveAttendeesTbody');
            if (!tbody) return;

            if (!data.attendees || data.attendees.length === 0) {
              tbody.innerHTML = `
                <tr>
                  <td colspan="6" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                    <i class="fa-solid fa-users-slash" style="font-size:1.8rem; margin-bottom:8px; display:block; color:#cbd5e1;"></i>
                    No attendees recorded yet. Scanned check-ins will appear here live!
                  </td>
                </tr>
              `;
            } else {
              let rowsHtml = '';
              data.attendees.forEach(a => {
                const isLate = (a.status === 'Late');
                rowsHtml += `
                  <tr class="live-data-row" data-status="${a.status || 'Present'}">
                    <td data-label="Student" style="padding:14px 18px;">
                      <strong style="color:#0f172a;">${a.full_name}</strong>
                    </td>
                    <td data-label="Student No." style="padding:14px 18px; font-size:0.85rem; color:#475569;">
                      ${a.student_number || 'ID#' + a.user_id}
                    </td>
                    <td data-label="Course" style="padding:14px 18px; font-size:0.85rem; color:#475569;">
                      ${a.course || '—'} ${a.section ? '(' + a.section + ')' : ''}
                    </td>
                    <td data-label="Check-in Time" style="padding:14px 18px; font-size:0.85rem; color:#475569;">
                      ${a.time_formatted || '—'}
                    </td>
                    <td data-label="Method" style="padding:14px 18px;">
                      <span style="padding:3px 8px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#f1f5f9; color:#475569; display:inline-flex; align-items:center; gap:4px;">
                        <i class="fa-solid ${a.method === 'Manual' ? 'fa-user-pen' : 'fa-qrcode'}"></i> ${a.method || 'QR'}
                      </span>
                    </td>
                    <td data-label="Status" style="padding:14px 18px;">
                      <span style="padding:4px 12px; font-size:0.75rem; font-weight:700; border-radius:8px; display:inline-flex; align-items:center; gap:5px; background:${isLate ? '#fef3c7' : '#dcfce7'}; color:${isLate ? '#b45309' : '#15803d'};">
                        <i class="fa-solid ${isLate ? 'fa-clock-rotate-left' : 'fa-check'}"></i> ${a.status || 'Present'}
                      </span>
                    </td>
                  </tr>
                `;
              });
              tbody.innerHTML = rowsHtml;
            }
          }
        })
        .finally(() => {
          if (icon) icon.classList.remove('fa-spin');
        });
    }

    function filterLiveTable() {
      const q = (document.getElementById('liveSearchInput')?.value || '').toLowerCase();
      const st = document.getElementById('liveStatusFilter')?.value || '';
      const rows = document.querySelectorAll('.live-data-row');

      rows.forEach(r => {
        const text = r.textContent.toLowerCase();
        const rowStatus = r.getAttribute('data-status') || '';
        const matchQ = !q || text.includes(q);
        const matchSt = !st || (rowStatus === st);
        r.style.display = (matchQ && matchSt) ? '' : 'none';
      });
    }

    function toggleFullScreenQR() {
      const elem = document.getElementById('qrFullscreenContainer');
      if (!elem) return;
      if (!document.fullscreenElement) {
        elem.requestFullscreen().catch(err => {
          alert(`Error attempting to enable full-screen: ${err.message}`);
        });
      } else {
        document.exitFullscreen();
      }
    }

    function switchEvent(eventId) {
      window.location.href = `tracking_qr_generator.php?event_id=${eventId}`;
    }

    // Modal triggers
    function openGenerateModal() {
      document.getElementById('generateModal').style.display = 'flex';
    }
    function closeGenerateModal() {
      document.getElementById('generateModal').style.display = 'none';
    }
    function openManualAttendanceModal() {
      document.getElementById('manualAttendanceModal').style.display = 'flex';
    }
    function closeManualAttendanceModal() {
      document.getElementById('manualAttendanceModal').style.display = 'none';
    }

    // Startup
    document.addEventListener('DOMContentLoaded', () => {
      <?php if ($has_active_session && !empty($active_session_data)): ?>
        const initialPayload = "BCP-ATTEND-E<?= $selected_event_id ?>-S<?= $active_session_data['id'] ?>-T<?= $active_session_data['current_token'] ?>";
        renderDynamicQR(initialPayload);
        startCountdown();
      <?php else: ?>
        // Static preview QR fallback
        renderDynamicQR("BCP-EVENT-<?= $selected_event_id ?>");
        const lbl = document.getElementById('countdownLabel');
        if (lbl) lbl.textContent = 'Session Inactive &bull; Click Open Attendance';
      <?php endif; ?>

      pollLiveAttendees();

      // Auto poll every 6 seconds
      livePollTimer = setInterval(() => {
        if (!document.hidden) {
          pollLiveAttendees();
        }
      }, 6000);
    });
  </script>
</body>

</html>
