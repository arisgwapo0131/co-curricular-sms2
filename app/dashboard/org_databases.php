<?php
// ============================================================
//  ORG_DATABASES.PHP  (app/dashboard/org_databases.php)
//  Dedicated Organization Database Hub & Categorized Inspector
//  Enables viewing, querying, syncing, and exporting isolated
//  per-organization MySQL databases (sms_org_*).
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/org_db_manager.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../shared/security.php';
require_auth();

$sess_role  = $_SESSION['role'] ?? 'student';
$sess_first = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last  = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic   = $_SESSION['profile_pic'] ?? null;
$user_id    = (int)$_SESSION['user_id'];
$csrf_token = csrf_token();

// The Organization Database module has been decommissioned across all system roles.
header('Location: dashboard.php');
exit;

// 1. Fetch All Accredited Clubs for Selector
$all_clubs = [];
$c_res = $conn->query("SELECT id, code, name, category, program, adviser_name, status FROM clubs WHERE deleted_at IS NULL ORDER BY code ASC");
if ($c_res) {
    while ($r = $c_res->fetch_assoc()) {
        $all_clubs[] = $r;
    }
}

// 2. Identify Adviser's Assigned Club (if applicable)
$adviser_club_id = null;
$adviser_club_code = null;
if ($sess_role === 'club_adviser') {
    $c_stmt = $conn->prepare("
        SELECT cm.club_id, c.code
        FROM club_memberships cm
        JOIN clubs c ON c.id = cm.club_id
        WHERE cm.user_id = ? AND cm.status = 'Active'
        LIMIT 1
    ");
    if ($c_stmt) {
        $c_stmt->bind_param('i', $user_id);
        $c_stmt->execute();
        $c_stmt->bind_result($adviser_club_id, $adviser_club_code);
        $c_stmt->fetch();
        $c_stmt->close();
    }
    // Fallback: match by username prefix
    if (empty($adviser_club_id)) {
        $sess_uname = $_SESSION['username'] ?? '';
        $prefix = strtoupper(explode('.', $sess_uname)[0] ?? '');
        foreach ($all_clubs as $cl) {
            $cl_clean = preg_replace('/[^a-zA-Z0-9]/', '', $cl['code']);
            $pr_clean = preg_replace('/[^a-zA-Z0-9]/', '', $prefix);
            if ($cl['code'] === $prefix || $cl_clean === $pr_clean) {
                $adviser_club_id = (int)$cl['id'];
                $adviser_club_code = $cl['code'];
                break;
            }
        }
    }
}

// 3. Resolve Selected Club
$selected_code = $_GET['club'] ?? '';
if ($sess_role === 'club_adviser') {
    // Adviser can only view their own organization database
    $selected_code = $adviser_club_code ?? '';
} else {
    // Fallback to first club if not selected or invalid for Admin/SSC
    if (empty($selected_code) && !empty($all_clubs)) {
        $selected_code = $all_clubs[0]['code'];
    }
}

$selected_club = null;
if (!empty($selected_code)) {
    foreach ($all_clubs as $cl) {
        if (strcasecmp($cl['code'], $selected_code) === 0) {
            $selected_club = $cl;
            break;
        }
    }
}

if (!$selected_club && $sess_role !== 'club_adviser' && !empty($all_clubs)) {
    $selected_club = $all_clubs[0];
    $selected_code = $selected_club['code'];
}

$dedicated_db_name = $selected_club ? get_org_db_name($selected_club['code']) : '';

// 4. Handle Actions: SQL Download, Sync, Sync All
$action = $_GET['action'] ?? '';
$flash_msg = '';
$flash_type = 'success';

if ($action === 'download_sql' && $selected_club) {
    $sql_dump = export_org_database_sql($selected_club['code']);
    $filename = "{$dedicated_db_name}_backup_" . date('Ymd_His') . ".sql";
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql_dump));
    echo $sql_dump;
    exit;
}

if ($action === 'resync' && $selected_club) {
    $ok = provision_org_database($conn, (int)$selected_club['id']);
    if ($ok) {
        sync_org_data_to_dedicated_db($conn, (int)$selected_club['id']);
        $flash_msg = "Database `{$dedicated_db_name}` successfully synchronized with master data!";
    } else {
        $flash_msg = "Failed to synchronize database `{$dedicated_db_name}`.";
        $flash_type = 'error';
    }
}

if ($action === 'sync_all' && $sess_role === 'admin') {
    $all_res = provision_all_org_databases($conn);
    $ready_count = count(array_filter($all_res, fn($o) => $o['status'] === 'Ready'));
    $flash_msg = "Provisioned & synchronized all {$ready_count} organization databases!";
}

// 5. Connect to Selected Club's Dedicated Database
$org_conn = $selected_club ? get_org_db_connection($selected_club['code']) : null;
$db_online = ($org_conn && !$org_conn->connect_error);

// 6. Query Categorized Tables
$tab = $_GET['tab'] ?? 'overview';

