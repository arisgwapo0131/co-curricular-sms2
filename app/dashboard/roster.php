<?php
// ============================================================
//  ROSTER.PHP  (dashboard/)
//  Co-Curricular System — Membership Roster Module (RBAC Enforced, Live DB)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_auth();
require_any_permission(['membership.review.all', 'membership.review.own', 'membership.view_own', 'membership.apply', 'membership.manage.all']);

$sess_first = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id = (int) $_SESSION['user_id'];

// -- Fetch data based on role ----------------------------------

// My memberships (Student) — Only show organizations where application is approved by the SSC
$my_memberships = [];
$my_pending_applications = [];
if ($sess_role === 'student') {
    $stmt = $conn->prepare(
        "SELECT cm.id, cm.club_id, cm.role AS member_role, cm.status, cm.joined_at, cm.adviser_review, cm.ssc_review,
                c.name AS club_name, c.code AS club_code, c.category, c.description, c.adviser_name
         FROM club_memberships cm
         JOIN clubs c ON c.id = cm.club_id
         WHERE cm.user_id = ?
           AND cm.status = 'Active'
         ORDER BY cm.joined_at DESC"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $my_memberships = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Applications still awaiting SSC approval
    $p_stmt = $conn->prepare(
        "SELECT cm.id, cm.club_id, cm.role AS member_role, cm.status, cm.joined_at, cm.adviser_review, cm.ssc_review,
                COALESCE(ca.letter_intent, cm.letter_intent) AS letter_intent,
                COALESCE(ca.letter_endorsement, cm.letter_endorsement) AS letter_endorsement,
                ca.motivation, cm.review_notes,
                c.name AS club_name, c.code AS club_code, c.category, c.description, c.adviser_name,
                u.first_name, u.last_name, s.student_number, s.course, s.year_level
         FROM club_memberships cm
         JOIN clubs c ON c.id = cm.club_id
         JOIN users u ON u.id = cm.user_id
         LEFT JOIN club_applications ca ON (ca.club_id = cm.club_id AND ca.user_id = cm.user_id)
         LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
         WHERE cm.user_id = ?
           AND cm.status = 'Pending'
         ORDER BY cm.joined_at DESC"
    );
    $p_stmt->bind_param('i', $user_id);
    $p_stmt->execute();
    $my_pending_applications = $p_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $p_stmt->close();

    // Also include any pending application from club_applications if exists and not already covered
    $app_chk = $conn->query("SHOW TABLES LIKE 'club_applications'");
    if ($app_chk && $app_chk->num_rows > 0) {
        $existing_club_ids = array_column($my_pending_applications, 'club_id');
        $active_club_ids = array_column($my_memberships, 'club_id');
        $exclude_ids = array_merge($existing_club_ids, $active_club_ids);

        $ca_stmt = $conn->prepare(
            "SELECT ca.id, ca.club_id, 'Applicant' AS member_role, ca.status, ca.applied_at AS joined_at,
                    ca.adviser_status AS adviser_review, 'Pending SSC' AS ssc_review,
                    ca.letter_intent, ca.letter_endorsement, ca.motivation, ca.review_notes,
                    c.name AS club_name, c.code AS club_code, c.category, c.description, c.adviser_name,
                    u.first_name, u.last_name, s.student_number, s.course, s.year_level
             FROM club_applications ca
             JOIN clubs c ON c.id = ca.club_id
             JOIN users u ON u.id = ca.user_id
             LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
             WHERE ca.user_id = ? AND ca.status IN ('Pending', 'PENDING_ADVISER', 'PENDING_SSC')
             ORDER BY ca.applied_at DESC"
        );
        if ($ca_stmt) {
            $ca_stmt->bind_param('i', $user_id);
            $ca_stmt->execute();
            $ca_apps = $ca_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $ca_stmt->close();
            foreach ($ca_apps as $ca_app) {
                if (!in_array($ca_app['club_id'], $exclude_ids)) {
                    $my_pending_applications[] = $ca_app;
                }
            }
        }
    }
}

// Fetch all accredited clubs assigned to user / visible to role with member counts
$all_clubs = get_user_assigned_clubs($conn, $user_id, $sess_role);
$adviser_club_ids = array_map('intval', array_column($all_clubs, 'id'));
$adviser_club_codes = array_column($all_clubs, 'code');

$my_club_id = null;
$my_club_name = '';
$my_club_code = '';

if ($sess_role === 'club_adviser' && !empty($all_clubs)) {
    $my_club_id = (int)$all_clubs[0]['id'];
    $my_club_name = $all_clubs[0]['name'];
    $my_club_code = $all_clubs[0]['code'];
}

// Fetch active club members for organization roster view
$all_org_members = [];
if ($sess_role === 'club_adviser') {
    if (!empty($adviser_club_ids)) {
        $in_clubs = implode(',', $adviser_club_ids);
        $res_members = $conn->query(
            "SELECT cm.id, cm.club_id, cm.role AS member_role, cm.status, cm.joined_at,
                    c.name AS club_name, c.code AS club_code, c.category AS club_category, c.adviser_name,
                    u.id AS user_id, u.username, u.first_name, u.last_name, u.email,
                    s.student_number, s.course, s.year_level, s.section, s.phone, s.birthday,
                    COALESCE(s.student_number, CONCAT('2024-', 10000 + u.id)) AS student_id_no
             FROM club_memberships cm
             JOIN clubs c ON c.id = cm.club_id
             JOIN users u ON u.id = cm.user_id
             LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
             WHERE cm.status = 'Active' AND cm.club_id IN ($in_clubs)
               AND u.role NOT IN ('club_adviser', 'admin')
               AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%'
             ORDER BY c.name, cm.joined_at DESC"
        );
        if ($res_members) {
            $all_org_members = $res_members->fetch_all(MYSQLI_ASSOC);
        }
    }
} else {
    $res_members = $conn->query(
        "SELECT cm.id, cm.club_id, cm.role AS member_role, cm.status, cm.joined_at,
                c.name AS club_name, c.code AS club_code, c.category AS club_category, c.adviser_name,
                u.id AS user_id, u.username, u.first_name, u.last_name, u.email,
                s.student_number, s.course, s.year_level, s.section, s.phone, s.birthday,
                COALESCE(s.student_number, CONCAT('2024-', 10000 + u.id)) AS student_id_no
         FROM club_memberships cm
         JOIN clubs c ON c.id = cm.club_id
         JOIN users u ON u.id = cm.user_id
         LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
         WHERE cm.status = 'Active'
         ORDER BY c.name, cm.joined_at DESC"
    );
    if ($res_members) {
        $all_org_members = $res_members->fetch_all(MYSQLI_ASSOC);
    }
}

// Pending / Review Queue applicants (Adviser, SSC, Admin)
$pending_applicants = [];

if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
  $club_filter = '';
  if ($sess_role === 'club_adviser') {
    if (!empty($adviser_club_ids)) {
      $club_filter = 'AND cm.club_id IN (' . implode(',', $adviser_club_ids) . ')';
    } else {
      $club_filter = 'AND 1=0';
    }
  }

  $sql = "SELECT cm.id, cm.club_id, cm.user_id, cm.joined_at, 
                 COALESCE(ca.letter_intent, cm.letter_intent) AS letter_intent, 
                 COALESCE(ca.letter_endorsement, cm.letter_endorsement) AS letter_endorsement,
                 cm.status AS current_status,
                 cm.adviser_review,
                 cm.ssc_review,
                 COALESCE(ca.applied_at, cm.joined_at) AS submitted_date,
                 c.name AS club_name, c.code AS club_code, c.category AS club_category,
                 COALESCE(NULLIF(ca.first_name,''), u.first_name) AS first_name,
                 COALESCE(NULLIF(ca.last_name,''), u.last_name) AS last_name,
                 COALESCE(NULLIF(ca.email,''), u.email) AS email,
                 COALESCE(NULLIF(ca.student_id_no,''), s.student_number, CONCAT('2024-', 10000 + u.id)) AS student_id_no,
                 COALESCE(NULLIF(ca.course,''), s.course, 'BSIT') AS course,
                 COALESCE(NULLIF(ca.year_level,''), s.year_level, '1st Year') AS year_level,
                 COALESCE(NULLIF(ca.phone,''), s.phone) AS phone,
                 ca.sex, ca.dob, ca.address, ca.motivation,
                 cm.review_notes AS review_notes
          FROM club_memberships cm
          JOIN clubs c ON c.id = cm.club_id
          JOIN users u ON u.id = cm.user_id
          LEFT JOIN club_applications ca ON (ca.id = (SELECT MAX(id) FROM club_applications WHERE club_id = cm.club_id AND user_id = cm.user_id))
          LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
          WHERE LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%' $club_filter
          ORDER BY FIELD(cm.status, 'Pending', 'Returned', 'Rejected', 'Active'), cm.joined_at DESC";
  $res_p = $conn->query($sql);
  $pending_applicants = $res_p ? $res_p->fetch_all(MYSQLI_ASSOC) : [];
}

// Active members (Adviser, SSC, Admin)
$active_members = [];
if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
  if ($sess_role === 'club_adviser') {
    $active_members = $all_org_members;
  } else {
    $sql = "SELECT cm.id, cm.club_id, cm.role AS member_role, cm.status, cm.joined_at,
                   c.name AS club_name, c.code AS club_code, c.category AS club_category, c.adviser_name,
                   u.id AS user_id, u.username, u.first_name, u.last_name, u.email,
                   s.student_number, s.course, s.year_level, s.section, s.phone, s.birthday,
                   COALESCE(s.student_number, CONCAT('2024-', 10000 + u.id)) AS student_id_no
            FROM club_memberships cm
            JOIN clubs c ON c.id = cm.club_id
            JOIN users u ON u.id = cm.user_id
            LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
            WHERE cm.status = 'Active'
              AND u.role NOT IN ('club_adviser', 'admin')
              AND LOWER(cm.role) != 'adviser'
              AND cm.role NOT LIKE '%adviser%'
            ORDER BY u.first_name ASC, u.last_name ASC";
    $res_a = $conn->query($sql);
    $active_members = $res_a ? $res_a->fetch_all(MYSQLI_ASSOC) : [];
  }
}

// Attach all active joined clubs for every student in queue and rosters
$all_relevant_uids = array_unique(array_filter(array_merge(
  array_column($pending_applicants, 'user_id'),
  array_column($active_members, 'user_id'),
  array_column($all_org_members, 'user_id')
)));

