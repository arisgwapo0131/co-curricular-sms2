<?php
// ============================================================
//  ADMIN_NOTIFICATIONS.PHP  (dashboard/)
//  Co-Curricular System — Notification Settings & Templates
//  Accessible to: System Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_permission('settings.manage');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// Load system settings from database
$sys_settings = [];
$res_s = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($res_s) {
    while ($r = $res_s->fetch_assoc()) {
        $sys_settings[$r['setting_key']] = $r['setting_value'];
    }
}
$notification_tpl = $sys_settings['notification_templates'] ?? "Event Approval Notice\nBudget Disbursement Notice\nCouncil Endorsement Notice\nAccount Credentials Reset\nCharter Accreditation Update";

$total_notifs = (int)$conn->query("SELECT COUNT(*) FROM notifications")->fetch_row()[0];
$unread_notifs= (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Notification Settings — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <meta name="csrf-token" content="<?= csrf_token() ?>"/>
  <script src="../js/page-loader.js"></script>
  <style>
    .kpi-grid-3 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
      margin-bottom: 24px;
    }
    .kpi-grid-3 .info-card {
      background: #ffffff;
      border-radius: 14px;
      padding: 16px 18px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .kpi-grid-3 .info-card .card-label {
      font-size: 0.78rem;
      font-weight: 700;
      color: #64748b;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .kpi-grid-3 .info-card .card-amount {
      font-size: 1.6rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.2;
      margin-bottom: 4px;
    }
    .kpi-grid-3 .info-card .card-detail {
      font-size: 0.75rem;
      color: #64748b;
      font-weight: 500;
    }
    .form-group-admin { margin-bottom:18px; }
    .form-group-admin label { display:block; font-size:0.75rem; font-weight:800; color:#475569; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.04em; }
    .form-group-admin textarea { width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.88rem; color:#1e293b; background:#fff; font-family:inherit; }
    .form-group-admin textarea:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
    .admin-alert { padding:14px 18px; border-radius:10px; margin-bottom:20px; font-size:0.88rem; font-weight:600; display:none; }

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
$ACTIVE_NAV = 'admin_notifications';
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
        <i class="fa-solid fa-bell" style="color:#2563eb;"></i>
        Notification Settings &amp; Templates
      </h2>
      <div style="font-size:0.8rem; color:#64748b; font-weight:600;">
        Role: <strong style="color:#0f172a;">System Administrator</strong>
      </div>
    </div>

    <div class="content-body" style="max-width:840px;">

      <!-- Alert Box -->
      <div id="adminAlert" class="admin-alert"></div>

      <!-- Stat Cards -->
      <div class="kpi-grid-3">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-bell" style="color:#2563eb;"></i> Total Notifications Dispatched</div>
          <div class="card-amount"><?= number_format($total_notifs) ?></div>
          <div class="card-detail">System-wide in-app notifications generated.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-envelope-open" style="color:#d97706;"></i> Unread Queue</div>
          <div class="card-amount" style="color:#d97706;"><?= number_format($unread_notifs) ?></div>
          <div class="card-detail">Pending user reads.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-satellite-dish" style="color:#16a34a;"></i> Real-Time Alerts</div>
          <div class="card-amount" style="color:#16a34a; font-size:1.35rem;"><i class="fa-solid fa-check"></i> Enabled</div>
          <div class="card-detail">Instant header badge updating enabled.</div>
        </div>
      </div>

      <!-- Notification Templates Card -->
      <div class="card">
        <div style="margin-bottom:18px; padding-bottom:12px; border-bottom:1px solid #f1f5f9;">
          <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-file-pen" style="color:#2563eb;"></i> Automated Notification Triggers</h3>
          <p style="margin:3px 0 0; font-size:0.8rem; color:#64748b;">Specify active notification types and event triggers supported across all multi-tier approval stages.</p>
        </div>

        <form id="notifSettingsForm" onsubmit="handleSaveNotifs(event)">
          <div class="form-group-admin">
            <label>Automated Notification Template Triggers (One per line)</label>
            <textarea name="notification_templates" rows="6" style="font-family:monospace; font-size:0.85rem;"><?= htmlspecialchars($notification_tpl) ?></textarea>
            <div style="font-size:0.75rem; color:#64748b; margin-top:4px;">When actions occur (event endorsement, budget release, role update, status deactivation), users will receive notifications matching these templates.</div>
          </div>

          <div style="text-align:right; margin-top:20px; padding-top:14px; border-top:1px solid #f1f5f9;">
            <button type="submit" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700; padding:10px 24px;">
              <i class="fa-solid fa-floppy-disk"></i> Save Notification Settings
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<script src="../js/dashboard.js"></script>
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

function handleSaveNotifs(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'save_system_settings');
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert(res.message, 'success');
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Failed to persist notification templates.', 'error'));
}
</script>
</body>
</html>
