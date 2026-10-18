<?php
// ============================================================
//  VOLUNTEER.PHP  (app/dashboard/)
//  Co-Curricular Management System — Volunteer Hours Module
//  Student Event Volunteering & Adviser Role Verification
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_auth();

// Ensure DB schema and authentic seed opportunities exist
require_once __DIR__ . '/../shared/init_volunteer.php';
require_once __DIR__ . '/../shared/volunteer_helpers.php';

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

$ACTIVE_NAV   = 'volunteer';

// ── 1. STUDENT PROFILE & METRICS ──────────────────────────────────────
$student_info   = null;
$student_course = '';
$student_number = '';
$student_year   = '';

if ($sess_role === 'student') {
    $s_stmt = $conn->prepare("
        SELECT u.first_name, u.last_name, u.email, s.student_number, s.course, s.year_level, s.section
        FROM users u
        LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
        WHERE u.id = ? LIMIT 1
    ");
    if ($s_stmt) {
        $s_stmt->bind_param('i', $user_id);
        $s_stmt->execute();
        $student_info = $s_stmt->get_result()->fetch_assoc();
        $s_stmt->close();
    }
    $student_course = $student_info['course'] ?? 'Bachelor of Science in Information Technology';
    $student_number = $student_info['student_number'] ?? 'N/A';
    $student_year   = $student_info['year_level'] ?? '2nd Year';
    $student_is_org_member = is_student_organization_member($conn, $user_id);
    $student_member_clubs  = [];

    // Fetch the specific organization(s) where the student's application was approved/endorsed by the adviser
    $mc_stmt = $conn->prepare("
        SELECT DISTINCT c.id, c.name, c.code, c.category, c.program, c.sub_category, c.adviser_name,
               COALESCE(u_adv.first_name, '') as adv_first, COALESCE(u_adv.last_name, '') as adv_last
        FROM clubs c
        LEFT JOIN users u_adv ON u_adv.id = c.adviser_user_id
        WHERE c.id IN (
            SELECT club_id FROM club_memberships 
            WHERE user_id = ? AND (status = 'Active' OR adviser_review = 'Endorsed' OR adviser_review = 'Approved')
            UNION
            SELECT club_id FROM club_applications 
            WHERE user_id = ? AND (adviser_status = 'Endorsed' OR adviser_review = 'Endorsed' OR status = 'Approved')
        )
        ORDER BY c.name ASC
    ");
    if ($mc_stmt) {
        $mc_stmt->bind_param('ii', $user_id, $user_id);
        $mc_stmt->execute();
        $mc_res = $mc_stmt->get_result();
        while ($mc = $mc_res->fetch_assoc()) {
            $student_member_clubs[] = $mc;
        }
        $mc_stmt->close();
    }
} else {
    $student_is_org_member = true;
    $student_member_clubs  = [];
}

// ── 2. ADVISER ASSIGNED ORGANIZATION ─────────────────────────────────
$adviser_club = null;
if ($sess_role === 'club_adviser') {
    $assigned_clubs = get_user_assigned_clubs($conn, $user_id, $sess_role);
    $adviser_club = !empty($assigned_clubs) ? $assigned_clubs[0] : null;
}

// ── 3. FETCH STUDENT'S EXISTING VOLUNTEER APPLICATIONS & LOGS ─────────
$my_volunteer_logs = [];
$my_applied_opps   = []; // opp_id => status
$total_verified_hours = 0.0;
$total_pending_hours  = 0.0;

if ($sess_role === 'student') {
    $v_stmt = $conn->prepare("
        SELECT vl.*, c.name as club_name, c.code as club_code, e.venue, e.event_date
        FROM volunteer_logs vl
        LEFT JOIN clubs c ON c.id = vl.club_id
        LEFT JOIN events e ON e.id = vl.event_id
        WHERE vl.user_id = ?
        ORDER BY vl.created_at DESC
    ");
    if ($v_stmt) {
        $v_stmt->bind_param('i', $user_id);
        $v_stmt->execute();
        $res = $v_stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $my_volunteer_logs[] = $row;
            if (!empty($row['opportunity_id'])) {
                $my_applied_opps[$row['opportunity_id']] = $row;
            }
            if ($row['status'] === 'Verified') {
                $total_verified_hours += (float)$row['hours'];
            } elseif ($row['status'] === 'Pending Verification') {
                $total_pending_hours += (float)$row['hours'];
            }
        }
        $v_stmt->close();
    }
}

// ── 4. FETCH VOLUNTEER OPPORTUNITIES (Filtered by Adviser of that Organization) ──
$opportunities = [];
if ($sess_role === 'student') {
    // Opportunities must be fetched for the organization(s) where the student is an approved member (approved by that organization's adviser)
    if (!empty($student_member_clubs)) {
        $club_ids = array_map(function($c) { return (int)$c['id']; }, $student_member_clubs);
        $in_clause = implode(',', $club_ids);
        $opp_query = "
            SELECT vo.*, e.title as event_title, e.event_date, e.venue, e.description as event_desc,
                   c.id as club_id, c.name as club_name, c.code as club_code, c.category as club_category,
                   c.program as club_program, c.sub_category as club_sub_category,
                   c.adviser_name,
                   (SELECT COUNT(*) FROM volunteer_logs vl WHERE vl.opportunity_id = vo.id AND vl.status IN ('Verified', 'Pending Verification')) as current_volunteers
            FROM volunteer_opportunities vo
            JOIN events e ON e.id = vo.event_id
            JOIN clubs c ON c.id = vo.club_id
            WHERE vo.status = 'Open' 
              AND e.status IN ('Approved', 'Upcoming', 'Pending SSC', 'Pending Admin')
              AND vo.club_id IN ($in_clause)
            ORDER BY e.event_date ASC, vo.id ASC
        ";
        $opp_res = $conn->query($opp_query);
        if ($opp_res) {
            while ($o = $opp_res->fetch_assoc()) {
                $elig = checkVolunteerEligibility(
                    $student_course,
                    $o['club_category'],
                    $o['club_program'],
                    $o['club_sub_category'],
                    $o['target_program']
                );
                $o['is_eligible'] = $elig['eligible'];
                $o['eligibility_reason'] = $elig['reason'];
                $opportunities[] = $o;
            }
        }
    }
} else {
    // Club adviser sees roles for their assigned club; Admin & SSC see all
    $club_filter = "";
    if ($sess_role === 'club_adviser' && $adviser_club) {
        $club_filter = "AND vo.club_id = " . (int)$adviser_club['id'];
    }
    $opp_query = "
        SELECT vo.*, e.title as event_title, e.event_date, e.venue, e.description as event_desc,
               c.id as club_id, c.name as club_name, c.code as club_code, c.category as club_category,
               c.program as club_program, c.sub_category as club_sub_category,
               c.adviser_name,
               (SELECT COUNT(*) FROM volunteer_logs vl WHERE vl.opportunity_id = vo.id AND vl.status IN ('Verified', 'Pending Verification')) as current_volunteers
        FROM volunteer_opportunities vo
        JOIN events e ON e.id = vo.event_id
        JOIN clubs c ON c.id = vo.club_id
        WHERE vo.status = 'Open' AND e.status IN ('Approved', 'Upcoming', 'Pending SSC', 'Pending Admin')
        $club_filter
        ORDER BY e.event_date ASC, vo.id ASC
    ";
    $opp_res = $conn->query($opp_query);
    if ($opp_res) {
        while ($o = $opp_res->fetch_assoc()) {
            $o['is_eligible'] = true;
            $o['eligibility_reason'] = 'Authorized Staff View';
            $opportunities[] = $o;
        }
    }
}

// ── Categorize opportunities to match Organization Directory structure ──
$academic_opps    = [];
$talent_opps      = [];
$independent_opps = [];

foreach ($opportunities as $opp) {
    $cat = strtolower(trim($opp['club_category'] ?? ''));
    $sub = trim($opp['club_sub_category'] ?? '');
    if ($cat === 'academic') {
        $academic_opps[] = $opp;
    } elseif (in_array($cat, ['cultural', 'sports']) || in_array($sub, ['Department Based Talent Group', 'Talent Center'])) {
        $talent_opps[] = $opp;
    } else {
        $independent_opps[] = $opp;
    }
}

// ── Card Renderer Helper matching club_directory.php .org-card style ──
if (!function_exists('renderVolunteerOppCard')) {
    function renderVolunteerOppCard(array $opp, ?array $my_app, string $student_course, string $sess_role): string {
        $has_applied = !empty($my_app);
        $app_status  = $has_applied ? $my_app['status'] : null;
        $cat_slug    = strtolower($opp['club_category'] ?? '');
        if (in_array($cat_slug, ['cultural', 'sports'])) {
            $cat_slug = 'talent';
        } elseif ($cat_slug !== 'academic') {
            $cat_slug = 'independent';
        }

        $event_date_formatted = $opp['event_date']
            ? date('M d, Y', strtotime($opp['event_date'])) . ' &bull; ' . date('g:i A', strtotime($opp['event_date']))
            : 'TBA';

        $search_blob = htmlspecialchars(strtolower(
            ($opp['role_name'] ?? '') . ' ' .
            ($opp['event_title'] ?? '') . ' ' .
            ($opp['club_name'] ?? '') . ' ' .
            ($opp['club_code'] ?? '') . ' ' .
            ($opp['target_program'] ?? '') . ' ' .
            ($opp['venue'] ?? '')
        ), ENT_QUOTES);

        $opp_id              = (int)$opp['id'];
        $role_name_escaped   = htmlspecialchars(addslashes($opp['role_name'] ?? ''));
        $event_title_escaped = htmlspecialchars(addslashes($opp['event_title'] ?? ''));
        $club_name_escaped   = htmlspecialchars(addslashes($opp['club_name'] ?? ''));
        $club_code_escaped   = htmlspecialchars(addslashes($opp['club_code'] ?? ''));
        $hours_float         = (float)($opp['hours'] ?? 0);

        ob_start();
        ?>
        <div class="org-card vol-opp-card"
             data-search="<?= $search_blob ?>"
             data-category="<?= htmlspecialchars($cat_slug) ?>"
             data-eligible="<?= $opp['is_eligible'] ? '1' : '0' ?>"
             style="cursor:default;">

          <!-- Top Row: Club Acronym Badge + Credit Hours Pill -->
          <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
            <span class="org-card-acronym" title="<?= htmlspecialchars($opp['club_name']) ?>"><?= htmlspecialchars($opp['club_code']) ?></span>
            <span style="background:#eff6ff; color:#1d4ed8; font-size:0.65rem; font-weight:800; padding:2.5px 8px; border-radius:12px; border:1px solid #bfdbfe; display:inline-flex; align-items:center; gap:3.5px; white-space:nowrap;">
              <i class="fa-solid fa-clock" style="color:#2563eb;"></i> <?= number_format($opp['hours'], 1) ?> hrs
            </span>
          </div>

          <!-- Role Name (Bold) -->
          <div class="org-card-name" style="font-size:0.82rem; font-weight:800; color:#0f172a; line-height:1.25;" title="<?= htmlspecialchars($opp['role_name']) ?>">
            <?= htmlspecialchars($opp['role_name']) ?>
          </div>

          <!-- Event Title -->
          <div class="org-card-type" style="font-size:0.68rem; color:#2563eb; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($opp['event_title']) ?>">
            <i class="fa-solid fa-calendar-check" style="font-size:0.62rem;"></i> <?= htmlspecialchars($opp['event_title']) ?>
          </div>

          <!-- Compact Event Metadata: Venue, Date, Slots -->
          <div style="font-size:0.68rem; color:#64748b; display:flex; flex-direction:column; gap:2.5px; margin-top:2px; line-height:1.3;">
            <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($opp['venue'] ?: 'Campus Grounds') ?>">
              <i class="fa-solid fa-location-dot" style="width:12px; color:#2563eb; font-size:0.65rem;"></i> <?= htmlspecialchars($opp['venue'] ?: 'Campus Grounds') ?>
            </div>
            <div>
              <i class="fa-solid fa-calendar-day" style="width:12px; color:#2563eb; font-size:0.65rem;"></i> <?= $event_date_formatted ?>
            </div>
            <div>
              <i class="fa-solid fa-users" style="width:12px; color:#2563eb; font-size:0.65rem;"></i> <?= (int)$opp['current_volunteers'] ?> of <?= (int)$opp['slots'] ?> slots filled
            </div>
          </div>

          <!-- Action Button matching .org-card-qr-btn -->
          <?php if ($sess_role === 'student'): ?>
            <?php if ($has_applied): ?>
              <?php if ($app_status === 'Verified'): ?>
                <button type="button" class="org-card-qr-btn" style="background:#dcfce7 !important; border-color:#86efac !important; color:#15803d !important; cursor:default;" disabled>
                  <i class="fa-solid fa-circle-check"></i> Verified
                </button>
              <?php elseif ($app_status === 'Pending Verification'): ?>
                <button type="button" class="org-card-qr-btn org-status-pending" style="cursor:default;" disabled title="Application submitted and awaiting adviser review">
                  <i class="fa-solid fa-clock"></i> Waiting for Approval
                </button>
              <?php else: ?>
                <button type="button" class="org-card-qr-btn" style="background:#fef2f2 !important; border-color:#fecaca !important; color:#b91c1c !important; cursor:default;" disabled>
                  <i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($app_status) ?>
                </button>
              <?php endif; ?>
            <?php else: ?>
              <?php if ($opp['is_eligible']): ?>
                <button type="button" class="org-card-qr-btn" style="background:#2563eb !important; border-color:#2563eb !important; color:#ffffff !important;"
                        onclick="openApplyVolunteerModal(<?= $opp_id ?>, '<?= $role_name_escaped ?>', '<?= $event_title_escaped ?>', '<?= $club_name_escaped ?>', '<?= $club_code_escaped ?>', <?= $hours_float ?>)">
                  <i class="fa-solid fa-hand-holding-heart"></i> Volunteer
                </button>
              <?php else: ?>
                <button type="button" class="org-card-qr-btn" style="background:#f1f5f9 !important; border-color:#e2e8f0 !important; color:#94a3b8 !important; cursor:not-allowed;" disabled title="<?= htmlspecialchars($opp['eligibility_reason']) ?>">
                  <i class="fa-solid fa-ban"></i> Ineligible
                </button>
              <?php endif; ?>
            <?php endif; ?>
          <?php else: ?>
            <button type="button" class="org-card-qr-btn" style="background:#f1f5f9 !important; border-color:#e2e8f0 !important; color:#334155 !important;" onclick="alert('Posting Title: <?= $role_name_escaped ?>\nTarget: <?= htmlspecialchars(addslashes($opp['target_program'])) ?>\nSlots: <?= (int)$opp['current_volunteers'] ?> of <?= (int)$opp['slots'] ?> filled')">
              <i class="fa-solid fa-eye"></i> View Role Info
            </button>
          <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }
}

// ── 5. FETCH ADVISER PENDING & VERIFIED QUEUE ─────────────────────────
$adviser_pending_queue = [];
$adviser_verified_list = [];
$adviser_total_hours_approved = 0.0;

if ($sess_role === 'club_adviser' && $adviser_club) {
    $club_id_adv = (int)$adviser_club['id'];

    // Pending applications for this adviser's club
    $p_stmt = $conn->prepare("
        SELECT vl.*, e.venue, e.event_date, c.name as club_name, c.code as club_code
        FROM volunteer_logs vl
        JOIN events e ON e.id = vl.event_id
        JOIN clubs c ON c.id = vl.club_id
        WHERE vl.club_id = ? AND vl.status = 'Pending Verification'
        ORDER BY vl.created_at ASC
    ");
    if ($p_stmt) {
        $p_stmt->bind_param('i', $club_id_adv);
        $p_stmt->execute();
        $p_res = $p_stmt->get_result();
        while ($pr = $p_res->fetch_assoc()) {
            // Check student program match against adviser's club
            $pr_elig = checkVolunteerEligibility(
                $pr['course'],
                $adviser_club['category'],
                $adviser_club['program'],
                $adviser_club['sub_category'],
                $adviser_club['program'] ?: 'All'
            );
            $pr['program_match'] = $pr_elig['eligible'];
            $pr['program_match_note'] = $pr_elig['reason'];
            $adviser_pending_queue[] = $pr;
        }
        $p_stmt->close();
    }

    // Verified volunteers
    $vfd_stmt = $conn->prepare("
        SELECT vl.*, e.venue, e.event_date, c.name as club_name, c.code as club_code,
               u_adv.first_name as adv_first, u_adv.last_name as adv_last
        FROM volunteer_logs vl
        JOIN events e ON e.id = vl.event_id
        JOIN clubs c ON c.id = vl.club_id
        LEFT JOIN users u_adv ON u_adv.id = vl.verified_by
        WHERE vl.club_id = ? AND vl.status = 'Verified'
        ORDER BY vl.verified_at DESC
    ");
    if ($vfd_stmt) {
        $vfd_stmt->bind_param('i', $club_id_adv);
        $vfd_stmt->execute();
        $vfd_res = $vfd_stmt->get_result();
        while ($vr = $vfd_res->fetch_assoc()) {
            $adviser_verified_list[] = $vr;
            $adviser_total_hours_approved += (float)$vr['hours'];
        }
        $vfd_stmt->close();
    }
} elseif (in_array($sess_role, ['ssc', 'admin'])) {
    // SSC / Admin Institutional Oversight
    $p_res = $conn->query("
        SELECT vl.*, e.venue, e.event_date, c.name as club_name, c.code as club_code, c.category as club_category, c.program as club_program, c.sub_category as club_sub_category
        FROM volunteer_logs vl
        JOIN events e ON e.id = vl.event_id
        JOIN clubs c ON c.id = vl.club_id
        WHERE vl.status = 'Pending Verification'
        ORDER BY vl.created_at ASC
    ");
    if ($p_res) {
        while ($pr = $p_res->fetch_assoc()) {
            $pr_elig = checkVolunteerEligibility(
                $pr['course'],
                $pr['club_category'],
                $pr['club_program'],
                $pr['club_sub_category'],
                $pr['club_program'] ?: 'All'
            );
            $pr['program_match'] = $pr_elig['eligible'];
            $pr['program_match_note'] = $pr_elig['reason'];
            $adviser_pending_queue[] = $pr;
        }
    }

    $vfd_res = $conn->query("
        SELECT vl.*, e.venue, e.event_date, c.name as club_name, c.code as club_code,
               u_adv.first_name as adv_first, u_adv.last_name as adv_last
        FROM volunteer_logs vl
        JOIN events e ON e.id = vl.event_id
        JOIN clubs c ON c.id = vl.club_id
        LEFT JOIN users u_adv ON u_adv.id = vl.verified_by
        WHERE vl.status = 'Verified'
        ORDER BY vl.verified_at DESC
    ");
    if ($vfd_res) {
        while ($vr = $vfd_res->fetch_assoc()) {
            $adviser_verified_list[] = $vr;
            $adviser_total_hours_approved += (float)$vr['hours'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Volunteer Hours – BCP Co-Curricular Management System</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    /* ── Volunteer Hours Theme & Typography ── */
    .vol-container { width: 100%; max-width: 100%; margin: 0; }
    
    .vol-kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .vol-kpi-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 18px 20px;
      display: flex;
      align-items: center;
      gap: 16px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .vol-kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .vol-kpi-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.35rem;
      flex-shrink: 0;
    }
    .vol-kpi-val { font-size: 1.55rem; font-weight: 900; color: #0f172a; line-height: 1.1; }
    .vol-kpi-lbl { font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }

    /* ── Category Filter Pills (matching Organization Directory) ── */
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

    /* ── Directory Search Box ── */
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

    /* ── Organization Directory Card Layout & Grid ── */
    .org-directory-wrap {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .org-category-section {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .org-category-header {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 20px;
      background: #1a3a8c;
      border-radius: 14px 14px 0 0;
      margin-bottom: 0;
    }
    .org-category-header .cat-icon {
      width: 38px;
      height: 38px;
      background: rgba(255,255,255,0.15);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      color: #fff;
      flex-shrink: 0;
    }
    .org-category-header .cat-info {
      flex: 1;
    }
    .org-category-header .cat-title {
      font-size: 0.95rem;
      font-weight: 800;
      color: #fff;
      letter-spacing: 0.01em;
    }
    .org-category-header .cat-sub {
      font-size: 0.72rem;
      color: rgba(255,255,255,0.72);
      margin-top: 2px;
    }
    .org-category-header .cat-count {
      background: rgba(255,255,255,0.2);
      color: #fff;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: 20px;
      border: 1px solid rgba(255,255,255,0.25);
    }
    .org-subcategory {
      background: #fff;
      border-top: none;
      padding: 16px 20px 8px;
    }
    .org-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 16px;
      width: 100%;
      padding-bottom: 8px;
    }
    .org-card {
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px 14px 14px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      cursor: default;
      transition: all 0.2s cubic-bezier(0.16,1,0.3,1);
      position: relative;
      overflow: hidden;
      min-height: 240px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .org-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: #2563eb;
      opacity: 0;
      transition: opacity 0.2s ease;
    }
    .org-card:hover {
      border-color: #93c5fd;
      background: #ffffff;
      box-shadow: 0 6px 20px rgba(37,99,235,0.12);
      transform: translateY(-2px);
    }
    .org-card:hover::before {
      opacity: 1;
    }
    .org-card-acronym {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #1a3a8c;
      color: #fff;
      font-size: 0.62rem;
      font-weight: 800;
      padding: 4px 8px;
      border-radius: 6px;
      letter-spacing: 0.04em;
      align-self: flex-start;
      max-width: 140px;
      text-align: center;
      line-height: 1.3;
    }
    .org-card-name {
      font-size: 0.78rem;
      font-weight: 700;
      color: #0f172a;
      line-height: 1.35;
    }
    .org-card-type {
      font-size: 0.68rem;
      color: #94a3b8;
      font-weight: 500;
    }
    .org-card-qr-btn {
      margin-top: auto;
      background: #2563eb !important;
      border: 1px solid #2563eb !important;
      color: #ffffff !important;
      border-radius: 8px;
      padding: 7px 12px;
      font-size: 0.74rem;
      font-weight: 700 !important;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      width: 100%;
      box-sizing: border-box;
      transition: all 0.15s;
    }
    .org-card-qr-btn:hover:not(:disabled) {
      filter: brightness(0.95);
      transform: translateY(-1px);
    }
    .org-card-qr-btn.org-status-pending {
      background: #fffbeb !important;
      border-color: #fde68a !important;
      color: #b45309 !important;
      cursor: default !important;
      box-shadow: none !important;
    }
    .org-card-qr-btn.org-status-member {
      background: #f0fdf4 !important;
      border-color: #bbf7d0 !important;
      color: #166534 !important;
      cursor: default !important;
      box-shadow: none !important;
    }

    /* ── Empty State ── */
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

    /* ── Buttons & Badges ── */
    .vol-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: 10px;
      font-size: 0.86rem;
      font-weight: 700;
      cursor: pointer;
      border: none;
      transition: all 0.2s ease;
      text-decoration: none;
      font-family: inherit;
    }
    .vol-btn-primary {
      background: #2563eb;
      color: #ffffff;
      box-shadow: 0 2px 8px rgba(37,99,235,0.25);
    }
    .vol-btn-primary:hover {
      background: #1d4ed8;
      transform: translateY(-1px);
      box-shadow: 0 4px 14px rgba(37,99,235,0.35);
    }
    .vol-btn-secondary {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    .vol-btn-secondary:hover {
      background: #dbeafe;
      color: #1e40af;
    }
    .vol-btn-disabled {
      background: #f1f5f9;
      color: #94a3b8;
      border: 1px solid #cbd5e1;
      cursor: not-allowed;
    }

    .vol-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.75rem;
      font-weight: 800;
      padding: 4px 10px;
      border-radius: 20px;
      letter-spacing: 0.3px;
    }
    .vol-badge-verified { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
    .vol-badge-pending  { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
    .vol-badge-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

    /* ── Tabbed Navigation ── */
    .vol-nav-tabs {
      display: flex;
      gap: 10px;
      border-bottom: 2px solid #e2e8f0;
      margin-bottom: 22px;
      padding-bottom: 2px;
    }
    .vol-nav-tab {
      padding: 10px 18px;
      font-size: 0.88rem;
      font-weight: 700;
      color: #64748b;
      cursor: pointer;
      border-bottom: 3px solid transparent;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
      background: none;
      border-top: none;
      border-left: none;
      border-right: none;
    }
    .vol-nav-tab.active {
      color: #1e3a8a;
      border-bottom-color: #2563eb;
    }
    .vol-nav-tab:hover:not(.active) {
      color: #0f172a;
    }

    /* ── Modals ── */
    .modal-overlay {
      position: fixed; inset: 0;
      background: rgba(15,23,42,0.68);
      backdrop-filter: blur(4px);
      display: none; align-items: center; justify-content: center;
      z-index: 99999; padding: 20px; overflow-y: auto;
    }
    .modal-card {
      background: #ffffff;
      border-radius: 18px;
      max-width: 640px;
      width: 100%;
      box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
      border: 1px solid #e2e8f0;
      position: relative;
      overflow: hidden;
      margin: auto;
    }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../shared/sidebar.php'; ?>

<div class="main">
  <!-- Topbar -->
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
  </div><!-- end topbar -->

  <div class="content">

    <!-- Page Title Bar (Full width flush with topbar) -->
    <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <div>
        <h2 class="page-title" style="margin:0; font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:10px;">
          <i class="fa-solid fa-hand-holding-heart" style="color:#2563eb;"></i>
          <?= $sess_role === 'student' ? 'Event Volunteer Hours &amp; Community Service' : ($sess_role === 'club_adviser' ? htmlspecialchars($adviser_club['code'] ?? 'Org') . ' Volunteer Verifications' : 'Volunteer Hours &amp; Community Service') ?>
        </h2>
        <div style="font-size:0.8rem; color:#64748b; margin-top:3px;">
          <?php if ($sess_role === 'student'): ?>
            Enrolled Program: <strong style="color:#1e293b;"><?= htmlspecialchars($student_course) ?></strong> (<?= htmlspecialchars($student_year) ?>)
            <?php if (!empty($student_member_clubs)): ?>
              &bull; Member of:
              <?php foreach ($student_member_clubs as $sc): ?>
                <span class="vol-badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:0.75rem; padding:2px 8px; margin-left:4px;">
                  <i class="fa-solid fa-check"></i> <?= htmlspecialchars($sc['name']) ?> (Adviser: <?= htmlspecialchars($sc['adviser_name'] ?: trim($sc['adv_first'] . ' ' . $sc['adv_last']) ?: 'Club Adviser') ?>)
                </span>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php else: ?>
            Official Co-Curricular Student Development &amp; Hours Accreditation
          <?php endif; ?>
        </div>
      </div>
      <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
        <div style="display:flex; gap:10px; align-items:center;">
          <button class="vol-btn vol-btn-primary" onclick="openCreateOpportunityModal()">
            <i class="fa-solid fa-plus-circle"></i> Post Volunteer Role
          </button>
        </div>
      <?php endif; ?>
    </div>

    <div class="content-body vol-container">

      <?php if ($sess_role === 'student' && !$student_is_org_member): ?>
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; padding:60px 24px; text-align:center; max-width:620px; margin:40px auto; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
          <div style="width:72px; height:72px; border-radius:50%; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:2rem; margin:0 auto 20px;">
            <i class="fa-solid fa-hand-holding-heart"></i>
          </div>
          <h3 style="font-size:1.35rem; font-weight:800; color:#0f172a; margin-bottom:12px;">Organization Membership Required</h3>
          <p style="color:#64748b; font-size:0.92rem; line-height:1.6; margin-bottom:24px;">
            The <strong>Volunteer Hours &amp; Community Service</strong> module is reserved for members of an accredited student organization. To participate in event volunteer activities and earn official verified service hours, your membership application must first be approved by the Club Adviser.
          </p>
          <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
            <a href="club_directory.php" class="vol-btn vol-btn-primary" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:8px; font-weight:700;">
              <i class="fa-solid fa-sitemap"></i> Explore Student Organizations
            </a>
            <a href="dashboard.php" class="vol-btn vol-btn-secondary" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:8px; font-weight:600;">
              <i class="fa-solid fa-arrow-left"></i> Return to Dashboard
            </a>
          </div>
        </div>
      <?php else: ?>

      <!-- KPI Summary Cards -->
      <div class="vol-kpi-grid">
        <?php if ($sess_role === 'student'): ?>
          <div class="vol-kpi-card" style="cursor:pointer;" onclick="openMyServiceLogModal()" title="View Verified Service Hours and Records">
            <div class="vol-kpi-icon" style="background:#eff6ff; color:#1d4ed8;"><i class="fa-solid fa-award"></i></div>
            <div>
              <div class="vol-kpi-val" style="color:#1d4ed8;"><?= number_format($total_verified_hours, 1) ?>h</div>
              <div class="vol-kpi-lbl">Total Verified Hours</div>
            </div>
          </div>
          <div class="vol-kpi-card">
            <div class="vol-kpi-icon" style="background:#fef3c7; color:#b45309;"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
              <div class="vol-kpi-val" style="color:#b45309;"><?= number_format($total_pending_hours, 1) ?>h</div>
              <div class="vol-kpi-lbl">Pending Adviser Review</div>
            </div>
          </div>
          <div class="vol-kpi-card">
            <div class="vol-kpi-icon" style="background:#eff6ff; color:#2563eb;"><i class="fa-solid fa-hand-holding-heart"></i></div>
            <div>
              <div class="vol-kpi-val" style="color:#2563eb;"><?= count($opportunities) ?></div>
              <div class="vol-kpi-lbl">Event Opportunities</div>
            </div>
          </div>
          <div class="vol-kpi-card" style="cursor:pointer;" onclick="openMyServiceLogModal()" title="View My Volunteer Applications and Receipts">
            <div class="vol-kpi-icon" style="background:#eff6ff; color:#1e40af;"><i class="fa-solid fa-list-check"></i></div>
            <div>
              <div class="vol-kpi-val" style="color:#1e40af;"><?= count($my_volunteer_logs) ?></div>
              <div class="vol-kpi-lbl">My Applications</div>
            </div>
          </div>
        <?php else: ?>
          <div class="vol-kpi-card">
            <div class="vol-kpi-icon" style="background:#fef3c7; color:#b45309;"><i class="fa-solid fa-user-clock"></i></div>
            <div>
              <div class="vol-kpi-val" style="color:#b45309;"><?= count($adviser_pending_queue) ?></div>
              <div class="vol-kpi-lbl">Pending Verifications</div>
            </div>
          </div>
          <div class="vol-kpi-card">
            <div class="vol-kpi-icon" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-circle-check"></i></div>
            <div>
              <div class="vol-kpi-val" style="color:#15803d;"><?= count($adviser_verified_list) ?></div>
              <div class="vol-kpi-lbl">Verified Volunteers</div>
            </div>
          </div>
          <div class="vol-kpi-card">
            <div class="vol-kpi-icon" style="background:#eff6ff; color:#1d4ed8;"><i class="fa-solid fa-clock"></i></div>
            <div>
              <div class="vol-kpi-val"><?= number_format($adviser_total_hours_approved, 1) ?>h</div>
              <div class="vol-kpi-lbl">Total Hours Credited</div>
            </div>
          </div>
          <div class="vol-kpi-card">
            <div class="vol-kpi-icon" style="background:#f5f3ff; color:#7c3aed;"><i class="fa-solid fa-calendar-star"></i></div>
            <div>
              <div class="vol-kpi-val"><?= count($opportunities) ?></div>
              <div class="vol-kpi-lbl">Available Role Posts</div>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Navigation Tabs (Only for Faculty Advisers and Admin Queue) -->
      <?php if ($sess_role !== 'student'): ?>
      <div class="vol-nav-tabs">
        <button class="vol-nav-tab active" id="tabBtnPending" onclick="switchVolTab('pending')">
          <i class="fa-solid fa-clock-rotate-left"></i> Pending Verifications Queue (<?= count($adviser_pending_queue) ?>)
        </button>
        <button class="vol-nav-tab" id="tabBtnOpportunities" onclick="switchVolTab('opportunities')">
          <i class="fa-solid fa-grip"></i> Event Role Postings (<?= count($opportunities) ?>)
        </button>
        <button class="vol-nav-tab" id="tabBtnVerifiedRoster" onclick="switchVolTab('verifiedRoster')">
          <i class="fa-solid fa-clipboard-check"></i> Verified Volunteers Roster (<?= count($adviser_verified_list) ?>)
        </button>
      </div>
      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════════
           TAB 1: EVENT VOLUNTEER OPPORTUNITIES (PER CARD)
      ══════════════════════════════════════════════════════════ -->
      <div id="tabContentOpportunities" style="display:<?= $sess_role === 'student' ? 'block' : 'none' ?>;">
        
        <?php if (empty($opportunities)): ?>
          <div style="background:#ffffff; border:1.5px dashed #cbd5e1; border-radius:16px; padding:48px 20px; text-align:center; color:#64748b;">
            <i class="fa-solid fa-calendar-xmark" style="font-size:2.8rem; color:#cbd5e1; margin-bottom:14px; display:block;"></i>
            <h3 style="margin:0 0 6px 0; color:#0f172a;">No Active Volunteer Roles Posted</h3>
            <p style="margin:0; font-size:0.88rem;">
              <?= !empty($student_member_clubs) ? 'The adviser of your organization (' . htmlspecialchars(implode(', ', array_column($student_member_clubs, 'name'))) . ') has not posted any active volunteer opportunities yet.' : 'Approved event volunteer roles will appear here once published by your organization adviser.' ?>
            </p>
          </div>
        <?php else: ?>
          <div class="org-cards-grid" id="volCardsGrid">
            <?php foreach ($opportunities as $opp): ?>
              <?= renderVolunteerOppCard($opp, $my_applied_opps[$opp['id']] ?? null, $student_course, $sess_role) ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </div><!-- end tab opportunities -->





      <!-- ══════════════════════════════════════════════════════════
           TAB 3: ADVISER PENDING VERIFICATION QUEUE
      ══════════════════════════════════════════════════════════ -->
      <?php if ($sess_role !== 'student'): ?>
      <div id="tabContentPending" style="display:block;">
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
            <div>
              <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;">
                <i class="fa-solid fa-user-clock" style="color:#d97706;"></i> 
                Student Volunteer Applications Awaiting Adviser Verification
              </h3>
              <p style="margin:3px 0 0 0; font-size:0.8rem; color:#64748b;">
                Verify student eligibility based on enrolled course and program before approving credited co-curricular hours.
              </p>
            </div>
            <div>
              <span class="vol-badge vol-badge-pending" style="font-size:0.85rem; padding:6px 14px;">
                <?= count($adviser_pending_queue) ?> Pending Action
              </span>
            </div>
          </div>

          <?php if (empty($adviser_pending_queue)): ?>
            <div style="text-align:center; padding:50px 20px; color:#64748b; border:1.5px dashed #cbd5e1; border-radius:12px;">
              <i class="fa-solid fa-circle-check" style="font-size:2.8rem; color:#86efac; margin-bottom:12px; display:block;"></i>
              <h4 style="margin:0 0 4px 0; color:#0f172a;">All Caught Up!</h4>
              <p style="margin:0; font-size:0.85rem;">No student volunteer requests are currently awaiting adviser verification.</p>
            </div>
          <?php else: ?>
            <div class="table-wrap" style="overflow-x:auto;">
              <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                  <tr style="background:#f8fafc; color:#475569; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0;">
                    <th style="padding:12px 14px; text-align:left;">Student Volunteer</th>
                    <th style="padding:12px 14px; text-align:left;">Course &bull; Program Match</th>
                    <th style="padding:12px 14px; text-align:left;">Event &bull; Role</th>
                    <th style="padding:12px 14px; text-align:center;">Hours</th>
                    <th style="padding:12px 14px; text-align:center;">Date Submitted</th>
                    <th style="padding:12px 14px; text-align:right;">Adviser Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($adviser_pending_queue as $q): ?>
                    <tr style="border-bottom:1px solid #f1f5f9;" id="pendingRow_<?= $q['id'] ?>">
                      <td style="padding:14px;">
                        <div style="font-weight:800; color:#0f172a; font-size:0.92rem;"><?= htmlspecialchars($q['student_name']) ?></div>
                        <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">ID: <strong><?= htmlspecialchars($q['student_number'] ?: 'N/A') ?></strong></div>
                      </td>
                      <td style="padding:14px;">
                        <div style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($q['course'] ?: 'General Program') ?></div>
                        <div style="font-size:0.75rem; margin-top:3px;">
                          <?php if (!empty($q['program_match'])): ?>
                            <span style="color:#15803d; font-weight:700;"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($q['program_match_note']) ?></span>
                          <?php else: ?>
                            <span style="color:#b45309; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($q['program_match_note'] ?? 'Non-Program Applicant') ?></span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td style="padding:14px;">
                        <div style="font-weight:800; color:#1e3a8a; font-size:0.9rem;"><?= htmlspecialchars($q['volunteer_role']) ?></div>
                        <div style="font-size:0.78rem; color:#475569; margin-top:2px; font-weight:600;">
                          <i class="fa-solid fa-calendar-day"></i> <?= htmlspecialchars($q['event_title']) ?>
                        </div>
                        <?php if (!empty($q['notes'])): ?>
                          <div style="font-size:0.72rem; color:#64748b; font-style:italic; margin-top:3px;">
                            "<?= htmlspecialchars($q['notes']) ?>"
                          </div>
                        <?php endif; ?>
                      </td>
                      <td style="padding:14px; text-align:center;">
                        <span style="font-weight:900; color:#15803d; font-size:1rem;"><?= number_format((float)$q['hours'], 1) ?>h</span>
                      </td>
                      <td style="padding:14px; text-align:center; color:#64748b; font-size:0.8rem;">
                        <?= date('M d, Y', strtotime($q['created_at'])) ?>
                      </td>
                      <td style="padding:14px; text-align:right;">
                        <div style="display:flex; justify-content:flex-end; gap:8px;">
                          <button type="button" class="vol-btn vol-btn-primary" style="padding:7px 14px; font-size:0.8rem;" 
                                  onclick="openVerifyModal(<?= (int)$q['id'] ?>, '<?= htmlspecialchars(addslashes($q['student_name'])) ?>', '<?= htmlspecialchars(addslashes($q['volunteer_role'])) ?>', '<?= htmlspecialchars(addslashes($q['event_title'])) ?>', <?= (float)$q['hours'] ?>)">
                            <i class="fa-solid fa-check"></i> Verify Role
                          </button>
                          <button type="button" class="vol-btn" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:7px 12px; font-size:0.8rem;" 
                                  onclick="rejectVolunteerApplication(<?= (int)$q['id'] ?>, '<?= htmlspecialchars(addslashes($q['student_name'])) ?>')">
                            <i class="fa-solid fa-xmark"></i> Reject
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
      </div>

      <!-- TAB 4: ADVISER VERIFIED VOLUNTEERS ROSTER -->
      <div id="tabContentVerifiedRoster" style="display:none;">
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
            <div>
              <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;">Official Verified Volunteers Roster</h3>
              <p style="margin:3px 0 0 0; font-size:0.8rem; color:#64748b;">Permanent co-curricular volunteer hours record accredited by faculty advisers.</p>
            </div>
            <div>
              <span class="vol-badge vol-badge-verified" style="font-size:0.85rem; padding:6px 14px;">
                <i class="fa-solid fa-circle-check"></i> <?= count($adviser_verified_list) ?> Verified Records
              </span>
            </div>
          </div>

          <?php if (empty($adviser_verified_list)): ?>
            <div style="text-align:center; padding:45px 20px; color:#64748b; border:1.5px dashed #cbd5e1; border-radius:12px;">
              <i class="fa-solid fa-folder-open" style="font-size:2.5rem; color:#cbd5e1; margin-bottom:10px; display:block;"></i>
              <h4 style="margin:0 0 4px 0; color:#0f172a;">No Verified Records Found</h4>
              <p style="margin:0; font-size:0.85rem;">Once pending applications are verified, they will be archived here.</p>
            </div>
          <?php else: ?>
            <div class="table-wrap" style="overflow-x:auto;">
              <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                  <tr style="background:#f8fafc; color:#475569; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0;">
                    <th style="padding:12px 14px; text-align:left;">Student Name &bull; ID</th>
                    <th style="padding:12px 14px; text-align:left;">Course / Program</th>
                    <th style="padding:12px 14px; text-align:left;">Event &bull; Role</th>
                    <th style="padding:12px 14px; text-align:center;">Hours Credited</th>
                    <th style="padding:12px 14px; text-align:center;">Verified At</th>
                    <th style="padding:12px 14px; text-align:right;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($adviser_verified_list as $v): ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                      <td style="padding:14px;">
                        <div style="font-weight:800; color:#0f172a;"><?= htmlspecialchars($v['student_name']) ?></div>
                        <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">ID: <?= htmlspecialchars($v['student_number'] ?: 'N/A') ?></div>
                      </td>
                      <td style="padding:14px; color:#334155; font-weight:600;">
                        <?= htmlspecialchars($v['course'] ?: 'General Program') ?>
                      </td>
                      <td style="padding:14px;">
                        <div style="font-weight:800; color:#1e3a8a;"><?= htmlspecialchars($v['volunteer_role']) ?></div>
                        <div style="font-size:0.75rem; color:#64748b; margin-top:2px;"><?= htmlspecialchars($v['event_title']) ?></div>
                      </td>
                      <td style="padding:14px; text-align:center;">
                        <span style="font-weight:900; color:#15803d; font-size:0.95rem;"><?= number_format((float)$v['hours'], 1) ?>h</span>
                      </td>
                      <td style="padding:14px; text-align:center; color:#64748b; font-size:0.8rem;">
                        <?= $v['verified_at'] ? date('M d, Y', strtotime($v['verified_at'])) : 'Verified' ?>
                      </td>
                      <td style="padding:14px; text-align:right;">
                        <span class="vol-badge vol-badge-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; /* end student_is_org_member check */ ?>
    </div><!-- end content-body -->
  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026 Bestlink College of the Philippines</div>
</div><!-- end main -->


<?php if ($sess_role === 'student'): ?>
<!-- ══════════════════════════════════════════════════════════════
     MODAL 0: STUDENT SERVICE LOG & OFFICIAL RECEIPTS
══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="myServiceLogModal" onclick="if(event.target === this) closeModal('myServiceLogModal')">
  <div class="modal-card" style="max-width:920px; width:95%; max-height:90vh; overflow-y:auto; padding:0 !important;">
    <div style="height:6px; background:linear-gradient(90deg, #1e3a8a, #2563eb);"></div>
    <div style="padding:24px 28px;">
      <button type="button" onclick="closeModal('myServiceLogModal')" style="position:absolute; top:18px; right:18px; background:#f1f5f9; border:none; width:34px; height:34px; border-radius:50%; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>

      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px; padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div style="width:42px; height:42px; border-radius:12px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.25rem;">
            <i class="fa-solid fa-receipt"></i>
          </div>
          <div>
            <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;">Official Volunteer Service History &amp; Credited Hours</h3>
            <p style="margin:2px 0 0 0; font-size:0.78rem; color:#64748b;">Every verified co-curricular role carries an official verification receipt and accreditation certificate.</p>
          </div>
        </div>
        <div>
          <span class="vol-badge" style="font-size:0.85rem; padding:6px 14px; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;">
            <i class="fa-solid fa-award"></i> Total Credited: <?= number_format($total_verified_hours, 1) ?> Hours
          </span>
        </div>
      </div>

      <?php if (empty($my_volunteer_logs)): ?>
        <div style="text-align:center; padding:45px 20px; color:#64748b; border:1.5px dashed #cbd5e1; border-radius:12px;">
          <i class="fa-solid fa-inbox" style="font-size:2.5rem; color:#cbd5e1; margin-bottom:10px; display:block;"></i>
          <h4 style="margin:0 0 4px 0; color:#0f172a;">No Volunteer Applications Yet</h4>
          <p style="margin:0; font-size:0.85rem;">Browse available event cards on the main page to volunteer your service.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap" style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
              <tr style="background:#f8fafc; color:#475569; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0;">
                <th style="padding:12px 14px; text-align:left;">Event &bull; Role</th>
                <th style="padding:12px 14px; text-align:left;">Organization</th>
                <th style="padding:12px 14px; text-align:center;">Date Applied</th>
                <th style="padding:12px 14px; text-align:center;">Hours</th>
                <th style="padding:12px 14px; text-align:center;">Status</th>
                <th style="padding:12px 14px; text-align:right;">Verification</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($my_volunteer_logs as $log): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                  <td style="padding:14px;">
                    <div style="font-weight:800; color:#0f172a; font-size:0.92rem;"><?= htmlspecialchars($log['volunteer_role']) ?></div>
                    <div style="font-size:0.78rem; color:#2563eb; font-weight:600; margin-top:2px;">
                      <i class="fa-solid fa-calendar-day"></i> <?= htmlspecialchars($log['event_title']) ?>
                    </div>
                  </td>
                  <td style="padding:14px; font-weight:600; color:#334155;">
                    <span style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; font-weight:800; padding:2px 7px; border-radius:6px; font-size:0.75rem; margin-right:6px;">
                      <?= htmlspecialchars($log['club_code'] ?: 'BCP') ?>
                    </span>
                    <?= htmlspecialchars($log['club_name'] ?: 'Bestlink College') ?>
                  </td>
                  <td style="padding:14px; text-align:center; color:#64748b; font-size:0.8rem;">
                    <?= date('M d, Y', strtotime($log['created_at'])) ?>
                  </td>
                  <td style="padding:14px; text-align:center;">
                    <span style="font-weight:900; color:#0f172a; font-size:0.95rem;"><?= number_format((float)$log['hours'], 1) ?>h</span>
                  </td>
                  <td style="padding:14px; text-align:center;">
                    <?php if ($log['status'] === 'Verified'): ?>
                      <span class="vol-badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;"><i class="fa-solid fa-circle-check"></i> Verified</span>
                    <?php elseif ($log['status'] === 'Pending Verification'): ?>
                      <span class="vol-badge vol-badge-pending"><i class="fa-solid fa-clock-rotate-left"></i> Awaiting Review</span>
                    <?php else: ?>
                      <span class="vol-badge vol-badge-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding:14px; text-align:right;">
                    <?php if ($log['status'] === 'Verified'): ?>
                      <span class="vol-badge vol-badge-verified"><i class="fa-solid fa-circle-check"></i> Credited</span>
                    <?php else: ?>
                      <span style="font-size:0.75rem; color:#94a3b8; font-style:italic;">Pending Review</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div style="display:flex; justify-content:flex-end; margin-top:20px; padding-top:14px; border-top:1px solid #e2e8f0;">
        <button type="button" class="vol-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('myServiceLogModal')">Close</button>
      </div>

    </div>
  </div>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════════
     MODAL 1: STUDENT VOLUNTEER APPLICATION MODAL
══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="applyVolunteerModal" onclick="if(event.target === this) closeModal('applyVolunteerModal')">
  <div class="modal-card">
    <div style="height:6px; background:linear-gradient(90deg, #1e3a8a, #2563eb);"></div>
    <div style="padding:26px 28px;">
      <button type="button" onclick="closeModal('applyVolunteerModal')" style="position:absolute; top:18px; right:18px; background:#f1f5f9; border:none; width:34px; height:34px; border-radius:50%; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>

      <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px; padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;">
        <div style="width:44px; height:44px; border-radius:12px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
          <i class="fa-solid fa-hand-holding-heart"></i>
        </div>
        <div>
          <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;">Event Volunteer Application</h3>
          <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">Co-Curricular Community Service Hours Program</div>
        </div>
      </div>

      <form id="applyVolunteerForm" onsubmit="submitVolunteerApplication(event)">
        <input type="hidden" name="action" value="apply_volunteer"/>
        <input type="hidden" name="opportunity_id" id="applyOppId" value=""/>
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"/>

        <!-- Details Summary Box -->
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:18px; font-size:0.84rem;">
          <div style="margin-bottom:8px;">
            <span style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Event &bull; Organization</span>
            <div style="font-weight:800; color:#0f172a; font-size:0.95rem; margin-top:2px;" id="applyEventTitle"></div>
            <div style="font-size:0.78rem; color:#2563eb; font-weight:700;" id="applyOrgName"></div>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px; border-top:1px dashed #cbd5e1; padding-top:10px;">
            <div>
              <span style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Volunteer Role</span>
              <div style="font-weight:800; color:#1e3a8a; font-size:0.92rem; margin-top:2px;" id="applyRoleName"></div>
            </div>
            <div>
              <span style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Credit Hours</span>
              <div style="font-weight:900; color:#0f172a; font-size:0.95rem; margin-top:2px;" id="applyHours"></div>
            </div>
          </div>
        </div>

        <!-- Student Voter / Volunteer Profile -->
        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:12px 16px; margin-bottom:18px; font-size:0.8rem; color:#1e40af;">
          <div style="font-weight:800; margin-bottom:2px;"><i class="fa-solid fa-id-card"></i> Student Applicant Profile:</div>
          <div><?= htmlspecialchars($student_info['first_name'] ?? $sess_first) ?> <?= htmlspecialchars($student_info['last_name'] ?? $sess_last) ?> &bull; <?= htmlspecialchars($student_course) ?> &bull; <?= htmlspecialchars($student_year) ?></div>
        </div>

        <!-- Optional Notes / Availability -->
        <div class="form-group" style="margin-bottom:20px;">
          <label style="display:block; font-size:0.78rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px;">
            Applicant Notes / Prior Experience (Optional)
          </label>
          <textarea name="notes" rows="3" placeholder="Briefly describe your availability or relevant background..." style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-family:inherit; font-size:0.85rem; box-sizing:border-box;"></textarea>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:16px;">
          <button type="button" class="vol-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('applyVolunteerModal')">Cancel</button>
          <button type="submit" id="applySubmitBtn" class="vol-btn vol-btn-primary">
            <i class="fa-solid fa-paper-plane"></i> Submit Volunteer Application
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     MODAL 2: ADVISER VOLUNTEER VERIFICATION MODAL
══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="verifyVolunteerModal" onclick="if(event.target === this) closeModal('verifyVolunteerModal')">
  <div class="modal-card">
    <div style="height:6px; background:linear-gradient(90deg, #1e3a8a, #2563eb);"></div>
    <div style="padding:26px 28px;">
      <button type="button" onclick="closeModal('verifyVolunteerModal')" style="position:absolute; top:18px; right:18px; background:#f1f5f9; border:none; width:34px; height:34px; border-radius:50%; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>

      <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px; padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;">
        <div style="width:44px; height:44px; border-radius:12px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
          <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
          <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;">Adviser Volunteer Verification</h3>
          <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">Faculty Oversight &amp; Service Hours Accreditation</div>
        </div>
      </div>

      <form id="verifyVolunteerForm" onsubmit="submitAdviserVerification(event)">
        <input type="hidden" name="action" value="verify_volunteer"/>
        <input type="hidden" name="log_id" id="verifyLogId" value=""/>
        <input type="hidden" name="decision" value="Verified"/>
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"/>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:18px; font-size:0.84rem;">
          <div style="margin-bottom:8px;">
            <span style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Student Volunteer</span>
            <div style="font-weight:900; color:#0f172a; font-size:1rem; margin-top:2px;" id="verifyStudentName"></div>
          </div>
          <div style="margin-bottom:8px;">
            <span style="font-size:0.72rem; text-transform:uppercase; font-weight:800; color:#64748b;">Event &bull; Assigned Role</span>
            <div style="font-weight:800; color:#1e3a8a; margin-top:2px;" id="verifyEventRole"></div>
          </div>
          <div style="border-top:1px dashed #cbd5e1; padding-top:10px; margin-top:10px;">
            <label style="display:block; font-size:0.75rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:4px;">
              Service Hours to Credit:
            </label>
            <input type="number" step="0.5" min="0.5" max="50" name="hours" id="verifyHoursInput" style="width:120px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-weight:800; font-size:0.95rem; color:#15803d;"/>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:20px;">
          <label style="display:block; font-size:0.78rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px;">
            Adviser Evaluation / Verification Remarks
          </label>
          <input type="text" name="verification_notes" value="Satisfactory volunteer performance verified by Organization Faculty Adviser." style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-family:inherit; font-size:0.85rem; box-sizing:border-box;"/>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:16px;">
          <button type="button" class="vol-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('verifyVolunteerModal')">Cancel</button>
          <button type="submit" id="verifySubmitBtn" class="vol-btn vol-btn-primary">
            <i class="fa-solid fa-stamp"></i> Officially Verify &amp; Credit Hours
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     MODAL 3: OFFICIAL DIGITAL VOLUNTEER RECEIPT / CERTIFICATE
══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="volunteerReceiptModal" style="z-index:100001;" onclick="if(event.target === this) closeModal('volunteerReceiptModal')">
  <div class="modal-card" style="max-width:680px; padding:0 !important;">
    <div style="height:6px; background:linear-gradient(90deg, #1e3a8a, #2563eb);"></div>
    <div style="padding:28px 32px;">
      <button type="button" onclick="closeModal('volunteerReceiptModal')" style="position:absolute; top:18px; right:18px; background:#f1f5f9; border:none; width:34px; height:34px; border-radius:50%; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close Receipt" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>

      <!-- Institutional Header -->
      <div style="text-align:center; padding-bottom:18px; border-bottom:2px dashed #cbd5e1; margin-bottom:20px;">
        <div style="display:inline-flex; align-items:center; justify-content:center; gap:10px; margin-bottom:6px;">
          <div style="width:42px; height:42px; border-radius:50%; background:#1e3a8a; color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
            <i class="fa-solid fa-hand-holding-heart"></i>
          </div>
          <div style="text-align:left;">
            <div style="font-size:1.05rem; font-weight:900; letter-spacing:0.5px; color:#0f172a; line-height:1.2;">BESTLINK COLLEGE OF THE PHILIPPINES</div>
            <div style="font-size:0.75rem; color:#64748b; font-weight:600;">Office of Student Affairs &bull; Co-Curricular Volunteer Services</div>
          </div>
        </div>
        <div>
          <div style="display:inline-block; margin-top:8px; background:#eff6ff; border:1.5px solid #bfdbfe; padding:6px 18px; border-radius:20px;">
            <span style="font-size:0.82rem; font-weight:800; color:#1d4ed8; letter-spacing:0.5px; display:inline-flex; align-items:center; gap:6px;">
              <i class="fa-solid fa-circle-check" style="color:#2563eb;"></i> OFFICIAL VOLUNTEER SERVICE CERTIFICATE
            </span>
          </div>
        </div>
        <div style="font-size:0.75rem; color:#64748b; margin-top:4px;">Electronic Community Service Record &bull; Faculty Adviser Verified</div>
      </div>

      <!-- Dynamic Receipt Body -->
      <div id="volunteerReceiptBody">
        <div style="text-align:center; padding:35px 20px; color:#64748b;">
          <i class="fa-solid fa-spinner fa-spin" style="font-size:1.8rem; color:#2563eb; margin-bottom:10px; display:block;"></i>
          Loading official volunteer verification record...
        </div>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:22px; padding-top:16px; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:10px;">
        <div style="font-size:0.75rem; color:#64748b;">
          <i class="fa-solid fa-lock" style="color:#2563eb;"></i> Cryptographically verified with SHA-256 seal
        </div>
        <div style="display:flex; gap:10px;">
          <button type="button" class="vol-btn vol-btn-primary" onclick="printVolunteerReceipt()">
            <i class="fa-solid fa-print"></i> Print / Save PDF
          </button>
          <button type="button" class="vol-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('volunteerReceiptModal')">
            Close
          </button>
        </div>
      </div>

    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     MODAL 4: CREATE VOLUNTEER OPPORTUNITY (Advisers & Admins)
══════════════════════════════════════════════════════════════ -->
<?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
<div class="modal-overlay" id="createOpportunityModal" onclick="if(event.target === this) closeModal('createOpportunityModal')">
  <div class="modal-card">
    <div style="height:6px; background:linear-gradient(90deg, #1e3a8a, #2563eb);"></div>
    <div style="padding:26px 28px;">
      <button type="button" onclick="closeModal('createOpportunityModal')" style="position:absolute; top:18px; right:18px; background:#f1f5f9; border:none; width:34px; height:34px; border-radius:50%; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>

      <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px; padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;">
        <div style="width:44px; height:44px; border-radius:12px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
          <i class="fa-solid fa-plus-circle"></i>
        </div>
        <div>
          <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;">Post Event Volunteer Role</h3>
          <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">Publish roles and recruit students based on academic program</div>
        </div>
      </div>

      <form id="createOpportunityForm" onsubmit="submitCreateOpportunity(event)">
        <input type="hidden" name="action" value="create_opportunity"/>
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"/>

        <div class="form-group">
          <label style="display:block; font-size:0.78rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px;">Select Event</label>
          <select name="event_id" required style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:0.88rem;">
            <?php
              $v_ev_res = $conn->query("
                SELECT e.id, e.title, e.event_date, e.event_type, e.target_course,
                       c.name as club_name, c.code as club_code, c.program as club_program, c.category as club_category
                FROM events e
                LEFT JOIN clubs c ON c.id = e.club_id
                WHERE e.status IN ('Approved', 'Upcoming')
                ORDER BY e.event_date ASC
              ");
              $vol_grouped = [];
              if ($v_ev_res) {
                while ($vev = $v_ev_res->fetch_assoc()) {
                  $prog = trim($vev['club_program'] ?? '');
                  $targetCourse = trim($vev['target_course'] ?? '');
                  $evType = $vev['event_type'] ?? 'Club';
                  
                  if (!empty($prog) && !in_array($prog, ['General', 'All Programs', 'All Courses', 'Non-Academic'])) {
                    $groupName = $prog;
                  } elseif (!empty($targetCourse) && !in_array($targetCourse, ['All Courses', 'All Programs', 'All Organizations'])) {
                    $groupName = $targetCourse;
                  } elseif ($evType === 'Institutional' || ($vev['club_category'] ?? '') !== 'Academic') {
                    $groupName = 'Institutional & Campus-Wide';
                  } else {
                    $groupName = 'General & Co-Curricular';
                  }
                  
                  if (str_contains($groupName, 'Information Technology') || $groupName === 'BSIT' || ($vev['club_code'] ?? '') === 'ITS') {
                    $optGroup = '💻 BSIT — Information Technology';
                  } elseif (str_contains($groupName, 'Hospitality') || $groupName === 'BSHM' || ($vev['club_code'] ?? '') === 'TECHs') {
                    $optGroup = '🏨 BSHM — Hospitality Management';
                  } elseif (str_contains($groupName, 'Tourism') || $groupName === 'BSTM' || ($vev['club_code'] ?? '') === 'TTS') {
                    $optGroup = '✈️ BSTM — Tourism Management';
                  } elseif (str_contains($groupName, 'Accounting') || $groupName === 'BSAIS' || ($vev['club_code'] ?? '') === 'AISS') {
                    $optGroup = '📊 BSAIS — Accounting Info Systems';
                  } elseif (str_contains($groupName, 'Computer Engineering') || $groupName === 'BSCpE' || ($vev['club_code'] ?? '') === 'ACES' || ($vev['club_code'] ?? '') === 'ACADS') {
                    $optGroup = '⚙️ BSCpE — Computer Engineering';
                  } elseif (str_contains($groupName, 'Computer Science') || $groupName === 'BSCS' || ($vev['club_code'] ?? '') === 'CSSEC') {
                    $optGroup = '💻 CSSEC — Computer Science';
                  } elseif (str_contains($groupName, 'Criminology') || $groupName === 'BSCrim' || ($vev['club_code'] ?? '') === 'CJSU') {
                    $optGroup = '⚖️ BSCrim — Criminology';
                  } elseif (str_contains($groupName, 'Psychology') || $groupName === 'BSPsych' || ($vev['club_code'] ?? '') === 'PsychSoc') {
                    $optGroup = '🧠 BSPsych — Psychology';
                  } elseif (str_contains($groupName, 'Business') || $groupName === 'BSBA' || ($vev['club_code'] ?? '') === 'JMA' || ($vev['club_code'] ?? '') === 'JFINEX') {
                    $optGroup = '💼 BSBA — Business Administration';
                  } elseif (str_contains($groupName, 'Office') || $groupName === 'BSOA') {
                    $optGroup = '🗂️ BSOA — Office Administration';
                  } elseif (str_contains($groupName, 'Education') || str_contains($groupName, 'BSEd') || str_contains($groupName, 'BSElEd') || str_contains($groupName, 'TLE')) {
                    $optGroup = '📚 Education & Teacher Training';
                  } elseif ($groupName === 'Institutional & Campus-Wide') {
                    $optGroup = '🏛️ Institutional & Campus-Wide';
                  } else {
                    $optGroup = '🎓 ' . $groupName;
                  }
                  $vol_grouped[$optGroup][] = $vev;
                }
              }
              if (empty($vol_grouped)) {
                echo "<option value=''>No Active Events Found</option>";
              } else {
                foreach ($vol_grouped as $grpLabel => $evList) {
                  echo "<optgroup label='" . htmlspecialchars($grpLabel) . "'>";
                  foreach ($evList as $vev) {
                    echo "<option value='{$vev['id']}'>" . htmlspecialchars($vev['title']) . " (" . date('M d, Y', strtotime($vev['event_date'])) . ")</option>";
                  }
                  echo "</optgroup>";
                }
              }
            ?>
          </select>
        </div>

        <div class="form-group">
          <label style="display:block; font-size:0.78rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px;">Posting Title</label>
          <input type="text" name="title" required placeholder="e.g. Hackathon Stage & Network Crew" style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:0.88rem; box-sizing:border-box;"/>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div class="form-group">
            <label style="display:block; font-size:0.78rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px;">Official Role Name</label>
            <input type="text" name="role_name" required placeholder="e.g. Technical Support Lead" style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:0.88rem; box-sizing:border-box;"/>
          </div>
          <div class="form-group">
            <label style="display:block; font-size:0.78rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px;">Service Hours</label>
            <input type="number" step="0.5" name="hours" value="4.0" required style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:0.88rem; box-sizing:border-box;"/>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div class="form-group">
            <label style="display:block; font-size:0.78rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px;">Slots Needed</label>
            <input type="number" name="slots" value="8" required style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:0.88rem; box-sizing:border-box;"/>
          </div>
          <div class="form-group">
            <label style="display:block; font-size:0.78rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px;">Target Academic Program</label>
            <input type="text" name="target_program" value="All" placeholder="e.g. BSIT, BSCS, BSIS or All" style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:0.88rem; box-sizing:border-box;"/>
          </div>
        </div>

        <div class="form-group">
          <label style="display:block; font-size:0.78rem; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px;">Duties &amp; Volunteer Responsibilities</label>
          <textarea name="description" rows="3" placeholder="Describe the tasks expected from student volunteers..." style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-family:inherit; font-size:0.85rem; box-sizing:border-box;"></textarea>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:16px;">
          <button type="button" class="vol-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('createOpportunityModal')">Cancel</button>
          <button type="submit" class="vol-btn vol-btn-primary">
            <i class="fa-solid fa-plus-circle"></i> Publish Role Opportunity
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>


<script>
const sessRole = '<?= $sess_role ?>';
const csrfToken = '<?= csrf_token() ?>';
let currentReceiptData = null;

// Tab switcher
function switchVolTab(tab) {
  document.querySelectorAll('.vol-nav-tab').forEach(btn => btn.classList.remove('active'));
  
  const contentOpp = document.getElementById('tabContentOpportunities');
  const contentHist = document.getElementById('tabContentMyHistory');
  const contentPend = document.getElementById('tabContentPending');
  const contentRost = document.getElementById('tabContentVerifiedRoster');

  if (contentOpp) contentOpp.style.display = 'none';
  if (contentHist) contentHist.style.display = 'none';
  if (contentPend) contentPend.style.display = 'none';
  if (contentRost) contentRost.style.display = 'none';

  if (tab === 'opportunities') {
    const btn = document.getElementById('tabBtnOpportunities');
    if (btn) btn.classList.add('active');
    if (contentOpp) contentOpp.style.display = 'block';
  } else if (tab === 'myHistory') {
    const btn = document.getElementById('tabBtnMyHistory');
    if (btn) btn.classList.add('active');
    if (contentHist) contentHist.style.display = 'block';
  } else if (tab === 'pending') {
    const btn = document.getElementById('tabBtnPending');
    if (btn) btn.classList.add('active');
    if (contentPend) contentPend.style.display = 'block';
  } else if (tab === 'verifiedRoster') {
    const btn = document.getElementById('tabBtnVerifiedRoster');
    if (btn) btn.classList.add('active');
    if (contentRost) contentRost.style.display = 'block';
  }
}

// Organization Directory Style Filtering Logic
let currentVolCat = 'all';

function filterCat(cat, btn) {
  currentVolCat = cat;
  document.querySelectorAll('.cat-filter-pill').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  applyVolFilters();
}

function handleVolSearch(val) {
  const clearBtn = document.getElementById('clearVolSearchBtn');
  if (clearBtn) clearBtn.style.display = val.trim() ? 'flex' : 'none';
  applyVolFilters();
}

function clearVolSearch() {
  const input = document.getElementById('volSearchInput');
  if (input) input.value = '';
  const clearBtn = document.getElementById('clearVolSearchBtn');
  if (clearBtn) clearBtn.style.display = 'none';
  applyVolFilters();
}

function resetAllVolFilters() {
  const input = document.getElementById('volSearchInput');
  if (input) input.value = '';
  const clearBtn = document.getElementById('clearVolSearchBtn');
  if (clearBtn) clearBtn.style.display = 'none';
  const allBtn = document.querySelector('.cat-filter-pill[data-cat="all"]');
  filterCat('all', allBtn);
}

function applyVolFilters() {
  const query = (document.getElementById('volSearchInput')?.value || '').toLowerCase().trim();
  const cards = document.querySelectorAll('.vol-opp-card');
  let totalVisibleCards = 0;

  cards.forEach(card => {
    const searchBlob = card.getAttribute('data-search') || '';
    const cardCategory = card.getAttribute('data-category') || '';
    const isEligible = card.getAttribute('data-eligible') === '1';

    let matchesSearch = !query || searchBlob.includes(query);
    let matchesCategory = true;

    if (currentVolCat === 'eligible') {
      matchesCategory = isEligible;
    } else if (currentVolCat !== 'all') {
      matchesCategory = (cardCategory === currentVolCat);
    }

    if (matchesSearch && matchesCategory) {
      card.style.display = 'flex';
      totalVisibleCards++;
    } else {
      card.style.display = 'none';
    }
  });

  const noRes = document.getElementById('volNoResults');
  if (noRes) {
    noRes.style.display = (totalVisibleCards === 0) ? 'block' : 'none';
  }

  const countElem = document.getElementById('volCardsCount');
  if (countElem) {
    countElem.textContent = totalVisibleCards;
  }
}

function filterVolunteerCards() {
  applyVolFilters();
}

// Open Student Service Log & Certificates Modal
function openMyServiceLogModal() {
  const modal = document.getElementById('myServiceLogModal');
  if (modal) {
    modal.classList.add('active');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

// Open Student Application Modal
function openApplyVolunteerModal(oppId, roleName, eventTitle, clubName, clubCode, hours) {
  document.getElementById('applyOppId').value = oppId;
  document.getElementById('applyEventTitle').textContent = eventTitle;
  document.getElementById('applyOrgName').textContent = `${clubName} (${clubCode})`;
  document.getElementById('applyRoleName').textContent = roleName;
  document.getElementById('applyHours').textContent = `+${parseFloat(hours).toFixed(1)} Hours Credit`;

  const modal = document.getElementById('applyVolunteerModal');
  if (modal) {
    modal.classList.add('active');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

// Submit Volunteer Application (Student)
async function submitVolunteerApplication(e) {
  e.preventDefault();
  const btn = document.getElementById('applySubmitBtn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
  }

  const form = document.getElementById('applyVolunteerForm');
  const fd = new FormData(form);
  fd.append('csrf_token', csrfToken);

  try {
    const res = await fetch('../shared/volunteer_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert(data.message || 'Error submitting application.');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Volunteer Application';
      }
    }
  } catch (err) {
    alert('Network error submitting volunteer application.');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Volunteer Application';
    }
  }
}

// Open Adviser Verification Modal
function openVerifyModal(logId, studentName, roleName, eventTitle, hours) {
  document.getElementById('verifyLogId').value = logId;
  document.getElementById('verifyStudentName').textContent = studentName;
  document.getElementById('verifyEventRole').textContent = `${roleName} • ${eventTitle}`;
  document.getElementById('verifyHoursInput').value = parseFloat(hours).toFixed(1);

  const modal = document.getElementById('verifyVolunteerModal');
  if (modal) {
    modal.classList.add('active');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

// Submit Adviser Verification
async function submitAdviserVerification(e) {
  e.preventDefault();
  const btn = document.getElementById('verifySubmitBtn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';
  }

  const form = document.getElementById('verifyVolunteerForm');
  const fd = new FormData(form);
  fd.append('csrf_token', csrfToken);

  try {
    const res = await fetch('../shared/volunteer_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert(data.message || 'Error verifying volunteer role.');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-stamp"></i> Officially Verify &amp; Credit Hours';
      }
    }
  } catch (err) {
    alert('Network error during verification.');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-stamp"></i> Officially Verify &amp; Credit Hours';
    }
  }
}

// Reject Volunteer Application (Adviser)
async function rejectVolunteerApplication(logId, studentName) {
  const reason = prompt(`Please enter reason for rejecting ${studentName}'s volunteer application:`, 'Role filled or program mismatch');
  if (reason === null) return;

  const fd = new FormData();
  fd.append('action', 'verify_volunteer');
  fd.append('log_id', logId);
  fd.append('decision', 'Rejected');
  fd.append('verification_notes', reason);
  fd.append('csrf_token', csrfToken);

  try {
    const res = await fetch('../shared/volunteer_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert('Application marked as rejected.');
      window.location.reload();
    } else {
      alert(data.message || 'Error processing rejection.');
    }
  } catch (err) {
    alert('Network error.');
  }
}

// View Official Volunteer Certificate & Verification Receipt
async function viewVolunteerReceipt(logId) {
  const modal = document.getElementById('volunteerReceiptModal');
  if (modal) {
    document.getElementById('volunteerReceiptBody').innerHTML = `
      <div style="text-align:center; padding:35px 20px; color:#64748b;">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:1.8rem; color:#2563eb; margin-bottom:12px; display:block;"></i>
        Retrieving official volunteer certificate & verification...
      </div>
    `;
    modal.classList.add('active');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  try {
    const res = await fetch(`../shared/volunteer_actions.php?action=get_receipt&log_id=${encodeURIComponent(logId)}`);
    const data = await res.json();
    if (data.success && data.receipt) {
      currentReceiptData = data.receipt;
      renderVolunteerReceipt(data.receipt);
    } else {
      alert(data.message || 'Unable to retrieve volunteer certificate.');
      closeModal('volunteerReceiptModal');
    }
  } catch (err) {
    alert('Network error retrieving receipt.');
    closeModal('volunteerReceiptModal');
  }
}

function renderVolunteerReceipt(r) {
  document.getElementById('volunteerReceiptBody').innerHTML = `
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; background:#f8fafc; border-radius:12px; padding:16px 20px; border:1px solid #e2e8f0; margin-bottom:18px; font-size:0.82rem;">
      <div>
        <div style="color:#64748b; font-size:0.72rem; text-transform:uppercase; font-weight:700;">Student Volunteer</div>
        <div style="font-weight:900; color:#0f172a; font-size:1rem; margin-top:2px;">${r.student_name}</div>
        <div style="color:#334155; font-weight:600; margin-top:2px;">ID: ${r.student_number}</div>
        <div style="color:#475569; font-size:0.78rem; margin-top:2px;">${r.course} &bull; ${r.year_level}</div>
      </div>
      <div>
        <div style="color:#64748b; font-size:0.72rem; text-transform:uppercase; font-weight:700;">Verification Reference</div>
        <div style="font-weight:800; color:#2563eb; font-size:0.92rem; margin-top:2px; font-family:monospace;">${r.reference_no}</div>
        <div style="color:#334155; font-weight:600; margin-top:2px;"><i class="fa-solid fa-stamp" style="color:#16a34a;"></i> Verified: ${r.verified_at}</div>
        <div style="color:#475569; font-size:0.78rem; margin-top:2px;">${r.org_name} (${r.org_code})</div>
      </div>
    </div>

    <!-- Credited Service Box -->
    <div style="border:1.5px solid #bfdbfe; background:#eff6ff; border-radius:12px; padding:16px 20px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center;">
      <div>
        <div style="font-size:0.75rem; font-weight:800; color:#1d4ed8; text-transform:uppercase; letter-spacing:0.5px;">Accredited Event Role</div>
        <div style="font-size:1.15rem; font-weight:900; color:#0f172a; margin-top:2px;">${r.volunteer_role}</div>
        <div style="font-size:0.82rem; color:#475569; margin-top:3px;"><i class="fa-solid fa-calendar-check" style="color:#2563eb;"></i> ${r.event_title} &bull; ${r.venue}</div>
      </div>
      <div style="text-align:right;">
        <span style="font-size:1.8rem; font-weight:900; color:#1d4ed8; line-height:1;">+${r.hours}</span>
        <div style="font-size:0.75rem; font-weight:800; color:#1d4ed8; text-transform:uppercase;">Volunteer Hours</div>
      </div>
    </div>

    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:18px; font-size:0.82rem;">
      <span style="font-weight:800; color:#475569; font-size:0.72rem; text-transform:uppercase;">Adviser Endorsement Remarks</span>
      <div style="color:#1e293b; margin-top:4px; font-style:italic;">"${r.verification_notes}"</div>
      <div style="font-size:0.75rem; color:#64748b; margin-top:6px; font-weight:600;">Signed by: <strong>${r.verified_by_name}</strong></div>
    </div>

    <!-- SHA-256 Cryptographic Audit Seal -->
    <div style="background:#f8fafc; border:1px dashed #cbd5e1; border-radius:10px; padding:10px 14px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
        <span style="font-size:0.7rem; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">
          <i class="fa-solid fa-fingerprint" style="color:#2563eb;"></i> Verification Seal (SHA-256)
        </span>
        <span style="font-size:0.68rem; color:#1d4ed8; font-weight:800;">OFFICIAL &amp; CERTIFIED</span>
      </div>
      <div style="font-family:monospace; font-size:0.7rem; color:#334155; word-break:break-all; background:#ffffff; padding:5px 8px; border-radius:4px; border:1px solid #e2e8f0;">
        ${r.audit_hash}
      </div>
    </div>
  `;
}

// Print Volunteer Certificate
function printVolunteerReceipt() {
  if (!currentReceiptData) return;
  const r = currentReceiptData;
  const printWin = window.open('', '_blank', 'width=850,height=800');
  if (!printWin) {
    window.print();
    return;
  }

  printWin.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Official Volunteer Certificate - ${r.reference_no}</title>
      <meta charset="utf-8"/>
      <style>
        @page { size: portrait; margin: 15mm; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #0f172a; margin: 0; padding: 20px; line-height: 1.4; }
        .cert-card { border: 2.5px solid #1e3a8a; border-radius: 12px; padding: 28px; position: relative; max-width: 720px; margin: 0 auto; }
        .header { text-align: center; border-bottom: 2px dashed #94a3b8; padding-bottom: 16px; margin-bottom: 20px; }
        .inst-title { font-size: 16px; font-weight: 900; letter-spacing: 0.5px; color: #1e3a8a; margin: 0; }
        .inst-sub { font-size: 11px; color: #475569; margin: 3px 0 0 0; }
        .badge { display: inline-block; background: #ecfdf5; border: 1.5px solid #059669; color: #065f46; font-size: 12px; font-weight: 800; padding: 5px 16px; border-radius: 20px; margin-top: 10px; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 18px; font-size: 12px; }
        .highlight-box { border: 2px solid #86efac; background: #f0fdf4; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; text-align: center; }
        .sign-line { border-top: 1.5px solid #0f172a; padding-top: 6px; font-size: 11.5px; font-weight: 700; margin-top: 45px; }
      </style>
    </head>
    <body>
      <div class="cert-card">
        <div class="header">
          <div class="inst-title">BESTLINK COLLEGE OF THE PHILIPPINES</div>
          <div class="inst-sub">Office of Student Affairs &amp; Services &bull; Student Supreme Council</div>
          <div class="inst-sub" style="font-weight:700; color:#1e3a8a; margin-top:2px;">CO-CURRICULAR VOLUNTEER SERVICE ACCREDITATION</div>
          <div class="badge">&#10004; CERTIFICATE OF VOLUNTEER SERVICE HOURS</div>
        </div>

        <div class="meta-grid">
          <div>
            <div style="font-size:10px; text-transform:uppercase; font-weight:700; color:#64748b;">Accredited Student Volunteer</div>
            <div style="font-size:14px; font-weight:900; color:#0f172a; margin-top:2px;">${r.student_name}</div>
            <div style="color:#334155; margin-top:2px;">Student ID: <strong>${r.student_number}</strong></div>
            <div style="color:#64748b; font-size:11px; margin-top:2px;">${r.course} &bull; ${r.year_level}</div>
          </div>
          <div>
            <div style="font-size:10px; text-transform:uppercase; font-weight:700; color:#64748b;">Certificate Reference</div>
            <div style="font-size:13px; font-weight:800; color:#1e3a8a; font-family:monospace; margin-top:2px;">${r.reference_no}</div>
            <div style="color:#334155; margin-top:2px;">Verified Date: <strong>${r.verified_at}</strong></div>
            <div style="color:#64748b; font-size:11px; margin-top:2px;">${r.org_name} (${r.org_code})</div>
          </div>
        </div>

        <div class="highlight-box">
          <div>
            <div style="font-size:11px; font-weight:800; color:#166534; text-transform:uppercase;">Accredited Event Role</div>
            <div style="font-size:16px; font-weight:900; color:#0f172a; margin-top:2px;">${r.volunteer_role}</div>
            <div style="font-size:12px; color:#475569; margin-top:3px;">Event: ${r.event_title} &bull; Venue: ${r.venue}</div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:24px; font-weight:900; color:#15803d; line-height:1;">+${r.hours}</div>
            <div style="font-size:10px; font-weight:800; color:#166534; text-transform:uppercase;">Service Hours</div>
          </div>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; font-size:11.5px; margin-bottom:18px;">
          <strong>Adviser Certification:</strong> "${r.verification_notes}"
        </div>

        <div style="background:#f8fafc; border:1px dashed #cbd5e1; border-radius:6px; padding:8px 12px; font-size:10px; font-family:monospace; color:#475569; word-break:break-all;">
          <strong>Cryptographic Audit Hash:</strong> ${r.audit_hash}
        </div>

        <div class="signatures">
          <div>
            <div class="sign-line">${r.verified_by_name}</div>
            <span style="font-size:10.5px; color:#64748b;">Faculty Organization Adviser</span>
          </div>
          <div>
            <div class="sign-line">Office of Student Affairs &amp; Services</div>
            <span style="font-size:10.5px; color:#64748b;">Institutional Student Development</span>
          </div>
        </div>
      </div>
    </body>
    </html>
  `);
  printWin.document.close();
  printWin.focus();
  setTimeout(() => {
    printWin.print();
  }, 400);
}

// Open Create Opportunity Modal
function openCreateOpportunityModal() {
  const modal = document.getElementById('createOpportunityModal');
  if (modal) {
    modal.classList.add('active');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

// Submit Create Opportunity (Adviser / Admin)
async function submitCreateOpportunity(e) {
  e.preventDefault();
  const form = document.getElementById('createOpportunityForm');
  const fd = new FormData(form);
  fd.append('csrf_token', csrfToken);

  try {
    const res = await fetch('../shared/volunteer_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert(data.message || 'Error posting volunteer role.');
    }
  } catch (err) {
    alert('Network error posting opportunity.');
  }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('active', 'open');
    el.style.display = 'none';
    const anyModalOpen = Array.from(document.querySelectorAll('.modal-overlay')).some(m => m.style.display === 'flex' || m.classList.contains('active'));
    if (!anyModalOpen) {
      document.body.style.overflow = '';
    }
  }
}
</script>
<script src="../js/dashboard.js"></script>
</body>
</html>