$student_joined_clubs_map = [];
if (!empty($all_relevant_uids)) {
  $uid_list = implode(',', array_map('intval', $all_relevant_uids));
  $jc_res = $conn->query("
    SELECT cm.user_id, cm.club_id, cm.role AS member_role, cm.status, cm.joined_at,
           c.name AS club_name, c.code AS club_code, c.category AS club_category
    FROM club_memberships cm
    JOIN clubs c ON c.id = cm.club_id
    WHERE cm.user_id IN ($uid_list) AND cm.status = 'Active' AND LOWER(cm.role) != 'adviser'
    ORDER BY cm.joined_at DESC
  ");
  if ($jc_res) {
    while ($r = $jc_res->fetch_assoc()) {
      $student_joined_clubs_map[(int)$r['user_id']][] = $r;
    }
  }
}

foreach ($pending_applicants as &$ap) {
  $ap['joined_clubs'] = $student_joined_clubs_map[(int)$ap['user_id']] ?? [];
}
unset($ap);

foreach ($active_members as &$m) {
  $m['joined_clubs'] = $student_joined_clubs_map[(int)$m['user_id']] ?? [];
}
unset($m);

foreach ($all_org_members as &$m) {
  $m['joined_clubs'] = $student_joined_clubs_map[(int)$m['user_id']] ?? [];
}
unset($m);

// 4 KPI Summary Cards Stats (2.3 Membership & Roster Oversight Specification)
$pending_count = 0;
$active_count = 0;
$rejected_count = 0;
$top_org_code = 'None';
$top_org_count = 0;
$top_org_name = 'No Organizations';

if (in_array($sess_role, ['ssc', 'admin'])) {
  $p_res = $conn->query("SELECT COUNT(*) FROM club_memberships WHERE status = 'Pending' AND LOWER(role) != 'adviser'");
  if ($p_res) $pending_count = (int)$p_res->fetch_row()[0];

  $a_res = $conn->query("SELECT COUNT(*) FROM club_memberships WHERE status = 'Active' AND LOWER(role) != 'adviser'");
  if ($a_res) $active_count = (int)$a_res->fetch_row()[0];

  $r_res = $conn->query("SELECT COUNT(*) FROM club_memberships WHERE status = 'Rejected' AND LOWER(role) != 'adviser'");
  if ($r_res) $rejected_count = (int)$r_res->fetch_row()[0];

  $top_res = $conn->query("
      SELECT c.code, c.name, COUNT(cm.id) AS cnt
      FROM clubs c
      JOIN club_memberships cm ON cm.club_id = c.id AND cm.status = 'Active' AND LOWER(cm.role) != 'adviser'
      WHERE c.deleted_at IS NULL
      GROUP BY c.id
      ORDER BY cnt DESC, c.name ASC
      LIMIT 1
  ");
  if ($top_res && $top_row = $top_res->fetch_assoc()) {
      $top_org_code = $top_row['code'];
      $top_org_count = (int)$top_row['cnt'];
      $top_org_name = $top_row['name'];
  }
} elseif ($sess_role === 'club_adviser') {
  $pending_count = count(array_filter($pending_applicants, fn($x) => $x['current_status'] === 'Pending'));
  $active_count = count($active_members);
  $rejected_count = count(array_filter($pending_applicants, fn($x) => $x['current_status'] === 'Rejected'));
  $top_org_code = !empty($my_club_code) ? $my_club_code : 'My Org';
  $top_org_count = $active_count;
  $top_org_name = !empty($my_club_name) ? $my_club_name : 'Assigned Organization';
}

// Club Selection & Deep Linking from Governance / Directory
$selected_club_code = trim($_GET['club_code'] ?? ($_GET['filter_org'] ?? ''));
$selected_club_id = (int)($_GET['club_id'] ?? 0);
$selected_club_info = null;

if (!empty($selected_club_code)) {
  foreach ($all_clubs as $cl) {
    if (strcasecmp($cl['code'], $selected_club_code) === 0) {
      $selected_club_info = $cl;
      $selected_club_id = (int)$cl['id'];
      $selected_club_code = $cl['code'];
      break;
    }
  }
}
if (!$selected_club_info && !empty($selected_club_id)) {
  foreach ($all_clubs as $cl) {
    if ((int)$cl['id'] === $selected_club_id) {
      $selected_club_info = $cl;
      $selected_club_code = $cl['code'];
      break;
    }
  }
}

// If coming with a club selected or view=roster, default view to roster
if (!empty($selected_club_code) || !empty($selected_club_id)) {
  $active_view = $_GET['view'] ?? 'roster';
} else {
  $active_view = $_GET['view'] ?? 'queue';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Membership Roster — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    /* ═══════════════════════════════════════════════════════════════
       RESPONSIVE MODAL SYSTEM (Phone, Tablet & Desktop)
       ═══════════════════════════════════════════════════════════════ */
    .roster-modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.72);
      backdrop-filter: blur(5px);
      -webkit-backdrop-filter: blur(5px);
      z-index: 9999;
      align-items: center;
      justify-content: center;
      padding: 16px;
      box-sizing: border-box;
      overflow-y: auto;
    }
    .roster-modal-card {
      background: #ffffff;
      border-radius: 16px;
      width: 100%;
      max-width: 720px;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
      border: 1px solid #cbd5e1;
      overflow: hidden;
      margin: auto;
      box-sizing: border-box;
    }
    .roster-modal-header {
      padding: 16px 20px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #ffffff;
      flex-shrink: 0;
      gap: 12px;
    }
    .roster-modal-body {
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      overflow-y: auto;
      flex: 1 1 auto;
      min-height: 0;
      -webkit-overflow-scrolling: touch;
    }
    .roster-modal-footer {
      padding: 14px 20px;
      border-top: 1px solid #e2e8f0;
      background: #f8fafc;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
      flex-shrink: 0;
    }

    /* Stepper & Profile Grid defaults (Desktop/Tablet) */
    .review-profile-meta-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      font-size: 0.8rem;
    }
    .review-stepper-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
    }

    /* ── Tablet Breakpoint (≤ 768px) ────────────────────────── */
    @media (max-width: 768px) {
      .roster-modal-overlay {
        padding: 12px;
      }
      .roster-modal-card {
        max-width: 95vw;
        max-height: 92vh;
        border-radius: 14px;
      }
      .roster-modal-header {
        padding: 14px 16px;
      }
      .roster-modal-body {
        padding: 16px;
        gap: 14px;
      }
      .roster-modal-footer {
        padding: 12px 16px;
      }
      .review-profile-meta-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
      }
      .review-stepper-grid {
        grid-template-columns: 1fr;
        gap: 8px;
      }
    }

    /* ── Mobile Phone Breakpoint (≤ 480px) ───────────────────── */
    @media (max-width: 480px) {
      .roster-modal-overlay {
        padding: 8px;
        align-items: flex-end; /* Bottom-sheet feel on small mobile screens */
      }
      .roster-modal-card {
        max-width: 100vw;
        width: 100%;
        max-height: 94vh;
        border-radius: 16px 16px 0 0;
        margin-bottom: 0;
      }
      .roster-modal-header {
        padding: 12px 14px;
      }
      .roster-modal-body {
        padding: 14px;
        gap: 12px;
      }
      .roster-modal-footer {
        padding: 12px 14px;
        flex-direction: column-reverse;
        align-items: stretch;
        gap: 8px;
      }
      .roster-modal-footer > button,
      .roster-modal-footer #reviewModalActionBtns {
        width: 100%;
      }
      .roster-modal-footer #reviewModalActionBtns {
        flex-direction: column;
        align-items: stretch;
      }
      .roster-modal-footer #reviewModalActionBtns button,
      .roster-modal-footer #reviewModalActionBtns a {
        width: 100%;
        justify-content: center;
        text-align: center;
      }
      .review-profile-meta-grid {
        grid-template-columns: 1fr;
        gap: 8px;
      }
      .review-stepper-grid {
        grid-template-columns: 1fr;
        gap: 6px;
      }
      .profile-top-strip {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 8px !important;
      }
      .profile-top-strip .org-right-badge {
        text-align: left !important;
      }

      /* Document Viewer Modal Mobile Tweaks */
      #applicationDocsModal .roster-modal-card {
        max-width: 100vw;
        width: 100%;
        height: 100vh;
        max-height: 100vh;
        border-radius: 0;
      }
      #applicationDocsModal .roster-modal-header {
        padding: 10px 12px;
      }
      #applicationDocsModal .roster-modal-header > div:first-child {
        width: 100%;
        margin-bottom: 6px;
      }
      #applicationDocsModal .roster-modal-header > div:last-child {
        width: 100%;
        justify-content: space-between;
      }
      #docsModalActionBtns {
        width: 100%;
        flex-direction: column;
      }
      #docsModalActionBtns button {
        width: 100%;
      }
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'roster';
  $ACTIVE_SUB = $active_view;
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
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i
            class="fa-solid fa-qrcode"></i></button>
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

      <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
        <div>
          <h2 class="page-title" style="margin:0; font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:10px;">
            <i class="fa-solid fa-users" style="color:#2563eb;"></i>
            Student Club Memberships
          </h2>
          <div style="font-size:0.80rem; color:#64748b; margin-top:3px;">
            <?= ($sess_role === 'student') ? 'Official Co-Curricular Club Memberships &bull; Application Status &bull; Active Roster' : 'Membership Verification Queue &bull; Student Roster Oversight &bull; Roster Management' ?>
          </div>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
          <a href="club_directory.php" class="card-btn" style="text-decoration:none; height:38px; padding:0 16px; border-radius:10px; font-weight:700; background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:8px; font-size:0.84rem; cursor:pointer; border:none; box-shadow:0 2px 6px rgba(37,99,235,0.25);">
            <i class="fa-solid fa-sitemap"></i> Explore Organizations
          </a>
        </div>
      </div>

      <div class="content-body">

        <!-- 2.3 Membership & Roster Oversight Summary Cards -->
        <?php if (in_array($sess_role, ['ssc', 'admin', 'club_adviser']) && !($sess_role === 'ssc' && $active_view === 'roster')): ?>
        <div class="info-row" style="grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Pending Applications</div>
              <div class="card-amount"><?= $pending_count ?></div>
              <div class="card-detail">Membership applications awaiting review.</div>
            </div>
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-users" style="color:#10b981;"></i> Active Members</div>
              <div class="card-amount"><?= $active_count ?></div>
              <div class="card-detail">Current active student memberships.</div>
            </div>
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-user-xmark" style="color:#ef4444;"></i> Rejected Applications</div>
              <div class="card-amount"><?= $rejected_count ?></div>
              <div class="card-detail">Rejected requests within selected period.</div>
            </div>
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> Organizations With Most Members</div>
              <div class="card-amount"><?= htmlspecialchars($top_org_code) ?> <span style="font-size:0.9rem; font-weight:600; color:#64748b;">(<?= $top_org_count ?> <?= $top_org_count === 1 ? 'member' : 'members' ?>)</span></div>
              <div class="card-detail">Organization membership distribution.</div>
            </div>
        </div>
        <?php endif; ?>



        <!-- Alert box -->
        <div id="rosterAlert"
          style="display:none; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:0.85rem;"></div>

        <!-- My Joined Organizations Cards & Roster (Student View) -->
        <?php if ($sess_role === 'student'): ?>
          <!-- 1. Joined Organizations Cards Grid (Shown First) -->
          <div class="card" id="joinedOrgsSection">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <div>
                <h3 style="margin:0; color:#1e293b;"><i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> Joined Organizations</h3>
                <span style="font-size:0.8rem; color:#64748b;">Select an organization you joined to view its active member roster</span>
              </div>
            </div>

            <?php if (empty($my_memberships)): ?>
              <div style="text-align:center; padding:30px; background:#f8fafc; border-radius:10px; border:1px dashed #cbd5e1;">
                <i class="fa-solid fa-folder-open" style="font-size:2.5rem; color:#94a3b8; margin-bottom:10px;"></i>
                <p style="margin:0; font-weight:600; color:#475569;">You have not joined any campus organization yet.</p>
                <a href="club_directory.php" class="card-btn" style="display:inline-block; margin-top:12px; background:#2563eb; color:#fff; text-decoration:none;">
                  <i class="fa-solid fa-compass"></i> Explore Club Directory &amp; Apply
                </a>
              </div>
            <?php else: ?>
              <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:16px;">
                <?php foreach ($my_memberships as $mem): ?>
                  <div class="joined-org-card" onclick="loadOrgRoster(<?= $mem['club_id'] ?>, '<?= htmlspecialchars(addslashes($mem['club_name'])) ?>', '<?= htmlspecialchars(addslashes($mem['club_code'])) ?>')">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:8px;">
                      <div style="width:40px; height:40px; border-radius:8px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.1rem;">
                        <?= htmlspecialchars(substr($mem['club_code'], 0, 2)) ?>
                      </div>
                      <span class="badge-active" style="font-size:0.75rem;">Active Member</span>
                    </div>
                    <div style="font-weight:700; color:#1e293b; font-size:0.95rem; margin-bottom:4px;"><?= htmlspecialchars($mem['club_name']) ?></div>
                    <div style="font-size:0.8rem; color:#64748b; margin-bottom:8px;"><?= htmlspecialchars($mem['category'] ?? 'Campus Organization') ?></div>
                    <div style="border-top:1px solid #f1f5f9; padding-top:8px; display:flex; justify-content:space-between; align-items:center; font-size:0.78rem; color:#64748b;">
                      <span>Role: <strong style="color:#2563eb;"><?= htmlspecialchars($mem['member_role']) ?></strong></span>
                      <span style="color:#2563eb; font-weight:600;"><i class="fa-solid fa-users"></i> View Roster &rarr;</span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($my_pending_applications)): ?>
              <div style="margin-top:24px; border-top:1px solid #f1f5f9; padding-top:16px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                  <h4 style="margin:0; color:#b45309; font-size:0.92rem; display:flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-clock-rotate-left"></i> Submitted Applications Awaiting SSC Approval (<?= count($my_pending_applications) ?>)
                  </h4>
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px;">
                  <?php foreach ($my_pending_applications as $p_app): 
                    $is_endorsed = (!empty($p_app['adviser_review']) && in_array($p_app['adviser_review'], ['Endorsed', 'Approved']));
                    $badge_label = $is_endorsed ? 'Endorsed · Pending SSC Approval' : 'Pending SSC Approval';
                  ?>
                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:transform 0.15s, box-shadow 0.15s;">
                      <div>
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; gap:8px;">
                          <span style="font-weight:800; font-size:0.9rem; color:#1a3a8c; background:#f1f5f9; padding:2px 8px; border-radius:6px;"><?= htmlspecialchars($p_app['club_code']) ?></span>
                          <?php if ($is_endorsed): ?>
                            <span style="background:#ecfdf5; color:#047857; font-size:0.72rem; font-weight:700; padding:3px 10px; border-radius:20px; border:1px solid #a7f3d0; display:inline-flex; align-items:center; gap:5px;">
                              <i class="fa-solid fa-stamp"></i> Endorsed &bull; Pending SSC
                            </span>
                          <?php else: ?>
                            <span style="background:#fffbeb; color:#b45309; font-size:0.72rem; font-weight:700; padding:3px 10px; border-radius:20px; border:1px solid #fde68a; display:inline-flex; align-items:center; gap:5px;">
                              <i class="fa-solid fa-hourglass-half"></i> Pending Adviser Endorsement
                            </span>
                          <?php endif; ?>
                        </div>
                        <div style="font-weight:700; color:#0f172a; font-size:0.95rem; margin-bottom:4px; line-height:1.35;"><?= htmlspecialchars($p_app['club_name']) ?></div>
                        <div style="font-size:0.75rem; color:#64748b;">Applied: <?= date('M d, Y', strtotime($p_app['joined_at'])) ?></div>
                      </div>
                      <div style="margin-top:12px; font-size:0.75rem; color:#475569; background:#f8fafc; border:1px solid #e2e8f0; padding:8px 12px; border-radius:8px; line-height:1.45;">
                        <i class="fa-solid fa-circle-info" style="color:#2563eb;"></i> This organization will appear in your <strong>Joined Organizations</strong> once the SSC reviews and approves your application.
                      </div>
                      <div style="margin-top:12px; padding-top:12px; border-top:1px solid #f1f5f9; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <button type="button" class="card-btn btn-sm" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:0.75rem; font-weight:700; padding:6px 12px; border-radius:8px; display:inline-flex; align-items:center; gap:6px; cursor:pointer;" onclick="viewStudentApplicationDoc(<?= (int)$p_app['id'] ?>, 'intent')" title="View Your Submitted Letter of Intent">
                          <i class="fa-solid fa-file-lines"></i> View My Letter of Intent
                        </button>
                        <?php if ($is_endorsed): ?>
                          <button type="button" class="card-btn btn-sm" style="background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; font-size:0.75rem; font-weight:700; padding:6px 12px; border-radius:8px; display:inline-flex; align-items:center; gap:6px; cursor:pointer;" onclick="viewStudentApplicationDoc(<?= (int)$p_app['id'] ?>, 'endorsement')" title="View Adviser Official Letter of Endorsement">
                            <i class="fa-solid fa-stamp"></i> View Adviser Endorsement
                          </button>
                        <?php else: ?>
                          <span style="font-size:0.72rem; color:#92400e; font-weight:600; display:inline-flex; align-items:center; gap:4px; padding:4px 6px;">
                            <i class="fa-solid fa-clock"></i> Awaiting Adviser Endorsement
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- 2. Selected Organization Roster Table (Hidden by default until clicked) -->
          <div class="card" id="selectedOrgRosterCard" style="display:none;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
              <div>
                <h3 style="margin:0; color:#1e293b;" id="selectedRosterTitle">
                  <i class="fa-solid fa-users-rectangle" style="color:#2563eb;"></i> Organization Member Roster
                </h3>
                <span style="font-size:0.8rem; color:#64748b;" id="selectedRosterSub">Select an organization above to view its member roster</span>
              </div>
              <button type="button" class="card-btn" onclick="closeOrgRoster()" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:6px 14px; border-radius:8px; font-weight:600; font-size:0.82rem; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:all 0.2s;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#1e293b';" onmouseout="this.style.background='#f1f5f9'; this.style.color='#475569';" title="Close member roster table">
                <i class="fa-solid fa-xmark" style="font-size:0.95rem;"></i> Close Roster
              </button>
            </div>

            <div id="rosterEmptyNotice" style="text-align:center; padding:30px; color:#64748b;">
              <i class="fa-solid fa-hand-pointer" style="font-size:2rem; color:#94a3b8; margin-bottom:8px; display:block;"></i>
              Click on an organization card above to view its member roster.
            </div>

            <div id="rosterTableWrap" class="table-wrap" style="display:none;">
              <table id="studentOrgRosterTable" class="table-wide">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Member Name</th>
                    <th>Email</th>
                    <th>Course &amp; Year</th>
                    <th>Assigned Role</th>
                    <th>Joined Date</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="studentOrgRosterBody">
                  <!-- Rendered dynamically by JS -->
                </tbody>
              </table>
            </div>
          </div>

          <script>
          const STUDENT_ALL_ORG_MEMBERS = <?= json_encode($all_org_members) ?>;

          function loadOrgRoster(clubId, clubName, clubCode) {
            const card = document.getElementById('selectedOrgRosterCard');
            const title = document.getElementById('selectedRosterTitle');
            const sub = document.getElementById('selectedRosterSub');
            const notice = document.getElementById('rosterEmptyNotice');
            const wrap = document.getElementById('rosterTableWrap');
            const tbody = document.getElementById('studentOrgRosterBody');

            if (!card) return;
            card.style.display = 'block';

            if (title) title.innerHTML = `<i class="fa-solid fa-users-rectangle" style="color:#2563eb;"></i> ${clubCode} &mdash; ${clubName} Active Roster`;
            
            const members = (STUDENT_ALL_ORG_MEMBERS || []).filter(m => parseInt(m.club_id) === parseInt(clubId));

            if (sub) sub.textContent = `Showing ${members.length} active registered member${members.length === 1 ? '' : 's'}`;

            if (!members.length) {
              if (notice) {
                notice.style.display = 'block';
                notice.innerHTML = `<i class="fa-solid fa-users-slash" style="font-size:2rem; color:#94a3b8; margin-bottom:8px; display:block;"></i>No active member records found for this organization.`;
              }
              if (wrap) wrap.style.display = 'none';
            } else {
              if (notice) notice.style.display = 'none';
              if (wrap) wrap.style.display = 'block';
              if (tbody) {
                tbody.innerHTML = members.map((m, idx) => `
                  <tr>
                    <td>${idx + 1}</td>
                    <td><strong>${escapeHtml(m.first_name + ' ' + m.last_name)}</strong></td>
                    <td><span style="font-size:0.82rem; color:#64748b;">${escapeHtml(m.email || '—')}</span></td>
                    <td><span style="font-size:0.82rem;">${escapeHtml(m.course || 'BSIT')} ${escapeHtml(m.year_level || '')}</span></td>
                    <td><span class="badge-role" style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; padding:3px 8px; border-radius:6px; font-size:0.75rem; font-weight:700;">${escapeHtml(m.member_role || 'Member')}</span></td>
                    <td style="font-size:0.82rem; color:#64748b;">${m.joined_at ? new Date(m.joined_at).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : '—'}</td>
                    <td><span class="badge-active" style="padding:2px 8px; font-size:0.72rem; font-weight:700; border-radius:6px; background:#dcfce7; color:#15803d;">Active</span></td>
                  </tr>
                `).join('');
              }
            }

            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }

          function closeOrgRoster() {
            const card = document.getElementById('selectedOrgRosterCard');
            if (card) card.style.display = 'none';
            document.getElementById('joinedOrgsSection')?.scrollIntoView({ behavior: 'smooth' });
          }

          function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
          }
          </script>
        <?php endif; ?>

        <!-- Membership Review Queue (SSC, Admin, Adviser) -->
        <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
          <div class="card" id="applicant-queue" style="<?= ($active_view === 'roster') ? 'display:none;' : '' ?>">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <div>
                <h3 style="margin:0; font-size:1.15rem; color:#1e293b; display:flex; align-items:center; gap:8px;">
                  <i class="fa-solid fa-list-check" style="color:#2563eb;"></i> Membership Review Queue
                  <?php if ($pending_count > 0): ?>
                    <span style="background:#fef3c7; color:#d97706; font-size:0.75rem; padding:3px 10px; border-radius:12px; font-weight:700;">
                      <?= $pending_count ?> Pending
                    </span>
                  <?php endif; ?>
                </h3>
              </div>
            </div>

            <!-- Filter & Search Toolbar -->
            <div style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px; align-items:center; background:#f8fafc; padding:12px 14px; border-radius:8px; border:1px solid #e2e8f0;">
              <div style="flex:1; min-width:220px; position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
                <input type="text" id="queueSearch" placeholder="<?= $sess_role === 'club_adviser' ? 'Search applicant, ID, or program...' : 'Search applicant, ID, program, or organization...' ?>" onkeyup="filterReviewQueue()" style="width:100%; padding:8px 12px 8px 34px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; box-sizing:border-box;" />
              </div>
              <?php if ($sess_role !== 'club_adviser'): ?>
              <div style="min-width:160px;">
                <select id="filterOrg" onchange="filterReviewQueue()" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; background:#fff; cursor:pointer;">
                  <option value="">All Organizations</option>
                  <?php foreach ($all_clubs as $cl): ?>
                    <option value="<?= htmlspecialchars($cl['code']) ?>"><?= htmlspecialchars($cl['code'] . ' - ' . $cl['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              <div style="min-width:150px;">
                <select id="filterStatus" onchange="filterReviewQueue()" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; background:#fff; cursor:pointer;">
                  <option value="">All Statuses</option>
                  <option value="Pending">Pending</option>
                  <option value="Approved">Approved</option>
                  <option value="Returned">Returned</option>
                  <option value="Rejected">Rejected</option>
                </select>
              </div>
              <div>
                <button type="button" onclick="resetQueueFilter()" class="card-btn btn-sm" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:0.82rem; padding:7px 12px; border-radius:6px; cursor:pointer;">
                  <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
              </div>
            </div>

            <div class="table-wrap">
              <table id="queueTable" class="table-wide">
                <thead>
                  <tr>
                    <th>Application No.</th>
                    <th>Student Name</th>
                    <th>Student No.</th>
                    <th>Program</th>
                    <th>Year</th>
                    <th>Organization</th>
                    <th>Submitted Date</th>
                    <th>Adviser Endorsement</th>
                    <th>SSC Review</th>
                    <th style="min-width:180px;">Action</th>
                  </tr>
                </thead>
                <tbody id="queueTableBody">
                  <?php if (empty($pending_applicants)): ?>
                    <tr>
                      <td colspan="10" class="empty-state-cell" style="text-align:center; padding:36px 16px; color:#94a3b8;">
                        <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; width:100%; margin:0 auto;">
                          <i class="fa-solid fa-folder-open" style="font-size:2.2rem; margin-bottom:10px; display:inline-block; color:#94a3b8;"></i>
                          <span style="font-weight:600; font-size:0.9rem; color:#475569; text-align:center;">No applications found in review queue.</span>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($pending_applicants as $ap): 
                      $sub_ts = !empty($ap['submitted_date']) ? strtotime($ap['submitted_date']) : (!empty($ap['joined_at']) ? strtotime($ap['joined_at']) : time());
                      $app_no = 'APP-' . date('Y', $sub_ts) . '-' . str_pad($ap['id'], 4, '0', STR_PAD_LEFT);
                      $curr_st = $ap['current_status'];
                      if ($curr_st === 'Active') $curr_st = 'Approved';
                      $adv_st = $ap['adviser_review'] ?: 'Pending Adviser';
                      $ssc_st = $ap['ssc_review'] ?: 'Pending SSC';
                    ?>
                      <tr class="queue-row" id="applicant-row-<?= $ap['id'] ?>" data-org="<?= htmlspecialchars($ap['club_code']) ?>" data-status="<?= htmlspecialchars($curr_st) ?>">
                        <!-- 1. Application No. -->
                        <td><span style="font-family:monospace; font-weight:700; color:#2563eb; font-size:0.82rem;"><?= htmlspecialchars($app_no) ?></span></td>
                        <!-- 2. Student Name -->
                        <td>
                          <strong><?= htmlspecialchars($ap['first_name'] . ' ' . $ap['last_name']) ?></strong>
                          <?php
                            $joined = $ap['joined_clubs'] ?? [];
                            $other_joined = array_filter($joined, fn($jc) => (int)$jc['club_id'] !== (int)$ap['club_id']);
                          ?>
                          <?php if (!empty($other_joined)): ?>
                            <div style="margin-top:4px; display:flex; flex-wrap:wrap; gap:4px;">
                              <?php foreach ($other_joined as $jc): ?>
                                <span class="badge-role" style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-size:0.7rem; font-weight:700; padding:1.5px 6px; border-radius:4px;" title="Active Member of <?= htmlspecialchars($jc['club_name']) ?>">
                                  <i class="fa-solid fa-check"></i> Member: <?= htmlspecialchars($jc['club_code']) ?>
                                </span>
                              <?php endforeach; ?>
                            </div>
                          <?php else: ?>
                            <div style="font-size:0.72rem; color:#94a3b8; margin-top:2px;">
                              <i class="fa-solid fa-user-plus" style="font-size:0.68rem;"></i> First-time Applicant
                            </div>
                          <?php endif; ?>
                        </td>
                        <!-- 3. Student No. -->
                        <td><span style="font-family:monospace; font-size:0.82rem; color:#475569;"><?= htmlspecialchars($ap['student_id_no']) ?></span></td>
                        <!-- 4. Program -->
                        <td><span style="font-size:0.82rem;"><?= htmlspecialchars($ap['course']) ?></span></td>
                        <!-- 5. Year -->
                        <td><span style="font-size:0.82rem; color:#475569;"><?= htmlspecialchars($ap['year_level']) ?></span></td>
                        <!-- 6. Organization -->
                        <td>
                          <span style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($ap['club_code']) ?></span>
                          <div style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($ap['club_name']) ?></div>
                        </td>
                        <!-- 7. Submitted Date -->
                        <td><span style="font-size:0.8rem; color:#475569;"><?= date('M d, Y', $sub_ts) ?></span></td>
                        <!-- 8. Adviser Endorsement -->
                        <td>
                          <?php if ($adv_st === 'Endorsed'): ?>
                            <span style="background:#dcfce7; color:#166534; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><i class="fa-solid fa-stamp"></i> Endorsed</span>
                          <?php elseif ($adv_st === 'Returned'): ?>
                            <span style="background:#f3e8ff; color:#7e22ce; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><i class="fa-solid fa-rotate-left"></i> Returned</span>
                          <?php elseif ($adv_st === 'Rejected'): ?>
                            <span style="background:#fee2e2; color:#991b1b; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><i class="fa-solid fa-xmark"></i> Rejected</span>
                          <?php else: ?>
                            <span style="background:#fef3c7; color:#92400e; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><i class="fa-solid fa-clock"></i> Pending Adviser</span>
                          <?php endif; ?>
                        </td>
                        <!-- 9. SSC Review -->
                        <td>
                          <?php if ($ssc_st === 'Approved' || $curr_st === 'Approved'): ?>
                            <span style="background:#dcfce7; color:#166534; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><i class="fa-solid fa-check-double"></i> Approved</span>
                          <?php elseif ($ssc_st === 'Under Review'): ?>
                            <span style="background:#fef3c7; color:#92400e; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><i class="fa-solid fa-magnifying-glass"></i> Under Review</span>
                          <?php elseif ($ssc_st === 'Returned'): ?>
                            <span style="background:#f3e8ff; color:#7e22ce; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><i class="fa-solid fa-rotate-left"></i> Returned</span>
                          <?php elseif ($ssc_st === 'Rejected'): ?>
                            <span style="background:#fee2e2; color:#991b1b; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><i class="fa-solid fa-xmark"></i> Rejected</span>
                          <?php else: ?>
                            <span style="background:#e0e7ff; color:#3730a3; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem; display:inline-block;"><i class="fa-solid fa-clock"></i> Pending SSC</span>
                          <?php endif; ?>
                        </td>
                        <!-- 10. Action -->
                        <td>
                          <div style="display:flex; gap:4px; align-items:center; flex-wrap:nowrap; white-space:nowrap;">
                            <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff;" onclick="reviewApplicant(<?= htmlspecialchars(json_encode($ap), ENT_QUOTES) ?>)" title="Review Application Details">
                              <i class="fa-solid fa-clipboard-check"></i> Review
                            </button>
                            <button type="button" class="card-btn btn-sm" style="background:#0284c7; color:#fff;" onclick="viewApplicantDocs(<?= htmlspecialchars(json_encode($ap), ENT_QUOTES) ?>)" title="View Application Documents">
                              <i class="fa-solid fa-file-lines"></i> Docs
                            </button>
                            <?php if ($sess_role === 'club_adviser'): ?>
                              <?php if ($curr_st === 'Pending' || $curr_st === 'Returned'): ?>
                                <?php if ($ap['adviser_review'] !== 'Endorsed'): ?>
                                  <button type="button" class="card-btn btn-sm" style="background:#059669; color:#fff;" onclick="openEndorseModal(<?= htmlspecialchars(json_encode($ap), ENT_QUOTES) ?>)" title="Endorse Applicant with Letter of Endorsement">
                                    <i class="fa-solid fa-stamp"></i> Endorse
                                  </button>
                                  <button type="button" class="card-btn btn-sm" style="background:#d97706; color:#fff;" onclick="openReturnModal(<?= $ap['id'] ?>, '<?= htmlspecialchars(addslashes($ap['first_name'] . ' ' . $ap['last_name'])) ?>')" title="Return for Revision">
                                    <i class="fa-solid fa-rotate-left"></i>
                                  </button>
                                  <button type="button" class="card-btn btn-sm btn-danger" onclick="handleApplication(<?= $ap['id'] ?>, 'reject')" title="Reject Application">
                                    <i class="fa-solid fa-xmark"></i>
                                  </button>
                                <?php else: ?>
                                  <span style="font-size:0.75rem; color:#065f46; font-weight:700; background:#d1fae5; padding:4px 8px; border-radius:6px; display:inline-block; border:1px solid #a7f3d0;" title="Endorsed with Letter of Endorsement and forwarded to SSC">
                                    <i class="fa-solid fa-check"></i> Endorsed
                                  </span>
                                  <span style="font-size:0.75rem; color:#64748b; font-weight:600; background:#f1f5f9; padding:4px 8px; border-radius:6px; display:inline-block; border:1px solid #e2e8f0;" title="Awaiting SSC review and final approval">
                                    <i class="fa-solid fa-clock"></i> SSC Review
                                  </span>
                                <?php endif; ?>
                              <?php else: ?>
                                <span style="font-size:0.75rem; color:#64748b; font-weight:600; background:#f1f5f9; padding:4px 8px; border-radius:6px; display:inline-block; border:1px solid #e2e8f0;">
                                  <?= htmlspecialchars($curr_st) ?>
                                </span>
                              <?php endif; ?>
                            <?php else: /* SSC or Admin (Approval Authority) */ ?>
                              <?php if ($curr_st === 'Pending' || $curr_st === 'Returned'): ?>
                                <button type="button" class="card-btn btn-sm" style="background:#16a34a; color:#fff;" onclick="handleApplication(<?= $ap['id'] ?>, 'approve')" title="Approve Application">
                                  <i class="fa-solid fa-check"></i>
                                </button>
                                <button type="button" class="card-btn btn-sm" style="background:#d97706; color:#fff;" onclick="openReturnModal(<?= $ap['id'] ?>, '<?= htmlspecialchars(addslashes($ap['first_name'] . ' ' . $ap['last_name'])) ?>')" title="Return for Revision">
                                  <i class="fa-solid fa-rotate-left"></i>
                                </button>
                                <button type="button" class="card-btn btn-sm btn-danger" onclick="handleApplication(<?= $ap['id'] ?>, 'reject')" title="Reject Application">
                                  <i class="fa-solid fa-xmark"></i>
                                </button>
                              <?php else: ?>
                                <button type="button" class="card-btn btn-sm btn-disabled" disabled style="background:#e2e8f0; color:#94a3b8; border:1px solid #cbd5e1; cursor:not-allowed; opacity:0.65;" title="Application already <?= htmlspecialchars(strtolower($curr_st)) ?>">
                                  <i class="fa-solid fa-check"></i>
                                </button>
                                <button type="button" class="card-btn btn-sm btn-disabled" disabled style="background:#e2e8f0; color:#94a3b8; border:1px solid #cbd5e1; cursor:not-allowed; opacity:0.65;" title="Return unavailable for <?= htmlspecialchars(strtolower($curr_st)) ?> application">
                                  <i class="fa-solid fa-rotate-left"></i>
                                </button>
                                <button type="button" class="card-btn btn-sm btn-disabled" disabled style="background:#e2e8f0; color:#94a3b8; border:1px solid #cbd5e1; cursor:not-allowed; opacity:0.65;" title="Reject unavailable for <?= htmlspecialchars(strtolower($curr_st)) ?> application">
                                  <i class="fa-solid fa-xmark"></i>
                                </button>
                              <?php endif; ?>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Master Roster (active members) with Organization Roster Oversight Suite -->
          <div class="card" id="master-roster" style="<?= ($active_view === 'queue') ? 'display:none;' : '' ?>">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
              <div>
                <h3 style="margin:0; font-size:1.15rem; color:#1e293b; display:flex; align-items:center; gap:8px;">
                  <i class="fa-solid fa-address-book" style="color:#2563eb;"></i> Active Organization Member Roster
                  <span id="rosterCountBadge" style="background:#eff6ff; color:#2563eb; font-size:0.75rem; padding:3px 10px; border-radius:12px; font-weight:700;">
                    <?= count($active_members) ?> Active
                  </span>
                </h3>
              </div>
              <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <button class="card-btn btn-sm" id="exportCsvBtn" style="background:#2563eb; color:#fff; font-weight:700; border-radius:8px; padding:8px 16px;">
                  <i class="fa-solid fa-download"></i> Export Roster (CSV)
                </button>
              </div>
            </div>

            <!-- Organization Selector & Search Toolbar -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:16px; display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
              <!-- Org Dropdown -->
              <div style="flex:2; min-width:260px;">
                <label for="rosterFilterOrg" style="display:block; font-size:0.75rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">
                  <i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> Select Organization
                </label>
                <select id="rosterFilterOrg" onchange="filterMasterRoster()" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.85rem; background:#fff; font-weight:600; color:#1e293b; cursor:pointer;">
                  <?php if ($sess_role === 'club_adviser'): ?>
                    <?php if (count($all_clubs) === 1): ?>
                      <option value="<?= htmlspecialchars($all_clubs[0]['code']) ?>" selected
                              data-name="<?= htmlspecialchars($all_clubs[0]['name']) ?>"
                              data-category="<?= htmlspecialchars($all_clubs[0]['category']) ?>"
                              data-adviser="<?= htmlspecialchars($all_clubs[0]['adviser_name'] ?? 'Unassigned') ?>"
                              data-count="<?= (int)$all_clubs[0]['active_member_count'] ?>"
                              data-pending="<?= (int)$all_clubs[0]['pending_member_count'] ?>"
                              data-id="<?= $all_clubs[0]['id'] ?>">
                        <?= htmlspecialchars($all_clubs[0]['code']) ?> &mdash; <?= htmlspecialchars($all_clubs[0]['name']) ?> (<?= (int)$all_clubs[0]['active_member_count'] ?> <?= (int)$all_clubs[0]['active_member_count'] === 1 ? 'member' : 'members' ?>)
                      </option>
                    <?php elseif (count($all_clubs) > 1): ?>
                      <option value="all">My Assigned Organizations (<?= count($all_clubs) ?> Clubs)</option>
                      <?php foreach ($all_clubs as $cl): ?>
                        <option value="<?= htmlspecialchars($cl['code']) ?>"
                                data-name="<?= htmlspecialchars($cl['name']) ?>"
                                data-category="<?= htmlspecialchars($cl['category']) ?>"
                                data-adviser="<?= htmlspecialchars($cl['adviser_name'] ?? 'Unassigned') ?>"
                                data-count="<?= (int)$cl['active_member_count'] ?>"
                                data-pending="<?= (int)$cl['pending_member_count'] ?>"
                                data-id="<?= $cl['id'] ?>"
                                <?= ($selected_club_code === $cl['code']) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($cl['code']) ?> &mdash; <?= htmlspecialchars($cl['name']) ?> (<?= (int)$cl['active_member_count'] ?> <?= (int)$cl['active_member_count'] === 1 ? 'member' : 'members' ?>)
                        </option>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <option value="none">No Assigned Organizations</option>
                    <?php endif; ?>
                  <?php else: ?>
                    <option value="all">All Campus Organizations (<?= count($all_clubs) ?> Recognized Clubs)</option>
                    <?php foreach ($all_clubs as $cl): ?>
                      <option value="<?= htmlspecialchars($cl['code']) ?>"
                              data-name="<?= htmlspecialchars($cl['name']) ?>"
                              data-category="<?= htmlspecialchars($cl['category']) ?>"
                              data-adviser="<?= htmlspecialchars($cl['adviser_name'] ?? 'Unassigned') ?>"
                              data-count="<?= (int)$cl['active_member_count'] ?>"
                              data-pending="<?= (int)$cl['pending_member_count'] ?>"
                              data-id="<?= $cl['id'] ?>"
                              <?= ($selected_club_code === $cl['code']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cl['code']) ?> &mdash; <?= htmlspecialchars($cl['name']) ?> (<?= (int)$cl['active_member_count'] ?> <?= (int)$cl['active_member_count'] === 1 ? 'member' : 'members' ?>)
                      </option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Live Search -->
              <div style="flex:2; min-width:220px;">
                <label for="rosterSearch" style="display:block; font-size:0.75rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">
                  <i class="fa-solid fa-magnifying-glass" style="color:#2563eb;"></i> Search Roster
                </label>
                <input type="text" id="rosterSearch" placeholder="Search member, ID, course, role..." oninput="filterMasterRoster()" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.85rem; box-sizing:border-box; background:#fff;" />
              </div>

              <!-- Role Filter -->
              <div style="flex:1; min-width:140px;">
                <label for="rosterFilterRole" style="display:block; font-size:0.75rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">
                  Role
                </label>
                <select id="rosterFilterRole" onchange="filterMasterRoster()" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.85rem; background:#fff; cursor:pointer;">
                  <option value="all">All Roles</option>
                  <option value="member">Members</option>
                  <option value="officer">Officers</option>
                  <option value="president">President</option>
                  <option value="vice president">Vice President</option>
                  <option value="secretary">Secretary</option>
                  <option value="treasurer">Treasurer</option>
                  <option value="auditor">Auditor</option>
                </select>
              </div>

              <!-- Reset Filter -->
              <div style="align-self:flex-end;">
                <button type="button" onclick="clearRosterFilter()" class="card-btn btn-sm" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:0.82rem; padding:9px 14px; border-radius:8px; cursor:pointer; height:38px;">
                  <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
              </div>
            </div>


            <!-- Master Roster Table (8 responsive columns) -->
            <div class="table-wrap">
              <table id="masterRosterTable" class="table-wide">
                <thead>
                  <tr>
                    <th>Member Student</th>
                    <th>Student ID</th>
                    <th>Course &amp; Year</th>
                    <th>Organization</th>
                    <th>Assigned Role</th>
                    <th>Joined Date</th>
                    <th>Status</th>
                    <th style="text-align:right;">Action</th>
                  </tr>
                </thead>
                <tbody id="masterRosterTbody">
                  <?php foreach ($active_members as $mem): ?>
                    <?php 
                      $s_term = strtolower($mem['first_name'] . ' ' . $mem['last_name'] . ' ' . ($mem['student_number'] ?? '') . ' ' . ($mem['course'] ?? '') . ' ' . ($mem['year_level'] ?? '') . ' ' . $mem['club_code'] . ' ' . $mem['club_name'] . ' ' . $mem['member_role'] . ' ' . $mem['email']);
                      $initial = strtoupper(substr($mem['first_name'] ?? 'M', 0, 1) . substr($mem['last_name'] ?? '', 0, 1));
                    ?>
                    <tr class="master-roster-row" id="member-row-<?= $mem['id'] ?>"
                        data-search="<?= htmlspecialchars($s_term, ENT_QUOTES) ?>"
                        data-org="<?= htmlspecialchars(strtoupper($mem['club_code']), ENT_QUOTES) ?>"
                        data-role="<?= htmlspecialchars(strtolower($mem['member_role']), ENT_QUOTES) ?>">
                      <td>
                        <div style="display:flex; align-items:center; gap:10px;">
                          <div style="width:34px; height:34px; border-radius:50%; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.8rem; border:1px solid #bfdbfe; flex-shrink:0;">
                            <?= htmlspecialchars($initial) ?>
                          </div>
                          <div>
                            <strong><?= htmlspecialchars($mem['first_name'] . ' ' . $mem['last_name']) ?></strong>
                            <div style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($mem['email']) ?></div>
                          </div>
                        </div>
                      </td>
                      <td><code><?= htmlspecialchars($mem['student_number'] ?: ('2024-' . (10000 + (int)$mem['user_id']))) ?></code></td>
                      <td><?= htmlspecialchars(($mem['course'] ?: 'BSIT') . ' - ' . ($mem['year_level'] ?: '1st Year')) ?></td>
                      <td>
                        <span class="badge-info" style="font-weight:700;"><?= htmlspecialchars($mem['club_code']) ?></span>
                        <div style="font-size:0.72rem; color:#64748b; margin-top:2px;"><?= htmlspecialchars($mem['club_name']) ?></div>
                      </td>
                      <td>
                        <span class="badge-pill" style="font-weight:600; font-size:0.78rem; background:#f1f5f9; color:#334155; padding:3px 8px; border-radius:12px; border:1px solid #cbd5e1;">
                          <?= htmlspecialchars($mem['member_role']) ?>
                        </span>
                      </td>
                      <td><?= date('M d, Y', strtotime($mem['joined_at'])) ?></td>
                      <td><span class="badge-active"><i class="fa-solid fa-circle-check"></i> Active</span></td>
                      <td style="text-align:right;">
                        <div class="actions-group" style="justify-content:flex-end; gap:6px;">
                          <!-- View Student Details Button -->
                          <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff; font-weight:700; padding:6px 12px; font-size:0.78rem; display:inline-flex; align-items:center; gap:5px;" onclick="viewMemberDetails(<?= htmlspecialchars(json_encode($mem), ENT_QUOTES) ?>)" title="View Student Member Details">
                            <i class="fa-solid fa-id-card"></i> Details
                          </button>
                          <?php if (in_array($sess_role, ['club_adviser', 'admin'])): ?>
                            <button class="card-btn btn-danger btn-sm" onclick="removeMember(<?= $mem['id'] ?>)" title="Revoke Membership">
                              <i class="fa-solid fa-user-minus"></i>
                            </button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Tailored Empty State when 0 members match or org has no members -->
            <div id="rosterEmptyStateWrap" style="display:none; text-align:center; padding:45px 20px; background:#f8fafc; border-radius:12px; border:1.5px dashed #cbd5e1; margin-top:14px;">
              <div style="width:64px; height:64px; border-radius:50%; background:#eff6ff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; font-size:1.8rem; margin-bottom:12px;">
                <i class="fa-solid fa-users-slash"></i>
              </div>
              <h3 id="rosterEmptyTitle" style="margin:0 0 6px; font-size:1.1rem; color:#1e293b;">No Active Members Found</h3>
              <p id="rosterEmptyDesc" style="margin:0; font-size:0.85rem; color:#64748b; max-width:500px; margin-inline:auto;">
                There are currently no active approved student memberships for this organization in the registry.
              </p>
            </div>
          </div>
        <?php endif; ?>

      </div><!-- end content-body -->
    </div><!-- end content -->

    <!-- ── MODAL 1: Applicant Review & Letters ── -->
    <div class="roster-modal-overlay" id="applicantReviewModal">
      <div class="roster-modal-card">
        
        <!-- Header -->
        <div class="roster-modal-header" style="background:#1a3a8c; color:#ffffff; border-bottom:1px solid rgba(255,255,255,0.12); padding:16px 20px;">
          <div style="display:flex; align-items:center; gap:12px; min-width:0;">
            <div style="width:40px; height:40px; border-radius:10px; background:rgba(255,255,255,0.15); color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:1.2rem; border:1px solid rgba(255,255,255,0.25); flex-shrink:0;">
              <i class="fa-solid fa-file-signature"></i>
            </div>
            <div style="min-width:0;">
              <h3 style="margin:0; font-size:1.05rem; color:#ffffff; font-weight:800; letter-spacing:-0.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" id="reviewModalTitle">Application Review</h3>
              <div style="display:flex; align-items:center; gap:6px; margin-top:3px; flex-wrap:wrap;">
                <span style="font-size:0.75rem; color:#cbd5e1; font-weight:500;">Membership Dossier</span>
                <span style="font-size:0.75rem; color:rgba(255,255,255,0.4);">&bull;</span>
                <span style="font-family:monospace; font-size:0.72rem; background:rgba(255,255,255,0.2); color:#ffffff; padding:2px 8px; border-radius:5px; font-weight:700; border:1px solid rgba(255,255,255,0.25);" id="reviewModalAppNo">APP-2026-0000</span>
              </div>
            </div>
          </div>
          <button type="button" onclick="closeModal('applicantReviewModal')" style="width:34px; height:34px; border-radius:8px; border:1px solid rgba(255,255,255,0.2); background:rgba(255,255,255,0.1); color:#ffffff; font-size:1.1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.25)';" onmouseout="this.style.background='rgba(255,255,255,0.1)';" title="Close">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>

        <!-- Body -->
        <div class="roster-modal-body">
          
          <!-- Applicant Profile Card -->
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px;">
            <!-- Top strip: Name, ID, Organization -->
            <div class="profile-top-strip" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid #e2e8f0;">
              <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                <div id="reviewAvatar" style="width:42px; height:42px; border-radius:50%; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.95rem; box-shadow:0 2px 4px rgba(37,99,235,0.25); flex-shrink:0;">
                  JS
                </div>
                <div style="min-width:0;">
                  <div style="font-size:1.05rem; font-weight:800; color:#0f172a; line-height:1.2; word-break:break-word;" id="reviewStudentName">Student Applicant</div>
                  <div style="font-family:monospace; font-weight:700; font-size:0.8rem; color:#2563eb; margin-top:2px;" id="reviewStudentNo">2024-00000</div>
                </div>
              </div>
              <div class="org-right-badge" style="text-align:right;">
                <span id="reviewOrgBadge" style="display:inline-block; font-size:0.8rem; font-weight:700; color:#1e293b; background:#fff; border:1px solid #cbd5e1; padding:4px 10px; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                  CSSEC
                </span>
                <div style="font-size:0.72rem; color:#64748b; margin-top:3px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" id="reviewOrgFullName">Computer Science Student Exec Council</div>
              </div>
            </div>

            <!-- Bottom metadata grid -->
            <div class="review-profile-meta-grid">
              <div>
                <div style="font-size:0.7rem; text-transform:uppercase; color:#64748b; font-weight:700; letter-spacing:0.3px; margin-bottom:2px;">Academic Program</div>
                <div style="font-weight:600; color:#1e293b; display:flex; align-items:center; gap:6px;">
                  <i class="fa-solid fa-graduation-cap" style="color:#2563eb; font-size:0.75rem;"></i>
                  <span id="reviewProgram">BSIT</span>
                </div>
              </div>
              <div>
                <div style="font-size:0.7rem; text-transform:uppercase; color:#64748b; font-weight:700; letter-spacing:0.3px; margin-bottom:2px;">Year Level</div>
                <div style="font-weight:600; color:#1e293b; display:flex; align-items:center; gap:6px;">
                  <i class="fa-solid fa-layer-group" style="color:#0284c7; font-size:0.75rem;"></i>
                  <span id="reviewYear">2nd Year</span>
                </div>
              </div>
              <div>
                <div style="font-size:0.7rem; text-transform:uppercase; color:#64748b; font-weight:700; letter-spacing:0.3px; margin-bottom:2px;">Submission Date</div>
                <div style="font-weight:600; color:#475569; display:flex; align-items:center; gap:6px;">
                  <i class="fa-regular fa-clock" style="color:#64748b; font-size:0.75rem;"></i>
                  <span id="reviewSubmitted">-</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Student Organization Affiliations & Target Application -->
          <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; border-bottom:1px solid #f1f5f9; padding-bottom:6px;">
              <div style="font-size:0.72rem; text-transform:uppercase; color:#64748b; font-weight:800; letter-spacing:0.4px; display:flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> Student Organization Affiliations
              </div>
              <span id="reviewJoinedClubsCountBadge" style="font-size:0.72rem; font-weight:700; background:#eff6ff; color:#1d4ed8; padding:2px 8px; border-radius:10px; border:1px solid #bfdbfe;">
                0 Joined Clubs
              </span>
            </div>
            
            <!-- Currently Joined Clubs List -->
            <div id="reviewJoinedClubsContainer" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
              <!-- Populated dynamically by JS -->
            </div>
            
            <!-- Target Club for this Application -->
            <div style="background:#f8fafc; border:1.5px dashed #cbd5e1; border-radius:8px; padding:10px 12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
              <div>
                <span style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:#0284c7; display:block;">Applying to Join:</span>
                <strong style="color:#0f172a; font-size:0.9rem;" id="reviewTargetClubName">Organization Name</strong>
                <span style="font-size:0.75rem; color:#64748b; font-weight:600; margin-left:4px;" id="reviewTargetClubCode">(CODE)</span>
              </div>
              <span style="font-size:0.75rem; font-weight:700; background:#eff6ff; color:#1d4ed8; padding:3px 10px; border-radius:6px; border:1px solid #bfdbfe;" id="reviewApplicationStatusPill">
                <i class="fa-solid fa-file-signature"></i> Application in Review
              </span>
            </div>
          </div>

          <!-- Multi-Tier Clearance Progress Stepper -->
          <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px;">
            <div style="font-size:0.7rem; text-transform:uppercase; color:#64748b; font-weight:800; letter-spacing:0.5px; margin-bottom:10px;">
              Multi-Tier Clearance Progress
            </div>
            <div class="review-stepper-grid" id="reviewStepperContainer">
              <!-- Dynamically rendered by JS: 3 Stages with status indicators -->
            </div>
          </div>

          <!-- Section 1: Letter of Intent & Motivation Statement -->
          <div style="border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
            <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:10px 16px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
              <div style="font-size:0.82rem; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:8px;">
                <span style="width:22px; height:22px; border-radius:6px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800;">1</span>
                <span>Letter of Intent (Student Submission)</span>
                <span style="font-size:0.7rem; font-weight:600; color:#059669; background:#dcfce7; padding:1px 6px; border-radius:4px;">Submitted</span>
              </div>
              <button type="button" class="card-btn btn-sm" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:0.75rem; padding:3px 10px; display:inline-flex; align-items:center; gap:5px;" onclick="if(CURRENT_REVIEW_AP) viewApplicantDocs(CURRENT_REVIEW_AP, 'intent')">
                <i class="fa-solid fa-expand"></i> Center View
              </button>
            </div>
            <div style="padding:14px 16px; display:flex; flex-direction:column; gap:10px;">
              <!-- Attached File Strip (if exists) -->
              <div id="reviewIntentFileBox"></div>
              
              <!-- Motivation Statement Block -->
              <div>
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; letter-spacing:0.3px; margin-bottom:4px;">Motivation Statement:</div>
                <div id="reviewMotivationText" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.85rem; line-height:1.55; color:#334155; white-space:pre-wrap; max-height:120px; overflow-y:auto;">
                  No motivation statement provided.
                </div>
              </div>
            </div>
          </div>

          <!-- Section 2: Adviser Letter of Endorsement -->
          <div style="border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;" id="reviewEndorsementSection">
            <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:10px 16px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
              <div style="font-size:0.82rem; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span style="width:22px; height:22px; border-radius:6px; background:#ecfdf5; color:#059669; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800;">2</span>
                <span>Letter of Endorsement (Club Faculty Adviser)</span>
                <span id="reviewEndorsementBadge"></span>
              </div>
              <div id="reviewEndorsementHeaderAction"></div>
            </div>
            <div style="padding:14px 16px; display:flex; flex-direction:column; gap:10px;" id="reviewEndorsementBody">
              <!-- Rendered dynamically: if pending, clean pending state card. If endorsed, file box + remarks -->
            </div>
          </div>

        </div>

        <!-- Footer Actions -->
        <div class="roster-modal-footer">
          <button type="button" class="card-btn" onclick="closeModal('applicantReviewModal')" style="background:#fff; color:#475569; border:1px solid #cbd5e1; font-weight:600; padding:8px 18px; border-radius:8px;">
            Close
          </button>
          <div id="reviewModalActionBtns" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <!-- Rendered dynamically -->
          </div>
        </div>
      </div>
    </div>

    <!-- ── MODAL 2: Documents & Attachments (Middle-of-Screen Full Document Viewer) ── -->
    <div class="roster-modal-overlay" id="applicationDocsModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.78); backdrop-filter:blur(6px); z-index:99999; align-items:center; justify-content:center; padding:16px;">
      <div class="roster-modal-card" style="background:#fff; border-radius:16px; max-width:1100px; width:95vw; height:92vh; max-height:92vh; box-shadow:0 25px 50px -12px rgba(15,23,42,0.5); border:1px solid #cbd5e1; display:flex; flex-direction:column; overflow:hidden;">
        <!-- Header -->
        <div style="padding:12px 20px; border-bottom:1px solid rgba(255,255,255,0.12); display:flex; align-items:center; justify-content:space-between; background:#1a3a8c; color:#fff; flex-shrink:0; gap:12px; flex-wrap:wrap;">
          <!-- Left: Compact Student & Org info -->
          <div style="display:flex; align-items:center; gap:10px; min-width:0;">
            <div style="width:34px; height:34px; border-radius:8px; background:rgba(255,255,255,0.15); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1rem; border:1px solid rgba(255,255,255,0.25); flex-shrink:0;">
              <i class="fa-solid fa-file-circle-check"></i>
            </div>
            <div style="display:flex; align-items:center; gap:8px; min-width:0; overflow:hidden; flex-wrap:wrap;">
              <h3 style="margin:0; font-size:0.95rem; color:#ffffff; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" id="docsModalStudentName">Student Document Review</h3>
              <span style="font-family:monospace; font-size:0.72rem; background:rgba(255,255,255,0.2); color:#ffffff; padding:2px 7px; border-radius:5px; font-weight:700; flex-shrink:0; border:1px solid rgba(255,255,255,0.25);" id="docsModalAppNo">APP-2026-0000</span>
              <span style="font-size:0.72rem; background:rgba(255,255,255,0.12); color:#e2e8f0; padding:2px 7px; border-radius:5px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px;" id="docsModalOrg">-</span>
            </div>
          </div>

          <!-- Right side: document tabs, external link, download & close -->
          <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
            <!-- Document Switcher Tabs -->
            <div id="docsTabSwitcher" style="display:inline-flex; background:rgba(0,0,0,0.22); border:1px solid rgba(255,255,255,0.2); padding:3px; border-radius:8px; gap:3px;">
              <!-- Tabs populated by JS -->
            </div>

            <!-- Quick Document Actions -->
            <a id="docsModalOpenExternal" href="#" target="_blank" style="display:none; width:32px; height:32px; border-radius:8px; border:1px solid rgba(255,255,255,0.25); background:rgba(255,255,255,0.12); color:#ffffff; align-items:center; justify-content:center; text-decoration:none; font-size:0.85rem; transition:all 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.25)';" onmouseout="this.style.background='rgba(255,255,255,0.12)';" title="Open in New Tab">
              <i class="fa-solid fa-arrow-up-right-from-square"></i>
            </a>
            <a id="docsModalDownload" href="#" download style="display:none; width:32px; height:32px; border-radius:8px; border:1px solid rgba(255,255,255,0.25); background:rgba(255,255,255,0.12); color:#ffffff; align-items:center; justify-content:center; text-decoration:none; font-size:0.85rem; transition:all 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.25)';" onmouseout="this.style.background='rgba(255,255,255,0.12)';" title="Download Document">
              <i class="fa-solid fa-download"></i>
            </a>

            <!-- Close Viewer Button -->
            <button type="button" onclick="closeModal('applicationDocsModal')" style="background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.25); color:#ffffff; width:32px; height:32px; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:0.95rem; transition:all 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.25)';" onmouseout="this.style.background='rgba(255,255,255,0.12)';" title="Close Viewer (Esc)">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>
        </div>

        <!-- Center Stage: The Actual Document Viewer in Middle of Screen -->
        <div style="flex:1; min-height:0; position:relative; background:#1e293b; display:flex; flex-direction:column; overflow:hidden;" id="docsModalViewerStage">
          <!-- Rendered dynamically: iframe / image / word presentation / institutional sheet -->
        </div>

        <!-- Footer / Decision Toolbar -->
        <div style="padding:10px 18px; border-top:1px solid #e2e8f0; background:#fff; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-shrink:0;">
          <div id="docsModalFileInfo" style="display:flex; align-items:center; gap:8px;">
            <!-- Rendered by JS -->
          </div>
          <div id="docsModalActionBtns" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <!-- Decision buttons rendered by JS -->
          </div>
        </div>
      </div>
    </div>

    <!-- ── MODAL 3: Return Application for Revision ── -->
    <div class="roster-modal-overlay" id="returnApplicationModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:16px;">
      <div class="roster-modal-card" style="background:#fff; border-radius:14px; max-width:500px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); border:1px solid #e2e8f0; display:flex; flex-direction:column;">
        <div style="padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.12); display:flex; align-items:center; justify-content:space-between; background:#1a3a8c; color:#fff; border-radius:14px 14px 0 0;">
          <div style="display:flex; align-items:center; gap:10px;">
            <div style="width:34px; height:34px; border-radius:8px; background:rgba(255,255,255,0.15); color:#fbbf24; display:flex; align-items:center; justify-content:center; font-size:1.1rem; border:1px solid rgba(255,255,255,0.25);">
              <i class="fa-solid fa-rotate-left"></i>
            </div>
            <div>
              <h3 style="margin:0; font-size:1.05rem; color:#fff; font-weight:700;">Return Application for Revision</h3>
              <div style="font-size:0.72rem; color:#cbd5e1; margin-top:2px;">Specify Feedback &amp; Additional Requirements</div>
            </div>
          </div>
          <button type="button" onclick="closeModal('returnApplicationModal')" style="width:30px; height:30px; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(255,255,255,0.1); color:#fff; font-size:1.1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.25)';" onmouseout="this.style.background='rgba(255,255,255,0.1)';" title="Close">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">
          <p style="margin:0; font-size:0.85rem; color:#475569;">
            Returning application for <strong id="returnStudentName">-</strong>. Please specify the feedback or missing requirements for the student to revise.
          </p>
          <input type="hidden" id="returnAppId" value="" />
          <div>
            <label style="display:block; font-size:0.78rem; font-weight:700; color:#334155; margin-bottom:6px;">Feedback / Revision Notes <span style="color:#dc2626;">*</span></label>
            <textarea id="returnNotes" rows="4" placeholder="e.g. Please re-upload official Adviser endorsement with signature..." style="width:100%; padding:10px 12px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:0.85rem; font-family:inherit; box-sizing:border-box; outline:none; resize:vertical;"></textarea>
          </div>
        </div>
        <div style="padding:14px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; gap:8px; border-radius:0 0 14px 14px;">
          <button type="button" class="card-btn" onclick="closeModal('returnApplicationModal')" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">Cancel</button>
          <button type="button" class="card-btn" onclick="submitReturnApplication()" style="background:#d97706; color:#fff; font-weight:700;">
            <i class="fa-solid fa-paper-plane"></i> Submit Return
          </button>
        </div>
      </div>
    </div>

    <!-- ── MODAL 4: Endorse Applicant with Letter of Endorsement ── -->
    <div class="roster-modal-overlay" id="endorseApplicantModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:16px;">
      <div class="roster-modal-card" style="background:#fff; border-radius:14px; max-width:540px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); border:1px solid #e2e8f0; display:flex; flex-direction:column;">
        <div style="padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.12); display:flex; align-items:center; justify-content:space-between; background:#1a3a8c; color:#fff; border-radius:14px 14px 0 0;">
          <div style="display:flex; align-items:center; gap:10px;">
            <div style="width:34px; height:34px; border-radius:8px; background:rgba(255,255,255,0.15); color:#34d399; display:flex; align-items:center; justify-content:center; font-size:1.15rem; border:1px solid rgba(255,255,255,0.25);">
              <i class="fa-solid fa-stamp"></i>
            </div>
            <div>
              <h3 style="margin:0; font-size:1.05rem; color:#fff; font-weight:700;">Endorse Student Applicant</h3>
              <div style="font-size:0.72rem; color:#cbd5e1; margin-top:2px;">Club Adviser Official Letter of Endorsement &amp; Recommendation</div>
            </div>
          </div>
          <button type="button" onclick="closeModal('endorseApplicantModal')" style="width:30px; height:30px; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(255,255,255,0.1); color:#fff; font-size:1.1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.25)';" onmouseout="this.style.background='rgba(255,255,255,0.1)';" title="Close">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <form id="endorseApplicantForm" onsubmit="submitEndorsement(event)">
          <div style="padding:20px; display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" id="endorseAppId" name="id" value="" />
            
            <!-- Applicant Info Strip -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; font-size:0.85rem; display:grid; grid-template-columns:1fr 1fr; gap:8px;">
              <div>
                <span style="color:#64748b; font-size:0.75rem; text-transform:uppercase; font-weight:700; display:block;">Applicant</span>
                <strong style="color:#0f172a;" id="endorseStudentName">-</strong>
              </div>
              <div>
                <span style="color:#64748b; font-size:0.75rem; text-transform:uppercase; font-weight:700; display:block;">Student Number</span>
                <span style="font-family:monospace; font-weight:700; color:#2563eb;" id="endorseStudentNo">-</span>
              </div>
              <div>
                <span style="color:#64748b; font-size:0.75rem; text-transform:uppercase; font-weight:700; display:block;">Organization</span>
                <strong style="color:#0f172a;" id="endorseOrgName">-</strong>
              </div>
              <div>
                <span style="color:#64748b; font-size:0.75rem; text-transform:uppercase; font-weight:700; display:block;">Program &amp; Year</span>
                <span style="color:#334155;" id="endorseProgram">-</span>
              </div>
            </div>

            <!-- Workflow Guidance Box -->
            <div style="background:#eff6ff; border-left:4px solid #2563eb; padding:10px 14px; border-radius:0 8px 8px 0; font-size:0.8rem; color:#1e40af; line-height:1.45;">
              <i class="fa-solid fa-circle-info"></i> <strong>Adviser Endorsement Workflow:</strong> Review the applicant's Letter of Intent, upload your official Letter of Endorsement, and provide recommendation remarks. Once submitted, this application is forwarded to the Supreme Student Council (SSC) for final review.
            </div>

            <!-- File Upload: Letter of Endorsement -->
            <div>
              <label for="endorseLetterFile" style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px;">
                <i class="fa-solid fa-paperclip" style="color:#059669;"></i> Letter of Endorsement File <span style="font-weight:400; color:#64748b;">(Optional if statement provided)</span>
              </label>
              <input type="file" id="endorseLetterFile" name="letter_endorsement" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" style="width:100%; padding:8px 10px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:0.82rem; background:#fff; box-sizing:border-box;" />
              <div style="font-size:0.72rem; color:#64748b; margin-top:4px;">
                Accepted formats: PDF, DOC, DOCX, JPG, PNG, WEBP (Max 10MB)
              </div>
            </div>

            <!-- Remarks / Endorsement Statement -->
            <div>
              <label for="endorseNotes" style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px;">
                <i class="fa-solid fa-comment-dots" style="color:#059669;"></i> Adviser Endorsement Remarks / Recommendation <span style="color:#dc2626;">*</span>
              </label>
              <textarea id="endorseNotes" name="review_notes" rows="3" placeholder="e.g. Student applicant meets all academic and co-curricular criteria in good standing. Endorsed for membership." style="width:100%; padding:10px 12px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:0.85rem; font-family:inherit; box-sizing:border-box; outline:none; resize:vertical;"></textarea>
            </div>
          </div>
          <div style="padding:14px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; gap:8px; border-radius:0 0 14px 14px;">
            <button type="button" class="card-btn" onclick="closeModal('endorseApplicantModal')" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">Cancel</button>
            <button type="submit" class="card-btn" style="background:#059669; color:#fff; font-weight:700;">
              <i class="fa-solid fa-stamp"></i> Confirm &amp; Endorse to SSC
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── MODAL: Student Member Details & Oversight (SSC / Admin / Adviser) ── -->
    <div class="roster-modal-overlay" id="memberDetailsModal" style="display:none;">
      <div class="roster-modal-card" style="max-width:680px; width:95%; border-radius:18px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(15,23,42,0.35);">
        
        <!-- Modal Header -->
        <div class="roster-modal-header" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #2563eb 100%); color:#ffffff; padding:18px 24px; border-bottom:1px solid rgba(255,255,255,0.12);">
          <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:42px; height:42px; border-radius:12px; background:rgba(255,255,255,0.15); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.25rem; border:1px solid rgba(255,255,255,0.25); flex-shrink:0;">
              <i class="fa-solid fa-id-card"></i>
            </div>
            <div>
              <h3 style="margin:0; font-size:1.1rem; color:#ffffff; font-weight:800; letter-spacing:-0.2px;">Student Member Profile</h3>
              <span style="font-size:0.75rem; color:#cbd5e1; font-weight:500;">Member Roster Oversight &bull; Student Details Record</span>
            </div>
          </div>
          <button type="button" onclick="closeModal('memberDetailsModal')" style="width:34px; height:34px; border-radius:8px; border:1px solid rgba(255,255,255,0.2); background:rgba(255,255,255,0.1); color:#ffffff; font-size:1.1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.25)';" onmouseout="this.style.background='rgba(255,255,255,0.1)';" title="Close">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>

        <!-- Modal Body -->
        <div class="roster-modal-body" style="padding:22px 24px; display:flex; flex-direction:column; gap:18px; max-height:calc(85vh - 120px); overflow-y:auto;">
          
          <!-- Top Identity Card -->
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px;">
            <div style="display:flex; align-items:center; gap:14px; min-width:0;">
              <div id="memDetailAvatar" style="width:52px; height:52px; border-radius:50%; background:linear-gradient(135deg, #1e3a8a, #2563eb); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.2rem; box-shadow:0 4px 10px rgba(37,99,235,0.3); flex-shrink:0;">
                JS
              </div>
              <div style="min-width:0;">
                <div id="memDetailFullName" style="font-size:1.15rem; font-weight:800; color:#0f172a; line-height:1.2; word-break:break-word;">Student Name</div>
                <div style="display:flex; align-items:center; gap:8px; margin-top:4px; flex-wrap:wrap;">
                  <span id="memDetailStudentId" style="font-family:monospace; font-weight:700; font-size:0.82rem; color:#2563eb; background:#eff6ff; padding:2px 8px; border-radius:6px; border:1px solid #bfdbfe;">2024-00000</span>
                  <span id="memDetailEmail" style="font-size:0.8rem; color:#64748b;">student@bcp.edu.ph</span>
                </div>
              </div>
            </div>
            <div style="text-align:right;">
              <span id="memDetailStatusBadge" style="background:#dcfce7; color:#166534; font-size:0.75rem; font-weight:700; padding:4px 12px; border-radius:20px; border:1px solid #86efac; display:inline-flex; align-items:center; gap:5px;">
                <i class="fa-solid fa-circle-check"></i> Active Member
              </span>
            </div>
          </div>

          <!-- Academic & Personal Information -->
          <div style="border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; background:#fff;">
            <h4 style="margin:0 0 14px 0; font-size:0.9rem; font-weight:800; color:#1e293b; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px; border-bottom:1px solid #f1f5f9; padding-bottom:8px;">
              <i class="fa-solid fa-graduation-cap" style="color:#2563eb;"></i> Academic &amp; Student Record
            </h4>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px;">
              <div>
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Degree / Program</div>
                <div id="memDetailCourse" style="font-weight:700; color:#0f172a; font-size:0.88rem;">Bachelor of Science in Information Technology</div>
              </div>
              <div>
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Year Level &amp; Section</div>
                <div id="memDetailYearSec" style="font-weight:700; color:#0f172a; font-size:0.88rem;">2nd Year</div>
              </div>
              <div>
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Date of Birth</div>
                <div id="memDetailDob" style="font-weight:600; color:#334155; font-size:0.85rem;">January 1, 2004</div>
              </div>
              <div>
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Contact Phone</div>
                <div id="memDetailPhone" style="font-weight:600; color:#334155; font-size:0.85rem;">Not provided</div>
              </div>
            </div>
          </div>

          <!-- Organization Membership Information -->
          <div style="border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; background:#fff;">
            <h4 style="margin:0 0 14px 0; font-size:0.9rem; font-weight:800; color:#1e293b; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px; border-bottom:1px solid #f1f5f9; padding-bottom:8px;">
              <i class="fa-solid fa-users-gear" style="color:#0284c7;"></i> Organization Affiliation
            </h4>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px;">
              <div>
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Organization</div>
                <div id="memDetailClubName" style="font-weight:700; color:#0f172a; font-size:0.88rem;">Computer Science Student Exec Council</div>
                <div id="memDetailClubCode" style="font-size:0.75rem; color:#64748b; font-weight:600; margin-top:1px;">CSSEC &bull; Recognized Organization</div>
              </div>
              <div>
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Assigned Role</div>
                <div style="margin-top:2px;">
                  <span id="memDetailRoleBadge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:0.78rem; font-weight:700; padding:3px 10px; border-radius:8px; display:inline-block;">Member</span>
                </div>
              </div>
              <div>
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Faculty Adviser</div>
                <div id="memDetailAdviser" style="font-weight:600; color:#334155; font-size:0.85rem;">Prof. Faculty Adviser</div>
              </div>
              <div>
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Membership Joined Date</div>
                <div id="memDetailJoinedDate" style="font-weight:600; color:#334155; font-size:0.85rem;">October 6, 2026</div>
              </div>
            </div>

            <!-- All Joined Organizations List -->
            <div style="margin-top:14px; padding-top:12px; border-top:1px solid #f1f5f9;">
              <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">
                <i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> All Active Organization Memberships
              </div>
              <div id="memDetailAllJoinedClubs" style="display:flex; flex-wrap:wrap; gap:6px;">
                <!-- Populated by JS -->
              </div>
            </div>
          </div>

        </div>

        <!-- Modal Footer -->
        <div class="roster-modal-footer" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; align-items:center;">
          <button type="button" class="card-btn" onclick="closeModal('memberDetailsModal')" style="background:#2563eb; color:#fff; font-weight:700; padding:8px 20px; border-radius:8px;">
            Close
          </button>
        </div>

      </div>
    </div>

    <div class="footer">eLearning Commons &copy; 2026</div>
  </div><!-- end main -->
  <script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
  <script>
    const CURRENT_USER_ROLE = '<?= $sess_role ?>';
    const ALL_CLUBS = <?= json_encode($all_clubs, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const ALL_ORG_MEMBERS = <?= json_encode($all_org_members, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const MY_MEMBERSHIPS = <?= json_encode($my_memberships, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const MY_PENDING_APPLICATIONS = <?= json_encode($my_pending_applications, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // View Detailed Student Member Profile (SSC / Admin / Adviser)
    let CURRENT_DETAIL_MEMBER = null;

    function viewMemberDetails(mem) {
      if (!mem) return;
      CURRENT_DETAIL_MEMBER = mem;
      
      const initial = ((mem.first_name ? mem.first_name.charAt(0) : 'M') + (mem.last_name ? mem.last_name.charAt(0) : '')).toUpperCase();
      const fullName = `${mem.first_name || ''} ${mem.last_name || ''}`.trim() || 'Student Member';
      const studentNum = mem.student_number || mem.student_id_no || (`2024-${10000 + Number(mem.user_id || 0)}`);
      
      const avEl = document.getElementById('memDetailAvatar');
      const nameEl = document.getElementById('memDetailFullName');
      const idEl = document.getElementById('memDetailStudentId');
      const emailEl = document.getElementById('memDetailEmail');
      const statusEl = document.getElementById('memDetailStatusBadge');
      
      const courseEl = document.getElementById('memDetailCourse');
      const yearSecEl = document.getElementById('memDetailYearSec');
      const dobEl = document.getElementById('memDetailDob');
      const phoneEl = document.getElementById('memDetailPhone');
      
      const clubNameEl = document.getElementById('memDetailClubName');
      const clubCodeEl = document.getElementById('memDetailClubCode');
      const roleBadgeEl = document.getElementById('memDetailRoleBadge');
      const adviserEl = document.getElementById('memDetailAdviser');
      const joinedDateEl = document.getElementById('memDetailJoinedDate');
      
      if (avEl) avEl.textContent = initial;
      if (nameEl) nameEl.textContent = fullName;
      if (idEl) idEl.textContent = studentNum;
      if (emailEl) emailEl.textContent = mem.email || 'N/A';
      
      if (courseEl) courseEl.textContent = mem.course || 'Bachelor of Science in Information Technology';
      const secStr = mem.section ? ` (${mem.section})` : '';
      if (yearSecEl) yearSecEl.textContent = `${mem.year_level || '1st Year'}${secStr}`;
      
      let formattedDob = 'Not specified';
      if (mem.birthday && mem.birthday !== '0000-00-00') {
        try {
          formattedDob = new Date(mem.birthday).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
        } catch(e) {
          formattedDob = mem.birthday;
        }
      }
      if (dobEl) dobEl.textContent = formattedDob;
      if (phoneEl) phoneEl.textContent = mem.phone || 'Not provided';
      
      if (clubNameEl) clubNameEl.textContent = mem.club_name || 'Organization';
      if (clubCodeEl) clubCodeEl.textContent = `${mem.club_code || 'CLUB'} • ${mem.club_category || 'Recognized Organization'}`;
      if (roleBadgeEl) roleBadgeEl.textContent = mem.member_role || 'Member';
      if (adviserEl) adviserEl.textContent = mem.adviser_name || 'Prof. Faculty Adviser';
      
      let formattedJoined = 'Recently';
      if (mem.joined_at) {
        try {
          formattedJoined = new Date(mem.joined_at.replace(' ', 'T')).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
        } catch(e) {
          formattedJoined = mem.joined_at;
        }
      }
      if (joinedDateEl) joinedDateEl.textContent = formattedJoined;
      
      const allClubsBox = document.getElementById('memDetailAllJoinedClubs');
      if (allClubsBox) {
        const jClubs = mem.joined_clubs || [];
        if (!jClubs.length) {
          allClubsBox.innerHTML = `<span style="font-size:0.78rem; color:#64748b; font-style:italic;">No other active memberships listed.</span>`;
        } else {
          allClubsBox.innerHTML = jClubs.map(c => `
            <span style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-size:0.75rem; font-weight:700; padding:3px 8px; border-radius:6px; display:inline-flex; align-items:center; gap:5px;">
              <i class="fa-solid fa-check"></i> ${escapeHtml(c.club_code)} &mdash; ${escapeHtml(c.club_name)}
              <span style="background:#dbeafe; color:#1e3a8a; padding:1px 4px; border-radius:3px; font-size:0.68rem;">${escapeHtml(c.member_role || 'Member')}</span>
            </span>
          `).join('');
        }
      }
      
      openModal('memberDetailsModal');
    }

    function viewStudentApplicationDoc(appId, tab = 'intent') {
      const found = MY_PENDING_APPLICATIONS.find(a => Number(a.id) === Number(appId));
      if (!found) return;
      const apDossier = {
        id: found.id,
        club_id: found.club_id,
        club_name: found.club_name,
        club_code: found.club_code,
        first_name: found.first_name || '<?= addslashes($sess_first) ?>',
        last_name: found.last_name || '<?= addslashes($sess_last) ?>',
        student_id_no: found.student_number || '',
        course: found.course || 'BSIT',
        year_level: found.year_level || '1st Year',
        submitted_date: found.joined_at,
        current_status: found.status || 'Pending',
        adviser_review: found.adviser_review || 'Pending Adviser',
        ssc_review: found.ssc_review || 'Pending SSC',
        letter_intent: found.letter_intent || '',
        letter_endorsement: found.letter_endorsement || '',
        motivation: found.motivation || '',
        review_notes: found.review_notes || ''
      };
      viewApplicantDocs(apDossier, tab);
    }

    function openModal(id)  { const el = document.getElementById(id); if (el) { el.classList.add('active'); el.style.display = 'flex'; } }
    function closeModal(id) { const el = document.getElementById(id); if (el) { el.classList.remove('active', 'open'); el.style.display = 'none'; } }

    function selectOrgRoster(clubId, clubName, clubCode) {
      const rosterCard = document.getElementById('selectedOrgRosterCard');
      if (rosterCard) rosterCard.style.display = 'block';

      const titleEl = document.getElementById('selectedRosterTitle');
      const subEl = document.getElementById('selectedRosterSub');
      const emptyNotice = document.getElementById('rosterEmptyNotice');
      const tableWrap = document.getElementById('rosterTableWrap');
      const body = document.getElementById('studentOrgRosterBody');

      if (!clubId) {
        if (emptyNotice) emptyNotice.style.display = 'block';
        if (tableWrap) tableWrap.style.display = 'none';
        return;
      }

      if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-users-rectangle" style="color:#2563eb;"></i> Member Roster: <strong>${clubName}</strong> (${clubCode})`;
      if (subEl) subEl.textContent = `Official active roster of members for ${clubName}`;

      const members = ALL_ORG_MEMBERS.filter(m => parseInt(m.club_id) === parseInt(clubId));

      if (!members.length) {
        if (emptyNotice) {
          emptyNotice.innerHTML = `
            <i class="fa-solid fa-users-slash" style="font-size:2rem; color:#94a3b8; margin-bottom:8px; display:block;"></i>
            No active members currently listed for <strong>${clubName}</strong>.
          `;
          emptyNotice.style.display = 'block';
        }
        if (tableWrap) tableWrap.style.display = 'none';
      } else {
        if (emptyNotice) emptyNotice.style.display = 'none';
        if (tableWrap) tableWrap.style.display = 'block';

        let html = '';
        members.forEach((m, idx) => {
          const courseStr = (m.course || 'BSIT') + ' - ' + (m.year_level || '3rd Year');
          const dateStr = new Date(m.joined_at.replace(' ','T')).toLocaleDateString('en-PH', {month:'short', day:'numeric', year:'numeric'});
          html += `
            <tr class="roster-row">
              <td>${idx + 1}</td>
              <td><strong>${m.first_name} ${m.last_name}</strong></td>
              <td>${m.email}</td>
              <td style="font-size:0.82rem; color:#64748b;">${courseStr}</td>
              <td><span class="badge-info" style="font-size:0.75rem; font-weight:700;">${m.member_role}</span></td>
              <td style="font-size:0.82rem;">${dateStr}</td>
              <td><span class="badge-active">Active</span></td>
              <td style="text-align:right;">
                <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff; font-weight:700; padding:5px 10px; font-size:0.76rem; display:inline-flex; align-items:center; gap:4px;" onclick="viewMemberDetails(ALL_ORG_MEMBERS.find(x => x.id == ${m.id}))" title="View Student Member Details">
                  <i class="fa-solid fa-id-card"></i> Details
                </button>
              </td>
            </tr>
          `;
        });
        if (body) body.innerHTML = html;
        const sTbl = document.getElementById('studentOrgRosterTable');
        if (sTbl && window.initTablePagination) {
          if (sTbl._paginator) sTbl._paginator.refresh();
          else window.initTablePagination(sTbl, { pageSize: 5 });
        }
      }

      if (rosterCard) rosterCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeOrgRoster() {
      const rosterCard = document.getElementById('selectedOrgRosterCard');
      if (rosterCard) {
        rosterCard.style.display = 'none';
      }
    }

    // -- Live AJAX actions -----------------------------------------
    function showAlert(msg, type) {
      const el = document.getElementById('rosterAlert');
      if (!el) return;
      el.style.display = 'block';
      el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
      el.style.color = type === 'success' ? '#166534' : '#991b1b';
      el.style.border = `1px solid ${type === 'success' ? '#bbf7d0' : '#fecaca'}`;
      el.textContent = msg;
      setTimeout(() => { el.style.display = 'none'; }, 4000);
    }

    async function handleApplication(id, action) {
      if (action === 'reject') {
        const confirmed = await window.showConfirmModal(
          'Reject Application?',
          'Do you want to reject this membership application?',
          { type: 'error', danger: true, confirmText: 'Yes, Reject Application' }
        );
        if (!confirmed) return;
      }
      if (action === 'approve') {
        const confirmed = await window.showConfirmModal(
          'Approve Application?',
          'Do you want to approve this membership application?',
          { type: 'decision', confirmText: 'Yes, Approve Application' }
        );
        if (!confirmed) return;
      }
      if (action === 'endorse') {
        const confirmed = await window.showConfirmModal(
          'Endorse Application?',
          'Do you want to endorse this membership application and forward it to SSC for final approval?',
          { type: 'decision', confirmText: 'Yes, Endorse to SSC' }
        );
        if (!confirmed) return;
      }

      const fd = new FormData();
      fd.set('action', action);
      fd.set('id', id);
      if (window.CSRF_TOKEN) fd.set('csrf_token', window.CSRF_TOKEN);
      fetch('../shared/roster_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showAlert(data.message, 'success');
            closeModal('applicantReviewModal');
            setTimeout(() => { window.location.reload(); }, 600);
          } else {
            showAlert(data.message, 'error');
          }
        })
        .catch(() => showAlert('Network error. Please try again.', 'error'));
    }

    function openReturnModal(id, studentName) {
      const idEl = document.getElementById('returnAppId');
      const nameEl = document.getElementById('returnStudentName');
      const notesEl = document.getElementById('returnNotes');
      if (idEl) idEl.value = id;
      if (nameEl) nameEl.textContent = studentName || 'Applicant';
      if (notesEl) notesEl.value = '';
      openModal('returnApplicationModal');
    }

    async function submitReturnApplication() {
      const id = document.getElementById('returnAppId')?.value;
      const notes = document.getElementById('returnNotes')?.value?.trim();
      if (!id) return;
      if (!notes) {
        window.alert('Please enter a feedback or revision note for the student.', 'warning');
        return;
      }

      const confirmed = await window.showConfirmModal(
        'Return Application for Revision?',
        'Do you want to return this membership application for revision with the stated feedback?',
        { type: 'warning', warning: true, confirmText: 'Yes, Return Application' }
      );
      if (!confirmed) return;

      const fd = new FormData();
      fd.set('action', 'return');
      fd.set('id', id);
      fd.set('notes', notes);
      if (window.CSRF_TOKEN) fd.set('csrf_token', window.CSRF_TOKEN);

      fetch('../shared/roster_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showAlert(data.message, 'success');
            closeModal('returnApplicationModal');
            closeModal('applicantReviewModal');
            setTimeout(() => { window.location.reload(); }, 600);
          } else {
            window.alert(data.message, 'error');
          }
        })
        .catch(() => window.alert('Network error. Please try again.', 'error'));
    }

    async function removeMember(id) {
      const confirmed = await window.showConfirmModal(
        'Remove Organization Member?',
        'Do you want to remove this member from the organization roster?',
        { type: 'error', danger: true, confirmText: 'Yes, Remove Member' }
      );
      if (!confirmed) return;

      const fd = new FormData();
      fd.set('action', 'remove_member');
      fd.set('id', id);
      if (window.CSRF_TOKEN) fd.set('csrf_token', window.CSRF_TOKEN);
      fetch('../shared/roster_actions.php', { method: 'POST', body: fd })
        .then(async r => {
          const res = await r.json().catch(() => null);
          if (!res) throw new Error('Server returned an invalid response. Please try again.');
          return res;
        })
        .then(data => {
          if (data.success) {
            showAlert(data.message, 'success');
            const row = document.getElementById(`member-row-${id}`);
            if (row) {
              row.style.opacity = '0';
              setTimeout(() => {
                row.remove();
                document.querySelectorAll('#queueTable, #masterRosterTable').forEach(t => t._paginator && t._paginator.refresh());
              }, 300);
            }
          } else {
            showAlert(data.message, 'error');
          }
        })
        .catch(err => showAlert(err.message || 'Network error.', 'error'));
    }

    // -- Search & Filters for Review Queue ------------------------
    function filterReviewQueue() {
      const q = (document.getElementById('queueSearch')?.value || '').toLowerCase().trim();
      const org = (document.getElementById('filterOrg')?.value || '').toLowerCase().trim();
      const st = (document.getElementById('filterStatus')?.value || '').toLowerCase().trim();

      document.querySelectorAll('.queue-row').forEach(row => {
        const text = row.textContent.toLowerCase();
        const rowOrg = (row.getAttribute('data-org') || '').toLowerCase();
        const rowSt = (row.getAttribute('data-status') || '').toLowerCase();

        const matchSearch = !q || text.includes(q);
        const matchOrg = !org || rowOrg === org;
        const matchSt = !st || rowSt === st || (st === 'approved' && rowSt === 'active');

        row.setAttribute('data-search-hidden', (matchSearch && matchOrg && matchSt) ? 'false' : 'true');
      });

      const tbl = document.getElementById('queueTable');
      if (tbl && tbl._paginator) {
        tbl._paginator.currentPage = 1;
        tbl._paginator.refresh();
      }
    }

    function resetQueueFilter() {
      const s = document.getElementById('queueSearch'); if (s) s.value = '';
      const o = document.getElementById('filterOrg'); if (o) o.value = '';
      const st = document.getElementById('filterStatus'); if (st) st.value = '';
      filterReviewQueue();
    }

    // -- Organization Roster Oversight Filter System --------------
    function filterMasterRoster() {
      const orgSelect = document.getElementById('rosterFilterOrg');
      const selectedOrg = (orgSelect?.value || 'all').toUpperCase().trim();
      const search = (document.getElementById('rosterSearch')?.value || '').toLowerCase().trim();
      const role = (document.getElementById('rosterFilterRole')?.value || 'all').toLowerCase().trim();


      // Filter master roster rows
      const allRows = document.querySelectorAll('.master-roster-row');
      let visibleCount = 0;

      allRows.forEach(row => {
        const rowOrg = (row.getAttribute('data-org') || '').toUpperCase().trim();
        const rowRole = (row.getAttribute('data-role') || '').toLowerCase().trim();
        const rowSearch = (row.getAttribute('data-search') || '').toLowerCase();

        const matchOrg = (selectedOrg === 'ALL') || (rowOrg === selectedOrg);
        const matchRole = (role === 'all') || rowRole.includes(role);
        const matchSearch = !search || rowSearch.includes(search);

        const isMatch = matchOrg && matchRole && matchSearch;
        row.setAttribute('data-search-hidden', isMatch ? 'false' : 'true');
        if (isMatch) visibleCount++;
      });

      // Toggle table wrap vs tailored empty state
      const tblWrap = document.querySelector('#master-roster .table-wrap');
      const emptyState = document.getElementById('rosterEmptyStateWrap');
      const emptyTitle = document.getElementById('rosterEmptyTitle');
      const emptyDesc = document.getElementById('rosterEmptyDesc');
      const countBadge = document.getElementById('rosterCountBadge');

      if (countBadge) countBadge.textContent = `${visibleCount} Active`;

      if (visibleCount === 0) {
        if (tblWrap) tblWrap.style.display = 'none';
        if (emptyState) emptyState.style.display = 'block';

        if (selectedOrg !== 'ALL') {
          const orgName = opt?.getAttribute('data-name') || selectedOrg;
          if (emptyTitle) emptyTitle.textContent = `No Active Members for ${selectedOrg}`;
          if (emptyDesc) emptyDesc.textContent = `There are currently 0 active approved student memberships in the registry for ${orgName}.`;
        } else {
          if (emptyTitle) emptyTitle.textContent = `No Active Members Found`;
          if (emptyDesc) emptyDesc.textContent = `No approved student memberships match your current search/filter criteria.`;
        }
      } else {
        if (tblWrap) tblWrap.style.display = '';
        if (emptyState) emptyState.style.display = 'none';
      }

      // Refresh paginator
      const mTbl = document.getElementById('masterRosterTable');
      if (mTbl && mTbl._paginator) {
        mTbl._paginator.currentPage = 1;
        mTbl._paginator.refresh();
      }
    }

    function clearRosterFilter() {
      const orgSelect = document.getElementById('rosterFilterOrg'); if (orgSelect) orgSelect.value = 'all';
      const s = document.getElementById('rosterSearch'); if (s) s.value = '';
      const r = document.getElementById('rosterFilterRole'); if (r) r.value = 'all';
      filterMasterRoster();
    }

    // -- Export CSV -----------------------------------------------
    document.getElementById('exportCsvBtn')?.addEventListener('click', function () {
      const orgSelect = document.getElementById('rosterFilterOrg');
      const selectedOrg = (orgSelect?.value || 'all').trim();
      const exportUrl = '../shared/roster_actions.php?action=export_csv' + (selectedOrg !== 'all' ? '&club_code=' + encodeURIComponent(selectedOrg) : '');

      fetch(exportUrl)
        .then(r => r.json())
        .then(data => {
          if (!data.success) { showAlert(data.message, 'error'); return; }
          const rows = data.csv_data;
          if (!rows.length) { showAlert('No active members to export for this selection.', 'error'); return; }
          const headers = [
            'Student Number', 'First Name', 'Last Name', 'Email',
            'Year', 'Section', 'Organization Name', 'Organization Code',
            'Role', 'Joined At'
          ];
          const csv = [headers.join(','), ...rows.map(r =>
            [
              r.student_number, r.first_name, r.last_name, r.email,
              r.year_level, r.section, r.club_name, r.code,
              r.member_role, r.joined_at
            ]
              .map(v => `"${(v || '').replace(/"/g, '""')}"`)
              .join(',')
          )].join('\n');
          const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a'); a.href = url;
          a.download = (selectedOrg !== 'all' ? `${selectedOrg}_` : 'BCP_Organization_') + 'Roster_' + new Date().toISOString().slice(0, 10) + '.csv';
          a.click(); URL.revokeObjectURL(url);
          showAlert('Roster exported successfully!', 'success');
        });
    });

    // Global references for modal viewers
    let CURRENT_REVIEW_AP = null;
    let CURRENT_DOC_AP = null;
    let CURRENT_DOC_TAB = 'intent';

    function escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function isRealDocFile(str) {
      if (!str || typeof str !== 'string') return false;
      const s = str.trim().toLowerCase();
      return s.endsWith('.pdf') || s.endsWith('.doc') || s.endsWith('.docx') || 
             s.endsWith('.png') || s.endsWith('.jpg') || s.endsWith('.jpeg') || s.endsWith('.webp');
    }

    function getDocExt(str) {
      if (!str || typeof str !== 'string') return '';
      const parts = str.trim().toLowerCase().split('.');
      return parts.length > 1 ? parts.pop() : '';
    }

    // -- Modal 1: Review Applicant --------------------------------
    function reviewApplicant(ap) {
      CURRENT_REVIEW_AP = ap;
      const appNo = 'APP-' + (ap.submitted_date ? ap.submitted_date.slice(0, 4) : '2026') + '-' + String(ap.id).padStart(4, '0');
      document.getElementById('reviewModalAppNo').textContent = appNo;
      document.getElementById('reviewStudentName').textContent = (ap.first_name + ' ' + ap.last_name);
      document.getElementById('reviewStudentNo').textContent = ap.student_id_no || 'N/A';
      document.getElementById('reviewProgram').textContent = ap.course || 'BSIT';
      document.getElementById('reviewYear').textContent = ap.year_level || '1st Year';
      
      const avEl = document.getElementById('reviewAvatar');
      if (avEl) {
        const i1 = (ap.first_name || 'S').trim().charAt(0).toUpperCase();
        const i2 = (ap.last_name || 'A').trim().charAt(0).toUpperCase();
        avEl.textContent = i1 + i2;
      }
      
      const orgBadge = document.getElementById('reviewOrgBadge');
      if (orgBadge) orgBadge.textContent = ap.club_code || 'ORG';
      const orgFullName = document.getElementById('reviewOrgFullName');
      if (orgFullName) orgFullName.textContent = ap.club_name || '';

      // Populate Joined Clubs in Review Modal
      const jClubs = ap.joined_clubs || [];
      const countBadge = document.getElementById('reviewJoinedClubsCountBadge');
      if (countBadge) {
        countBadge.textContent = `${jClubs.length} Active Club${jClubs.length === 1 ? '' : 's'}`;
      }
      
      const jContainer = document.getElementById('reviewJoinedClubsContainer');
      if (jContainer) {
        if (!jClubs.length) {
          jContainer.innerHTML = `
            <div style="font-size:0.78rem; color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:6px; padding:8px 12px; width:100%;">
              <i class="fa-solid fa-circle-info" style="color:#2563eb;"></i> No other active organization memberships (First-time applicant).
            </div>
          `;
        } else {
          jContainer.innerHTML = jClubs.map(c => `
            <span style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-size:0.75rem; font-weight:700; padding:4px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:6px;">
              <i class="fa-solid fa-circle-check" style="color:#2563eb;"></i>
              <strong>${escapeHtml(c.club_code)}</strong> &mdash; ${escapeHtml(c.club_name)}
              <span style="background:#dbeafe; color:#1e3a8a; padding:1px 5px; border-radius:4px; font-size:0.7rem;">${escapeHtml(c.member_role || 'Member')}</span>
            </span>
          `).join('');
        }
      }
      
      const targetNameEl = document.getElementById('reviewTargetClubName');
      if (targetNameEl) targetNameEl.textContent = ap.club_name || 'Organization';
      const targetCodeEl = document.getElementById('reviewTargetClubCode');
      if (targetCodeEl) targetCodeEl.textContent = `(${ap.club_code || 'CLUB'})`;

      // Formatted date
      let dateStr = ap.submitted_date || ap.joined_at || '';
      if (dateStr) {
        try {
          const d = new Date(dateStr.replace(' ', 'T'));
          if (!isNaN(d.getTime())) {
            dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' • ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
          }
        } catch(e) {}
      }
      document.getElementById('reviewSubmitted').textContent = dateStr;

      // Status conditions
      const isEndorsed = (ap.adviser_review === 'Endorsed' || ap.adviser_status === 'Endorsed');
      const isApproved = (ap.current_status === 'Active' || ap.current_status === 'Approved' || ap.ssc_review === 'Approved');
      const isRejected = (ap.current_status === 'Rejected' || ap.ssc_review === 'Rejected' || ap.adviser_review === 'Rejected');
      const isReturned = (ap.current_status === 'Returned' || ap.ssc_review === 'Returned');

      // Stepper rendering (Multi-Tier Clearance Progress)
      const stepperEl = document.getElementById('reviewStepperContainer');
      if (stepperEl) {
        // Stage 1: Student
        let s1 = `
          <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px;">
            <div style="font-size:0.68rem; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:0.3px; display:flex; align-items:center; gap:5px;">
              <i class="fa-solid fa-circle-check"></i> Stage 1: Student
            </div>
            <div style="font-size:0.8rem; font-weight:700; color:#166534; margin-top:3px;">Intent Submitted</div>
          </div>
        `;

        // Stage 2: Adviser
        let s2 = '';
        if (isEndorsed) {
          s2 = `
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px;">
              <div style="font-size:0.68rem; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:0.3px; display:flex; align-items:center; gap:5px;">
                <i class="fa-solid fa-circle-check"></i> Stage 2: Adviser
              </div>
              <div style="font-size:0.8rem; font-weight:700; color:#166534; margin-top:3px;">Endorsed with Letter</div>
            </div>
          `;
        } else if (ap.adviser_review === 'Returned') {
          s2 = `
            <div style="background:#faf5ff; border:1px solid #e9d5ff; border-radius:8px; padding:10px;">
              <div style="font-size:0.68rem; font-weight:700; color:#7e22ce; text-transform:uppercase; letter-spacing:0.3px; display:flex; align-items:center; gap:5px;">
                <i class="fa-solid fa-rotate-left"></i> Stage 2: Adviser
              </div>
              <div style="font-size:0.8rem; font-weight:700; color:#6b21a8; margin-top:3px;">Returned for Revision</div>
            </div>
          `;
        } else {
          s2 = `
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:10px;">
              <div style="font-size:0.68rem; font-weight:700; color:#b45309; text-transform:uppercase; letter-spacing:0.3px; display:flex; align-items:center; gap:5px;">
                <i class="fa-solid fa-clock"></i> Stage 2: Adviser
              </div>
              <div style="font-size:0.8rem; font-weight:700; color:#92400e; margin-top:3px;">Pending Endorsement</div>
            </div>
          `;
        }

        // Stage 3: SSC
        let s3 = '';
        if (isApproved) {
          s3 = `
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px;">
              <div style="font-size:0.68rem; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:0.3px; display:flex; align-items:center; gap:5px;">
                <i class="fa-solid fa-circle-check"></i> Stage 3: SSC
              </div>
              <div style="font-size:0.8rem; font-weight:700; color:#166534; margin-top:3px;">Approved &amp; Active</div>
            </div>
          `;
        } else if (isRejected) {
          s3 = `
            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px;">
              <div style="font-size:0.68rem; font-weight:700; color:#b91c1c; text-transform:uppercase; letter-spacing:0.3px; display:flex; align-items:center; gap:5px;">
                <i class="fa-solid fa-circle-xmark"></i> Stage 3: SSC
              </div>
              <div style="font-size:0.8rem; font-weight:700; color:#991b1b; margin-top:3px;">Rejected</div>
            </div>
          `;
        } else if (isReturned) {
          s3 = `
            <div style="background:#faf5ff; border:1px solid #e9d5ff; border-radius:8px; padding:10px;">
              <div style="font-size:0.68rem; font-weight:700; color:#7e22ce; text-transform:uppercase; letter-spacing:0.3px; display:flex; align-items:center; gap:5px;">
                <i class="fa-solid fa-rotate-left"></i> Stage 3: SSC
              </div>
              <div style="font-size:0.8rem; font-weight:700; color:#6b21a8; margin-top:3px;">Returned for Revision</div>
            </div>
          `;
        } else {
          s3 = `
            <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px;">
              <div style="font-size:0.68rem; font-weight:700; color:#1d4ed8; text-transform:uppercase; letter-spacing:0.3px; display:flex; align-items:center; gap:5px;">
                <i class="fa-solid fa-clock"></i> Stage 3: SSC
              </div>
              <div style="font-size:0.8rem; font-weight:700; color:#1e40af; margin-top:3px;">Pending SSC Review</div>
            </div>
          `;
        }

        stepperEl.innerHTML = s1 + s2 + s3;
      }

      // Section 1: Letter of Intent File & Motivation
      const motText = document.getElementById('reviewMotivationText');
      if (motText) {
        motText.textContent = ap.motivation || (isRealDocFile(ap.letter_intent) ? '' : ap.letter_intent) || 'I hereby express my genuine intent and commitment to participate actively in this organization.';
      }
      const intentFileBox = document.getElementById('reviewIntentFileBox');
      if (intentFileBox) {
        if (isRealDocFile(ap.letter_intent)) {
          const ext = getDocExt(ap.letter_intent).toUpperCase();
          let iconClass = 'fa-solid fa-file-lines';
          let iconBg = '#eff6ff';
          let iconColor = '#2563eb';
          if (ext === 'PDF') { iconClass = 'fa-solid fa-file-pdf'; iconBg = '#fef2f2'; iconColor = '#dc2626'; }
          else if (['DOC', 'DOCX'].includes(ext)) { iconClass = 'fa-solid fa-file-word'; iconBg = '#eff6ff'; iconColor = '#2563eb'; }
          else if (['PNG', 'JPG', 'JPEG', 'WEBP'].includes(ext)) { iconClass = 'fa-solid fa-file-image'; iconBg = '#f0fdf4'; iconColor = '#16a34a'; }

          intentFileBox.innerHTML = `
            <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
              <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                <div style="width:38px; height:38px; border-radius:8px; background:${iconBg}; color:${iconColor}; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">
                  <i class="${iconClass}"></i>
                </div>
                <div style="min-width:0;">
                  <div style="font-weight:700; font-size:0.85rem; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:320px;" title="${escapeHtml(ap.letter_intent)}">
                    ${escapeHtml(ap.letter_intent)}
                  </div>
                  <div style="font-size:0.72rem; color:#64748b; margin-top:2px;">
                    ${ext} Document • Attached Letter of Intent
                  </div>
                </div>
              </div>
              <div style="display:flex; align-items:center; gap:6px;">
                <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:5px; font-weight:600; padding:6px 12px; border-radius:6px;" onclick="viewApplicantDocs(CURRENT_REVIEW_AP, 'intent')" title="View in Center Screen Document Center">
                  <i class="fa-solid fa-expand"></i> View
                </button>
                <a href="../uploads/applications/${encodeURIComponent(ap.letter_intent)}" target="_blank" class="card-btn btn-sm" style="background:#fff; color:#475569; border:1px solid #cbd5e1; text-decoration:none; display:inline-flex; align-items:center; gap:4px; padding:6px 10px; border-radius:6px;" title="Open in new tab">
                  <i class="fa-solid fa-arrow-up-right-from-square"></i>
                </a>
              </div>
            </div>
          `;
        } else {
          intentFileBox.innerHTML = `
            <div style="font-size:0.78rem; color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; padding:8px 12px;">
              <i class="fa-solid fa-circle-info" style="color:#2563eb;"></i> Digital text application statement (no standalone file attachment).
            </div>
          `;
        }
      }

      // Section 2: Adviser Endorsement Letter
      const endBadge = document.getElementById('reviewEndorsementBadge');
      const endHeaderAction = document.getElementById('reviewEndorsementHeaderAction');
      const endBody = document.getElementById('reviewEndorsementBody');

      if (isEndorsed) {
        if (endBadge) {
          endBadge.innerHTML = `<span style="font-size:0.7rem; font-weight:700; color:#166534; background:#dcfce7; padding:2px 8px; border-radius:4px;"><i class="fa-solid fa-check"></i> Endorsed</span>`;
        }
        if (endHeaderAction) {
          endHeaderAction.innerHTML = `
            <button type="button" class="card-btn btn-sm" style="background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; font-size:0.75rem; padding:3px 10px; display:inline-flex; align-items:center; gap:5px;" onclick="viewApplicantDocs(CURRENT_REVIEW_AP, 'endorsement')">
              <i class="fa-solid fa-expand"></i> Center View
            </button>
          `;
        }
        if (endBody) {
          let fileHtml = '';
          if (isRealDocFile(ap.letter_endorsement)) {
            const ext = getDocExt(ap.letter_endorsement).toUpperCase();
            let iconClass = 'fa-solid fa-stamp';
            let iconBg = '#ecfdf5';
            let iconColor = '#059669';
            if (ext === 'PDF') { iconClass = 'fa-solid fa-file-pdf'; iconBg = '#fef2f2'; iconColor = '#dc2626'; }
            else if (['DOC', 'DOCX'].includes(ext)) { iconClass = 'fa-solid fa-file-word'; iconBg = '#eff6ff'; iconColor = '#2563eb'; }

            fileHtml = `
              <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                  <div style="width:38px; height:38px; border-radius:8px; background:${iconBg}; color:${iconColor}; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">
                    <i class="${iconClass}"></i>
                  </div>
                  <div style="min-width:0;">
                    <div style="font-weight:700; font-size:0.85rem; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:320px;" title="${escapeHtml(ap.letter_endorsement)}">
                      ${escapeHtml(ap.letter_endorsement)}
                    </div>
                    <div style="font-size:0.72rem; color:#64748b; margin-top:2px;">
                      ${ext} Document • Official Adviser Endorsement Letter
                    </div>
                  </div>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                  <button type="button" class="card-btn btn-sm" style="background:#059669; color:#fff; display:inline-flex; align-items:center; gap:5px; font-weight:600; padding:6px 12px; border-radius:6px;" onclick="viewApplicantDocs(CURRENT_REVIEW_AP, 'endorsement')" title="View in Center Screen Document Center">
                    <i class="fa-solid fa-expand"></i> View
                  </button>
                  <a href="../uploads/applications/${encodeURIComponent(ap.letter_endorsement)}" target="_blank" class="card-btn btn-sm" style="background:#fff; color:#475569; border:1px solid #cbd5e1; text-decoration:none; display:inline-flex; align-items:center; gap:4px; padding:6px 10px; border-radius:6px;" title="Open in new tab">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                  </a>
                </div>
              </div>
            `;
          }

          const notesText = ap.review_notes && !isRealDocFile(ap.review_notes) ? ap.review_notes : 'Applicant is recommended and meets good standing criteria. Endorsed for official membership.';
          endBody.innerHTML = `
            ${fileHtml}
            <div>
              <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; letter-spacing:0.3px; margin-bottom:4px;">Adviser Recommendation Remarks:</div>
              <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.85rem; line-height:1.55; color:#334155; white-space:pre-wrap;">
                ${escapeHtml(notesText)}
              </div>
            </div>
          `;
        }
      } else {
        if (endBadge) {
          endBadge.innerHTML = `<span style="font-size:0.7rem; font-weight:600; color:#b45309; background:#fef3c7; padding:2px 8px; border-radius:4px;">Pending Adviser</span>`;
        }
        if (endHeaderAction) {
          endHeaderAction.innerHTML = '';
        }
        if (endBody) {
          endBody.innerHTML = `
            <div style="background:#fffbeb; border:1.5px dashed #fcd34d; border-radius:10px; padding:16px; display:flex; align-items:center; gap:14px;">
              <div style="width:42px; height:42px; border-radius:10px; background:#fef3c7; color:#d97706; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0;">
                <i class="fa-solid fa-hourglass-start"></i>
              </div>
              <div style="flex:1;">
                <div style="font-size:0.88rem; font-weight:700; color:#92400e;">Awaiting Faculty Adviser Endorsement</div>
                <div style="font-size:0.78rem; color:#b45309; line-height:1.45; margin-top:2px;">
                  The Club Faculty Adviser has not yet issued the official Letter of Endorsement for this applicant. Once endorsed, the signed document and recommendation remarks will be recorded here.
                </div>
              </div>
            </div>
          `;
        }
      }

      // Action buttons
      const btnBox = document.getElementById('reviewModalActionBtns');
      if (btnBox) {
        if (ap.current_status === 'Pending' || ap.current_status === 'Returned') {
          if (CURRENT_USER_ROLE === 'club_adviser') {
            if (!isEndorsed) {
              btnBox.innerHTML = `
                <button type="button" class="card-btn" style="background:#d97706; color:#fff; font-weight:600; padding:8px 14px; border-radius:8px;" onclick="openReturnModal(${ap.id}, '${escapeHtml(ap.first_name + ' ' + ap.last_name)}')">
                  <i class="fa-solid fa-rotate-left"></i> Return
                </button>
                <button type="button" class="card-btn btn-danger" style="font-weight:600; padding:8px 14px; border-radius:8px;" onclick="handleApplication(${ap.id}, 'reject')">
                  <i class="fa-solid fa-xmark"></i> Reject
                </button>
                <button type="button" class="card-btn" style="background:#059669; color:#fff; font-weight:700; padding:8px 16px; border-radius:8px; box-shadow:0 1px 3px rgba(5,150,105,0.3);" onclick="openEndorseModal(CURRENT_REVIEW_AP)">
                  <i class="fa-solid fa-stamp"></i> Endorse with Letter of Endorsement
                </button>
              `;
            } else {
              btnBox.innerHTML = `
                <span style="display:inline-flex; align-items:center; gap:6px; font-size:0.82rem; font-weight:700; color:#065f46; background:#d1fae5; border:1px solid #a7f3d0; padding:6px 14px; border-radius:8px;">
                  <i class="fa-solid fa-check-double"></i> Endorsed &mdash; Awaiting SSC Approval
                </span>
              `;
            }
          } else {
            // SSC or Admin (Approval Authority for the joined/applied club)
            btnBox.innerHTML = `
              <button type="button" class="card-btn" style="background:#d97706; color:#fff; font-weight:600; padding:8px 14px; border-radius:8px;" onclick="openReturnModal(${ap.id}, '${escapeHtml(ap.first_name + ' ' + ap.last_name)}')">
                <i class="fa-solid fa-rotate-left"></i> Return
              </button>
              <button type="button" class="card-btn btn-danger" style="font-weight:600; padding:8px 14px; border-radius:8px;" onclick="handleApplication(${ap.id}, 'reject')">
                <i class="fa-solid fa-xmark"></i> Reject
              </button>
              <button type="button" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700; padding:8px 18px; border-radius:8px; box-shadow:0 1px 3px rgba(22,163,74,0.3); display:inline-flex; align-items:center; gap:6px;" onclick="handleApplication(${ap.id}, 'approve')" title="Approve applicant into ${escapeHtml(ap.club_code)} membership">
                <i class="fa-solid fa-check"></i> Approve Application for ${escapeHtml(ap.club_code)}
              </button>
            `;
          }
        } else {
          btnBox.innerHTML = `
            <span style="font-size:0.82rem; color:#64748b; font-weight:600; align-self:center;">Decision finalized (${escapeHtml(ap.current_status)})</span>
          `;
        }
      }

      openModal('applicantReviewModal');
    }

    // -- Modal 2: View Docs in Center Screen ------------------------
    function viewApplicantDocs(ap, initialTab = 'intent') {
      CURRENT_DOC_AP = ap;
      CURRENT_DOC_TAB = initialTab;

      // Close review modal if open so docs viewer takes full center focus
      closeModal('applicantReviewModal');

      const appNo = 'APP-' + (ap.submitted_date ? ap.submitted_date.slice(0, 4) : '2026') + '-' + String(ap.id).padStart(4, '0');
      const nameEl = document.getElementById('docsModalStudentName');
      if (nameEl) nameEl.textContent = `${ap.first_name} ${ap.last_name}`;
      const appNoEl = document.getElementById('docsModalAppNo');
      if (appNoEl) appNoEl.textContent = appNo;
      const orgEl = document.getElementById('docsModalOrg');
      if (orgEl) orgEl.textContent = `${ap.club_code} - ${ap.club_name}`;

      renderDocTabs();
      renderActiveDocViewer();
      renderDocsFooterActions();

      openModal('applicationDocsModal');
    }

    function switchDocTab(tab) {
      CURRENT_DOC_TAB = tab;
      renderDocTabs();
      renderActiveDocViewer();
    }

    function renderDocTabs() {
      const container = document.getElementById('docsTabSwitcher');
      if (!container || !CURRENT_DOC_AP) return;

      const hasIntentFile = isRealDocFile(CURRENT_DOC_AP.letter_intent);
      const hasEndorseFile = isRealDocFile(CURRENT_DOC_AP.letter_endorsement);
      const intentExt = hasIntentFile ? getDocExt(CURRENT_DOC_AP.letter_intent).toUpperCase() : 'NOTE';
      const endorseExt = hasEndorseFile ? getDocExt(CURRENT_DOC_AP.letter_endorsement).toUpperCase() : 'NOTE';

      const isIntentActive = CURRENT_DOC_TAB === 'intent';
      const isEndorseActive = CURRENT_DOC_TAB === 'endorsement';

      container.innerHTML = `
        <button type="button" onclick="switchDocTab('intent')" style="padding:4px 12px; border:none; border-radius:6px; cursor:pointer; font-size:0.78rem; display:inline-flex; align-items:center; gap:6px; transition:all 0.15s; ${isIntentActive ? 'background:#ffffff; color:#1a3a8c; font-weight:700; box-shadow:0 1px 3px rgba(0,0,0,0.25);' : 'background:transparent; color:rgba(255,255,255,0.85); font-weight:600;'}">
          <i class="fa-solid fa-file-lines" style="${isIntentActive ? 'color:#1a3a8c;' : 'color:rgba(255,255,255,0.7);'}"></i>
          <span>Letter of Intent</span>
          <span style="font-size:0.65rem; font-weight:700; padding:1px 5px; border-radius:4px; ${isIntentActive ? 'background:#eff6ff; color:#1d4ed8;' : 'background:rgba(255,255,255,0.18); color:#ffffff;'}">${intentExt}</span>
        </button>
        <button type="button" onclick="switchDocTab('endorsement')" style="padding:4px 12px; border:none; border-radius:6px; cursor:pointer; font-size:0.78rem; display:inline-flex; align-items:center; gap:6px; transition:all 0.15s; ${isEndorseActive ? 'background:#ffffff; color:#047857; font-weight:700; box-shadow:0 1px 3px rgba(0,0,0,0.25);' : 'background:transparent; color:rgba(255,255,255,0.85); font-weight:600;'}">
          <i class="fa-solid fa-stamp" style="${isEndorseActive ? 'color:#047857;' : 'color:rgba(255,255,255,0.7);'}"></i>
          <span>Adviser Endorsement</span>
          <span style="font-size:0.65rem; font-weight:700; padding:1px 5px; border-radius:4px; ${isEndorseActive ? 'background:#ecfdf5; color:#059669;' : 'background:rgba(255,255,255,0.18); color:#ffffff;'}">${endorseExt}</span>
        </button>
      `;
    }

    function renderActiveDocViewer() {
      const stage = document.getElementById('docsModalViewerStage');
      const btnOpen = document.getElementById('docsModalOpenExternal');
      const btnDown = document.getElementById('docsModalDownload');
      if (!stage || !CURRENT_DOC_AP) return;

      const isIntent = CURRENT_DOC_TAB === 'intent';
      const rawFile = isIntent ? CURRENT_DOC_AP.letter_intent : CURRENT_DOC_AP.letter_endorsement;
      const statementText = isIntent 
        ? (CURRENT_DOC_AP.motivation || (isRealDocFile(CURRENT_DOC_AP.letter_intent) ? '' : CURRENT_DOC_AP.letter_intent) || 'I hereby express my genuine motivation and commitment to actively contribute to the organization.')
        : (CURRENT_DOC_AP.review_notes || (isRealDocFile(CURRENT_DOC_AP.letter_endorsement) ? '' : CURRENT_DOC_AP.letter_endorsement) || 'Official recommendation by club faculty adviser endorsing the student for membership.');

      const docCategory = isIntent ? 'Letter of Intent' : 'Adviser Endorsement Letter';

      if (isRealDocFile(rawFile)) {
        const ext = getDocExt(rawFile);
        const encodedFile = encodeURIComponent(rawFile);
        const fileUrl = '../uploads/applications/' + encodedFile;

        // Configure header quick actions
        if (btnOpen) {
          btnOpen.href = fileUrl;
          btnOpen.style.display = 'inline-flex';
        }
        if (btnDown) {
          btnDown.href = fileUrl;
          btnDown.download = rawFile;
          btnDown.style.display = 'inline-flex';
        }

        // 1. PDF Document - Pure 100% full stage view
        if (ext === 'pdf') {
          stage.innerHTML = `
            <iframe src="${fileUrl}#toolbar=1&navpanes=0" style="width:100%; height:100%; border:none; display:block; background:#525659;" title="Student PDF Viewer"></iframe>
          `;
          return;
        }

        // 2. Image Document (PNG, JPG, JPEG, WEBP)
        if (['png', 'jpg', 'jpeg', 'webp'].includes(ext)) {
          stage.innerHTML = `
            <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; padding:16px; overflow:auto; background:#0f172a;">
              <img src="${fileUrl}" alt="Student Document" style="max-width:100%; max-height:100%; object-fit:contain; border-radius:6px; box-shadow:0 10px 30px rgba(0,0,0,0.5);" />
            </div>
          `;
          return;
        }

        // 3. Word Document (.doc, .docx)
        stage.innerHTML = `
          <div style="width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#f8fafc; padding:24px; overflow-y:auto;">
            <div style="background:#fff; border-radius:12px; padding:32px; border:1px solid #e2e8f0; box-shadow:0 4px 14px rgba(0,0,0,0.05); max-width:650px; width:100%; text-align:center;">
              <div style="width:52px; height:52px; border-radius:12px; background:#eff6ff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; font-size:1.6rem; margin-bottom:12px;">
                <i class="fa-solid fa-file-word"></i>
              </div>
              <h4 style="margin:0 0 6px 0; font-size:1rem; color:#0f172a; font-weight:700;">${escapeHtml(rawFile)}</h4>
              <p style="margin:0 0 18px 0; font-size:0.8rem; color:#64748b;">Microsoft Word Document • Student Attached File</p>
              <a href="${fileUrl}" download="${rawFile}" class="card-btn" style="background:#2563eb; color:#fff; text-decoration:none; display:inline-flex; align-items:center; gap:8px; padding:8px 18px; border-radius:8px; font-weight:600; font-size:0.85rem; box-shadow:0 2px 4px rgba(37,99,235,0.2);">
                <i class="fa-solid fa-download"></i> Download Word File
              </a>
              ${statementText ? `
                <div style="margin-top:22px; text-align:left; background:#f8fafc; border-radius:8px; padding:16px; border:1px solid #e2e8f0;">
                  <div style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.5px;">Accompanying Application Statement:</div>
                  <div style="font-size:0.88rem; line-height:1.6; color:#1e293b; white-space:pre-wrap;">${escapeHtml(statementText)}</div>
                </div>
              ` : ''}
            </div>
          </div>
        `;
        return;
      }

      // Hide header external/download buttons if no real file exists
      if (btnOpen) btnOpen.style.display = 'none';
      if (btnDown) btnDown.style.display = 'none';

      // 4. Fallback / Official Digital Application Letter Sheet
      stage.innerHTML = `
        <div style="width:100%; height:100%; display:flex; flex-direction:column; background:#f8fafc; overflow-y:auto; padding:24px;">
          <div style="background:#fff; border-radius:12px; padding:32px 38px; box-shadow:0 4px 14px rgba(0,0,0,0.06); border:1px solid #e2e8f0; max-width:740px; margin:auto; width:100%;">
            <div style="text-align:center; border-bottom:2px solid #0f172a; padding-bottom:14px; margin-bottom:18px;">
              <div style="font-size:0.72rem; font-weight:800; letter-spacing:1.5px; color:#64748b; text-transform:uppercase;">Bestlink College of the Philippines</div>
              <div style="font-size:1.05rem; font-weight:800; color:#0f172a; margin-top:2px;">OFFICE OF STUDENT AFFAIRS &amp; CO-CURRICULAR SERVICES</div>
              <div style="font-size:0.78rem; color:#475569; margin-top:2px; font-weight:600;">OFFICIAL APPLICATION ${escapeHtml(docCategory.toUpperCase())}</div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:#f8fafc; padding:12px 16px; border-radius:8px; border:1px solid #e2e8f0; font-size:0.8rem; margin-bottom:18px;">
              <div><span style="color:#64748b; font-weight:600;">Applicant:</span> <strong style="color:#0f172a;">${escapeHtml(CURRENT_DOC_AP.first_name + ' ' + CURRENT_DOC_AP.last_name)}</strong></div>
              <div><span style="color:#64748b; font-weight:600;">Student ID:</span> <span style="font-family:monospace; font-weight:700; color:#2563eb;">${escapeHtml(CURRENT_DOC_AP.student_id_no || 'N/A')}</span></div>
              <div><span style="color:#64748b; font-weight:600;">Program:</span> ${escapeHtml(CURRENT_DOC_AP.course)} (${escapeHtml(CURRENT_DOC_AP.year_level)})</div>
              <div><span style="color:#64748b; font-weight:600;">Target Club:</span> <strong>${escapeHtml(CURRENT_DOC_AP.club_code)} - ${escapeHtml(CURRENT_DOC_AP.club_name)}</strong></div>
            </div>
            <div style="font-size:0.88rem; line-height:1.7; color:#1e293b; white-space:pre-wrap; min-height:140px; padding:4px 2px;">
              ${escapeHtml(statementText)}
            </div>
            <div style="margin-top:26px; padding-top:14px; border-top:1px dashed #cbd5e1; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
              <div style="display:flex; align-items:center; gap:6px; font-size:0.75rem; color:#059669; font-weight:600;">
                <i class="fa-solid fa-circle-check"></i> Digitally Recorded via BCP Student Portal
              </div>
              <div style="font-family:monospace; font-size:0.72rem; color:#94a3b8;">
                REF: APP-${CURRENT_DOC_AP.id}
              </div>
            </div>
          </div>
        </div>
      `;
    }

    function renderDocsFooterActions() {
      const box = document.getElementById('docsModalActionBtns');
      const fileInfoBox = document.getElementById('docsModalFileInfo');
      if (!box || !CURRENT_DOC_AP) return;

      const ap = CURRENT_DOC_AP;

      // Left side: Student Profile shortcut
      if (fileInfoBox) {
        fileInfoBox.innerHTML = `
          <button type="button" class="card-btn btn-sm" style="background:#fff; color:#2563eb; border:1px solid #bfdbfe; font-size:0.78rem; font-weight:600; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:6px; cursor:pointer;" onclick="closeModal('applicationDocsModal'); reviewApplicant(CURRENT_DOC_AP);" title="Open Complete Applicant Profile">
            <i class="fa-regular fa-id-card"></i>
            <span>Applicant Details</span>
          </button>
        `;
      }

      let btns = '';

      if (ap.current_status === 'Pending' || ap.current_status === 'Returned') {
        if (CURRENT_USER_ROLE === 'student') {
          btns += `
            <span style="display:inline-flex; align-items:center; gap:6px; font-size:0.78rem; font-weight:700; color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe; padding:6px 14px; border-radius:6px;">
              <i class="fa-solid fa-hourglass-half"></i> Application Under Review
            </span>
          `;
        } else if (CURRENT_USER_ROLE === 'club_adviser') {
          if (ap.adviser_review !== 'Endorsed') {
            btns += `
              <button type="button" class="card-btn btn-sm" style="background:#fff; color:#d97706; border:1px solid #fed7aa; font-size:0.78rem; font-weight:600; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:6px; cursor:pointer;" onclick="closeModal('applicationDocsModal'); openReturnModal(${ap.id}, '${escapeHtml(ap.first_name + ' ' + ap.last_name)}')">
                <i class="fa-solid fa-rotate-left"></i> Return
              </button>
              <button type="button" class="card-btn btn-sm" style="background:#059669; color:#fff; border:none; font-size:0.78rem; font-weight:700; padding:6px 16px; border-radius:6px; box-shadow:0 1px 3px rgba(5,150,105,0.3); display:inline-flex; align-items:center; gap:6px; cursor:pointer;" onclick="openEndorseModal(CURRENT_DOC_AP)">
                <i class="fa-solid fa-stamp"></i> Endorse to SSC
              </button>
            `;
          } else {
            btns += `
              <span style="display:inline-flex; align-items:center; gap:6px; font-size:0.78rem; font-weight:700; color:#065f46; background:#d1fae5; border:1px solid #a7f3d0; padding:6px 12px; border-radius:6px;">
                <i class="fa-solid fa-check"></i> Endorsed &mdash; Awaiting SSC Approval
              </span>
            `;
          }
        } else {
          btns += `
            <button type="button" class="card-btn btn-sm" style="background:#fff; color:#dc2626; border:1px solid #fecaca; font-size:0.78rem; font-weight:600; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:6px; cursor:pointer;" onclick="handleApplicationFromDocs(${ap.id}, 'reject')">
              <i class="fa-solid fa-xmark"></i> Reject
            </button>
            <button type="button" class="card-btn btn-sm" style="background:#fff; color:#d97706; border:1px solid #fed7aa; font-size:0.78rem; font-weight:600; padding:6px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:6px; cursor:pointer;" onclick="openReturnFromDocs(${ap.id}, '${escapeHtml(ap.first_name + ' ' + ap.last_name)}')">
              <i class="fa-solid fa-rotate-left"></i> Return
            </button>
            <button type="button" class="card-btn btn-sm" style="background:#16a34a; color:#fff; border:none; font-size:0.78rem; font-weight:700; padding:6px 16px; border-radius:6px; box-shadow:0 1px 3px rgba(22,163,74,0.3); display:inline-flex; align-items:center; gap:6px; cursor:pointer;" onclick="handleApplicationFromDocs(${ap.id}, 'approve')" title="Approve applicant into ${escapeHtml(ap.club_code)}">
              <i class="fa-solid fa-check"></i> Approve for ${escapeHtml(ap.club_code)}
            </button>
          `;
        }
      } else if (ap.current_status === 'Approved') {
        btns += `
          <span style="display:inline-flex; align-items:center; gap:5px; font-size:0.78rem; font-weight:700; color:#16a34a; background:#dcfce7; padding:4px 10px; border-radius:6px;">
            <i class="fa-solid fa-circle-check"></i> Approved Member
          </span>
        `;
      } else if (ap.current_status === 'Rejected') {
        btns += `
          <span style="display:inline-flex; align-items:center; gap:5px; font-size:0.78rem; font-weight:700; color:#dc2626; background:#fee2e2; padding:4px 10px; border-radius:6px;">
            <i class="fa-solid fa-ban"></i> Application Rejected
          </span>
        `;
      }

      box.innerHTML = btns;
    }

    function handleApplicationFromDocs(id, action) {
      closeModal('applicationDocsModal');
      handleApplication(id, action);
    }

    function openReturnFromDocs(id, name) {
      closeModal('applicationDocsModal');
      openReturnModal(id, name);
    }

    function openEndorseModal(ap) {
      if (!ap) return;
      closeModal('applicantReviewModal');
      closeModal('applicationDocsModal');
      const idEl = document.getElementById('endorseAppId');
      const nameEl = document.getElementById('endorseStudentName');
      const noEl = document.getElementById('endorseStudentNo');
      const orgEl = document.getElementById('endorseOrgName');
      const progEl = document.getElementById('endorseProgram');
      const fileEl = document.getElementById('endorseLetterFile');
      const notesEl = document.getElementById('endorseNotes');

      if (idEl) idEl.value = ap.id;
      if (nameEl) nameEl.textContent = (ap.first_name + ' ' + ap.last_name);
      if (noEl) noEl.textContent = ap.student_id_no || 'N/A';
      if (orgEl) orgEl.textContent = (ap.club_code ? ap.club_code + ' - ' : '') + (ap.club_name || '');
      if (progEl) progEl.textContent = (ap.course || 'BSIT') + ' (' + (ap.year_level || '1st Year') + ')';
      if (fileEl) fileEl.value = '';
      if (notesEl) {
        notesEl.value = (ap.review_notes && !isRealDocFile(ap.review_notes)) ? ap.review_notes : 'Applicant is recommended and meets good standing criteria. Endorsed for official membership.';
      }

      openModal('endorseApplicantModal');
    }

    async function submitEndorsement(e) {
      if (e) e.preventDefault();
      const id = document.getElementById('endorseAppId')?.value;
      const notes = document.getElementById('endorseNotes')?.value?.trim();
      const fileInput = document.getElementById('endorseLetterFile');

      if (!id) return;
      if (!notes && (!fileInput?.files || fileInput.files.length === 0)) {
        window.alert('Please provide either an endorsement statement or attach the Letter of Endorsement.', 'warning');
        return;
      }

      const confirmed = await window.showConfirmModal(
        'Endorse Applicant to SSC?',
        'Do you want to submit this endorsement with Letter of Endorsement and forward this application to SSC for final approval?',
        { type: 'decision', confirmText: 'Yes, Endorse to SSC' }
      );
      if (!confirmed) return;

      const fd = new FormData();
      fd.set('action', 'endorse');
      fd.set('id', id);
      fd.set('review_notes', notes || 'Endorsed by Adviser with official Letter of Endorsement.');
      if (fileInput?.files && fileInput.files[0]) {
        fd.append('letter_endorsement', fileInput.files[0]);
      }
      if (window.CSRF_TOKEN) fd.set('csrf_token', window.CSRF_TOKEN);

      fetch('../shared/roster_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showAlert(data.message, 'success');
            closeModal('endorseApplicantModal');
            setTimeout(() => { window.location.reload(); }, 600);
          } else {
            window.alert(data.message, 'error');
          }
        })
        .catch(() => window.alert('Network error. Please try again.', 'error'));
    }

    // Initialize Pagination with 5 records per page and run initial filters
    document.addEventListener('DOMContentLoaded', function() {
      if (window.initTablePagination) {
        const qTbl = document.getElementById('queueTable');
        if (qTbl) window.initTablePagination(qTbl, { pageSize: 5 });

        const mTbl = document.getElementById('masterRosterTable');
        if (mTbl) window.initTablePagination(mTbl, { pageSize: 5 });
      }

      if (typeof filterMasterRoster === 'function') {
        filterMasterRoster();
      }

      const qOrg = document.getElementById('filterOrg');
      if (qOrg && qOrg.value) {
        filterReviewQueue();
      }
    });
  </script>
  <script src="../js/table-pagination.js"></script>
</body>

</html>
