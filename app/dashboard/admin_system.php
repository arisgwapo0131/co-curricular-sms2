<?php
// ============================================================
//  ADMIN_SYSTEM.PHP  (dashboard/)
//  Co-Curricular System — User & Access Management
//  Accessible to: System Admin (Full CRUD)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_permission('users.manage.all');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];
$is_admin     = true;

// -- Live User & Access Statistics (User & Access Management) ──
$user_count        = (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$student_count     = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetch_row()[0];
$adviser_count     = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='club_adviser'")->fetch_row()[0];
$ssc_count         = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='ssc'")->fetch_row()[0];
$admin_count       = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetch_row()[0];
$super_admin_count = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='super_admin'")->fetch_row()[0];
$staff_count       = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='staff'")->fetch_row()[0];
$inactive_count    = (int)$conn->query("SELECT COUNT(*) FROM users WHERE status='Inactive'")->fetch_row()[0];

// -- Fetch all users with profile data and organizational scope ──
$all_users = $conn->query(
    "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.role, u.status, u.last_login, u.last_password_change, u.created_at,
            s.student_number, s.course, s.year_level, s.section, s.status AS student_status,
            (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = u.id AND cm.status = 'Active') AS club_names,
            (SELECT GROUP_CONCAT(COALESCE(c.code, c.name) SEPARATOR ', ') FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = u.id AND cm.status = 'Active') AS club_codes,
            (SELECT GROUP_CONCAT(cm.role SEPARATOR ', ') FROM club_memberships cm WHERE cm.user_id = u.id AND cm.status = 'Active') AS membership_roles
     FROM users u
     LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
     ORDER BY u.role, u.last_name, u.first_name"
)->fetch_all(MYSQLI_ASSOC);

$role_labels = [
    'super_admin'  => 'Super Administrator',
    'admin'        => 'System Admin',
    'staff'        => 'Office of Student Affairs (Staff)',
    'ssc'          => 'Supreme Student Council (SSC)',
    'club_adviser' => 'Faculty Club Adviser',
    'student'      => 'General Student',
];

$academic_programs = [];
$acad_res = $conn->query("SELECT id, code, name FROM academic_programs WHERE status = 'Active' ORDER BY name");
if ($acad_res) {
    $academic_programs = $acad_res->fetch_all(MYSQLI_ASSOC);
}

$active_clubs = [];
$club_res = $conn->query("SELECT id, code, name FROM clubs WHERE status = 'Active' ORDER BY name");
if ($club_res) {
    $active_clubs = $club_res->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>User &amp; Access Management — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <meta name="csrf-token" content="<?= csrf_token() ?>"/>
  <script src="../js/page-loader.js"></script>
  <style>
    .role-badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:0.72rem; font-weight:800; }
    .role-superadmin, .role-super_admin { background:#ede9fe; color:#5b21b6; border: 1px solid #c4b5fd; }
    .role-admin   { background:#fee2e2; color:#991b1b; border: 1px solid #fca5a5; }
    .role-staff   { background:#ccfbf1; color:#115e59; border: 1px solid #99f6e4; }
    .role-ssc     { background:#fef3c7; color:#92400e; border: 1px solid #fde68a; }
    .role-adviser { background:#e0e7ff; color:#3730a3; border: 1px solid #c7d2fe; }
    .role-student { background:#f1f5f9; color:#475569; border: 1px solid #e2e8f0; }

    .status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.72rem; font-weight:700; }
    .status-active { background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; }
    .status-inactive { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }

    .admin-alert { padding:14px 18px; border-radius:10px; margin-bottom:20px; font-size:0.88rem; font-weight:600; display:none; }
    
    /* 6 KPI Cards Grid */
    .kpi-grid-6 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
      gap: 14px;
      margin-bottom: 24px;
    }
    .kpi-grid-6 .info-card {
      background: #ffffff;
      border-radius: 14px;
      padding: 16px 18px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-height: 100px;
    }
    .kpi-grid-6 .info-card .card-label {
      font-size: 0.78rem;
      font-weight: 700;
      color: #64748b;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .kpi-grid-6 .info-card .card-amount {
      font-size: 1.6rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.2;
      margin-bottom: 4px;
    }
    .kpi-grid-6 .info-card .card-detail {
      font-size: 0.75rem;
      color: #64748b;
      font-weight: 500;
    }

    /* User Directory Compact Table & Row Layout */
    #userTable {
      width: 100%;
      border-collapse: collapse;
      table-layout: auto;
    }
    #userTable th {
      padding: 9px 12px;
      font-size: 0.74rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      color: #475569;
      background: #f8fafc;
      border-bottom: 2px solid #e2e8f0;
      white-space: nowrap;
      vertical-align: middle;
    }
    #userTable td {
      padding: 6px 12px;
      vertical-align: middle;
      font-size: 0.82rem;
      border-bottom: 1px solid #f1f5f9;
      white-space: nowrap;
      height: 42px;
      color: #334155;
    }
    #userTable tr:hover td {
      background: #f8fafc;
    }
    #userTable .role-badge {
      padding: 2px 8px;
      font-size: 0.7rem;
      font-weight: 700;
      border-radius: 10px;
      display: inline-block;
      line-height: 1.3;
    }
    #userTable .status-badge {
      padding: 2px 7px;
      font-size: 0.7rem;
      font-weight: 700;
      border-radius: 10px;
      display: inline-block;
      line-height: 1.3;
    }
    #userTable code {
      font-size: 0.78rem;
      padding: 2px 6px;
      background: #f1f5f9;
      color: #0f172a;
      border-radius: 4px;
      border: 1px solid #e2e8f0;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }

    /* Action Buttons in Single Compact Row */
    .user-actions-nowrap {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      flex-wrap: nowrap !important;
      white-space: nowrap !important;
      justify-content: flex-end;
    }
    .btn-action-view {
      height: 28px;
      padding: 0 10px;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 700;
      border: none;
      background: #0284c7;
      color: #fff;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all 0.15s ease;
      white-space: nowrap;
    }
    .btn-action-view:hover {
      background: #0369a1;
      transform: translateY(-1px);
    }
    .btn-action-icon {
      width: 28px;
      height: 28px;
      padding: 0;
      border-radius: 6px;
      font-size: 0.75rem;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s ease;
    }
    .btn-action-icon:hover {
      transform: translateY(-1px);
      filter: brightness(0.92);
    }
    .btn-action-edit {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    .btn-action-edit:hover {
      background: #dbeafe;
      color: #1e40af;
    }
    .btn-action-role {
      background: #f5f3ff;
      color: #6d28d9;
      border: 1px solid #ddd6fe;
    }
    .btn-action-role:hover {
      background: #ede9fe;
      color: #5b21b6;
    }
    .btn-action-reset {
      background: #fffbeb;
      color: #b45309;
      border: 1px solid #fde68a;
    }
    .btn-action-reset:hover {
      background: #fef3c7;
      color: #92400e;
    }
    .btn-action-deactivate {
      background: #fef2f2;
      color: #b91c1c;
      border: 1px solid #fecaca;
    }
    .btn-action-deactivate:hover {
      background: #fee2e2;
      color: #991b1b;
    }
    .btn-action-activate {
      background: #f0fdf4;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }
    .btn-action-activate:hover {
      background: #dcfce7;
      color: #166534;
    }
    .btn-action-audit {
      background: #f8fafc;
      color: #475569;
      border: 1px solid #cbd5e1;
    }
    .btn-action-audit:hover {
      background: #f1f5f9;
      color: #1e293b;
    }
    .btn-action-disabled {
      background: #f1f5f9;
      color: #94a3b8;
      border: 1px solid #e2e8f0;
      cursor: not-allowed;
    }
    .action-tag {
      background: #e2e8f0;
      color: #1e293b;
      padding: 2px 7px;
      border-radius: 4px;
      font-weight: 700;
      font-size: 0.73rem;
    }

    /* Card Pagination Toolbar & Layout */
    .pagination-toolbar {
      display: flex !important;
      align-items: center !important;
      justify-content: space-between !important;
      flex-wrap: wrap !important;
      gap: 12px !important;
      padding: 12px 6px 4px !important;
      margin-top: 10px !important;
      border-top: 1px solid #f1f5f9 !important;
    }
    .pagination-info {
      display: none !important;
    }
    .pagination-controls {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: flex-end !important;
      gap: 14px !important;
      flex-wrap: wrap !important;
      width: auto !important;
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

    /* Modal styles */
    .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:9999; display:none; align-items:center; justify-content:center; padding:16px; }
    .modal-overlay.active { display:flex !important; }
    .modal-card { background:#fff; border-radius:18px; width:100%; max-width:540px; max-height:90vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,0.3); }
    .modal-header { padding:18px 24px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc; border-radius:18px 18px 0 0; }
    .modal-header h3 { margin:0; font-size:1.05rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px; }
    .modal-body { padding:22px 24px; }
    .modal-footer { padding:14px 24px; border-top:1px solid #e2e8f0; display:flex; gap:10px; justify-content:flex-end; background:#f8fafc; border-radius:0 0 18px 18px; }
    
    .form-group-admin { margin-bottom:16px; }
    .form-group-admin label { display:block; font-size:0.75rem; font-weight:800; color:#475569; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.04em; }
    .form-group-admin input, .form-group-admin select, .form-group-admin textarea { width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.88rem; color:#1e293b; background:#fff; font-family:inherit; }
    .form-group-admin input:focus, .form-group-admin select:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.1); }

    /* Slide-in User Profile Drawer */
    .profile-drawer-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(3px);
      z-index: 10000;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.28s ease;
    }
    .profile-drawer-overlay.active {
      opacity: 1;
      pointer-events: auto;
    }
    .profile-drawer {
      position: fixed;
      top: 0;
      right: -500px;
      width: 480px;
      max-width: 95vw;
      height: 100vh;
      background: #ffffff;
      box-shadow: -8px 0 36px rgba(15, 23, 42, 0.2);
      z-index: 10001;
      transition: right 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .profile-drawer.active {
      right: 0;
    }
    .drawer-header {
      padding: 18px 22px;
      border-bottom: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #f8fafc;
    }
    .drawer-body {
      padding: 22px 24px;
      overflow-y: auto;
      flex: 1;
    }
    .drawer-section {
      margin-bottom: 22px;
      padding-bottom: 18px;
      border-bottom: 1px solid #f1f5f9;
    }
    .drawer-section:last-child {
      border-bottom: none;
      margin-bottom: 0;
    }
    .drawer-section-title {
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      color: #64748b;
      text-transform: uppercase;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .drawer-info-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
    }
    .drawer-info-item {
      font-size: 0.82rem;
    }
    .drawer-info-label {
      color: #94a3b8;
      font-size: 0.72rem;
      font-weight: 600;
      margin-bottom: 2px;
    }
    .drawer-info-value {
      color: #0f172a;
      font-weight: 700;
      word-break: break-word;
    }
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'admin_users';
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

    <div class="page-title-bar" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
      <h2 class="page-title">
        <i class="fa-solid fa-users-gear" style="color:#2563eb;"></i>
        User &amp; Access Management
      </h2>
      <div style="font-size:0.8rem; color:#64748b; font-weight:600;">
        Role: <strong style="color:#0f172a;"><?= htmlspecialchars($role_labels[$sess_role] ?? $sess_role) ?></strong>
      </div>
    </div>

    <div class="content-body">

      <!-- Alert Box -->
      <div id="adminAlert" class="admin-alert"></div>

      <!-- 8 User & Access Management Stat Cards -->
      <div class="kpi-grid-6">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-users" style="color:#2563eb;"></i> Total Users</div>
          <div class="card-amount"><?= $user_count ?></div>
          <div class="card-detail">All system accounts.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-user-graduate" style="color:#0284c7;"></i> Students</div>
          <div class="card-amount"><?= $student_count ?></div>
          <div class="card-detail">Student accounts.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-chalkboard-user" style="color:#4f46e5;"></i> Advisers</div>
          <div class="card-amount"><?= $adviser_count ?></div>
          <div class="card-detail">Faculty advisers.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-user-tie" style="color:#d97706;"></i> SSC Officers</div>
          <div class="card-amount"><?= $ssc_count ?></div>
          <div class="card-detail">Council officers.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-user-shield" style="color:#dc2626;"></i> System Admins</div>
          <div class="card-amount"><?= $admin_count ?></div>
          <div class="card-detail">Admin accounts.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-crown" style="color:#7c3aed;"></i> Super Admins</div>
          <div class="card-amount" style="color:#6d28d9;"><?= $super_admin_count ?></div>
          <div class="card-detail">Root authority.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-id-card-clip" style="color:#0d9488;"></i> OSA Staff</div>
          <div class="card-amount" style="color:#0f766e;"><?= $staff_count ?></div>
          <div class="card-detail">Student affairs staff.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-user-slash" style="color:#64748b;"></i> Inactive Accounts</div>
          <div class="card-amount" style="color:<?= $inactive_count > 0 ? '#dc2626' : '#64748b' ?>;"><?= $inactive_count ?></div>
          <div class="card-detail">Disabled accounts.</div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════
           SECTION 1: User Directory Table
      ══════════════════════════════════════════════════════════════ -->
      <div class="card" style="margin-bottom:24px;">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #f1f5f9;">
          <div>
            <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-users-gear" style="color:#2563eb;"></i> User directory</h3>
            <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">Manage user authentication credentials, system roles, and status</p>
          </div>
          <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <input type="text" id="userSearchInput" placeholder="Search name, username, email, program..." style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; min-width:220px;" oninput="filterUserTable()"/>
            <select id="userRoleFilterSelect" style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; font-weight:600;" onchange="filterUserTable()">
              <option value="ALL">All Roles</option>
              <option value="super_admin">Super Administrator</option>
              <option value="admin">System Admin</option>
              <option value="staff">OSA Staff</option>
              <option value="ssc">SSC Officer</option>
              <option value="club_adviser">Club Adviser</option>
              <option value="student">Student</option>
            </select>
            <select id="userStatusFilterSelect" style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; font-weight:600;" onchange="filterUserTable()">
              <option value="ALL">All Statuses</option>
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
            <button type="button" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700;" onclick="openCreateUserModal()">
              <i class="fa-solid fa-user-plus"></i> Add New User
            </button>
            <a href="../shared/admin_actions.php?action=export_users_csv" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700; text-decoration:none;">
              <i class="fa-solid fa-file-csv"></i> Export Users (CSV)
            </a>
          </div>
        </div>

        <div class="table-responsive">
          <table id="userTable" class="table-wide">
            <thead>
              <tr>
                <th style="width:70px; text-align:center;">User ID</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Program / Organization</th>
                <th style="text-align:center;">Account Status</th>
                <th>Last Login</th>
                <th>Created</th>
                <th style="text-align:right; width:220px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($all_users)): ?>
              <tr>
                <td colspan="10" style="text-align:center; padding:32px; color:#94a3b8;">
                  <i class="fa-regular fa-folder-open" style="font-size:2rem; margin-bottom:8px; display:block;"></i>
                  No user records found in the database.
                </td>
              </tr>
              <?php else: ?>
              <tr id="userTableNoMatch" style="display:none;">
                <td colspan="10" style="text-align:center; padding:32px; color:#94a3b8;">
                  <i class="fa-solid fa-magnifying-glass" style="font-size:1.8rem; margin-bottom:8px; display:block; color:#cbd5e1;"></i>
                  No users match the search criteria.
                </td>
              </tr>
              <?php foreach ($all_users as $u): ?>
              <?php 
                $r_cls = str_replace('_', '', $u['role']); 
                $u_status = !empty($u['status']) ? $u['status'] : 'Active';
                $scope_val = '—';
                if ($u['role'] === 'student') {
                  $scope_val = $u['course'] ?? 'Student';
                  if (!empty($u['club_codes'])) {
                    $scope_val .= ' (' . $u['club_codes'] . ')';
                  }
                } elseif ($u['role'] === 'club_adviser') {
                  $scope_val = !empty($u['club_names']) ? $u['club_names'] : 'Faculty Adviser';
                } elseif ($u['role'] === 'ssc') {
                  $scope_val = 'Supreme Student Council';
                } elseif ($u['role'] === 'staff') {
                  $scope_val = 'Office of Student Affairs (Staff)';
                } elseif ($u['role'] === 'super_admin') {
                  $scope_val = 'Root Institutional Authority';
                } else {
                  $scope_val = 'System Administration';
                }
                $search_content = strtolower($u['id'] . ' ' . $u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['username'] . ' ' . $u['email'] . ' ' . $scope_val . ' ' . $u_status);
              ?>
              <tr class="user-row" data-role="<?= $u['role'] ?>" data-status="<?= $u_status ?>" data-search="<?= htmlspecialchars($search_content, ENT_QUOTES) ?>">
                <td style="text-align:center;"><strong>#<?= $u['id'] ?></strong></td>
                <td><strong style="color:#0f172a;"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></strong></td>
                <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                <td><span style="font-size:0.8rem; color:#475569;" title="<?= htmlspecialchars($u['email']) ?>"><?= htmlspecialchars($u['email']) ?></span></td>
                <td>
                  <span class="role-badge role-<?= $r_cls ?>"><?= htmlspecialchars($role_labels[$u['role']] ?? $u['role']) ?></span>
                </td>
                <td>
                  <span style="font-size:0.78rem; color:#475569; max-width:200px; display:inline-block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($scope_val) ?>">
                    <?= htmlspecialchars($scope_val) ?>
                  </span>
                </td>
                <td style="text-align:center;">
                  <span class="status-badge <?= $u_status === 'Active' ? 'status-active' : 'status-inactive' ?>">
                    <i class="fa-solid <?= $u_status === 'Active' ? 'fa-circle-check' : 'fa-circle-xmark' ?>" style="font-size:0.68rem; margin-right:3px;"></i>
                    <?= htmlspecialchars($u_status) ?>
                  </span>
                </td>
                <td>
                  <span style="font-size:0.78rem; color:#64748b;">
                    <?= !empty($u['last_login']) ? date('M d, Y H:i', strtotime($u['last_login'])) : '<span style="color:#94a3b8;">Never</span>' ?>
                  </span>
                </td>
                <td>
                  <span style="font-size:0.78rem; color:#64748b;">
                    <?= !empty($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '—' ?>
                  </span>
                </td>
                <td style="text-align:right;">
                  <div class="user-actions-nowrap">
                    <!-- View (Opens Profile Drawer) -->
                    <button type="button" class="btn-action-view" onclick="openUserProfileDrawer(<?= $u['id'] ?>)" title="View user profile drawer">
                      <i class="fa-solid fa-eye"></i> View
                    </button>
                    <!-- Edit -->
                    <button type="button" class="btn-action-icon btn-action-edit" onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)" title="Edit account details">
                      <i class="fa-solid fa-pen"></i>
                    </button>
                    <!-- Role -->
                    <button type="button" class="btn-action-icon btn-action-role" onclick="openChangeRoleModal(<?= $u['id'] ?>, '<?= $u['role'] ?>', '<?= htmlspecialchars(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?>')" title="Assign system role">
                      <i class="fa-solid fa-user-tag"></i>
                    </button>
                    <!-- Reset -->
                    <button type="button" class="btn-action-icon btn-action-reset" onclick="openResetPasswordModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?>')" title="Reset account password">
                      <i class="fa-solid fa-key"></i>
                    </button>
                    <!-- Deactivate / Activate -->
                    <?php if ($u_status === 'Inactive'): ?>
                      <button type="button" class="btn-action-icon btn-action-activate" onclick="toggleUserStatus(<?= $u['id'] ?>, 'Active', '<?= htmlspecialchars(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?>')" title="Activate account">
                        <i class="fa-solid fa-user-check"></i>
                      </button>
                    <?php else: ?>
                      <?php if ($u['id'] !== $user_id): ?>
                        <button type="button" class="btn-action-icon btn-action-deactivate" onclick="toggleUserStatus(<?= $u['id'] ?>, 'Inactive', '<?= htmlspecialchars(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?>')" title="Deactivate account">
                          <i class="fa-solid fa-user-slash"></i>
                        </button>
                      <?php else: ?>
                        <button type="button" class="btn-action-icon btn-action-disabled" title="You cannot deactivate your own administrative account" disabled>
                          <i class="fa-solid fa-user-slash"></i>
                        </button>
                      <?php endif; ?>
                    <?php endif; ?>
                    <!-- Activity -->
                    <button type="button" class="btn-action-icon btn-action-audit" onclick="openUserActivityModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?>')" title="View recent audit activity trail">
                      <i class="fa-solid fa-clock-rotate-left"></i>
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
    </div>
  </div>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     MODAL 1: Create New User (Admin Only)
───────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="createUserModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user-plus" style="color:#2563eb;"></i> Add New System User</h3>
      <button type="button" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('createUserModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="createUserForm" onsubmit="handleCreateUser(event)" autocomplete="off">
      <div class="modal-body">
        <div id="createUserAlert" style="display:none; padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:0.84rem; font-weight:600; line-height:1.4;"></div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div class="form-group-admin">
            <label>First Name <span style="color:#ef4444;">*</span></label>
            <input type="text" name="first_name" required placeholder="e.g. John"/>
          </div>
          <div class="form-group-admin">
            <label>Last Name <span style="color:#ef4444;">*</span></label>
            <input type="text" name="last_name" required placeholder="e.g. Doe"/>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div class="form-group-admin">
            <label>Username <span style="color:#ef4444;">*</span></label>
            <input type="text" name="username" required placeholder="e.g. john.doe" style="text-transform:lowercase;"/>
          </div>
          <div class="form-group-admin">
            <label>Email Address <span style="color:#ef4444;">*</span></label>
            <input type="email" name="email" required placeholder="e.g. jdoe@bcp.edu.ph"/>
          </div>
        </div>

        <div class="form-group-admin">
          <label>System Role <span style="color:#ef4444;">*</span></label>
          <select name="role" id="newUserRoleSelect" onchange="handleNewUserRoleChange(this.value)">
            <option value="student" selected>General Student</option>
            <option value="club_adviser">Faculty Club Adviser</option>
            <option value="ssc">Supreme Student Council (SSC)</option>
            <option value="staff">Office of Student Affairs (Staff)</option>
            <option value="admin">System Administrator</option>
            <option value="super_admin">Super Administrator</option>
          </select>
        </div>

        <!-- Role Guidance Note -->
        <div id="roleGuidanceNote" style="display:none; padding:10px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:14px; font-size:0.78rem; color:#475569;">
          <i class="fa-solid fa-circle-info" style="color:#2563eb; margin-right:4px;"></i>
          <span id="roleGuidanceText"></span>
        </div>

        <!-- Student-Specific Fields -->
        <div id="studentSpecificFields">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div class="form-group-admin">
              <label>Student ID Number <span style="font-weight:normal; color:#64748b;">(Optional)</span></label>
              <input type="text" name="student_number" placeholder="e.g. 2026-10450"/>
            </div>
            <div class="form-group-admin">
              <label>Year Level</label>
              <select name="year_level">
                <option value="1st Year">1st Year</option>
                <option value="2nd Year">2nd Year</option>
                <option value="3rd Year">3rd Year</option>
                <option value="4th Year">4th Year</option>
              </select>
            </div>
          </div>
          <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px;">
            <div class="form-group-admin">
              <label>Academic Program / Course</label>
              <select name="course" id="createStudentCourseSelect">
                <option value="">-- Select Academic Program --</option>
                <?php foreach ($academic_programs as $ap): ?>
                  <option value="<?= htmlspecialchars($ap['name']) ?>"><?= htmlspecialchars($ap['code'] . ' — ' . $ap['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group-admin">
              <label>Section</label>
              <input type="text" name="section" placeholder="e.g. 1A" value="1A"/>
            </div>
          </div>
        </div>

        <!-- Adviser-Specific Fields -->
        <div id="adviserSpecificFields" style="display:none;">
          <div class="form-group-admin">
            <label>Assigned Club / Organization <span style="font-weight:normal; color:#64748b;">(Optional)</span></label>
            <select name="club_id" id="createAdviserClubSelect">
              <option value="">-- Unassigned (or Select Organization) --</option>
              <?php foreach ($active_clubs as $cl): ?>
                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name'] . ' (' . $cl['code'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
            <span style="font-size:0.72rem; color:#64748b; margin-top:4px; display:block;">Assigning an organization will set this user as the official faculty adviser.</span>
          </div>
        </div>

        <!-- Initial Password with generator & toggle -->
        <div class="form-group-admin">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
            <label style="margin:0;">Initial Password <span style="color:#ef4444;">*</span></label>
            <button type="button" onclick="generateNewUserPassword()" style="background:none; border:none; color:#2563eb; font-size:0.75rem; font-weight:700; cursor:pointer; padding:0; display:flex; align-items:center; gap:4px;">
              <i class="fa-solid fa-key"></i> Auto-Generate
            </button>
          </div>
          <div style="position:relative; display:flex; align-items:center;">
            <input type="password" name="password" id="createUserPasswordInput" placeholder="Enter initial password (min 8 chars)" required minlength="8" style="padding-right:40px; width:100%;"/>
            <button type="button" onclick="togglePasswordVisibility('createUserPasswordInput', this)" style="position:absolute; right:10px; background:none; border:none; color:#64748b; cursor:pointer; font-size:0.9rem;" title="Show/Hide Password">
              <i class="fa-solid fa-eye"></i>
            </button>
          </div>
          <span style="font-size:0.72rem; color:#64748b; margin-top:4px; display:block;">Minimum 8 characters. User can update this anytime via Account Settings.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('createUserModal')">Cancel</button>
        <button type="submit" id="createUserSubmitBtn" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700;">
          <i class="fa-solid fa-check"></i> Create Account
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     MODAL 2: Edit User & Role (Admin Only)
───────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="editUserModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user-pen" style="color:#2563eb;"></i> Edit User Details &amp; Role</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('editUserModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="editUserForm" onsubmit="handleUpdateUser(event)">
      <input type="hidden" name="user_id" id="editUserId"/>
      <div class="modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div class="form-group-admin">
            <label>First Name</label>
            <input type="text" name="first_name" id="editFirstName" required/>
          </div>
          <div class="form-group-admin">
            <label>Last Name</label>
            <input type="text" name="last_name" id="editLastName" required/>
          </div>
        </div>
        <div class="form-group-admin">
          <label>Email Address</label>
          <input type="email" name="email" id="editEmail" required/>
        </div>
        <div class="form-group-admin">
          <label>System Role</label>
          <select name="role" id="editRole">
            <option value="student">General Student</option>
            <option value="club_adviser">Faculty Club Adviser</option>
            <option value="ssc">Supreme Student Council (SSC)</option>
            <option value="staff">Office of Student Affairs (Staff)</option>
            <option value="admin">System Administrator</option>
            <option value="super_admin">Super Administrator</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('editUserModal')">Cancel</button>
        <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700;"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     MODAL 3: Reset Password (Admin Only)
───────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="resetPasswordModal">
  <div class="modal-card" style="max-width:440px;">
    <div class="modal-header" style="background:#fffbeb; border-bottom:1px solid #fde68a;">
      <h3 style="color:#92400e;"><i class="fa-solid fa-key" style="color:#d97706;"></i> Reset User Password</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('resetPasswordModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="resetPasswordForm" onsubmit="handleResetPassword(event)">
      <input type="hidden" name="user_id" id="resetUserId"/>
      <div class="modal-body">
        <p style="font-size:0.85rem; color:#475569; margin-top:0;" id="resetUserNameText">Resetting password for user...</p>
        <div class="form-group-admin">
          <label>New Password</label>
          <input type="password" name="new_password" placeholder="Enter new password (min 8 chars)" required/>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('resetPasswordModal')">Cancel</button>
        <button type="submit" class="card-btn" style="background:#d97706; color:#fff; font-weight:700;"><i class="fa-solid fa-rotate"></i> Reset Password</button>
      </div>
    </form>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     MODAL 3B: Change Role (Admin Only)
───────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="changeRoleModal">
  <div class="modal-card" style="max-width:440px;">
    <div class="modal-header" style="background:#f5f3ff; border-bottom:1px solid #ddd6fe;">
      <h3 style="color:#6d28d9;"><i class="fa-solid fa-user-tag" style="color:#7c3aed;"></i> Assign System Role</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('changeRoleModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="changeRoleForm" onsubmit="handleUpdateRole(event)">
      <input type="hidden" name="user_id" id="roleUserId"/>
      <div class="modal-body">
        <p style="font-size:0.85rem; color:#475569; margin-top:0;" id="roleUserNameText">Modifying role for user...</p>
        <div class="form-group-admin">
          <label>Select System Role <span style="color:#ef4444;">*</span></label>
          <select name="new_role" id="roleSelectField" required>
            <option value="student">General Student</option>
            <option value="club_adviser">Faculty Club Adviser</option>
            <option value="ssc">Supreme Student Council (SSC)</option>
            <option value="staff">Office of Student Affairs (Staff)</option>
            <option value="admin">System Administrator</option>
            <option value="super_admin">Super Administrator</option>
          </select>
        </div>
        <div style="font-size:0.75rem; color:#64748b; background:#f8fafc; border:1px solid #e2e8f0; padding:10px 12px; border-radius:8px; line-height:1.4;">
          <i class="fa-solid fa-circle-info" style="color:#2563eb;"></i> Role changes alter the user's navigational permissions and data access scopes immediately.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('changeRoleModal')">Cancel</button>
        <button type="submit" class="card-btn" style="background:#7c3aed; color:#fff; font-weight:700;"><i class="fa-solid fa-check"></i> Save Role</button>
      </div>
    </form>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     MODAL 3C: User Activity Trail (Admin Only)
───────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="userActivityModal">
  <div class="modal-card" style="max-width:640px;">
    <div class="modal-header">
      <h3><i class="fa-solid fa-clock-rotate-left" style="color:#2563eb;"></i> User Audit Activity Trail</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('userActivityModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
        <strong id="activityUserNameText" style="font-size:0.92rem; color:#0f172a;"></strong>
      </div>
      <div id="userActivityTimeline" style="max-height:380px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px;">
        <p style="text-align:center; color:#94a3b8; padding:20px;">Loading activity...</p>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('userActivityModal')">Close</button>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     SLIDE-IN: User Profile Drawer (Admin Only)
───────────────────────────────────────────────────────────── -->
<div class="profile-drawer-overlay" id="userProfileDrawerOverlay" onclick="closeUserProfileDrawer()"></div>
<div class="profile-drawer" id="userProfileDrawer">
  <div class="drawer-header">
    <div style="display:flex; align-items:center; gap:12px;">
      <div id="drawerAvatar" style="width:38px; height:38px; border-radius:50%; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.95rem; box-shadow:0 2px 6px rgba(37,99,235,0.3);">
        U
      </div>
      <div>
        <h3 id="drawerName" style="margin:0; font-size:1.02rem; font-weight:800; color:#0f172a;">User Profile</h3>
        <span id="drawerRoleBadge" class="role-badge role-student">Student</span>
      </div>
    </div>
    <button style="background:none; border:none; font-size:1.25rem; cursor:pointer; color:#64748b;" onclick="closeUserProfileDrawer()" title="Close Drawer">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>
  <div class="drawer-body" id="drawerBody">
    <!-- ACCOUNT INFORMATION -->
    <div class="drawer-section">
      <div class="drawer-section-title"><i class="fa-solid fa-id-card" style="color:#2563eb;"></i> ACCOUNT INFORMATION</div>
      <div class="drawer-info-grid">
        <div class="drawer-info-item">
          <div class="drawer-info-label">Name</div>
          <div class="drawer-info-value" id="drawerAccName">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Username</div>
          <div class="drawer-info-value" id="drawerAccUsername">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Email</div>
          <div class="drawer-info-value" id="drawerAccEmail">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Role</div>
          <div class="drawer-info-value" id="drawerAccRole">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Status</div>
          <div class="drawer-info-value" id="drawerAccStatus">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Created Date</div>
          <div class="drawer-info-value" id="drawerAccCreated">—</div>
        </div>
        <div class="drawer-info-item" style="grid-column: span 2;">
          <div class="drawer-info-label">Last Login</div>
          <div class="drawer-info-value" id="drawerAccLastLogin">—</div>
        </div>
      </div>
    </div>

    <!-- ACADEMIC / ORGANIZATIONAL -->
    <div class="drawer-section">
      <div class="drawer-section-title"><i class="fa-solid fa-graduation-cap" style="color:#0284c7;"></i> ACADEMIC / ORGANIZATIONAL</div>
      <div class="drawer-info-grid">
        <div class="drawer-info-item">
          <div class="drawer-info-label">Student Number</div>
          <div class="drawer-info-value" id="drawerAcaStudentNo">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Program</div>
          <div class="drawer-info-value" id="drawerAcaProgram">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Year</div>
          <div class="drawer-info-value" id="drawerAcaYear">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Section</div>
          <div class="drawer-info-value" id="drawerAcaSection">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Organization</div>
          <div class="drawer-info-value" id="drawerAcaOrg">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Membership Role</div>
          <div class="drawer-info-value" id="drawerAcaRole">—</div>
        </div>
      </div>
    </div>

    <!-- SECURITY -->
    <div class="drawer-section">
      <div class="drawer-section-title"><i class="fa-solid fa-shield-halved" style="color:#dc2626;"></i> SECURITY</div>
      <div class="drawer-info-grid">
        <div class="drawer-info-item">
          <div class="drawer-info-label">Last Password Change</div>
          <div class="drawer-info-value" id="drawerSecPassChange">—</div>
        </div>
        <div class="drawer-info-item">
          <div class="drawer-info-label">Last Login</div>
          <div class="drawer-info-value" id="drawerSecLastLogin">—</div>
        </div>
        <div class="drawer-info-item" style="grid-column: span 2;">
          <div class="drawer-info-label">Recent IP</div>
          <div class="drawer-info-value" id="drawerSecRecentIp">—</div>
        </div>
      </div>
      <div style="margin-top:14px;">
        <div class="drawer-info-label" style="margin-bottom:6px;">Recent Actions</div>
        <div id="drawerSecRecentActions" style="font-size:0.78rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; max-height:160px; overflow-y:auto;">
          <em>None recorded</em>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="../js/dashboard.js"></script>
<script src="../js/table-pagination.js"></script>
<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

function showAlert(msg, type) {
  const el = document.getElementById('adminAlert');
  if (!el) return;
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
  el.style.color      = type === 'success' ? '#166534' : '#991b1b';
  el.style.border     = `1px solid ${type === 'success' ? '#bbf7d0' : '#fecaca'}`;
  el.textContent = msg;
  setTimeout(() => { el.style.display = 'none'; }, 4000);
}

function openModal(id) { 
  const el = document.getElementById(id);
  if (el) {
    el.style.display = 'flex';
    el.classList.add('active');
  }
}

function closeModal(id) { 
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('active');
    el.style.display = 'none';
  }
}

// Backdrop click closes modal
document.addEventListener('click', function(e) {
  if (e.target && e.target.classList && e.target.classList.contains('modal-overlay')) {
    closeModal(e.target.id);
  }
});

function handleNewUserRoleChange(role) {
  const stWrap = document.getElementById('studentSpecificFields');
  const advWrap = document.getElementById('adviserSpecificFields');
  const noteWrap = document.getElementById('roleGuidanceNote');
  const noteText = document.getElementById('roleGuidanceText');

  if (stWrap) stWrap.style.display = (role === 'student') ? 'block' : 'none';
  if (advWrap) advWrap.style.display = (role === 'club_adviser') ? 'block' : 'none';

  if (noteWrap && noteText) {
    if (role === 'ssc') {
      noteText.textContent = 'Supreme Student Council (SSC) officer account with review, endorsement, and governance permissions.';
      noteWrap.style.display = 'block';
    } else if (role === 'staff') {
      noteText.textContent = 'Office of Student Affairs (OSA) staff account with oversight and validation permissions.';
      noteWrap.style.display = 'block';
    } else if (role === 'admin') {
      noteText.textContent = 'System Administrator account with full operational and user management permissions.';
      noteWrap.style.display = 'block';
    } else if (role === 'super_admin') {
      noteText.textContent = 'Super Administrator account with root institutional access and security controls.';
      noteWrap.style.display = 'block';
    } else {
      noteWrap.style.display = 'none';
    }
  }
}

function generateNewUserPassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
  let pw = 'Bcp2026!';
  for (let i = 0; i < 4; i++) {
    pw += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  const input = document.getElementById('createUserPasswordInput');
  if (input) {
    input.value = pw;
    input.type = 'text';
    const alertBox = document.getElementById('createUserAlert');
    if (alertBox) {
      alertBox.style.display = 'block';
      alertBox.style.background = '#eff6ff';
      alertBox.style.color = '#1d4ed8';
      alertBox.style.border = '1px solid #bfdbfe';
      alertBox.innerHTML = '<i class="fa-solid fa-key" style="margin-right:6px;"></i> Generated password: <strong>' + pw + '</strong> (Password is currently visible)';
    }
  }
}

function togglePasswordVisibility(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const isPw = input.type === 'password';
  input.type = isPw ? 'text' : 'password';
  const icon = btn.querySelector('i');
  if (icon) {
    icon.className = isPw ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
  }
}

function toggleStudentFields(role) {
  handleNewUserRoleChange(role);
}

function filterUserTable() {
  const q = (document.getElementById('userSearchInput')?.value || '').toLowerCase().trim();
  const role = document.getElementById('userRoleFilterSelect')?.value || 'ALL';
  const status = document.getElementById('userStatusFilterSelect')?.value || 'ALL';
  const rows = document.querySelectorAll('.user-row');
  let matchedCount = 0;

  rows.forEach(tr => {
    const s = tr.getAttribute('data-search') || '';
    const r = tr.getAttribute('data-role') || '';
    const st = tr.getAttribute('data-status') || '';
    const matchQ = !q || s.includes(q);
    const matchR = (role === 'ALL') || (r === role);
    const matchSt = (status === 'ALL') || (st === status);
    const isMatch = matchQ && matchR && matchSt;

    if (isMatch) {
      tr.removeAttribute('data-search-hidden');
      matchedCount++;
    } else {
      tr.setAttribute('data-search-hidden', 'true');
      tr.style.setProperty('display', 'none', 'important');
    }
  });

  const noMatchRow = document.getElementById('userTableNoMatch');
  if (noMatchRow) {
    noMatchRow.style.display = (matchedCount === 0 && rows.length > 0) ? '' : 'none';
  }

  const table = document.getElementById('userTable');
  if (table && table._paginator) {
    table._paginator.currentPage = 1;
    table._paginator.render();
  }
}

function initUserDirectoryPagination() {
  const tbl = document.getElementById('userTable');
  if (tbl && window.initTablePagination && !tbl._paginator) {
    window.initTablePagination(tbl, {
      pageSize: 10,
      showPageSizeSelector: false,
      showInfo: false
    });
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initUserDirectoryPagination);
} else {
  initUserDirectoryPagination();
}

function openCreateUserModal() {
  const form = document.getElementById('createUserForm');
  if (form) form.reset();

  const alertBox = document.getElementById('createUserAlert');
  if (alertBox) {
    alertBox.style.display = 'none';
    alertBox.innerHTML = '';
  }

  const submitBtn = document.getElementById('createUserSubmitBtn');
  if (submitBtn) {
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> Create Account';
  }

  const pwInput = document.getElementById('createUserPasswordInput');
  if (pwInput) pwInput.type = 'password';

  const roleSelect = document.getElementById('newUserRoleSelect');
  if (roleSelect) roleSelect.value = 'student';
  handleNewUserRoleChange('student');

  openModal('createUserModal');
}

function handleCreateUser(e) {
  e.preventDefault();
  const form = e.target;
  const submitBtn = document.getElementById('createUserSubmitBtn');
  const alertBox = document.getElementById('createUserAlert');

  if (alertBox) {
    alertBox.style.display = 'none';
    alertBox.innerHTML = '';
  }

  const fd = new FormData(form);
  fd.append('action', 'create_user');
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  const origBtnHtml = submitBtn ? submitBtn.innerHTML : '';
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Creating Account...';
  }

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        if (alertBox) {
          alertBox.style.display = 'block';
          alertBox.style.background = '#dcfce7';
          alertBox.style.color = '#166534';
          alertBox.style.border = '1px solid #bbf7d0';
          alertBox.innerHTML = '<i class="fa-solid fa-circle-check" style="margin-right:6px;"></i> ' + res.message;
        }
        showAlert(res.message, 'success');
        setTimeout(() => {
          closeModal('createUserModal');
          location.reload();
        }, 1000);
      } else {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = origBtnHtml;
        }
        if (alertBox) {
          alertBox.style.display = 'block';
          alertBox.style.background = '#fef2f2';
          alertBox.style.color = '#991b1b';
          alertBox.style.border = '1px solid #fecaca';
          alertBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i> ' + (res.message || 'Failed to create user account.');
        }
        showAlert(res.message || 'Failed to create user account.', 'error');
      }
    })
    .catch(err => {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origBtnHtml;
      }
      if (alertBox) {
        alertBox.style.display = 'block';
        alertBox.style.background = '#fef2f2';
        alertBox.style.color = '#991b1b';
        alertBox.style.border = '1px solid #fecaca';
        alertBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i> Communication error while connecting to server. Please try again.';
      }
      showAlert('Network error while connecting to server.', 'error');
    });
}

function openEditUserModal(u) {
  document.getElementById('editUserId').value = u.id;
  document.getElementById('editFirstName').value = u.first_name;
  document.getElementById('editLastName').value = u.last_name;
  document.getElementById('editEmail').value = u.email;
  document.getElementById('editRole').value = u.role;
  openModal('editUserModal');
}

function handleUpdateUser(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'update_user');
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert(res.message, 'success');
        closeModal('editUserModal');
        setTimeout(() => location.reload(), 1200);
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

function openResetPasswordModal(id, name) {
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetUserNameText').textContent = `Resetting password for: ${name}`;
  openModal('resetPasswordModal');
}

function handleResetPassword(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'reset_password');
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert(res.message, 'success');
        closeModal('resetPasswordModal');
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

async function deleteUser(id, name) {
  const confirmed = await window.showConfirmModal(
    'Delete User Account?',
    `Do you want to permanently delete the user account for "${name}"?`,
    { type: 'error', danger: true, confirmText: 'Yes, Delete User' }
  );
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action', 'delete_user');
  fd.append('user_id', id);
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert('User deleted successfully.', 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

// ── User Profile Drawer & Lifecycle Handlers ──────────────────
function openUserProfileDrawer(userId) {
  document.getElementById('userProfileDrawerOverlay')?.classList.add('active');
  document.getElementById('userProfileDrawer')?.classList.add('active');

  document.getElementById('drawerName').textContent = 'Loading profile...';
  document.getElementById('drawerAccName').textContent = 'Loading...';

  fetch(`../shared/admin_actions.php?action=get_user_profile&user_id=${userId}`)
    .then(r => r.json())
    .then(res => {
      if (!res.success || !res.user) {
        showAlert(res.message || 'Failed to load profile details.', 'error');
        closeUserProfileDrawer();
        return;
      }
      const u = res.user;
      const fullName = `${u.first_name} ${u.last_name}`;
      
      document.getElementById('drawerName').textContent = fullName;
      const avatarEl = document.getElementById('drawerAvatar');
      if (avatarEl) avatarEl.textContent = (u.first_name ? u.first_name[0] : 'U').toUpperCase();
      
      const roleMap = {
        'student': 'General Student',
        'club_adviser': 'Faculty Club Adviser',
        'ssc': 'Supreme Student Council',
        'staff': 'Office of Student Affairs (Staff)',
        'admin': 'System Administrator',
        'super_admin': 'Super Administrator'
      };
      const badgeEl = document.getElementById('drawerRoleBadge');
      if (badgeEl) {
        badgeEl.className = `role-badge role-${u.role.replace('_', '')}`;
        badgeEl.textContent = roleMap[u.role] || u.role;
      }

      // 1. ACCOUNT INFORMATION
      document.getElementById('drawerAccName').textContent = fullName;
      document.getElementById('drawerAccUsername').textContent = u.username;
      document.getElementById('drawerAccEmail').textContent = u.email;
      document.getElementById('drawerAccRole').textContent = roleMap[u.role] || u.role;
      document.getElementById('drawerAccStatus').innerHTML = `
        <span class="status-badge ${u.status === 'Active' ? 'status-active' : 'status-inactive'}">
          ${u.status || 'Active'}
        </span>
      `;
      document.getElementById('drawerAccCreated').textContent = u.created_at ? new Date(u.created_at).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : '—';
      document.getElementById('drawerAccLastLogin').textContent = u.last_login ? new Date(u.last_login).toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'numeric'}) : 'Never authenticated';

      // 2. ACADEMIC / ORGANIZATIONAL
      document.getElementById('drawerAcaStudentNo').textContent = u.student_number || 'N/A';
      document.getElementById('drawerAcaProgram').textContent = u.course || 'N/A';
      document.getElementById('drawerAcaYear').textContent = u.year_level || 'N/A';
      document.getElementById('drawerAcaSection').textContent = u.section || 'N/A';
      document.getElementById('drawerAcaOrg').textContent = u.club_names || (u.role === 'club_adviser' ? 'Faculty Assignment' : (u.role === 'ssc' ? 'Supreme Student Council' : 'None'));
      document.getElementById('drawerAcaRole').textContent = u.membership_roles || (u.role === 'student' ? 'Member' : (roleMap[u.role] || 'Staff'));

      // 3. SECURITY
      document.getElementById('drawerSecPassChange').textContent = u.last_password_change ? new Date(u.last_password_change).toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'numeric'}) : 'Standard / Default';
      document.getElementById('drawerSecLastLogin').textContent = u.last_login ? new Date(u.last_login).toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'numeric'}) : 'Never';
      document.getElementById('drawerSecRecentIp').textContent = res.recent_ip || '127.0.0.1 (Localhost)';

      const recActionsDiv = document.getElementById('drawerSecRecentActions');
      if (recActionsDiv) {
        if (!res.recent_actions || res.recent_actions.length === 0) {
          recActionsDiv.innerHTML = '<span style="color:#94a3b8; font-style:italic;">No recorded audit trail entries for this account.</span>';
        } else {
          recActionsDiv.innerHTML = res.recent_actions.map(a => `
            <div style="padding:6px 0; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
              <div>
                <strong style="color:#0f172a; font-size:0.76rem;">${a.action}</strong>
                <div style="color:#64748b; font-size:0.72rem;">${a.detail || ''}</div>
              </div>
              <span style="color:#94a3b8; font-size:0.7rem; white-space:nowrap;">${a.created_at}</span>
            </div>
          `).join('');
        }
      }
    })
    .catch(() => {
      showAlert('Failed to contact server for user profile.', 'error');
      closeUserProfileDrawer();
    });
}

function closeUserProfileDrawer() {
  document.getElementById('userProfileDrawerOverlay')?.classList.remove('active');
  document.getElementById('userProfileDrawer')?.classList.remove('active');
}

async function toggleUserStatus(userId, newStatus, userName) {
  const actionWord = newStatus === 'Active' ? 'activate' : 'deactivate';
  const confirmed = await window.showConfirmModal(
    `${newStatus === 'Active' ? 'Activate' : 'Deactivate'} User Account?`,
    `Do you want to ${actionWord} the account for "${userName}"?`,
    { type: newStatus === 'Active' ? 'decision' : 'warning', warning: newStatus !== 'Active', confirmText: `Yes, ${actionWord.charAt(0).toUpperCase() + actionWord.slice(1)}` }
  );
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action', 'toggle_status');
  fd.append('user_id', userId);
  fd.append('status', newStatus);
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert(res.message, 'success');
        setTimeout(() => location.reload(), 900);
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error while toggling account status.', 'error'));
}

function openChangeRoleModal(userId, currentRole, userName) {
  document.getElementById('roleUserId').value = userId;
  document.getElementById('roleUserNameText').textContent = `Assigning new system role for: ${userName}`;
  document.getElementById('roleSelectField').value = currentRole;
  openModal('changeRoleModal');
}

function handleUpdateRole(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'update_role');
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert(res.message, 'success');
        closeModal('changeRoleModal');
        setTimeout(() => location.reload(), 1000);
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error while updating role.', 'error'));
}

