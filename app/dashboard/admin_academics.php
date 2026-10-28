<?php
// ============================================================
//  ADMIN_ACADEMICS.PHP  (dashboard/)
//  Co-Curricular System — Academic Master Data Management
//  Accessible to: System Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_permission('master_data.academic');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// Query live academic programs
$academic_programs = $conn->query("
    SELECT * FROM academic_programs ORDER BY code ASC
")->fetch_all(MYSQLI_ASSOC);

$total_progs   = count($academic_programs);
$active_progs  = count(array_filter($academic_programs, fn($p) => ($p['status'] ?? 'Active') === 'Active'));
$inactive_progs= $total_progs - $active_progs;
$departments   = count(array_unique(array_filter(array_column($academic_programs, 'department'))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Academic Master Data — BCP Co-Curricular Portal</title>
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
    @keyframes rowPulseAnim {
      0% { background-color: #dcfce7 !important; }
      50% { background-color: #bbf7d0 !important; }
      100% { background-color: transparent; }
    }
    .row-pulse-highlight td {
      animation: rowPulseAnim 3.5s ease-out forwards !important;
    }

    /* Academic Degree Programs Compact Table Layout */
    #academicProgTable {
      width: 100%;
      border-collapse: collapse;
      table-layout: auto;
    }
    #academicProgTable th {
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
    #academicProgTable td {
      padding: 7px 12px;
      vertical-align: middle;
      font-size: 0.82rem;
      border-bottom: 1px solid #f1f5f9;
      white-space: nowrap;
      height: 42px;
      color: #334155;
    }
    #academicProgTable tr:hover td {
      background: #f8fafc;
    }
    #academicProgTable code {
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
$ACTIVE_NAV = 'admin_academics';
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
        <i class="fa-solid fa-graduation-cap" style="color:#2563eb;"></i>
        Academic Master Data Management
      </h2>
      <?php
      $role_labels = [
          'super_admin' => 'Super Administrator',
          'admin'       => 'System Administrator',
          'ssc'         => 'Supreme Student Council (SSC)',
          'staff'       => 'OSA Staff'
      ];
      $display_role = $role_labels[$sess_role] ?? ucwords(str_replace('_', ' ', $sess_role));
      ?>
      <div style="font-size:0.8rem; color:#64748b; font-weight:600;">
        Role: <strong style="color:#0f172a;"><?= htmlspecialchars($display_role) ?></strong>
      </div>
    </div>

    <div class="content-body">

      <!-- Alert Box -->
      <div id="adminAlert" class="admin-alert"></div>

      <!-- Stat Cards -->
      <div class="kpi-grid-4">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-graduation-cap" style="color:#2563eb;"></i> Total Degree Programs</div>
          <div class="card-amount"><?= $total_progs ?></div>
          <div class="card-detail">Accredited curriculum programs.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Active Programs</div>
          <div class="card-amount" style="color:#16a34a;"><?= $active_progs ?></div>
          <div class="card-detail">Available for student enrollments.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-building" style="color:#6366f1;"></i> Academic Colleges</div>
          <div class="card-amount"><?= $departments ?></div>
          <div class="card-detail">Department classifications.</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-ban" style="color:#64748b;"></i> Inactive Programs</div>
          <div class="card-amount" style="color:<?= $inactive_progs > 0 ? '#dc2626' : '#64748b' ?>;"><?= $inactive_progs ?></div>
          <div class="card-detail">Archived degree offerings.</div>
        </div>
      </div>

      <!-- Table Card -->
      <div class="card">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #f1f5f9;">
          <div>
            <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-graduation-cap" style="color:#2563eb;"></i> Academic Degree Programs Directory</h3>
            <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">Curricular degree programs and collegiate department master directory</p>
          </div>
          <div style="display:flex; align-items:center; gap:8px;">
            <input type="text" id="progSearchInput" placeholder="Filter code, program, department..." style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; min-width:240px;" oninput="filterProgramTable()"/>
            <button type="button" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700;" onclick="openAddProgramModal()">
              <i class="fa-solid fa-plus"></i> Add Academic Program
            </button>
          </div>
        </div>

        <div class="table-responsive">
          <table id="academicProgTable" class="table-wide">
            <thead>
              <tr>
                <th style="width:60px; text-align:center;">#</th>
                <th style="width:140px;">Program Code</th>
                <th>Degree / Program Title</th>
                <th>Academic Department</th>
                <th style="text-align:center; width:110px;">Status</th>
                <th style="text-align:right; width:130px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($academic_programs)): ?>
              <tr>
                <td colspan="6" style="text-align:center; padding:32px; color:#94a3b8;">
                  <i class="fa-regular fa-folder-open" style="font-size:2rem; margin-bottom:8px; display:block;"></i>
                  No academic program records found.
                </td>
              </tr>
              <?php else: ?>
              <tr id="progTableNoMatch" style="display:none;">
                <td colspan="6" style="text-align:center; padding:32px; color:#94a3b8;">
                  <i class="fa-solid fa-magnifying-glass" style="font-size:1.8rem; margin-bottom:8px; display:block; color:#cbd5e1;"></i>
                  No academic programs match the search criteria.
                </td>
              </tr>
              <?php $row_num = 1; foreach ($academic_programs as $p): ?>
              <tr class="prog-row" id="prog-row-<?= $p['id'] ?>" data-prog-id="<?= $p['id'] ?>" data-search="<?= strtolower($p['code'] . ' ' . $p['name'] . ' ' . ($p['department'] ?? '')) ?>">
                <td style="text-align:center; color:#64748b; font-weight:700;"><?= $row_num++ ?></td>
                <td><code><strong><?= htmlspecialchars($p['code']) ?></strong></code></td>
                <td><strong style="color:#0f172a;"><?= htmlspecialchars($p['name']) ?></strong></td>
                <td><?= htmlspecialchars($p['department'] ?? '—') ?></td>
                <td style="text-align:center;">
                  <?php if (($p['status'] ?? 'Active') === 'Active'): ?>
                    <span class="badge-active"><i class="fa-solid fa-check"></i> Active</span>
                  <?php else: ?>
                    <span class="badge-warning" style="background:#fee2e2; color:#991b1b;"><i class="fa-solid fa-circle-xmark"></i> Inactive</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <div style="display:inline-flex; gap:6px; justify-content:flex-end;">
                    <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:5px;" onclick="openEditProgramModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
                      <i class="fa-solid fa-pen"></i> Edit
                    </button>
                    <button type="button" class="card-btn btn-sm btn-danger" onclick="deleteAcademicProgram(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['code'])) ?>')" title="Delete program">
                      <i class="fa-solid fa-trash"></i>
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

<!-- Modal: Add Academic Program -->
<div class="modal-overlay" id="addProgramModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-graduation-cap" style="color:#16a34a;"></i> Add Academic Program</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('addProgramModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="addProgramForm" onsubmit="handleAddProgram(event)">
      <div class="modal-body">
        <div id="addProgModalAlert" class="admin-alert" style="display:none; margin-bottom:14px; padding:10px 14px; border-radius:8px; font-size:0.84rem; font-weight:600;"></div>
        <div class="form-group-admin">
          <label>Program Code <span style="color:#ef4444;">*</span></label>
          <input type="text" name="code" required placeholder="e.g. BSIT, BSHM, BEEd"/>
        </div>
        <div class="form-group-admin">
          <label>Degree / Program Title <span style="color:#ef4444;">*</span></label>
          <input type="text" name="name" required placeholder="e.g. Bachelor of Science in Information Technology"/>
        </div>
        <div class="form-group-admin">
          <label>Academic Department / College</label>
          <input type="text" name="department" placeholder="e.g. College of Computer Studies"/>
        </div>
        <div class="form-group-admin">
          <label>Status</label>
          <select name="status">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('addProgramModal')">Cancel</button>
        <button type="submit" id="addProgSubmitBtn" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700;"><i class="fa-solid fa-plus"></i> Save Program</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit Academic Program -->
<div class="modal-overlay" id="editProgramModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-pen-to-square" style="color:#2563eb;"></i> Edit Academic Program</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('editProgramModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="editProgramForm" onsubmit="handleUpdateProgram(event)">
      <input type="hidden" name="id" id="editProgId"/>
      <div class="modal-body">
        <div id="editProgModalAlert" class="admin-alert" style="display:none; margin-bottom:14px; padding:10px 14px; border-radius:8px; font-size:0.84rem; font-weight:600;"></div>
        <div class="form-group-admin">
          <label>Program Code <span style="color:#ef4444;">*</span></label>
          <input type="text" name="code" id="editProgCode" required/>
        </div>
        <div class="form-group-admin">
          <label>Degree / Program Title <span style="color:#ef4444;">*</span></label>
          <input type="text" name="name" id="editProgName" required/>
        </div>
        <div class="form-group-admin">
          <label>Academic Department / College</label>
          <input type="text" name="department" id="editProgDept"/>
        </div>
        <div class="form-group-admin">
          <label>Status</label>
          <select name="status" id="editProgStatus">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('editProgramModal')">Cancel</button>
        <button type="submit" id="editProgSubmitBtn" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700;"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
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
  el.style.border     = `1.5px solid ${type === 'success' ? '#86efac' : '#fca5a5'}`;
  el.innerHTML        = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i> ` + msg;
  setTimeout(() => { el.style.display = 'none'; }, 4500);
}

function showModalAlert(alertId, msg, type) {
  const el = document.getElementById(alertId);
  if (!el) {
    showAlert(msg, type);
    return;
  }
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
  el.style.color      = type === 'success' ? '#166534' : '#991b1b';
  el.style.border     = `1.5px solid ${type === 'success' ? '#86efac' : '#fca5a5'}`;
  el.innerHTML        = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i> ` + msg;
}

function openModal(id) { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

function filterProgramTable() {
  const q = (document.getElementById('progSearchInput')?.value || '').toLowerCase().trim();
  const rows = document.querySelectorAll('.prog-row');
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

  const noMatchRow = document.getElementById('progTableNoMatch');
  if (noMatchRow) {
    noMatchRow.style.display = (matchedCount === 0 && rows.length > 0) ? '' : 'none';
  }

  const table = document.getElementById('academicProgTable');
  if (table && table._paginator) {
    table._paginator.currentPage = 1;
    table._paginator.render();
  }
}

function initAcademicProgPagination() {
  const tbl = document.getElementById('academicProgTable');
  if (tbl && window.initTablePagination && !tbl._paginator) {
    window.initTablePagination(tbl, {
      pageSize: 25,
      showPageSizeSelector: false,
      showInfo: false
    });
  }
}

function openAddProgramModal() {
  const form = document.getElementById('addProgramForm');
  if (form) form.reset();
  const alertEl = document.getElementById('addProgModalAlert');
  if (alertEl) { alertEl.style.display = 'none'; alertEl.innerHTML = ''; }
  const btn = document.getElementById('addProgSubmitBtn');
  if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-plus"></i> Save Program'; }
  openModal('addProgramModal');
}

function handleAddProgram(e) {
  e.preventDefault();
  const form = e.target;
  const btn = document.getElementById('addProgSubmitBtn');
  const alertEl = document.getElementById('addProgModalAlert');
  if (alertEl) { alertEl.style.display = 'none'; alertEl.innerHTML = ''; }

  const origBtnHtml = btn ? btn.innerHTML : '<i class="fa-solid fa-plus"></i> Save Program';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
  }

  const fd = new FormData(form);
  fd.append('action', 'create_academic_program');
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        if (res.id) {
          sessionStorage.setItem('highlight_academic_prog_id', res.id);
        }
        sessionStorage.setItem('flash_academic_msg', res.message || 'Academic program saved successfully.');
        closeModal('addProgramModal');
        location.reload();
      } else {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = origBtnHtml;
        }
        showModalAlert('addProgModalAlert', res.message || 'Failed to save academic program.', 'error');
      }
    })
    .catch(err => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = origBtnHtml;
      }
      showModalAlert('addProgModalAlert', 'A server or network error occurred. Please try again.', 'error');
    });
}

