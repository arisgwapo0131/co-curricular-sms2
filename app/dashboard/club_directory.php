<?php
// ============================================================
//  CLUB_DIRECTORY.PHP  (dashboard/)
//  BCP Co-Curricular System — Accredited Organizations Directory
//  "Apply Now" flow: Org Profile -> Application Form -> Multi-Tier Workflow
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_auth();
require_permission('organization.view');

$sess_first = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id = (int) ($_SESSION['user_id'] ?? 0);

// Restrict users who only manage their own club rather than browsing directory
if (can('organization.manage.own') && !can('organization.review.all')) {
  header('Location: ../dashboard/dashboard.php');
  exit;
}

$can_apply = can('organization.apply');

// Fetch student profile details from DB if logged in as student
$student_info = null;
if ($can_apply) {
  $stmt = $conn->prepare("SELECT s.student_number, s.birthday, s.course, s.year_level, s.section, s.phone, u.first_name, u.last_name, u.email 
                          FROM users u 
                          LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name)) 
                          WHERE u.id = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $s_res = $stmt->get_result();
    if ($s_res && $s_res->num_rows > 0) {
      $student_info = $s_res->fetch_assoc();
    }
    $stmt->close();
  }
}

// -- Fetch real club IDs from DB, indexed by code --
$db_clubs = [];
$club_res = $conn->query("SELECT id, code FROM clubs WHERE status='Active' AND deleted_at IS NULL");
if ($club_res) {
  while ($rc = $club_res->fetch_assoc()) {
    $db_clubs[strtoupper($rc['code'])] = (int) $rc['id'];
    $clean_c = preg_replace('/[^A-Z0-9]/', '', strtoupper($rc['code']));
    $db_clubs[$clean_c] = (int) $rc['id'];
  }
}

// -- Fetch student's current club memberships and applications --
$student_applications = []; // [club_id => status]
$student_org_statuses = []; // [acronym => status]
if ($can_apply && !empty($user_id)) {
  $st_app_res = $conn->query("SELECT club_id, status FROM club_memberships WHERE user_id = " . (int)$user_id);
  if ($st_app_res) {
    while ($row = $st_app_res->fetch_assoc()) {
      $student_applications[(int)$row['club_id']] = $row['status'];
    }
  }
  $st_app_res2 = $conn->query("SELECT club_id, status FROM club_applications WHERE user_id = " . (int)$user_id . " ORDER BY id ASC");
  if ($st_app_res2) {
    while ($row = $st_app_res2->fetch_assoc()) {
      $cid = (int)$row['club_id'];
      if (!isset($student_applications[$cid])) {
        $student_applications[$cid] = $row['status'];
      }
    }
  }
}


// ============================================================
// DYNAMIC DATABASE DATA FETCHING (NO HARDCODED ARRAYS)
// ============================================================

