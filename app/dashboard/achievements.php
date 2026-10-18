<?php
// ============================================================
//  ACHIEVEMENTS.PHP  (dashboard/)
//  2.8 Achievements & Organizational Recognition
//  Database-Driven Ledger with Full Workflow (Verify / Reject / Clarify)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../shared/security.php';
require_auth();
require_any_permission(['achievements.view', 'achievements.submit', 'achievements.verify.ssc', 'achievements.admin']);

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
if ($sess_role === 'student') {
    header('Location: dashboard.php');
    exit;
}
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// -- Role Scope Filter ----------------------------------------
$scope_where  = '';
$scope_params = [];
$scope_types  = '';
$adviser_club_id = null;

if ($sess_role === 'student') {
    // Students see their own submissions
    $scope_where  = 'WHERE a.submitted_by = ?';
    $scope_params = [$user_id];
    $scope_types  = 'i';
} elseif ($sess_role === 'club_adviser') {
    // Advisers see their assigned club's achievements
    $adviser_clubs = get_user_assigned_clubs($conn, $user_id, $sess_role);
    $adviser_club_ids = array_map('intval', array_column($adviser_clubs, 'id'));
    if (!empty($adviser_club_ids)) {
        $adviser_club_id = $adviser_club_ids[0];
        $scope_where  = 'WHERE a.club_id IN (' . implode(',', $adviser_club_ids) . ')';
        $scope_params = [];
        $scope_types  = '';
    } else {
        $scope_where  = 'WHERE 1=0';
    }
} else {
    // SSC and Admin have institutional scope across all clubs
    $scope_where  = 'WHERE 1=1';
}

// ── 5 Metric Cards Telemetry (Database Driven) ────────────────

// 1. Pending Verifications
$q_pending = "SELECT COUNT(*) FROM achievements a $scope_where AND a.status = 'Pending'";
$stmt = $conn->prepare($q_pending);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$stat_pending = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// 2. Verified Achievements
$q_verified = "SELECT COUNT(*) FROM achievements a $scope_where AND a.status = 'Verified'";
$stmt = $conn->prepare($q_verified);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$stat_verified = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// 3. Rejected Submissions
$q_rejected = "SELECT COUNT(*) FROM achievements a $scope_where AND a.status = 'Rejected'";
$stmt = $conn->prepare($q_rejected);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$stat_rejected = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// 4. Top Organizations (Participation / achievement distribution)
$q_top = "SELECT c.code, c.name, COUNT(a.id) as cnt
          FROM achievements a
          JOIN clubs c ON c.id = a.club_id
          $scope_where AND a.status = 'Verified'
          GROUP BY a.club_id
          ORDER BY cnt DESC, c.name ASC
          LIMIT 1";
$stmt = $conn->prepare($q_top);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$top_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$stat_top_org = $top_row ? $top_row['code'] : 'None';
$stat_top_org_count = $top_row ? (int)$top_row['cnt'] : 0;

// 5. Achievements This Year (Annual achievement volume)
$q_year = "SELECT COUNT(*) FROM achievements a $scope_where AND YEAR(a.award_date) = YEAR(CURDATE())";
$stmt = $conn->prepare($q_year);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$stat_this_year = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// ── Fetch Full Achievements Ledger ───────────────────────────
$sql = "SELECT a.id, a.title, a.competition, a.award_date, a.proof_file, a.status, a.notes, a.created_at,
               c.id AS club_id, c.name AS club_name, c.code AS club_code,
               u.id AS submitter_id, u.first_name AS sub_first, u.last_name AS sub_last, u.role AS sub_role,
               v.id AS verifier_id, v.first_name AS ver_first, v.last_name AS ver_last, v.role AS ver_role
        FROM achievements a
        JOIN clubs c ON c.id = a.club_id
        JOIN users u ON u.id = a.submitted_by
        LEFT JOIN users v ON v.id = a.verified_by
        $scope_where
        ORDER BY FIELD(a.status, 'Pending', 'Verified', 'Rejected'), a.award_date DESC, a.created_at DESC";
