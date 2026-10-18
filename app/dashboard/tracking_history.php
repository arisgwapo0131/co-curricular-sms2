<?php
// ============================================================
//  TRACKING_HISTORY.PHP  (dashboard/)
//  Co-Curricular System — Student Event Attendance Log & History
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

// 1. Fetch verified attendance records
$my_attendance = [];
$att_res = $conn->query("
  SELECT al.*, e.title as event_title, e.event_date, e.venue, c.name as club_name, c.code as club_code
  FROM attendance_logs al
  JOIN events e ON e.id = al.event_id
  LEFT JOIN clubs c ON c.id = e.club_id
  WHERE al.user_id = $user_id
  ORDER BY al.check_in DESC
");
if ($att_res) {
  $my_attendance = $att_res->fetch_all(MYSQLI_ASSOC);
}

// 2. Fetch registered events that have concluded where the student was absent
$missed_res = $conn->query("
  SELECT 0 as id, er.event_id, er.user_id, e.event_date as check_in, 'None' as method, NULL as logged_by, NULL as override_reason, 'Absent' as status,
         e.title as event_title, e.event_date, e.venue, c.name as club_name, c.code as club_code
  FROM event_registrations er
  JOIN events e ON e.id = er.event_id
  LEFT JOIN clubs c ON c.id = e.club_id
  WHERE er.user_id = $user_id
    AND e.event_date < NOW()
    AND e.status IN ('Completed', 'Approved')
    AND er.event_id NOT IN (SELECT event_id FROM attendance_logs WHERE user_id = $user_id)
");
$missed_records = $missed_res ? $missed_res->fetch_all(MYSQLI_ASSOC) : [];

// Merge records and sort by date descending
$all_records = array_merge($my_attendance, $missed_records);
usort($all_records, function($a, $b) {
  return strtotime($b['check_in'] ?? $b['event_date']) - strtotime($a['check_in'] ?? $a['event_date']);
});

// 3. Compute KPI Counts
$present_count = 0;
$late_count = 0;
foreach ($my_attendance as $rec) {
  if (strtolower($rec['status'] ?? '') === 'late') {
    $late_count++;
  } else {
    $present_count++;
  }
}
$absent_count = count($missed_records);
$total_attended_or_missed = count($all_records);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Attendance History – Tracking Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    /* Metric Cards Grid for Attendance History */
    .history-metrics-grid {
      display: grid !important;
      grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
      gap: 14px !important;
      margin-bottom: 22px !important;
      align-items: stretch !important;
    }
    @media (max-width: 900px) {
      .history-metrics-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
    }
    @media (max-width: 480px) {
      .history-metrics-grid {
        grid-template-columns: 1fr !important;
      }
    }

    .filter-bar-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 18px;
    }
    .filter-controls-group {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      flex: 1;
    }
    .att-search-box {
      position: relative;
      min-width: 240px;
      flex: 1;
      max-width: 380px;
    }
    .att-search-box input {
      width: 100%;
      height: 38px;
      padding: 0 12px 0 34px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 0.84rem;
      font-weight: 500;
      color: #0f172a;
      outline: none;
      box-sizing: border-box;
      background: #f8fafc;
    }
    .att-search-box i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 0.82rem;
    }
    .att-filter-select {
      height: 38px;
      padding: 0 12px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 0.82rem;
      font-weight: 600;
      color: #334155;
      background: #f8fafc;
      outline: none;
      cursor: pointer;
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'attendance';
  $ACTIVE_SUB = 'history';
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

      <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
          <h2 class="page-title" style="margin:0;">
            <i class="fa-solid fa-clipboard-user" style="color:#2563eb;"></i>
            My Attendance History
          </h2>
          <div style="font-size:0.82rem; color:#64748b; margin-top:4px;">
            Personal log of all campus activity check-ins and participation status.
          </div>
        </div>
        <div>
          <a href="tracking_scanner.php" class="btn-sys-primary" style="display:inline-flex; align-items:center; gap:8px; padding:10px 18px; border-radius:10px; font-weight:700; text-decoration:none; font-size:0.86rem; box-shadow:0 4px 12px rgba(37,99,235,0.25);">
            <i class="fa-solid fa-qrcode"></i> Scan Attendance
          </a>
        </div>
      </div>

      <div class="content-body">

        <!-- 1. KPI Summary Cards (Consistent Sizes & Layout) -->
        <div class="history-metrics-grid">
          <div class="metric-card">
            <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;">
              <i class="fa-solid fa-check"></i>
            </div>
            <div class="metric-info">
              <div class="metric-val"><?= $present_count ?></div>
              <div class="metric-lbl">Present</div>
            </div>
          </div>

          <div class="metric-card">
            <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;">
              <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div class="metric-info">
              <div class="metric-val"><?= $late_count ?></div>
              <div class="metric-lbl">Late</div>
            </div>
          </div>

          <div class="metric-card">
            <div class="metric-icon-wrap" style="background:#fee2e2; color:#dc2626;">
              <i class="fa-solid fa-xmark"></i>
            </div>
            <div class="metric-info">
              <div class="metric-val"><?= $absent_count ?></div>
              <div class="metric-lbl">Absent</div>
            </div>
          </div>

          <div class="metric-card">
            <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;">
              <i class="fa-solid fa-calendar-check"></i>
            </div>
            <div class="metric-info">
              <div class="metric-val"><?= $total_attended_or_missed ?></div>
              <div class="metric-lbl">Total Events</div>
            </div>
          </div>
        </div>

        <!-- 2. Attendance History Table Card -->
        <div class="card">
          <div class="filter-bar-wrap">
            <div class="filter-controls-group">
              <div class="att-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="attSearchInput" placeholder="Search event, club, or venue..." onkeyup="filterAttendanceTable()" />
              </div>
              <select id="attStatusFilter" class="att-filter-select" onchange="filterAttendanceTable()">
                <option value="">All Status</option>
                <option value="Present">✓ Present</option>
                <option value="Late">⚠ Late</option>
                <option value="Absent">✕ Absent</option>
              </select>
              <select id="attDateFilter" class="att-filter-select" onchange="filterAttendanceTable()">
                <option value="">All Dates</option>
                <option value="recent">Recent (Last 30 Days)</option>
              </select>
            </div>
            <div style="display:none;">
              <span id="visibleRowCount"><?= count($all_records) ?></span>
            </div>
          </div>

          <div class="table-wrap">
            <table id="myAttendanceTable">
              <thead>
                <tr>
                  <th style="padding:14px 18px;">Event Title</th>
                  <th style="padding:14px 18px;">Host Club / Org</th>
                  <th style="padding:14px 18px;">Date &amp; Time</th>
                  <th style="padding:14px 18px;">Venue</th>
                  <th style="padding:14px 18px;">Check-In Method</th>
                  <th style="padding:14px 18px;">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($all_records)): ?>
                  <tr id="emptyTableRow">
                    <td colspan="6" style="text-align:center; padding:45px 15px; color:#94a3b8;">
                      <i class="fa-solid fa-calendar-xmark" style="font-size:2rem; color:#cbd5e1; margin-bottom:10px; display:block;"></i>
                      <div style="font-weight:700; font-size:0.95rem; color:#475569;">No attendance records found yet</div>
                      <div style="font-size:0.82rem; color:#94a3b8; margin-top:4px;">Scan an event QR code when attending campus activities to record check-in!</div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($all_records as $att): ?>
                    <?php
                      $st = strtolower($att['status'] ?? 'present');
                      $isLate = ($st === 'late');
                      $isAbsent = ($st === 'absent');
                      $dateRaw = $att['check_in'] ?? $att['event_date'];
                    ?>
                    <tr class="att-data-row" data-status="<?= $isAbsent ? 'Absent' : ($isLate ? 'Late' : 'Present') ?>" data-date="<?= date('Y-m-d', strtotime($dateRaw)) ?>">
                      <td data-label="Event" style="padding:14px 18px;">
                        <strong style="color:#0f172a; font-size:0.9rem;"><?= htmlspecialchars($att['event_title']) ?></strong>
                      </td>
                      <td data-label="Host Club" style="padding:14px 18px; font-size:0.85rem; color:#334155;">
                        <?= htmlspecialchars($att['club_name'] ?? 'Campus Event') ?>
                        <span style="color:#94a3b8; font-weight:600;">(<?= htmlspecialchars($att['club_code'] ?? 'BCP') ?>)</span>
                      </td>
                      <td data-label="Date" style="padding:14px 18px; font-size:0.85rem; color:#475569;">
                        <?= date('M d, Y', strtotime($dateRaw)) ?>
                        <div style="font-size:0.75rem; color:#94a3b8;"><?= date('h:i A', strtotime($dateRaw)) ?></div>
                      </td>
                      <td data-label="Venue" style="padding:14px 18px; font-size:0.85rem; color:#475569;">
                        <?= htmlspecialchars($att['venue'] ?? 'Campus Venue') ?>
                      </td>
                      <td data-label="Method" style="padding:14px 18px;">
                        <?php if ($isAbsent): ?>
                          <span style="padding:4px 10px; border-radius:6px; font-size:0.75rem; font-weight:600; background:#f1f5f9; color:#94a3b8;">
                            — Missed
                          </span>
                        <?php else: ?>
                          <span style="padding:4px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#f1f5f9; color:#475569; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid <?= in_array($att['method'], ['QR', 'QR_SELF']) ? 'fa-qrcode' : 'fa-clipboard-check' ?>"></i>
                            <?= htmlspecialchars($att['method'] ?? 'QR') ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Status" style="padding:14px 18px;">
                        <?php if ($isAbsent): ?>
                          <span style="padding:4px 12px; font-size:0.75rem; font-weight:700; border-radius:8px; background:#fee2e2; color:#dc2626; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid fa-xmark"></i> Absent
                          </span>
                        <?php elseif ($isLate): ?>
                          <span style="padding:4px 12px; font-size:0.75rem; font-weight:700; border-radius:8px; background:#fef3c7; color:#b45309; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid fa-clock-rotate-left"></i> Late
                          </span>
                        <?php else: ?>
                          <span style="padding:4px 12px; font-size:0.75rem; font-weight:700; border-radius:8px; background:#dcfce7; color:#15803d; display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid fa-check"></i> Present
                          </span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">eLearning Commons &copy; 2026</div>
  </div><!-- end main -->

  <script src="../js/dashboard.js"></script>
  <script src="../js/table-pagination.js"></script>
  <script>
    function filterAttendanceTable() {
      const q = (document.getElementById('attSearchInput')?.value || '').toLowerCase();
      const status = document.getElementById('attStatusFilter')?.value || '';
      const dateFilter = document.getElementById('attDateFilter')?.value || '';
      const rows = document.querySelectorAll('.att-data-row');

      let visible = 0;
      const now = new Date();
      const thirtyDaysAgo = new Date();
      thirtyDaysAgo.setDate(now.getDate() - 30);

      rows.forEach(r => {
        const text = r.textContent.toLowerCase();
        const rowStatus = r.getAttribute('data-status') || '';
        const rowDateStr = r.getAttribute('data-date') || '';
        const rowDate = new Date(rowDateStr);

        let matchText = !q || text.includes(q);
        let matchStatus = !status || (rowStatus === status);
        let matchDate = true;
        if (dateFilter === 'recent') {
          matchDate = (rowDate >= thirtyDaysAgo);
        }

        if (matchText && matchStatus && matchDate) {
          r.style.display = '';
          visible++;
        } else {
          r.style.display = 'none';
        }
      });

      const countSpan = document.getElementById('visibleRowCount');
      if (countSpan) countSpan.textContent = visible;
    }
  </script>
</body>

</html>
