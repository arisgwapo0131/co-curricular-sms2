<?php
// ============================================================
//  ADMIN_SECURITY.PHP  (dashboard/)
//  Co-Curricular System — 6.11 Security & Access Monitoring
//  Accessible to: System Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_permission('audit.manage.full');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// ── 6 Core Security Metric Cards (Dynamic Database Queries) ─────
// 1. Failed Login Attempts: Recent failed authentication attempts
$failed_logins_res   = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('AUTH_LOGIN_FAILED', 'login_failed', 'FAILED_LOGIN', 'AUTH_MFA_FAILED') OR action LIKE '%login_failed%' OR action LIKE '%mfa_failed%'");
$failed_logins_count = ($failed_logins_res && $r = $failed_logins_res->fetch_row()) ? (int)$r[0] : 0;

// 2. Suspicious Activity: Configured security alerts
$suspicious_res   = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE severity = 'critical' OR action IN ('SUSPICIOUS_ACTIVITY', 'UNAUTHORIZED_ACCESS', 'CSRF_VIOLATION') OR action LIKE '%suspicious%'");
$suspicious_count = ($suspicious_res && $r = $suspicious_res->fetch_row()) ? (int)$r[0] : 0;

// 3. Password Resets: Password reset activity
$password_resets_res   = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('PASSWORD_RESET', 'reset_password', 'password_change') OR action LIKE '%password%'");
$password_resets_count = ($password_resets_res && $r = $password_resets_res->fetch_row()) ? (int)$r[0] : 0;

// 4. Role Changes: Role assignment / change events
$role_changes_res   = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('ROLE_CHANGE', 'update_role', 'role_assignment') OR action LIKE '%role%'");
$role_changes_count = ($role_changes_res && $r = $role_changes_res->fetch_row()) ? (int)$r[0] : 0;

// 5. Admin Actions: Sensitive administrator actions
$admin_actions_res   = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'ADMIN_%' OR action LIKE '%admin%' OR action IN ('create_user', 'delete_user', 'admin_export_audit_csv', 'SYSTEM_RESET', 'GENERATE_REPORT')");
$admin_actions_count = ($admin_actions_res && $r = $admin_actions_res->fetch_row()) ? (int)$r[0] : 0;

// 6. Recent Overrides: Workflow overrides
$overrides_res   = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%override%' OR action IN ('WORKFLOW_OVERRIDE', 'attendance_override', 'override_budget')");
$overrides_count = ($overrides_res && $r = $overrides_res->fetch_row()) ? (int)$r[0] : 0;