// 1. Fetch real student officers by club from club_memberships
$officers_by_club = [];
$officers_res = $conn->query("
    SELECT cm.club_id, cm.role, u.first_name, u.last_name, u.profile_pic
    FROM club_memberships cm
    JOIN users u ON u.id = cm.user_id
    WHERE cm.status = 'Active' AND cm.role NOT IN ('Member', 'Adviser', 'club_adviser')
    ORDER BY cm.club_id, FIELD(cm.role, 'President', 'Vice President', 'VP Internal', 'VP External', 'Secretary', 'Treasurer', 'Auditor', 'Public Relations Officer', 'PRO', 'Creative Lead', 'Editor-in-Chief', 'Officer') ASC
");
if ($officers_res) {
    while ($off = $officers_res->fetch_assoc()) {
        $cid = (int)$off['club_id'];
        $officers_by_club[$cid][] = [
            'name' => trim($off['first_name'] . ' ' . $off['last_name']),
            'pos'  => $off['role']
        ];
    }
}

// 2. Fetch real verified achievements by club from achievements table
$achievements_by_club = [];
$ach_res = $conn->query("
    SELECT club_id, title, competition, award_date 
    FROM achievements 
    WHERE status = 'Verified' 
    ORDER BY award_date DESC
");
if ($ach_res) {
    while ($ach = $ach_res->fetch_assoc()) {
        $cid = (int)$ach['club_id'];
        $entry = $ach['title'];
        if (!empty($ach['competition'])) {
            $entry .= ' (' . $ach['competition'] . ')';
        }
        $achievements_by_club[$cid][] = $entry;
    }
}

// 3. Fetch all active clubs from database
$academic_orgs = [];
$talent_subcategories = [
    'Department Based Talent Group' => [],
    'Talent Center' => []
];
$independent_orgs = [];
$all_orgs = [];

$clubs_query = $conn->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Active') as active_count
    FROM clubs c 
    WHERE c.deleted_at IS NULL 
    ORDER BY c.category ASC, c.name ASC
");

if ($clubs_query) {
    while ($row = $clubs_query->fetch_assoc()) {
        $cid = (int)$row['id'];
        $acronym = $row['code'];
        $clean_a = preg_replace('/[^A-Z0-9]/', '', strtoupper($acronym));
        $db_clubs[strtoupper($acronym)] = $cid;
        $db_clubs[$clean_a] = $cid;

        $desc = !empty($row['description']) 
            ? $row['description'] 
            : 'Official accredited student organization under the BCP Supreme Student Council (SSC), dedicated to student empowerment, community service, and academic excellence.';
        
        $adviser = !empty($row['adviser_name']) ? $row['adviser_name'] : 'Faculty Adviser';

        // Officers: Use real student officers from DB; if none yet, include Adviser
        $officers = $officers_by_club[$cid] ?? [];
        if (empty($officers)) {
            $officers = [
                ['name' => $adviser, 'pos' => 'Faculty Adviser']
            ];
        }

        // Achievements: Use verified records from DB; fallback to default accreditation
        $achievements = $achievements_by_club[$cid] ?? [
            'BCP Accredited Organization AY 2025-2026',
            'Supreme Student Council Charter Recognition'
        ];

        $org_item = [
            'club_id'   => $cid,
            'acronym'   => $acronym,
            'name'      => $row['name'],
            'category'  => $row['category'],
            'sub_category' => $row['sub_category'] ?? '',
            'adviser'   => $adviser,
            'profile'   => [
                'desc'         => $desc,
                'adviser'      => $adviser,
                'achievements' => $achievements,
                'officers'     => $officers
            ]
        ];

        // Classification into sections
        $cat = strtolower(trim($row['category']));
        $sub = trim($row['sub_category'] ?? '');
        $is_active_club = ($row['status'] === 'Active');

        if ($cat === 'academic') {
            if ($is_active_club) {
                $academic_orgs[] = $org_item;
            }
            $all_orgs[$acronym] = [
                'name'     => $row['name'],
                'category' => 'Academic Organization',
                'accent'   => '#2563eb',
                'color'    => '#1a3a8c',
                'profile'  => $org_item['profile'],
                'club_id'  => $cid
            ];
        } elseif (in_array($cat, ['cultural', 'sports']) || in_array($sub, ['Department Based Talent Group', 'Talent Center'])) {
            $sub_label = ($sub === 'Department Based Talent Group') ? 'Department Based Talent Group' : 'Talent Center';
            if ($is_active_club) {
                $talent_subcategories[$sub_label][] = $org_item;
            }
            $all_orgs[$acronym] = [
                'name'     => $row['name'],
                'category' => $sub_label,
                'accent'   => '#2563eb',
                'color'    => '#1a3a8c',
                'profile'  => $org_item['profile'],
                'club_id'  => $cid
            ];
        } else {
            // Advocacy / Independent / Others
            if ($is_active_club) {
                $independent_orgs[] = $org_item;
            }
            $all_orgs[$acronym] = [
                'name'     => $row['name'],
                'category' => 'Independent Organization',
                'accent'   => '#2563eb',
                'color'    => '#1a3a8c',
                'profile'  => $org_item['profile'],
                'club_id'  => $cid
            ];
        }

        // Also index by clean and upper acronym so lookups never fail
        if (!empty($clean_a) && !isset($all_orgs[$clean_a])) {
            $all_orgs[$clean_a] = $all_orgs[$acronym];
        }
        $upper_a = strtoupper($acronym);
        if (!isset($all_orgs[$upper_a])) {
            $all_orgs[$upper_a] = $all_orgs[$acronym];
        }
    }
}

$talent_count = array_sum(array_map('count', $talent_subcategories));
$total_count = count($academic_orgs) + $talent_count + count($independent_orgs);

// Map student status by acronym
foreach ($all_orgs as $acr => $o) {
  $cid = (int)$o['club_id'];
  if ($cid > 0 && isset($student_applications[$cid])) {
    $student_org_statuses[$acr] = $student_applications[$cid];
  }
}

// Helper to render organization card action button based on real application/membership status
if (!function_exists('renderOrgCardButton')) {
function renderOrgCardButton(array $org, bool $can_apply, array $db_clubs, array $student_applications): string {
  if (!$can_apply) {
    return '<button class="org-card-qr-btn" style="background:#f1f5f9;color:#64748b;border-color:#e2e8f0;" onclick="openOrgProfile(\'' . htmlspecialchars($org['acronym'], ENT_QUOTES) . '\')"><i class="fa-solid fa-eye"></i> View Profile</button>';
  }

  $clean_a = preg_replace('/[^A-Z0-9]/', '', strtoupper($org['acronym']));
  $cid = $db_clubs[strtoupper($org['acronym'])] ?? $db_clubs[$clean_a] ?? 0;
  $status = ($cid > 0 && isset($student_applications[$cid])) ? $student_applications[$cid] : null;

  if ($status === 'Pending') {
    return '<button class="org-card-qr-btn org-status-pending" data-org-acronym="' . htmlspecialchars($org['acronym'], ENT_QUOTES) . '" style="background:#fffbeb; color:#b45309; border-color:#fde68a; cursor:default;" disabled title="Your application has been submitted and is awaiting SSC review and approval."><i class="fa-solid fa-clock"></i> Waiting for Approval</button>';
  }
  if ($status === 'Active') {
    return '<button class="org-card-qr-btn org-status-member" data-org-acronym="' . htmlspecialchars($org['acronym'], ENT_QUOTES) . '" style="background:#f0fdf4; color:#166534; border-color:#bbf7d0; cursor:default;" disabled title="You are an active member of this organization."><i class="fa-solid fa-circle-check"></i> Member</button>';
  }

  // If status is 'Rejected' or not applied, return to original 'Apply Now' state
  return '<button class="org-card-qr-btn" data-org-acronym="' . htmlspecialchars($org['acronym'], ENT_QUOTES) . '" onclick="openAppForm(\'' . htmlspecialchars($org['acronym'], ENT_QUOTES) . '\')"><i class="fa-solid fa-user-plus"></i> Apply Now</button>';
}
}

// Governance queries for SSC / Admin
$is_gov_role = in_array($sess_role, ['ssc', 'admin']);
$gov_clubs = [];
$active_orgs_count = 0;
$pending_charters_count = 0;
$total_pending_charters = 0;
$suspended_orgs_count = 0;
$cat_counts = [];
$no_adviser_count = 0;
$compliance_warning_count = 0;
$faculty_advisers = [];

if ($is_gov_role) {
    $fa_res = $conn->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'club_adviser' ORDER BY last_name, first_name");
    if ($fa_res) {
        while ($fa = $fa_res->fetch_assoc()) {
            $faculty_advisers[] = $fa;
        }
    }
    $active_orgs_count = (int) ($conn->query("SELECT COUNT(*) FROM clubs WHERE status = 'Active' AND deleted_at IS NULL")->fetch_row()[0] ?? 0);
    $pending_charters_count = (int) ($conn->query("SELECT COUNT(*) FROM clubs WHERE status = 'Pending Charter' AND deleted_at IS NULL")->fetch_row()[0] ?? 0);
    $pending_charter_apps = (int) ($conn->query("SELECT COUNT(*) FROM club_applications WHERE status = 'Pending'")->fetch_row()[0] ?? 0);
    $total_pending_charters = $pending_charters_count + $pending_charter_apps;
    $suspended_orgs_count = (int) ($conn->query("SELECT COUNT(*) FROM clubs WHERE status = 'Suspended' AND deleted_at IS NULL")->fetch_row()[0] ?? 0);

    $cats_query = $conn->query("SELECT category, COUNT(*) as cnt FROM clubs WHERE deleted_at IS NULL GROUP BY category");
    if ($cats_query) {
        while ($cr = $cats_query->fetch_assoc()) {
            $cat_counts[$cr['category']] = (int)$cr['cnt'];
        }
    }

    $no_adviser_count = (int) ($conn->query("SELECT COUNT(*) FROM clubs WHERE (adviser_name IS NULL OR adviser_name = '' OR adviser_name = 'Unassigned') AND deleted_at IS NULL")->fetch_row()[0] ?? 0);
    $compliance_warning_count = $no_adviser_count + $suspended_orgs_count + $pending_charters_count;

    $gov_clubs_query = $conn->query("
        SELECT c.*,
               (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Active') as member_count,
               (SELECT CONCAT(e.title, ' (', DATE_FORMAT(e.event_date, '%b %d, %Y'), ')') FROM events e WHERE e.club_id = c.id AND e.status IN ('Approved', 'Completed') ORDER BY e.event_date DESC LIMIT 1) as last_activity
        FROM clubs c
        WHERE c.deleted_at IS NULL
        ORDER BY c.status ASC, c.name ASC
    ");
    if ($gov_clubs_query) {
        while ($g = $gov_clubs_query->fetch_assoc()) {
            // Compliance
            if ($g['status'] === 'Suspended') {
                $g['compliance'] = 'Non-Compliant';
                $g['compliance_badge'] = '<span class="badge-danger" style="background:#fee2e2; color:#b91c1c; font-weight:700; padding:3px 8px; border-radius:12px; font-size:0.75rem;"><i class="fa-solid fa-circle-xmark"></i> Non-Compliant</span>';
            } elseif (empty($g['adviser_name']) || $g['adviser_name'] === 'Unassigned') {
                $g['compliance'] = 'Warning';
                $g['compliance_badge'] = '<span class="badge-warning" style="background:#fef3c7; color:#b45309; font-weight:700; padding:3px 8px; border-radius:12px; font-size:0.75rem;"><i class="fa-solid fa-triangle-exclamation"></i> Warning (No Adviser)</span>';
            } elseif ($g['status'] === 'Pending Charter') {
                $g['compliance'] = 'Pending Review';
                $g['compliance_badge'] = '<span class="badge-info" style="background:#e0f2fe; color:#0369a1; font-weight:700; padding:3px 8px; border-radius:12px; font-size:0.75rem;"><i class="fa-solid fa-clock"></i> Under Review</span>';
            } else {
                $g['compliance'] = 'Complete';
                $g['compliance_badge'] = '<span class="badge-active" style="background:#dcfce7; color:#15803d; font-weight:700; padding:3px 8px; border-radius:12px; font-size:0.75rem;"><i class="fa-solid fa-circle-check"></i> Complete</span>';
            }

            // Charter Status
            if ($g['status'] === 'Active') {
                $g['charter_status'] = 'Accredited';
                $g['charter_badge'] = '<span class="badge-active" style="background:#dbeafe; color:#1e40af; font-weight:600; padding:3px 8px; border-radius:12px; font-size:0.75rem;"><i class="fa-solid fa-stamp"></i> Accredited</span>';
            } elseif ($g['status'] === 'Pending Charter') {
                $g['charter_status'] = 'Pending Review';
                $g['charter_badge'] = '<span class="badge-warning" style="background:#fef3c7; color:#b45309; font-weight:600; padding:3px 8px; border-radius:12px; font-size:0.75rem;"><i class="fa-solid fa-hourglass-half"></i> Under Review</span>';
            } else {
                $g['charter_status'] = 'Suspended';
                $g['charter_badge'] = '<span class="badge-danger" style="background:#fee2e2; color:#b91c1c; font-weight:600; padding:3px 8px; border-radius:12px; font-size:0.75rem;"><i class="fa-solid fa-ban"></i> Suspended</span>';
            }

            $gov_clubs[] = $g;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Organization Directory – BCP Co-Curricular Portal</title>
  <meta name="csrf-token" content="<?= csrf_token() ?>" />
  <link rel="stylesheet" href="../css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../css/dashboard.css'); ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>

  <style>
    /* ── Governance KPI Summary Grid & Cards Alignment ── */
    .gov-summary-grid {
      display: grid !important;
      grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
      gap: 14px !important;
      margin-bottom: 22px !important;
      align-items: stretch !important;
    }

    /* Base Desktop Styles */
    .gov-kpi-tile {
      margin: 0 !important;
      padding: 16px 18px !important;
      background: #ffffff !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 12px !important;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05) !important;
      display: flex !important;
      flex-direction: column !important;
      justify-content: space-between !important;
      height: 100% !important;
      box-sizing: border-box !important;
      transition: transform 0.15s ease, box-shadow 0.15s ease !important;
    }

    .gov-kpi-tile:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
    }

    .gov-kpi-header {
      display: flex !important;
      justify-content: space-between !important;
      align-items: flex-start !important;
      gap: 8px !important;
      min-height: 38px !important;
    }

    .gov-kpi-title {
      font-size: 0.72rem !important;
      font-weight: 800 !important;
      color: #475569 !important;
      text-transform: uppercase !important;
      letter-spacing: 0.5px !important;
      line-height: 1.25 !important;
    }

    .gov-kpi-icon {
      width: 32px !important;
      height: 32px !important;
      min-width: 32px !important;
      border-radius: 8px !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      font-size: 0.95rem !important;
    }

    .gov-kpi-body {
      min-height: 48px !important;
      display: flex !important;
      align-items: center !important;
      margin: 10px 0 8px !important;
    }

    .gov-kpi-value {
      font-size: 1.8rem !important;
      font-weight: 800 !important;
      color: #0f172a !important;
      line-height: 1 !important;
    }

    .gov-kpi-desc {
      font-size: 0.75rem !important;
      color: #64748b !important;
      line-height: 1.35 !important;
      margin-top: auto !important;
    }

    .gov-kpi-cat-grid {
      display: grid !important;
      grid-template-columns: 1fr 1fr !important;
      gap: 5px !important;
      width: 100% !important;
    }

    .gov-cat-item {
      display: flex !important;
      align-items: center !important;
      justify-content: space-between !important;
      background: #f8fafc !important;
      border: 1px solid #ede9fe !important;
      border-radius: 6px !important;
      padding: 3px 6px !important;
      min-width: 0 !important;
      transition: background 0.15s ease !important;
    }

    .gov-cat-item:hover {
      background: #f5f3ff !important;
    }

    .gov-cat-name {
      font-size: 0.68rem !important;
      font-weight: 600 !important;
      color: #334155 !important;
      white-space: nowrap !important;
      overflow: hidden !important;
      text-overflow: ellipsis !important;
      margin-right: 4px !important;
    }

    .gov-cat-pill {
      font-size: 0.68rem !important;
      font-weight: 800 !important;
      background: #ede9fe !important;
      color: #7c3aed !important;
      padding: 1px 6px !important;
      border-radius: 10px !important;
      line-height: 1.25 !important;
      flex-shrink: 0 !important;
    }

    /* Responsive adjustments for KPI summary grid & compact mobile tiles */
    @media (max-width: 1399px) {
      .gov-summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
      }
    }

    @media (max-width: 768px) {
      .gov-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px !important;
        margin-bottom: 14px !important;
      }
      .gov-kpi-tile {
        padding: 9px 11px !important;
        border-radius: 9px !important;
      }
      .gov-kpi-header {
        min-height: unset !important;
        gap: 6px !important;
      }
      .gov-kpi-title {
        font-size: 0.65rem !important;
        letter-spacing: 0.3px !important;
      }
      .gov-kpi-icon {
        width: 24px !important;
        height: 24px !important;
        min-width: 24px !important;
        font-size: 0.75rem !important;
        border-radius: 6px !important;
      }
      .gov-kpi-body {
        min-height: unset !important;
        margin: 3px 0 2px !important;
      }
      .gov-kpi-value {
        font-size: 1.3rem !important;
      }
      .gov-kpi-desc {
        font-size: 0.65rem !important;
        line-height: 1.25 !important;
      }
      .gov-kpi-cat-grid {
        gap: 3px !important;
      }
      .gov-cat-item {
        padding: 2px 4px !important;
        border-radius: 4px !important;
      }
      .gov-cat-name {
        font-size: 0.58rem !important;
        margin-right: 2px !important;
      }
      .gov-cat-pill {
        font-size: 0.58rem !important;
        padding: 1px 4px !important;
        border-radius: 8px !important;
      }
    }

    @media (max-width: 480px) {
      .gov-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 6px !important;
      }
      .gov-kpi-tile {
        padding: 8px 10px !important;
      }
      .gov-kpi-value {
        font-size: 1.22rem !important;
      }
    }

    @media (max-width: 340px) {
      .gov-summary-grid {
        grid-template-columns: 1fr !important;
      }
    }

    /* ── Governance Registry Table Formatting & Cut-off Prevention ── */
    #govRegistrySection .table-wrap {
      overflow-x: auto !important;
      position: relative !important;
      margin-bottom: 0 !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 10px !important;
      scrollbar-width: thin !important;
      scrollbar-color: #cbd5e1 #f8fafc !important;
    }

    #orgGovernanceTable {
      width: 100% !important;
      min-width: 980px !important;
      border-collapse: separate !important;
      border-spacing: 0 !important;
    }

    #orgGovernanceTable th {
      background: #f8fafc !important;
      padding: 10px 10px !important;
      font-size: 0.74rem !important;
      font-weight: 800 !important;
      text-transform: uppercase !important;
      letter-spacing: 0.4px !important;
      color: #475569 !important;
      border-bottom: 2px solid #e2e8f0 !important;
      white-space: nowrap !important;
    }

    #orgGovernanceTable td {
      padding: 10px 10px !important;
      font-size: 0.82rem !important;
      vertical-align: middle !important;
      border-bottom: 1px solid #f1f5f9 !important;
      background: #ffffff !important;
    }

    #orgGovernanceTable tr:hover td {
      background: #f8fafc !important;
    }

    #orgGovernanceTable td strong.org-table-name {
      font-size: 0.82rem !important;
      line-height: 1.3 !important;
      display: block !important;
      max-width: 200px !important;
      word-break: normal !important;
    }

    /* Action Column Styling: Seamless, Flat, and Centered (Eliminates artificial embossing/shadows) */
    #orgGovernanceTable th.gov-actions-head,
    #orgGovernanceTable td.gov-actions-col {
      position: static !important;
      border-left: none !important;
      border-right: none !important;
      box-shadow: none !important;
      text-align: center !important;
      white-space: nowrap !important;
      padding: 10px 14px !important;
      min-width: 185px !important;
      width: 185px !important;
      box-sizing: border-box !important;
    }

    #orgGovernanceTable th.gov-actions-head {
      background: #f8fafc !important;
      color: #475569 !important;
      border-bottom: 2px solid #e2e8f0 !important;
      text-align: center !important;
    }

    #orgGovernanceTable td.gov-actions-col {
      background: #ffffff !important;
      border-bottom: 1px solid #f1f5f9 !important;
    }

    #orgGovernanceTable tr:hover td.gov-actions-col {
      background: #f8fafc !important;
    }

    #orgGovernanceTable .actions-group {
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 8px !important;
      flex-wrap: nowrap !important;
      width: 100% !important;
      margin: 0 auto !important;
    }

    /* Common Button Styling: clean, flat, consistent sizing without 3D emboss/drop-shadows */
    #orgGovernanceTable .actions-group .card-btn {
      padding: 6px 12px !important;
      font-size: 0.8rem !important;
      font-weight: 600 !important;
      border-radius: 6px !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 6px !important;
      white-space: nowrap !important;
      box-shadow: none !important;
      height: 32px !important;
      min-height: 32px !important;
      min-width: 82px !important;
      cursor: pointer !important;
      transition: background 0.15s ease, border-color 0.15s ease, opacity 0.15s ease !important;
      flex-shrink: 0 !important;
      text-decoration: none !important;
      box-sizing: border-box !important;
    }

    #orgGovernanceTable .actions-group .card-btn:hover {
      transform: none !important;
      box-shadow: none !important;
      opacity: 0.92 !important;
    }

    /* Mobile / Responsive Card View (width <= 768px): clean centered full-width actions */
    @media (max-width: 768px) {
      #orgGovernanceTable td.gov-actions-col {
        position: static !important;
        width: 100% !important;
        min-width: 0 !important;
        border-left: none !important;
        box-shadow: none !important;
        padding: 12px 0 4px !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        box-sizing: border-box !important;
      }

      #orgGovernanceTable td.gov-actions-col::before {
        display: block !important;
        text-align: center !important;
        margin-bottom: 8px !important;
        font-size: 0.72rem !important;
        font-weight: 800 !important;
        color: #94a3b8 !important;
        letter-spacing: 0.5px !important;
        width: 100% !important;
      }

      #orgGovernanceTable .actions-group {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 10px !important;
        width: 100% !important;
        margin: 0 auto !important;
      }

      #orgGovernanceTable .actions-group .card-btn {
        flex: 1 1 0 !important;
        max-width: 160px !important;
        min-height: 36px !important;
        font-size: 0.84rem !important;
        padding: 8px 16px !important;
      }
    }

    /* -- Status Badges for Org Cards & Profile Modal -- */
    .org-card-qr-btn.org-status-pending {
      background: #fffbeb !important;
      border-color: #fde68a !important;
      color: #b45309 !important;
      cursor: default !important;
      box-shadow: none !important;
    }
    .org-card-qr-btn.org-status-pending:hover {
      background: #fffbeb !important;
      border-color: #fde68a !important;
      color: #b45309 !important;
    }
    .org-card-qr-btn.org-status-member {
      background: #f0fdf4 !important;
      border-color: #bbf7d0 !important;
      color: #166534 !important;
      cursor: default !important;
      box-shadow: none !important;
    }
    .org-card-qr-btn.org-status-member:hover {
      background: #f0fdf4 !important;
      border-color: #bbf7d0 !important;
      color: #166534 !important;
    }
    .opm-apply-cta.is-pending {
      background: #fef3c7 !important;
      color: #92400e !important;
      border: 1px solid #fde68a !important;
      box-shadow: none !important;
      cursor: default !important;
    }
    .opm-apply-cta.is-member {
      background: #dcfce7 !important;
      color: #166534 !important;
      border: 1px solid #bbf7d0 !important;
      box-shadow: none !important;
      cursor: default !important;
    }

    /* -- Category Filter Pills -- */
    .cat-filter-pill {
      background: #f1f5f9;
      border: 1.5px solid #e2e8f0;
      color: #64748b;
      border-radius: 20px;
      padding: 6px 16px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.15s;
    }

    .cat-filter-pill:hover {
      border-color: #2563eb;
      color: #2563eb;
      background: #eff6ff;
    }

    .cat-filter-pill.active {
      background: #1a3a8c;
      color: #fff;
      border-color: transparent;
    }

    /* -- Directory Search Box -- */
    .org-search-box {
      position: relative;
      display: inline-flex;
      align-items: center;
      min-width: 260px;
      flex: 1;
      max-width: 380px;
    }

    .org-search-box input {
      width: 100%;
      background: #ffffff;
      border: 1.5px solid #cbd5e1;
      border-radius: 20px;
      padding: 7px 34px 7px 36px;
      font-size: 0.82rem;
      color: #1e293b;
      font-weight: 500;
      transition: all 0.2s ease;
      outline: none;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    }

    .org-search-box input:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .org-search-box .org-search-icon {
      position: absolute;
      left: 13px;
      font-size: 0.82rem;
      color: #94a3b8;
      pointer-events: none;
    }

    .org-search-box .org-search-clear {
      position: absolute;
      right: 8px;
      background: none;
      border: none;
      color: #94a3b8;
      font-size: 0.82rem;
      cursor: pointer;
      padding: 4px 6px;
      border-radius: 50%;
      display: none;
      align-items: center;
      justify-content: center;
      transition: color 0.15s;
    }

    .org-search-box .org-search-clear:hover {
      color: #ef4444;
    }

    /* -- No Results State -- */
    .org-no-results {
      display: none;
      text-align: center;
      padding: 40px 20px;
      background: #ffffff;
      border-radius: 16px;
      border: 1.5px dashed #cbd5e1;
      margin-top: 15px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }

    .org-no-results > i,
    .org-no-results-icon {
      font-size: 2.4rem !important;
      color: #94a3b8;
      margin-bottom: 12px;
      display: inline-block;
    }

    .org-no-results h3 {
      font-size: 1.1rem;
      font-weight: 800;
      color: #1e293b;
      margin: 0 0 6px;
    }

    .org-no-results p {
      font-size: 0.85rem;
      color: #64748b;
      margin: 0 0 16px;
    }

    .org-reset-btn {
      border-color: #2563eb !important;
      color: #2563eb !important;
      background: #eff6ff !important;
      padding: 6px 16px !important;
      font-size: 0.8rem !important;
      gap: 6px !important;
    }

    .org-reset-btn i {
      font-size: 0.8rem !important;
      margin-bottom: 0 !important;
      color: inherit !important;
    }

    /* -- Org Profile Modal -- */
    .org-profile-overlay {
      position: fixed;
      inset: 0;
      background: rgba(10, 12, 30, 0.65);
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .org-profile-overlay.active {
      display: flex;
      animation: fadeIn 0.2s ease;
    }

    @keyframes fadeIn {
      from {
        opacity: 0
      }

      to {
        opacity: 1
      }
    }

    .org-profile-modal {
      background: #fff;
      border-radius: 20px;
      width: 100%;
      max-width: 620px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 24px 64px rgba(0, 0, 0, 0.28);
      display: flex;
      flex-direction: column;
      animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes slideUp {
      from {
        transform: translateY(24px);
        opacity: 0
      }

      to {
        transform: translateY(0);
        opacity: 1
      }
    }

    .opm-hero {
      padding: 28px 26px 22px;
      position: relative;
      overflow: hidden;
      border-radius: 20px 20px 0 0;
    }

    .opm-hero::after {
      content: '';
      position: absolute;
      top: -50px;
      right: -50px;
      width: 180px;
      height: 180px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.08);
      pointer-events: none;
    }

    .opm-close {
      position: absolute;
      top: 14px;
      right: 16px;
      background: rgba(255, 255, 255, 0.18);
      border: none;
      color: #fff;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      font-size: 1.1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.15s;
      z-index: 2;
    }

    .opm-close:hover {
      background: rgba(255, 255, 255, 0.3);
    }

    .opm-acronym-badge {
      display: inline-block;
      background: rgba(255, 255, 255, 0.18);
      border: 1px solid rgba(255, 255, 255, 0.28);
      color: #fff;
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      padding: 4px 12px;
      border-radius: 14px;
      margin-bottom: 10px;
    }

    .opm-title {
      font-size: 1.25rem;
      font-weight: 900;
      color: #fff;
      margin: 0 0 5px;
      line-height: 1.25;
    }

    .opm-category {
      font-size: 0.78rem;
      color: rgba(255, 255, 255, 0.72);
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .opm-body {
      padding: 22px 26px 8px;
    }

    .opm-section-label {
      font-size: 0.68rem;
      font-weight: 800;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .opm-section-label::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #f0f2f5;
    }

    .opm-description {
      font-size: 0.85rem;
      color: #374151;
      line-height: 1.65;
      background: #f8fafc;
      border-radius: 10px;
      padding: 14px 16px;
      border: 1px solid #e2e8f0;
      margin-bottom: 18px;
    }

    /* Achievements list */
    .opm-achievements {
      list-style: none;
      margin: 0 0 18px;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 7px;
    }

    .opm-achievements li {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.82rem;
      color: #1a1a2e;
      font-weight: 600;
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-radius: 8px;
      padding: 8px 12px;
    }

    .opm-achievements li i {
      color: #16a34a;
      flex-shrink: 0;
    }

    /* Officers grid */
    .opm-officers-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
      gap: 10px;
      margin-bottom: 18px;
    }

    .opm-officer-card {
      text-align: center;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 12px 10px;
    }

    .opm-officer-avatar {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: #1a3a8c;
      color: #fff;
      font-size: 1rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 8px;
    }

    .opm-officer-name {
      font-size: 0.78rem;
      font-weight: 700;
      color: #1a1a2e;
    }

    .opm-officer-pos {
      font-size: 0.65rem;
      color: #94a3b8;
      font-weight: 500;
      margin-top: 2px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    /* CTA button at bottom of profile */
    .opm-apply-cta {
      margin: 6px 26px 24px;
      padding: 14px 20px;
      background: #1a3a8c;
      color: #fff;
      border: none;
      border-radius: 12px;
      font-size: 0.92rem;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      transition: all 0.2s ease;
      box-shadow: 0 4px 16px rgba(37, 99, 235, 0.35);
      width: calc(100% - 52px);
    }

    .opm-apply-cta:hover {
      box-shadow: 0 8px 24px rgba(37, 99, 235, 0.48);
      transform: translateY(-1px);
    }

    /* -- Application Form Modal -- */
    .app-form-overlay {
      position: fixed;
      inset: 0;
      background: rgba(10, 12, 30, 0.72);
      z-index: 1100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .app-form-overlay.active {
      display: flex;
      animation: fadeIn 0.2s ease;
    }

    .app-form-modal {
      background: #fff;
      border-radius: 20px;
      width: 100%;
      max-width: 680px;
      max-height: 92vh;
      overflow-y: auto;
      box-shadow: 0 24px 64px rgba(0, 0, 0, 0.3);
      animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      display: flex;
      flex-direction: column;
    }

    .afm-header {
      padding: 20px 24px 16px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 2;
      border-radius: 20px 20px 0 0;
    }

    .afm-header-left {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .afm-header-org {
      font-size: 0.7rem;
      font-weight: 700;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .afm-header-title {
      font-size: 1rem;
      font-weight: 800;
      color: #1a1a2e;
    }

    .afm-close {
      background: #f1f5f9;
      border: none;
      color: #475569;
      width: 34px;
      height: 34px;
      border-radius: 50%;
      font-size: 1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s;
      flex-shrink: 0;
    }

    .afm-close:hover {
      background: #e2e8f0;
      color: #1a1a2e;
    }

    .afm-body {
      padding: 22px 24px 16px;
    }

    .afm-notice {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 0.8rem;
      color: #1e40af;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-bottom: 20px;
      line-height: 1.55;
    }

    .afm-notice i {
      margin-top: 1px;
      flex-shrink: 0;
    }

    .afm-section-title {
      font-size: 0.7rem;
      font-weight: 800;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 12px;
      margin-top: 18px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .afm-section-title:first-child {
      margin-top: 0;
    }

    .afm-section-title::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #f0f2f5;
    }

    .afm-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .afm-grid.cols-3 {
      grid-template-columns: repeat(3, 1fr);
    }

    .afm-grid.cols-1 {
      grid-template-columns: 1fr;
    }

    .afm-field {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .afm-field.full {
      grid-column: 1/-1;
    }

    .afm-field label {
      font-size: 0.72rem;
      font-weight: 700;
      color: #374151;
      letter-spacing: 0.02em;
    }

    .afm-field label span {
      color: #e11d48;
      margin-left: 2px;
    }

    .afm-field input,
    .afm-field select,
    .afm-field textarea {
      padding: 10px 13px;
      border: 1.5px solid #e2e8f0;
      border-radius: 9px;
      font-size: 0.85rem;
      color: #1a1a2e;
      background: #fafafa;
      transition: all 0.15s ease;
      font-family: inherit;
      width: 100%;
      box-sizing: border-box;
    }

    .afm-field textarea {
      resize: vertical;
      min-height: 80px;
    }

    .afm-field input:focus,
    .afm-field select:focus,
    .afm-field textarea:focus {
      border-color: #2563eb;
      background: #fff;
      outline: none;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .afm-field input.error {
      border-color: #e11d48;
      background: #fff0f3;
    }

    .afm-checkbox-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .afm-checkbox-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.8rem;
      color: #374151;
      font-weight: 500;
      cursor: pointer;
    }

    .afm-checkbox-item input[type="checkbox"] {
      width: 16px;
      height: 16px;
      cursor: pointer;
      accent-color: #2563eb;
    }

    /* Enhanced File Upload UI */
    .afm-file-upload-card {
      border: 1.5px dashed #cbd5e1;
      border-radius: 14px;
      background: #f8fafc;
      padding: 18px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 10px;
      cursor: pointer;
      transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      text-align: center;
      min-height: 120px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }

    .afm-file-upload-card:hover {
      border-color: #2563eb;
      background: #f0f7ff;
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(37, 99, 235, 0.08);
    }

    .afm-file-upload-card.has-file {
      border-color: #10b981;
      background: #f0fdf4;
      border-style: solid;
    }

    .afm-file-upload-card.error {
      border-color: #e11d48;
      background: #fff0f3;
      animation: afmShake 0.4s ease;
    }

    @keyframes afmShake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-5px); }
      75% { transform: translateX(5px); }
    }

    .afm-file-upload-card input[type="file"] {
      position: absolute;
      width: 100%;
      height: 100%;
      top: 0;
      left: 0;
      opacity: 0;
      cursor: pointer;
      z-index: 2;
    }

    .afm-file-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: #e0e7ff;
      color: #2563eb;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      transition: all 0.2s ease;
    }

    .afm-file-upload-card:hover .afm-file-icon {
      background: #dbeafe;
      color: #1d4ed8;
      transform: scale(1.06);
    }

    .afm-file-upload-card.has-file .afm-file-icon {
      background: #dcfce7;
      color: #16a34a;
    }

    .afm-file-btn {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: #1a3a8c;
      color: #ffffff;
      font-size: 0.82rem;
      font-weight: 700;
      padding: 8px 18px;
      border-radius: 9px;
      box-shadow: 0 2px 6px rgba(26, 58, 140, 0.25);
      pointer-events: none;
      transition: all 0.15s ease;
      letter-spacing: 0.01em;
    }

    .afm-file-upload-card:hover .afm-file-btn {
      background: #2563eb;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
    }

    .afm-file-upload-card.has-file .afm-file-btn {
      background: #059669;
      box-shadow: 0 2px 6px rgba(5, 150, 105, 0.25);
    }

    .afm-file-name {
      font-size: 0.8rem;
      font-weight: 600;
      color: #475569;
      max-width: 95%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      pointer-events: none;
    }

    .afm-file-upload-card.has-file .afm-file-name {
      color: #065f46;
      font-weight: 700;
    }

    .afm-file-hint {
      font-size: 0.72rem;
      color: #94a3b8;
      pointer-events: none;
    }

    /* Auto Profile Summary Info Card in Form */
    .afm-student-summary {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 14px 18px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      flex-wrap: wrap;
    }

    .afm-student-summary-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .afm-student-avatar {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      background: #1a3a8c;
      color: #fff;
      font-weight: 800;
      font-size: 1rem;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 3px 8px rgba(26, 58, 140, 0.2);
    }

    .afm-student-meta h4 {
      margin: 0;
      font-size: 0.92rem;
      font-weight: 800;
      color: #0f172a;
    }

    .afm-student-meta p {
      margin: 3px 0 0;
      font-size: 0.78rem;
      color: #64748b;
      line-height: 1.35;
    }

    .afm-verified-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ecfdf5;
      color: #059669;
      border: 1px solid #a7f3d0;
      font-size: 0.74rem;
      font-weight: 700;
      padding: 5px 12px;
      border-radius: 20px;
      box-shadow: 0 1px 3px rgba(16, 185, 129, 0.1);
    }

    /* Signature area */
    .afm-signature-wrap {
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      overflow: hidden;
      background: #fafafa;
    }

    .afm-signature-canvas {
      width: 100%;
      height: 100px;
      display: block;
      cursor: crosshair;
      touch-action: none;
    }

    .afm-sig-actions {
      display: flex;
      gap: 8px;
      padding: 6px 10px;
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
    }

    .afm-sig-clear {
      background: none;
      border: 1px solid #e2e8f0;
      border-radius: 7px;
      padding: 5px 12px;
      font-size: 0.72rem;
      font-weight: 600;
      color: #64748b;
      cursor: pointer;
    }

    .afm-sig-clear:hover {
      border-color: #e11d48;
      color: #e11d48;
    }

    .afm-footer {
      padding: 16px 24px 24px;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      border-top: 1px solid #f0f2f5;
      position: sticky;
      bottom: 0;
      background: #fff;
      border-radius: 0 0 20px 20px;
    }

    .afm-btn-submit {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: #1a3a8c;
      color: #fff;
      border: none;
      border-radius: 11px;
      padding: 13px 20px;
      font-size: 0.9rem;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.2s;
      box-shadow: 0 4px 14px rgba(37, 99, 235, 0.32);
    }

    .afm-btn-submit:hover {
      box-shadow: 0 8px 24px rgba(37, 99, 235, 0.45);
      transform: translateY(-1px);
    }

    .afm-btn-download {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: #f1f5f9;
      color: #475569;
      border: 1.5px solid #e2e8f0;
      border-radius: 11px;
      padding: 13px 20px;
      font-size: 0.88rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.15s;
    }

    .afm-btn-download:hover {
      background: #e2e8f0;
      color: #1a1a2e;
      border-color: #cbd5e1;
    }

    /* -- Success State -- */
    .afm-success {
      padding: 40px 30px;
      text-align: center;
      display: none;
      flex-direction: column;
      align-items: center;
      gap: 14px;
    }

    .afm-success.active {
      display: flex;
    }

    .afm-success-icon {
      font-size: 3.5rem;
      color: #22c55e;
      animation: bounceIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes bounceIn {
      from {
        transform: scale(0.5);
        opacity: 0
      }

      to {
        transform: scale(1);
        opacity: 1
      }
    }

    .afm-success h3 {
      font-size: 1.2rem;
      font-weight: 800;
      color: #1a1a2e;
      margin: 0;
    }

    .afm-success p {
      font-size: 0.85rem;
      color: #64748b;
      line-height: 1.6;
      margin: 0;
      max-width: 400px;
    }

    /* Responsive adjustments */
    @media (max-width:600px) {
      .opm-hero {
        padding: 20px 18px 18px;
      }

      .opm-body {
        padding: 18px 18px 6px;
      }

      .opm-apply-cta {
        width: calc(100% - 36px);
        margin: 6px 18px 20px;
      }

      .afm-grid {
        grid-template-columns: 1fr;
      }

      .afm-grid.cols-3 {
        grid-template-columns: 1fr 1fr;
      }

      .opm-officers-grid {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
      }

      .afm-footer {
        flex-direction: column;
      }

      .afm-btn-submit,
      .afm-btn-download {
        width: 100%;
      }

      .org-search-box {
        min-width: 0;
        width: 100%;
        max-width: 100%;
      }
    }
  </style>
</head>

<body>
  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'clubs';
  require_once __DIR__ . '/../shared/sidebar.php';
  ?>
  <div class="main">
    <div class="topbar">
      <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar"><i class="fa-solid fa-bars"></i></button>
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

    <div class="content">
      <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
        <div>
          <h2 class="page-title" style="margin:0; font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:10px;">
            <i class="fa-solid fa-sitemap" style="color:#2563eb;"></i>
            Club Directory
          </h2>
          <div style="font-size:0.80rem; color:#64748b; margin-top:3px;">
            <?= $is_gov_role ? 'Official Student Organization Registry &bull; Charters &bull; Governance Oversight' : ($sess_role === 'club_adviser' ? 'Assigned Organization Profile &bull; Member Rosters &bull; Campus Directory' : 'Official Directory of Accredited Student Organizations &bull; Membership Portals') ?>
          </div>
        </div>
      </div>

      <div class="content-body">

        <?php if ($is_gov_role): ?>
          <!-- ══════════════════════════════════════════════════════════════
               2.2 ORGANIZATION GOVERNANCE MODULE (SSC Executive / Admin)
               ══════════════════════════════════════════════════════════════ -->
          <!-- Summary Cards Section (6 KPI tiles from specification) -->
          <div class="gov-summary-grid">
            
            <!-- Card 1: Active Organizations -->
            <div class="card gov-kpi-tile">
              <div class="gov-kpi-header">
                <span class="gov-kpi-title">Active Organizations</span>
                <div class="gov-kpi-icon" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-circle-check"></i></div>
              </div>
              <div class="gov-kpi-body">
                <span class="gov-kpi-value"><?= $active_orgs_count ?></span>
              </div>
              <span class="gov-kpi-desc">Recognized organizations currently active.</span>
            </div>

            <!-- Card 2: Pending Charters -->
            <div class="card gov-kpi-tile">
              <div class="gov-kpi-header">
                <span class="gov-kpi-title">Pending Charters</span>
                <div class="gov-kpi-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-file-signature"></i></div>
              </div>
              <div class="gov-kpi-body">
                <span class="gov-kpi-value"><?= $total_pending_charters ?></span>
              </div>
              <span class="gov-kpi-desc">Organizations awaiting charter / recognition review.</span>
            </div>

            <!-- Card 3: Suspended Organizations -->
            <div class="card gov-kpi-tile">
              <div class="gov-kpi-header">
                <span class="gov-kpi-title">Suspended Organizations</span>
                <div class="gov-kpi-icon" style="background:#fee2e2; color:#ef4444;"><i class="fa-solid fa-ban"></i></div>
              </div>
              <div class="gov-kpi-body">
                <span class="gov-kpi-value"><?= $suspended_orgs_count ?></span>
              </div>
              <span class="gov-kpi-desc">Organizations temporarily restricted.</span>
            </div>

            <!-- Card 4: Organizations by Category -->
            <div class="card gov-kpi-tile">
              <div class="gov-kpi-header">
                <span class="gov-kpi-title">Orgs by Category</span>
                <div class="gov-kpi-icon" style="background:#ede9fe; color:#8b5cf6;"><i class="fa-solid fa-layer-group"></i></div>
              </div>
              <div class="gov-kpi-body">
                <div class="gov-kpi-cat-grid">
                  <?php if (empty($cat_counts)): ?>
                    <div class="gov-cat-item" style="grid-column:1/-1;">
                      <span class="gov-cat-name">General</span>
                      <span class="gov-cat-pill">0</span>
                    </div>
                  <?php else: ?>
                    <?php foreach ($cat_counts as $cat_key => $cat_num): ?>
                      <div class="gov-cat-item" title="<?= htmlspecialchars($cat_key) ?>: <?= $cat_num ?>">
                        <span class="gov-cat-name"><?= htmlspecialchars($cat_key) ?></span>
                        <span class="gov-cat-pill"><?= $cat_num ?></span>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
              <span class="gov-kpi-desc">Distribution by configured category.</span>
            </div>

            <!-- Card 5: Organizations Without Adviser -->
            <div class="card gov-kpi-tile">
              <div class="gov-kpi-header">
                <span class="gov-kpi-title">Without Adviser</span>
                <div class="gov-kpi-icon" style="background:#fef3c7; color:#f59e0b;"><i class="fa-solid fa-user-slash"></i></div>
              </div>
              <div class="gov-kpi-body">
                <span class="gov-kpi-value"><?= $no_adviser_count ?></span>
              </div>
              <span class="gov-kpi-desc">Governance exception count.</span>
            </div>

            <!-- Card 6: Organizations With Expiring Documents / Warnings -->
            <div class="card gov-kpi-tile">
              <div class="gov-kpi-header">
                <span class="gov-kpi-title">Compliance Warnings</span>
                <div class="gov-kpi-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-triangle-exclamation"></i></div>
              </div>
              <div class="gov-kpi-body">
                <span class="gov-kpi-value"><?= $compliance_warning_count ?></span>
              </div>
              <span class="gov-kpi-desc">Compliance warning count.</span>
            </div>
          </div>


          <!-- ══════════════════════════════════════════════════════════════
               ORGANIZATION GOVERNANCE REGISTRY TABLE (11 COLUMNS)
               ══════════════════════════════════════════════════════════════ -->
          <div class="card" id="govRegistrySection" style="margin-bottom:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <div>
                <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-table-list" style="color:#2563eb;"></i> Organization Governance Registry</h3>
              </div>
              <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <input type="text" id="govTableSearch" placeholder="Search code, name, adviser..." style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; min-width:200px;" oninput="filterGovRegistryTable()"/>
                <select id="govCategoryFilter" style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; font-weight:600; color:#334155;" onchange="filterGovRegistryTable()">
                  <option value="all">All Categories</option>
                  <option value="academic">Academic</option>
                  <option value="cultural">Cultural</option>
                  <option value="sports">Sports</option>
                  <option value="advocacy">Advocacy</option>
                  <option value="religious">Religious</option>
                </select>
                <select id="govStatusFilter" style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; font-weight:600; color:#334155;" onchange="filterGovRegistryTable()">
                  <option value="all">All Statuses</option>
                  <option value="active">Active</option>
                  <option value="pending charter">Pending Charter</option>
                  <option value="suspended">Suspended</option>
                </select>
                <?php if ($sess_role === 'ssc'): ?>
                  <button type="button" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700; padding:7px 14px; border-radius:8px; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; font-size:0.82rem; border:none; cursor:pointer;" onclick="openCharterModal()">
                    <i class="fa-solid fa-plus-circle"></i> Create Organization
                  </button>
                <?php endif; ?>
              </div>
            </div>

            <!-- Responsive 11-column Governance Registry Table -->
            <div class="table-wrap">
              <table class="table-wide" id="orgGovernanceTable">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Organization Name</th>
                    <th>Category</th>
                    <th>Program</th>
                    <th>Adviser</th>
                    <th style="text-align:center;">Members</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:center;">Charter</th>
                    <th>Last Activity</th>
                    <th style="text-align:center;">Compliance</th>
                    <th class="gov-actions-head" style="text-align:center;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($gov_clubs)): ?>
                    <tr><td colspan="11" class="table-empty-cell">No organizations found in database.</td></tr>
                  <?php else: ?>
                    <?php foreach ($gov_clubs as $gc_idx => $gc): ?>
                      <?php 
                        $search_blob = strtolower($gc['code'] . ' ' . $gc['name'] . ' ' . $gc['category'] . ' ' . ($gc['program'] ?? '') . ' ' . ($gc['adviser_name'] ?? ''));
                        $init_hidden = ($gc_idx >= 5);
                      ?>
                      <tr class="gov-registry-row<?= $init_hidden ? ' is-hidden' : '' ?>" <?= $init_hidden ? 'style="display:none !important;" ' : '' ?>data-search="<?= htmlspecialchars($search_blob, ENT_QUOTES) ?>" data-category="<?= htmlspecialchars(strtolower($gc['category']), ENT_QUOTES) ?>" data-status="<?= htmlspecialchars(strtolower($gc['status']), ENT_QUOTES) ?>">
                        <td><code><?= htmlspecialchars($gc['code']) ?></code></td>
                        <td><strong class="org-table-name"><?= htmlspecialchars($gc['name']) ?></strong></td>
                        <td><span class="badge-info"><?= htmlspecialchars($gc['category']) ?></span></td>
                        <td><span style="font-size:0.82rem; color:#475569;"><?= htmlspecialchars($gc['program'] ?: 'Institutional') ?></span></td>
                        <td>
                          <?php if (!empty($gc['adviser_name']) && $gc['adviser_name'] !== 'Unassigned'): ?>
                            <span style="font-weight:600; color:#1e293b;"><?= htmlspecialchars($gc['adviser_name']) ?></span>
                          <?php else: ?>
                            <span style="color:#d97706; font-size:0.78rem; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> Unassigned</span>
                          <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                          <strong style="color:#0f172a;"><?= (int)$gc['member_count'] ?></strong> <span style="font-size:0.75rem; color:#64748b;">members</span>
                        </td>
                        <td style="text-align:center;">
                          <?php if ($gc['status'] === 'Active'): ?>
                            <span class="badge-active"><i class="fa-solid fa-circle-check"></i> Active</span>
                          <?php elseif ($gc['status'] === 'Suspended'): ?>
                            <span class="badge-danger" style="background:#fee2e2; color:#b91c1c; font-weight:700; padding:3px 8px; border-radius:12px; font-size:0.75rem;"><i class="fa-solid fa-ban"></i> Suspended</span>
                          <?php else: ?>
                            <span class="badge-warning"><?= htmlspecialchars($gc['status']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td style="text-align:center;"><?= $gc['charter_badge'] ?></td>
                        <td>
                          <span style="font-size:0.8rem; color:#475569;"><?= htmlspecialchars($gc['last_activity'] ?: 'No activity recorded') ?></span>
                        </td>
                        <td style="text-align:center;"><?= $gc['compliance_badge'] ?></td>
                        <td class="gov-actions-col" style="text-align:center;">
                          <div class="actions-group" style="justify-content:center; gap:6px;">
                            <?php if ($sess_role === 'admin' && $gc['status'] === 'Pending Charter'): ?>
                              <button type="button" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700; padding:6px 12px; font-size:0.78rem; border:none; cursor:pointer;" onclick="handleApproveCharter(<?= (int)$gc['id'] ?>, '<?= htmlspecialchars(addslashes($gc['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($gc['code']), ENT_QUOTES) ?>')" title="Approve &amp; Grant Official Accreditation">
                                <i class="fa-solid fa-circle-check"></i> Approve
                              </button>
                            <?php endif; ?>
                            <button type="button" class="card-btn" style="background:#2563eb; color:#fff;" onclick="openOrgProfile('<?= htmlspecialchars(addslashes($gc['code'])) ?>')" title="Inspect Organization Profile &amp; Governance Details">
                              <i class="fa-solid fa-eye"></i> View
                            </button>
                            <a href="roster.php?view=roster&club_id=<?= $gc['id'] ?>&club_code=<?= urlencode($gc['code']) ?>" class="card-btn" style="background:#f1f5f9; color:#334155; border:1px solid #cbd5e1;" title="View <?= htmlspecialchars($gc['code']) ?> Member Roster">
                              <i class="fa-solid fa-users"></i> Roster
                            </a>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  <tr id="govTableEmptyMsg" style="display:none;">
                    <td colspan="11" class="table-empty-cell"><i class="fa-solid fa-circle-info"></i> No organizations match the current filter criteria.</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Table Pagination Bar (5 records per page) -->
            <div id="govPaginationWrap" class="pagination-toolbar" style="justify-content:flex-end;">
              <div class="pagination-controls" style="margin-left:auto;">
                <div class="pagination-buttons" id="govPaginationButtons">
                  <!-- Dynamically rendered -->
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════════════════
             DIRECTORY CARDS SECTION (General Students Only)
             ══════════════════════════════════════════════════════════════ -->
        <?php if (!$is_gov_role): ?>
        <div id="orgDirectoryCardsSection">

        <?php if ($sess_role === 'club_adviser'): ?>
          <?php
          // Fetch handled organization for Adviser
          $my_org = $conn->query("SELECT c.*, 
                                 (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id=c.id AND cm.status='Active') as active_count,
                                 (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id=c.id AND cm.status='Pending') as pending_count
                                 FROM clubs c 
                                 JOIN club_memberships cm ON cm.club_id=c.id 
                                 WHERE cm.user_id=$user_id AND cm.status='Active' AND c.deleted_at IS NULL LIMIT 1")->fetch_assoc();
          if (!$my_org) {
            $my_org = $conn->query("SELECT c.*, 
                                     (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id=c.id AND cm.status='Active') as active_count,
                                     (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id=c.id AND cm.status='Pending') as pending_count
                                     FROM clubs c WHERE c.status='Active' AND c.deleted_at IS NULL LIMIT 1")->fetch_assoc();
          }
          ?>
          <?php if ($my_org): ?>
            <div
              style="background: #1a3a8c; color: white; padding: 24px; border-radius: 16px; margin-bottom: 25px; box-shadow: 0 10px 25px -5px rgba(37,99,235,0.3);">
              <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div>
                  <span
                    style="background:rgba(255,255,255,0.2); padding:4px 12px; border-radius:20px; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Handled
                    Organization Governance</span>
                  <h2 style="margin:8px 0 4px; font-size:1.6rem; font-weight:800; color:white;">
                    <?= htmlspecialchars($my_org['name']) ?> (<?= htmlspecialchars($my_org['code']) ?>)</h2>
                  <p style="margin:0; font-size:0.9rem; opacity:0.9; max-width:650px;">
                    <?= htmlspecialchars($my_org['description'] ?: 'Accredited Student Organization under BCP Office of Student Affairs.') ?>
                  </p>
                </div>
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                  <button
                    onclick="openBroadcastModal(<?= $my_org['id'] ?>, '<?= htmlspecialchars(addslashes($my_org['name'])) ?>')"
                    style="background:white; color:#0f2a73; border:none; padding:10px 18px; border-radius:10px; font-weight:700; cursor:pointer; font-size:0.88rem; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-bullhorn" style="margin-right:6px;color:#2563eb;"></i>Post Announcement
                  </button>
                  <a href="roster.php"
                    style="background:rgba(255,255,255,0.2); color:white; text-decoration:none; padding:10px 18px; border-radius:10px; font-weight:700; font-size:0.88rem; border:1px solid rgba(255,255,255,0.3); display:inline-flex; align-items:center;">
                    <i class="fa-solid fa-users" style="margin-right:6px;"></i>Review Applicants
                    (<?= (int) $my_org['pending_count'] ?>)
                  </a>
                </div>
              </div>
              <div
                style="display:flex; gap:24px; margin-top:20px; padding-top:16px; border-top:1px solid rgba(255,255,255,0.2); font-size:0.88rem; flex-wrap:wrap;">
                <div><i class="fa-solid fa-user-check" style="margin-right:6px;opacity:0.8;"></i>Active Members:
                  <strong><?= $my_org['active_count'] ?></strong></div>
                <div><i class="fa-solid fa-clock" style="margin-right:6px;opacity:0.8;"></i>Pending Applicants:
                  <strong><?= $my_org['pending_count'] ?></strong></div>
                <div><i class="fa-solid fa-user-tie" style="margin-right:6px;opacity:0.8;"></i>Adviser:
                  <strong><?= htmlspecialchars($sess_first . ' ' . $sess_last) ?></strong></div>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <!-- Category Filters & Live Search -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <button type="button" class="cat-filter-pill active" onclick="filterCat('all',this)"><i class="fa-solid fa-th-large"></i> All Organizations</button>
            <button type="button" class="cat-filter-pill" onclick="filterCat('academic',this)"><i class="fa-solid fa-graduation-cap"></i> Academic</button>
            <button type="button" class="cat-filter-pill" onclick="filterCat('talent',this)"><i class="fa-solid fa-star"></i> Talent &amp; Cultural</button>
            <button type="button" class="cat-filter-pill" onclick="filterCat('independent',this)"><i class="fa-solid fa-seedling"></i> Independent</button>
          </div>
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; flex:1; justify-content:flex-end;">
            <div class="org-search-box">
              <i class="fa-solid fa-magnifying-glass org-search-icon"></i>
              <input type="text" id="orgSearchInput" placeholder="Search organization by name or acronym..." oninput="handleOrgSearch(this.value)" autocomplete="off" />
              <button type="button" class="org-search-clear" id="clearOrgSearchBtn" onclick="clearOrgSearch()" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
            </div>
          </div>
        </div>

        <div class="org-directory-wrap" id="orgDirectory">

          <!-- -- ACADEMIC -- -->
          <div class="org-category-section" data-category="academic">
            <div class="org-category-header">
              <div class="cat-icon"><i class="fa-solid fa-graduation-cap"></i></div>
              <div class="cat-info">
                <div class="cat-title">LEAGUE OF ORGANIZATIONAL CHAIRPERSONS (LOC)</div>
                <div class="cat-sub">Academic Organizations</div>
              </div>
              <span class="cat-count"><?php echo count($academic_orgs); ?> orgs</span>
            </div>
            <div class="org-subcategory">
              <div class="org-cards-grid">
                <?php foreach ($academic_orgs as $org): ?>
                  <div class="org-card"
                    data-name="<?php echo htmlspecialchars(strtolower($org['name'] . ' ' . $org['acronym'])); ?>"
                    data-acronym="<?php echo htmlspecialchars($org['acronym']); ?>">
                    <span class="org-card-acronym"><?php echo htmlspecialchars($org['acronym']); ?></span>
                    <div class="org-card-name"><?php echo htmlspecialchars($org['name']); ?></div>
                    <div class="org-card-type"><i class="fa-solid fa-circle-dot"
                        style="color:#2563eb;font-size:.6rem;"></i> Academic Organization</div>
                    <?php echo renderOrgCardButton($org, $can_apply, $db_clubs, $student_applications); ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- -- TALENT & CULTURAL -- -->
          <div class="org-category-section" data-category="talent">
            <div class="org-category-header"
              style="background:#1a3a8c;">
              <div class="cat-icon"><i class="fa-solid fa-star"></i></div>
              <div class="cat-info">
                <div class="cat-title">CENTER FOR TALENT AND CULTURAL EMPOWERMENT (CTCE)</div>
                <div class="cat-sub">Non-Academic Organizations</div>
              </div>
              <span class="cat-count"><?php echo $talent_count; ?> orgs</span>
            </div>
            <?php foreach ($talent_subcategories as $subcat_label => $subcat_orgs): ?>
              <?php if (!empty($subcat_orgs)): ?>
                <div class="org-subcategory">
                  <div class="org-subcategory-label">
                    <i class="fa-solid fa-chevron-right" style="color:#2563eb;font-size:.6rem;"></i>
                    <?php echo htmlspecialchars($subcat_label); ?>
                    <span
                      style="background:#eff6ff;color:#2563eb;border-radius:12px;padding:2px 8px;font-size:.65rem;margin-left:4px;"><?php echo count($subcat_orgs); ?></span>
                  </div>
                  <div class="org-cards-grid">
                    <?php foreach ($subcat_orgs as $org): ?>
                      <div class="org-card"
                        data-name="<?php echo htmlspecialchars(strtolower($org['name'] . ' ' . $org['acronym'])); ?>"
                        data-acronym="<?php echo htmlspecialchars($org['acronym']); ?>">
                        <span class="org-card-acronym"
                          style="background:#1a3a8c;"><?php echo htmlspecialchars($org['acronym']); ?></span>
                        <div class="org-card-name"><?php echo htmlspecialchars($org['name']); ?></div>
                        <div class="org-card-type"><i class="fa-solid fa-circle-dot"
                            style="color:#2563eb;font-size:.6rem;"></i> <?php echo htmlspecialchars($subcat_label); ?>
                        </div>
                        <?php echo renderOrgCardButton($org, $can_apply, $db_clubs, $student_applications); ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <!-- -- INDEPENDENT -- -->
          <div class="org-category-section" data-category="independent">
            <div class="org-category-header"
              style="background:#1a3a8c;">
              <div class="cat-icon"><i class="fa-solid fa-seedling"></i></div>
              <div class="cat-info">
                <div class="cat-title">INDEPENDENT ORGANIZATIONS</div>
                <div class="cat-sub">Campus-Wide Independent Bodies</div>
              </div>
              <span class="cat-count"><?php echo count($independent_orgs); ?> orgs</span>
            </div>
            <div class="org-subcategory">
              <div class="org-cards-grid">
                <?php foreach ($independent_orgs as $org): ?>
                  <div class="org-card"
                    data-name="<?php echo htmlspecialchars(strtolower($org['name'] . ' ' . $org['acronym'])); ?>"
                    data-acronym="<?php echo htmlspecialchars($org['acronym']); ?>">
                    <span class="org-card-acronym"
                      style="background:#1a3a8c;"><?php echo htmlspecialchars($org['acronym']); ?></span>
                    <div class="org-card-name"><?php echo htmlspecialchars($org['name']); ?></div>
                    <div class="org-card-type"><i class="fa-solid fa-circle-dot"
                        style="color:#2563eb;font-size:.6rem;"></i> Independent Organization</div>
                    <?php echo renderOrgCardButton($org, $can_apply, $db_clubs, $student_applications); ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Empty State when no org matches search / filter -->
          <div class="org-no-results" id="orgNoResults">
            <i class="fa-solid fa-magnifying-glass org-no-results-icon"></i>
            <h3>No Organizations Found</h3>
            <p>We couldn't find any organization matching your search or category criteria.</p>
            <button type="button" class="cat-filter-pill org-reset-btn" onclick="resetAllFilters()">
              <i class="fa-solid fa-rotate-left"></i> Reset Search &amp; Filters
            </button>
          </div>

        </div><!-- end #orgDirectory -->
      </div><!-- end #orgDirectoryCardsSection -->
      <?php endif; ?>
      </div>
    </div>
    <div class="footer">eLearning Commons &copy; 2026</div>
  </div>

  <!-- ----------------------------------------------------------
     MODAL 1: Organization Profile
---------------------------------------------------------- -->
  <div class="org-profile-overlay" id="orgProfileOverlay">
    <div class="org-profile-modal" id="orgProfileModal">

      <!-- Hero Header -->
      <div class="opm-hero" id="opmHero">
        <button class="opm-close" id="opmClose" title="Close"><i class="fa-solid fa-xmark"></i></button>
        <div class="opm-acronym-badge" id="opmAcronym">ORG</div>
        <h2 class="opm-title" id="opmTitle">Organization Name</h2>
        <div class="opm-category" id="opmCategory">
          <i class="fa-solid fa-circle-dot"></i> <span></span>
        </div>
      </div>

      <!-- Profile Body -->
      <div class="opm-body">

        <!-- Description -->
        <div class="opm-section-label"><i class="fa-solid fa-circle-info"></i> About</div>
        <div class="opm-description" id="opmDescription"></div>

        <!-- Adviser -->
        <div class="opm-section-label"><i class="fa-solid fa-user-tie"></i> Faculty Adviser</div>
        <div class="opm-description" id="opmAdviser" style="margin-bottom:18px; font-weight:700; color:#1e3a8a;"></div>

        <!-- Achievements -->
        <div class="opm-section-label"><i class="fa-solid fa-trophy"></i> Achievements</div>
        <ul class="opm-achievements" id="opmAchievements"></ul>

        <!-- Officers -->
        <div class="opm-section-label"><i class="fa-solid fa-users"></i> Current Officers</div>
        <div class="opm-officers-grid" id="opmOfficers"></div>

      </div>

      <!-- Apply CTA (Student only) -->
      <?php if ($can_apply): ?>
        <button class="opm-apply-cta" id="opmApplyBtn">
          <i class="fa-solid fa-file-pen"></i>
          Submit Your Application
        </button>
      <?php else: ?>
        <div style="margin:6px 26px 24px; display:flex; gap:10px; flex-wrap:wrap;">
          <a href="roster.php?view=roster" id="opmRosterLinkBtn" class="card-btn" style="flex:1; justify-content:center; text-align:center; text-decoration:none; background:#2563eb; color:#fff; font-weight:700; padding:12px 20px; border-radius:12px; font-size:0.9rem; display:inline-flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-users"></i> View Member Roster
          </a>
          <a href="roster.php?view=queue" id="opmQueueLinkBtn" class="card-btn" style="text-decoration:none; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; font-weight:600; padding:12px 18px; border-radius:12px; font-size:0.85rem; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-list-check"></i> Review Queue
          </a>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- ----------------------------------------------------------
     MODAL 2: Membership Application Form
---------------------------------------------------------- -->
  <div class="app-form-overlay" id="appFormOverlay">
    <div class="app-form-modal" id="appFormModal">

      <!-- Sticky Header -->
      <div class="afm-header">
        <div class="afm-header-left">
          <div class="afm-header-org" id="afmOrgName">Organization</div>
          <div class="afm-header-title">Membership Application Form</div>
        </div>
        <button class="afm-close" id="afmClose" title="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <!-- Form body (hidden when success shows) -->
      <div class="afm-body" id="afmFormBody">

        <!-- Student Applicant Summary (Database-driven) -->
        <div class="afm-student-summary">
          <div class="afm-student-summary-left">
            <div class="afm-student-avatar"><?= $sess_initial ?></div>
            <div class="afm-student-meta">
              <h4><?= htmlspecialchars(trim($sess_first . ' ' . $sess_last) ?: 'Student Applicant') ?></h4>
              <p>
                Student No: <strong><?= htmlspecialchars($student_info['student_number'] ?? 'N/A') ?></strong> &bull;
                Program: <strong><?= htmlspecialchars($student_info['course'] ?? 'BSIT') ?> (<?= htmlspecialchars($student_info['year_level'] ?? '1st Year') ?>)</strong>
              </p>
            </div>
          </div>
          <span class="afm-verified-badge"><i class="fa-solid fa-circle-check"></i> Verified Student Profile</span>
        </div>

        <!-- SKILLS & INTERESTS -->
        <div class="afm-section-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Skills &amp; Interests</div>
        <div class="afm-grid cols-1">
          <div class="afm-field">
            <label>Skills &amp; Competencies</label>
            <textarea id="afmSkills"
              placeholder="e.g. Web development, public speaking, graphic design, leadership..."></textarea>
          </div>
          <div class="afm-field">
            <label>Why do you want to join this organization? <span>*</span></label>
            <textarea id="afmMotivation" placeholder="Share your motivation, goals, and what you can contribute..."
              required style="min-height:90px;"></textarea>
          </div>
        </div>

        <!-- REQUIRED DOCUMENTS SUBMISSION -->
        <div class="afm-section-title"><i class="fa-solid fa-file-arrow-up"></i> Required Document Submission</div>
        <div style="margin-bottom:14px;">
          <div class="afm-field">
            <label>Letter of Intent <span>*</span> <span style="font-weight:400;color:#94a3b8;">(PDF/DOCX/Image)</span></label>
            <div class="afm-file-upload-card" id="afmIntentCard">
              <input type="file" id="afmLetterIntent" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp" required onchange="handleFileSelect(this, 'afmIntentCard', 'afmLetterIntentName', 'afmIntentIcon')" />
              <div class="afm-file-icon" id="afmIntentIcon"><i class="fa-solid fa-file-arrow-up"></i></div>
              <span class="afm-file-btn"><i class="fa-solid fa-folder-open"></i> Choose File</span>
              <div class="afm-file-name" id="afmLetterIntentName">No file chosen</div>
              <span class="afm-file-hint">PDF, DOCX, PNG, JPG (Max 10MB)</span>
            </div>
          </div>
          <div style="font-size:0.78rem; color:#475569; background:#f8fafc; border:1px solid #e2e8f0; padding:10px 14px; border-radius:10px; margin-top:12px; display:flex; align-items:flex-start; gap:10px;">
            <i class="fa-solid fa-stamp" style="color:#059669; font-size:1rem; margin-top:2px;"></i>
            <div style="line-height:1.45;">
              <strong style="color:#0f172a;">Adviser Endorsement Notice:</strong> Once you submit your Letter of Intent, your application will be forwarded to the assigned <strong>Club Adviser</strong> who will review your submission and issue the official <strong>Letter of Endorsement</strong> to the Supreme Student Council (SSC).
            </div>
          </div>
        </div>

      </div><!-- end afm-body -->

      <!-- Success State -->
      <div class="afm-success" id="afmSuccess">
        <div class="afm-success-icon"><i class="fa-solid fa-circle-check"></i></div>
        <h3>Application Submitted!</h3>
        <p>Your membership application has been received. The organization will review your application and contact you
          via email within 3–5 business days.</p>
        <button class="afm-btn-submit" onclick="closeAppForm()" style="max-width:240px; margin:16px auto 0;">
          <i class="fa-solid fa-check"></i> Done
        </button>
      </div>

      <!-- Sticky Footer Actions -->
      <div class="afm-footer" id="afmFooter">
        <button class="afm-btn-submit" id="afmSubmitBtn" style="width:100%;">
          <i class="fa-solid fa-paper-plane"></i> Submit Application
        </button>
      </div>

    </div>
  </div>


  <script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
  <script>
    // -- Org data from PHP -----------------------------------------
    const ORG_DATA = <?php echo json_encode($all_orgs, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const STUDENT_STATUSES = <?php echo json_encode($student_org_statuses, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const studentDb = <?php echo json_encode($student_info ?? [
      'first_name' => $sess_first,
      'last_name' => $sess_last,
      'email' => $_SESSION['email'] ?? '',
      'student_number' => '',
      'course' => '',
      'year_level' => '',
      'section' => '',
      'phone' => '',
      'birthday' => ''
    ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let currentOrg = null; // { acronym, ...data }

    // Helper to dynamically reflect 'Waiting for Approval' on the org card
    function updateOrgCardToPending(acronym) {
      if (!acronym) return;
      const cards = document.querySelectorAll(`.org-card[data-acronym="${acronym}"]`);
      cards.forEach(card => {
        const btn = card.querySelector('.org-card-qr-btn');
        if (btn) {
          btn.className = 'org-card-qr-btn org-status-pending';
          btn.disabled = true;
          btn.removeAttribute('onclick');
          btn.style.background = '#fffbeb';
          btn.style.borderColor = '#fde68a';
          btn.style.color = '#b45309';
          btn.style.cursor = 'default';
          btn.title = 'Your application has been submitted and is awaiting SSC review and approval.';
          btn.innerHTML = '<i class="fa-solid fa-clock"></i> Waiting for Approval';
        }
      });
    }

    // -- CATEGORY & SEARCH FILTERS ---------------------------------
    let currentCategory = 'all';
    let currentSearchQuery = '';

    function filterDirectory() {
      const q = currentSearchQuery.toLowerCase().trim();
      const cat = currentCategory;
      let totalVisible = 0;

      document.querySelectorAll('.org-category-section').forEach(section => {
        const sectionCat = section.dataset.category;
        const matchesCategory = (cat === 'all' || sectionCat === cat);

        if (!matchesCategory) {
          section.style.display = 'none';
          return;
        }

        let sectionVisibleCount = 0;
        section.querySelectorAll('.org-subcategory').forEach(sub => {
          let subVisibleCount = 0;
          sub.querySelectorAll('.org-card').forEach(card => {
            const name = (card.dataset.name || '').toLowerCase();
            const matchesQuery = (!q || name.includes(q));

            if (matchesQuery) {
              card.style.display = '';
              subVisibleCount++;
              sectionVisibleCount++;
              totalVisible++;
            } else {
              card.style.display = 'none';
            }
          });

          sub.style.display = subVisibleCount > 0 ? '' : 'none';
        });

        section.style.display = sectionVisibleCount > 0 ? '' : 'none';
      });

      const noRes = document.getElementById('orgNoResults');
      if (noRes) {
        noRes.style.display = totalVisible === 0 ? 'block' : 'none';
      }
    }

    function filterCat(cat, btn) {
      currentCategory = cat;
      document.querySelectorAll('.cat-filter-pill').forEach(p => p.classList.remove('active'));
      if (btn) btn.classList.add('active');
      filterDirectory();
    }
    window.filterCat = filterCat;

    function handleOrgSearch(val) {
      currentSearchQuery = val || '';
      const clearBtn = document.getElementById('clearOrgSearchBtn');
      if (clearBtn) {
        clearBtn.style.display = currentSearchQuery.trim().length > 0 ? 'inline-flex' : 'none';
      }
      filterDirectory();
    }
    window.handleOrgSearch = handleOrgSearch;

    function clearOrgSearch() {
      const input = document.getElementById('orgSearchInput');
      if (input) {
        input.value = '';
        input.focus();
      }
      handleOrgSearch('');
    }
    window.clearOrgSearch = clearOrgSearch;

    function resetAllFilters() {
      const allBtn = document.querySelector('.cat-filter-pill');
      currentCategory = 'all';
      if (allBtn) {
        document.querySelectorAll('.cat-filter-pill').forEach(p => p.classList.remove('active'));
        allBtn.classList.add('active');
      }
      clearOrgSearch();
    }
    window.resetAllFilters = resetAllFilters;

    // -- OPEN ORG PROFILE MODAL ------------------------------------
    function openOrgProfile(acronym) {
      if (!acronym) return;
      let data = (typeof ORG_DATA !== 'undefined' && ORG_DATA) ? ORG_DATA[acronym] : null;
      if (!data && typeof ORG_DATA !== 'undefined' && ORG_DATA) {
        // Fallback: search case-insensitively or by clean alphanumeric key or by club name
        const cleanAcr = String(acronym).replace(/[^A-Za-z0-9]/g, '').toLowerCase();
        const matchKey = Object.keys(ORG_DATA).find(k => 
          k.toLowerCase() === String(acronym).toLowerCase() || 
          k.replace(/[^A-Za-z0-9]/g, '').toLowerCase() === cleanAcr ||
          (ORG_DATA[k] && ORG_DATA[k].name && ORG_DATA[k].name.toLowerCase() === String(acronym).toLowerCase())
        );
        if (matchKey) {
          data = ORG_DATA[matchKey];
          acronym = matchKey;
        }
      }

      if (!data) {
        console.warn('Organization profile data not found for:', acronym);
        return;
      }
      currentOrg = { acronym, ...data };

      // Hero
      const hero = document.getElementById('opmHero');
      if (hero) hero.style.background = data.color || '#1a3a8c';
      const acrEl = document.getElementById('opmAcronym');
      if (acrEl) acrEl.textContent = acronym;
      const titEl = document.getElementById('opmTitle');
      if (titEl) titEl.textContent = data.name || acronym;
      const catEl = document.getElementById('opmCategory');
      if (catEl && catEl.querySelector('span')) catEl.querySelector('span').textContent = data.category || 'Accredited Organization';

      // Description
      const descEl = document.getElementById('opmDescription');
      if (descEl) descEl.textContent = (data.profile && data.profile.desc) ? data.profile.desc : 'Official accredited student organization dedicated to student empowerment and academic excellence.';

      // Adviser
      const advEl = document.getElementById('opmAdviser');
      if (advEl) advEl.textContent = (data.profile && data.profile.adviser) ? data.profile.adviser : 'Prof. BCP Faculty Adviser';

      // Achievements
      const achList = document.getElementById('opmAchievements');
      if (achList) {
        const achs = (data.profile && Array.isArray(data.profile.achievements) && data.profile.achievements.length) 
          ? data.profile.achievements 
          : ['BCP Accredited Organization AY 2025-2026', 'Supreme Student Council Charter Recognition'];
        achList.innerHTML = achs.map(a =>
          `<li><i class="fa-solid fa-medal"></i> ${a}</li>`
        ).join('');
      }

      // Officers
      const officersGrid = document.getElementById('opmOfficers');
      if (officersGrid) {
        const offs = (data.profile && Array.isArray(data.profile.officers)) ? data.profile.officers : [];
        if (offs.length === 0) {
          officersGrid.innerHTML = '<div style="color:#64748b; font-size:0.8rem; font-style:italic; padding:6px 0;">No active student officers recorded yet.</div>';
        } else {
          officersGrid.innerHTML = offs.map(o => {
            const initials = (o.name || 'O').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
            return `
          <div class="opm-officer-card">
            <div class="opm-officer-avatar" style="background:${data.color || '#1a3a8c'};">${initials}</div>
            <div class="opm-officer-name">${o.name || ''}</div>
            <div class="opm-officer-pos">${o.pos || 'Officer'}</div>
          </div>`;
          }).join('');
        }
      }

      // Check application / membership status for the profile CTA button
      const applyBtn = document.getElementById('opmApplyBtn');
      if (applyBtn) {
        const status = (typeof STUDENT_STATUSES !== 'undefined' && STUDENT_STATUSES[acronym]) ? STUDENT_STATUSES[acronym] : null;
        if (status === 'Pending') {
          applyBtn.disabled = true;
          applyBtn.className = 'opm-apply-cta is-pending';
          applyBtn.innerHTML = '<i class="fa-solid fa-clock"></i> Waiting for Approval';
        } else if (status === 'Active') {
          applyBtn.disabled = true;
          applyBtn.className = 'opm-apply-cta is-member';
          applyBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Active Member';
        } else {
          // If rejected or not applied, regular old state
          applyBtn.disabled = false;
          applyBtn.className = 'opm-apply-cta';
          applyBtn.innerHTML = '<i class="fa-solid fa-file-pen"></i> Submit Your Application';
        }
      }

      // Wire direct links for Admin & SSC in the modal
      const rosterLink = document.getElementById('opmRosterLinkBtn');
      if (rosterLink) {
        rosterLink.href = 'roster.php?view=roster&club_code=' + encodeURIComponent(acronym);
      }
      const queueLink = document.getElementById('opmQueueLinkBtn');
      if (queueLink) {
        queueLink.href = 'roster.php?view=queue&filter_org=' + encodeURIComponent(acronym);
      }

      const profOverlay = document.getElementById('orgProfileOverlay');
      if (profOverlay) {
        profOverlay.classList.add('active');
        profOverlay.style.display = 'flex';
      }
      document.body.style.overflow = 'hidden';
    }

    function closeOrgProfile() {
      const profOverlay = document.getElementById('orgProfileOverlay');
      if (profOverlay) {
        profOverlay.classList.remove('active');
        profOverlay.style.display = 'none';
      }
      document.body.style.overflow = '';
    }

    // Close profile modal
    document.getElementById('opmClose')?.addEventListener('click', closeOrgProfile);
    document.getElementById('orgProfileOverlay')?.addEventListener('click', e => {
      if (e.target === document.getElementById('orgProfileOverlay')) {
        closeOrgProfile();
      }
    });

    // Apply CTA button in profile modal -> close profile, open application form
    document.getElementById('opmApplyBtn')?.addEventListener('click', () => {
      if (currentOrg) {
        const status = (typeof STUDENT_STATUSES !== 'undefined' && STUDENT_STATUSES[currentOrg.acronym]) ? STUDENT_STATUSES[currentOrg.acronym] : null;
        if (status === 'Pending') {
          showToast('Your application for this organization is already awaiting SSC review and approval.', 'info');
          return;
        }
        if (status === 'Active') {
          showToast('You are already an active member of this organization.', 'info');
          return;
        }
        openAppForm(currentOrg.acronym);
      } else {
        openAppForm();
      }
    });

    // -- OPEN APPLICATION FORM MODAL -------------------------------
    function openAppForm(acronym) {
      if (acronym && typeof STUDENT_STATUSES !== 'undefined') {
        const status = STUDENT_STATUSES[acronym];
        if (status === 'Pending') {
          showToast('Your application for this organization is already awaiting SSC review and approval.', 'info');
          return;
        }
        if (status === 'Active') {
          showToast('You are already an active member of this organization.', 'info');
          return;
        }
      }

      if (acronym && ORG_DATA[acronym]) {
        currentOrg = { acronym, ...ORG_DATA[acronym] };
      } else if (acronym) {
        const matchKey = Object.keys(ORG_DATA).find(k => k.toLowerCase() === acronym.toLowerCase() || k.replace(/[^A-Za-z0-9]/g, '').toLowerCase() === acronym.replace(/[^A-Za-z0-9]/g, '').toLowerCase());
        if (matchKey) {
          currentOrg = { acronym: matchKey, ...ORG_DATA[matchKey] };
        } else {
          currentOrg = { acronym: acronym, name: acronym, club_id: 0 };
        }
      }

      if (!currentOrg) {
        const firstKey = Object.keys(ORG_DATA)[0];
        if (firstKey) currentOrg = { acronym: firstKey, ...ORG_DATA[firstKey] };
      }

      const orgNameEl = document.getElementById('afmOrgName');
      if (orgNameEl && currentOrg) {
        orgNameEl.textContent = (currentOrg.acronym || 'ORG') + ' — ' + (currentOrg.name || 'Organization');
      }

      resetForm();
      closeOrgProfile();

      const appOverlay = document.getElementById('appFormOverlay');
      if (appOverlay) {
        appOverlay.classList.add('active');
        appOverlay.style.display = 'flex';
      }
      document.body.style.overflow = 'hidden';
    }

    function closeAppForm() {
      const appOverlay = document.getElementById('appFormOverlay');
      if (appOverlay) {
        appOverlay.classList.remove('active');
        appOverlay.style.display = 'none';
      }
      document.body.style.overflow = '';
      resetForm();
    }

    // File selection UI handler
    function handleFileSelect(input, cardId, nameId, iconId) {
      const card = document.getElementById(cardId);
      const nameEl = document.getElementById(nameId);
      const icon = document.getElementById(iconId);
      if (input.files && input.files[0]) {
        const file = input.files[0];
        if (nameEl) nameEl.textContent = file.name;
        if (card) {
          card.classList.add('has-file');
          card.classList.remove('error');
        }
        if (icon) icon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
      } else {
        if (nameEl) nameEl.textContent = 'No file chosen';
        if (card) card.classList.remove('has-file');
        if (icon) icon.innerHTML = '<i class="fa-solid fa-file-arrow-up"></i>';
      }
    }

    function resetForm() {
      const formBody = document.getElementById('afmFormBody');
      if (formBody) formBody.style.display = '';
      const successEl = document.getElementById('afmSuccess');
      if (successEl) successEl.classList.remove('active');
      const footerEl = document.getElementById('afmFooter');
      if (footerEl) footerEl.style.display = '';
      
      // Clear inputs
      document.querySelectorAll('#appFormModal input, #appFormModal textarea').forEach(el => {
        if (el.type === 'checkbox') el.checked = false;
        else if (el.type !== 'submit' && el.type !== 'button') el.value = '';
        el.classList.remove('error');
      });

      // Reset file upload cards
      ['afmIntentCard', 'afmEndorsementCard'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
          el.classList.remove('has-file');
          el.classList.remove('error');
        }
      });
      const intentName = document.getElementById('afmLetterIntentName');
      if (intentName) intentName.textContent = 'No file chosen';
      const intentIcon = document.getElementById('afmIntentIcon');
      if (intentIcon) intentIcon.innerHTML = '<i class="fa-solid fa-file-arrow-up"></i>';

      const endName = document.getElementById('afmLetterEndorsementName');
      if (endName) endName.textContent = 'No file chosen';
      const endIcon = document.getElementById('afmEndorsementIcon');
      if (endIcon) endIcon.innerHTML = '<i class="fa-solid fa-file-arrow-up"></i>';
    }

    document.getElementById('afmClose')?.addEventListener('click', closeAppForm);
    document.getElementById('appFormOverlay')?.addEventListener('click', e => {
      if (e.target === document.getElementById('appFormOverlay')) closeAppForm();
    });

    // Bind to window object for global availability
    window.openOrgProfile = openOrgProfile;
    window.closeOrgProfile = closeOrgProfile;
    window.openAppForm = openAppForm;
    window.closeAppForm = closeAppForm;
    window.handleFileSelect = handleFileSelect;

    // -- FORM VALIDATION -------------------------------------------
    function validateForm() {
      let valid = true;
      const motiv = document.getElementById('afmMotivation');
      if (motiv && !motiv.value.trim()) {
        motiv.classList.add('error');
        valid = false;
      } else if (motiv) {
        motiv.classList.remove('error');
      }

      const intentInput = document.getElementById('afmLetterIntent');
      const intentCard = document.getElementById('afmIntentCard');

      if (!intentInput || !intentInput.files.length) {
        if (intentCard) intentCard.classList.add('error');
        valid = false;
      } else if (intentCard) {
        intentCard.classList.remove('error');
      }

      if (!valid && document.querySelector('.error')) {
        document.querySelector('.error').scrollIntoView({ behavior: 'smooth', block: 'center' });
        showToast('Please complete your motivation and attach your Letter of Intent.', 'error');
      }
      return valid;
    }

    // -- SUBMIT HANDLER (Real AJAX with Files) ---------------------
    document.getElementById('afmSubmitBtn')?.addEventListener('click', async function () {
      if (!validateForm()) return;

      const clubId = currentOrg ? (currentOrg.club_id || 0) : 0;
      const orgAcr = currentOrg ? (currentOrg.acronym || '') : '';
      const orgName = currentOrg ? (currentOrg.name || '') : '';

      const confirmed = await window.showConfirmModal(
        'Submit Membership Application?',
        `Do you want to submit your membership application to ${orgName || 'this organization'}? Your application and Letter of Intent will be sent to the club adviser for verification and endorsement.`,
        { type: 'info', confirmText: 'Yes, Submit Application' }
      );
      if (!confirmed) return;

      this.disabled = true;
      this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

      const fd = new FormData();
      fd.set('action', 'apply');
      fd.set('club_id', clubId);
      fd.set('org_acronym', orgAcr);
      fd.set('org_name', orgName);
      fd.set('first_name', <?= json_encode($_SESSION['first_name'] ?? '') ?>);
      fd.set('last_name', <?= json_encode($_SESSION['last_name'] ?? '') ?>);
      fd.set('student_id_no', <?= json_encode($student_info['student_number'] ?? '') ?>);
      fd.set('course', <?= json_encode($student_info['course'] ?? '') ?>);
      fd.set('year_level', <?= json_encode($student_info['year_level'] ?? '') ?>);
      fd.set('email', <?= json_encode($student_info['email'] ?? $_SESSION['email'] ?? '') ?>);
      fd.set('contact', <?= json_encode($student_info['phone'] ?? '') ?>);
      fd.set('dob', <?= json_encode($student_info['birthday'] ?? '') ?>);
      fd.set('skills', document.getElementById('afmSkills')?.value.trim() || '');
      fd.set('motivation', document.getElementById('afmMotivation')?.value.trim() || '');

      const csrfMeta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      if (csrfMeta) fd.append('csrf_token', csrfMeta);

      const intentFile = document.getElementById('afmLetterIntent')?.files[0];
      if (intentFile) fd.append('letter_intent', intentFile);

      fetch('../shared/roster_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          this.disabled = false;
          this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Application';
          if (data.success) {
            document.getElementById('afmFormBody').style.display = 'none';
            document.getElementById('afmFooter').style.display = 'none';
            document.getElementById('afmSuccess').classList.add('active');

            // Dynamically update card to 'Waiting for Approval' state
            if (currentOrg && currentOrg.acronym) {
              if (typeof STUDENT_STATUSES !== 'undefined') {
                STUDENT_STATUSES[currentOrg.acronym] = 'Pending';
              }
              updateOrgCardToPending(currentOrg.acronym);
            }
          } else {
            showToast(data.message || 'Submission failed. Please try again.', 'error');
          }
        })
        .catch(() => {
          this.disabled = false;
          this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Application';
          showToast('Network error. Please check your connection.', 'error');
        });
    });


    async function openBroadcastModal(clubId, orgName) {
      const titleDecision = await window.showDecisionModal({
        title: 'Broadcast Announcement',
        message: `Do you want to post an official broadcast announcement for ${orgName}?`,
        badge: 'Official Broadcast',
        badgeType: 'info',
        inputLabel: 'Announcement Title',
        inputPlaceholder: 'Enter clear headline...',
        inputRequired: true,
        confirmText: 'Next: Enter Content',
        confirmClass: 'btn-modal-primary'
      });
      if (!titleDecision.confirmed || !titleDecision.reason.trim()) return;
      const title = titleDecision.reason.trim();

      const bodyDecision = await window.showDecisionModal({
        title: 'Announcement Content',
        message: `Enter the full announcement text for "${title}":`,
        badge: 'Details',
        badgeType: 'info',
        inputLabel: 'Announcement Body',
        inputPlaceholder: 'Type announcement content for all organization members...',
        inputRequired: true,
        confirmText: 'Publish Announcement',
        confirmClass: 'btn-modal-primary'
      });
      if (!bodyDecision.confirmed || !bodyDecision.reason.trim()) return;
      const message = bodyDecision.reason.trim();

      const formData = new FormData();
      formData.append('action', 'broadcast');
      formData.append('club_id', clubId);
      formData.append('title', title);
      formData.append('message', message);

      try {
        const res = await fetch('../shared/notification_actions.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          window.alert('Announcement successfully posted to active organization members!', 'success');
        } else {
          window.alert('Error: ' + data.message, 'error');
        }
      } catch {
        window.alert('Network error posting announcement.', 'error');
      }
    }

    // -- CHARTER NEW ORGANIZATION (SSC Propose Only / Admin Approve) -
    function openCharterModal() {
      document.getElementById('charterModalOverlay')?.classList.add('active');
    }
    function closeCharterModal() {
      document.getElementById('charterModalOverlay')?.classList.remove('active');
    }
    function updateAdviserNameField(sel) {
      const opt = sel.options[sel.selectedIndex];
      const name = opt ? (opt.getAttribute('data-name') || '') : '';
      const hidden = document.getElementById('charterAdviserName');
      if (hidden) hidden.value = name || 'Unassigned';
    }
    function handleCharterOrg(e) {
      e.preventDefault();
      const btn = document.getElementById('btnSubmitCharter');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting Proposal...';
      }

      const fd = new FormData(e.target);
      fd.append('action', 'create_club');
      fetch('../shared/club_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            if (typeof showToast === 'function') {
              showToast(res.message, 'success');
            } else {
              alert(res.message);
            }
            closeCharterModal();
            setTimeout(() => location.reload(), 1200);
          } else {
            alert(res.message || 'Failed to submit organization charter proposal.');
            if (btn) {
              btn.disabled = false;
              btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit for Admin Approval';
            }
          }
        })
        .catch(() => {
          alert('Network error submitting organization charter proposal.');
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit for Admin Approval';
          }
        });
    }

    function handleApproveCharter(clubId, clubName, clubCode) {
      if (!confirm(`Are you sure you want to approve and grant official accreditation to "${clubName}" (${clubCode})?`)) {
        return;
      }
      const fd = new FormData();
      fd.append('action', 'approve_charter');
      fd.append('club_id', clubId);
      const csrfMeta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      if (csrfMeta) fd.append('csrf_token', csrfMeta);

      fetch('../shared/club_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            if (typeof showToast === 'function') {
              showToast(res.message, 'success');
            } else {
              alert(res.message);
            }
            setTimeout(() => location.reload(), 1200);
          } else {
            alert(res.message || 'Failed to approve charter.');
          }
        })
        .catch(() => alert('Network error while approving charter.'));
    }

    // -- GOVERNANCE REGISTRY PAGINATION & FILTER SYSTEM (5 records/page)
    let govCurrentPage = 1;
    const GOV_PAGE_SIZE = 5;

    function getMatchingGovRows() {
      const query = (document.getElementById('govTableSearch')?.value || '').toLowerCase().trim();
      const cat = (document.getElementById('govCategoryFilter')?.value || 'all').toLowerCase();
      const status = (document.getElementById('govStatusFilter')?.value || 'all').toLowerCase();

      const allRows = Array.from(document.querySelectorAll('.gov-registry-row'));
      return allRows.filter(row => {
        const rowSearch = (row.getAttribute('data-search') || '').toLowerCase();
        const rowCat = (row.getAttribute('data-category') || '').toLowerCase();
        const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();

        const matchesQuery = !query || rowSearch.includes(query);
        const matchesCat = cat === 'all' || rowCat === cat;
        const matchesStatus = status === 'all' || rowStatus === status;

        return matchesQuery && matchesCat && matchesStatus;
      });
    }

    function renderGovTablePage() {
      const allRows = document.querySelectorAll('.gov-registry-row');
      if (!allRows.length) return;

      const matchingRows = getMatchingGovRows();
      const total = matchingRows.length;
      const totalPages = Math.ceil(total / GOV_PAGE_SIZE) || 1;

      if (govCurrentPage > totalPages) govCurrentPage = totalPages;
      if (govCurrentPage < 1) govCurrentPage = 1;

      const startIndex = (govCurrentPage - 1) * GOV_PAGE_SIZE;
      const endIndex = Math.min(startIndex + GOV_PAGE_SIZE, total);

      // Hide all rows with both is-hidden class and display none
      allRows.forEach(r => {
        r.style.setProperty('display', 'none', 'important');
        r.classList.add('is-hidden');
      });

      // Display slice for current page
      for (let i = startIndex; i < endIndex; i++) {
        if (matchingRows[i]) {
          matchingRows[i].style.removeProperty('display');
          matchingRows[i].classList.remove('is-hidden');
        }
      }

      // Empty state
      const emptyMsg = document.getElementById('govTableEmptyMsg');
      if (emptyMsg) {
        emptyMsg.style.display = total === 0 ? '' : 'none';
      }

      // Update info labels
      const startEl = document.getElementById('govPageStart');
      const endEl = document.getElementById('govPageEnd');
      const totalEl = document.getElementById('govTotalCount');
      if (startEl) startEl.textContent = total === 0 ? 0 : startIndex + 1;
      if (endEl) endEl.textContent = endIndex;
      if (totalEl) totalEl.textContent = total;

      // Render pagination buttons
      renderGovPaginationControls(totalPages);

      // Re-apply responsive mobile card labels
      if (typeof window.initResponsiveTables === 'function') {
        window.initResponsiveTables();
      }
    }

    function renderGovPaginationControls(totalPages) {
      const container = document.getElementById('govPaginationButtons');
      if (!container) return;

      let html = '';
      const prevDisabled = govCurrentPage <= 1 ? 'disabled' : '';
      html += `<button type="button" class="card-btn btn-sm pagination-btn prev-btn ${govCurrentPage <= 1 ? 'disabled' : ''}" onclick="goToGovPage(${govCurrentPage - 1})" ${prevDisabled}><i class="fa-solid fa-chevron-left"></i> Prev</button>`;

      for (let p = 1; p <= totalPages; p++) {
        if (p === govCurrentPage) {
          html += `<button type="button" class="card-btn btn-sm pagination-btn page-num-btn active">${p}</button>`;
        } else if (p === 1 || p === totalPages || (p >= govCurrentPage - 1 && p <= govCurrentPage + 1)) {
          html += `<button type="button" class="card-btn btn-sm pagination-btn page-num-btn" onclick="goToGovPage(${p})">${p}</button>`;
        } else if (p === govCurrentPage - 2 || p === govCurrentPage + 2) {
          html += `<span class="pagination-ellipsis">...</span>`;
        }
      }

      const nextDisabled = govCurrentPage >= totalPages ? 'disabled' : '';
      html += `<button type="button" class="card-btn btn-sm pagination-btn next-btn ${govCurrentPage >= totalPages ? 'disabled' : ''}" onclick="goToGovPage(${govCurrentPage + 1})" ${nextDisabled}>Next <i class="fa-solid fa-chevron-right"></i></button>`;

      container.innerHTML = html;
    }

    function goToGovPage(page) {
      govCurrentPage = page;
      renderGovTablePage();
      const tblWrap = document.getElementById('orgGovernanceTable');
      if (tblWrap) {
        tblWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

    function filterGovRegistryTable() {
      govCurrentPage = 1;
      renderGovTablePage();
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', renderGovTablePage);
    } else {
      renderGovTablePage();
    }
  </script>

  <?php if ($sess_role === 'ssc'): ?>
  <!-- CHARTER ORGANIZATION MODAL (SSC Propose Only — Awaits Admin Approval) -->
  <div class="org-profile-overlay" id="charterModalOverlay">
    <div class="org-profile-modal" style="max-width:560px;">
      <div class="opm-hero" style="background:linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color:#fff; display:flex; justify-content:space-between; align-items:center; padding:18px 24px; border-radius:20px 20px 0 0;">
        <div>
          <h3 style="margin:0; font-size:1.15rem; color:#fff; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-plus-circle" style="color:#facc15;"></i> Create Organization Proposal
          </h3>
          <span style="font-size:0.75rem; color:#bfdbfe; font-weight:600;">Supreme Student Council (SSC) Charter Endorsement</span>
        </div>
        <button type="button" style="background:none; border:none; color:#fff; font-size:1.25rem; cursor:pointer;" onclick="closeCharterModal()"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="charterOrgForm" onsubmit="handleCharterOrg(event)">
        <?= csrf_field() ?>
        <div class="opm-body" style="padding:22px 26px; max-height:75vh; overflow-y:auto;">
          
          <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px 14px; margin-bottom:16px; display:flex; align-items:flex-start; gap:10px;">
            <i class="fa-solid fa-circle-info" style="color:#2563eb; font-size:1rem; margin-top:2px;"></i>
            <div style="font-size:0.78rem; color:#1e40af; line-height:1.4;">
              <strong>Charter Approval Workflow:</strong> Organizations proposed by the SSC are saved with <em>Pending Charter</em> status and must receive final accreditation clearance from the <strong>System Administrator</strong> before activation.
            </div>
          </div>

          <div style="display:grid; grid-template-columns:1fr 2fr; gap:12px; margin-bottom:14px;">
            <div>
              <label style="font-size:0.75rem; font-weight:800; color:#475569; display:block; margin-bottom:5px; text-transform:uppercase;">Acronym / Code <span style="color:#ef4444;">*</span></label>
              <input type="text" name="code" required placeholder="e.g. JPCS" style="width:100%; padding:9px 12px; border-radius:8px; border:1.5px solid #cbd5e1; font-weight:700; text-transform:uppercase;" oninput="this.value = this.value.toUpperCase()"/>
            </div>
            <div>
              <label style="font-size:0.75rem; font-weight:800; color:#475569; display:block; margin-bottom:5px; text-transform:uppercase;">Organization Name <span style="color:#ef4444;">*</span></label>
              <input type="text" name="name" required placeholder="e.g. Junior Philippine Computer Society" style="width:100%; padding:9px 12px; border-radius:8px; border:1.5px solid #cbd5e1;"/>
            </div>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
            <div>
              <label style="font-size:0.75rem; font-weight:800; color:#475569; display:block; margin-bottom:5px; text-transform:uppercase;">Category <span style="color:#ef4444;">*</span></label>
              <select name="category" required style="width:100%; padding:9px 12px; border-radius:8px; border:1.5px solid #cbd5e1; font-weight:600; background:#fff;">
                <option value="Academic">Academic Organization</option>
                <option value="Cultural">Cultural &amp; Talent</option>
                <option value="Sports">Sports &amp; Athletics</option>
                <option value="Advocacy">Advocacy &amp; Civic</option>
                <option value="Religious">Religious &amp; Faith</option>
              </select>
            </div>
            <div>
              <label style="font-size:0.75rem; font-weight:800; color:#475569; display:block; margin-bottom:5px; text-transform:uppercase;">Program / Scope <span style="color:#ef4444;">*</span></label>
              <select name="program" required style="width:100%; padding:9px 12px; border-radius:8px; border:1.5px solid #cbd5e1; font-weight:600; background:#fff;">
                <option value="Institutional">Institutional (All Programs)</option>
                <option value="BSCS">BSCS - Computer Science</option>
                <option value="BSIT">BSIT - Information Technology</option>
                <option value="BSCpE">BSCpE - Computer Engineering</option>
                <option value="BSAIS">BSAIS - Accounting Information System</option>
                <option value="BSBA">BSBA - Business Administration</option>
                <option value="BSCrim">BSCrim - Criminology</option>
                <option value="BSEd">BSEd - Secondary Education</option>
                <option value="BSHM">BSHM - Hospitality Management</option>
                <option value="BSTM">BSTM - Tourism Management</option>
                <option value="BSPsych">BSPsych - Psychology</option>
                <option value="BLIS">BLIS - Library &amp; Information Science</option>
              </select>
            </div>
          </div>

          <div style="margin-bottom:14px;">
            <label style="font-size:0.75rem; font-weight:800; color:#475569; display:block; margin-bottom:5px; text-transform:uppercase;">Assigned Faculty Adviser</label>
            <select name="adviser_user_id" id="charterAdviserSelect" style="width:100%; padding:9px 12px; border-radius:8px; border:1.5px solid #cbd5e1; background:#fff;" onchange="updateAdviserNameField(this)">
              <option value="">-- No Adviser Assigned (Unassigned) --</option>
              <?php foreach ($faculty_advisers as $fa): ?>
                <option value="<?= (int)$fa['id'] ?>" data-name="<?= htmlspecialchars($fa['first_name'] . ' ' . $fa['last_name'], ENT_QUOTES) ?>">
                  <?= htmlspecialchars($fa['first_name'] . ' ' . $fa['last_name'] . ' (' . $fa['email'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="adviser_name" id="charterAdviserName" value="Unassigned"/>
          </div>

          <div style="margin-bottom:14px;">
            <label style="font-size:0.75rem; font-weight:800; color:#475569; display:block; margin-bottom:5px; text-transform:uppercase;">Charter Objectives &amp; Mission</label>
            <textarea name="description" rows="3" placeholder="Charter statement, core purpose, target student membership..." style="width:100%; padding:9px 12px; border-radius:8px; border:1.5px solid #cbd5e1; font-family:inherit; font-size:0.85rem;"></textarea>
          </div>
        </div>
        <div style="padding:14px 26px; border-top:1px solid #f1f5f9; background:#f8fafc; display:flex; justify-content:flex-end; gap:10px; border-radius:0 0 20px 20px;">
          <button type="button" class="card-btn" style="background:#e2e8f0; color:#475569;" onclick="closeCharterModal()">Cancel</button>
          <button type="submit" id="btnSubmitCharter" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700;"><i class="fa-solid fa-paper-plane"></i> Submit for Admin Approval</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</body>

</html>