$members = [];
$events = [];
$attendance = [];
$budgets = [];
$achievements = [];
$announcements = [];
$audit_logs = [];
$profile = null;

$stat_members_count = 0;
$stat_events_count = 0;
$stat_attendance_count = 0;
$stat_budgets_total = 0.0;
$stat_achievements_count = 0;

if ($db_online) {
    // Org Profile
    $p_res = $org_conn->query("SELECT * FROM org_profile LIMIT 1");
    if ($p_res && $p_res->num_rows > 0) {
        $profile = $p_res->fetch_assoc();
    }

    // Org Members
    $m_res = $org_conn->query("SELECT * FROM org_members ORDER BY FIELD(role, 'President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor', 'PRO', 'Adviser') DESC, full_name ASC");
    if ($m_res) {
        $members = $m_res->fetch_all(MYSQLI_ASSOC);
        $stat_members_count = count($members);
    }

    // Org Events
    $e_res = $org_conn->query("SELECT * FROM org_events ORDER BY event_date DESC");
    if ($e_res) {
        $events = $e_res->fetch_all(MYSQLI_ASSOC);
        $stat_events_count = count($events);
    }

    // Org Attendance
    $a_res = $org_conn->query("SELECT * FROM org_attendance ORDER BY check_in DESC LIMIT 150");
    if ($a_res) {
        $attendance = $a_res->fetch_all(MYSQLI_ASSOC);
        $stat_attendance_count = count($attendance);
    }

    // Org Budgets
    $b_res = $org_conn->query("SELECT * FROM org_budgets ORDER BY created_at DESC");
    if ($b_res) {
        $budgets = $b_res->fetch_all(MYSQLI_ASSOC);
        foreach ($budgets as $b) {
            $stat_budgets_total += (float)$b['amount'];
        }
    }

    // Org Achievements
    $ach_res = $org_conn->query("SELECT * FROM org_achievements ORDER BY award_date DESC");
    if ($ach_res) {
        $achievements = $ach_res->fetch_all(MYSQLI_ASSOC);
        $stat_achievements_count = count($achievements);
    }

    // Org Announcements
    $ann_res = $org_conn->query("SELECT * FROM org_announcements ORDER BY created_at DESC");
    if ($ann_res) {
        $announcements = $ann_res->fetch_all(MYSQLI_ASSOC);
    }

    // Org Audit Trail
    $aud_res = $org_conn->query("SELECT * FROM org_audit_trail ORDER BY created_at DESC LIMIT 50");
    if ($aud_res) {
        $audit_logs = $aud_res->fetch_all(MYSQLI_ASSOC);
    }
}