function openEditProgramModal(p) {
  document.getElementById('editProgId').value = p.id;
  document.getElementById('editProgCode').value = p.code;
  document.getElementById('editProgName').value = p.name;
  document.getElementById('editProgDept').value = p.department || '';
  document.getElementById('editProgStatus').value = p.status || 'Active';
  const alertEl = document.getElementById('editProgModalAlert');
  if (alertEl) { alertEl.style.display = 'none'; alertEl.innerHTML = ''; }
  const btn = document.getElementById('editProgSubmitBtn');
  if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes'; }
  openModal('editProgramModal');
}

function handleUpdateProgram(e) {
  e.preventDefault();
  const form = e.target;
  const btn = document.getElementById('editProgSubmitBtn');
  const alertEl = document.getElementById('editProgModalAlert');
  if (alertEl) { alertEl.style.display = 'none'; alertEl.innerHTML = ''; }

  const origBtnHtml = btn ? btn.innerHTML : '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
  }

  const fd = new FormData(form);
  fd.append('action', 'update_academic_program');
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        if (res.id) {
          sessionStorage.setItem('highlight_academic_prog_id', res.id);
        }
        sessionStorage.setItem('flash_academic_msg', res.message || 'Academic program updated successfully.');
        closeModal('editProgramModal');
        location.reload();
      } else {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = origBtnHtml;
        }
        showModalAlert('editProgModalAlert', res.message || 'Failed to update academic program.', 'error');
      }
    })
    .catch(err => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = origBtnHtml;
      }
      showModalAlert('editProgModalAlert', 'A server or network error occurred. Please try again.', 'error');
    });
}

