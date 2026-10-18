<?php
// ============================================================
//  TRACKING_SCANNER.PHP  (dashboard/)
//  Co-Curricular System — Role-Adaptive QR Attendance Scanner
//  Student Mode: Self-Checkin Camera Scanner & Rich Result Modal
//  Staff Mode: On-Site Scanner Terminal for Event Badge Logging
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_auth();

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
if ($sess_role === 'student') {
    header('Location: dashboard.php');
    exit;
}
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)($_SESSION['user_id'] ?? 0);

// Determine mode: students default to student mode; staff can toggle via ?mode=student or ?mode=terminal
$req_mode = $_GET['mode'] ?? '';
$is_student_mode = ($sess_role === 'student') || ($req_mode === 'student');

// Fetch active events for staff event selection
$active_events = [];
$grouped_active_events = [];
if (!$is_student_mode || in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
  $ev_res = $conn->query("
    SELECT e.id, e.title, e.event_date, e.venue, e.event_type, e.target_course,
           c.name as club_name, c.code as club_code, c.program as club_program, c.category as club_category
    FROM events e
    LEFT JOIN clubs c ON c.id = e.club_id
    WHERE e.status IN ('Approved','Upcoming','Active','Ongoing','Completed')
    ORDER BY e.event_date DESC
  ");
  if ($ev_res) {
    $active_events = $ev_res->fetch_all(MYSQLI_ASSOC);
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
}
$target_event_id = (int)($_GET['event_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= $is_student_mode ? 'Scan Event QR Code – Attendance' : 'On-Site Scanner Terminal – Tracking Portal' ?></title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <script src="../js/html5-qrcode.min.js"></script>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    /* ── Common Scanner Styles ────────────────────────────────────────── */
    .scanner-viewport-box {
      background: #0f172a;
      border-radius: 18px;
      position: relative;
      overflow: hidden;
      aspect-ratio: 4/3;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 10px 25px rgba(0,0,0,0.25);
    }
    #html5qrReader {
      width: 100% !important;
      height: 100% !important;
      border: none !important;
    }
    #html5qrReader video {
      width: 100% !important;
      height: 100% !important;
      object-fit: cover !important;
      border-radius: 16px !important;
    }
    #html5qrReader__scan_region {
      width: 100% !important;
      height: 100% !important;
    }
    #html5qrReader__header_message {
      display: none !important;
    }
    .scanner-laser {
      position: absolute;
      top: 15%;
      left: 10%;
      right: 10%;
      height: 3px;
      background: linear-gradient(90deg, transparent, #22c55e, #38bdf8, transparent);
      box-shadow: 0 0 14px #22c55e;
      animation: laserSweep 2s ease-in-out infinite;
      z-index: 5;
      pointer-events: none;
    }
    @keyframes laserSweep {
      0%, 100% { top: 15%; }
      50% { top: 85%; }
    }

    /* Target Box Overlay */
    .scan-target-frame {
      position: absolute;
      width: 65%;
      height: 65%;
      pointer-events: none;
      z-index: 4;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .scan-corner {
      position: absolute;
      width: 24px;
      height: 24px;
      border-color: #38bdf8;
      border-style: solid;
    }
    .corner-tl { top: 0; left: 0; border-width: 4px 0 0 4px; border-top-left-radius: 8px; }
    .corner-tr { top: 0; right: 0; border-width: 4px 4px 0 0; border-top-right-radius: 8px; }
    .corner-bl { bottom: 0; left: 0; border-width: 0 0 4px 4px; border-bottom-left-radius: 8px; }
    .corner-br { bottom: 0; right: 0; border-width: 0 4px 4px 0; border-bottom-right-radius: 8px; }

    /* ── Student Centered Card UI ─────────────────────────────────────── */
    .student-scan-container {
      max-width: 540px;
      margin: 0 auto;
      padding: 10px 0 30px;
    }
    .student-scan-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 20px;
      padding: 24px 24px 28px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    }
    .student-header-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 20px;
    }
    .back-btn-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #f1f5f9;
      color: #334155;
      padding: 8px 16px;
      border-radius: 999px;
      font-size: 0.85rem;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.15s ease;
    }
    .back-btn-pill:hover {
      background: #e2e8f0;
      color: #0f172a;
    }

    /* ── Result Modal UI ──────────────────────────────────────────────── */
    .result-modal-backdrop {
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
    @keyframes modalFadeIn {
      from { opacity: 0; transform: scale(0.96); }
      to { opacity: 1; transform: scale(1); }
    }
    .result-modal-card {
      background: #ffffff;
      border-radius: 22px;
      width: 100%;
      max-width: 440px;
      overflow: hidden;
      box-shadow: 0 20px 45px rgba(0,0,0,0.25);
      text-align: center;
      padding: 32px 28px;
      border: 1px solid #e2e8f0;
      position: relative;
    }
    .result-icon-bubble {
      width: 76px;
      height: 76px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 2.2rem;
      margin-bottom: 18px;
    }
    .result-title {
      font-size: 1.35rem;
      font-weight: 800;
      color: #0f172a;
      margin: 0 0 8px;
    }
    .result-event-name {
      font-size: 1.1rem;
      font-weight: 700;
      color: #1e3a8a;
      margin: 12px 0 6px;
    }
    .result-meta-row {
      font-size: 0.85rem;
      color: #64748b;
      margin-bottom: 16px;
    }
    .result-status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 16px;
      border-radius: 999px;
      font-size: 0.88rem;
      font-weight: 800;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      margin-bottom: 16px;
    }
    .result-subnote {
      font-size: 0.85rem;
      color: #475569;
      line-height: 1.45;
      margin-bottom: 24px;
      white-space: pre-line;
    }
    .btn-done-full {
      width: 100%;
      height: 46px;
      background: #2563eb;
      color: #ffffff;
      border: none;
      border-radius: 12px;
      font-size: 0.95rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
      transition: all 0.15s ease;
    }
    .btn-done-full:hover {
      background: #1d4ed8;
      transform: translateY(-1px);
    }

    /* ── Terminal Layout for Staff ────────────────────────────────────── */
    .scanner-layout {
      display: grid;
      grid-template-columns: 1fr 1.1fr;
      gap: 24px;
      align-items: start;
    }
    @media (max-width: 992px) {
      .scanner-layout {
        grid-template-columns: 1fr;
      }
    }
    .feed-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 14px;
      border-bottom: 1px solid #f1f5f9;
      font-size: 0.85rem;
      animation: fadeIn 0.3s ease;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'attendance';
  $ACTIVE_SUB = 'scanner';
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

      <?php if ($is_student_mode): ?>
        <!-- ===============================================================
             STUDENT SCANNER UI (Clean, Focused, Mobile-Ready)
             =============================================================== -->
        <div class="student-scan-container">

          <div class="student-header-bar">
            <a href="tracking_history.php" class="back-btn-pill" id="btnBackAttendance">
              <i class="fa-solid fa-arrow-left"></i> Attendance
            </a>
            <?php if ($sess_role !== 'student'): ?>
              <a href="tracking_scanner.php?mode=terminal" class="back-btn-pill" style="background:#eff6ff; color:#2563eb;">
                <i class="fa-solid fa-desktop"></i> Staff Terminal
              </a>
            <?php endif; ?>
          </div>

          <div class="student-scan-card">
            <div style="text-align:center; margin-bottom:18px;">
              <h2 style="margin:0 0 6px 0; font-size:1.35rem; font-weight:800; color:#0f172a;">
                <i class="fa-solid fa-qrcode" style="color:#2563eb;"></i> Scan Event QR Code
              </h2>
              <p style="margin:0; font-size:0.84rem; color:#64748b;">
                Point your camera at the attendance QR code displayed at the venue.
              </p>
            </div>

            <!-- Camera Viewport Box -->
            <div class="scanner-viewport-box" id="studentScannerBox" style="aspect-ratio: 1/1; max-width: 440px; margin: 0 auto;">
              <div id="html5qrReader"></div>
              <div class="scan-target-frame">
                <span class="scan-corner corner-tl"></span>
                <span class="scan-corner corner-tr"></span>
                <span class="scan-corner corner-bl"></span>
                <span class="scan-corner corner-br"></span>
              </div>
              <div class="scanner-laser" id="scannerLaser"></div>

              <div id="cameraStandbyBox" style="color:#94a3b8; text-align:center; padding:20px; z-index:2;">
                <i class="fa-solid fa-camera" style="font-size:3rem; margin-bottom:12px; color:#475569;"></i>
                <div style="font-weight:700; font-size:1rem; color:#e2e8f0;">Camera Ready</div>
                <div style="font-size:0.8rem; color:#94a3b8; margin:6px 0 16px;">Click below to grant camera access and start scanning.</div>
                <button type="button" onclick="startScannerEngine()" style="padding:10px 22px; background:#2563eb; color:#fff; border:none; border-radius:10px; font-weight:700; font-size:0.88rem; cursor:pointer;">
                  <i class="fa-solid fa-play"></i> Start Camera
                </button>
              </div>
            </div>

            <div style="text-align:center; margin-top:14px;">
              <span style="font-size:0.82rem; font-weight:600; color:#64748b; display:inline-flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-bullseye" style="color:#2563eb;"></i> Position the QR code inside the box
              </span>
            </div>

            <!-- Controls row: Manual Code entry & Camera switch -->
            <div style="margin-top:22px; display:flex; flex-direction:column; gap:10px;">
              <button type="button" onclick="openManualCodeModal()" class="card-btn" style="width:100%; height:44px; background:#f8fafc; border:1.5px solid #cbd5e1; color:#334155; font-weight:700; font-size:0.88rem; border-radius:12px; display:flex; align-items:center; justify-content:center; gap:8px; cursor:pointer;">
                <i class="fa-solid fa-keyboard" style="color:#64748b;"></i> Enter Code Manually
              </button>

              <div style="display:flex; justify-content:space-between; align-items:center; padding:4px 4px;">
                <button type="button" onclick="switchCameraFacing()" id="btnSwitchCam" style="background:none; border:none; color:#64748b; font-size:0.8rem; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                  <i class="fa-solid fa-camera-rotate"></i> Flip Camera
                </button>
                <button type="button" onclick="toggleScannerPause()" id="btnPauseCam" style="background:none; border:none; color:#64748b; font-size:0.8rem; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                  <i class="fa-solid fa-pause"></i> Pause Camera
                </button>
              </div>
            </div>
          </div>
        </div>

      <?php else: ?>
        <!-- ===============================================================
             STAFF SCANNER TERMINAL UI (Adviser, SSC, Admin Badge Scanner)
             =============================================================== -->
        <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
          <div>
            <h2 class="page-title" style="margin:0;">
              <i class="fa-solid fa-desktop" style="color:#2563eb;"></i>
              On-Site Attendance Scanner Terminal
            </h2>
            <div style="font-size:0.82rem; color:#64748b; margin-top:4px;">
              Log student attendee arrivals via camera QR scan or student badge barcode.
            </div>
          </div>
          <div>
            <a href="tracking_scanner.php?mode=student" class="btn-sys-secondary" style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px; border-radius:10px; font-weight:700; text-decoration:none; font-size:0.84rem;">
              <i class="fa-solid fa-camera"></i> Student Scan Mode
            </a>
          </div>
        </div>

        <div class="content-body">
          <div class="scanner-layout">

            <!-- Left: Camera Viewport & Controls -->
            <div class="card">
              <div style="margin-bottom:14px;">
                <label style="font-size:0.75rem; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:0.04em; display:block; margin-bottom:6px;">
                  Target Campus Event <span style="color:#dc2626;">*</span>
                </label>
                <select id="scannerEventSelect" style="width:100%; height:42px; border:1px solid #cbd5e1; border-radius:10px; padding:0 12px; font-size:0.86rem; font-weight:600; color:#0f172a; background:#f8fafc;" onchange="handleEventSelectionChange()">
                  <option value="">-- Choose active campus event to check in --</option>
                  <?php if (!empty($grouped_active_events)): ?>
                    <?php foreach ($grouped_active_events as $progGroup => $groupEvs): ?>
                      <optgroup label="<?= htmlspecialchars($progGroup) ?>">
                        <?php foreach ($groupEvs as $ev): ?>
                          <option value="<?= $ev['id'] ?>" <?= ($target_event_id === (int)$ev['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['title']) ?> (<?= date('M d, Y', strtotime($ev['event_date'])) ?> &bull; <?= htmlspecialchars($ev['venue'] ?? 'Campus') ?>)
                          </option>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Viewport -->
              <div class="scanner-viewport-box" id="scannerViewportBox">
                <div id="html5qrReader" style="width:100%; height:100%; display:none;"></div>
                <div class="scan-target-frame">
                  <span class="scan-corner corner-tl"></span>
                  <span class="scan-corner corner-tr"></span>
                  <span class="scan-corner corner-bl"></span>
                  <span class="scan-corner corner-br"></span>
                </div>
                <div class="scanner-laser" id="scannerLaser" style="display:none;"></div>
                <div id="cameraStandbyBox" style="color:#94a3b8; text-align:center; padding:20px;">
                  <i class="fa-solid fa-camera" style="font-size:2.6rem; margin-bottom:10px; color:#475569;"></i>
                  <div style="font-weight:700; font-size:0.95rem; color:#cbd5e1;">Scanner Standby</div>
                  <div style="font-size:0.8rem; color:#64748b; margin-top:4px;">Click button below to start live terminal camera.</div>
                </div>
              </div>

              <div id="scanResultAlert" style="display:none; margin-top:14px; padding:12px 16px; border-radius:10px; font-size:0.88rem; font-weight:700; align-items:center; gap:8px;"></div>

              <div style="margin-top:16px; display:flex; flex-direction:column; gap:10px;">
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                  <button id="toggleScannerBtn" type="button" class="card-btn" style="flex:1; min-width:180px; height:44px; background:#16a34a; color:#fff; font-size:0.92rem; font-weight:700; border-radius:10px; justify-content:center; display:flex; align-items:center; gap:8px; cursor:pointer;" onclick="toggleTerminalScanner()">
                    <i class="fa-solid fa-camera"></i> Start Terminal Scanner
                  </button>
                </div>

                <div style="display:flex; gap:8px;">
                  <div style="position:relative; flex:1;">
                    <i class="fa-solid fa-barcode" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
                    <input type="text" id="manualQrInput" placeholder="Manual code entry (e.g. BCP-STUDENT-2024-10001 or 2024-10001)" style="width:100%; height:40px; padding:0 12px 0 34px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.82rem; font-weight:500; outline:none;" onkeydown="if(event.key==='Enter'){event.preventDefault();submitManualTerminalCode();}" />
                  </div>
                  <button type="button" onclick="submitManualTerminalCode()" style="height:40px; padding:0 16px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:0.84rem; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-check"></i> Check In
                  </button>
                </div>
              </div>
            </div>

            <!-- Right: Real-time Attendance Stream -->
            <div class="card">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
                <div>
                  <h3 style="margin:0;"><i class="fa-solid fa-stream" style="color:#2563eb;"></i> Live Check-in Feed</h3>
                  <div id="streamEventSubtitle" style="font-size:0.78rem; color:#64748b; margin-top:2px;">Showing check-ins for selected event</div>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                  <span style="font-size:0.75rem; color:#64748b; font-weight:700; background:#f1f5f9; padding:4px 10px; border-radius:999px;" id="scanCountLabel">0 Check-ins</span>
                  <button type="button" onclick="refreshSelectedEventStream(true)" title="Refresh Feed" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; width:30px; height:30px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#475569;">
                    <i class="fa-solid fa-rotate-right" id="streamRefreshIcon"></i>
                  </button>
                </div>
              </div>

              <div id="scanStreamLog" style="max-height:480px; min-height:220px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:10px; background:#f8fafc;">
                <div id="emptyLogPrompt" style="text-align:center; padding:45px 20px; color:#94a3b8;">
                  <i class="fa-solid fa-calendar-xmark" style="font-size:2.2rem; margin-bottom:10px; color:#cbd5e1;"></i>
                  <p style="margin:0; font-size:0.88rem; font-weight:600; color:#475569;">Please select a target event above.</p>
                </div>
              </div>
            </div>

          </div>
        </div>
      <?php endif; ?>

    </div><!-- end content -->

    <div class="footer">eLearning Commons &copy; 2026</div>
  </div><!-- end main -->

  <!-- ── Result Modal Overlay (Section 4 Specification) ────────────────── -->
  <div id="scanResultModal" class="result-modal-backdrop" style="display:none;" onclick="if(event.target===this)closeScanResultModal()">
    <div class="result-modal-card">
      <div class="result-icon-bubble" id="resIconBubble">
        <i class="fa-solid fa-check" id="resIcon"></i>
      </div>
      <h3 class="result-title" id="resTitle">Attendance Recorded</h3>
      <div class="result-event-name" id="resEventTitle">Campus Event</div>
      <div class="result-meta-row" id="resMetaRow">September 30, 2026 &bull; 12:56 PM</div>
      <div id="resStatusWrap">
        <span class="result-status-pill" id="resStatusPill">STATUS: PRESENT</span>
      </div>
      <div class="result-subnote" id="resSubnote">✓ Successfully recorded</div>
      <button type="button" class="btn-done-full" id="btnModalAction" onclick="closeScanResultModal()">
        Done
      </button>
    </div>
  </div>

  <!-- ── Manual Code Fallback Modal ────────────────────────────────────── -->
  <div id="manualCodeModal" class="result-modal-backdrop" style="display:none;" onclick="if(event.target===this)closeManualCodeModal()">
    <div class="result-modal-card" style="text-align:left;">
      <h3 style="margin:0 0 8px 0; font-size:1.2rem; font-weight:800; color:#0f172a;">
        <i class="fa-solid fa-keyboard" style="color:#2563eb;"></i> Enter Code Manually
      </h3>
      <p style="margin:0 0 16px 0; font-size:0.82rem; color:#64748b;">
        If your camera cannot scan, paste or enter the alphanumeric QR token code provided by the organizer.
      </p>
      <div style="margin-bottom:18px;">
        <input type="text" id="studentManualInput" placeholder="e.g. BCP-ATTEND-E1-S2-T..." style="width:100%; height:44px; border:1.5px solid #cbd5e1; border-radius:10px; padding:0 14px; font-size:0.9rem; font-weight:600; outline:none; box-sizing:border-box;" onkeydown="if(event.key==='Enter'){event.preventDefault();submitStudentManualCode();}" />
      </div>
      <div style="display:flex; gap:10px;">
        <button type="button" onclick="closeManualCodeModal()" style="flex:1; height:42px; background:#f1f5f9; color:#475569; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
          Cancel
        </button>
        <button type="button" onclick="submitStudentManualCode()" style="flex:1; height:42px; background:#2563eb; color:#fff; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
          Submit Code
        </button>
      </div>
    </div>
  </div>

  <script src="../js/dashboard.js"></script>
  <script>
    let html5QrScanner = null;
    let isScanning = false;
    let isPaused = false;
    let cameraFacing = "environment"; // default back camera
    let currentCameras = [];
    let selectedCameraIndex = 0;
    let scanCooldown = false;

    // Audio synthesizer chimes
    function playBeep(success) {
      try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        if (success) {
          osc.frequency.setValueAtTime(880, ctx.currentTime);
          osc.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.12);
          gain.gain.setValueAtTime(0.3, ctx.currentTime);
          gain.gain.linearRampToValueAtTime(0.01, ctx.currentTime + 0.18);
          osc.start();
          osc.stop(ctx.currentTime + 0.18);
        } else {
          osc.frequency.setValueAtTime(360, ctx.currentTime);
          gain.gain.setValueAtTime(0.25, ctx.currentTime);
          gain.gain.linearRampToValueAtTime(0.01, ctx.currentTime + 0.22);
          osc.start();
          osc.stop(ctx.currentTime + 0.22);
        }
      } catch(e) {}
    }

    // Start scanner engine
    async function startScannerEngine() {
      const standby = document.getElementById('cameraStandbyBox');
      const reader = document.getElementById('html5qrReader');
      const laser = document.getElementById('scannerLaser');

      if (typeof Html5Qrcode === 'undefined') {
        alert('Scanner library failed to load. Please check network connection.');
        return;
      }

      try {
        if (standby) standby.style.display = 'none';
        if (reader) reader.style.display = 'block';
        if (laser) laser.style.display = 'block';

        html5QrScanner = new Html5Qrcode("html5qrReader");
        currentCameras = await Html5Qrcode.getCameras().catch(() => []);

        let camConfig = { facingMode: cameraFacing };
        if (currentCameras && currentCameras.length > 0) {
          const backCam = currentCameras.find(c => /back|rear|environment/i.test(c.label));
          camConfig = backCam ? { deviceId: { exact: backCam.id } } : { deviceId: { exact: currentCameras[0].id } };
        }

        await html5QrScanner.start(
          camConfig,
          {
            fps: 15,
            qrbox: function(w, h) {
              const minEdge = Math.min(w, h);
              return { width: Math.floor(minEdge * 0.75), height: Math.floor(minEdge * 0.75) };
            }
          },
          (decodedText) => onScanSuccess(decodedText),
          () => {} // suppress per-frame error logs
        );

        isScanning = true;
      } catch (err) {
        console.error('Camera start error:', err);
        if (standby) {
          standby.style.display = 'block';
          standby.innerHTML = `
            <i class="fa-solid fa-triangle-exclamation" style="font-size:2.5rem; color:#f59e0b; margin-bottom:10px;"></i>
            <div style="font-weight:700; color:#e2e8f0;">Camera Permission Needed</div>
            <div style="font-size:0.8rem; color:#94a3b8; margin:6px 0 14px;">Please allow camera access in your browser to scan QR codes.</div>
            <button type="button" onclick="startScannerEngine()" style="padding:8px 18px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">
              Try Again
            </button>
          `;
        }
      }
    }

    async function switchCameraFacing() {
      if (!html5QrScanner || !isScanning) {
        cameraFacing = (cameraFacing === 'environment') ? 'user' : 'environment';
        startScannerEngine();
        return;
      }
      try {
        await html5QrScanner.stop();
        cameraFacing = (cameraFacing === 'environment') ? 'user' : 'environment';
        await startScannerEngine();
      } catch(e) {}
    }

    function toggleScannerPause() {
      if (!html5QrScanner || !isScanning) return;
      const btn = document.getElementById('btnPauseCam');
      if (isPaused) {
        html5QrScanner.resume();
        isPaused = false;
        if (btn) btn.innerHTML = '<i class="fa-solid fa-pause"></i> Pause Camera';
      } else {
        html5QrScanner.pause();
        isPaused = true;
        if (btn) btn.innerHTML = '<i class="fa-solid fa-play"></i> Resume Camera';
      }
    }

    // Handle scan success with cooldown
    function onScanSuccess(decodedText) {
      if (scanCooldown) return;
      scanCooldown = true;

      // Temporary pause camera while processing
      if (html5QrScanner && isScanning) {
        try { html5QrScanner.pause(); } catch(e) {}
      }

      sendAttendanceLog(decodedText);

      setTimeout(() => {
        scanCooldown = false;
      }, 2500);
    }

    // Submit attendance scan to backend
    function sendAttendanceLog(qrData, targetEventId = 0) {
      const fd = new FormData();
      fd.append('action', 'log_qr');
      fd.append('qr_data', qrData);
      if (targetEventId > 0) {
        fd.append('event_id', targetEventId);
      } else {
        const staffSel = document.getElementById('scannerEventSelect');
        if (staffSel && staffSel.value) {
          fd.append('event_id', staffSel.value);
        }
      }

      fetch('../shared/attendance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          playBeep(data.success);
          showResultModal(data, qrData);

          // Update staff stream if present
          if (typeof refreshSelectedEventStream === 'function') {
            refreshSelectedEventStream(false);
          }
        })
        .catch(err => {
          playBeep(false);
          showResultModal({
            success: false,
            result: 'NETWORK_ERROR',
            message: 'Network connection issue. Please check your connection and try again.'
          }, qrData);
        });
    }

    // Render Result Modal with all 6 states
    function showResultModal(data, rawCode) {
      const modal = document.getElementById('scanResultModal');
      const bubble = document.getElementById('resIconBubble');
      const icon = document.getElementById('resIcon');
      const title = document.getElementById('resTitle');
      const evTitle = document.getElementById('resEventTitle');
      const metaRow = document.getElementById('resMetaRow');
      const statusWrap = document.getElementById('resStatusWrap');
      const statusPill = document.getElementById('resStatusPill');
      const subnote = document.getElementById('resSubnote');
      const btn = document.getElementById('btnModalAction');

      if (!modal) return;

      const res = (data.result || (data.success ? 'VALID' : 'FAILED')).toUpperCase();

      // Reset defaults
      statusWrap.style.display = 'block';
      evTitle.style.display = 'block';
      metaRow.style.display = 'block';
      evTitle.textContent = data.event_title || 'Campus Event';
      metaRow.textContent = `${data.event_date || 'Today'} • ${data.check_in_time || 'Just now'}`;
      btn.textContent = 'Done';
      btn.onclick = closeScanResultModal;

      switch (res) {
        case 'VALID':
        case 'PRESENT':
          bubble.style.background = '#dcfce7';
          bubble.style.color = '#16a34a';
          icon.className = 'fa-solid fa-check';
          title.textContent = '✓ Attendance Recorded';
          statusPill.style.background = '#dcfce7';
          statusPill.style.color = '#15803d';
          statusPill.textContent = 'STATUS: PRESENT';
          subnote.textContent = '✓ Successfully recorded';
          break;

        case 'LATE':
          bubble.style.background = '#fef3c7';
          bubble.style.color = '#d97706';
          icon.className = 'fa-solid fa-clock-rotate-left';
          title.textContent = '✓ Attendance Recorded';
          statusPill.style.background = '#fef3c7';
          statusPill.style.color = '#b45309';
          statusPill.textContent = 'STATUS: LATE';
          subnote.textContent = '⚠ Recorded after scheduled cutoff time';
          break;

        case 'ALREADY_SCANNED':
          bubble.style.background = '#eff6ff';
          bubble.style.color = '#2563eb';
          icon.className = 'fa-solid fa-circle-info';
          title.textContent = 'Already Recorded';
          statusPill.style.background = '#eff6ff';
          statusPill.style.color = '#1d4ed8';
          statusPill.textContent = `CHECKED IN AT ${data.check_in_time || 'EARLIER'}`;
          subnote.textContent = `Your attendance for this event was already recorded at ${data.check_in_time || 'earlier today'}.`;
          break;

        case 'EXPIRED':
          bubble.style.background = '#fee2e2';
          bubble.style.color = '#dc2626';
          icon.className = 'fa-solid fa-triangle-exclamation';
          title.textContent = 'QR Code Expired';
          statusWrap.style.display = 'none';
          metaRow.style.display = 'none';
          subnote.textContent = 'Please scan the current QR code displayed by the event organizer.';
          btn.textContent = 'Try Again';
          break;

        case 'CLOSED':
          bubble.style.background = '#f1f5f9';
          bubble.style.color = '#475569';
          icon.className = 'fa-solid fa-lock';
          title.textContent = 'Attendance Closed';
          statusWrap.style.display = 'none';
          subnote.textContent = 'This event is no longer accepting attendance scans.';
          btn.textContent = 'Back to Attendance';
          btn.onclick = () => { window.location.href = 'tracking_history.php'; };
          break;

        case 'NOT_ELIGIBLE':
        case 'UNAUTHORIZED':
          bubble.style.background = '#fee2e2';
          bubble.style.color = '#dc2626';
          icon.className = 'fa-solid fa-shield-xmark';
          title.textContent = 'Attendance Not Allowed';
          statusWrap.style.display = 'none';
          subnote.textContent = 'You are not eligible to record attendance for this event.';
          break;

        default:
          bubble.style.background = '#fee2e2';
          bubble.style.color = '#dc2626';
          icon.className = 'fa-solid fa-circle-xmark';
          title.textContent = 'Scan Unsuccessful';
          statusWrap.style.display = 'none';
          subnote.textContent = data.message || 'Invalid or unrecognized QR code format.';
          break;
      }

      modal.style.display = 'flex';
    }

    function closeScanResultModal() {
      const modal = document.getElementById('scanResultModal');
      if (modal) modal.style.display = 'none';
      if (html5QrScanner && isScanning) {
        try { html5QrScanner.resume(); } catch(e) {}
      }
    }

    // Manual Code Modal functions
    function openManualCodeModal() {
      const m = document.getElementById('manualCodeModal');
      const input = document.getElementById('studentManualInput');
      if (m) m.style.display = 'flex';
      if (input) {
        input.value = '';
        setTimeout(() => input.focus(), 100);
      }
    }

    function closeManualCodeModal() {
      const m = document.getElementById('manualCodeModal');
      if (m) m.style.display = 'none';
    }

    function submitStudentManualCode() {
      const input = document.getElementById('studentManualInput');
      const code = input ? input.value.trim() : '';
      if (!code) return;
      closeManualCodeModal();
      sendAttendanceLog(code);
    }

    // Staff Terminal Specific Functions
    function toggleTerminalScanner() {
      const btn = document.getElementById('toggleScannerBtn');
      if (isScanning) {
        if (html5QrScanner) {
          html5QrScanner.stop().then(() => {
            isScanning = false;
            document.getElementById('cameraStandbyBox').style.display = 'block';
            document.getElementById('html5qrReader').style.display = 'none';
            document.getElementById('scannerLaser').style.display = 'none';
            if (btn) btn.innerHTML = '<i class="fa-solid fa-camera"></i> Start Terminal Scanner';
          });
        }
      } else {
        startScannerEngine();
        if (btn) btn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop Terminal Scanner';
      }
    }

    function submitManualTerminalCode() {
      const input = document.getElementById('manualQrInput');
      const code = input ? input.value.trim() : '';
      if (!code) return;
      const sel = document.getElementById('scannerEventSelect');
      const evId = parseInt(sel ? sel.value : '0');
      if (evId <= 0) {
        alert('Please select a target campus event first.');
        return;
      }
      sendAttendanceLog(code, evId);
      input.value = '';
    }

    function handleEventSelectionChange() {
      const sel = document.getElementById('scannerEventSelect');
      const eventId = parseInt(sel?.value || '0');
      if (eventId > 0) {
        loadEventStream(eventId, true);
      } else {
        const stream = document.getElementById('scanStreamLog');
        if (stream) stream.innerHTML = `
          <div id="emptyLogPrompt" style="text-align:center; padding:45px 20px; color:#94a3b8;">
            <i class="fa-solid fa-calendar-xmark" style="font-size:2.2rem; margin-bottom:10px; color:#cbd5e1;"></i>
            <p style="margin:0; font-size:0.88rem; font-weight:600; color:#475569;">Please select a target event above.</p>
          </div>
        `;
        const count = document.getElementById('scanCountLabel');
        if (count) count.textContent = 'No Event Selected';
      }
    }

    function loadEventStream(eventId, showSpinner = false) {
      const log = document.getElementById('scanStreamLog');
      const countLabel = document.getElementById('scanCountLabel');
      const icon = document.getElementById('streamRefreshIcon');
      if (showSpinner && icon) icon.classList.add('fa-spin');

      fetch(`../shared/attendance_actions.php?action=list_event&event_id=${eventId}`)
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            const count = data.count || 0;
            if (countLabel) countLabel.textContent = `${count} Check-in${count === 1 ? '' : 's'}`;
            if (count === 0) {
              if (log) log.innerHTML = `
                <div style="text-align:center; padding:40px 15px; color:#94a3b8;">
                  <i class="fa-solid fa-users-slash" style="font-size:1.8rem; margin-bottom:8px; color:#cbd5e1;"></i>
                  <div style="font-weight:600; font-size:0.88rem; color:#475569;">No attendees logged yet.</div>
                  <div style="font-size:0.78rem; color:#94a3b8; margin-top:2px;">Scan student badges to log arrival.</div>
                </div>
              `;
            } else {
              let html = '';
              data.attendees.forEach(a => {
                const time = new Date(a.check_in).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                html += `
                  <div class="feed-item">
                    <div>
                      <div style="font-weight:700; color:#0f172a;">${a.first_name} ${a.last_name}</div>
                      <div style="font-size:0.75rem; color:#64748b;">${a.student_number || 'ID#' + a.user_id}</div>
                    </div>
                    <div style="text-align:right;">
                      <span style="font-size:0.75rem; font-weight:700; color:#15803d; background:#dcfce7; padding:2px 8px; border-radius:6px;">✓ Present</span>
                      <div style="font-size:0.72rem; color:#94a3b8; margin-top:2px;">${time}</div>
                    </div>
                  </div>
                `;
              });
              if (log) log.innerHTML = html;
            }
          }
        })
        .finally(() => {
          if (icon) icon.classList.remove('fa-spin');
        });
    }

    function refreshSelectedEventStream(spinner) {
      const sel = document.getElementById('scannerEventSelect');
      if (sel && sel.value) loadEventStream(parseInt(sel.value), spinner);
    }

    // Auto-start student scanner on page load for seamless mobile experience
    document.addEventListener('DOMContentLoaded', () => {
      <?php if ($is_student_mode): ?>
        // If on student view, start camera automatically after user interaction or trigger
        startScannerEngine();
      <?php else: ?>
        const sel = document.getElementById('scannerEventSelect');
        if (sel && sel.value) handleEventSelectionChange();
      <?php endif; ?>
    });
  </script>
</body>

</html>
