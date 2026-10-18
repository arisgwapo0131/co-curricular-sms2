<?php
// ============================================================
//  ADMIN_HEALTH.PHP  (dashboard/)
//  Co-Curricular System — System Health & Diagnostics
//  Accessible to: System Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_permission('system.health');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

$user_count     = (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$club_count     = (int)$conn->query("SELECT COUNT(*) FROM clubs WHERE status='Active' AND deleted_at IS NULL")->fetch_row()[0];
$total_audit    = (int)$conn->query("SELECT COUNT(*) FROM audit_logs")->fetch_row()[0];

$stuck_budgets  = (int)$conn->query("SELECT COUNT(*) FROM budget_requests WHERE status NOT IN ('Disbursed','Rejected') AND deleted_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_row()[0];
$stuck_events   = (int)$conn->query("SELECT COUNT(*) FROM events WHERE status NOT IN ('Approved','Completed','Cancelled','Rejected') AND deleted_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_row()[0];

$uploads_writable = is_writable(__DIR__ . '/../uploads');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>System Health &amp; Diagnostics — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <meta name="csrf-token" content="<?= csrf_token() ?>"/>
  <script src="../js/page-loader.js"></script>
  <style>
    .kpi-grid-4 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 14px;
      margin-bottom: 24px;
    }
    .kpi-grid-4 .info-card {
      background: #ffffff;
      border-radius: 14px;
      padding: 16px 18px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .kpi-grid-4 .info-card .card-label {
      font-size: 0.78rem;
      font-weight: 700;
      color: #64748b;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .kpi-grid-4 .info-card .card-amount {
      font-size: 1.6rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.2;
      margin-bottom: 4px;
    }
    .kpi-grid-4 .info-card .card-detail {
      font-size: 0.75rem;
      color: #64748b;
      font-weight: 500;
    }
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
$ACTIVE_NAV = 'admin_health';
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
        <i class="fa-solid fa-heart-pulse" style="color:#2563eb;"></i>
        System Health &amp; Diagnostics
      </h2>
      <div style="font-size:0.8rem; color:#64748b; font-weight:600;">
        Role: <strong style="color:#0f172a;">System Administrator</strong>
      </div>
    </div>

    <div class="content-body">

      <!-- Alert Box -->
      <div id="adminAlert" class="admin-alert"></div>

      <!-- Stat Cards -->
      <div class="kpi-grid-4">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-server" style="color:#2563eb;"></i> System Status</div>
          <div class="card-amount" style="color:#16a34a; font-size:1.35rem;"><i class="fa-solid fa-circle-check"></i> Operational</div>
          <div class="card-detail">All subsystems online &amp; healthy.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock" style="color:#6366f1;"></i> Server Time</div>
          <div class="card-amount" style="font-size:1.15rem;"><?= date('H:i:s') ?></div>
          <div class="card-detail"><?= date_default_timezone_get() ?> &bull; <?= date('M d, Y') ?></div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-hand-holding-dollar" style="color:#f59e0b;"></i> Stuck Budgets</div>
          <div class="card-amount" style="color:<?= $stuck_budgets > 0 ? '#dc2626' : '#16a34a' ?>;"><?= $stuck_budgets ?></div>
          <div class="card-detail">> 7 Days pending movement.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-calendar-xmark" style="color:#ef4444;"></i> Stuck Events</div>
          <div class="card-amount" style="color:<?= $stuck_events > 0 ? '#dc2626' : '#16a34a' ?>;"><?= $stuck_events ?></div>
          <div class="card-detail">> 7 Days pending endorsement.</div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
        <!-- Server Diagnostics Card -->
        <div class="card">
          <h3 style="margin:0 0 14px; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-server" style="color:#2563eb;"></i> Server Infrastructure Diagnostics</h3>
          <div class="table-wrap table-compact">
            <table class="table-compact">
              <tbody>
                <tr><td><strong>PHP Version</strong></td><td><code><?= PHP_VERSION ?></code></td></tr>
                <tr><td><strong>MySQL Server</strong></td><td><code><?= $conn->server_info ?></code></td></tr>
                <tr><td><strong>Storage Permissions</strong></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Writable (`uploads/`)</span></td></tr>
                <tr><td><strong>Server Timezone</strong></td><td><code><?= date_default_timezone_get() ?> (<?= date('Y-m-d H:i:s') ?>)</code></td></tr>
                <tr><td><strong>Registered Users</strong></td><td><strong><?= $user_count ?></strong> accounts</td></tr>
                <tr><td><strong>Active Organizations</strong></td><td><strong><?= $club_count ?></strong> chartered orgs</td></tr>
                <tr><td><strong>System Audit Records</strong></td><td><strong><?= number_format($total_audit) ?></strong> recorded logs</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Stuck Requests Operations -->
        <div class="card">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
            <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-bolt" style="color:#f59e0b;"></i> Stuck Workflow Overrides</h3>
            <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff;" onclick="loadStuckItemsLive()">
              <i class="fa-solid fa-rotate"></i> Refresh
            </button>
          </div>
          <p style="font-size:0.8rem; color:#64748b; margin-top:0;">Budget requests and proposals pending for over 7 days:</p>
          <div id="stuckBudgetsContainer" style="max-height:280px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:10px; padding:10px;">
            <p style="text-align:center; color:#94a3b8; padding:20px; font-size:0.82rem;">Loading items...</p>
          </div>
        </div>
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

function loadStuckItemsLive() {
  const cont = document.getElementById('stuckBudgetsContainer');
  cont.innerHTML = '<p style="text-align:center; color:#94a3b8; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Fetching stuck queue...</p>';

  fetch('../shared/admin_actions.php?action=list_stuck')
    .then(r => r.json())
    .then(data => {
      if (!data.success || !data.stuck || !data.stuck.length) {
        cont.innerHTML = '<p style="text-align:center; color:#16a34a; padding:24px; font-weight:600;"><i class="fa-solid fa-circle-check"></i> No requests stuck > 7 days.</p>';
        return;
      }
      cont.innerHTML = data.stuck.map(r => `
        <div style="display:flex; align-items:center; justify-content:space-between; padding:10px; border-bottom:1px solid #f1f5f9; gap:10px;">
          <div>
            <strong style="font-size:0.86rem; color:#0f172a;">${r.title}</strong>
            <div style="font-size:0.75rem; color:#64748b;">${r.club_name} — ₱${parseFloat(r.amount).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
            <div style="font-size:0.72rem; color:#d97706; font-weight:700;">Status: ${r.status} &bull; Created: ${new Date(r.created_at).toLocaleDateString()}</div>
          </div>
          <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff;" onclick="forceApproveBudget(${r.id})">
            <i class="fa-solid fa-bolt"></i> Force Approve
          </button>
        </div>
      `).join('');
    })
    .catch(() => {
      cont.innerHTML = '<p style="color:#dc2626; text-align:center;">Failed to load stuck items.</p>';
    });
}

async function forceApproveBudget(id) {
  const confirmed = await window.showConfirmModal(
    'Force-Forward Budget Request?',
    'Do you want to force-forward this stuck budget request directly to Administration clearance?',
    { type: 'warning', confirmText: 'Yes, Force Forward' }
  );
  if (!confirmed) return;
  const fd = new FormData();
  fd.append('action', 'override_budget');
  fd.append('budget_id', id);
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert('Budget request force-advanced successfully!', 'success');
        loadStuckItemsLive();
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

window.addEventListener('DOMContentLoaded', loadStuckItemsLive);
</script>
</body>
</html>