$stmt = $conn->prepare($sql);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$achievements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Club List for Submit Form --------------------------------
$clubs = [];
if ($sess_role === 'club_adviser') {
    $stmt_c = $conn->prepare(
        "SELECT c.id, c.name, c.code
         FROM clubs c
         WHERE (c.id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active')
            OR c.code = UPPER(SUBSTRING_INDEX(?, '.', 1)))
           AND c.status = 'Active'
         ORDER BY c.name"
    );
    $stmt_c->bind_param('is', $user_id, $sess_user);
    $stmt_c->execute();
    $clubs = $stmt_c->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_c->close();
} elseif ($sess_role === 'student') {
    $stmt_c = $conn->prepare(
        "SELECT c.id, c.name, c.code
         FROM clubs c
         JOIN club_memberships cm ON cm.club_id = c.id
         WHERE cm.user_id = ? AND cm.status = 'Active' AND c.status = 'Active'
         ORDER BY c.name"
    );
    $stmt_c->bind_param('i', $user_id);
    $stmt_c->execute();
    $clubs = $stmt_c->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_c->close();
} else {
    $clubs = get_user_assigned_clubs($conn, $user_id, $sess_role);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Achievements & Organizational Recognition – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    /* Metric Cards Grid */
    .metrics-grid-5 {
      display: grid !important;
      grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
      gap: 14px !important;
      margin-bottom: 22px !important;
      align-items: stretch !important;
    }
    @media (max-width: 1100px) {
      .metrics-grid-5 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
    }
    @media (max-width: 768px) {
      .metrics-grid-5 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 10px !important; }
    }
    @media (max-width: 480px) {
      .metrics-grid-5 { grid-template-columns: 1fr !important; }
    }

    /* Status Filter Tabs */
    .status-tab {
      padding: 6px 14px;
      font-size: 0.82rem;
      font-weight: 700;
      border-radius: 20px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #64748b;
      cursor: pointer;
      transition: all 0.15s ease;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .status-tab:hover {
      background: #e2e8f0;
      color: #1e293b;
    }
    .status-tab.active {
      background: #2563eb;
      color: #ffffff;
      border-color: #2563eb;
      box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
    }

    /* Modal Styling */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.65);
      z-index: 2100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
      backdrop-filter: blur(3px);
    }
    .modal-overlay.active {
      display: flex;
      animation: modalFadeIn 0.2s ease;
    }
    @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
    .modal-card {
      background: #ffffff;
      border-radius: 16px;
      width: 100%;
      max-width: 580px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.3);
      animation: modalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      box-sizing: border-box;
    }
    @keyframes modalSlideUp { from { transform: translateY(18px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-header {
      padding: 18px 24px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #f8fafc;
      border-radius: 16px 16px 0 0;
    }
    .modal-header h3 { margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
    .modal-body { padding: 22px 24px; box-sizing: border-box; }
    .modal-footer {
      padding: 14px 24px;
      border-top: 1px solid #e2e8f0;
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      background: #f8fafc;
      border-radius: 0 0 16px 16px;
    }
    .form-group { margin-bottom: 15px; }
    .form-group label {
      display: block;
      font-size: 0.78rem;
      font-weight: 700;
      color: #475569;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .form-group input, .form-group select, .form-group textarea {
      width: 100%;
      padding: 9px 12px;
      border: 1.5px solid #cbd5e1;
      border-radius: 8px;
      font-size: 0.88rem;
      color: #0f172a;
      box-sizing: border-box;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }
    .ach-alert {
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 16px;
      font-size: 0.86rem;
      font-weight: 500;
      display: none;
    }
    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 700;
      white-space: nowrap;
    }
    .badge-pending { background: #fef3c7; color: #b45309; }
    .badge-verified { background: #dcfce7; color: #15803d; }
    .badge-rejected { background: #fee2e2; color: #b91c1c; }
    .action-btn-group {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: nowrap;
      white-space: nowrap;
    }
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'achievements';
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
    <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
      <div>
        <h2 class="page-title" style="margin:0; font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:10px;">
          <i class="fa-solid fa-award" style="color:#2563eb;"></i>
          Club Achievement Records
        </h2>
        <div style="font-size:0.80rem; color:#64748b; margin-top:3px;">
          Institutional Honors &bull; Competition Laurels &bull; Official Student Recognition Ledger
        </div>
      </div>
      <?php if (can('achievements.submit')): ?>
        <div style="display:flex; gap:10px; align-items:center;">
          <button class="card-btn" onclick="openSubmitModal()" style="height:38px; padding:0 18px; border-radius:10px; font-weight:700; background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer; border:none; box-shadow:0 2px 6px rgba(37,99,235,0.25);">
            <i class="fa-solid fa-plus-circle"></i> Submit Achievement
          </button>
        </div>
      <?php endif; ?>
    </div>

    <div class="content-body">

      <!-- Alert Banner -->
      <div id="achAlert" class="ach-alert"></div>

      <!-- 5 Metric Cards (Pure Database-Driven & Zero Hardcoded Definitions) -->
      <div class="metrics-grid-5">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-hourglass-half" style="color:#d97706;"></i> Pending Verifications</div>
          <div class="card-amount"><?= number_format($stat_pending) ?></div>
        </div>

        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Verified Achievements</div>
          <div class="card-amount"><?= number_format($stat_verified) ?></div>
        </div>

        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Rejected Submissions</div>
          <div class="card-amount"><?= number_format($stat_rejected) ?></div>
        </div>

        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> Top Organizations</div>
          <div class="card-amount" style="font-size:1.35rem;"><?= htmlspecialchars($stat_top_org) ?></div>
        </div>

        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-calendar-check" style="color:#9333ea;"></i> Achievements This Year</div>
          <div class="card-amount"><?= number_format($stat_this_year) ?></div>
        </div>
      </div>

      <!-- Main Ledger Card -->
      <div class="card">
        <!-- Filter and Search Row -->
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <button type="button" class="status-tab active" data-filter="all" onclick="filterStatus('all', this)">
              All <span style="font-size:0.75rem; background:#e2e8f0; color:#1e293b; padding:1px 6px; border-radius:10px;"><?= count($achievements) ?></span>
            </button>
            <button type="button" class="status-tab" data-filter="Pending" onclick="filterStatus('Pending', this)">
              Pending <span style="font-size:0.75rem; background:#fef3c7; color:#b45309; padding:1px 6px; border-radius:10px;"><?= $stat_pending ?></span>
            </button>
            <button type="button" class="status-tab" data-filter="Verified" onclick="filterStatus('Verified', this)">
              Verified <span style="font-size:0.75rem; background:#dcfce7; color:#15803d; padding:1px 6px; border-radius:10px;"><?= $stat_verified ?></span>
            </button>
            <button type="button" class="status-tab" data-filter="Rejected" onclick="filterStatus('Rejected', this)">
              Rejected <span style="font-size:0.75rem; background:#fee2e2; color:#b91c1c; padding:1px 6px; border-radius:10px;"><?= $stat_rejected ?></span>
            </button>
          </div>
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <div style="position:relative; width:260px; max-width:100%;">
              <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
              <input type="text" id="achSearchInput" placeholder="Filter achievements..." oninput="filterAchTable()" style="width:100%; padding:8px 12px 8px 34px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.85rem; outline:none; box-sizing:border-box;"/>
            </div>
            <?php if (can('achievements.submit')): ?>
            <button type="button" class="card-btn" id="openSubmitBtn" style="background:#2563eb; color:#fff; font-weight:700; padding:8px 16px; border-radius:8px; display:inline-flex; align-items:center; gap:8px; border:none; cursor:pointer; font-size:0.85rem; white-space:nowrap; box-shadow:0 2px 6px rgba(37,99,235,0.22);">
              <i class="fa-solid fa-plus-circle"></i> Submit Achievement
            </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if (empty($achievements)): ?>
          <div style="text-align:center; padding:48px 16px; color:#94a3b8;">
            <i class="fa-solid fa-trophy" style="font-size:2.8rem; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
            <div style="font-weight:600; font-size:1rem; color:#64748b;">No achievements found</div>
            <div style="font-size:0.82rem; margin-top:4px;">No achievement records currently match your scope or query.</div>
          </div>
        <?php else: ?>
        <div class="table-wrap">
          <table id="achTable" class="table-wide">
            <thead>
              <tr>
                <th>Achievement</th>
                <th>Organization</th>
                <th>Competition</th>
                <th>Award Date</th>
                <th>Submitted By</th>
                <th>Evidence</th>
                <th>Status</th>
                <th>Verified By</th>
                <th style="text-align:center;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($achievements as $a): ?>
              <tr class="ach-table-row" data-status="<?= htmlspecialchars($a['status']) ?>" id="ach-row-<?= $a['id'] ?>">
                <!-- 1. Achievement Title -->
                <td>
                  <strong style="color:#0f172a; font-size:0.9rem;"><?= htmlspecialchars($a['title']) ?></strong>
                  <?php if (!empty($a['notes'])): ?>
                    <div style="font-size:0.75rem; color:#64748b; margin-top:2px; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($a['notes']) ?>">
                      <i class="fa-regular fa-comment-dots" style="color:#94a3b8; margin-right:3px;"></i><?= htmlspecialchars($a['notes']) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <!-- 2. Organization -->
                <td>
                  <div style="font-weight:600; color:#1e293b; font-size:0.86rem;"><?= htmlspecialchars($a['club_name']) ?></div>
                  <span style="font-size:0.70rem; background:#f1f5f9; color:#475569; padding:1px 6px; border-radius:4px; font-weight:700; display:inline-block; margin-top:2px;"><?= htmlspecialchars($a['club_code']) ?></span>
                </td>

                <!-- 3. Competition -->
                <td>
                  <span style="font-size:0.85rem; color:#334155; font-weight:500;"><?= htmlspecialchars($a['competition']) ?></span>
                </td>

                <!-- 4. Award Date -->
                <td>
                  <span style="font-size:0.84rem; color:#475569; white-space:nowrap;">
                    <i class="fa-regular fa-calendar" style="color:#94a3b8; margin-right:4px;"></i><?= date('M d, Y', strtotime($a['award_date'])) ?>
                  </span>
                </td>

                <!-- 5. Submitted By -->
                <td>
                  <div style="font-weight:600; color:#0f172a; font-size:0.84rem;"><?= htmlspecialchars($a['sub_first'] . ' ' . $a['sub_last']) ?></div>
                  <span style="font-size:0.70rem; color:#64748b; text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $a['sub_role'])) ?></span>
                </td>

                <!-- 6. Evidence -->
                <td>
                  <?php if (!empty($a['proof_file'])): ?>
                    <button type="button" class="card-btn" style="padding:4px 8px; font-size:0.75rem; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; border-radius:6px; cursor:pointer;" onclick="openProofModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)">
                      <i class="fa-solid fa-paperclip"></i> View Proof
                    </button>
                  <?php else: ?>
                    <span style="color:#94a3b8; font-size:0.76rem; font-style:italic;">No attachment</span>
                  <?php endif; ?>
                </td>

                <!-- 7. Status -->
                <td>
                  <?php if ($a['status'] === 'Verified'): ?>
                    <span class="badge-status badge-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>
                  <?php elseif ($a['status'] === 'Pending'): ?>
                    <span class="badge-status badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                  <?php else: ?>
                    <span class="badge-status badge-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                  <?php endif; ?>
                </td>

                <!-- 8. Verified By -->
                <td>
                  <?php if (!empty($a['ver_first'])): ?>
                    <div style="font-weight:600; color:#0f172a; font-size:0.84rem;"><?= htmlspecialchars($a['ver_first'] . ' ' . $a['ver_last']) ?></div>
                    <span style="font-size:0.68rem; background:#eff6ff; color:#2563eb; padding:1px 5px; border-radius:4px; font-weight:700;"><?= strtoupper(htmlspecialchars($a['ver_role'] ?? 'SSC')) ?></span>
                  <?php else: ?>
                    <span style="color:#94a3b8; font-style:italic; font-size:0.78rem;">Awaiting Review</span>
                  <?php endif; ?>
                </td>

                <!-- 9. Action (View proof / verify / reject / clarify) -->
                <td>
                  <div class="action-btn-group" style="justify-content:center; gap:4px; flex-wrap:nowrap;">
                    <?php if (can_any(['achievements.verify.ssc', 'achievements.admin'])): ?>
                      <?php if ($a['status'] === 'Pending'): ?>
                        <button type="button" class="card-btn" style="background:#16a34a; color:#fff; padding:4px 8px; font-size:0.73rem; border-radius:6px; border:none; cursor:pointer; white-space:nowrap;" title="Verify and endorse achievement" onclick="verifyAch(<?= $a['id'] ?>, 'verify')">
                          <i class="fa-solid fa-check"></i> Verify
                        </button>
                        <button type="button" class="card-btn btn-danger" style="padding:4px 8px; font-size:0.73rem; border-radius:6px; cursor:pointer; white-space:nowrap;" title="Reject submission" onclick="openRejectModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">
                          <i class="fa-solid fa-times"></i> Reject
                        </button>
                        <button type="button" class="card-btn" style="background:#f59e0b; color:#fff; padding:4px 8px; font-size:0.73rem; border-radius:6px; border:none; cursor:pointer; white-space:nowrap;" title="Request Clarification" onclick="openClarifyModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">
                          <i class="fa-solid fa-comment-dots"></i> Clarify
                        </button>
                      <?php else: ?>
                        <button type="button" class="card-btn btn-disabled" disabled style="background:#e2e8f0; color:#94a3b8; border:1px solid #cbd5e1; padding:4px 8px; font-size:0.73rem; border-radius:6px; cursor:not-allowed; opacity:0.65; white-space:nowrap;" title="Achievement already <?= htmlspecialchars(strtolower($a['status'])) ?>">
                          <i class="fa-solid fa-check"></i> Verify
                        </button>
                        <button type="button" class="card-btn btn-disabled" disabled style="background:#e2e8f0; color:#94a3b8; border:1px solid #cbd5e1; padding:4px 8px; font-size:0.73rem; border-radius:6px; cursor:not-allowed; opacity:0.65; white-space:nowrap;" title="Rejection unavailable (Status: <?= htmlspecialchars($a['status']) ?>)">
                          <i class="fa-solid fa-times"></i> Reject
                        </button>
                        <button type="button" class="card-btn" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; padding:4px 8px; font-size:0.73rem; border-radius:6px; cursor:pointer; white-space:nowrap;" title="View/Update Clarification Note" onclick="openClarifyModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">
                          <i class="fa-solid fa-comment-dots"></i> Clarify
                        </button>
                      <?php endif; ?>
                    <?php endif; ?>
                    <!-- Universal View Details & Proof button -->
                    <button type="button" class="card-btn" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:4px 8px; font-size:0.73rem; border-radius:6px; cursor:pointer; white-space:nowrap;" title="View Details & Proof" onclick="openProofModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)">
                      <i class="fa-solid fa-eye"></i> Details
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- end main -->

<!-- ── Submit Achievement Modal (Student / Adviser / SSC / Admin) ── -->
<div class="modal-overlay" id="submitAchModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-trophy" style="color:#2563eb;"></i> Submit Competition Achievement</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" id="closeAchModal" aria-label="Close modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label>Achievement Title *</label>
          <input type="text" id="achTitle" placeholder="e.g. 1st Place – National IT Hackathon 2026"/>
        </div>
        <div class="form-group">
          <label>Event / Competition *</label>
          <input type="text" id="achCompetition" placeholder="e.g. PSITE Student Convention 2026"/>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Date of Recognition *</label>
          <input type="date" id="achDate"/>
        </div>
        <div class="form-group">
          <label>Recipient Organization *</label>
          <select id="achClub">
            <?php if (empty($clubs)): ?>
              <option value="">-- No Active Joined Organizations (Join a club first) --</option>
            <?php else: ?>
              <?php if ($sess_role !== 'club_adviser'): ?>
                <option value="">-- Select Recipient Organization --</option>
              <?php endif; ?>
              <?php foreach ($clubs as $c): ?>
              <option value="<?= $c['id'] ?>" <?= ($sess_role === 'club_adviser' && $c['id'] == $adviser_club_id) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)
              </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Proof / Attachment Evidence (PDF, PNG, JPG)</label>
        <input type="file" id="achProofFile" accept=".pdf,.png,.jpg,.jpeg,.webp"/>
        <p style="font-size:0.75rem; color:#94a3b8; margin:4px 0 0;">Upload official certificate, medal photo, or formal ranking tally (max 5MB).</p>
      </div>
      <div class="form-group">
        <label>Notes / Description</label>
        <textarea id="achNotes" placeholder="Describe the achievement category, participants, or scope..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" id="cancelAchBtn">Cancel</button>
      <button class="card-btn" id="submitAchBtn" style="background:#2563eb; color:#fff; font-weight:700;">
        <i class="fa-solid fa-paper-plane"></i> Submit for Verification
      </button>
    </div>
  </div>
</div>

<!-- ── View Proof & Details Modal ────────────────────────────── -->
<div class="modal-overlay" id="proofDetailsModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3 id="proofModalTitle"><i class="fa-solid fa-award" style="color:#2563eb;"></i> Achievement Details &amp; Evidence</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" onclick="closeProofModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="proofModalBody">
      <!-- Populated dynamically via JS -->
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" onclick="closeProofModal()">Close</button>
    </div>
  </div>
</div>

<!-- ── Clarify Modal (SSC / Admin) ───────────────────────────── -->
<div class="modal-overlay" id="clarifyModal">
  <div class="modal-card" style="max-width:480px;">
    <div class="modal-header">
      <h3><i class="fa-solid fa-comment-dots" style="color:#f59e0b;"></i> Request Clarification</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" onclick="closeClarifyModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="clarifyAchId"/>
      <div style="font-weight:600; color:#0f172a; margin-bottom:8px;" id="clarifyAchTitle"></div>
      <div class="form-group">
        <label>Clarification Notes / Required Documents *</label>
        <textarea id="clarifyNotes" style="min-height:90px;" placeholder="Specify what information or evidence needs to be clarified or submitted..."></textarea>
      </div>
      <p style="font-size:0.75rem; color:#64748b; margin:0;">The submitter will receive an instant notification with this instruction.</p>
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" onclick="closeClarifyModal()">Cancel</button>
      <button class="card-btn" id="submitClarifyBtn" style="background:#f59e0b; color:#fff; font-weight:700;" onclick="submitClarify()">
        <i class="fa-solid fa-paper-plane"></i> Send Clarification
      </button>
    </div>
  </div>
</div>

<!-- ── Reject Modal (SSC / Admin) ────────────────────────────── -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal-card" style="max-width:480px;">
    <div class="modal-header">
      <h3><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Reject Achievement Submission</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" onclick="closeRejectModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="rejectAchId"/>
      <div style="font-weight:600; color:#0f172a; margin-bottom:8px;" id="rejectAchTitle"></div>
      <div class="form-group">
        <label>Reason for Rejection *</label>
        <textarea id="rejectNotes" style="min-height:90px;" placeholder="Explain why this achievement or evidence was rejected..."></textarea>
      </div>
      <p style="font-size:0.75rem; color:#dc2626; margin:0;">This submission will be marked as Rejected on the official ledger.</p>
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" onclick="closeRejectModal()">Cancel</button>
      <button class="card-btn btn-danger" id="submitRejectBtn" style="font-weight:700;" onclick="submitReject()">
        <i class="fa-solid fa-times"></i> Confirm Rejection
      </button>
    </div>
  </div>
</div>

<script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
<script src="../js/table-pagination.js"></script>
<script>
// -- Alert Helper ----------------------------------------------
function showAlert(msg, type) {
  const el = document.getElementById('achAlert');
  if (!el) return;
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
  el.style.color      = type === 'success' ? '#166534' : '#991b1b';
  el.style.border     = `1px solid ${type === 'success' ? '#bbf7d0' : '#fecaca'}`;
  el.textContent = msg;
  window.scrollTo({ top: 0, behavior: 'smooth' });
  setTimeout(() => { el.style.display = 'none'; }, 5000);
}

// -- Live Filter by Status & Search ---------------------------
let currentStatusFilter = 'all';

function filterStatus(status, btn) {
  currentStatusFilter = status;
  document.querySelectorAll('.status-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  applyFilters();
}

function filterAchTable() {
  applyFilters();
}

function applyFilters() {
  const q = document.getElementById('achSearchInput')?.value.toLowerCase().trim() || '';
  document.querySelectorAll('.ach-table-row').forEach(row => {
    const rowStatus = row.dataset.status;
    const matchesStatus = (currentStatusFilter === 'all' || rowStatus === currentStatusFilter);
    const matchesSearch = !q || row.textContent.toLowerCase().includes(q);
    row.style.display = (matchesStatus && matchesSearch) ? '' : 'none';
  });
}

// -- Submit Modal Handling ------------------------------------
const submitModal = document.getElementById('submitAchModal');
document.getElementById('openSubmitBtn')?.addEventListener('click', () => { submitModal?.classList.add('active'); });
document.getElementById('closeAchModal')?.addEventListener('click', () => { submitModal?.classList.remove('active'); });
document.getElementById('cancelAchBtn')?.addEventListener('click', () => { submitModal?.classList.remove('active'); });
submitModal?.addEventListener('click', e => { if (e.target === submitModal) submitModal.classList.remove('active'); });

document.getElementById('submitAchBtn')?.addEventListener('click', function() {
  const title       = document.getElementById('achTitle').value.trim();
  const competition = document.getElementById('achCompetition').value.trim();
  const date        = document.getElementById('achDate').value;
  const club_id     = document.getElementById('achClub').value;
  const notes       = document.getElementById('achNotes').value.trim();
  const fileInput   = document.getElementById('achProofFile');

  if (!title || !competition || !date || !club_id) {
    showAlert('Please fill in all required fields (Title, Event, Date, Organization).', 'error');
    return;
  }

  this.disabled = true;
  this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

  const fd = new FormData();
  fd.set('action', 'submit');
  fd.set('title', title);
  fd.set('competition', competition);
  fd.set('award_date', date);
  fd.set('club_id', club_id);
  fd.set('notes', notes);
  if (fileInput && fileInput.files.length > 0) {
    fd.append('proof_file', fileInput.files[0]);
  }

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      this.disabled = false;
      this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit for Verification';
      if (data.success) {
        submitModal.classList.remove('active');
        showAlert(data.message || 'Achievement submitted for SSC verification.', 'success');
        ['achTitle','achCompetition','achDate','achNotes'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.value = '';
        });
        if (fileInput) fileInput.value = '';
        setTimeout(() => location.reload(), 1400);
      } else {
        showAlert(data.message || 'Submission failed.', 'error');
      }
    })
    .catch(() => {
      this.disabled = false;
      this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit for Verification';
      showAlert('Network error occurred. Please try again.', 'error');
    });
});

// -- Proof & Details Modal ------------------------------------
const proofModal = document.getElementById('proofDetailsModal');
function closeProofModal() { proofModal?.classList.remove('active'); }
proofModal?.addEventListener('click', e => { if (e.target === proofModal) closeProofModal(); });

function openProofModal(item) {
  const body = document.getElementById('proofModalBody');
  if (!body) return;

  let proofContent = '<span style="color:#94a3b8; font-style:italic;">No file attachment submitted.</span>';
  if (item.proof_file) {
    const fileUrl = '../uploads/achievements/' + encodeURIComponent(item.proof_file);
    const isImg = item.proof_file.match(/\.(jpg|jpeg|png|webp)$/i);
    if (isImg) {
      proofContent = `
        <div style="margin-top:8px; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; background:#0f172a; text-align:center;">
          <img src="${fileUrl}" style="max-width:100%; max-height:340px; object-fit:contain;" alt="Proof Evidence"/>
        </div>
        <div style="margin-top:8px; text-align:right;">
          <a href="${fileUrl}" target="_blank" class="card-btn" style="display:inline-flex; align-items:center; gap:6px; background:#eff6ff; color:#2563eb; text-decoration:none;">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Full Image
          </a>
        </div>
      `;
    } else {
      proofContent = `
        <div style="margin-top:8px; padding:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; display:flex; align-items:center; justify-content:space-between;">
          <div style="display:flex; align-items:center; gap:10px;">
            <i class="fa-solid fa-file-pdf" style="font-size:2rem; color:#dc2626;"></i>
            <div>
              <div style="font-weight:600; color:#0f172a; font-size:0.88rem;">${item.proof_file}</div>
              <div style="font-size:0.75rem; color:#64748b;">PDF Document Attachment</div>
            </div>
          </div>
          <a href="${fileUrl}" target="_blank" class="card-btn" style="background:#2563eb; color:#fff; text-decoration:none;">
            <i class="fa-solid fa-file-arrow-down"></i> View / Download PDF
          </a>
        </div>
      `;
    }
  }

  let statusBadge = '';
  if (item.status === 'Verified') {
    statusBadge = '<span class="badge-status badge-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>';
  } else if (item.status === 'Pending') {
    statusBadge = '<span class="badge-status badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>';
  } else {
    statusBadge = '<span class="badge-status badge-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>';
  }

  body.innerHTML = `
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:14px;">
      <div>
        <h4 style="margin:0; font-size:1.15rem; color:#0f172a; font-weight:700;">${item.title}</h4>
        <div style="color:#2563eb; font-weight:600; font-size:0.88rem; margin-top:2px;">${item.competition}</div>
      </div>
      <div>${statusBadge}</div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; font-size:0.84rem;">
      <div style="background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
        <span style="color:#64748b; font-size:0.75rem; font-weight:700; text-transform:uppercase; display:block;">Recipient Organization</span>
        <strong style="color:#0f172a;">${item.club_name} (${item.club_code})</strong>
      </div>
      <div style="background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
        <span style="color:#64748b; font-size:0.75rem; font-weight:700; text-transform:uppercase; display:block;">Date of Recognition</span>
        <strong style="color:#0f172a;">${item.award_date}</strong>
      </div>
      <div style="background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
        <span style="color:#64748b; font-size:0.75rem; font-weight:700; text-transform:uppercase; display:block;">Submitted By</span>
        <strong style="color:#0f172a;">${item.sub_first} ${item.sub_last}</strong> (${item.sub_role})
      </div>
      <div style="background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
        <span style="color:#64748b; font-size:0.75rem; font-weight:700; text-transform:uppercase; display:block;">Verified By</span>
        <strong style="color:#0f172a;">${item.ver_first ? (item.ver_first + ' ' + item.ver_last) : 'Awaiting Review'}</strong>
      </div>
    </div>

    ${item.notes ? `
    <div style="margin-bottom:16px; background:#eff6ff; border-left:4px solid #2563eb; padding:10px 14px; border-radius:0 8px 8px 0;">
      <div style="font-size:0.75rem; font-weight:700; color:#1e40af; text-transform:uppercase; margin-bottom:4px;">Notes &amp; Review Remarks</div>
      <div style="font-size:0.86rem; color:#1e3a8a;">${item.notes}</div>
    </div>
    ` : ''}

    <div>
      <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:6px; text-transform:uppercase;">Proof &amp; Evidence Document</label>
      ${proofContent}
    </div>
  `;

  proofModal?.classList.add('active');
}

// -- Clarify Modal --------------------------------------------
const clarifyModal = document.getElementById('clarifyModal');
function closeClarifyModal() { clarifyModal?.classList.remove('active'); }
clarifyModal?.addEventListener('click', e => { if (e.target === clarifyModal) closeClarifyModal(); });

function openClarifyModal(id, title) {
  document.getElementById('clarifyAchId').value = id;
  document.getElementById('clarifyAchTitle').textContent = 'Achievement: ' + title;
  document.getElementById('clarifyNotes').value = '';
  clarifyModal?.classList.add('active');
}

function submitClarify() {
  const id    = document.getElementById('clarifyAchId').value;
  const notes = document.getElementById('clarifyNotes').value.trim();
  const btn   = document.getElementById('submitClarifyBtn');

  if (!notes) {
    window.alert('Please enter clarification notes for the requester.', 'warning');
    return;
  }

  const confirmed = await window.showConfirmModal(
    'Request Clarification?',
    'Do you want to request clarification from the student for this achievement application?',
    { type: 'warning', warning: true, confirmText: 'Yes, Send Clarification' }
  );
  if (!confirmed) return;

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';

  const fd = new FormData();
  fd.set('action', 'clarify');
  fd.set('id', id);
  fd.set('notes', notes);

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Clarification';
      if (data.success) {
        closeClarifyModal();
        showAlert(data.message || 'Clarification request saved and sent.', 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        window.alert(data.message || 'Failed to send clarification.', 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Clarification';
      window.alert('Network error occurred.', 'error');
    });
}

// -- Reject Modal ---------------------------------------------
const rejectModal = document.getElementById('rejectModal');
function closeRejectModal() { rejectModal?.classList.remove('active'); }
rejectModal?.addEventListener('click', e => { if (e.target === rejectModal) closeRejectModal(); });

function openRejectModal(id, title) {
  document.getElementById('rejectAchId').value = id;
  document.getElementById('rejectAchTitle').textContent = 'Achievement: ' + title;
  document.getElementById('rejectNotes').value = '';
  rejectModal?.classList.add('active');
}

async function submitReject() {
  const id    = document.getElementById('rejectAchId').value;
  const notes = document.getElementById('rejectNotes').value.trim();
  const btn   = document.getElementById('submitRejectBtn');

  if (!notes) {
    window.alert('Please provide a reason for rejection.', 'warning');
    return;
  }

  const confirmed = await window.showConfirmModal(
    'Reject Achievement Application?',
    'Do you want to reject this achievement application with the stated reason?',
    { type: 'error', danger: true, confirmText: 'Yes, Reject Application' }
  );
  if (!confirmed) return;

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rejecting...';

  const fd = new FormData();
  fd.set('action', 'reject');
  fd.set('id', id);
  fd.set('notes', notes);

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-times"></i> Confirm Rejection';
      if (data.success) {
        closeRejectModal();
        showAlert(data.message || 'Achievement marked as Rejected.', 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        window.alert(data.message || 'Failed to reject.', 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-times"></i> Confirm Rejection';
      window.alert('Network error occurred.', 'error');
    });
}

// -- Direct Verify Action (SSC / Admin) -----------------------
async function verifyAch(id, action) {
  if (action === 'verify') {
    const confirmed = await window.showConfirmModal(
      'Verify Achievement Application?',
      'Do you want to verify and endorse this achievement application on the official ledger?',
      { type: 'decision', confirmText: 'Yes, Verify & Endorse' }
    );
    if (!confirmed) return;
  }

  const fd = new FormData();
  fd.set('action', action);
  fd.set('id', id);

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert(data.message, 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        showAlert(data.message, 'error');
      }
    })
    .catch(() => showAlert('Network error occurred.', 'error'));
}
</script>
</body>
</html>