$ACTIVE_NAV = 'org_databases';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="csrf-token" content="<?= $csrf_token ?>">
  <title>Organization Database Hub &bull; BCP Co-Curricular</title>
  <link rel="stylesheet" href="../css/dashboard.css">
  <link rel="stylesheet" href="../css/page-loader.css">
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <style>
    .org-db-hero {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #1e3a8a 100%);
      color: #ffffff;
      border-radius: 16px;
      padding: 24px 28px;
      margin-bottom: 24px;
      box-shadow: 0 10px 25px rgba(15, 23, 42, 0.15);
      position: relative;
      overflow: hidden;
    }
    .org-db-hero::after {
      content: '';
      position: absolute;
      right: -30px;
      bottom: -40px;
      width: 220px;
      height: 220px;
      background: radial-gradient(circle, rgba(59, 130, 246, 0.2) 0%, transparent 70%);
      border-radius: 50%;
      pointer-events: none;
    }
    .db-badge-online {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      background: rgba(34, 197, 94, 0.18);
      border: 1px solid rgba(34, 197, 94, 0.35);
      color: #4ade80;
      border-radius: 9999px;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.3px;
    }
    .db-badge-offline {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      background: rgba(239, 68, 68, 0.18);
      border: 1px solid rgba(239, 68, 68, 0.35);
      color: #f87171;
      border-radius: 9999px;
      font-size: 0.78rem;
      font-weight: 700;
    }
    .stats-bar {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 14px;
      margin-bottom: 24px;
    }
    .stat-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px 18px;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
      display: flex;
      align-items: center;
      justify-content: space-between;
      transition: all 0.2s ease;
    }
    .stat-card:hover {
      border-color: #2563eb;
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(37, 99, 235, 0.08);
    }
    .stat-num {
      font-size: 1.45rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.2;
    }
    .stat-label {
      font-size: 0.78rem;
      color: #64748b;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-top: 2px;
    }
    .stat-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.15rem;
    }

    /* Tabs Styling */
    .tabs-nav {
      display: flex;
      gap: 8px;
      border-bottom: 2px solid #e2e8f0;
      margin-bottom: 20px;
      overflow-x: auto;
      padding-bottom: 2px;
    }
    .tab-btn {
      padding: 10px 18px;
      border-radius: 8px 8px 0 0;
      font-size: 0.88rem;
      font-weight: 700;
      color: #64748b;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
      transition: all 0.2s ease;
      white-space: nowrap;
    }
    .tab-btn:hover {
      color: #1e3a8a;
      background: #f8fafc;
    }
    .tab-btn.active {
      color: #2563eb;
      border-bottom-color: #2563eb;
      background: #eff6ff;
    }
    .tab-counter {
      background: rgba(37, 99, 235, 0.12);
      color: #2563eb;
      font-size: 0.72rem;
      padding: 2px 7px;
      border-radius: 9999px;
      font-weight: 800;
    }

    /* Data Table */
    .db-table-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }
    .db-table-header {
      padding: 16px 20px;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }
    .db-table-title {
      font-size: 1.05rem;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .table-responsive {
      overflow-x: auto;
      max-height: 580px;
      overflow-y: auto;
    }
    .custom-table {
      width: 100%;
      min-width: 850px;
      border-collapse: collapse;
      font-size: 0.88rem;
    }
    .custom-table th {
      background: #f8fafc;
      position: sticky;
      top: 0;
      z-index: 2;
      color: #475569;
      font-weight: 700;
      text-transform: uppercase;
      font-size: 0.75rem;
      letter-spacing: 0.5px;
      padding: 12px 16px;
      border-bottom: 1.5px solid #e2e8f0;
      text-align: left;
      white-space: nowrap;
    }
    .custom-table td {
      padding: 13px 16px;
      border-bottom: 1px solid #f1f5f9;
      color: #1e293b;
    }
    .custom-table tr:hover td {
      background: #f8fafc;
    }

    .pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 0.76rem;
      font-weight: 700;
    }
    .pill-blue { background: #dbeafe; color: #1e40af; }
    .pill-green { background: #dcfce7; color: #166534; }
    .pill-amber { background: #fef3c7; color: #92400e; }
    .pill-purple { background: #f3e8ff; color: #6b21a8; }
    .pill-slate { background: #f1f5f9; color: #475569; }

    .btn-db {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      border-radius: 8px;
      font-size: 0.86rem;
      font-weight: 700;
      text-decoration: none;
      border: none;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn-db-primary { background: #2563eb; color: #ffffff; }
    .btn-db-primary:hover { background: #1d4ed8; }
    .btn-db-success { background: #10b981; color: #ffffff; }
    .btn-db-success:hover { background: #059669; }
    .btn-db-dark { background: #334155; color: #ffffff; }
    .btn-db-dark:hover { background: #1e293b; }
    .btn-db-secondary { background: #ffffff; color: #334155; border: 1px solid #cbd5e1; }
    .btn-db-secondary:hover { background: #f8fafc; border-color: #94a3b8; }

    .selector-box {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 7px 12px;
      font-size: 0.88rem;
      font-weight: 600;
      color: #0f172a;
      outline: none;
      cursor: pointer;
      min-width: 260px;
    }
    .selector-box:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/../shared/sidebar.php'; ?>

<div class="main">
  <!-- Topbar -->
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn" title="Toggle Sidebar"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap" id="topbarSearchWrap">
        <i class="fa-solid fa-magnifying-glass search-icon"></i>
        <input type="text" placeholder="Search tables, members, events..." autocomplete="off" />
        <button type="button" class="search-clear-btn" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <button class="topbar-chat-btn" id="chatFabBtn" title="Inter-Club Messenger" type="button"><i class="fa-solid fa-comment-dots"></i><span class="chat-unread-badge" id="chatUnreadBadge" style="display:none;"></span></button>
      <?php if (($sess_role ?? $_SESSION['role'] ?? '') === 'student'): ?>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Badge"><i class="fa-solid fa-qrcode"></i></button>
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

  <div class="content">

    <?php if (!empty($flash_msg)): ?>
      <div style="padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 700; font-size: 0.9rem; display: flex; align-items: center; justify-content: space-between; <?= $flash_type === 'error' ? 'background:#fee2e2; color:#991b1b; border:1px solid #f87171;' : 'background:#dcfce7; color:#166534; border:1px solid #86efac;' ?>">
        <span><i class="fa-solid <?= $flash_type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>" style="margin-right: 8px;"></i> <?= htmlspecialchars($flash_msg) ?></span>
        <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:inherit; font-size:1.1rem; cursor:pointer;">&times;</button>
      </div>
    <?php endif; ?>

    <!-- HERO HEADER WITH DB TELEMETRY & SELECTOR -->
    <div class="org-db-hero">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 20px;">
        <div>
          <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px; flex-wrap: wrap;">
            <span style="font-size: 1.65rem; font-weight: 800; letter-spacing: -0.5px;">
              <?= htmlspecialchars($selected_club['name'] ?? 'Organization Database') ?>
            </span>
            <span style="background: rgba(255,255,255,0.18); font-family: 'JetBrains Mono', monospace; font-size: 0.88rem; font-weight: 700; padding: 3px 10px; border-radius: 6px;">
              <?= htmlspecialchars($selected_club['code'] ?? '') ?>
            </span>
            <?php if ($db_online): ?>
              <span class="db-badge-online"><i class="fa-solid fa-circle" style="font-size: 0.55rem;"></i> Dedicated MySQL Online</span>
            <?php else: ?>
              <span class="db-badge-offline"><i class="fa-solid fa-circle" style="font-size: 0.55rem;"></i> Database Offline</span>
            <?php endif; ?>
          </div>

          <div style="font-size: 0.9rem; color: #cbd5e1; display: flex; align-items: center; gap: 18px; flex-wrap: wrap;">
            <span><i class="fa-solid fa-database text-primary" style="margin-right: 5px; color:#60a5fa;"></i> Database: <code style="color:#ffffff; font-family:'JetBrains Mono', monospace;"><?= htmlspecialchars($dedicated_db_name) ?></code></span>
            <span><i class="fa-solid fa-layer-group" style="margin-right: 5px; color:#93c5fd;"></i> Category: <strong><?= htmlspecialchars($selected_club['category'] ?? 'Academic') ?></strong></span>
            <?php if (!empty($selected_club['adviser_name'])): ?>
              <span><i class="fa-solid fa-user-tie" style="margin-right: 5px; color:#93c5fd;"></i> Adviser: <strong><?= htmlspecialchars($selected_club['adviser_name']) ?></strong></span>
            <?php endif; ?>
          </div>
        </div>

        <!-- ACTIONS & SELECTOR -->
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
          <?php if ($sess_role !== 'club_adviser'): ?>
            <!-- Organization Dropdown Selector -->
            <form method="GET" style="margin: 0; display: flex; align-items: center; gap: 8px;">
              <select name="club" class="selector-box" onchange="this.form.submit()">
                <?php foreach ($all_clubs as $cl): ?>
                  <option value="<?= htmlspecialchars($cl['code']) ?>" <?= $cl['code'] === $selected_code ? 'selected' : '' ?>>
                    [<?= htmlspecialchars($cl['code']) ?>] <?= htmlspecialchars($cl['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>

          <!-- Download SQL Dump -->
          <a href="?club=<?= urlencode($selected_code) ?>&action=download_sql" class="btn-db btn-db-success" title="Export complete SQL backup of this dedicated database">
            <i class="fa-solid fa-download"></i> Export SQL Dump
          </a>

          <!-- Resync with Master -->
          <a href="?club=<?= urlencode($selected_code) ?>&action=resync" class="btn-db btn-db-primary" onclick="event.preventDefault(); window.showConfirmModal('Synchronize Dedicated Database?', 'Do you want to synchronize this dedicated database with the master database tables? Existing schema and records will be updated.', { type: 'info', confirmText: 'Yes, Synchronize' }).then(ok => { if(ok) location.href = this.href; });" title="Push master data to dedicated database">
            <i class="fa-solid fa-arrows-rotate"></i> Sync Data
          </a>

          <?php if ($sess_role === 'admin'): ?>
            <a href="?club=<?= urlencode($selected_code) ?>&action=sync_all" class="btn-db btn-db-dark" onclick="event.preventDefault(); window.showConfirmModal('Synchronize All 42 Databases?', 'Do you want to synchronize all 42 dedicated organization databases with the master database? This operation will process in batch.', { type: 'warning', confirmText: 'Yes, Sync All 42 DBs' }).then(ok => { if(ok) location.href = this.href; });" title="Run batch synchronization across all 42 databases">
              <i class="fa-solid fa-server"></i> Sync All 42 DBs
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- METRICS STRIP -->
    <div class="stats-bar">
      <div class="stat-card">
        <div>
          <div class="stat-num"><?= number_format($stat_members_count) ?></div>
          <div class="stat-label">Active Members</div>
        </div>
        <div class="stat-icon" style="background:#eff6ff; color:#2563eb;"><i class="fa-solid fa-users"></i></div>
      </div>

      <div class="stat-card">
        <div>
          <div class="stat-num"><?= number_format($stat_events_count) ?></div>
          <div class="stat-label">Hosted Events</div>
        </div>
        <div class="stat-icon" style="background:#f0fdf4; color:#16a34a;"><i class="fa-solid fa-calendar-check"></i></div>
      </div>

      <div class="stat-card">
        <div>
          <div class="stat-num"><?= number_format($stat_attendance_count) ?></div>
          <div class="stat-label">QR Attendance</div>
        </div>
        <div class="stat-icon" style="background:#faf5ff; color:#9333ea;"><i class="fa-solid fa-qrcode"></i></div>
      </div>

      <div class="stat-card">
        <div>
          <div class="stat-num">₱<?= number_format($stat_budgets_total, 2) ?></div>
          <div class="stat-label">Total Budgets</div>
        </div>
        <div class="stat-icon" style="background:#fffbeb; color:#d97706;"><i class="fa-solid fa-wallet"></i></div>
      </div>

      <div class="stat-card">
        <div>
          <div class="stat-num"><?= number_format($stat_achievements_count) ?></div>
          <div class="stat-label">Verified Awards</div>
        </div>
        <div class="stat-icon" style="background:#fef2f2; color:#dc2626;"><i class="fa-solid fa-trophy"></i></div>
      </div>
    </div>

    <!-- CATEGORIZED TABS NAV -->
    <div class="tabs-nav">
      <a href="?club=<?= urlencode($selected_code) ?>&tab=overview" class="tab-btn <?= $tab === 'overview' ? 'active' : '' ?>">
        <i class="fa-solid fa-building-columns"></i> Profile &amp; Metadata
      </a>
      <a href="?club=<?= urlencode($selected_code) ?>&tab=members" class="tab-btn <?= $tab === 'members' ? 'active' : '' ?>">
        <i class="fa-solid fa-users"></i> Official Roster
        <span class="tab-counter"><?= count($members) ?></span>
      </a>
      <a href="?club=<?= urlencode($selected_code) ?>&tab=events" class="tab-btn <?= $tab === 'events' ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-days"></i> Events &amp; Workshops
        <span class="tab-counter"><?= count($events) ?></span>
      </a>
      <a href="?club=<?= urlencode($selected_code) ?>&tab=attendance" class="tab-btn <?= $tab === 'attendance' ? 'active' : '' ?>">
        <i class="fa-solid fa-clipboard-user"></i> Attendance Logs
        <span class="tab-counter"><?= count($attendance) ?></span>
      </a>
      <a href="?club=<?= urlencode($selected_code) ?>&tab=budgets" class="tab-btn <?= $tab === 'budgets' ? 'active' : '' ?>">
        <i class="fa-solid fa-money-check-dollar"></i> Budgets
        <span class="tab-counter"><?= count($budgets) ?></span>
      </a>
      <a href="?club=<?= urlencode($selected_code) ?>&tab=achievements" class="tab-btn <?= $tab === 'achievements' ? 'active' : '' ?>">
        <i class="fa-solid fa-award"></i> Awards &amp; Honors
        <span class="tab-counter"><?= count($achievements) ?></span>
      </a>
      <a href="?club=<?= urlencode($selected_code) ?>&tab=announcements" class="tab-btn <?= $tab === 'announcements' ? 'active' : '' ?>">
        <i class="fa-solid fa-bullhorn"></i> Announcements
        <span class="tab-counter"><?= count($announcements) ?></span>
      </a>
      <a href="?club=<?= urlencode($selected_code) ?>&tab=audit" class="tab-btn <?= $tab === 'audit' ? 'active' : '' ?>">
        <i class="fa-solid fa-clock-rotate-left"></i> Audit Trail
        <span class="tab-counter"><?= count($audit_logs) ?></span>
      </a>
    </div>

    <!-- TAB 1: OVERVIEW & PROFILE -->
    <?php if ($tab === 'overview'): ?>
      <div class="db-table-card" style="padding: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 1.15rem; color: #0f172a; display:flex; align-items:center; gap:8px;">
          <i class="fa-solid fa-id-card text-primary"></i> Categorized Table: <code>org_profile</code>
        </h3>
        <?php if ($profile): ?>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px;">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">
              <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Organization Code</span>
              <div style="font-size: 1.15rem; font-weight: 800; color: #1e3a8a; margin-top: 4px; font-family: 'JetBrains Mono', monospace;">
                <?= htmlspecialchars($profile['code']) ?>
              </div>
            </div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">
              <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Organization Full Name</span>
              <div style="font-size: 1.05rem; font-weight: 800; color: #0f172a; margin-top: 4px;">
                <?= htmlspecialchars($profile['name']) ?>
              </div>
            </div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">
              <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Accreditation Status</span>
              <div style="margin-top: 6px;">
                <span class="pill pill-green"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($profile['status'] ?? 'Active') ?></span>
              </div>
            </div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">
              <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Category &amp; Department</span>
              <div style="font-size: 0.95rem; font-weight: 700; color: #334155; margin-top: 4px;">
                <?= htmlspecialchars($profile['category']) ?> <?= !empty($profile['sub_category']) ? ' &bull; ' . htmlspecialchars($profile['sub_category']) : '' ?>
              </div>
            </div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">
              <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Assigned Faculty Adviser</span>
              <div style="font-size: 1rem; font-weight: 700; color: #0f172a; margin-top: 4px;">
                <?= htmlspecialchars($profile['adviser_name'] ?? 'None Assigned') ?>
              </div>
            </div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">
              <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Academic Degree Program</span>
              <div style="font-size: 0.95rem; font-weight: 700; color: #334155; margin-top: 4px;">
                <?= htmlspecialchars($profile['program'] ?? 'General Campus') ?>
              </div>
            </div>
          </div>
          <?php if (!empty($profile['description'])): ?>
            <div style="margin-top: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px;">
              <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Charter &amp; Purpose Statement</span>
              <p style="margin: 8px 0 0; font-size: 0.92rem; color: #334155; line-height: 1.6;">
                <?= nl2br(htmlspecialchars($profile['description'])) ?>
              </p>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div style="text-align: center; padding: 40px; color: #64748b;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size: 2.2rem; color: #f59e0b; margin-bottom: 10px;"></i>
            <p style="font-size: 1rem; font-weight: 600;">No profile data stored in dedicated database yet.</p>
            <p style="font-size: 0.85rem;">Click <strong>Sync Data</strong> above to initialize this organization's database tables.</p>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- TAB 2: ROSTER & MEMBERS -->
    <?php if ($tab === 'members'): ?>
      <div class="db-table-card">
        <div class="db-table-header">
          <div class="db-table-title">
            <i class="fa-solid fa-users text-primary"></i> Categorized Table: <code>org_members</code>
            <span class="tab-counter"><?= count($members) ?> records</span>
          </div>
          <div style="font-size: 0.82rem; color: #64748b;">
            Directly stored in <code style="font-family:'JetBrains Mono', monospace;"><?= htmlspecialchars($dedicated_db_name) ?>.org_members</code>
          </div>
        </div>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Student No.</th>
                <th>Full Name</th>
                <th>Email Address</th>
                <th>Degree Program</th>
                <th>Year &amp; Section</th>
                <th>Role in Org</th>
                <th>Status</th>
                <th>Joined Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($members)): ?>
                <tr><td colspan="8" style="text-align: center; padding: 36px; color: #64748b;">No members found in dedicated database table.</td></tr>
              <?php else: ?>
                <?php foreach ($members as $m): ?>
                  <tr>
                    <td>
                      <div style="display:flex; align-items:center; gap:10px;">
                        <?php if (!empty($m['profile_pic']) && file_exists(__DIR__ . '/../uploads/avatars/' . $m['profile_pic'])): ?>
                          <img src="../uploads/avatars/<?= htmlspecialchars($m['profile_pic']) ?>" alt="" style="width:32px; height:32px; border-radius:50%; object-fit:cover; border:1px solid #cbd5e1;"/>
                        <?php else: ?>
                          <span style="width:32px; height:32px; border-radius:50%; background:#e2e8f0; color:#475569; display:inline-flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:700;">
                            <?= strtoupper(substr($m['full_name'] ?? 'U', 0, 1)) ?>
                          </span>
                        <?php endif; ?>
                        <strong><?= htmlspecialchars($m['full_name']) ?></strong>
                      </div>
                    </td>

                    <td style="color: #334155;"><?= htmlspecialchars($m['course'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(trim(($m['year_level'] ?? '') . ' ' . ($m['section'] ?? ''))) ?: '—' ?></td>
                    <td>
                      <?php if (in_array($m['role'], ['President', 'Vice President'])): ?>
                        <span class="pill pill-blue"><i class="fa-solid fa-crown"></i> <?= htmlspecialchars($m['role']) ?></span>
                      <?php elseif (in_array($m['role'], ['Secretary', 'Treasurer', 'Auditor', 'PRO'])): ?>
                        <span class="pill pill-purple"><i class="fa-solid fa-star"></i> <?= htmlspecialchars($m['role']) ?></span>
                      <?php elseif ($m['role'] === 'Adviser'): ?>
                        <span class="pill pill-amber"><i class="fa-solid fa-user-tie"></i> Faculty Adviser</span>
                      <?php else: ?>
                        <span class="pill pill-slate"><?= htmlspecialchars($m['role']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="pill <?= ($m['status'] === 'Active') ? 'pill-green' : 'pill-slate' ?>">
                        <?= htmlspecialchars($m['status']) ?>
                      </span>
                    </td>
                    <td style="color: #64748b; font-size: 0.82rem;"><?= date('M d, Y', strtotime($m['joined_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- TAB 3: EVENTS -->
    <?php if ($tab === 'events'): ?>
      <div class="db-table-card">
        <div class="db-table-header">
          <div class="db-table-title">
            <i class="fa-solid fa-calendar-days text-primary"></i> Categorized Table: <code>org_events</code>
            <span class="tab-counter"><?= count($events) ?> records</span>
          </div>
          <div style="font-size: 0.82rem; color: #64748b;">
            Directly stored in <code style="font-family:'JetBrains Mono', monospace;"><?= htmlspecialchars($dedicated_db_name) ?>.org_events</code>
          </div>
        </div>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Event Title</th>
                <th>Schedule Date &amp; Time</th>
                <th>Venue / Location</th>
                <th>Type</th>
                <th>Lifecycle Status</th>
                <th>Created At</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($events)): ?>
                <tr><td colspan="6" style="text-align: center; padding: 36px; color: #64748b;">No events recorded in dedicated database table.</td></tr>
              <?php else: ?>
                <?php foreach ($events as $ev): ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars($ev['title']) ?></strong>
                      <?php if (!empty($ev['description'])): ?>
                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 2px; max-width: 320px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                          <?= htmlspecialchars($ev['description']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td><strong><?= date('M d, Y h:i A', strtotime($ev['event_date'])) ?></strong></td>
                    <td><?= htmlspecialchars($ev['venue']) ?></td>
                    <td><span class="pill pill-slate"><?= htmlspecialchars($ev['event_type']) ?></span></td>
                    <td>
                      <span class="pill <?= in_array($ev['status'], ['Approved', 'Completed']) ? 'pill-green' : 'pill-amber' ?>">
                        <?= htmlspecialchars($ev['status']) ?>
                      </span>
                    </td>
                    <td style="color: #64748b; font-size: 0.82rem;"><?= date('M d, Y', strtotime($ev['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- TAB 4: ATTENDANCE & QR CHECK-INS -->
    <?php if ($tab === 'attendance'): ?>
      <div class="db-table-card">
        <div class="db-table-header">
          <div class="db-table-title">
            <i class="fa-solid fa-clipboard-user text-primary"></i> Categorized Table: <code>org_attendance</code>
            <span class="tab-counter"><?= count($attendance) ?> records</span>
          </div>
          <div style="font-size: 0.82rem; color: #64748b;">
            Directly stored in <code style="font-family:'JetBrains Mono', monospace;"><?= htmlspecialchars($dedicated_db_name) ?>.org_attendance</code>
          </div>
        </div>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Event</th>
                <th>Student Number</th>
                <th>Student Name</th>
                <th>Verification Method</th>
                <th>Check-in Timestamp</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($attendance)): ?>
                <tr><td colspan="5" style="text-align: center; padding: 36px; color: #64748b;">No attendance logs recorded in dedicated database table.</td></tr>
              <?php else: ?>
                <?php foreach ($attendance as $att): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($att['event_title'] ?? 'Event #' . $att['event_id']) ?></strong></td>
                    <td><code style="font-family: 'JetBrains Mono', monospace; color: #1e3a8a;"><?= htmlspecialchars($att['student_number'] ?? '—') ?></code></td>
                    <td><strong><?= htmlspecialchars($att['student_name']) ?></strong></td>
                    <td>
                      <span class="pill <?= $att['method'] === 'QR' ? 'pill-blue' : ($att['method'] === 'QR_SELF' ? 'pill-purple' : 'pill-green') ?>">
                        <i class="fa-solid <?= $att['method'] === 'QR' || $att['method'] === 'QR_SELF' ? 'fa-qrcode' : 'fa-id-card' ?>"></i> <?= htmlspecialchars($att['method']) ?>
                      </span>
                    </td>
                    <td style="color: #64748b; font-size: 0.82rem; font-family: 'JetBrains Mono', monospace;">
                      <?= date('M d, Y h:i:s A', strtotime($att['check_in'])) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- TAB 5: BUDGETS -->
    <?php if ($tab === 'budgets'): ?>
      <div class="db-table-card">
        <div class="db-table-header">
          <div class="db-table-title">
            <i class="fa-solid fa-money-check-dollar text-primary"></i> Categorized Table: <code>org_budgets</code>
            <span class="tab-counter"><?= count($budgets) ?> records</span>
          </div>
          <div style="font-size: 0.82rem; color: #64748b;">
            Directly stored in <code style="font-family:'JetBrains Mono', monospace;"><?= htmlspecialchars($dedicated_db_name) ?>.org_budgets</code>
          </div>
        </div>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Budget Proposal Title</th>
                <th>Amount Requested</th>
                <th>Approval Status</th>
                <th>Requested By</th>
                <th>Notes / Remarks</th>
                <th>Created Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($budgets)): ?>
                <tr><td colspan="6" style="text-align: center; padding: 36px; color: #64748b;">No budget records found in dedicated database table.</td></tr>
              <?php else: ?>
                <?php foreach ($budgets as $b): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($b['title']) ?></strong></td>
                    <td><strong style="color: #15803d; font-size: 0.95rem;">₱<?= number_format((float)$b['amount'], 2) ?></strong></td>
                    <td>
                      <span class="pill <?= strpos($b['status'], 'Approved') !== false ? 'pill-green' : (strpos($b['status'], 'Pending') !== false ? 'pill-amber' : 'pill-slate') ?>">
                        <?= htmlspecialchars($b['status']) ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($b['requested_by'] ?? '—') ?></td>
                    <td style="color: #64748b; font-size: 0.82rem;"><?= htmlspecialchars($b['notes'] ?? '—') ?></td>
                    <td style="color: #64748b; font-size: 0.82rem;"><?= date('M d, Y', strtotime($b['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- TAB 6: ACHIEVEMENTS -->
    <?php if ($tab === 'achievements'): ?>
      <div class="db-table-card">
        <div class="db-table-header">
          <div class="db-table-title">
            <i class="fa-solid fa-award text-primary"></i> Categorized Table: <code>org_achievements</code>
            <span class="tab-counter"><?= count($achievements) ?> records</span>
          </div>
          <div style="font-size: 0.82rem; color: #64748b;">
            Directly stored in <code style="font-family:'JetBrains Mono', monospace;"><?= htmlspecialchars($dedicated_db_name) ?>.org_achievements</code>
          </div>
        </div>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Award / Honor Title</th>
                <th>Competition / Event</th>
                <th>Award Date</th>
                <th>Verification Status</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($achievements)): ?>
                <tr><td colspan="5" style="text-align: center; padding: 36px; color: #64748b;">No achievements recorded in dedicated database table.</td></tr>
              <?php else: ?>
                <?php foreach ($achievements as $ach): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($ach['title']) ?></strong></td>
                    <td><?= htmlspecialchars($ach['competition']) ?></td>
                    <td><strong><?= date('M d, Y', strtotime($ach['award_date'])) ?></strong></td>
                    <td><span class="pill pill-green"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($ach['status']) ?></span></td>
                    <td style="color: #64748b; font-size: 0.82rem;"><?= htmlspecialchars($ach['notes'] ?? '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- TAB 7: ANNOUNCEMENTS -->
    <?php if ($tab === 'announcements'): ?>
      <div class="db-table-card">
        <div class="db-table-header">
          <div class="db-table-title">
            <i class="fa-solid fa-bullhorn text-primary"></i> Categorized Table: <code>org_announcements</code>
            <span class="tab-counter"><?= count($announcements) ?> records</span>
          </div>
          <div style="font-size: 0.82rem; color: #64748b;">
            Directly stored in <code style="font-family:'JetBrains Mono', monospace;"><?= htmlspecialchars($dedicated_db_name) ?>.org_announcements</code>
          </div>
        </div>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Announcement Title</th>
                <th>Category</th>
                <th>Priority</th>
                <th>Target Group</th>
                <th>Content Snippet</th>
                <th>Date Posted</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($announcements)): ?>
                <tr><td colspan="6" style="text-align: center; padding: 36px; color: #64748b;">No announcements found in dedicated database table.</td></tr>
              <?php else: ?>
                <?php foreach ($announcements as $ann): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($ann['title']) ?></strong></td>
                    <td><span class="pill pill-slate"><?= htmlspecialchars($ann['category']) ?></span></td>
                    <td>
                      <span class="pill <?= $ann['priority'] === 'High' ? 'pill-amber' : ($ann['priority'] === 'Urgent' ? 'pill-blue' : 'pill-slate') ?>">
                        <?= htmlspecialchars($ann['priority']) ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($ann['target_group']) ?></td>
                    <td style="color: #475569; max-width: 320px;"><?= htmlspecialchars(substr($ann['content'], 0, 100)) ?>...</td>
                    <td style="color: #64748b; font-size: 0.82rem;"><?= date('M d, Y', strtotime($ann['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- TAB 8: AUDIT TRAIL -->
    <?php if ($tab === 'audit'): ?>
      <div class="db-table-card">
        <div class="db-table-header">
          <div class="db-table-title">
            <i class="fa-solid fa-clock-rotate-left text-primary"></i> Categorized Table: <code>org_audit_trail</code>
            <span class="tab-counter"><?= count($audit_logs) ?> records</span>
          </div>
          <div style="font-size: 0.82rem; color: #64748b;">
            Directly stored in <code style="font-family:'JetBrains Mono', monospace;"><?= htmlspecialchars($dedicated_db_name) ?>.org_audit_trail</code>
          </div>
        </div>
        <div class="table-responsive">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Action</th>
                <th>Details</th>
                <th>Performed By</th>
                <th>Timestamp</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($audit_logs)): ?>
                <tr><td colspan="4" style="text-align: center; padding: 36px; color: #64748b;">No audit entries logged yet for this organization.</td></tr>
              <?php else: ?>
                <?php foreach ($audit_logs as $aud): ?>
                  <tr>
                    <td><strong style="color: #1e3a8a;"><?= htmlspecialchars($aud['action']) ?></strong></td>
                    <td style="color: #334155;"><?= htmlspecialchars($aud['detail'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($aud['performed_by'] ?? 'System') ?></td>
                    <td style="color: #64748b; font-size: 0.82rem; font-family: 'JetBrains Mono', monospace;">
                      <?= date('M d, Y h:i:s A', strtotime($aud['created_at'])) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

  </div><!-- end content -->
</div><!-- end main -->

<script>
  // Topbar search filter on visible table rows
  document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.querySelector('#topbarSearchWrap input');
    const clearBtn = document.querySelector('.search-clear-btn');
    if (searchInput) {
      searchInput.addEventListener('input', () => {
        const query = searchInput.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.custom-table tbody tr');
        rows.forEach(row => {
          const text = row.textContent.toLowerCase();
          row.style.display = text.includes(query) ? '' : 'none';
        });
      });
      if (clearBtn) {
        clearBtn.addEventListener('click', () => {
          searchInput.value = '';
          searchInput.dispatchEvent(new Event('input'));
        });
      }
    }
  });
</script>
</body>
</html>
