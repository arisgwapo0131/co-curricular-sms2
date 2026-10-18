<?php
// ============================================================
//  ADMIN_ORG_MASTER.PHP  (dashboard/)
//  Co-Curricular System — Organization Master Data Management
//  Accessible to: System Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_permission('organization.crud');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// Query live clubs master data
$clubs_master = $conn->query("
    SELECT c.*,
           CONCAT(u.first_name, ' ', u.last_name) AS adviser_name,
           u.email AS adviser_email,
           (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Active') AS member_count
    FROM clubs c
    LEFT JOIN users u ON u.id = c.adviser_user_id
    WHERE c.deleted_at IS NULL
    ORDER BY c.name ASC
")->fetch_all(MYSQLI_ASSOC);

// Query faculty advisers for assignment dropdown
$faculty_advisers = $conn->query("
    SELECT id, first_name, last_name, email FROM users WHERE role = 'club_adviser' ORDER BY last_name, first_name
")->fetch_all(MYSQLI_ASSOC);

$total_orgs     = count($clubs_master);
$active_orgs    = count(array_filter($clubs_master, fn($c) => $c['status'] === 'Active'));
$suspended_orgs = count(array_filter($clubs_master, fn($c) => $c['status'] === 'Suspended'));
$unassigned_adv = count(array_filter($clubs_master, fn($c) => empty($c['adviser_user_id'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Organization Master Data — BCP Co-Curricular Portal</title>
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
    .admin-alert { padding:14px 18px; border-radius:10px; margin-bottom:20px; font-size:0.88rem; font-weight:600; display:none; }

    /* Organization Directory Master Compact Table Layout */
    #clubMasterTable {
      width: 100%;
      border-collapse: collapse;
      table-layout: auto;
    }
    #clubMasterTable th {
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
    #clubMasterTable td {
      padding: 7px 12px;
      vertical-align: middle;
      font-size: 0.82rem;
      border-bottom: 1px solid #f1f5f9;
      white-space: nowrap;
      height: 42px;
      color: #334155;
    }
    #clubMasterTable tr:hover td {
      background: #f8fafc;
    }
    #clubMasterTable code {
      font-size: 0.78rem;
      padding: 2px 6px;
      background: #f1f5f9;
      color: #0f172a;
      border-radius: 4px;
      border: 1px solid #e2e8f0;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
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
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'admin_org_master';
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
        <i class="fa-solid fa-sitemap" style="color:#2563eb;"></i>
        Organization Master Data Management
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
          <div class="card-label"><i class="fa-solid fa-building-columns" style="color:#2563eb;"></i> Total Organizations</div>
          <div class="card-amount"><?= $total_orgs ?></div>
          <div class="card-detail">All registered campus organizations.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Active &amp; Chartered</div>
          <div class="card-amount" style="color:#16a34a;"><?= $active_orgs ?></div>
          <div class="card-detail">Accredited active clubs.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Suspended Charters</div>
          <div class="card-amount" style="color:<?= $suspended_orgs > 0 ? '#dc2626' : '#64748b' ?>;"><?= $suspended_orgs ?></div>
          <div class="card-detail">Temporarily frozen charters.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-user-clock" style="color:#d97706;"></i> Unassigned Advisers</div>
          <div class="card-amount" style="color:<?= $unassigned_adv > 0 ? '#d97706' : '#16a34a' ?>;"><?= $unassigned_adv ?></div>
          <div class="card-detail">Pending faculty adviser assignment.</div>
        </div>
      </div>

      <!-- Organization Master Data Table Card -->
      <div class="card">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #f1f5f9;">
          <div>
            <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> Organization Directory Master</h3>
            <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">Accreditation status control, category classifications, and faculty adviser assignments</p>
          </div>
          <div style="display:flex; align-items:center; gap:8px;">
            <input type="text" id="clubSearchInput" placeholder="Filter code, name, adviser, category..." style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; min-width:260px;" oninput="filterClubMasterTable()"/>
          </div>
        </div>

        <div class="table-responsive">
          <table id="clubMasterTable" class="table-wide">
            <thead>
              <tr>
                <th style="width:90px;">Code</th>
                <th>Organization Name</th>
                <th>Classification</th>
                <th>Assigned Faculty Adviser</th>
                <th>Active Roster</th>
                <th style="text-align:center;">Charter Status</th>
                <th style="text-align:right; width:120px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($clubs_master)): ?>
              <tr>
                <td colspan="7" style="text-align:center; padding:32px; color:#94a3b8;">
                  <i class="fa-regular fa-folder-open" style="font-size:2rem; margin-bottom:8px; display:block;"></i>
                  No organization records found.
                </td>
              </tr>
              <?php else: ?>
              <tr id="clubTableNoMatch" style="display:none;">
                <td colspan="7" style="text-align:center; padding:32px; color:#94a3b8;">
                  <i class="fa-solid fa-magnifying-glass" style="font-size:1.8rem; margin-bottom:8px; display:block; color:#cbd5e1;"></i>
                  No organizations match the search criteria.
                </td>
              </tr>
              <?php foreach ($clubs_master as $cl): ?>
              <tr class="club-master-row" data-search="<?= strtolower($cl['code'] . ' ' . $cl['name'] . ' ' . ($cl['adviser_name'] ?? '') . ' ' . ($cl['category'] ?? '')) ?>">
                <td><code><?= htmlspecialchars($cl['code']) ?></code></td>
                <td><strong><?= htmlspecialchars($cl['name']) ?></strong></td>
                <td><span class="badge-info"><?= htmlspecialchars($cl['category'] ?? 'General') ?></span></td>
                <td><?= !empty($cl['adviser_name']) ? htmlspecialchars($cl['adviser_name']) : '<em style="color:#94a3b8;">Unassigned</em>' ?></td>
                <td><strong><?= (int)$cl['member_count'] ?></strong> members</td>
                <td style="text-align:center;">
                  <?php if ($cl['status'] === 'Active'): ?>
                    <span class="badge-active"><i class="fa-solid fa-circle-check"></i> Active</span>
                  <?php elseif ($cl['status'] === 'Suspended'): ?>
                    <span class="badge-warning" style="background:#fee2e2; color:#991b1b;"><i class="fa-solid fa-triangle-exclamation"></i> Suspended</span>
                  <?php else: ?>
                    <span class="badge-warning"><?= htmlspecialchars($cl['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:5px;" onclick="openEditClubModal(<?= htmlspecialchars(json_encode($cl), ENT_QUOTES) ?>)">
                    <i class="fa-solid fa-sliders"></i> Configure
                  </button>
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

<!-- Modal: Configure Organization Master Data -->
<div class="modal-overlay" id="editClubModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> Configure Organization Master Data</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('editClubModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="editClubForm" onsubmit="handleUpdateClub(event)">
      <input type="hidden" name="club_id" id="editClubId"/>
      <div class="modal-body">
        <div style="margin-bottom:14px; background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">
          <strong id="editClubNameDisp" style="font-size:0.95rem; color:#0f172a;"></strong>
          <div id="editClubCodeDisp" style="font-size:0.8rem; color:#64748b; font-family:monospace;"></div>
        </div>
        <div class="form-group-admin">
          <label>Charter &amp; Accreditation Status</label>
          <select name="status" id="editClubStatusSelect" required>
            <option value="Active">Active (Recognized &amp; Accredited)</option>
            <option value="Suspended">Suspended (Temporarily Frozen)</option>
            <option value="Archived">Archived (De-recognized)</option>
            <option value="Pending">Pending (Under Review)</option>
          </select>
        </div>
        <div class="form-group-admin">
          <label>Classification Category</label>
          <select name="category" id="editClubCatSelect" required>
            <option value="Academic">Academic (LOC)</option>
            <option value="Cultural">Talent &amp; Cultural (CTCE)</option>
            <option value="Advocacy">Advocacy</option>
            <option value="Sports">Sports</option>
          </select>
        </div>
        <div class="form-group-admin">
          <label>Assigned Faculty Club Adviser</label>
          <select name="adviser_user_id" id="editClubAdviserSelect">
            <option value="">-- No Adviser Assigned --</option>
            <?php foreach ($faculty_advisers as $fa): ?>
              <option value="<?= $fa['id'] ?>"><?= htmlspecialchars($fa['first_name'] . ' ' . $fa['last_name'] . ' (' . $fa['email'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('editClubModal')">Cancel</button>
        <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700;"><i class="fa-solid fa-floppy-disk"></i> Update Organization</button>
      </div>
    </form>
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

function openModal(id) { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

function filterClubMasterTable() {
  const q = (document.getElementById('clubSearchInput')?.value || '').toLowerCase().trim();
  const rows = document.querySelectorAll('.club-master-row');
  let matchedCount = 0;

  rows.forEach(tr => {
    const s = tr.getAttribute('data-search') || '';
    const isMatch = !q || s.includes(q);

    if (isMatch) {
      tr.removeAttribute('data-search-hidden');
      matchedCount++;
    } else {
      tr.setAttribute('data-search-hidden', 'true');
      tr.style.setProperty('display', 'none', 'important');
    }
  });

  const noMatchRow = document.getElementById('clubTableNoMatch');
  if (noMatchRow) {
    noMatchRow.style.display = (matchedCount === 0 && rows.length > 0) ? '' : 'none';
  }

  const table = document.getElementById('clubMasterTable');
  if (table && table._paginator) {
    table._paginator.currentPage = 1;
    table._paginator.render();
  }
}

function initClubMasterPagination() {
  const tbl = document.getElementById('clubMasterTable');
  if (tbl && window.initTablePagination && !tbl._paginator) {
    window.initTablePagination(tbl, {
      pageSize: 10,
      showPageSizeSelector: false,
      showInfo: false
    });
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initClubMasterPagination);
} else {
  initClubMasterPagination();
}

function openEditClubModal(cl) {
  document.getElementById('editClubId').value = cl.id;
  document.getElementById('editClubNameDisp').textContent = cl.name;
  document.getElementById('editClubCodeDisp').textContent = cl.code;
  document.getElementById('editClubStatusSelect').value = cl.status;
  document.getElementById('editClubCatSelect').value = cl.category || 'Academic';
  document.getElementById('editClubAdviserSelect').value = cl.adviser_user_id || '';
  openModal('editClubModal');
}

function handleUpdateClub(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'update_club_master');
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert(res.message, 'success');
        closeModal('editClubModal');
        setTimeout(() => location.reload(), 1000);
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Failed to update organization.', 'error'));
}
</script>
</body>
</html>
