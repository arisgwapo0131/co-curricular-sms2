<?php
// ============================================================
//  ADMIN_AUDIT.PHP  (dashboard/)
//  Co-Curricular System — System Audit Trail & Logs
//  Accessible to: System Admin
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_role('admin');
require_permission('audit.manage.full');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// Query recent 150 audit logs
$audit_logs = $conn->query(
    "SELECT al.id, al.action, al.target_table, al.target_id, al.detail, al.ip_address, al.created_at,
            u.first_name, u.last_name, u.role
     FROM audit_logs al
     JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC LIMIT 150"
)->fetch_all(MYSQLI_ASSOC);

$total_logs_count = (int)$conn->query("SELECT COUNT(*) FROM audit_logs")->fetch_row()[0];
$today_logs_count = (int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetch_row()[0];
$unique_actors    = (int)$conn->query("SELECT COUNT(DISTINCT user_id) FROM audit_logs")->fetch_row()[0];
$admin_actions    = (int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'admin_%'")->fetch_row()[0];

$role_labels = [
    'admin'        => 'System Admin',
    'ssc'          => 'Supreme Student Council (SSC)',
    'club_adviser' => 'Faculty Club Adviser',
    'student'      => 'General Student',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>System Audit Trail — BCP Co-Curricular Portal</title>
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
    .role-badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:0.72rem; font-weight:800; }
    .role-admin   { background:#fee2e2; color:#991b1b; border: 1px solid #fca5a5; }
    .role-ssc     { background:#fef3c7; color:#92400e; border: 1px solid #fde68a; }
    .role-adviser { background:#e0e7ff; color:#3730a3; border: 1px solid #c7d2fe; }
    .role-student { background:#f1f5f9; color:#475569; border: 1px solid #e2e8f0; }

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
$ACTIVE_NAV = 'admin_audit';
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
        <i class="fa-solid fa-list-check" style="color:#2563eb;"></i>
        System Activity Audit Trail
      </h2>
      <div style="font-size:0.8rem; color:#64748b; font-weight:600;">
        Role: <strong style="color:#0f172a;">System Administrator</strong>
      </div>
    </div>

    <div class="content-body">

      <!-- Stat Cards -->
      <div class="kpi-grid-4">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i> Total Recorded Logs</div>
          <div class="card-amount"><?= number_format($total_logs_count) ?></div>
          <div class="card-detail">Permanent tamper-evident audit records.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock" style="color:#16a34a;"></i> Activity Today</div>
          <div class="card-amount" style="color:#16a34a;"><?= number_format($today_logs_count) ?></div>
          <div class="card-detail">Events logged in past 24 hours.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-user-shield" style="color:#dc2626;"></i> Administrative Actions</div>
          <div class="card-amount" style="color:#dc2626;"><?= number_format($admin_actions) ?></div>
          <div class="card-detail">Privileged modifications recorded.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-users" style="color:#4f46e5;"></i> Unique Actors</div>
          <div class="card-amount"><?= $unique_actors ?></div>
          <div class="card-detail">System user accounts recorded.</div>
        </div>
      </div>

      <!-- Audit Table Card -->
      <div class="card">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #f1f5f9;">
          <div>
            <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-lock" style="color:#2563eb;"></i> System Activity Logs</h3>
            <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">Real-time tamper-evident records of administrative, authorization, and financial pipeline events</p>
          </div>
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="text" id="auditSearchInput" placeholder="Filter actions, details, users..." style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; min-width:240px;" oninput="filterAuditTable()"/>
            <a href="../shared/admin_actions.php?action=export_audit_csv" class="card-btn" style="background:#0f172a; color:#fff; font-weight:700; text-decoration:none;">
              <i class="fa-solid fa-file-csv"></i> Export Audit Logs (CSV)
            </a>
          </div>
        </div>

        <div class="table-wrap">
          <table id="auditTable" class="table-wide">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>Actor</th>
                <th>Role</th>
                <th>Action</th>
                <th>Target</th>
                <th>Detail &amp; Payload</th>
                <th>IP Address</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($audit_logs)): ?>
              <tr>
                <td colspan="7" style="text-align:center; padding:32px; color:#94a3b8;">
                  No audit trail records found.
                </td>
              </tr>
              <?php else: ?>
              <?php foreach ($audit_logs as $log): ?>
              <?php $r_cls = str_replace('_', '', $log['role']); ?>
              <tr class="audit-row" data-search="<?= strtolower($log['first_name'] . ' ' . $log['last_name'] . ' ' . $log['action'] . ' ' . ($log['detail'] ?? '')) ?>">
                <td style="font-size:0.8rem; color:#64748b; white-space:nowrap;"><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                <td><strong><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></strong></td>
                <td><span class="role-badge role-<?= $r_cls ?>"><?= htmlspecialchars($role_labels[$log['role']] ?? $log['role']) ?></span></td>
                <td><code style="font-size:0.75rem; color:#1e3a8a; background:#e0f2fe; padding:2px 6px; border-radius:4px;"><?= htmlspecialchars($log['action']) ?></code></td>
                <td><?= htmlspecialchars($log['target_table'] ?? '—') ?></td>
                <td style="font-size:0.8rem; color:#334155; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($log['detail'] ?? '') ?>">
                  <?= htmlspecialchars($log['detail'] ?? '—') ?>
                </td>
                <td style="font-size:0.75rem; color:#94a3b8; font-family:monospace;"><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?></td>
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

<script src="../js/dashboard.js"></script>
<script src="../js/table-pagination.js"></script>
<script>
function filterAuditTable() {
  const q = (document.getElementById('auditSearchInput')?.value || '').toLowerCase().trim();
  document.querySelectorAll('.audit-row').forEach(tr => {
    const s = tr.getAttribute('data-search') || '';
    tr.style.display = (!q || s.includes(q)) ? '' : 'none';
  });
}
</script>
</body>
</html>
