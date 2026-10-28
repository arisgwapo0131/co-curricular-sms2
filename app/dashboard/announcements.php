<?php
// ============================================================
//  ANNOUNCEMENTS.PHP  (app/dashboard/announcements.php)
//  Co-Curricular System — Announcement & Communication Management
//  - Admin: Publication controls, notification templates, system notices, broad communications
//  - SSC: Council & governance communications within permitted audience
//  - Adviser: Organization announcements for assigned club
//  - Student: Campus-wide and organization notices
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_auth();
require_any_permission(['announcements.view', 'announcements.create.org', 'announcements.create.council', 'announcements.manage.all']);

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];
$ACTIVE_NAV   = 'announcements';

// ── 1. Fetch Clubs for Selection (Admin & Adviser) ───────────
$all_clubs = get_user_assigned_clubs($conn, $user_id, $sess_role);
$adviser_club = ($sess_role === 'club_adviser' && !empty($all_clubs)) ? $all_clubs[0] : null;

// ── 2. Telemetry & Metrics (Database-Driven) ─────────────────
if ($sess_role === 'admin') {
    $kpi_total_ann   = (int)$conn->query("SELECT COUNT(*) FROM org_announcements")->fetch_row()[0];
    $kpi_sys_notices = (int)$conn->query("SELECT COUNT(*) FROM org_announcements WHERE scope = 'System'")->fetch_row()[0];
    $kpi_templates   = (int)$conn->query("SELECT COUNT(*) FROM notification_templates WHERE status = 'Active'")->fetch_row()[0];
    $kpi_active_pub  = (int)$conn->query("SELECT COUNT(*) FROM org_announcements WHERE status = 'Published'")->fetch_row()[0];
    $kpi_pinned      = (int)$conn->query("SELECT COUNT(*) FROM org_announcements WHERE is_pinned = 1 AND status = 'Published'")->fetch_row()[0];
} elseif ($sess_role === 'ssc') {
    $kpi_council_ann = (int)$conn->query("SELECT COUNT(*) FROM org_announcements WHERE scope = 'Council'")->fetch_row()[0];
    $kpi_assemblies  = (int)$conn->query("SELECT COUNT(*) FROM org_announcements WHERE scope = 'Council' AND category IN ('Council Assembly', 'Governance & Resolutions')")->fetch_row()[0];
    $kpi_total_students = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetch_row()[0];
    $kpi_active_pub  = (int)$conn->query("SELECT COUNT(*) FROM org_announcements WHERE scope = 'Council' AND status = 'Published'")->fetch_row()[0];
} else {
    $kpi_total_ann   = (int)$conn->query("SELECT COUNT(*) FROM org_announcements WHERE status = 'Published' AND (scope IN ('System', 'Council') OR club_id IN (SELECT club_id FROM club_memberships WHERE user_id = {$user_id} AND status = 'Active'))")->fetch_row()[0];
    $kpi_urgent_cnt  = (int)$conn->query("SELECT COUNT(*) FROM org_announcements WHERE priority = 'Urgent' AND status = 'Published' AND (scope IN ('System', 'Council') OR club_id IN (SELECT club_id FROM club_memberships WHERE user_id = {$user_id} AND status = 'Active'))")->fetch_row()[0];
}