async function deleteAcademicProgram(id, code) {
  const confirmed = await window.showConfirmModal(
    'Delete Academic Program?',
    `Do you want to delete academic program "${code}"? All linked records will be updated.`,
    { type: 'error', danger: true, confirmText: 'Yes, Delete Program' }
  );
  if (!confirmed) return;
  const fd = new FormData();
  fd.append('action', 'delete_academic_program');
  fd.append('id', id);
  if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        sessionStorage.setItem('flash_academic_msg', res.message || 'Academic program removed successfully.');
        location.reload();
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

document.addEventListener('DOMContentLoaded', () => {
  initAcademicProgPagination();

  // Handle flash message from redirect
  const flashMsg = sessionStorage.getItem('flash_academic_msg');
  if (flashMsg) {
    showAlert(flashMsg, 'success');
    sessionStorage.removeItem('flash_academic_msg');
  }

  // Handle highlight for newly created or updated program
  const highlightId = sessionStorage.getItem('highlight_academic_prog_id');
  if (highlightId) {
    sessionStorage.removeItem('highlight_academic_prog_id');
    const targetRow = document.getElementById('prog-row-' + highlightId);
    if (targetRow) {
      const tbl = document.getElementById('academicProgTable');
      if (tbl && tbl._paginator) {
        const rows = tbl._paginator.getAllRows();
        const rowIdx = rows.indexOf(targetRow);
        if (rowIdx !== -1) {
          const targetPage = Math.floor(rowIdx / tbl._paginator.pageSize) + 1;
          tbl._paginator.currentPage = targetPage;
          tbl._paginator.render();
        }
      }

      targetRow.classList.add('row-pulse-highlight');
      targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => {
        targetRow.classList.remove('row-pulse-highlight');
      }, 3500);
    }
  }
});
</script>
</body>
</html>