// ── Query Security Events Audit Trail ────────────────────────
$sec_logs_res = $conn->query(
    "SELECT al.*, 
            u.username, u.first_name, u.last_name, u.role as user_role, u.email,
            res_u.first_name as res_first, res_u.last_name as res_last
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     LEFT JOIN users res_u ON res_u.id = al.resolved_by
     ORDER BY al.created_at DESC"
);
$sec_logs = $sec_logs_res ? $sec_logs_res->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Security &amp; Access Monitoring — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <meta name="csrf-token" content="<?= csrf_token() ?>"/>
  <script src="../js/page-loader.js"></script>
  <style>
    /* 6 Core Cards Grid */
    .kpi-grid-6 {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 12px;
      margin-bottom: 20px;
    }
    @media (max-width: 1360px) {
      .kpi-grid-6 { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 768px) {
      .kpi-grid-6 { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 480px) {
      .kpi-grid-6 { grid-template-columns: 1fr; }
    }

    .security-kpi-card {
      background: #ffffff;
      border-radius: 14px;
      padding: 14px 16px;
      border: 1.5px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-height: 122px;
      transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }
    .security-kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(0,0,0,0.06);
      border-color: #cbd5e1;
    }
    .security-kpi-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 6px;
    }
    .security-kpi-icon {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.85rem;
      flex-shrink: 0;
    }
    .security-kpi-title {
      font-size: 0.72rem;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      line-height: 1.2;
    }
    .security-kpi-val {
      font-size: 1.55rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.15;
      margin-bottom: 4px;
    }
    .security-kpi-desc {
      font-size: 0.71rem;
      color: #64748b;
      line-height: 1.35;
      font-weight: 500;
    }

    /* Card Themes */
    .card-red .security-kpi-icon { background: rgba(239, 68, 68, 0.12); color: #dc2626; }
    .card-amber .security-kpi-icon { background: rgba(245, 158, 11, 0.12); color: #d97706; }
    .card-indigo .security-kpi-icon { background: rgba(99, 102, 241, 0.12); color: #4f46e5; }
    .card-blue .security-kpi-icon { background: rgba(37, 99, 235, 0.12); color: #2563eb; }
    .card-purple .security-kpi-icon { background: rgba(139, 92, 246, 0.12); color: #7c3aed; }
    .card-emerald .security-kpi-icon { background: rgba(5, 150, 105, 0.12); color: #059669; }

    /* Security Events Section */
    .sec-table-container {
      background: #ffffff;
      border-radius: 16px;
      border: 1.5px solid #e2e8f0;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0,0,0,0.03);
      margin-bottom: 24px;
    }
    .sec-table-header {
      padding: 16px 20px;
      background: #ffffff;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }
    .sec-table-title {
      font-size: 0.95rem;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* Filter Controls Bar */
    .sec-filter-bar {
      padding: 12px 20px;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
    }
    .sec-filter-group {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .sec-search-input {
      height: 36px;
      padding: 0 12px 0 34px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      font-size: 0.82rem;
      background: #ffffff;
      color: #0f172a;
      min-width: 220px;
      outline: none;
      transition: border-color 0.15s ease;
    }
    .sec-search-input:focus { border-color: #2563eb; }
    .sec-search-wrap {
      position: relative;
      display: inline-flex;
      align-items: center;
    }
    .sec-search-icon {
      position: absolute;
      left: 11px;
      font-size: 0.78rem;
      color: #94a3b8;
      pointer-events: none;
    }
    .sec-select {
      height: 36px;
      padding: 0 10px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      font-size: 0.81rem;
      background: #ffffff;
      color: #0f172a;
      outline: none;
      cursor: pointer;
    }

    /* Compact Security Events Table */
    .table-wrap-security {
      width: 100%;
      overflow-x: auto;
      scrollbar-width: thin;
      scrollbar-color: #cbd5e1 #f8fafc;
    }
    .table-security {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.81rem;
      text-align: left;
    }
    .table-security th {
      background: #f8fafc;
      color: #475569;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      padding: 10px 14px;
      border-bottom: 1.5px solid #cbd5e1;
      white-space: nowrap;
    }
    .table-security td {
      padding: 10px 14px;
      border-bottom: 1px solid #f1f5f9;
      color: #1e293b;
      vertical-align: middle;
      font-size: 0.81rem;
    }
    .table-security tbody tr:hover td {
      background: #f8fafc;
    }

    /* Event Badges */
    .event-code-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 700;
      font-family: monospace;
      letter-spacing: 0.02em;
    }
    .event-auth-fail { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
    .event-suspicious { background: #fff1f2; color: #e11d48; border: 1px solid #ffe4e6; }
    .event-password { background: #eef2ff; color: #4338ca; border: 1px solid #e0e7ff; }
    .event-role { background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe; }
    .event-admin { background: #faf5ff; color: #7e22ce; border: 1px solid #f3e8ff; }
    .event-override { background: #ecfdf5; color: #047857; border: 1px solid #d1fae5; }
    .event-general { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }

    /* Severity Badges */
    .badge-sev {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 0.70rem;
      font-weight: 700;
      text-transform: capitalize;
    }
    .badge-sev-info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .badge-sev-warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .badge-sev-critical { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

    /* Status Badge */
    .badge-res {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 7px;
      border-radius: 10px;
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: capitalize;
    }
    .badge-res-resolved { background: #ecfdf5; color: #047857; }
    .badge-res-investigating { background: #fffbeb; color: #b45309; }
    .badge-res-unresolved { background: #fef2f2; color: #dc2626; }

    /* IP Badge */
    .ip-box {
      font-family: monospace;
      font-size: 0.77rem;
      color: #334155;
      background: #f8fafc;
      padding: 3px 7px;
      border-radius: 5px;
      border: 1px solid #e2e8f0;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    /* Action Buttons in Table */
    .btn-action-group {
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .btn-tbl-action {
      border: none;
      border-radius: 6px;
      padding: 4px 8px;
      font-size: 0.74rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.15s ease;
    }
    .btn-inspect { background: #f1f5f9; color: #334155; }
    .btn-inspect:hover { background: #e2e8f0; color: #0f172a; }
    .btn-investigate { background: #eff6ff; color: #2563eb; }
    .btn-investigate:hover { background: #dbeafe; }
    .btn-resolve { background: #ecfdf5; color: #059669; }
    .btn-resolve:hover { background: #d1fae5; }

    /* Pagination Footer */
    .sec-pagination {
      padding: 12px 20px;
      background: #ffffff;
      border-top: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      font-size: 0.80rem;
      color: #64748b;
    }
    .sec-pager-btns {
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .sec-page-btn {
      min-width: 30px;
      height: 30px;
      padding: 0 8px;
      border-radius: 6px;
      border: 1px solid #cbd5e1;
      background: #ffffff;
      color: #334155;
      font-size: 0.78rem;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s ease;
    }
    .sec-page-btn:hover:not(:disabled) {
      background: #f1f5f9;
      color: #0f172a;
    }
    .sec-page-btn.active {
      background: #2563eb;
      color: #ffffff;
      border-color: #2563eb;
    }
    .sec-page-btn:disabled {
      opacity: 0.4;
      cursor: not-allowed;
    }

    /* Modal Dialogs */
    .sec-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(5px);
      -webkit-backdrop-filter: blur(5px);
      z-index: 99999;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      box-sizing: border-box;
    }
    .sec-modal-overlay.active {
      display: flex !important;
    }
    .sec-modal-card {
      background: #ffffff;
      border-radius: 16px;
      width: 100%;
      max-width: 680px;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.35);
      overflow: hidden;
      animation: modalPop 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes modalPop {
      0% { opacity: 0; transform: scale(0.95) translateY(10px); }
      100% { opacity: 1; transform: scale(1) translateY(0); }
    }
    .sec-modal-header {
      background: #0f172a;
      padding: 16px 22px;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
    }
    .sec-modal-header h3 {
      margin: 0;
      font-size: 1.02rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .sec-modal-body {
      padding: 22px;
      overflow-y: auto;
      flex: 1;
    }
    .sec-modal-footer {
      padding: 14px 22px;
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      flex-shrink: 0;
    }

    /* Inspect Key-Value Grid */
    .sec-kv-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-bottom: 16px;
    }
    .sec-kv-item {
      background: #f8fafc;
      padding: 10px 14px;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
    }
    .sec-kv-label {
      font-size: 0.69rem;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      margin-bottom: 3px;
    }
    .sec-kv-val {
      font-size: 0.85rem;
      font-weight: 700;
      color: #0f172a;
      word-break: break-all;
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
$ACTIVE_NAV = 'admin_security';
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

    <!-- Page Title Bar -->
    <div class="page-title-bar" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
      <div>
        <h2 class="page-title" style="margin:0; font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:10px;">
          <i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i>
          Security &amp; Access Monitoring
        </h2>
        <div style="font-size:0.80rem; color:#64748b; margin-top:3px;">
          Central Security Intelligence &bull; Failed Authentication Telemetry &bull; Privilege Audits
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" onclick="exportSecurityLogsCSV()" style="height:36px; padding:0 12px; font-size:0.82rem; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-file-csv"></i> Export CSV
        </button>
        <button type="button" class="btn btn-secondary" onclick="location.reload()" style="height:36px; padding:0 12px; font-size:0.82rem; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-arrows-rotate"></i> Refresh
        </button>
      </div>
    </div>

    <div class="content-body">

      <!-- 6 Security Metric Cards -->
      <div class="kpi-grid-6">
        <!-- 1. Failed Login Attempts -->
        <div class="security-kpi-card card-red">
          <div>
            <div class="security-kpi-header">
              <span class="security-kpi-title">Failed Login Attempts</span>
              <div class="security-kpi-icon"><i class="fa-solid fa-user-xmark"></i></div>
            </div>
            <div class="security-kpi-val"><?= $failed_logins_count ?></div>
          </div>
          <div class="security-kpi-desc">Recent failed authentication attempts.</div>
        </div>

        <!-- 2. Suspicious Activity -->
        <div class="security-kpi-card card-amber">
          <div>
            <div class="security-kpi-header">
              <span class="security-kpi-title">Suspicious Activity</span>
              <div class="security-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            </div>
            <div class="security-kpi-val"><?= $suspicious_count ?></div>
          </div>
          <div class="security-kpi-desc">Configured security alerts.</div>
        </div>

        <!-- 3. Password Resets -->
        <div class="security-kpi-card card-indigo">
          <div>
            <div class="security-kpi-header">
              <span class="security-kpi-title">Password Resets</span>
              <div class="security-kpi-icon"><i class="fa-solid fa-key"></i></div>
            </div>
            <div class="security-kpi-val"><?= $password_resets_count ?></div>
          </div>
          <div class="security-kpi-desc">Password reset activity.</div>
        </div>

        <!-- 4. Role Changes -->
        <div class="security-kpi-card card-blue">
          <div>
            <div class="security-kpi-header">
              <span class="security-kpi-title">Role Changes</span>
              <div class="security-kpi-icon"><i class="fa-solid fa-user-gear"></i></div>
            </div>
            <div class="security-kpi-val"><?= $role_changes_count ?></div>
          </div>
          <div class="security-kpi-desc">Role assignment / change events.</div>
        </div>

        <!-- 5. Admin Actions -->
        <div class="security-kpi-card card-purple">
          <div>
            <div class="security-kpi-header">
              <span class="security-kpi-title">Admin Actions</span>
              <div class="security-kpi-icon"><i class="fa-solid fa-user-shield"></i></div>
            </div>
            <div class="security-kpi-val"><?= $admin_actions_count ?></div>
          </div>
          <div class="security-kpi-desc">Sensitive administrator actions.</div>
        </div>

        <!-- 6. Recent Overrides -->
        <div class="security-kpi-card card-emerald">
          <div>
            <div class="security-kpi-header">
              <span class="security-kpi-title">Recent Overrides</span>
              <div class="security-kpi-icon"><i class="fa-solid fa-arrow-right-arrow-left"></i></div>
            </div>
            <div class="security-kpi-val"><?= $overrides_count ?></div>
          </div>
          <div class="security-kpi-desc">Workflow overrides.</div>
        </div>
      </div>

      <!-- Security Events Table Container -->
      <div class="sec-table-container">
        
        <div class="sec-table-header">
          <div class="sec-table-title">
            <i class="fa-solid fa-clipboard-list" style="color:#2563eb;"></i>
            <span>Security &amp; Authorization Events Log</span>
            <span style="font-size:0.75rem; font-weight:600; color:#64748b; background:#f1f5f9; padding:2px 8px; border-radius:12px;" id="totalEventsBadge">
              <?= count($sec_logs) ?> Events Recorded
            </span>
          </div>
        </div>

        <!-- Filter & Search Controls -->
        <div class="sec-filter-bar">
          <div class="sec-filter-group">
            <div class="sec-search-wrap">
              <i class="fa-solid fa-magnifying-glass sec-search-icon"></i>
              <input type="text" class="sec-search-input" id="tableSearchInput" placeholder="Search user, event, IP, details..." oninput="handleTableFilter()"/>
            </div>

            <select class="sec-select" id="severityFilter" onchange="handleTableFilter()">
              <option value="">All Severities</option>
              <option value="critical">Critical</option>
              <option value="warning">Warning</option>
              <option value="info">Informational</option>
            </select>

            <select class="sec-select" id="eventCategoryFilter" onchange="handleTableFilter()">
              <option value="">All Event Categories</option>
              <option value="AUTH_LOGIN_FAILED">Failed Logins</option>
              <option value="SUSPICIOUS_ACTIVITY">Suspicious Alerts</option>
              <option value="PASSWORD_RESET">Password Resets</option>
              <option value="ROLE_CHANGE">Role Changes</option>
              <option value="ADMIN_">Admin Actions</option>
              <option value="OVERRIDE">Overrides</option>
            </select>

            <select class="sec-select" id="statusFilter" onchange="handleTableFilter()">
              <option value="">All Statuses</option>
              <option value="unresolved">Unresolved</option>
              <option value="investigating">Investigating</option>
              <option value="resolved">Resolved</option>
            </select>
          </div>

          <div style="display:none;" id="filterCountDisplay"></div>
        </div>

        <!-- Table View -->
        <div class="table-wrap-security">
          <table class="table-security" id="securityEventsTable">
            <thead>
              <tr>
                <th style="width: 140px;">Timestamp</th>
                <th style="width: 190px;">User</th>
                <th style="width: 180px;">Event</th>
                <th style="width: 130px;">IP</th>
                <th style="width: 110px;">Severity</th>
                <th style="width: 180px; text-align: right;">Action</th>
              </tr>
            </thead>
            <tbody id="securityTableBody">
              <?php if (empty($sec_logs)): ?>
                <tr>
                  <td colspan="6" style="text-align:center; padding:35px 20px; color:#94a3b8;">
                    <i class="fa-solid fa-shield-check fa-2x" style="color:#cbd5e1; margin-bottom:8px; display:block;"></i>
                    No security events or alerts recorded in the system.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($sec_logs as $idx => $sl): ?>
                  <?php
                    $sev = strtolower($sl['severity'] ?? 'info');
                    $res_status = strtolower($sl['resolution_status'] ?? 'unresolved');
                    $action_name = $sl['action'] ?? '';
                    
                    // Event Code Badge Class
                    $event_class = 'event-general';
                    $event_icon = 'fa-tag';
                    if (stripos($action_name, 'fail') !== false || stripos($action_name, 'auth') !== false) {
                        $event_class = 'event-auth-fail';
                        $event_icon = 'fa-lock';
                    } elseif (stripos($action_name, 'suspicious') !== false || stripos($action_name, 'threat') !== false || $sev === 'critical') {
                        $event_class = 'event-suspicious';
                        $event_icon = 'fa-triangle-exclamation';
                    } elseif (stripos($action_name, 'password') !== false) {
                        $event_class = 'event-password';
                        $event_icon = 'fa-key';
                    } elseif (stripos($action_name, 'role') !== false) {
                        $event_class = 'event-role';
                        $event_icon = 'fa-user-gear';
                    } elseif (stripos($action_name, 'admin') !== false) {
                        $event_class = 'event-admin';
                        $event_icon = 'fa-user-shield';
                    } elseif (stripos($action_name, 'override') !== false) {
                        $event_class = 'event-override';
                        $event_icon = 'fa-arrow-right-arrow-left';
                    }

                    // User presentation
                    $displayName = !empty($sl['first_name']) ? trim($sl['first_name'] . ' ' . $sl['last_name']) : (!empty($sl['user_display']) ? $sl['user_display'] : 'System / Guest');
                    $username = !empty($sl['username']) ? ('@' . $sl['username']) : (!empty($sl['user_display']) ? $sl['user_display'] : 'Unauthenticated');
                    $roleLabel = !empty($sl['user_role']) ? ucfirst(str_replace('_', ' ', $sl['user_role'])) : 'External';

                    // Severity text & icon
                    $sev_lbl = match($sev) {
                        'critical' => 'Critical',
                        'warning'  => 'Warning',
                        default    => 'Informational',
                    };
                    $sev_icon = match($sev) {
                        'critical' => 'fa-circle-exclamation',
                        'warning'  => 'fa-triangle-exclamation',
                        default    => 'fa-circle-info',
                    };

                    // JSON encoded payload for Inspect modal
                    $jsonPayload = htmlspecialchars(json_encode([
                        'id'                => $sl['id'],
                        'timestamp'         => date('M d, Y H:i:s', strtotime($sl['created_at'])),
                        'user_name'         => $displayName,
                        'username'          => $username,
                        'user_role'         => $roleLabel,
                        'user_id'           => $sl['user_id'] ?: 'N/A',
                        'email'             => $sl['email'] ?? 'N/A',
                        'event'             => $action_name,
                        'target_table'      => $sl['target_table'] ?: 'system',
                        'target_id'         => $sl['target_id'] ?: '0',
                        'ip'                => $sl['ip_address'] ?? '127.0.0.1',
                        'severity'          => $sev_lbl,
                        'detail'            => $sl['detail'] ?: 'No additional payload provided.',
                        'resolution_status' => ucfirst($res_status),
                        'resolution_notes'  => $sl['resolution_notes'] ?: 'None recorded.',
                        'resolved_by'       => !empty($sl['res_first']) ? ($sl['res_first'] . ' ' . $sl['res_last']) : ($sl['resolved_by'] ? 'Admin #' . $sl['resolved_by'] : 'Unassigned'),
                        'resolved_at'       => !empty($sl['resolved_at']) ? date('M d, Y H:i', strtotime($sl['resolved_at'])) : 'Pending'
                    ]), ENT_QUOTES, 'UTF-8');
                  ?>
                  <tr class="sec-row" 
                      data-id="<?= $sl['id'] ?>"
                      data-severity="<?= htmlspecialchars($sev) ?>"
                      data-action="<?= htmlspecialchars($action_name) ?>"
                      data-status="<?= htmlspecialchars($res_status) ?>"
                      data-ip="<?= htmlspecialchars($sl['ip_address'] ?? '') ?>"
                      data-user="<?= htmlspecialchars(strtolower($displayName . ' ' . $username)) ?>"
                      data-search="<?= htmlspecialchars(strtolower($displayName . ' ' . $username . ' ' . $action_name . ' ' . ($sl['ip_address'] ?? '') . ' ' . ($sl['detail'] ?? ''))) ?>">
                    
                    <!-- 1. Timestamp -->
                    <td>
                      <div style="font-weight:700; color:#0f172a;"><?= date('M d, Y', strtotime($sl['created_at'])) ?></div>
                      <div style="font-size:0.72rem; color:#64748b;"><?= date('H:i:s', strtotime($sl['created_at'])) ?></div>
                    </td>

                    <!-- 2. User -->
                    <td>
                      <div style="font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px;" title="<?= htmlspecialchars($displayName) ?>">
                        <?= htmlspecialchars($displayName) ?>
                      </div>
                      <div style="font-size:0.72rem; color:#64748b; display:flex; align-items:center; gap:5px;">
                        <span><?= htmlspecialchars($username) ?></span>
                        &bull;
                        <span style="font-weight:600; color:#475569;"><?= $roleLabel ?></span>
                      </div>
                    </td>

                    <!-- 3. Event -->
                    <td>
                      <span class="event-code-badge <?= $event_class ?>">
                        <i class="fa-solid <?= $event_icon ?>"></i>
                        <?= htmlspecialchars($action_name) ?>
                      </span>
                      <div style="font-size:0.71rem; color:#64748b; margin-top:3px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($sl['detail'] ?? '') ?>">
                        <?= htmlspecialchars($sl['detail'] ?? '—') ?>
                      </div>
                    </td>

                    <!-- 4. IP -->
                    <td>
                      <span class="ip-box" title="Network Source IP">
                        <i class="fa-solid fa-network-wired" style="font-size:0.68rem; color:#64748b;"></i>
                        <?= htmlspecialchars($sl['ip_address'] ?? '127.0.0.1') ?>
                      </span>
                    </td>

                    <!-- 5. Severity -->
                    <td>
                      <span class="badge-sev badge-sev-<?= $sev ?>">
                        <i class="fa-solid <?= $sev_icon ?>"></i>
                        <?= $sev_lbl ?>
                      </span>
                      <div style="margin-top:3px;">
                        <span class="badge-res badge-res-<?= $res_status ?>">
                          <?= ucfirst($res_status) ?>
                        </span>
                      </div>
                    </td>

                    <!-- 6. Action: Inspect / Investigate / Resolve -->
                    <td style="text-align: right;">
                      <div class="btn-action-group" style="justify-content: flex-end;">
                        <button type="button" class="btn-tbl-action btn-inspect" onclick='openInspectModal(<?= $jsonPayload ?>)' title="Inspect Event Details">
                          <i class="fa-solid fa-eye"></i> Inspect
                        </button>
                        <button type="button" class="btn-tbl-action btn-investigate" onclick="investigateFilter('<?= htmlspecialchars($sl['ip_address'] ?? '') ?>', '<?= htmlspecialchars($username) ?>')" title="Investigate Activity Pattern">
                          <i class="fa-solid fa-magnifying-glass"></i> Investigate
                        </button>
                        <button type="button" class="btn-tbl-action btn-resolve" onclick="openResolveModal(<?= $sl['id'] ?>, '<?= $res_status ?>', '<?= htmlspecialchars(addslashes($sl['resolution_notes'] ?? '')) ?>')" title="Update Security Status">
                          <i class="fa-solid fa-check-double"></i> Resolve
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Compact Table Pagination -->
        <div class="sec-pagination">
          <div style="display:flex; align-items:center; gap:8px;">
            <span>Rows per page:</span>
            <select class="sec-select" id="pageSizeSelect" onchange="changePageSize(this.value)" style="height:28px; padding:0 6px; font-size:0.75rem;">
              <option value="10">10</option>
              <option value="25" selected>25</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
            <span id="pageInfoText" style="margin-left:6px;">Page 1 of 1</span>
          </div>

          <div class="sec-pager-btns" id="paginationControls">
            <!-- Rendered by JS -->
          </div>
        </div>

      </div><!-- /sec-table-container -->

    </div><!-- /content-body -->
  </div><!-- /content -->

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- /main -->

<!-- Modal 1: Inspect Security Event Details -->
<div class="sec-modal-overlay" id="inspectModalOverlay" onclick="handleBackdropClick(event, 'inspectModalOverlay')">
  <div class="sec-modal-card" onclick="event.stopPropagation()">
    <div class="sec-modal-header">
      <h3 id="inspectModalTitle"><i class="fa-solid fa-shield-halved" style="color:#60a5fa;"></i> Security Event Inspection</h3>
      <button type="button" onclick="closeInspectModal()" style="background:none; border:none; color:#94a3b8; font-size:1.1rem; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="sec-modal-body" id="inspectModalBody">
      <!-- Loaded dynamically via openInspectModal -->
    </div>
    <div class="sec-modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeInspectModal()" style="height:34px; padding:0 14px; font-size:0.80rem; border-radius:8px;">Close</button>
      <button type="button" class="btn btn-primary" id="inspectQuickResolveBtn" style="height:34px; padding:0 14px; font-size:0.80rem; border-radius:8px;">
        <i class="fa-solid fa-check"></i> Resolve Event
      </button>
    </div>
  </div>
</div>

<!-- Modal 2: Resolve Security Event -->
<div class="sec-modal-overlay" id="resolveModalOverlay" onclick="handleBackdropClick(event, 'resolveModalOverlay')">
  <div class="sec-modal-card" style="max-width:520px;" onclick="event.stopPropagation()">
    <div class="sec-modal-header">
      <h3><i class="fa-solid fa-clipboard-check" style="color:#34d399;"></i> Resolve Security Incident</h3>
      <button type="button" onclick="closeResolveModal()" style="background:none; border:none; color:#94a3b8; font-size:1.1rem; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="resolveEventForm" onsubmit="submitResolveSecurityEvent(event)">
      <input type="hidden" id="resolveLogId" name="log_id" value=""/>
      <div class="sec-modal-body">
        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.75rem; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:6px;">
            Resolution Status:
          </label>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:6px; font-size:0.82rem; font-weight:600; cursor:pointer; background:#f8fafc; padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1;">
              <input type="radio" name="status" value="resolved" checked />
              <span style="color:#059669;"><i class="fa-solid fa-circle-check"></i> Resolved</span>
            </label>
            <label style="display:flex; align-items:center; gap:6px; font-size:0.82rem; font-weight:600; cursor:pointer; background:#f8fafc; padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1;">
              <input type="radio" name="status" value="investigating" />
              <span style="color:#d97706;"><i class="fa-solid fa-magnifying-glass"></i> Investigating</span>
            </label>
            <label style="display:flex; align-items:center; gap:6px; font-size:0.82rem; font-weight:600; cursor:pointer; background:#f8fafc; padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1;">
              <input type="radio" name="status" value="unresolved" />
              <span style="color:#dc2626;"><i class="fa-solid fa-circle-exclamation"></i> Unresolved</span>
            </label>
          </div>
        </div>

        <div>
          <label for="resolveNotesInput" style="display:block; font-size:0.75rem; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:6px;">
            Administrator Findings / Resolution Notes:
          </label>
          <textarea id="resolveNotesInput" name="notes" rows="4" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:10px; font-size:0.82rem; color:#0f172a; outline:none; font-family:inherit; resize:vertical;" placeholder="Document root cause, mitigation steps, or confirmation of account ownership..."></textarea>
        </div>
      </div>
      <div class="sec-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeResolveModal()" style="height:34px; padding:0 14px; font-size:0.80rem; border-radius:8px;">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitResolution" style="height:34px; padding:0 14px; font-size:0.80rem; border-radius:8px;">
          <i class="fa-solid fa-floppy-disk"></i> Save Resolution
        </button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../shared/qr_modal.php'; ?>

<div id="toast" class="toast-notification" style="display:none;"></div>
<script src="../js/dashboard.js"></script>
<script>
let currentPage = 1;
let pageSize = 25;
let filteredRows = [];

document.addEventListener('DOMContentLoaded', () => {
  filteredRows = Array.from(document.querySelectorAll('.sec-row'));
  paginateTable();
});

// Real-time table filtering
function handleTableFilter() {
  const search = document.getElementById('tableSearchInput').value.toLowerCase().trim();
  const sevFilter = document.getElementById('severityFilter').value.toLowerCase();
  const eventFilter = document.getElementById('eventCategoryFilter').value;
  const statusFilter = document.getElementById('statusFilter').value.toLowerCase();

  const allRows = Array.from(document.querySelectorAll('.sec-row'));
  filteredRows = allRows.filter(row => {
    const rSearch = row.getAttribute('data-search') || '';
    const rSev = row.getAttribute('data-severity') || '';
    const rAction = row.getAttribute('data-action') || '';
    const rStatus = row.getAttribute('data-status') || '';

    const matchSearch = !search || rSearch.includes(search);
    const matchSev = !sevFilter || rSev === sevFilter;
    const matchStatus = !statusFilter || rStatus === statusFilter;
    const matchEvent = !eventFilter || rAction.includes(eventFilter);

    return matchSearch && matchSev && matchStatus && matchEvent;
  });

  currentPage = 1;
  paginateTable();
}

function changePageSize(size) {
  pageSize = parseInt(size, 10) || 25;
  currentPage = 1;
  paginateTable();
}

function paginateTable() {
  const allRows = Array.from(document.querySelectorAll('.sec-row'));
  allRows.forEach(r => r.style.display = 'none');

  const total = filteredRows.length;
  const totalPages = Math.ceil(total / pageSize) || 1;
  if (currentPage > totalPages) currentPage = totalPages;

  const start = (currentPage - 1) * pageSize;
  const end = Math.min(start + pageSize, total);

  for (let i = start; i < end; i++) {
    if (filteredRows[i]) filteredRows[i].style.display = '';
  }

  // Update counter text
  const filterCountEl = document.getElementById('filterCountDisplay');
  if (filterCountEl) filterCountEl.textContent = '';
  document.getElementById('pageInfoText').textContent = `Page ${currentPage} of ${totalPages}`;

  // Render pagination buttons
  const pager = document.getElementById('paginationControls');
  let btns = '';

  btns += `<button type="button" class="sec-page-btn" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}><i class="fa-solid fa-chevron-left"></i></button>`;

  let startP = Math.max(1, currentPage - 2);
  let endP = Math.min(totalPages, startP + 4);
  if (endP - startP < 4) startP = Math.max(1, endP - 4);

  for (let p = startP; p <= endP; p++) {
    btns += `<button type="button" class="sec-page-btn ${p === currentPage ? 'active' : ''}" onclick="goToPage(${p})">${p}</button>`;
  }

  btns += `<button type="button" class="sec-page-btn" onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}><i class="fa-solid fa-chevron-right"></i></button>`;
  pager.innerHTML = btns;
}

function goToPage(p) {
  const totalPages = Math.ceil(filteredRows.length / pageSize) || 1;
  if (p < 1 || p > totalPages) return;
  currentPage = p;
  paginateTable();
}

// Inspect Modal
function openInspectModal(data) {
  const body = document.getElementById('inspectModalBody');
  body.innerHTML = `
    <div class="sec-kv-grid">
      <div class="sec-kv-item">
        <div class="sec-kv-label">Event Timestamp</div>
        <div class="sec-kv-val">${data.timestamp}</div>
      </div>
      <div class="sec-kv-item">
        <div class="sec-kv-label">Severity Level</div>
        <div class="sec-kv-val">${data.severity}</div>
      </div>
      <div class="sec-kv-item">
        <div class="sec-kv-label">Related User / Account</div>
        <div class="sec-kv-val">${data.user_name} <span style="font-size:0.75rem; color:#64748b;">(${data.username})</span></div>
      </div>
      <div class="sec-kv-item">
        <div class="sec-kv-label">User Role</div>
        <div class="sec-kv-val">${data.user_role}</div>
      </div>
      <div class="sec-kv-item">
        <div class="sec-kv-label">Network Source IP</div>
        <div class="sec-kv-val" style="font-family:monospace;">${data.ip}</div>
      </div>
      <div class="sec-kv-item">
        <div class="sec-kv-label">Resolution Status</div>
        <div class="sec-kv-val">${data.resolution_status}</div>
      </div>
    </div>

    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; margin-bottom:14px;">
      <div class="sec-kv-label">Security Event Action</div>
      <div style="font-family:monospace; font-weight:700; color:#1e3a8a; font-size:0.90rem; margin-bottom:6px;">${data.event}</div>
      <div class="sec-kv-label">Event Detail &amp; Payload</div>
      <div style="font-size:0.83rem; color:#334155; line-height:1.45;">${data.detail}</div>
    </div>

    <div style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:10px; padding:12px 14px;">
      <div style="font-size:0.72rem; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Investigation &amp; Resolution History</div>
      <div style="font-size:0.80rem; color:#1e293b; margin-bottom:4px;"><strong>Administrator:</strong> ${data.resolved_by} &bull; <strong>Resolved Date:</strong> ${data.resolved_at}</div>
      <div style="font-size:0.80rem; color:#475569;"><strong>Notes:</strong> ${data.resolution_notes}</div>
    </div>
  `;

  document.getElementById('inspectQuickResolveBtn').onclick = () => {
    closeInspectModal();
    openResolveModal(data.id, data.resolution_status.toLowerCase(), data.resolution_notes);
  };

  document.getElementById('inspectModalOverlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeInspectModal() {
  document.getElementById('inspectModalOverlay').classList.remove('active');
  document.body.style.overflow = '';
}

// Investigate Quick Filter
function investigateFilter(ip, username) {
  const searchInput = document.getElementById('tableSearchInput');
  const target = (ip && ip !== '127.0.0.1' && ip !== '::1') ? ip : username.replace('@', '');
  searchInput.value = target;
  handleTableFilter();
  searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Resolve Modal
function openResolveModal(logId, currentStatus, currentNotes) {
  document.getElementById('resolveLogId').value = logId;
  const radios = document.getElementsByName('status');
  for (let r of radios) {
    if (r.value === currentStatus) r.checked = true;
  }
  document.getElementById('resolveNotesInput').value = (currentNotes && currentNotes !== 'None recorded.') ? currentNotes : '';
  document.getElementById('resolveModalOverlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeResolveModal() {
  document.getElementById('resolveModalOverlay').classList.remove('active');
  document.body.style.overflow = '';
}

function handleBackdropClick(event, overlayId) {
  if (event.target === document.getElementById(overlayId)) {
    if (overlayId === 'inspectModalOverlay') closeInspectModal();
    if (overlayId === 'resolveModalOverlay') closeResolveModal();
  }
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeInspectModal();
    closeResolveModal();
  }
});

// Submit Resolution via AJAX
async function submitResolveSecurityEvent(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSubmitResolution');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

  const form = document.getElementById('resolveEventForm');
  const fd = new FormData(form);
  fd.append('action', 'resolve_security_log');

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.content);

  try {
    const res = await fetch('../shared/admin_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      closeResolveModal();
      location.reload();
    } else {
      alert(data.message || 'Failed to update security incident status.');
    }
  } catch (err) {
    alert('An error occurred while connecting to the server. Please try again.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Resolution';
  }
}

// Export Table to CSV
function exportSecurityLogsCSV() {
  const rows = filteredRows.length ? filteredRows : Array.from(document.querySelectorAll('.sec-row'));
  if (!rows.length) {
    alert('No security records available to export.');
    return;
  }

  let csv = [];
  csv.push(['Timestamp', 'User', 'Username', 'Event Action', 'IP Address', 'Severity', 'Status'].map(c => `"${c}"`).join(','));

  rows.forEach(r => {
    const cells = r.querySelectorAll('td');
    if (cells.length >= 6) {
      const time = cells[0].innerText.replace(/\n/g, ' ').trim();
      const user = cells[1].querySelector('div:first-child')?.innerText.trim() || '';
      const uname = cells[1].querySelector('div:last-child span')?.innerText.trim() || '';
      const event = cells[2].querySelector('.event-code-badge')?.innerText.trim() || '';
      const ip = cells[3].innerText.trim();
      const sev = cells[4].querySelector('.badge-sev')?.innerText.trim() || '';
      const status = cells[4].querySelector('.badge-res')?.innerText.trim() || '';

      csv.push([time, user, uname, event, ip, sev, status].map(v => `"${String(v).replace(/"/g, '""')}"`).join(','));
    }
  });

  const blob = new Blob(['\uFEFF' + csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.setAttribute('download', `bcp_security_monitoring_${Date.now()}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
</script>
</body>
</html>