// ── 3. Fetch Master Announcements List ────────────────────────
$announcements = [];
if ($sess_role === 'admin') {
    $r_ann = $conn->query("
        SELECT a.*, c.name AS club_name, c.code AS club_code,
               u.first_name, u.last_name, u.role AS author_role
        FROM org_announcements a
        LEFT JOIN clubs c ON c.id = a.club_id
        JOIN users u ON u.id = a.author_id
        ORDER BY a.is_pinned DESC, a.created_at DESC
    ");
} elseif ($sess_role === 'ssc') {
    $r_ann = $conn->query("
        SELECT a.*, c.name AS club_name, c.code AS club_code,
               u.first_name, u.last_name, u.role AS author_role
        FROM org_announcements a
        LEFT JOIN clubs c ON c.id = a.club_id
        JOIN users u ON u.id = a.author_id
        WHERE a.scope = 'Council' OR a.scope = 'System' OR a.author_id = {$user_id}
        ORDER BY a.is_pinned DESC, a.created_at DESC
    ");
} elseif ($sess_role === 'club_adviser') {
    $cid = $adviser_club ? (int)$adviser_club['id'] : 0;
    $r_ann = $conn->query("
        SELECT a.*, c.name AS club_name, c.code AS club_code,
               u.first_name, u.last_name, u.role AS author_role
        FROM org_announcements a
        LEFT JOIN clubs c ON c.id = a.club_id
        JOIN users u ON u.id = a.author_id
        WHERE a.scope IN ('System', 'Council') OR a.club_id = {$cid} OR a.author_id = {$user_id}
        ORDER BY a.is_pinned DESC, a.created_at DESC
    ");
} else {
    $r_ann = $conn->query("
        SELECT a.*, c.name AS club_name, c.code AS club_code,
               u.first_name, u.last_name, u.role AS author_role
        FROM org_announcements a
        LEFT JOIN clubs c ON c.id = a.club_id
        JOIN users u ON u.id = a.author_id
        WHERE a.status = 'Published'
          AND (a.scope IN ('System', 'Council') OR a.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = {$user_id} AND status = 'Active'))
        ORDER BY a.is_pinned DESC, a.created_at DESC
    ");
}

if ($r_ann) {
    $announcements = $r_ann->fetch_all(MYSQLI_ASSOC);
}

// ── 4. Fetch Notification Templates (for Admin & Quick Post) ──
$templates_list = [];
$res_tpl = $conn->query("SELECT * FROM notification_templates ORDER BY category ASC, title ASC");
if ($res_tpl) {
    $templates_list = $res_tpl->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>
    <?php if ($sess_role === 'admin'): ?>
      Announcement &amp; Communication Administration — BCP Co-Curricular Portal
    <?php elseif ($sess_role === 'ssc'): ?>
      Council &amp; Governance Communications — BCP Co-Curricular Portal
    <?php else: ?>
      Campus Announcements — BCP Co-Curricular Portal
    <?php endif; ?>
  </title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <meta name="csrf-token" content="<?= csrf_token() ?>" />
  <script src="../js/page-loader.js"></script>
  <style>
    /* ── Typography & KPI Layout ── */
    .comm-kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .comm-kpi-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px 18px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .comm-kpi-card:hover {
      box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .comm-kpi-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }
    .comm-kpi-title {
      font-size: 0.76rem;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .comm-kpi-icon {
      width: 34px;
      height: 34px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.95rem;
    }
    .comm-kpi-value {
      font-size: 1.65rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.2;
      margin-bottom: 2px;
    }
    .comm-kpi-desc {
      font-size: 0.74rem;
      color: #64748b;
    }

    /* ── Admin Navigation Tabs ── */
    .comm-nav-tabs {
      display: flex;
      align-items: center;
      gap: 6px;
      border-bottom: 2px solid #e2e8f0;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    .comm-nav-tab {
      padding: 10px 16px;
      font-size: 0.84rem;
      font-weight: 700;
      color: #64748b;
      background: transparent;
      border: none;
      border-bottom: 2px solid transparent;
      margin-bottom: -2px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
    }
    .comm-nav-tab:hover {
      color: #2563eb;
    }
    .comm-nav-tab.active {
      color: #2563eb;
      border-bottom-color: #2563eb;
    }

    /* ── Badges & Status Pills ── */
    .scope-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 0.70rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.4px;
    }
    .scope-system  { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .scope-council { background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; }
    .scope-club    { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

    .status-pub-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 7px;
      border-radius: 9999px;
      font-size: 0.68rem;
      font-weight: 700;
    }
    .status-pub-published { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .status-pub-draft     { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .status-pub-archived  { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

    .prio-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 7px;
      border-radius: 9999px;
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: uppercase;
    }
    .prio-urgent    { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .prio-important { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
    .prio-normal    { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

    .pinned-pill {
      background: #fef9c3;
      color: #854d0e;
      border: 1px solid #fef08a;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 7px;
      border-radius: 9999px;
      font-size: 0.68rem;
      font-weight: 800;
    }

    /* ── Announcement Cards ── */
    .ann-item-card {
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 18px 20px;
      margin-bottom: 14px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
      transition: all 0.2s ease;
      position: relative;
    }
    .ann-item-card.is-pinned-card {
      border-color: #fde047;
      background: #fffdf5;
      box-shadow: 0 3px 10px rgba(234, 179, 8, 0.08);
    }
    .ann-item-card:hover {
      border-color: #cbd5e1;
      box-shadow: 0 4px 14px rgba(0,0,0,0.06);
    }
    .ann-item-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 10px;
    }
    .ann-item-title {
      font-size: 1.05rem;
      font-weight: 800;
      color: #0f172a;
      margin: 0 0 8px;
      line-height: 1.35;
    }
    .ann-item-body {
      font-size: 0.85rem;
      color: #334155;
      line-height: 1.6;
      white-space: pre-line;
      margin-bottom: 14px;
    }
    .ann-meta-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      padding-top: 12px;
      border-top: 1px solid #f1f5f9;
      font-size: 0.74rem;
      color: #64748b;
    }

    /* ── Compact Admin Table ── */
    .admin-fit-table {
      width: 100% !important;
      border-collapse: collapse !important;
      font-size: 0.75rem !important;
      text-align: left !important;
    }
    .admin-fit-table thead th {
      background: #f8fafc !important;
      color: #334155 !important;
      font-size: 0.70rem !important;
      font-weight: 800 !important;
      text-transform: uppercase !important;
      letter-spacing: 0.5px !important;
      padding: 8px 8px !important;
      border-bottom: 1.5px solid #e2e8f0 !important;
    }
    .admin-fit-table tbody td {
      padding: 7px 8px !important;
      vertical-align: middle !important;
      border-bottom: 1px solid #f1f5f9 !important;
      font-size: 0.75rem !important;
      color: #1e293b !important;
    }

    /* Layout & Footer Anchor */
    .main {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .content {
      flex: 1;
      padding-bottom: 0 !important;
    }
    .footer {
      margin-top: auto;
      flex-shrink: 0;
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT   = '../';
  $ACTIVE_NAV = 'announcements';
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
        <button class="topbar-qr-btn" id="qrFabBtn" title="View Personal Attendance QR Code" type="button">
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
      <div class="page-title-bar" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
          <h2 class="page-title" style="margin:0; font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:10px;">
            <i class="fa-solid fa-bullhorn" style="color:#2563eb;"></i>
            Inter-club Communication
          </h2>
          <div style="font-size:0.80rem; color:#64748b; margin-top:3px;">
            <?php if ($sess_role === 'admin' || $sess_role === 'super_admin'): ?>
              Institutional Communication &bull; Broadcast Administration &bull; Campus Bulletins
            <?php elseif ($sess_role === 'ssc'): ?>
              Supreme Student Council Broadcasts &bull; Student Assembly Bulletins &bull; Campus Feeds
            <?php elseif ($sess_role === 'club_adviser'): ?>
              Organization Announcements &bull; Meeting Reminders &bull; Inter-Club Communications
            <?php else: ?>
              Official Campus Announcements &bull; Organization Updates &bull; Student Notices
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="content-body">

        <!-- Alert Notification Box -->
        <div id="annAlert" style="display:none; padding:12px 16px; border-radius:8px; font-size:0.82rem; margin-bottom:16px;"></div>

        <!-- ══════════════════════════════════════════════════════
             TELEMETRY STAT TILES (Database-Driven)
             ══════════════════════════════════════════════════════ -->
        <div class="comm-kpi-grid">
          <?php if ($sess_role === 'admin'): ?>
            <!-- Admin KPI 1: Total Announcements -->
            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">Total Notices</span>
                <div class="comm-kpi-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-layer-group"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_total_ann) ?></div>
              <div class="comm-kpi-desc">Across System, Council &amp; Clubs</div>
            </div>

            <!-- Admin KPI 2: System Notices -->
            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">System Notices</span>
                <div class="comm-kpi-icon" style="background:#eff6ff; color:#1d4ed8;"><i class="fa-solid fa-shield-halved"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_sys_notices) ?></div>
              <div class="comm-kpi-desc">Administrative &amp; campus advisories</div>
            </div>

            <!-- Admin KPI 3: Notification Templates -->
            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">Notification Templates</span>
                <div class="comm-kpi-icon" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-file-invoice"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_templates) ?></div>
              <div class="comm-kpi-desc">Ready communication formats</div>
            </div>

            <!-- Admin KPI 4: Publication Controls Status -->
            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">Active Dispatches</span>
                <div class="comm-kpi-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-thumbtack"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_pinned) ?> Pinned / <?= number_format($kpi_active_pub) ?> Live</div>
              <div class="comm-kpi-desc">Controlled publication visibility</div>
            </div>

          <?php elseif ($sess_role === 'ssc'): ?>
            <!-- SSC KPI 1: Council Notices -->
            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">Council Bulletins</span>
                <div class="comm-kpi-icon" style="background:#faf5ff; color:#7e22ce;"><i class="fa-solid fa-scale-balanced"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_council_ann) ?></div>
              <div class="comm-kpi-desc">Governance announcements</div>
            </div>

            <!-- SSC KPI 2: Assemblies & Resolutions -->
            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">Assemblies &amp; Policy</span>
                <div class="comm-kpi-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-gavel"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_assemblies) ?></div>
              <div class="comm-kpi-desc">Ratified student council policies</div>
            </div>

            <!-- SSC KPI 3: Student Body Audience -->
            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">Permitted Audience</span>
                <div class="comm-kpi-icon" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-users"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_total_students) ?></div>
              <div class="comm-kpi-desc">Enrolled student campus learners</div>
            </div>

            <!-- SSC KPI 4: Live Status -->
            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">Published Notices</span>
                <div class="comm-kpi-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-bullhorn"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_active_pub) ?> Active</div>
              <div class="comm-kpi-desc">Live on student feeds</div>
            </div>

          <?php else: ?>
            <!-- General / Student / Adviser KPI -->
            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">Total Active Announcements</span>
                <div class="comm-kpi-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-bullhorn"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_total_ann) ?></div>
              <div class="comm-kpi-desc">Published campus notices</div>
            </div>

            <div class="comm-kpi-card">
              <div class="comm-kpi-header">
                <span class="comm-kpi-title">Urgent Advisories</span>
                <div class="comm-kpi-icon" style="background:#fee2e2; color:#dc2626;"><i class="fa-solid fa-triangle-exclamation"></i></div>
              </div>
              <div class="comm-kpi-value"><?= number_format($kpi_urgent_cnt) ?></div>
              <div class="comm-kpi-desc">High priority campus broadcasts</div>
            </div>

            <?php if ($sess_role === 'club_adviser'): ?>
              <div class="comm-kpi-card">
                <div class="comm-kpi-header">
                  <span class="comm-kpi-title">Assigned Organization</span>
                  <div class="comm-kpi-icon" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-building-columns"></i></div>
                </div>
                <div class="comm-kpi-value" style="font-size:1.15rem;"><?= htmlspecialchars($adviser_club['code'] ?? 'N/A') ?></div>
                <div class="comm-kpi-desc"><?= htmlspecialchars($adviser_club['name'] ?? 'Faculty Adviser') ?></div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- ══════════════════════════════════════════════════════
             ROLE TABS (ADMIN NAVIGATION: BROADCASTS & TEMPLATES)
             ══════════════════════════════════════════════════════ -->
        <?php if ($sess_role === 'admin'): ?>
          <div class="comm-nav-tabs">
            <button class="comm-nav-tab active" id="tabBtnAnnouncements" onclick="switchCommTab('notices')">
              <i class="fa-solid fa-bullhorn"></i> System Notices &amp; Broad Communications
            </button>
            <button class="comm-nav-tab" id="tabBtnTemplates" onclick="switchCommTab('templates')">
              <i class="fa-solid fa-file-invoice"></i> Notification Templates (<?= count($templates_list) ?>)
            </button>
            <button class="comm-nav-tab" id="tabBtnControls" onclick="switchCommTab('controls')">
              <i class="fa-solid fa-sliders"></i> Publication Controls Overview
            </button>
          </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════════
             SECTION 1: SYSTEM NOTICES & ANNOUNCEMENTS MASTER FEED
             ══════════════════════════════════════════════════════ -->
        <div id="sectionNotices" class="card" style="padding:18px 20px; border-radius:14px; border:1px solid #e2e8f0; margin-bottom:24px;">
          
          <!-- Toolbar: Filters and Action Buttons -->
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <div>
              <h3 style="margin:0; font-size:1.05rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-newspaper" style="color:#2563eb;"></i>
                <?php if ($sess_role === 'admin'): ?>
                  Active Communication Ledger
                <?php elseif ($sess_role === 'ssc'): ?>
                  Council &amp; Governance Bulletins
                <?php else: ?>
                  Latest Announcements
                <?php endif; ?>
              </h3>
              <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
                <?php if ($sess_role === 'admin'): ?>
                  Manage administrative notices, verify publication statuses, pin urgent alerts, and dispatch to campus audiences.
                <?php elseif ($sess_role === 'ssc'): ?>
                  Publish assemblies, resolutions, and governance communications to your permitted student audience.
                <?php else: ?>
                  Filter announcements by category and priority.
                <?php endif; ?>
              </div>
            </div>

            <!-- Action Controls Toolbar -->
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
              <!-- Live Search -->
              <div style="position:relative; width:210px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:0.72rem; color:#94a3b8;"></i>
                <input type="text" id="annSearchInput" placeholder="Search notices, audience..." oninput="filterAnnouncementsClient()" style="width:100%; height:32px; padding:0 10px 0 28px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.75rem; box-sizing:border-box;" />
              </div>

              <!-- Scope Filter (Admin Only) -->
              <?php if ($sess_role === 'admin'): ?>
                <select id="annScopeFilter" onchange="filterAnnouncementsClient()" style="height:32px; padding:0 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.75rem; font-weight:600; color:#334155; background:#fff;">
                  <option value="all">All Scopes</option>
                  <option value="System">System Notices</option>
                  <option value="Council">Council Bulletins</option>
                  <option value="Club">Organization Notices</option>
                </select>

                <!-- Publication Status Filter (Admin Only) -->
                <select id="annStatusFilter" onchange="filterAnnouncementsClient()" style="height:32px; padding:0 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.75rem; font-weight:600; color:#334155; background:#fff;">
                  <option value="all">All Statuses</option>
                  <option value="Published">Published</option>
                  <option value="Draft">Drafts</option>
                  <option value="Archived">Archived</option>
                </select>
              <?php endif; ?>

              <!-- Priority Filter -->
              <select id="annPriorityFilter" onchange="filterAnnouncementsClient()" style="height:32px; padding:0 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.75rem; font-weight:600; color:#334155; background:#fff;">
                <option value="all">All Priorities</option>
                <option value="Urgent">Urgent</option>
                <option value="Important">Important</option>
                <option value="Normal">Normal</option>
              </select>

              <!-- Action Buttons -->
              <?php if (can_any(['announcements.manage.all', 'announcements.create.council', 'announcements.create.org'])): ?>
                <button type="button" class="card-btn" onclick="openCreateModal()" style="height:32px; padding:0 12px; font-size:0.75rem; background:#2563eb; color:#fff; font-weight:700; border-radius:6px; display:inline-flex; align-items:center; gap:6px; border:none; cursor:pointer;">
                  <i class="fa-solid fa-plus-circle"></i>
                  <?= can('announcements.manage.all') ? 'Broadcast Notice' : (can('announcements.create.council') ? 'Publish Council Notice' : 'Post Announcement') ?>
                </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Announcement Feed Container -->
          <div id="annFeedContainer">
            <?php if (empty($announcements)): ?>
              <div style="text-align:center; padding:50px 20px; background:#f8fafc; border-radius:10px; border:1px dashed #cbd5e1; margin-top:14px;">
                <i class="fa-solid fa-bullhorn" style="font-size:2rem; color:#94a3b8; margin-bottom:10px; display:block;"></i>
                <h3 style="margin:0; font-size:1.05rem; color:#1e293b;">No Announcements Found</h3>
                <p style="margin:4px 0 0; font-size:0.78rem; color:#64748b;">There are currently no announcements matching your role or filter criteria.</p>
                <?php if (in_array($sess_role, ['admin', 'ssc', 'club_adviser'])): ?>
                  <button type="button" onclick="openCreateModal()" class="card-btn" style="margin-top:14px; background:#2563eb; color:#fff; padding:6px 14px; font-size:0.75rem; border-radius:6px; cursor:pointer;">
                    <i class="fa-solid fa-plus-circle"></i> Create Announcement
                  </button>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <?php foreach ($announcements as $ann): ?>
                <?php
                  $scope = $ann['scope'] ?? 'Club';
                  $scope_class = ($scope === 'System') ? 'scope-system' : (($scope === 'Council') ? 'scope-council' : 'scope-club');
                  $scope_icon  = ($scope === 'System') ? 'fa-shield-halved' : (($scope === 'Council') ? 'fa-scale-balanced' : 'fa-users');

                  $prio = $ann['priority'] ?? 'Normal';
                  $prio_class = ($prio === 'Urgent') ? 'prio-urgent' : (($prio === 'Important') ? 'prio-important' : 'prio-normal');

                  $pub_status = $ann['status'] ?? 'Published';
                  $status_class = ($pub_status === 'Published') ? 'status-pub-published' : (($pub_status === 'Draft') ? 'status-pub-draft' : 'status-pub-archived');

                  $is_pinned = !empty($ann['is_pinned']);
                  $is_author = ((int)$ann['author_id'] === $user_id);
                  $can_manage = ($sess_role === 'admin') || ($sess_role === 'ssc' && $scope === 'Council') || ($sess_role === 'club_adviser' && $is_author);
                ?>
                <div class="ann-item-card <?= $is_pinned ? 'is-pinned-card' : '' ?>"
                     data-scope="<?= htmlspecialchars($scope) ?>"
                     data-status="<?= htmlspecialchars($pub_status) ?>"
                     data-priority="<?= htmlspecialchars($prio) ?>"
                     data-category="<?= htmlspecialchars($ann['category'] ?? '') ?>"
                     data-title="<?= htmlspecialchars(strtolower($ann['title'])) ?>"
                     data-content="<?= htmlspecialchars(strtolower($ann['content'])) ?>">
                  
                  <!-- Card Header: Badges & Time -->
                  <div class="ann-item-header">
                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                      <!-- Scope Badge -->
                      <span class="scope-badge <?= $scope_class ?>">
                        <i class="fa-solid <?= $scope_icon ?>"></i> <?= htmlspecialchars($scope) ?> Notice
                      </span>

                      <!-- Priority Badge -->
                      <span class="prio-pill <?= $prio_class ?>">
                        <i class="fa-solid fa-circle"></i> <?= htmlspecialchars($prio) ?>
                      </span>

                      <!-- Publication Status (Admin & SSC) -->
                      <?php if (in_array($sess_role, ['admin', 'ssc'])): ?>
                        <span class="status-pub-pill <?= $status_class ?>">
                          <i class="fa-solid fa-circle-dot"></i> <?= htmlspecialchars($pub_status) ?>
                        </span>
                      <?php endif; ?>

                      <!-- Pinned Indicator -->
                      <?php if ($is_pinned): ?>
                        <span class="pinned-pill">
                          <i class="fa-solid fa-thumbtack"></i> Pinned
                        </span>
                      <?php endif; ?>

                      <!-- Category -->
                      <span style="font-size:0.72rem; color:#475569; background:#f1f5f9; padding:2px 8px; border-radius:4px; font-weight:700;">
                        <?= htmlspecialchars($ann['category'] ?? 'General') ?>
                      </span>

                      <!-- Target Audience -->
                      <span style="font-size:0.72rem; color:#1e40af; background:#eff6ff; padding:2px 8px; border-radius:4px; font-weight:700; border:1px solid #bfdbfe;">
                        <i class="fa-solid fa-bullseye" style="margin-right:3px;"></i><?= htmlspecialchars($ann['target_group'] ?? 'All Campus Users') ?>
                      </span>
                    </div>

                    <!-- Timestamp -->
                    <div style="font-size:0.72rem; color:#64748b; white-space:nowrap;">
                      <i class="fa-regular fa-clock" style="margin-right:3px;"></i>
                      <?= date('M d, Y h:i A', strtotime($ann['created_at'])) ?>
                    </div>
                  </div>

                  <!-- Card Title & Content -->
                  <h3 class="ann-item-title"><?= htmlspecialchars($ann['title']) ?></h3>
                  <div class="ann-item-body"><?= htmlspecialchars($ann['content']) ?></div>

                  <!-- Metadata Bar & Action Controls -->
                  <div class="ann-meta-bar">
                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                      <span>
                        <i class="fa-solid fa-user-pen" style="color:#2563eb;"></i>
                        <strong><?= htmlspecialchars(($ann['first_name'] ?? '') . ' ' . ($ann['last_name'] ?? '')) ?></strong>
                        (<?= htmlspecialchars(strtoupper($ann['author_role'] ?? 'Staff')) ?>)
                      </span>
                      <?php if (!empty($ann['club_name'])): ?>
                        <span>&bull; <?= htmlspecialchars($ann['club_name']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($ann['channels'])): ?>
                        <span>&bull; <i class="fa-solid fa-tower-broadcast"></i> <?= htmlspecialchars($ann['channels']) ?></span>
                      <?php endif; ?>
                    </div>

                    <!-- Publication Controls Toolbar -->
                    <?php if ($can_manage): ?>
                      <div style="display:flex; align-items:center; gap:6px;">
                        <?php if ($sess_role === 'admin' || $sess_role === 'ssc'): ?>
                          <!-- Pin Toggle Button -->
                          <button type="button" onclick="togglePinAnnouncement(<?= (int)$ann['id'] ?>)" class="card-btn"
                                  style="height:26px; padding:0 8px; font-size:0.70rem; background:#f8fafc; color:#334155; border:1px solid #cbd5e1; border-radius:5px; cursor:pointer;"
                                  title="<?= $is_pinned ? 'Unpin Notice' : 'Pin Notice to Top' ?>">
                            <i class="fa-solid fa-thumbtack" style="color:<?= $is_pinned ? '#eab308' : '#94a3b8' ?>;"></i> <?= $is_pinned ? 'Unpin' : 'Pin' ?>
                          </button>

                          <!-- Status Switcher (Admin Only) -->
                          <?php if ($sess_role === 'admin'): ?>
                            <select onchange="updateAnnouncementStatus(<?= (int)$ann['id'] ?>, this.value)" style="height:26px; padding:0 6px; font-size:0.70rem; border:1px solid #cbd5e1; border-radius:5px; font-weight:700; color:#334155; background:#fff;">
                              <option value="Published" <?= $pub_status === 'Published' ? 'selected' : '' ?>>Published</option>
                              <option value="Draft" <?= $pub_status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                              <option value="Archived" <?= $pub_status === 'Archived' ? 'selected' : '' ?>>Archived</option>
                            </select>
                          <?php endif; ?>
                        <?php endif; ?>

                        <!-- Edit Button -->
                        <button type="button" onclick="openEditModal(<?= htmlspecialchars(json_encode($ann)) ?>)" class="card-btn"
                                style="height:26px; padding:0 8px; font-size:0.70rem; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:5px; cursor:pointer;">
                          <i class="fa-solid fa-pen"></i> Edit
                        </button>

                        <!-- Delete Button -->
                        <button type="button" onclick="deleteAnnouncement(<?= (int)$ann['id'] ?>)" class="card-btn"
                                style="height:26px; padding:0 8px; font-size:0.70rem; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:5px; cursor:pointer;">
                          <i class="fa-solid fa-trash"></i> Delete
                        </button>
                      </div>
                    <?php endif; ?>
                  </div>

                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

        </div><!-- end sectionNotices -->


        <!-- ══════════════════════════════════════════════════════
             SECTION 2: NOTIFICATION TEMPLATES (ADMIN ONLY)
             ══════════════════════════════════════════════════════ -->
        <?php if ($sess_role === 'admin'): ?>
          <div id="sectionTemplates" class="card" style="display:none; padding:18px 20px; border-radius:14px; border:1px solid #e2e8f0; margin-bottom:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <div>
                <h3 style="margin:0; font-size:1.05rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                  <i class="fa-solid fa-file-invoice" style="color:#2563eb;"></i> Standard Notification Templates
                </h3>
                <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
                  Pre-configured broad communication formats, emergency bulletins, and administrative clearance notices.
                </div>
              </div>
              <button type="button" class="card-btn" onclick="openTemplateModal()" style="height:32px; padding:0 12px; font-size:0.75rem; background:#2563eb; color:#fff; font-weight:700; border-radius:6px; display:inline-flex; align-items:center; gap:6px; border:none; cursor:pointer;">
                <i class="fa-solid fa-plus"></i> New Notification Template
              </button>
            </div>

            <!-- Templates Table -->
            <div class="table-wrap">
              <table class="admin-fit-table">
                <thead>
                  <tr>
                    <th style="width:13%;">Code</th>
                    <th style="width:20%;">Template Title</th>
                    <th style="width:15%;">Category</th>
                    <th style="width:24%;">Subject Preview</th>
                    <th style="width:14%;">Default Audience</th>
                    <th style="width:14%; text-align:right;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($templates_list)): ?>
                    <?php foreach ($templates_list as $tpl): ?>
                      <tr>
                        <td>
                          <span style="font-family:monospace; font-weight:700; font-size:0.72rem; padding:2px 6px; background:#f1f5f9; border-radius:4px; color:#1e293b;">
                            <?= htmlspecialchars($tpl['code']) ?>
                          </span>
                        </td>
                        <td>
                          <div style="font-weight:700; color:#0f172a; font-size:0.76rem;"><?= htmlspecialchars($tpl['title']) ?></div>
                        </td>
                        <td>
                          <span style="font-size:0.70rem; font-weight:700; color:#1e40af; background:#eff6ff; padding:2px 6px; border-radius:4px; border:1px solid #bfdbfe;">
                            <?= htmlspecialchars($tpl['category']) ?>
                          </span>
                        </td>
                        <td>
                          <div style="font-size:0.74rem; color:#334155; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($tpl['subject_template']) ?>">
                            <?= htmlspecialchars($tpl['subject_template']) ?>
                          </div>
                        </td>
                        <td>
                          <span style="font-size:0.70rem; color:#475569; font-weight:600;">
                            <i class="fa-solid fa-users" style="color:#94a3b8; margin-right:3px;"></i><?= htmlspecialchars($tpl['default_target']) ?>
                          </span>
                        </td>
                        <td style="text-align:right;">
                          <div style="display:inline-flex; align-items:center; gap:5px;">
                            <button type="button" class="card-btn" onclick="useTemplateToBroadcast(<?= htmlspecialchars(json_encode($tpl)) ?>)" style="height:24px; padding:0 8px; font-size:0.68rem; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:4px; cursor:pointer;" title="Post announcement using this template">
                              <i class="fa-solid fa-bolt"></i> Use
                            </button>
                            <button type="button" class="card-btn" onclick="openTemplateModal(<?= htmlspecialchars(json_encode($tpl)) ?>)" style="height:24px; padding:0 8px; font-size:0.68rem; background:#f8fafc; color:#334155; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer;" title="Edit template">
                              <i class="fa-solid fa-pen"></i>
                            </button>
                            <button type="button" class="card-btn" onclick="deleteTemplate(<?= (int)$tpl['id'] ?>)" style="height:24px; padding:0 8px; font-size:0.68rem; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:4px; cursor:pointer;" title="Delete template">
                              <i class="fa-solid fa-trash"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">
                        No notification templates defined in the database.
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div><!-- end sectionTemplates -->


          <!-- ══════════════════════════════════════════════════════
               SECTION 3: PUBLICATION CONTROLS OVERVIEW (ADMIN ONLY)
               ══════════════════════════════════════════════════════ -->
          <div id="sectionControls" class="card" style="display:none; padding:18px 20px; border-radius:14px; border:1px solid #e2e8f0; margin-bottom:24px;">
            <h3 style="margin:0 0 4px; font-size:1.05rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
              <i class="fa-solid fa-sliders" style="color:#2563eb;"></i> Administrative Publication Controls
            </h3>
            <p style="font-size:0.76rem; color:#64748b; margin-bottom:16px;">
              System-wide rules governing broad audience reach, automated broadcast notifications, and pinning priorities.
            </p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
              <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 6px; font-size:0.85rem; color:#0f172a;"><i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i> Publication Status Workflow</h4>
                <p style="font-size:0.75rem; color:#64748b; margin:0 0 10px; line-height:1.4;">
                  Notices set to <strong>Draft</strong> remain visible solely to administrators and authors. Once toggled to <strong>Published</strong>, notifications are dispatched to target accounts.
                </p>
                <span class="status-pub-pill status-pub-published"><i class="fa-solid fa-circle-check"></i> Published</span>
                <span class="status-pub-pill status-pub-draft"><i class="fa-solid fa-pen-ruler"></i> Draft</span>
                <span class="status-pub-pill status-pub-archived"><i class="fa-solid fa-box-archive"></i> Archived</span>
              </div>

              <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 6px; font-size:0.85rem; color:#0f172a;"><i class="fa-solid fa-thumbtack" style="color:#eab308;"></i> Pinning &amp; Urgency Escalation</h4>
                <p style="font-size:0.75rem; color:#64748b; margin:0 0 10px; line-height:1.4;">
                  Pinned notices anchor at the top of the campus feed above chronological club notices. Urgent notices trigger immediate system warning banners.
                </p>
                <span class="pinned-pill"><i class="fa-solid fa-thumbtack"></i> Pinned to Top</span>
                <span class="prio-pill prio-urgent"><i class="fa-solid fa-circle"></i> Urgent Broadcast</span>
              </div>

              <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 6px; font-size:0.85rem; color:#0f172a;"><i class="fa-solid fa-users" style="color:#10b981;"></i> Broad Audience Reach</h4>
                <p style="font-size:0.75rem; color:#64748b; margin:0; line-height:1.4;">
                  Administrators have exclusive capability to broadcast across <strong>All Campus Users</strong> (Students, Advisers, SSC, Admin). SSC is scoped to their permitted student/council audience.
                </p>
              </div>
            </div>
          </div><!-- end sectionControls -->
        <?php endif; ?>

      </div><!-- end content-body -->
    </div><!-- end content -->
  </div><!-- end main -->


  <!-- ══════════════════════════════════════════════════════════
       MODAL 1: CREATE / EDIT ANNOUNCEMENT & BROAD COMMUNICATION
       ══════════════════════════════════════════════════════════ -->
  <div class="qr-modal-overlay" id="annModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:14px; width:580px; max-width:92vw; padding:22px; box-shadow:0 20px 40px rgba(0,0,0,0.2); max-height:92vh; overflow-y:auto;">
      
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
        <h3 id="modalTitleText" style="margin:0; font-size:1.05rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
          <i class="fa-solid fa-bullhorn" style="color:#2563eb;"></i> Post Announcement
        </h3>
        <button type="button" onclick="closeModal('annModal')" style="background:none; border:none; font-size:1.1rem; cursor:pointer; color:#64748b;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <form id="annForm" onsubmit="handleAnnouncementSubmit(event)">
        <input type="hidden" name="id" id="annFormId" value="0" />

        <!-- Scope & Pre-fill from template (Admin Only) -->
        <?php if ($sess_role === 'admin'): ?>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
            <div>
              <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Communication Scope *</label>
              <select name="scope" id="annFormScope" onchange="handleScopeChange()" style="width:100%; height:34px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem; font-weight:600;">
                <option value="System">System Notice (Institution-Wide)</option>
                <option value="Council">Council Communication</option>
                <option value="Club">Organization Notice</option>
              </select>
            </div>
            <div>
              <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Quick Fill Template</label>
              <select id="annTemplateSelector" onchange="applyTemplateToForm(this.value)" style="width:100%; height:34px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem;">
                <option value="">-- Choose Template --</option>
                <?php foreach ($templates_list as $t): ?>
                  <option value="<?= htmlspecialchars(json_encode($t)) ?>"><?= htmlspecialchars($t['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        <?php else: ?>
          <input type="hidden" name="scope" id="annFormScope" value="<?= ($sess_role === 'ssc') ? 'Council' : 'Club' ?>" />
        <?php endif; ?>

        <!-- Club selector (when Scope is Club) -->
        <div id="annClubWrap" style="display:<?= ($sess_role === 'club_adviser') ? 'none' : 'none' ?>; margin-bottom:12px;">
          <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Target Club</label>
          <select name="club_id" id="annFormClubId" style="width:100%; height:34px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem;">
            <?php foreach ($all_clubs as $cl): ?>
              <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?> (<?= htmlspecialchars($cl['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Announcement Title -->
        <div style="margin-bottom:12px;">
          <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Announcement / Broadcast Title *</label>
          <input type="text" name="title" id="annFormTitle" required placeholder="e.g. Campus Advisory: Class Suspension & Event Schedule" style="width:100%; height:34px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.80rem; box-sizing:border-box;" />
        </div>

        <!-- Category & Priority -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
          <div>
            <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Category *</label>
            <select name="category" id="annFormCategory" style="width:100%; height:34px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem;">
              <?php if ($sess_role === 'admin'): ?>
                <option value="System Maintenance">System Maintenance</option>
                <option value="Campus Advisory">Campus Advisory</option>
                <option value="Institutional Policy">Institutional Policy</option>
                <option value="Emergency Notice">Emergency Notice</option>
                <option value="Academic Schedule">Academic Schedule</option>
                <option value="General">General Notice</option>
              <?php elseif ($sess_role === 'ssc'): ?>
                <option value="Council Assembly">Council Assembly</option>
                <option value="Governance & Resolutions">Governance &amp; Resolutions</option>
                <option value="Student Elections">Student Elections</option>
                <option value="Campus Activity">Campus Activity</option>
                <option value="Committee Bulletin">Committee Bulletin</option>
                <option value="General">General Notice</option>
              <?php else: ?>
                <option value="Event">Event</option>
                <option value="Activity">Activity</option>
                <option value="Requirement / Submission">Requirement / Submission</option>
                <option value="Meeting">Meeting</option>
                <option value="General">General</option>
              <?php endif; ?>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Priority Level *</label>
            <select name="priority" id="annFormPriority" style="width:100%; height:34px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem;">
              <option value="Normal">Normal</option>
              <option value="Important">Important</option>
              <option value="Urgent">Urgent</option>
            </select>
          </div>
        </div>

        <!-- Target Audience & Channels -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
          <div>
            <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Target Audience *</label>
            <select name="target_group" id="annFormTarget" style="width:100%; height:34px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem;">
              <?php if ($sess_role === 'admin'): ?>
                <option value="All Campus Users">All Campus Users (Institution-wide)</option>
                <option value="All Students">All Students</option>
                <option value="All Club Advisers">All Club Advisers</option>
                <option value="Supreme Student Council (SSC)">Supreme Student Council (SSC)</option>
                <option value="Club Presidents & Student Leaders">Club Presidents &amp; Student Leaders</option>
              <?php elseif ($sess_role === 'ssc'): ?>
                <option value="Student Body (All Enrolled Students)">Student Body (All Enrolled Students)</option>
                <option value="Council Officers & Committee Members">Council Officers &amp; Committee Members</option>
                <option value="Club Presidents & Student Leaders">Club Presidents &amp; Student Leaders</option>
              <?php else: ?>
                <option value="All Members">All Members (Only joined members)</option>
                <option value="Organization Officers">Organization Officers</option>
                <option value="Public">Public (All campus students)</option>
              <?php endif; ?>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Broadcast Channels</label>
            <input type="text" name="channels" id="annFormChannels" value="In-App Notification" placeholder="e.g. In-App, Dashboard Banner" style="width:100%; height:34px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem; box-sizing:border-box;" />
          </div>
        </div>

        <!-- Publication Controls: Status & Pinning (Admin/SSC) -->
        <?php if (in_array($sess_role, ['admin', 'ssc'])): ?>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #e2e8f0;">
            <div>
              <label style="display:block; font-size:0.72rem; font-weight:800; color:#475569; margin-bottom:4px; text-transform:uppercase;">Publication Status</label>
              <select name="status" id="annFormStatus" style="width:100%; height:32px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem; font-weight:700;">
                <option value="Published">Published (Dispatches Alerts)</option>
                <option value="Draft">Draft (Private to Author/Admin)</option>
                <option value="Archived">Archived</option>
              </select>
            </div>

            <div style="display:flex; flex-direction:column; justify-content:center;">
              <label style="display:flex; align-items:center; gap:8px; font-size:0.78rem; font-weight:700; color:#334155; cursor:pointer;">
                <input type="checkbox" name="is_pinned" id="annFormPinned" value="1" style="width:16px; height:16px; cursor:pointer;" />
                <span><i class="fa-solid fa-thumbtack" style="color:#eab308;"></i> Pin to Top of Feed</span>
              </label>
            </div>
          </div>
        <?php else: ?>
          <input type="hidden" name="status" id="annFormStatus" value="Published" />
          <input type="hidden" name="is_pinned" id="annFormPinned" value="0" />
        <?php endif; ?>

        <!-- Content Body -->
        <div style="margin-bottom:16px;">
          <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Notice Content / Announcement Details *</label>
          <textarea name="content" id="annFormContent" rows="5" required placeholder="Write full details, guidelines, venue, directives, or instructions here..." style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.80rem; font-family:inherit; box-sizing:border-box;"></textarea>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px;">
          <button type="button" onclick="closeModal('annModal')" class="card-btn" style="height:34px; padding:0 14px; font-size:0.75rem; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer;">Cancel</button>
          <button type="submit" class="card-btn" style="height:34px; padding:0 16px; font-size:0.75rem; background:#2563eb; color:#fff; font-weight:700; border-radius:6px; border:none; cursor:pointer;">
            <i class="fa-solid fa-paper-plane"></i> Save &amp; Publish
          </button>
        </div>
      </form>
    </div>
  </div>


  <!-- ══════════════════════════════════════════════════════════
       MODAL 2: CREATE / EDIT NOTIFICATION TEMPLATE (ADMIN ONLY)
       ══════════════════════════════════════════════════════════ -->
  <?php if ($sess_role === 'admin'): ?>
    <div class="qr-modal-overlay" id="templateModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
      <div style="background:#fff; border-radius:14px; width:560px; max-width:92vw; padding:22px; box-shadow:0 20px 40px rgba(0,0,0,0.2); max-height:92vh; overflow-y:auto;">
        
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
          <h3 id="tplModalTitleText" style="margin:0; font-size:1.05rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-file-invoice" style="color:#2563eb;"></i> Notification Template
          </h3>
          <button type="button" onclick="closeModal('templateModal')" style="background:none; border:none; font-size:1.1rem; cursor:pointer; color:#64748b;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form id="templateForm" onsubmit="handleTemplateSubmit(event)">
          <input type="hidden" name="id" id="tplFormId" value="0" />

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
            <div>
              <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Template Code</label>
              <input type="text" name="code" id="tplFormCode" placeholder="e.g. TPL-SYS-MAINT" style="width:100%; height:34px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem; font-family:monospace; box-sizing:border-box;" />
            </div>
            <div>
              <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Category *</label>
              <select name="category" id="tplFormCategory" style="width:100%; height:34px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem;">
                <option value="System Notice">System Notice</option>
                <option value="Emergency Alert">Emergency Alert</option>
                <option value="Academic Deadline">Academic Deadline</option>
                <option value="Council & Governance">Council &amp; Governance</option>
                <option value="Elections Notice">Elections Notice</option>
                <option value="Institutional Policy">Institutional Policy</option>
              </select>
            </div>
          </div>

          <div style="margin-bottom:12px;">
            <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Template Title *</label>
            <input type="text" name="title" id="tplFormTitle" required placeholder="e.g. Scheduled Infrastructure Maintenance" style="width:100%; height:34px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.80rem; box-sizing:border-box;" />
          </div>

          <div style="margin-bottom:12px;">
            <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Subject Line Format *</label>
            <input type="text" name="subject_template" id="tplFormSubject" required placeholder="e.g. Notice: Core System Maintenance on [Date]" style="width:100%; height:34px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.80rem; box-sizing:border-box;" />
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
            <div>
              <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Default Priority</label>
              <select name="default_priority" id="tplFormPriority" style="width:100%; height:34px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem;">
                <option value="Normal">Normal</option>
                <option value="Important">Important</option>
                <option value="Urgent">Urgent</option>
              </select>
            </div>

            <div>
              <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Default Target Audience</label>
              <select name="default_target" id="tplFormTarget" style="width:100%; height:34px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem;">
                <option value="All Campus Users">All Campus Users</option>
                <option value="All Students">All Students</option>
                <option value="All Club Advisers">All Club Advisers</option>
                <option value="Club Advisers & Officers">Club Advisers &amp; Officers</option>
              </select>
            </div>
          </div>

          <div style="margin-bottom:16px;">
            <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px;">Body Template Content *</label>
            <textarea name="body_template" id="tplFormBody" rows="5" required placeholder="Write standard boilerplate communication text here with [Placeholders]..." style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.80rem; font-family:inherit; box-sizing:border-box;"></textarea>
          </div>

          <div style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" onclick="closeModal('templateModal')" class="card-btn" style="height:34px; padding:0 14px; font-size:0.75rem; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer;">Cancel</button>
            <button type="submit" class="card-btn" style="height:34px; padding:0 16px; font-size:0.75rem; background:#2563eb; color:#fff; font-weight:700; border-radius:6px; border:none; cursor:pointer;">
              <i class="fa-solid fa-save"></i> Save Template
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>


  <!-- ══════════════════════════════════════════════════════════
       JAVASCRIPT INTERACTIONS (CRUD, FILTERING, TEMPLATES)
       ══════════════════════════════════════════════════════════ -->
  <script>
    // ── Tab Navigation ──
    function switchCommTab(tab) {
      const secNotices = document.getElementById('sectionNotices');
      const secTemplates = document.getElementById('sectionTemplates');
      const secControls = document.getElementById('sectionControls');

      document.querySelectorAll('.comm-nav-tab').forEach(b => b.classList.remove('active'));

      if (tab === 'notices') {
        if (secNotices) secNotices.style.display = 'block';
        if (secTemplates) secTemplates.style.display = 'none';
        if (secControls) secControls.style.display = 'none';
        document.getElementById('tabBtnAnnouncements')?.classList.add('active');
      } else if (tab === 'templates') {
        if (secNotices) secNotices.style.display = 'none';
        if (secTemplates) secTemplates.style.display = 'block';
        if (secControls) secControls.style.display = 'none';
        document.getElementById('tabBtnTemplates')?.classList.add('active');
      } else if (tab === 'controls') {
        if (secNotices) secNotices.style.display = 'none';
        if (secTemplates) secTemplates.style.display = 'none';
        if (secControls) secControls.style.display = 'block';
        document.getElementById('tabBtnControls')?.classList.add('active');
      }
    }

    // ── Modals Handling ──
    function openModal(id) {
      const el = document.getElementById(id);
      if (el) el.style.display = 'flex';
    }
    function closeModal(id) {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    }

    function showAlert(msg, isSuccess) {
      const alertBox = document.getElementById('annAlert');
      if (!alertBox) return;
      alertBox.style.display = 'block';
      alertBox.style.background = isSuccess ? '#dcfce7' : '#fee2e2';
      alertBox.style.color = isSuccess ? '#15803d' : '#991b1b';
      alertBox.style.border = isSuccess ? '1px solid #bbf7d0' : '1px solid #fecaca';
      alertBox.innerHTML = (isSuccess ? '<i class="fa-solid fa-circle-check"></i> ' : '<i class="fa-solid fa-triangle-exclamation"></i> ') + msg;
      setTimeout(() => { alertBox.style.display = 'none'; }, 4500);
    }

    // ── Announcement Modal Logic ──
    function openCreateModal() {
      document.getElementById('annFormId').value = '0';
      document.getElementById('annFormTitle').value = '';
      document.getElementById('annFormContent').value = '';
      document.getElementById('modalTitleText').innerHTML = '<i class="fa-solid fa-bullhorn" style="color:#2563eb;"></i> Post Announcement / Broad Communication';
      handleScopeChange();
      openModal('annModal');
    }

    function openEditModal(ann) {
      document.getElementById('annFormId').value = ann.id;
      document.getElementById('annFormTitle').value = ann.title || '';
      document.getElementById('annFormContent').value = ann.content || '';
      if (document.getElementById('annFormScope')) {
        document.getElementById('annFormScope').value = ann.scope || 'System';
      }
      if (document.getElementById('annFormCategory')) {
        document.getElementById('annFormCategory').value = ann.category || 'General';
      }
      if (document.getElementById('annFormPriority')) {
        document.getElementById('annFormPriority').value = ann.priority || 'Normal';
      }
      if (document.getElementById('annFormTarget')) {
        document.getElementById('annFormTarget').value = ann.target_group || 'All Campus Users';
      }
      if (document.getElementById('annFormStatus')) {
        document.getElementById('annFormStatus').value = ann.status || 'Published';
      }
      if (document.getElementById('annFormPinned')) {
        document.getElementById('annFormPinned').checked = (parseInt(ann.is_pinned) === 1);
      }
      if (document.getElementById('annFormChannels')) {
        document.getElementById('annFormChannels').value = ann.channels || 'In-App';
      }

      handleScopeChange();
      document.getElementById('modalTitleText').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:#2563eb;"></i> Edit Announcement';
      openModal('annModal');
    }

    function handleScopeChange() {
      const scopeEl = document.getElementById('annFormScope');
      const clubWrap = document.getElementById('annClubWrap');
      if (scopeEl && clubWrap) {
        clubWrap.style.display = (scopeEl.value === 'Club') ? 'block' : 'none';
      }
    }

    function applyTemplateToForm(jsonStr) {
      if (!jsonStr) return;
      try {
        const tpl = JSON.parse(jsonStr);
        document.getElementById('annFormTitle').value = tpl.subject_template || tpl.title;
        document.getElementById('annFormContent').value = tpl.body_template || '';
        if (document.getElementById('annFormPriority')) {
          document.getElementById('annFormPriority').value = tpl.default_priority || 'Normal';
        }
        if (document.getElementById('annFormTarget')) {
          document.getElementById('annFormTarget').value = tpl.default_target || 'All Campus Users';
        }
      } catch (e) {}
    }

    function useTemplateToBroadcast(tpl) {
      switchCommTab('notices');
      openCreateModal();
      document.getElementById('annFormTitle').value = tpl.subject_template || tpl.title;
      document.getElementById('annFormContent').value = tpl.body_template || '';
      if (document.getElementById('annFormPriority')) {
        document.getElementById('annFormPriority').value = tpl.default_priority || 'Normal';
      }
      if (document.getElementById('annFormTarget')) {
        document.getElementById('annFormTarget').value = tpl.default_target || 'All Campus Users';
      }
    }

    // ── Submit Announcement Form (Create / Update) ──
    function handleAnnouncementSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('annForm');
      const fd = new FormData(form);
      const isEdit = parseInt(document.getElementById('annFormId').value) > 0;
      fd.append('action', isEdit ? 'update' : 'create');

      fetch('../shared/announcement_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            showAlert(res.message, true);
            closeModal('annModal');
            setTimeout(() => location.reload(), 800);
          } else {
            showAlert(res.message, false);
          }
        })
        .catch(() => showAlert('Network error processing request.', false));
    }

    // ── Publication Controls: Quick Pin & Status ──
    function togglePinAnnouncement(id) {
      const fd = new FormData();
      fd.append('action', 'toggle_pin');
      fd.append('id', id);

      fetch('../shared/announcement_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            showAlert(res.message, true);
            setTimeout(() => location.reload(), 600);
          } else {
            showAlert(res.message, false);
          }
        })
        .catch(() => showAlert('Failed to toggle pin.', false));
    }

    function updateAnnouncementStatus(id, newStatus) {
      const fd = new FormData();
      fd.append('action', 'toggle_status');
      fd.append('id', id);
      fd.append('status', newStatus);

      fetch('../shared/announcement_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            showAlert(res.message, true);
            setTimeout(() => location.reload(), 600);
          } else {
            showAlert(res.message, false);
          }
        })
        .catch(() => showAlert('Failed to update publication status.', false));
    }

    async function deleteAnnouncement(id) {
      const confirmed = await window.showConfirmModal(
        'Delete Announcement?',
        'Do you want to permanently delete this announcement?',
        { type: 'error', danger: true, confirmText: 'Yes, Delete Announcement' }
      );
      if (!confirmed) return;

      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', id);

      fetch('../shared/announcement_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            showAlert(res.message, true);
            setTimeout(() => location.reload(), 600);
          } else {
            showAlert(res.message, false);
          }
        })
        .catch(() => showAlert('Error deleting announcement.', false));
    }

    // ── Template Modal Logic (Admin) ──
    function openTemplateModal(tpl = null) {
      if (tpl) {
        document.getElementById('tplFormId').value = tpl.id;
        document.getElementById('tplFormCode').value = tpl.code || '';
        document.getElementById('tplFormTitle').value = tpl.title || '';
        document.getElementById('tplFormCategory').value = tpl.category || 'System Notice';
        document.getElementById('tplFormSubject').value = tpl.subject_template || '';
        document.getElementById('tplFormBody').value = tpl.body_template || '';
        document.getElementById('tplFormPriority').value = tpl.default_priority || 'Normal';
        document.getElementById('tplFormTarget').value = tpl.default_target || 'All Campus Users';
        document.getElementById('tplModalTitleText').innerHTML = '<i class="fa-solid fa-pen" style="color:#2563eb;"></i> Edit Notification Template';
      } else {
        document.getElementById('tplFormId').value = '0';
        document.getElementById('tplFormCode').value = '';
        document.getElementById('tplFormTitle').value = '';
        document.getElementById('tplFormSubject').value = '';
        document.getElementById('tplFormBody').value = '';
        document.getElementById('tplModalTitleText').innerHTML = '<i class="fa-solid fa-plus-circle" style="color:#2563eb;"></i> Create Notification Template';
      }
      openModal('templateModal');
    }

    function handleTemplateSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('templateForm');
      const fd = new FormData(form);
      fd.append('action', 'save_template');

      fetch('../shared/announcement_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            showAlert(res.message, true);
            closeModal('templateModal');
            setTimeout(() => location.reload(), 800);
          } else {
            showAlert(res.message, false);
          }
        })
        .catch(() => showAlert('Error saving template.', false));
    }

    async function deleteTemplate(id) {
      const confirmed = await window.showConfirmModal(
        'Delete Notification Template?',
        'Do you want to delete this notification template?',
        { type: 'error', danger: true, confirmText: 'Yes, Delete Template' }
      );
      if (!confirmed) return;

      const fd = new FormData();
      fd.append('action', 'delete_template');
      fd.append('id', id);

      fetch('../shared/announcement_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            showAlert(res.message, true);
            setTimeout(() => location.reload(), 600);
          } else {
            showAlert(res.message, false);
          }
        })
        .catch(() => showAlert('Failed to delete template.', false));
    }

    // ── Client-Side Feed Filter ──
    function filterAnnouncementsClient() {
      const q = (document.getElementById('annSearchInput')?.value || '').toLowerCase().trim();
      const scope = document.getElementById('annScopeFilter')?.value || 'all';
      const status = document.getElementById('annStatusFilter')?.value || 'all';
      const prio = document.getElementById('annPriorityFilter')?.value || 'all';

      const cards = document.querySelectorAll('.ann-item-card');
      cards.forEach(card => {
        const cScope = card.dataset.scope || '';
        const cStatus = card.dataset.status || '';
        const cPrio = card.dataset.priority || '';
        const cTitle = card.dataset.title || '';
        const cContent = card.dataset.content || '';

        const matchScope = (scope === 'all' || cScope === scope);
        const matchStatus = (status === 'all' || cStatus === status);
        const matchPrio = (prio === 'all' || cPrio === prio);
        const matchQuery = (!q || cTitle.includes(q) || cContent.includes(q));

        if (matchScope && matchStatus && matchPrio && matchQuery) {
          card.style.display = 'block';
        } else {
          card.style.display = 'none';
        }
      });
    }
  </script>

  <script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
</body>
</html>