function openUserActivityModal(userId, userName) {
  document.getElementById('activityUserNameText').textContent = `Audit Log History: ${userName}`;
  const el = document.getElementById('userActivityTimeline');
  el.innerHTML = '<p style="text-align:center; color:#94a3b8; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Fetching activity trail...</p>';
  openModal('userActivityModal');

  fetch(`../shared/admin_actions.php?action=get_user_activity&user_id=${userId}`)
    .then(r => r.json())
    .then(res => {
      if (!res.success || !res.activity || res.activity.length === 0) {
        el.innerHTML = '<p style="text-align:center; color:#94a3b8; padding:24px;">No logged activities found for this user account.</p>';
        return;
      }
      el.innerHTML = res.activity.map(a => `
        <div style="padding:10px 12px; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
          <div>
            <strong style="color:#0f172a; font-size:0.84rem;">${a.action}</strong>
            <div style="color:#475569; font-size:0.78rem; margin-top:2px;">${a.detail || '—'}</div>
            <div style="color:#94a3b8; font-size:0.72rem; margin-top:2px;">Target: <code>${a.target_table} #${a.target_id || ''}</code> &bull; IP: ${a.ip_address || '127.0.0.1'}</div>
          </div>
          <span style="color:#64748b; font-size:0.74rem; font-weight:600; white-space:nowrap;">${a.created_at}</span>
        </div>
      `).join('');
    })
    .catch(() => {
      el.innerHTML = '<p style="text-align:center; color:#dc2626; padding:20px;">Failed to load activity log.</p>';
    });
}

</script>
</body>
</html>
