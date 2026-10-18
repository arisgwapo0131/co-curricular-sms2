<?php
// ============================================================
//  ELECTIONS.PHP  (dashboard/)
//  Co-Curricular Management System — Elections & Voting Portal
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_auth();
require_any_permission(['elections.vote', 'elections.manage.org', 'elections.oversight', 'elections.admin']);

// Auto-run DB schema check
require_once __DIR__ . '/../shared/init_elections.php';

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];
$student_is_org_member = ($sess_role === 'student') ? is_student_organization_member($conn, $user_id) : true;

// ── 1. Fetch Adviser Handled Organization ──────────────────────────
$adviser_club = null;
if ($sess_role === 'club_adviser') {
    $stmt_ac = $conn->prepare("
        SELECT c.id, c.name, c.code, c.description 
        FROM clubs c 
        JOIN club_memberships cm ON cm.club_id = c.id 
        WHERE cm.user_id = ? AND cm.status = 'Active' 
        LIMIT 1
    ");
    if ($stmt_ac) {
        $stmt_ac->bind_param('i', $user_id);
        $stmt_ac->execute();
        $res_ac = $stmt_ac->get_result();
        if ($res_ac && $row_ac = $res_ac->fetch_assoc()) {
            $adviser_club = $row_ac;
        }
        $stmt_ac->close();
    }
    if (!$adviser_club) {
        $r_c = $conn->query("SELECT id, name, code, description FROM clubs WHERE status='Active' LIMIT 1");
        if ($r_c && $row_c = $r_c->fetch_assoc()) {
            $adviser_club = $row_c;
        }
    }
}

// Session-based votes cast tracker
if (!isset($_SESSION['votes_cast'])) { $_SESSION['votes_cast'] = []; }

// ── 2. Fetch Active Elections from DB (Scoped by Role) ─────────────
$where_sql = "";
$params = [];
$types = "";

if ($sess_role === 'club_adviser' && $adviser_club) {
    $where_sql = "WHERE e.club_id = ?";
    $params = [$adviser_club['id']];
    $types = "i";
} elseif ($sess_role === 'student') {
    $where_sql = "WHERE (e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active') OR e.scope = 'Campus' OR e.club_id = 0)";
    $params = [$user_id];
    $types = "i";
}

$sql_elections = "
    SELECT e.id, e.election_code, e.club_id, e.title, e.description, e.closes_at, e.status, e.positions, e.created_at, e.created_by,
           e.poll_verified_at, e.poll_verified_by, e.officers_announced_at, e.officers_announced_by, e.officers_verified_at, e.officers_verified_by,
           c.name AS club_name, c.code AS club_code
    FROM elections e
    JOIN clubs c ON c.id = e.club_id
    $where_sql
    ORDER BY e.created_at DESC
";

$stmt_el = $conn->prepare($sql_elections);
if (!empty($types) && !empty($params)) {
    $stmt_el->bind_param($types, ...$params);
}
$stmt_el->execute();
$raw_elections = $stmt_el->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_el->close();

// Build elections dataset with candidates
$active_elections = [];
$colors = [
    '#1a3a8c',
    '#1a3a8c',
    '#1a3a8c',
    '#1a3a8c'
];
$color_idx = 0;

foreach ($raw_elections as $el) {
    $eid = (int)$el['id'];
    $el_status = $el['status'];

    // Auto-close election if closes_at datetime has passed
    if ($el_status === 'open' && !empty($el['closes_at']) && strtotime($el['closes_at']) <= time()) {
        $conn->query("UPDATE elections SET status = 'closed' WHERE id = {$eid}");
        $el_status = 'closed';
    }

    // Fetch Candidates
    $c_stmt = $conn->prepare("SELECT id, candidate_code, name, position, party, year_level, program, gwa, platform_tag, achievements, votes_count, COALESCE(is_appointed, 0) AS is_appointed FROM election_candidates WHERE election_id = ? ORDER BY position, name");
    $c_stmt->bind_param('i', $eid);
    $c_stmt->execute();
    $cands_raw = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $c_stmt->close();

    $candidates = [];
    foreach ($cands_raw as $cand) {
        $initials = '';
        $parts = explode(' ', $cand['name']);
        foreach ($parts as $p) { $initials .= strtoupper(substr($p, 0, 1)); }
        $initials = substr($initials, 0, 2);

        $ach_arr = json_decode($cand['achievements'] ?? '[]', true);
        if (!is_array($ach_arr)) $ach_arr = array_filter(explode(',', $cand['achievements'] ?? ''));

        $candidates[] = [
            'id'           => $cand['id'],
            'cand_code'    => $cand['candidate_code'],
            'name'         => htmlspecialchars($cand['name']),
            'pos'          => htmlspecialchars($cand['position']),
            'party'        => htmlspecialchars($cand['party']),
            'initials'     => $initials,
            'color'        => $colors[$color_idx % count($colors)],
            'year'         => htmlspecialchars($cand['year_level']),
            'prog'         => htmlspecialchars($cand['program']),
            'gwa'          => htmlspecialchars($cand['gwa']),
            'tag'          => htmlspecialchars($cand['platform_tag']),
            'achievements' => $ach_arr,
            'votes_count'  => (int)$cand['votes_count'],
            'is_appointed' => (int)$cand['is_appointed']
        ];
    }

    // Turnout stats — real eligible member count (students only, excluding advisers)
    $el_club_id = (int)$el['club_id'];
    $eligible = 0;
    if ($el_club_id > 0) {
        $m_stmt = $conn->prepare("
            SELECT COUNT(*) AS c 
            FROM club_memberships cm 
            JOIN users u ON u.id = cm.user_id 
            WHERE cm.club_id = ? 
              AND cm.status = 'Active' 
              AND u.role NOT IN ('club_adviser', 'admin', 'super_admin', 'staff')
              AND cm.role != 'Adviser'
        ");
        $m_stmt->bind_param('i', $el_club_id);
        $m_stmt->execute();
        $eligible = (int)$m_stmt->get_result()->fetch_assoc()['c'];
        $m_stmt->close();
    } else {
        $eligible = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'Active'")->fetch_row()[0] ?? 0);
    }
    if ($eligible <= 0 && !empty($el['eligible_voters'])) {
        $eligible = (int)$el['eligible_voters'];
    }

    $v_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM election_votes WHERE election_id = ?");
    $v_stmt->bind_param('i', $eid);
    $v_stmt->execute();
    $voted = (int)$v_stmt->get_result()->fetch_assoc()['c'];
    $v_stmt->close();

    // Check authentic voter participation from database
    $user_has_voted = false;
    $voted_at_formatted = null;
    $my_v_stmt = $conn->prepare("SELECT voted_at FROM election_voters WHERE election_id = ? AND user_id = ? LIMIT 1");
    if ($my_v_stmt) {
        $my_v_stmt->bind_param('ii', $eid, $user_id);
        $my_v_stmt->execute();
        $my_v_res = $my_v_stmt->get_result()->fetch_assoc();
        if ($my_v_res) {
            $user_has_voted = true;
            $voted_at_formatted = !empty($my_v_res['voted_at']) ? date('g:i A, F j, Y', strtotime($my_v_res['voted_at'])) : date('g:i A, F j, Y');
        }
        $my_v_stmt->close();
    }

    $positions = json_decode($el['positions'] ?? '[]', true);
    if (!is_array($positions) || empty($positions)) {
        $positions = ['President', 'Vice President', 'Secretary', 'Treasurer'];
    }

    $closes_formatted = !empty($el['closes_at']) ? date('g:i A, F j, Y', strtotime($el['closes_at'])) : 'Closes Soon';

    $active_elections[] = [
        'id'                    => $el['id'],
        'code'                  => $el['election_code'],
        'org'                   => htmlspecialchars($el['club_name']),
        'acronym'               => htmlspecialchars($el['club_code']),
        'color'                 => $colors[$color_idx % count($colors)],
        'title'                 => htmlspecialchars($el['title']),
        'description'           => htmlspecialchars($el['description'] ?? ''),
        'closes'                => $closes_formatted,
        'closes_raw'            => $el['closes_at'],
        'eligible'              => $eligible,
        'voted'                 => $voted,
        'user_has_voted'        => $user_has_voted,
        'voted_at'              => $voted_at_formatted,
        'status'                => $el_status,
        'created_by'            => (int)($el['created_by'] ?? 0),
        'poll_verified_at'      => $el['poll_verified_at'] ?? null,
        'officers_announced_at' => $el['officers_announced_at'] ?? null,
        'officers_verified_at'  => $el['officers_verified_at'] ?? null,
        'positions'             => $positions,
        'candidates'            => $candidates,
        'club_id'               => (int)$el['club_id']
    ];
    $color_idx++;
}

// ── 3. Past Election Results (Scoped by Role & Real DB Query) ──────
$past_where = "WHERE e.status = 'closed'";
$past_params = [];
$past_types = "";

if ($sess_role === 'club_adviser' && $adviser_club) {
    $past_where .= " AND e.club_id = ?";
    $past_params = [$adviser_club['id']];
    $past_types = "i";
} elseif ($sess_role === 'student') {
    $past_where .= " AND e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active')";
    $past_params = [$user_id];
    $past_types = "i";
}

$sql_past = "
    SELECT e.id, e.title, e.closes_at, e.club_id, e.eligible_voters, e.positions, c.name AS club_name, c.code AS club_code
    FROM elections e
    JOIN clubs c ON c.id = e.club_id
    $past_where
    ORDER BY e.closes_at DESC
";
$stmt_p = $conn->prepare($sql_past);
if (!empty($past_types)) {
    $stmt_p->bind_param($past_types, ...$past_params);
}
$stmt_p->execute();
$raw_past = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_p->close();

$past_results = [];
foreach ($raw_past as $pr) {
    $pr_id = (int)$pr['id'];
    $pr_club_id = (int)$pr['club_id'];

    // Voted count
    $v_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM election_votes WHERE election_id = ?");
    $v_stmt->bind_param('i', $pr_id);
    $v_stmt->execute();
    $pvoted = (int)$v_stmt->get_result()->fetch_assoc()['c'];
    $v_stmt->close();

    // Eligible count (students only, excluding advisers)
    $peligible = 0;
    if ($pr_club_id > 0) {
        $m_stmt = $conn->prepare("
            SELECT COUNT(*) AS c 
            FROM club_memberships cm 
            JOIN users u ON u.id = cm.user_id 
            WHERE cm.club_id = ? 
              AND cm.status = 'Active' 
              AND u.role NOT IN ('club_adviser', 'admin', 'super_admin', 'staff')
              AND cm.role != 'Adviser'
        ");
        $m_stmt->bind_param('i', $pr_club_id);
        $m_stmt->execute();
        $peligible = (int)$m_stmt->get_result()->fetch_assoc()['c'];
        $m_stmt->close();
    } else {
        $peligible = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'Active'")->fetch_row()[0] ?? 0);
    }
    if ($peligible <= 0 && !empty($pr['eligible_voters'])) {
        $peligible = (int)$pr['eligible_voters'];
    }

    // Load authentic candidates for this archived election
    $c_stmt = $conn->prepare("SELECT id, candidate_code, name, position, party, year_level, program, votes_count, platform_tag FROM election_candidates WHERE election_id = ? ORDER BY votes_count DESC");
    $c_stmt->bind_param('i', $pr_id);
    $c_stmt->execute();
    $p_candidates = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $c_stmt->close();

    // Winning President or top candidate
    $w_stmt = $conn->prepare("SELECT name, votes_count FROM election_candidates WHERE election_id = ? AND position LIKE '%President%' ORDER BY votes_count DESC LIMIT 1");
    $w_stmt->bind_param('i', $pr_id);
    $w_stmt->execute();
    $w_res = $w_stmt->get_result()->fetch_assoc();
    $w_stmt->close();
    
    if (!$w_res && !empty($p_candidates)) {
        $w_res = $p_candidates[0];
    }
    
    $winner_name = $w_res ? $w_res['name'] : 'Declared Winner';
    if ($pvoted > $peligible) $peligible = $pvoted;

    $pct = $peligible > 0 ? round(($pvoted / $peligible) * 100) : 0;

    $past_results[] = [
        'id'          => $pr_id,
        'org'         => htmlspecialchars($pr['club_name']),
        'acronym'     => htmlspecialchars($pr['club_code']),
        'title'       => htmlspecialchars($pr['title']),
        'date'        => !empty($pr['closes_at']) ? date('M j, Y', strtotime($pr['closes_at'])) : 'Completed',
        'winner'      => htmlspecialchars($winner_name),
        'votes'       => "{$pvoted}/{$peligible} ({$pct}%)",
        'voted'       => $pvoted,
        'eligible'    => $peligible,
        'candidates'  => $p_candidates,
        'positions'   => json_decode($pr['positions'] ?? '[]', true) ?: ['President'],
        'details'     => htmlspecialchars($pr['title'])
    ];
}

// ── 4. SSC Student Governance & Elections Metrics & Registry ────────
$ssc_active_count     = 0;
$ssc_upcoming_count   = 0;
$ssc_closed_count     = 0;
$ssc_total_candidates = 0;
$ssc_total_votes      = 0;
$ssc_turnout_pct      = 0;
$ssc_registry         = [];

if ($sess_role === 'ssc' || $sess_role === 'admin') {
    // 1. Active Elections (Currently open)
    $ssc_active_count = (int)($conn->query("
        SELECT COUNT(*) FROM elections 
        WHERE status IN ('active', 'open') 
        AND (starts_at IS NULL OR starts_at <= NOW()) 
        AND (closes_at IS NULL OR closes_at >= NOW())
    ")->fetch_row()[0] ?? 0);

    // 2. Upcoming Elections (Scheduled future elections)
    $ssc_upcoming_count = (int)($conn->query("
        SELECT COUNT(*) FROM elections 
        WHERE status = 'draft' 
        OR (starts_at IS NOT NULL AND starts_at > NOW())
    ")->fetch_row()[0] ?? 0);

    // 3. Closed Elections (Completed elections)
    $ssc_closed_count = (int)($conn->query("
        SELECT COUNT(*) FROM elections 
        WHERE status IN ('closed', 'verified') 
        OR (status IN ('active', 'open') AND closes_at IS NOT NULL AND closes_at < NOW())
    ")->fetch_row()[0] ?? 0);

    // 4. Total Candidates
    $ssc_total_candidates = (int)($conn->query("
        SELECT COUNT(*) FROM election_candidates
    ")->fetch_row()[0] ?? 0);

    // 5. Total Votes & 6. Voter Turnout
    $cand_votes_sum = (int)($conn->query("SELECT COALESCE(SUM(votes_count), 0) FROM election_candidates")->fetch_row()[0] ?? 0);
    $real_votes_cnt = (int)($conn->query("SELECT COUNT(*) FROM election_votes")->fetch_row()[0] ?? 0);
    $ssc_total_votes = max($cand_votes_sum, $real_votes_cnt);

    $eligible_sum = (int)($conn->query("SELECT COALESCE(SUM(eligible_voters), 0) FROM elections")->fetch_row()[0] ?? 0);
    $ssc_total_eligible = max($eligible_sum, $ssc_total_votes);
    $ssc_turnout_pct = ($ssc_total_eligible > 0 && $ssc_total_votes > 0)
        ? round(($ssc_total_votes / $ssc_total_eligible) * 100, 1)
        : 0;

    // Election Registry (All columns fetched dynamically from database)
    $sql_reg = "
        SELECT e.id, e.election_code, e.club_id, e.title, e.description, e.election_type,
               e.starts_at, e.closes_at, e.status, e.eligible_voters, e.positions, e.created_at,
               e.verified_at, e.verified_by, e.audit_notes,
               c.name AS club_name, c.code AS club_code,
               (SELECT COUNT(*) FROM election_candidates ec WHERE ec.election_id = e.id) AS candidates_count,
               (SELECT COALESCE(SUM(ec.votes_count), 0) FROM election_candidates ec WHERE ec.election_id = e.id) AS candidate_votes_sum,
               (SELECT COUNT(*) FROM election_votes ev WHERE ev.election_id = e.id) AS recorded_votes_count,
               (SELECT COUNT(*) FROM election_voters evo WHERE evo.election_id = e.id) AS voters_count,
               (SELECT COUNT(*) 
                FROM club_memberships cm 
                JOIN users u ON u.id = cm.user_id 
                WHERE cm.club_id = e.club_id 
                  AND cm.status = 'Active' 
                  AND u.role NOT IN ('club_adviser', 'admin', 'super_admin', 'staff')
                  AND cm.role != 'Adviser') AS active_members_count
        FROM elections e
        JOIN clubs c ON c.id = e.club_id
        ORDER BY 
            CASE 
                WHEN e.status IN ('active', 'open') THEN 1
                WHEN e.status = 'draft' THEN 2
                WHEN e.status = 'closed' THEN 3
                WHEN e.status = 'verified' THEN 4
                ELSE 5
            END,
            e.created_at DESC
    ";
    $res_reg = $conn->query($sql_reg);
    $accum_eligible = 0;
    if ($res_reg) {
        while ($row = $res_reg->fetch_assoc()) {
            $eid = (int)$row['id'];
            $cand_count = (int)$row['candidates_count'];
            $rec_count  = (int)$row['recorded_votes_count'];
            $voter_cnt  = (int)$row['voters_count'];
            $votes_cast = max($voter_cnt, $rec_count);

            $el_voters = (int)$row['active_members_count'];
            if ($el_voters <= 0 && !empty($row['eligible_voters'])) {
                $el_voters = (int)$row['eligible_voters'];
            }
            if ($el_voters < $votes_cast) {
                $el_voters = $votes_cast;
            }
            $accum_eligible += $el_voters;

            $turnout_val = ($el_voters > 0 && $votes_cast > 0) ? round(($votes_cast / $el_voters) * 100, 1) : 0;

            $raw_status = strtolower($row['status']);
            $norm_status = match($raw_status) {
                'open', 'active', 'counting'                         => 'active',
                'pending_verification', 'pending_approval', 'pending'=> 'pending_verification',
                'rejected'                                           => 'rejected',
                'draft'                                              => 'draft',
                'closed'                                             => 'closed',
                'announced'                                          => 'announced',
                'verified'                                           => 'verified',
                default                                              => $raw_status
            };

            $start_fmt = !empty($row['starts_at']) ? date('M d, Y h:i A', strtotime($row['starts_at'])) : date('M d, Y', strtotime($row['created_at']));
            $close_fmt = !empty($row['closes_at']) ? date('M d, Y h:i A', strtotime($row['closes_at'])) : 'TBD';

            $ssc_registry[] = [
                'id'              => $eid,
                'election_code'   => $row['election_code'],
                'club_name'       => $row['club_name'],
                'club_code'       => $row['club_code'],
                'title'           => $row['title'],
                'election_type'   => !empty($row['election_type']) ? $row['election_type'] : 'Student Governance',
                'start_date'      => $start_fmt,
                'closing_date'    => $close_fmt,
                'closes_at'       => $row['closes_at'],
                'candidates'      => $cand_count,
                'eligible_voters' => $el_voters,
                'votes_cast'      => $votes_cast,
                'turnout'         => $turnout_val . '%',
                'status'          => $norm_status,
                'raw_status'      => $raw_status,
                'description'     => $row['description'],
                'positions'       => json_decode($row['positions'] ?? '[]', true) ?: [],
                'verified_at'     => $row['verified_at'],
                'verified_by'     => $row['verified_by'],
                'audit_notes'     => $row['audit_notes']
            ];
        }
        if ($accum_eligible > 0) {
            $ssc_total_eligible = max($accum_eligible, $ssc_total_votes);
            $ssc_turnout_pct = ($ssc_total_eligible > 0 && $ssc_total_votes > 0)
                ? round(($ssc_total_votes / $ssc_total_eligible) * 100, 1)
                : 0;
        }
    }
}

// ── Admin-Specific Responsibilities Metrics (Election Administration) ──
$admin_registry     = $ssc_registry;
$admin_audit_count  = 0;
$admin_locked_count = 0;
$admin_active_count = 0;

if ($sess_role === 'admin') {
    // 1. Audit Logs Count (Activity & Access Logs)
    $r_aud = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE (target_table IN ('elections', 'election_votes', 'election_candidates') OR action LIKE '%election%')");
    if ($r_aud) {
        $admin_audit_count = (int)($r_aud->fetch_row()[0] ?? 0);
    }
    // 2. Security Metrics: Locked / Closed vs Open
    $admin_locked_count = (int)($conn->query("SELECT COUNT(*) FROM elections WHERE status IN ('closed', 'locked', 'verified')")->fetch_row()[0] ?? 0);
    $admin_active_count = (int)($conn->query("SELECT COUNT(*) FROM elections WHERE status IN ('open', 'active')")->fetch_row()[0] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= csrf_token() ?>"/>
  <title><?= ($sess_role === 'admin') ? 'Election Administration' : (($sess_role === 'ssc') ? 'Student Governance &amp; Elections' : 'Elections &amp; Voting Portal') ?> – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.65); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 9999; }
    .modal-card { background: #fff; border-radius: 16px; width: 100%; max-width: 560px; padding: 28px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 0.88rem; color: #0f172a; font-family: inherit; box-sizing: border-box; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #2563eb; outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
    .candidate-manage-chip { display: inline-flex; align-items: center; gap: 6px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 12px; font-size: 0.8rem; font-weight: 600; color: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin: 3px 2px; }
    .candidate-manage-chip strong { color: #0f172a; }
    .candidate-manage-chip .btn-del { color: #ef4444; cursor: pointer; font-size: 0.85rem; margin-left: 4px; transition: color 0.15s ease; }
    .eorg-status-badge.eorg-pending { background: #fef3c7 !important; color: #b45309 !important; border: 1px solid #fde68a !important; }
    .eorg-status-badge.eorg-announced { background: #ede9fe !important; color: #6d28d9 !important; border: 1px solid #ddd6fe !important; }
    .eorg-status-badge.eorg-verified { background: #dbeafe !important; color: #1d4ed8 !important; border: 1px solid #bfdbfe !important; }

    /* ── Professional System Layout: Election KPI Cards Grid ── */
    .election-kpi-grid,
    .gov-summary-grid,
    .gov-summary-grid-4 {
      display: grid !important;
      grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)) !important;
      gap: 14px !important;
      margin-bottom: 22px !important;
      align-items: stretch !important;
    }
    .election-kpi-card,
    .gov-kpi-tile {
      background: #ffffff !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 14px !important;
      padding: 16px 18px !important;
      display: flex !important;
      align-items: center !important;
      gap: 14px !important;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03) !important;
      transition: transform 0.2s ease, box-shadow 0.2s ease !important;
      text-decoration: none !important;
      box-sizing: border-box !important;
    }
    .election-kpi-card:hover,
    .gov-kpi-tile:hover {
      transform: translateY(-2px) !important;
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06) !important;
    }
    .election-kpi-icon,
    .gov-kpi-icon {
      width: 46px !important;
      height: 46px !important;
      min-width: 46px !important;
      max-width: 46px !important;
      border-radius: 12px !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      font-size: 1.25rem !important;
      flex-shrink: 0 !important;
    }
    .election-kpi-val,
    .gov-kpi-value {
      font-size: 1.5rem !important;
      font-weight: 800 !important;
      color: #0f172a !important;
      line-height: 1.1 !important;
      letter-spacing: -0.4px !important;
    }
    .election-kpi-lbl,
    .gov-kpi-title {
      font-size: 0.72rem !important;
      font-weight: 700 !important;
      color: #64748b !important;
      text-transform: uppercase !important;
      letter-spacing: 0.5px !important;
      margin-top: 3px !important;
      line-height: 1.2 !important;
    }
    .gov-kpi-desc { display: none !important; }
    .gov-kpi-header { display: contents !important; }
    .gov-kpi-body { display: contents !important; }

    /* ── Sleek Action Buttons for Election Registry ── */
    .el-act-btn {
      height: 28px;
      padding: 0 9px;
      font-size: 0.74rem;
      font-weight: 700;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.15s ease;
      white-space: nowrap;
      text-decoration: none;
      box-sizing: border-box;
    }
    .el-act-btn:hover {
      transform: translateY(-1px);
      filter: brightness(0.96);
    }
    .el-act-verify { background: #16a34a; color: #fff; }
    .el-act-verify:hover { background: #15803d; }
    .el-act-reject { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 0 6px; }
    .el-act-reject:hover { background: #fecaca; }
    .el-act-assign { background: #2563eb; color: #fff; }
    .el-act-assign:hover { background: #1d4ed8; }
    .el-act-view { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .el-act-view:hover { background: #2563eb; color: #fff; border-color: #2563eb; }
    .el-act-monitor { background: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; }
    .el-act-monitor:hover { background: #0284c7; color: #fff; border-color: #0284c7; }
    .el-act-audit { background: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; }
    .el-act-audit:hover { background: #7c3aed; color: #fff; border-color: #7c3aed; }

    /* SSC Election Registry Responsive Table Rules */
    #sscElectionRegistryTable {
      min-width: 1150px !important;
      width: 100% !important;
      border-collapse: collapse !important;
    }
    #sscElectionRegistryTable th {
      background: #f8fafc !important;
      color: #475569 !important;
      font-weight: 700 !important;
      text-transform: uppercase !important;
      font-size: 0.72rem !important;
      letter-spacing: 0.5px !important;
      border-bottom: 2px solid #e2e8f0 !important;
      padding: 12px 14px !important;
    }
    #sscElectionRegistryTable td {
      padding: 12px 14px !important;
      vertical-align: middle !important;
      font-size: 0.82rem !important;
      border-bottom: 1px solid #f1f5f9 !important;
    }
    @media (max-width: 1024px) {
      .gov-summary-grid-4 {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
    }
    @media (max-width: 540px) {
      .gov-summary-grid-4 {
        grid-template-columns: 1fr !important;
      }
    }

    #adminElectionTable {
      width: 100% !important;
      table-layout: fixed !important;
      border-collapse: collapse !important;
      font-size: 0.80rem !important;
    }
    #adminElectionTable th {
      padding: 9px 8px !important;
      font-size: 0.70rem !important;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      color: #475569;
      white-space: nowrap;
      background: #f8fafc;
      border-bottom: 2px solid #e2e8f0;
    }
    #adminElectionTable td {
      padding: 7px 8px !important;
      font-size: 0.78rem !important;
      vertical-align: middle !important;
      word-break: break-word;
      border-bottom: 1px solid #e2e8f0;
    }
    #adminElectionTable .admin-act-group {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: flex-end !important;
      gap: 3px !important;
      flex-wrap: nowrap !important;
      width: 100%;
    }
    #adminElectionTable .admin-tbl-act-btn {
      width: 26px;
      height: 26px;
      padding: 0;
      border-radius: 5px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.72rem;
      border: none;
      cursor: pointer;
      transition: all 0.15s ease;
      flex-shrink: 0;
      text-decoration: none;
      line-height: 1;
    }
    #adminElectionTable .admin-tbl-act-btn:hover:not(:disabled) {
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
      filter: brightness(1.1);
    }
    #adminElectionTable .admin-tbl-act-btn:disabled,
    #adminElectionTable .admin-tbl-act-btn.btn-disabled {
      background: #e2e8f0 !important;
      color: #94a3b8 !important;
      border: 1px solid #cbd5e1 !important;
      cursor: not-allowed !important;
      opacity: 0.65 !important;
      transform: none !important;
      box-shadow: none !important;
      filter: none !important;
    }

    @media print {
      body { background: #fff !important; margin: 0; padding: 0; }
      .sidebar, .topbar, .hamburger, .page-title-bar, .modal-close,
      #closeResultsModalBtn, .card-btn, .election-view-nav, .footer,
      #electionLanding, #boothView, #candidatesView, #sscGovernanceView { display: none !important; }
      .modal-overlay { position: static !important; inset: auto !important; background: none !important; display: block !important; }
      .modal-card { max-width: 100% !important; box-shadow: none !important; padding: 0 !important; max-height: none !important; overflow: visible !important; }
      #printableResultsArea { display: block !important; width: 100% !important; }
    }
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'elections';
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

    <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
      <div>
        <h2 class="page-title" style="margin:0; font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:10px;">
          <i class="fa-solid fa-check-to-slot" style="color:#2563eb;"></i>
          Club Officer Elections
        </h2>
        <div style="font-size:0.80rem; color:#64748b; margin-top:3px;">
          <?= in_array($sess_role, ['admin', 'super_admin']) ? 'Official Institutional Governance &bull; Election Pool Administration &bull; Security Audit' : (($sess_role === 'ssc') ? 'Supreme Student Council &bull; Executive Election Registry &bull; Democratic Governance' : 'Official Co-Curricular Student Leadership Elections &bull; Secure Voting Portal') ?>
        </div>
      </div>
      <div style="display:flex; gap:10px; align-items:center;">
        <?php if (in_array($sess_role, ['ssc', 'admin', 'super_admin'])): ?>
          <button class="card-btn" onclick="openCreateElectionModal()" style="height:38px; padding:0 18px; border-radius:10px; font-weight:700; background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer; border:none; box-shadow:0 2px 6px rgba(37,99,235,0.25);">
            <i class="fa-solid fa-plus-circle"></i> Create Election
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div class="content-body">

      <?php if ($sess_role === 'admin' || $sess_role === 'super_admin'): ?>
      <!-- ══════════════════════════════════════════════════════
           ELECTION ADMINISTRATION (Admin Role View)
           ══════════════════════════════════════════════════════ -->
      <div id="adminElectionView">
        <!-- 4 Responsibility KPI Metric Cards Row (Specification Table) -->
        <div class="election-kpi-grid">
          <!-- Card 1: Election Registry -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-folder-tree"></i></div>
            <div>
              <div class="election-kpi-val"><?= number_format(count($admin_registry)) ?></div>
              <div class="election-kpi-lbl">Election Registry</div>
            </div>
          </div>

          <!-- Card 2: Security -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#ffedd5; color:#ea580c;"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
              <div class="election-kpi-val"><?= number_format($admin_locked_count) ?> <span style="font-size:0.75rem; font-weight:700; color:#64748b;">(<?= number_format($admin_active_count) ?> Open)</span></div>
              <div class="election-kpi-lbl">Security &amp; Closed</div>
            </div>
          </div>

          <!-- Card 3: Results -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-square-poll-vertical"></i></div>
            <div>
              <div class="election-kpi-val"><?= number_format($ssc_total_votes) ?> <span style="font-size:0.75rem; font-weight:700; color:#16a34a;">(<?= $ssc_turnout_pct ?>%)</span></div>
              <div class="election-kpi-lbl">Ballots &amp; Turnout</div>
            </div>
          </div>

          <!-- Card 4: Audit -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#ede9fe; color:#7c3aed;"><i class="fa-solid fa-clipboard-check"></i></div>
            <div>
              <div class="election-kpi-val"><?= number_format($admin_audit_count) ?></div>
              <div class="election-kpi-lbl">Election Audit Logs</div>
            </div>
          </div>
        </div>

        <!-- Master Election Administration Table Card -->
        <div class="card" style="padding:18px 20px; border-radius:14px; border:1px solid #e2e8f0; margin-top:14px;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
            <div>
              <h3 style="margin:0; font-size:1.05rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-landmark" style="color:#2563eb;"></i> Election Administration Registry
              </h3>
              <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
                Institutional oversight: registry configuration, security locking, results verification, and audit logging.
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
              <div style="position:relative; width:200px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:0.75rem; color:#94a3b8;"></i>
                <input type="text" id="adminElectionSearch" placeholder="Search elections..." oninput="filterAdminElections()" style="width:100%; height:34px; padding:0 10px 0 30px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.78rem;" />
              </div>
              <select id="adminStatusFilter" onchange="filterAdminElections()" style="height:34px; padding:0 10px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.78rem; font-weight:600; color:#334155; background:#fff;">
                <option value="all">All Statuses</option>
                <option value="active">Open / Active</option>
                <option value="closed">Locked / Closed</option>
                <option value="verified">Verified &amp; Certified</option>
                <option value="draft">Draft</option>
              </select>
              <button class="card-btn" onclick="openCreateElectionModal()" style="height:34px; padding:0 14px; border-radius:8px; font-weight:700; background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:6px; font-size:0.78rem; cursor:pointer;" title="Establish New Election Pool">
                <i class="fa-solid fa-plus-circle"></i> Create Election
              </button>
              <button class="card-btn" onclick="openAdminAuditModal(0, 'All Campus Elections')" style="height:34px; padding:0 12px; border-radius:8px; font-weight:700; background:#7c3aed; color:#fff; display:inline-flex; align-items:center; gap:6px; font-size:0.78rem; cursor:pointer;" title="Inspect Access &amp; Activity Audit Logs">
                <i class="fa-solid fa-shield-halved"></i> Audit Trail
              </button>
            </div>
          </div>

          <!-- Fluid Compact Table: Zero Horizontal Scroll / Cut-off -->
          <div class="table-wrap" style="width:100%; overflow-x:hidden;">
            <table id="adminElectionTable" class="table" style="width:100% !important; table-layout:fixed !important; border-collapse:collapse;">
              <thead>
                <tr>
                  <th style="width:23%;">Election Details</th>
                  <th style="width:15%;">Organization</th>
                  <th style="width:18%;">Timeline</th>
                  <th style="width:16%;">Slate &amp; Turnout</th>
                  <th style="width:13%;">Security Status</th>
                  <th style="width:15%; text-align:right;">Admin Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($admin_registry)): ?>
                <tr>
                  <td colspan="6" style="text-align:center; padding:32px 16px; color:#64748b;">
                    <div style="font-size:1.6rem; color:#cbd5e1; margin-bottom:8px;"><i class="fa-solid fa-folder-open"></i></div>
                    <div style="font-weight:700; color:#334155;">No election records registered</div>
                    <div style="font-size:0.75rem; color:#94a3b8; margin-top:2px;">Click "Create Election" above to establish an institutional election pool.</div>
                  </td>
                </tr>
                <?php else: ?>
                <?php foreach ($admin_registry as $r): ?>
                <?php
                  $is_open     = ($r['status'] === 'active' || $r['status'] === 'open');
                  $is_closed   = ($r['status'] === 'closed');
                  $is_verified = ($r['status'] === 'verified');
                  $json_r      = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                ?>
                <tr class="admin-el-row"
                    data-code="<?= strtolower(htmlspecialchars($r['election_code'])) ?>"
                    data-org="<?= strtolower(htmlspecialchars($r['club_name'] . ' ' . $r['club_code'])) ?>"
                    data-title="<?= strtolower(htmlspecialchars($r['title'])) ?>"
                    data-status="<?= strtolower($r['status']) ?>">
                  <!-- 1. Election Details -->
                  <td>
                    <div style="font-weight:700; color:#0f172a; line-height:1.2; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;" title="<?= htmlspecialchars($r['title']) ?>">
                      <?= htmlspecialchars($r['title']) ?>
                    </div>
                    <div style="display:flex; align-items:center; gap:5px; margin-top:3px; flex-wrap:wrap;">
                      <span class="badge" style="font-family:monospace; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:0.68rem; padding:1px 4px;">
                        <?= htmlspecialchars($r['election_code']) ?>
                      </span>
                      <span class="badge" style="background:#eff6ff; color:#2563eb; font-size:0.68rem; padding:1px 4px;">
                        <?= htmlspecialchars($r['election_type']) ?>
                      </span>
                    </div>
                  </td>

                  <!-- 2. Organization -->
                  <td>
                    <div style="font-weight:700; color:#1e293b; line-height:1.2; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;" title="<?= htmlspecialchars($r['club_name']) ?>">
                      <?= htmlspecialchars($r['club_name']) ?>
                    </div>
                    <span class="badge" style="background:#dbeafe; color:#1e40af; font-size:0.68rem; padding:1px 5px; margin-top:3px; display:inline-block; font-weight:700;">
                      <?= htmlspecialchars($r['club_code']) ?>
                    </span>
                  </td>

                  <!-- 3. Timeline -->
                  <td>
                    <div style="font-size:0.75rem; color:#166534; display:flex; align-items:center; gap:4px; line-height:1.2;">
                      <i class="fa-regular fa-clock" style="font-size:0.7rem;"></i> <?= htmlspecialchars($r['start_date']) ?>
                    </div>
                    <div style="font-size:0.75rem; color:#b91c1c; display:flex; align-items:center; gap:4px; line-height:1.2; margin-top:3px;">
                      <i class="fa-solid fa-hourglass-end" style="font-size:0.7rem;"></i> <?= htmlspecialchars($r['closing_date']) ?>
                    </div>
                  </td>

                  <!-- 4. Slate & Turnout -->
                  <td>
                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem;">
                      <span style="font-weight:700; color:#334155;"><i class="fa-solid fa-users" style="color:#6366f1;"></i> <?= $r['candidates'] ?> Slates</span>
                      <span style="font-weight:700; color:#16a34a;"><?= $r['turnout'] ?></span>
                    </div>
                    <div style="font-size:0.70rem; color:#64748b; margin-top:2px;">
                      <?= number_format($r['votes_cast']) ?> / <?= number_format($r['eligible_voters']) ?> ballots
                    </div>
                    <div style="background:#e2e8f0; border-radius:4px; height:4px; width:100%; margin-top:3px; overflow:hidden;">
                      <div style="background:#2563eb; height:100%; width:<?= min(100, (float)$r['turnout']) ?>%;"></div>
                    </div>
                  </td>

                  <!-- 5. Security Status -->
                  <td>
                    <?php if ($is_open): ?>
                      <span class="badge" style="background:#dcfce7; color:#15803d; font-weight:700; font-size:0.68rem; padding:2px 6px;">
                        <i class="fa-solid fa-circle-dot fa-fade"></i> Open
                      </span>
                    <?php elseif ($is_verified): ?>
                      <span class="badge" style="background:#ede9fe; color:#6d28d9; font-weight:700; font-size:0.68rem; padding:2px 6px;">
                        <i class="fa-solid fa-certificate"></i> Verified
                      </span>
                      <div style="font-size:0.66rem; color:#6d28d9; font-weight:600; margin-top:2px;">
                        <i class="fa-solid fa-check-double"></i> Certified
                      </div>
                    <?php elseif ($is_closed): ?>
                      <span class="badge" style="background:#fee2e2; color:#b91c1c; font-weight:700; font-size:0.68rem; padding:2px 6px;">
                        <i class="fa-solid fa-lock"></i> Locked
                      </span>
                    <?php else: ?>
                      <span class="badge" style="background:#f1f5f9; color:#475569; font-weight:700; font-size:0.68rem; padding:2px 6px;">
                        <i class="fa-solid fa-file-pen"></i> <?= ucfirst(htmlspecialchars($r['status'])) ?>
                      </span>
                    <?php endif; ?>
                  </td>

                  <!-- 6. Admin Actions (All 5 buttons always present, gray when disabled) -->
                  <td style="text-align:right;">
                    <div class="admin-act-group">
                      <!-- 1. Configure Record (Registry) -->
                      <button type="button" class="admin-tbl-act-btn" style="background:#2563eb; color:#fff;"
                              onclick="openAdminConfigureModal(<?= $json_r ?>)"
                              title="Configure Election Record &amp; Parameters" aria-label="Configure">
                        <i class="fa-solid fa-pen-to-square"></i>
                      </button>

                      <!-- 2. Security Lock / Reopen (Security) -->
                      <?php if ($is_open): ?>
                        <button type="button" class="admin-tbl-act-btn" style="background:#ea580c; color:#fff;"
                                onclick="toggleElectionLock(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['title'])) ?>', 'open')"
                                title="Security: Lock &amp; Close Election to prevent further votes" aria-label="Lock Election">
                          <i class="fa-solid fa-lock"></i>
                        </button>
                      <?php elseif ($is_closed && !$is_verified): ?>
                        <button type="button" class="admin-tbl-act-btn" style="background:#0d9488; color:#fff;"
                                onclick="toggleElectionLock(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['title'])) ?>', 'closed')"
                                title="Security: Reopen Election for active balloting" aria-label="Reopen Election">
                          <i class="fa-solid fa-lock-open"></i>
                        </button>
                      <?php else: ?>
                        <button type="button" class="admin-tbl-act-btn btn-disabled" disabled
                                title="<?= $is_verified ? 'Security Locked (Election Verified &amp; Certified)' : 'Security Toggle Unavailable' ?>"
                                aria-label="Security Locked">
                          <i class="fa-solid fa-lock"></i>
                        </button>
                      <?php endif; ?>

                      <!-- 3. Verify & Certify (Security & Registry) -->
                      <?php if ($is_closed && !$is_verified): ?>
                        <button type="button" class="admin-tbl-act-btn" style="background:#16a34a; color:#fff;"
                                onclick="openAdminVerifyModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['title'])) ?>')"
                                title="Verify &amp; Certify Election Clearance" aria-label="Verify &amp; Certify">
                          <i class="fa-solid fa-certificate"></i>
                        </button>
                      <?php elseif ($is_verified): ?>
                        <button type="button" class="admin-tbl-act-btn btn-disabled" disabled
                                title="Already Verified &amp; Certified (<?= htmlspecialchars($r['verified_at'] ?? 'Certified') ?>)"
                                aria-label="Already Verified">
                          <i class="fa-solid fa-check-double"></i>
                        </button>
                      <?php else: ?>
                        <button type="button" class="admin-tbl-act-btn btn-disabled" disabled
                                title="Verification Unavailable (Election must be closed first)"
                                aria-label="Verification Disabled">
                          <i class="fa-solid fa-certificate"></i>
                        </button>
                      <?php endif; ?>

                      <!-- 4. Results Output (Results: Verify configured result output; immutable votes) -->
                      <button type="button" class="admin-tbl-act-btn" style="background:#0284c7; color:#fff;"
                              onclick="openAdminResultsModal(<?= $r['id'] ?>)"
                              title="Results: Verify configured result output; immutable individual votes" aria-label="Verify Results">
                        <i class="fa-solid fa-chart-pie"></i>
                      </button>

                      <!-- 5. Audit Trail (Audit: Inspect election activity and access logs) -->
                      <button type="button" class="admin-tbl-act-btn" style="background:#7c3aed; color:#fff;"
                              onclick="openAdminAuditModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['title'])) ?>')"
                              title="Audit: Inspect election activity and access logs" aria-label="Inspect Audit">
                        <i class="fa-solid fa-clipboard-list"></i>
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
      </div><!-- end #adminElectionView -->
      <?php elseif ($sess_role === 'ssc'): ?>
      <!-- ══════════════════════════════════════════════════════
           2.7 STUDENT GOVERNANCE & ELECTIONS (SSC Role View)
           ══════════════════════════════════════════════════════ -->
      <div id="sscGovernanceView">
        <!-- 6 KPI Metric Cards Row (Clean Responsive System Layout) -->
        <div class="election-kpi-grid">
          <!-- Card 1: Active Elections -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-circle-check"></i></div>
            <div>
              <div class="election-kpi-val" style="color:#16a34a;"><?= number_format($ssc_active_count) ?></div>
              <div class="election-kpi-lbl">Active Elections</div>
            </div>
          </div>

          <!-- Card 2: Upcoming Elections -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-calendar-plus"></i></div>
            <div>
              <div class="election-kpi-val" style="color:#2563eb;"><?= number_format($ssc_upcoming_count) ?></div>
              <div class="election-kpi-lbl">Upcoming Elections</div>
            </div>
          </div>

          <!-- Card 3: Closed Elections -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#f1f5f9; color:#64748b;"><i class="fa-solid fa-lock"></i></div>
            <div>
              <div class="election-kpi-val" style="color:#475569;"><?= number_format($ssc_closed_count) ?></div>
              <div class="election-kpi-lbl">Closed Elections</div>
            </div>
          </div>

          <!-- Card 4: Total Candidates -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#ede9fe; color:#7c3aed;"><i class="fa-solid fa-users-line"></i></div>
            <div>
              <div class="election-kpi-val" style="color:#7c3aed;"><?= number_format($ssc_total_candidates) ?></div>
              <div class="election-kpi-lbl">Total Candidates</div>
            </div>
          </div>

          <!-- Card 5: Total Votes -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-square-poll-vertical"></i></div>
            <div>
              <div class="election-kpi-val" style="color:#d97706;"><?= number_format($ssc_total_votes) ?></div>
              <div class="election-kpi-lbl">Total Votes Cast</div>
            </div>
          </div>

          <!-- Card 6: Voter Turnout -->
          <div class="election-kpi-card">
            <div class="election-kpi-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-chart-pie"></i></div>
            <div>
              <div class="election-kpi-val" style="color:#0284c7;"><?= $ssc_turnout_pct ?>%</div>
              <div class="election-kpi-lbl">Voter Turnout</div>
            </div>
          </div>
        </div>

        <!-- Election Registry Table Card -->
        <div class="card" style="padding:22px 24px; border-radius:16px; border:1px solid #e2e8f0; margin-top:14px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:18px;">
            <div>
              <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-landmark" style="color:#2563eb;"></i> Election Registry
              </h3>
              <p style="margin:3px 0 0; font-size:0.78rem; color:#64748b;">
                Official accredited club elections, ballot verification, and real-time voter turnout.
              </p>
            </div>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
              <div style="position:relative; width:220px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:0.8rem; color:#94a3b8;"></i>
                <input type="text" id="sscElectionSearch" placeholder="Search registry..." oninput="filterSscElections()" style="width:100%; height:38px; padding:0 12px 0 34px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.82rem;" />
              </div>
              <select id="sscStatusFilter" onchange="filterSscElections()" style="height:38px; padding:0 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.82rem; font-weight:600; color:#334155; background:#fff;">
                <option value="all">All Statuses</option>
                <option value="pending_verification">Pending Verification</option>
                <option value="active">Active / Voting Open</option>
                <option value="closed">Voting Concluded</option>
                <option value="announced">Officers Announced</option>
                <option value="verified">Officers Verified</option>
              </select>
              <button class="card-btn" onclick="openCreateElectionModal()" style="height:38px; padding:0 16px; border-radius:8px; font-weight:700; background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:6px; font-size:0.82rem; cursor:pointer;">
                <i class="fa-solid fa-plus-circle"></i> Create Election
              </button>
            </div>
          </div>

          <div class="table-wrap" style="overflow-x:auto; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:0;">
            <table id="sscElectionRegistryTable" style="width:100%; min-width:1150px; border-collapse:collapse; font-size:0.82rem;">
              <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#475569; font-weight:700; text-transform:uppercase; font-size:0.72rem; letter-spacing:0.5px;">
                  <th style="padding:12px 14px; text-align:left; width:110px; white-space:nowrap;">Election Code</th>
                  <th style="padding:12px 14px; text-align:left; width:170px; min-width:150px;">Organization</th>
                  <th style="padding:12px 14px; text-align:left; min-width:170px;">Election Title</th>
                  <th style="padding:12px 14px; text-align:left; width:130px; white-space:nowrap;">Election Type</th>
                  <th style="padding:12px 14px; text-align:left; width:125px; white-space:nowrap;">Start Date</th>
                  <th style="padding:12px 14px; text-align:left; width:125px; white-space:nowrap;">Closing Date</th>
                  <th style="padding:12px 14px; text-align:center; width:85px; white-space:nowrap;">Candidates</th>
                  <th style="padding:12px 14px; text-align:center; width:95px; white-space:nowrap;">Eligible</th>
                  <th style="padding:12px 14px; text-align:center; width:85px; white-space:nowrap;">Votes Cast</th>
                  <th style="padding:12px 14px; text-align:center; width:85px; white-space:nowrap;">Turnout</th>
                  <th style="padding:12px 14px; text-align:center; width:135px; white-space:nowrap;">Status</th>
                  <th style="padding:12px 14px; text-align:right; width:190px; white-space:nowrap;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($ssc_registry)): ?>
                <tr>
                  <td colspan="12" class="empty-state-cell" style="text-align:center; padding:36px 16px; color:#64748b;">
                    <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; width:100%; margin:0 auto;">
                      <i class="fa-solid fa-inbox" style="font-size:2.2rem; color:#cbd5e1; margin-bottom:10px; display:inline-block;"></i>
                      <span style="font-weight:600; font-size:0.9rem; color:#475569; text-align:center;">No election registry records found.</span>
                    </div>
                  </td>
                </tr>
                <?php else: ?>
                <?php foreach ($ssc_registry as $r): ?>
                <?php
                  $statusBadge = match($r['status']) {
                    'active'                                             => '<span class="badge" style="background:#dcfce7; color:#15803d; font-weight:700; border:1px solid #bbf7d0;"><i class="fa-solid fa-circle-play"></i> Voting Open</span>',
                    'pending_verification', 'pending_approval', 'pending'=> '<span class="badge" style="background:#fef3c7; color:#b45309; font-weight:700; border:1px solid #fde68a;"><i class="fa-solid fa-clock"></i> Pending Verification</span>',
                    'rejected'                                           => '<span class="badge" style="background:#fee2e2; color:#b91c1c; font-weight:700; border:1px solid #fca5a5;"><i class="fa-solid fa-ban"></i> Rejected</span>',
                    'draft'                                              => '<span class="badge" style="background:#f1f5f9; color:#475569; font-weight:700; border:1px solid #cbd5e1;"><i class="fa-solid fa-file-pen"></i> Draft</span>',
                    'closed'                                             => '<span class="badge" style="background:#f1f5f9; color:#475569; font-weight:700; border:1px solid #cbd5e1;"><i class="fa-solid fa-lock"></i> Voting Concluded</span>',
                    'announced'                                          => '<span class="badge" style="background:#ede9fe; color:#7c3aed; font-weight:700; border:1px solid #ddd6fe;"><i class="fa-solid fa-bullhorn"></i> Officers Announced</span>',
                    'verified'                                           => '<span class="badge" style="background:#dbeafe; color:#1d4ed8; font-weight:700; border:1px solid #bfdbfe;"><i class="fa-solid fa-circle-check"></i> Officers Verified</span>',
                    default                                              => '<span class="badge">' . htmlspecialchars(ucfirst($r['status'])) . '</span>'
                  };
                ?>
                <tr class="ssc-reg-row" data-code="<?= strtolower($r['election_code']) ?>" data-org="<?= strtolower($r['club_name'] . ' ' . $r['club_code']) ?>" data-title="<?= strtolower($r['title']) ?>" data-status="<?= strtolower($r['status']) ?>" style="border-bottom:1px solid #f1f5f9;">
                  <td data-label="Election Code" style="padding:12px 14px; white-space:nowrap;">
                    <span class="badge" style="background:#f1f5f9; color:#0f172a; font-family:monospace; font-weight:700; border:1px solid #cbd5e1;"><?= htmlspecialchars($r['election_code']) ?></span>
                  </td>
                  <td data-label="Organization" style="padding:12px 14px;">
                    <div style="font-weight:700; color:#0f172a; white-space:nowrap;"><?= htmlspecialchars($r['club_code']) ?></div>
                    <div style="font-size:0.75rem; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px;" title="<?= htmlspecialchars($r['club_name']) ?>"><?= htmlspecialchars($r['club_name']) ?></div>
                  </td>
                  <td data-label="Election Title" style="padding:12px 14px;">
                    <span style="font-weight:700; color:#0f172a;"><?= htmlspecialchars($r['title']) ?></span>
                  </td>
                  <td data-label="Election Type" style="padding:12px 14px; white-space:nowrap;">
                    <span class="badge" style="background:#ede9fe; color:#7c3aed; font-weight:700; font-size:0.74rem; border:1px solid #ddd6fe;"><?= htmlspecialchars($r['election_type']) ?></span>
                  </td>
                  <td data-label="Start Date" style="padding:12px 14px; font-size:0.78rem; color:#475569; white-space:nowrap;">
                    <?= htmlspecialchars($r['start_date']) ?>
                  </td>
                  <td data-label="Closing Date" style="padding:12px 14px; font-size:0.78rem; color:#475569; white-space:nowrap;">
                    <?= htmlspecialchars($r['closing_date']) ?>
                  </td>
                  <td data-label="Candidates" style="padding:12px 14px; text-align:center; font-weight:700;">
                    <?= $r['candidates'] ?>
                  </td>
                  <td data-label="Eligible Voters" style="padding:12px 14px; text-align:center; color:#475569; font-weight:600;">
                    <?= number_format($r['eligible_voters']) ?>
                  </td>
                  <td data-label="Votes Cast" style="padding:12px 14px; text-align:center; font-weight:700; color:#0f172a;">
                    <?= number_format($r['votes_cast']) ?>
                  </td>
                  <td data-label="Turnout" style="padding:12px 14px; text-align:center;">
                    <span class="badge" style="background:#dcfce7; color:#16a34a; font-weight:800; font-size:0.78rem; border:1px solid #bbf7d0;"><?= $r['turnout'] ?></span>
                  </td>
                  <td data-label="Status" style="padding:12px 14px; text-align:center; white-space:nowrap;">
                    <?= $statusBadge ?>
                  </td>
                  <td data-label="Action" style="padding:12px 14px; text-align:right; white-space:nowrap;">
                    <div style="display:inline-flex; gap:4px; align-items:center; justify-content:flex-end; white-space:nowrap;">
                      <?php if (in_array($r['status'], ['pending_verification', 'pending_approval']) && in_array($sess_role, ['ssc', 'admin'])): ?>
                      <button class="el-act-btn el-act-verify" onclick="verifyPoll(<?= $r['id'] ?>, '<?= addslashes($r['title']) ?>')" title="Verify Poll &amp; Open Voting for Students">
                        <i class="fa-solid fa-stamp"></i> Verify
                      </button>
                      <button class="el-act-btn el-act-reject" onclick="rejectElection(<?= $r['id'] ?>, '<?= addslashes($r['title']) ?>')" title="Return / Reject Proposal">
                        <i class="fa-solid fa-ban"></i>
                      </button>
                      <?php endif; ?>
                      <?php if ($r['status'] === 'announced' && in_array($sess_role, ['ssc', 'admin'])): ?>
                      <button class="el-act-btn el-act-verify" onclick="showResultsModal(<?= $r['id'] ?>)" title="Review &amp; Verify Announced Officers">
                        <i class="fa-solid fa-stamp"></i> Verify
                      </button>
                      <?php endif; ?>
                      <?php if ($r['status'] === 'verified' && in_array($sess_role, ['ssc', 'admin'])): ?>
                      <button class="el-act-btn el-act-assign" onclick="showResultsModal(<?= $r['id'] ?>)" title="Assign Officer Roles in Club Roster">
                        <i class="fa-solid fa-user-check"></i> Assign
                      </button>
                      <?php endif; ?>
                      <button class="el-act-btn el-act-view" onclick="openViewElectionModal(<?= $r['id'] ?>)" title="View Election Details &amp; Slate">
                        <i class="fa-solid fa-eye"></i> View
                      </button>
                      <button class="el-act-btn el-act-monitor" onclick="showResultsModal(<?= $r['id'] ?>)" title="Live Monitor &amp; Voting Progress">
                        <i class="fa-solid fa-chart-column"></i> Monitor
                      </button>
                      <button class="el-act-btn el-act-audit" onclick="openAuditElectionModal(<?= $r['id'] ?>)" title="Audit Clearance Seal">
                        <i class="fa-solid fa-clipboard-check"></i> Audit
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
      </div><!-- end #sscGovernanceView -->
      <?php else: ?>

      <?php if ($sess_role === 'student' && !$student_is_org_member): ?>
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; padding:60px 24px; text-align:center; max-width:620px; margin:40px auto; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
          <div style="width:72px; height:72px; border-radius:50%; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:2rem; margin:0 auto 20px;">
            <i class="fa-solid fa-check-to-slot"></i>
          </div>
          <h3 style="font-size:1.35rem; font-weight:800; color:#0f172a; margin-bottom:12px;">Organization Membership Required</h3>
          <p style="color:#64748b; font-size:0.92rem; line-height:1.6; margin-bottom:24px;">
            The <strong>Elections &amp; Voting Portal</strong> is reserved for members of an accredited student organization. To participate in organizational elections, cast ballots, or view candidate profiles, your membership application must first be approved by the Club Adviser.
          </p>
          <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
            <a href="club_directory.php" class="card-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:8px; font-weight:700; background:#2563eb; color:#fff;">
              <i class="fa-solid fa-sitemap"></i> Explore Student Organizations
            </a>
            <a href="dashboard.php" class="card-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:8px; font-weight:600; background:#f1f5f9; color:#334155;">
              <i class="fa-solid fa-arrow-left"></i> Return to Dashboard
            </a>
          </div>
        </div>
      <?php else: ?>

      <!-- ══════════════════════════════════════════════════════
           FACULTY ADVISER CONTROL HUB (Visible to Adviser)
      ══════════════════════════════════════════════════════ -->
      <?php if ($sess_role === 'club_adviser' && $adviser_club): ?>
      <div class="adviser-hero-banner" style="background: #1a3a8c; border-radius:16px; padding:24px 28px; color:#fff; margin-bottom:24px; box-shadow:0 10px 25px -5px rgba(30,58,138,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
          <div>
            <div style="display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,0.15); padding:4px 12px; border-radius:20px; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
              <i class="fa-solid fa-user-shield"></i> Faculty Adviser Election Control Hub
            </div>
            <h2 style="margin:0; font-size:1.4rem; font-weight:800; color:#fff;">
              <?= htmlspecialchars($adviser_club['name']) ?> (<?= htmlspecialchars($adviser_club['code']) ?>)
            </h2>
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="card-btn" onclick="openCreateElectionModal()" style="background:#fff; color:#1e3a8a; font-weight:700; height:42px; padding:0 20px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.15); cursor:pointer;">
              <i class="fa-solid fa-plus-circle"></i> Create Election Pool
            </button>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════
           LANDING VIEW: Active Elections by Organization
      ══════════════════════════════════════════════════════ -->
      <div id="electionLanding">

        <!-- Section Header -->
        <div class="election-landing-header">
          <div>
            <h3 class="election-landing-title">
              <i class="fa-solid fa-circle-dot" style="color:#22c55e;"></i>
              <?= $sess_role === 'club_adviser' ? htmlspecialchars($adviser_club['code'] ?? 'Org') . ' Active Elections' : 'Active Campus Elections' ?>
            </h3>
            <p class="election-landing-sub">
              <?= $sess_role === 'club_adviser' ? 'Supervise election progress and candidate slates for ' . htmlspecialchars($adviser_club['name'] ?? 'your organization') . '.' : 'Select an organization below to access its Digital Balloting Booth or view Candidate Profiles.' ?>
            </p>
          </div>
        </div>

        <!-- Organization Election Cards -->
        <div class="election-org-grid" id="electionOrgGrid">
          <?php if (empty($active_elections)): ?>
          <div style="grid-column: 1/-1; background:#fff; padding:40px; text-align:center; border-radius:14px; border:1px solid #e2e8f0; color:#64748b;">
            <i class="fa-solid fa-box-archive" style="font-size:2.5rem; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
            <h4 style="margin:0 0 6px 0; font-size:1.1rem; color:#1e293b;">No Active Elections Found</h4>
            <p style="margin:0; font-size:0.85rem;">
              <?= $sess_role === 'club_adviser' ? 'Click "Create Election Pool" above to set up an election for ' . htmlspecialchars($adviser_club['name']) . '.' : 'There are no active elections currently scheduled.' ?>
            </p>
          </div>
          <?php else: ?>
          <?php foreach ($active_elections as $el): ?>
          <?php
            $voted       = !empty($el['user_has_voted']);
            $turnout     = $el['eligible'] > 0 ? round(($el['voted'] / $el['eligible']) * 100) : 0;
            $isClosed    = in_array($el['status'], ['closed', 'announced', 'verified', 'completed']);
            $isPending   = in_array($el['status'], ['pending_verification', 'pending_approval', 'pending']);
            $isOpen      = in_array($el['status'], ['open', 'active']);

            $statusLabel = match($el['status']) {
              'open', 'active'                                      => 'Voting Open',
              'pending_verification', 'pending_approval', 'pending' => 'Pending SSC Verification',
              'rejected'                                            => 'Proposal Returned',
              'closed'                                              => 'Voting Concluded',
              'announced'                                           => 'Officers Announced',
              'verified'                                            => 'Certified & Verified',
              'completed'                                           => 'Officers Commissioned',
              'counting'                                            => 'Vote Counting',
              default                                               => ucfirst($el['status'])
            };
            $statusClass = match($el['status']) {
              'open', 'active'                                      => 'eorg-live',
              'pending_verification', 'pending_approval', 'pending' => 'eorg-pending',
              'rejected'                                            => 'eorg-closed',
              'closed'                                              => 'eorg-closed',
              'announced'                                           => 'eorg-announced',
              'verified', 'completed'                               => 'eorg-verified',
              'counting'                                            => 'eorg-counting',
              default                                               => ''
            };
          ?>
          <div class="election-org-card <?= $isClosed ? 'closed' : '' ?>" data-election-id="<?= $el['id'] ?>">
            <!-- Card Top Band -->
            <div class="eorg-band" style="background:<?= $el['color'] ?>;">
              <div class="eorg-acronym"><?= $el['acronym'] ?></div>
              <div class="eorg-status-badge <?= $statusClass ?>">
                <?php if ($isOpen): ?><span class="eorg-dot"></span><?php endif; ?>
                <?= $statusLabel ?>
              </div>
            </div>

            <!-- Card Body -->
            <div class="eorg-body">
              <div class="eorg-org-name"><?= $el['org'] ?></div>
              <div class="eorg-title"><?= $el['title'] ?></div>
              <div class="eorg-meta" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
                <span><i class="fa-solid fa-clock"></i> Closes: <?= $el['closes'] ?></span>
                <?php if ($isOpen): ?>
                <span class="countdown-badge" id="timer-<?= $el['id'] ?>" data-closes="<?= htmlspecialchars($el['closes_raw'] ?? '') ?>" style="font-weight:700; color:#2563eb; background:#eff6ff; padding:3px 10px; border-radius:6px; font-size:0.75rem; display:inline-flex; align-items:center; gap:4px;">
                  <i class="fa-solid fa-stopwatch"></i> Active
                </span>
                <?php elseif ($isPending): ?>
                <span class="countdown-badge" style="font-weight:700; color:#b45309; background:#fef3c7; padding:3px 10px; border-radius:6px; font-size:0.75rem; display:inline-flex; align-items:center; gap:4px;">
                  <i class="fa-solid fa-hourglass-half"></i> Review in Progress
                </span>
                <?php endif; ?>
              </div>

              <!-- Turnout bar -->
              <div class="eorg-turnout">
                <div class="eorg-turnout-labels">
                  <span>Voter Turnout</span>
                  <span><?= $turnout ?>% (<?= $el['voted'] ?>/<?= $el['eligible'] ?>)</span>
                </div>
                <div class="eorg-turnout-track">
                  <div class="eorg-turnout-fill <?= $isOpen ? 'fill-green' : 'fill-grey' ?>" style="width:<?= $turnout ?>%"></div>
                </div>
              </div>

              <!-- Positions chips -->
              <div class="eorg-positions">
                <?php foreach ($el['positions'] as $pos): ?>
                <span class="eorg-pos-chip"><?= htmlspecialchars($pos) ?></span>
                <?php endforeach; ?>
              </div>

              <?php if ($sess_role === 'club_adviser'): ?>
              <!-- Managed Candidates Preview Chip List -->
              <div style="margin-top:14px; padding:12px 14px; background:#f8fafc; border-radius:12px; border:1px solid #e2e8f0;">
                <div style="font-size:0.75rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                  <span>Candidates (<?= count($el['candidates']) ?>)</span>
                  <a href="javascript:void(0)" onclick="openAddCandidateModal(<?= $el['id'] ?>)" style="color:#2563eb; text-decoration:none; font-weight:700; font-size:0.78rem;">+ ADD</a>
                </div>
                <?php if (empty($el['candidates'])): ?>
                  <span style="font-size:0.78rem; color:#94a3b8; font-style:italic;">No candidates added yet.</span>
                <?php else: ?>
                  <div style="display:flex; flex-wrap:wrap; gap:6px;">
                    <?php foreach ($el['candidates'] as $c): ?>
                    <span class="candidate-manage-chip">
                      <strong><?= htmlspecialchars($c['name']) ?></strong> (<?= htmlspecialchars($c['pos']) ?>)
                      <i class="fa-solid fa-xmark btn-del" title="Remove candidate" onclick="deleteCandidate(<?= $c['id'] ?>, '<?= addslashes($c['name']) ?>')"></i>
                    </span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if ($voted && $sess_role !== 'club_adviser'): ?>
              <div class="eorg-voted-badge">
                <i class="fa-solid fa-circle-check"></i> You have already voted
              </div>
              <?php endif; ?>
            </div>

            <!-- Card Actions -->
            <?php if ($isPending): ?>
            <div class="eorg-actions">
              <button class="eorg-btn eorg-btn-secondary" onclick="openCandidatesView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-id-card-clip"></i>
                Candidate Profiles
              </button>
              <?php if (in_array($sess_role, ['ssc', 'admin'])): ?>
              <button class="eorg-btn" style="background:#16a34a; color:#fff; font-weight:700;" onclick="verifyPoll(<?= $el['id'] ?>, '<?= addslashes($el['title']) ?>')">
                <i class="fa-solid fa-stamp"></i>
                Verify Poll &amp; Open Voting
              </button>
              <?php elseif ($sess_role === 'club_adviser'): ?>
              <button class="eorg-btn" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; cursor:default; font-weight:700;">
                <i class="fa-solid fa-clock-rotate-left"></i>
                Submitted to SSC for Verification
              </button>
              <?php else: ?>
              <button class="eorg-btn" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; cursor:not-allowed; font-weight:700;" disabled title="Voting will open once the Supreme Student Council (SSC) verifies this election poll.">
                <i class="fa-solid fa-lock"></i>
                Voting Locked (Awaiting SSC Verification)
              </button>
              <?php endif; ?>
            </div>
            <?php elseif ($isOpen): ?>
            <div class="eorg-actions">
              <button class="eorg-btn eorg-btn-secondary" onclick="openCandidatesView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-id-card-clip"></i>
                Candidate Profiles
              </button>
              <?php if ($sess_role === 'club_adviser'): ?>
              <button class="eorg-btn eorg-btn-primary" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-chart-pie"></i>
                Live Monitor
              </button>
              <button class="eorg-btn" style="background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; font-weight:700;" onclick="closeElection(<?= $el['id'] ?>)" title="End voting and close election pool">
                <i class="fa-solid fa-power-off"></i>
                Close Voting
              </button>
              <?php elseif ($sess_role === 'ssc'): ?>
              <button class="eorg-btn eorg-btn-primary" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-chart-pie"></i>
                Live Monitor
              </button>
              <?php elseif (!$voted): ?>
              <button class="eorg-btn eorg-btn-primary" onclick="openBoothView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-check-to-slot"></i>
                Enter Ballot Booth
              </button>
              <?php else: ?>
              <button class="eorg-btn eorg-btn-receipt" onclick="openBoothView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-receipt"></i>
                View My Receipt
              </button>
              <?php endif; ?>
            </div>
            <?php elseif ($el['status'] === 'closed'): ?>
            <div class="eorg-actions">
              <button class="eorg-btn eorg-btn-secondary" onclick="openCandidatesView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-id-card-clip"></i>
                Candidate Profiles
              </button>
              <?php if ($sess_role === 'club_adviser'): ?>
              <button class="eorg-btn eorg-btn-primary" style="background:#7c3aed; color:#fff;" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-bullhorn"></i>
                Review &amp; Announce Officers
              </button>
              <?php else: ?>
              <button class="eorg-btn eorg-btn-primary" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-chart-pie"></i>
                View Tally (Awaiting Announcement)
              </button>
              <?php endif; ?>
            </div>
            <?php elseif ($el['status'] === 'announced'): ?>
            <div class="eorg-actions">
              <button class="eorg-btn eorg-btn-secondary" onclick="openCandidatesView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-id-card-clip"></i>
                Candidate Profiles
              </button>
              <?php if (in_array($sess_role, ['ssc', 'admin'])): ?>
              <button class="eorg-btn" style="background:#059669; color:#fff; font-weight:700;" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-stamp"></i>
                Verify Elected Officers
              </button>
              <?php else: ?>
              <button class="eorg-btn eorg-btn-primary" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-trophy"></i>
                View Announced Officers
              </button>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="eorg-actions">
              <button class="eorg-btn eorg-btn-secondary" onclick="openCandidatesView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-id-card-clip"></i>
                Candidate Profiles
              </button>
              <?php if (in_array($sess_role, ['ssc', 'admin'])): ?>
              <button class="eorg-btn eorg-btn-primary" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-user-check"></i>
                View Results &amp; Assign Roles
              </button>
              <?php else: ?>
              <button class="eorg-btn eorg-btn-primary" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-trophy"></i>
                View Official Results
              </button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div><!-- end grid -->

        <!-- Past Results -->
        <div class="card" id="results" style="margin-top:28px;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <h3 style="margin:0;">
              <i class="fa-solid fa-trophy" style="color:#2563eb;"></i> 
              <?= $sess_role === 'club_adviser' ? htmlspecialchars($adviser_club['code'] ?? 'Org') . ' Past Election Results &amp; Archives' : 'Past Election Results &amp; Archives' ?>
            </h3>
          </div>
          <div class="table-wrap">
            <table id="pastElectionsTable">
              <thead>
                <tr>
                  <th style="padding:14px 18px;">Organization</th>
                  <th style="padding:14px 18px;">Election Date</th>
                  <th style="padding:14px 18px;">Winning President</th>
                  <th style="padding:14px 18px;">Votes Cast</th>
                  <th style="padding:14px 18px; text-align:center;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($past_results as $r): ?>
                <tr>
                  <td data-label="Organization" style="padding:14px 18px;"><strong><?= htmlspecialchars($r['org']) ?> (<?= $r['acronym'] ?>)</strong></td>
                  <td data-label="Date" style="padding:14px 18px; font-size:0.85rem; color:#475569;"><?= $r['date'] ?></td>
                  <td data-label="Winner" style="padding:14px 18px; font-size:0.85rem; color:#1e293b; font-weight:600;"><?= $r['winner'] ?></td>
                  <td data-label="Votes" style="padding:14px 18px; font-size:0.85rem; color:#475569;"><?= $r['votes'] ?></td>
                  <td data-label="Action" style="padding:14px 18px; text-align:center;">
                    <div style="display:inline-flex; gap:8px; align-items:center; justify-content:center; flex-wrap:wrap;">
                      <button class="card-btn" style="height:36px; padding:0 14px; border-radius:8px; font-weight:700; background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:6px; font-size:0.82rem; cursor:pointer;" onclick="showArchivedResultsModal(<?= $r['id'] ?>, '<?= addslashes($r['org']) ?>', '<?= addslashes($r['winner']) ?>', '<?= $r['votes'] ?>', '<?= $r['date'] ?>')">
                        <i class="fa-solid fa-eye"></i> View Results
                      </button>
                      <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
                      <button class="card-btn" style="height:36px; padding:0 14px; border-radius:8px; font-weight:700; background:#16a34a; color:#fff; display:inline-flex; align-items:center; gap:6px; font-size:0.82rem; cursor:pointer;" onclick="printSpecificPastElection(<?= $r['id'] ?>, '<?= addslashes($r['org']) ?>', '<?= addslashes($r['winner']) ?>', '<?= $r['votes'] ?>', '<?= $r['date'] ?>')">
                        <i class="fa-solid fa-print"></i> Export Winners &amp; Results
                      </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- end #electionLanding -->

      <!-- ══════════════════════════════════════════════════════
           BOOTH VIEW (hidden until org selected)
      ══════════════════════════════════════════════════════ -->
      <div id="boothView" style="display:none;">
        <div class="election-view-nav">
          <button class="election-back-btn" onclick="backToLanding()">
            <i class="fa-solid fa-arrow-left"></i> Back to Elections
          </button>
          <span class="election-view-breadcrumb" id="boothBreadcrumb"></span>
        </div>
        <div id="boothContent"></div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           CANDIDATES VIEW (hidden until org selected)
      ══════════════════════════════════════════════════════ -->
      <div id="candidatesView" style="display:none;">
        <div class="election-view-nav">
          <button class="election-back-btn" onclick="backToLanding()">
            <i class="fa-solid fa-arrow-left"></i> Back to Elections
          </button>
          <span class="election-view-breadcrumb" id="candsBreadcrumb"></span>
        </div>
        <div id="candsContent"></div>
      </div>
      <?php endif; ?>
      <?php endif; ?>

    </div><!-- end content-body -->
  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- end main -->

<!-- ════════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════ -->

<!-- 1. CREATE ELECTION MODAL -->
<div class="modal-overlay" id="createElectionModal">
  <div class="modal-card" style="max-width:680px; max-height:90vh; overflow-y:auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
      <h3 style="margin:0; font-size:1.1rem; color:#0f172a; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-box-archive" style="color:#2563eb;"></i> Create Election Pool
      </h3>
      <button onclick="closeModal('createElectionModal')" style="background:none; border:none; font-size:1.1rem; color:#64748b; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form id="createElectionForm" onsubmit="handleCreateElection(event)">
      <?php if ($sess_role === 'club_adviser' && $adviser_club): ?>
        <input type="hidden" name="club_id" id="createElectionClubId" value="<?= $adviser_club['id'] ?>"/>
        <div class="form-group">
          <label>Target Organization</label>
          <input type="text" value="<?= htmlspecialchars($adviser_club['name']) ?> (<?= htmlspecialchars($adviser_club['code']) ?>)" readonly style="background:#f8fafc; font-weight:700; color:#1e293b;"/>
        </div>
      <?php else: ?>
        <div class="form-group">
          <label>Target Organization</label>
          <select name="club_id" id="createElectionClubId" onchange="onElectionClubChanged()" required>
            <?php
              $r_clubs = $conn->query("SELECT id, name, code FROM clubs WHERE status='Active' ORDER BY name");
              while ($cl = $r_clubs->fetch_assoc()) {
                  echo "<option value='{$cl['id']}'>" . htmlspecialchars($cl['name']) . " ({$cl['code']})</option>";
              }
            ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="form-group">
        <label>Election Title *</label>
        <input type="text" name="title" placeholder="e.g. IT Society Executive Board Election 2026-2027" required/>
      </div>

      <div class="form-group">
        <label>Description / Voting Guidelines</label>
        <textarea name="description" rows="3" placeholder="Official balloting poll for active members..."></textarea>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <div class="form-group">
          <label>Voting Closing Date *</label>
          <input type="date" name="closing_date" id="closingDateInput" min="<?= date('Y-m-d') ?>" required/>
        </div>
        <div class="form-group">
          <label>Voting Closing Time *</label>
          <input type="time" name="closing_time" id="closingTimeInput" value="23:59" required/>
        </div>
      </div>

      <div class="form-group">
        <label>Positions to Vote For (Comma Separated)</label>
        <input type="text" name="positions" id="electionPositionsInput" value="President, Vice President, Secretary, Treasurer, Auditor" required oninput="updateDraftPositions()"/>
      </div>

      <!-- Candidate Slate Addition Section -->
      <div style="border-top: 1px dashed #cbd5e1; margin: 20px 0; padding-top: 16px;">
        <div style="margin-bottom: 12px; display:flex; justify-content:space-between; align-items:center;">
          <h4 style="margin: 0; font-size: 0.95rem; color: #0f172a; display: flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-user-plus" style="color: #2563eb;"></i> Candidate Slate (Add Candidates before Publishing)
          </h4>
          <span style="font-size: 0.78rem; color: #64748b;">Only active club members are eligible</span>
        </div>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 16px;">
          <!-- Member Selection / Student ID Lookup Row -->
          <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 10px; margin-bottom: 10px;">
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Student ID / Nominate Member *</label>
              <div style="position:relative;">
                <input type="text" id="draftCandStudentId" list="draftClubMembersList" placeholder="e.g. 2024-10001 or select member" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;" oninput="onDraftStudentIdInput(this.value)" autocomplete="off"/>
                <datalist id="draftClubMembersList"></datalist>
              </div>
              <input type="hidden" id="draftCandUserId" value="0"/>
              <div id="draftMemberStatus" style="font-size: 0.72rem; margin-top: 4px; min-height: 16px;"></div>
            </div>
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Position *</label>
              <select id="draftCandPosition" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                <option value="President">President</option>
                <option value="Vice President">Vice President</option>
                <option value="Secretary">Secretary</option>
                <option value="Treasurer">Treasurer</option>
                <option value="Auditor">Auditor</option>
              </select>
            </div>
          </div>

          <!-- Auto-Populated Candidate Details -->
          <div style="display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Candidate Name (Auto-fetched)</label>
              <input type="text" id="draftCandName" placeholder="Student Full Name" readonly style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #f1f5f9; font-weight: 600; color: #0f172a;"/>
            </div>
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Program (Auto-fetched)</label>
              <input type="text" id="draftCandProg" placeholder="BSIT" readonly style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #f1f5f9; color: #334155;"/>
            </div>
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Year Level (Auto-fetched)</label>
              <input type="text" id="draftCandYear" placeholder="3rd Year" readonly style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #f1f5f9; color: #334155;"/>
            </div>
          </div>

          <!-- Campaign Details -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Party / Alliance</label>
              <input type="text" id="draftCandParty" placeholder="Independent" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;"/>
            </div>
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Campaign Slogan / Tagline</label>
              <input type="text" id="draftCandTag" placeholder="Empowering students through innovation" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;"/>
            </div>
          </div>

          <div style="margin-bottom: 10px;">
            <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Key Achievements</label>
            <input type="text" id="draftCandAch" placeholder="Dean's Lister, Leadership Award" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;"/>
          </div>

          <button type="button" class="card-btn" onclick="addDraftCandidate()" style="background: #2563eb; color: #fff; font-size: 0.82rem; font-weight: 600; padding: 6px 14px; border-radius: 6px; cursor: pointer;">
            <i class="fa-solid fa-plus"></i> Add Candidate to Slate
          </button>
        </div>

        <div id="draftCandidatesList" style="margin-bottom: 16px;"></div>
        <input type="hidden" name="candidates" id="candidatesJsonInput" value="[]"/>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px;">
        <button type="button" class="card-btn" onclick="closeModal('createElectionModal')" style="background:#e2e8f0; color:#475569;">Cancel</button>
        <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700;"><i class="fa-solid fa-check"></i> Establish &amp; Publish Election Pool</button>
      </div>
    </form>
  </div>
</div>

<!-- 2. ADD CANDIDATE MODAL -->
<div class="modal-overlay" id="addCandidateModal">
  <div class="modal-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
      <h3 style="margin:0; font-size:1.1rem; color:#0f172a; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-user-plus" style="color:#2563eb;"></i> Add Candidate Profile
      </h3>
      <button onclick="closeModal('addCandidateModal')" style="background:none; border:none; font-size:1.1rem; color:#64748b; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form id="addCandidateForm" onsubmit="handleAddCandidate(event)">
      <div class="form-group">
        <label>Select Target Election *</label>
        <select name="election_id" id="candElectionSelect" onchange="onTargetElectionChanged()" required>
          <?php foreach ($active_elections as $el): ?>
            <option value="<?= $el['id'] ?>" data-club-id="<?= $el['club_id'] ?? 0 ?>"><?= $el['acronym'] ?> &bull; <?= $el['title'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Student ID / Nominate Active Member *</label>
        <input type="text" id="addCandStudentId" name="student_number" list="addCandMembersList" placeholder="Type Student ID (e.g. 2024-10001) or select member" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;" oninput="onAddCandStudentIdInput(this.value)" autocomplete="off" required/>
        <datalist id="addCandMembersList"></datalist>
        <input type="hidden" name="user_id" id="addCandUserId" value="0"/>
        <div id="addCandMemberStatus" style="font-size: 0.72rem; margin-top: 4px; min-height: 16px;"></div>
      </div>

      <div class="form-group">
        <label>Candidate Full Name (Auto-fetched) *</label>
        <input type="text" name="name" id="addCandName" placeholder="Student Full Name" readonly style="background:#f1f5f9; font-weight:600;" required/>
      </div>

      <div class="form-group">
        <label>Position *</label>
        <select name="position" id="candPositionSelect" required>
          <option value="President">President</option>
          <option value="Vice President">Vice President</option>
          <option value="Secretary">Secretary</option>
          <option value="Treasurer">Treasurer</option>
          <option value="Auditor">Auditor</option>
          <option value="P.R.O.">Public Relations Officer (P.R.O.)</option>
        </select>
      </div>

      <div class="form-group">
        <label>Party List / Alliance Name</label>
        <input type="text" name="party" placeholder="e.g. Innovate Tech Party / Independent"/>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
        <div class="form-group">
          <label>Year Level (Auto-fetched)</label>
          <input type="text" name="year_level" id="addCandYear" value="3rd Year" readonly style="background:#f1f5f9;"/>
        </div>
        <div class="form-group">
          <label>Program (Auto-fetched)</label>
          <input type="text" name="program" id="addCandProg" value="BSIT" readonly style="background:#f1f5f9;"/>
        </div>
        <div class="form-group">
          <label>GWA</label>
          <input type="text" name="gwa" value="1.5"/>
        </div>
      </div>

      <div class="form-group">
        <label>Campaign Tagline / Slogan</label>
        <input type="text" name="platform_tag" placeholder='"Empowering students through innovation."'/>
      </div>

      <div class="form-group">
        <label>Key Achievements (Comma Separated)</label>
        <input type="text" name="achievements" placeholder="Dean's Lister 2025, Hackathon Winner"/>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px;">
        <button type="button" class="card-btn" onclick="closeModal('addCandidateModal')" style="background:#e2e8f0; color:#475569;">Cancel</button>
        <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700;"><i class="fa-solid fa-plus"></i> Save Candidate</button>
      </div>
    </form>
  </div>
</div>

<!-- 3. OFFICIAL RESULTS & EXPORT MODAL -->
<div class="modal-overlay" id="resultsModal" style="display:none;">
  <div class="modal-card" style="max-width:760px; position:relative;">
    <button type="button" class="modal-close" onclick="closeModal('resultsModal')" data-close="resultsModal" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div id="printableResultsArea">
      <!-- School Header with Official Logo -->
      <div style="display:flex; align-items:center; justify-content:center; gap:16px; text-align:center; padding-bottom:16px; border-bottom:2px solid #1e3a8a; margin-bottom:18px;">
        <img src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" style="height:64px; width:auto; object-fit:contain;" />
        <div style="text-align:left;">
          <div style="font-size:1.1rem; font-weight:800; color:#1e3a8a; text-transform:uppercase; letter-spacing:0.5px; line-height:1.2;">Bestlink College of the Philippines</div>
          <div style="font-size:0.75rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">Office of Student Affairs &amp; Services &bull; Student Commission on Elections</div>
          <div style="font-size:0.88rem; font-weight:800; color:#2563eb; margin-top:4px;" id="modalResHeader">Official Election Results Summary</div>
        </div>
      </div>

      <div style="font-size:0.8rem; color:#475569; background:#f8fafc; padding:10px 14px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:16px;" id="modalResSub">
        Verified Digital Balloting Audit Report
      </div>

      <?php if (in_array($sess_role, ['admin', 'ssc'])): ?>
      <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:0.80rem; display:flex; align-items:center; gap:10px;">
        <i class="fa-solid fa-shield-halved" style="font-size:1.15rem; color:#2563eb; flex-shrink:0;"></i>
        <div>
          <strong>Results Integrity Protocol:</strong> Verify configured result output; do not casually edit individual votes. Balloting tallies and cryptographic voter hashes are immutable.
        </div>
      </div>
      <?php endif; ?>

      <div id="modalResBody"></div>
    </div>

    <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px; border-top:1px solid #f1f5f9; padding-top:16px;">
      <button type="button" class="card-btn" id="printResultsBtn" onclick="printCurrentElectionReport()" style="background:#16a34a; color:#fff; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
        <i class="fa-solid fa-print"></i> Print / Export Report
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>
<!-- 4. VIEW ELECTION MODAL (SSC / Admin) -->
<div class="modal-overlay" id="viewElectionModal" style="display:none;">
  <div class="modal-card" style="max-width:720px; position:relative;">
    <button type="button" class="modal-close" onclick="closeModal('viewElectionModal')" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px; padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;">
      <div style="width:42px; height:42px; border-radius:10px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
        <i class="fa-solid fa-eye"></i>
      </div>
      <div>
        <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;" id="viewModalTitle">Election Details</h3>
        <div style="display:flex; align-items:center; gap:8px; margin-top:4px; flex-wrap:wrap;">
          <span class="badge" id="viewModalCode" style="font-family:monospace; background:#f1f5f9; font-weight:700; color:#0f172a; border:1px solid #cbd5e1;"></span>
          <span class="badge" id="viewModalOrg" style="background:#dbeafe; color:#1d4ed8; font-weight:700;"></span>
          <span class="badge" id="viewModalType" style="background:#ede9fe; color:#7c3aed; font-weight:700;"></span>
        </div>
      </div>
    </div>

    <!-- Metadata Grid -->
    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; margin-bottom:18px; background:#f8fafc; padding:14px; border-radius:10px; border:1px solid #e2e8f0;">
      <div>
        <div style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Opening Date</div>
        <div style="font-size:0.85rem; font-weight:700; color:#0f172a; margin-top:2px;" id="viewModalStart"></div>
      </div>
      <div>
        <div style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Closing Date</div>
        <div style="font-size:0.85rem; font-weight:700; color:#0f172a; margin-top:2px;" id="viewModalClose"></div>
      </div>
      <div>
        <div style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Status & Turnout</div>
        <div style="font-size:0.85rem; font-weight:700; color:#0f172a; margin-top:2px;" id="viewModalStatus"></div>
      </div>
    </div>

    <div style="margin-bottom:14px;">
      <div style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px;">Election Guidelines / Description</div>
      <div style="font-size:0.85rem; color:#334155; line-height:1.45; background:#fff; border:1px solid #e2e8f0; padding:10px 14px; border-radius:8px;" id="viewModalDesc"></div>
    </div>

    <div style="margin-bottom:16px;">
      <h4 style="margin:0 0 8px 0; font-size:0.88rem; font-weight:800; color:#334155; text-transform:uppercase; letter-spacing:0.5px;">Registered Slate &amp; Candidates</h4>
      <div id="viewModalCandidatesList" style="max-height:260px; overflow-y:auto; display:flex; flex-direction:column; gap:8px;"></div>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:18px; border-top:1px solid #f1f5f9; padding-top:14px;">
      <button type="button" class="card-btn" onclick="closeModal('viewElectionModal')" style="background:#e2e8f0; color:#475569; font-weight:700;">Close</button>
    </div>
  </div>
</div>

<!-- 5. AUDIT ELECTION MODAL (SSC / Admin) -->
<div class="modal-overlay" id="auditElectionModal" style="display:none;">
  <div class="modal-card" style="max-width:720px; position:relative;">
    <button type="button" class="modal-close" onclick="closeModal('auditElectionModal')" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px; padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;">
      <div style="width:42px; height:42px; border-radius:10px; background:#f5f3ff; color:#7c3aed; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
        <i class="fa-solid fa-shield-halved"></i>
      </div>
      <div>
        <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:#0f172a;">Election Governance Verification &amp; Certification</h3>
        <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">Supreme Student Council Electoral Oversight &amp; Certification</div>
      </div>
    </div>

    <div id="auditModalContent"></div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top:1px solid #f1f5f9; padding-top:16px;">
      <button type="button" class="card-btn" onclick="printAuditRecord()" style="background:#16a34a; color:#fff; font-weight:700; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
        <i class="fa-solid fa-print"></i> Print Audit Record
      </button>
      <button type="button" class="card-btn" onclick="closeModal('auditElectionModal')" style="background:#e2e8f0; color:#475569; font-weight:700; cursor:pointer;">Close</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     ADMIN MODALS (Election Administration)
     ══════════════════════════════════════════════════════ -->

<!-- ADMIN CONFIGURE ELECTION MODAL -->
<div class="modal-overlay" id="adminConfigureElectionModal" style="display:none;">
  <div class="modal-card" style="max-width:600px; position:relative;">
    <button type="button" class="modal-close" onclick="closeModal('adminConfigureElectionModal')" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px; padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;">
      <div style="width:40px; height:40px; border-radius:10px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
        <i class="fa-solid fa-pen-to-square"></i>
      </div>
      <div>
        <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#0f172a;">Configure Election Parameters</h3>
        <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">Election Registry: Update official record parameters and voter thresholds.</div>
      </div>
    </div>
    <form id="adminConfigureForm" onsubmit="handleAdminConfigure(event)">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"/>
      <input type="hidden" id="adminCfgElectionId" name="election_id" value=""/>

      <div class="form-group">
        <label>Election Title *</label>
        <input type="text" id="adminCfgTitle" name="title" required/>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
        <div class="form-group">
          <label>Election Type</label>
          <select id="adminCfgType" name="election_type">
            <option value="Student Governance">Student Governance</option>
            <option value="Club Executive">Club Executive</option>
            <option value="Special Plebiscite">Special Plebiscite</option>
            <option value="Class Representative">Class Representative</option>
          </select>
        </div>
        <div class="form-group">
          <label>Eligible Voters Threshold</label>
          <input type="number" id="adminCfgEligible" name="eligible_voters" min="0" placeholder="e.g. 500"/>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
        <div class="form-group">
          <label>Closing Date</label>
          <input type="date" id="adminCfgClosingDate" name="closing_date"/>
        </div>
        <div class="form-group">
          <label>Closing Time</label>
          <input type="time" id="adminCfgClosingTime" name="closing_time" value="23:59"/>
        </div>
      </div>

      <div class="form-group">
        <label>Positions (Comma-separated)</label>
        <input type="text" id="adminCfgPositions" name="positions" placeholder="President, Vice President, Secretary, Treasurer"/>
      </div>

      <div class="form-group">
        <label>Guidelines / Description</label>
        <textarea id="adminCfgDescription" name="description" rows="3"></textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #f1f5f9; padding-top:14px;">
        <button type="button" class="card-btn" onclick="closeModal('adminConfigureElectionModal')" style="background:#e2e8f0; color:#475569; font-weight:700;">Cancel</button>
        <button type="submit" id="adminCfgSubmitBtn" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-floppy-disk"></i> Save Configuration
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ADMIN VERIFY ELECTION MODAL -->
<div class="modal-overlay" id="adminVerifyElectionModal" style="display:none;">
  <div class="modal-card" style="max-width:540px; position:relative;">
    <button type="button" class="modal-close" onclick="closeModal('adminVerifyElectionModal')" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px; padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;">
      <div style="width:40px; height:40px; border-radius:10px; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
        <i class="fa-solid fa-certificate"></i>
      </div>
      <div>
        <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#0f172a;">Verify &amp; Certify Election</h3>
        <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">Security &amp; Registry: Execute official administrative certification.</div>
      </div>
    </div>
    <form id="adminVerifyForm" onsubmit="handleAdminVerify(event)">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"/>
      <input type="hidden" id="adminVerifyElectionId" name="election_id" value=""/>

      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; margin-bottom:14px;">
        <div style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase;">Target Election</div>
        <div style="font-size:0.92rem; font-weight:800; color:#0f172a; margin-top:2px;" id="adminVerifyTitleDisplay"></div>
      </div>

      <div class="alert alert-info" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:8px; padding:10px 12px; font-size:0.78rem; margin-bottom:14px; display:flex; align-items:flex-start; gap:8px;">
        <i class="fa-solid fa-shield-halved" style="margin-top:2px;"></i>
        <div>
          <strong>Institutional Certification Protocol:</strong> Once certified, election parameters and balloting tallies are locked permanently. Individual votes remain immutable.
        </div>
      </div>

      <div class="form-group">
        <label>Audit &amp; Verification Clearance Notes *</label>
        <textarea id="adminVerifyNotes" name="audit_notes" rows="3" required placeholder="State verification summary, compliance with voting guidelines, and clearance remarks..."></textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #f1f5f9; padding-top:14px;">
        <button type="button" class="card-btn" onclick="closeModal('adminVerifyElectionModal')" style="background:#e2e8f0; color:#475569; font-weight:700;">Cancel</button>
        <button type="submit" id="adminVerifySubmitBtn" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-stamp"></i> Certify &amp; Verify Election
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ADMIN AUDIT LOGS MODAL -->
<div class="modal-overlay" id="adminAuditLogsModal" style="display:none;">
  <div class="modal-card" style="max-width:820px; position:relative;">
    <button type="button" class="modal-close" onclick="closeModal('adminAuditLogsModal')" style="position:absolute; top:18px; right:18px; background:none; border:none; font-size:1.1rem; color:#64748b; cursor:pointer;" title="Close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px; padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;">
      <div style="width:40px; height:40px; border-radius:10px; background:#ede9fe; color:#7c3aed; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
        <i class="fa-solid fa-clipboard-check"></i>
      </div>
      <div>
        <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#0f172a;">Election Activity &amp; Access Audit Logs</h3>
        <div style="font-size:0.75rem; color:#64748b; margin-top:2px;" id="adminAuditTargetSubtitle">Audit: Real-time inspection of balloting actions, security locks, and config updates.</div>
      </div>
    </div>

    <!-- Filter input inside modal -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
      <div style="position:relative; width:260px;">
        <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:0.75rem; color:#94a3b8;"></i>
        <input type="text" id="adminAuditFilterInput" placeholder="Filter audit events..." oninput="filterAdminAuditList()" style="width:100%; height:32px; padding:0 10px 0 28px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem;" />
      </div>
      <span style="font-size:0.75rem; color:#64748b;" id="adminAuditCountBadge">Showing latest events</span>
    </div>

    <!-- Scrollable Table -->
    <div class="table-wrap" style="max-height:380px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px;">
      <table class="table" style="width:100%; border-collapse:collapse; font-size:0.76rem;" id="adminAuditTable">
        <thead style="position:sticky; top:0; background:#f8fafc; z-index:1;">
          <tr>
            <th style="padding:8px 10px; border-bottom:1px solid #e2e8f0; width:18%;">Timestamp</th>
            <th style="padding:8px 10px; border-bottom:1px solid #e2e8f0; width:22%;">Actor &amp; Role</th>
            <th style="padding:8px 10px; border-bottom:1px solid #e2e8f0; width:18%;">Action</th>
            <th style="padding:8px 10px; border-bottom:1px solid #e2e8f0; width:14%;">IP Address</th>
            <th style="padding:8px 10px; border-bottom:1px solid #e2e8f0; width:28%;">Audit Detail</th>
          </tr>
        </thead>
        <tbody id="adminAuditTableBody">
          <tr>
            <td colspan="5" style="text-align:center; padding:30px; color:#94a3b8;">
              <i class="fa-solid fa-spinner fa-spin"></i> Loading audit logs...
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px; border-top:1px solid #f1f5f9; padding-top:12px;">
      <button type="button" class="card-btn" onclick="closeModal('adminAuditLogsModal')" style="background:#e2e8f0; color:#475569; font-weight:700;">Close</button>
    </div>
  </div>
</div>

<script>
// Serialise PHP elections data into JS
const electionsData = <?= json_encode($active_elections) ?>;
const pastResultsData = <?= json_encode($past_results) ?>;
const sscRegistryData = <?= json_encode($ssc_registry) ?>;
const sessRole = '<?= $sess_role ?>';

function filterSscElections() {
  const searchInput = document.getElementById('sscElectionSearch');
  const statusFilter = document.getElementById('sscStatusFilter');
  if (!searchInput || !statusFilter) return;

  const query = searchInput.value.toLowerCase().trim();
  const status = statusFilter.value.toLowerCase();

  const rows = document.querySelectorAll('.ssc-reg-row');
  rows.forEach(row => {
    const code = row.getAttribute('data-code') || '';
    const org = row.getAttribute('data-org') || '';
    const title = row.getAttribute('data-title') || '';
    const rowStatus = row.getAttribute('data-status') || '';

    const matchesQuery = !query || code.includes(query) || org.includes(query) || title.includes(query);
    const matchesStatus = (status === 'all') || (rowStatus === status);

    if (matchesQuery && matchesStatus) {
      row.removeAttribute('data-search-hidden');
      row.style.display = '';
    } else {
      row.setAttribute('data-search-hidden', 'true');
      row.style.display = 'none';
    }
  });

  if (window.refreshTablePagination) {
    window.refreshTablePagination('#sscElectionRegistryTable');
  }
}

// ── Admin Election Administration Handlers ──
function filterAdminElections() {
  const searchInput = document.getElementById('adminElectionSearch');
  const statusFilter = document.getElementById('adminStatusFilter');
  if (!searchInput || !statusFilter) return;

  const query = searchInput.value.toLowerCase().trim();
  const status = statusFilter.value.toLowerCase();

  const rows = document.querySelectorAll('.admin-el-row');
  rows.forEach(row => {
    const code = row.getAttribute('data-code') || '';
    const org = row.getAttribute('data-org') || '';
    const title = row.getAttribute('data-title') || '';
    const rowStatus = row.getAttribute('data-status') || '';

    const matchesQuery = !query || code.includes(query) || org.includes(query) || title.includes(query);
    const matchesStatus = (status === 'all') || (rowStatus === status);

    if (matchesQuery && matchesStatus) {
      row.removeAttribute('data-search-hidden');
      row.style.display = '';
    } else {
      row.setAttribute('data-search-hidden', 'true');
      row.style.display = 'none';
    }
  });

  if (window.refreshTablePagination) {
    window.refreshTablePagination('#adminElectionTable');
  }
}

function openAdminConfigureModal(el) {
  if (!el) return;
  document.getElementById('adminCfgElectionId').value = el.id || '';
  document.getElementById('adminCfgTitle').value = el.title || '';
  document.getElementById('adminCfgType').value = el.election_type || 'Student Governance';
  document.getElementById('adminCfgEligible').value = el.eligible_voters || '';
  
  if (el.closes_at) {
    const dt = new Date(el.closes_at.replace(' ', 'T'));
    if (!isNaN(dt.getTime())) {
      document.getElementById('adminCfgClosingDate').value = el.closes_at.substring(0, 10);
      document.getElementById('adminCfgClosingTime').value = el.closes_at.substring(11, 16);
    }
  }

  if (Array.isArray(el.positions)) {
    document.getElementById('adminCfgPositions').value = el.positions.join(', ');
  } else if (typeof el.positions === 'string') {
    try {
      const parsed = JSON.parse(el.positions);
      if (Array.isArray(parsed)) {
        document.getElementById('adminCfgPositions').value = parsed.join(', ');
      } else {
        document.getElementById('adminCfgPositions').value = el.positions;
      }
    } catch(err) {
      document.getElementById('adminCfgPositions').value = el.positions || '';
    }
  } else {
    document.getElementById('adminCfgPositions').value = 'President, Vice President, Secretary, Treasurer';
  }

  document.getElementById('adminCfgDescription').value = el.description || '';
  document.getElementById('adminConfigureElectionModal').style.display = 'flex';
}

async function handleAdminConfigure(e) {
  e.preventDefault();
  const form = e.target;
  const submitBtn = document.getElementById('adminCfgSubmitBtn');
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
  }

  const fd = new FormData(form);
  fd.append('action', 'configure_election');

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Configuration';
      }
    }
  } catch (err) {
    alert('Network error while saving election configuration.');
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Configuration';
    }
  }
}

async function verifyPoll(electionId, title) {
  const confirmed = typeof window.showConfirmModal === 'function'
    ? await window.showConfirmModal(
        'Verify Election Poll & Open Voting?',
        `Are you sure you want to verify the election poll "${title || 'this election'}" created by the Faculty Adviser? Once verified by the Supreme Student Council (SSC), voting will immediately open for all active student members.`,
        { type: 'info', confirmText: 'Yes, Verify Poll & Open Voting' }
      )
    : confirm(`Verify poll and open voting for "${title || 'this election'}"?`);
  if (!confirmed) return;

  const fd = new FormData();
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.getAttribute('content'));
  fd.append('action', 'verify_poll');
  fd.append('election_id', electionId);

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      if (typeof window.showToastNotification === 'function') {
        window.showToastNotification(data.message, 'success');
      } else {
        alert(data.message);
      }
      setTimeout(() => window.location.reload(), 600);
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error verifying election poll.');
  }
}
const approveElection = verifyPoll;

async function announceOfficers(electionId) {
  const el = (typeof electionsData !== 'undefined') ? electionsData.find(e => e.id == electionId) : null;
  const title = el ? el.title : 'this election';

  const confirmed = typeof window.showConfirmModal === 'function'
    ? await window.showConfirmModal(
        'Announce Elected Officers?',
        `As Faculty Adviser, do you confirm and officially announce the proclaimed winners of "${title}"? This will transmit the results to the Supreme Student Council (SSC) for official verification and role commissioning.`,
        { type: 'decision', confirmText: 'Yes, Announce Elected Officers' }
      )
    : confirm(`Announce elected officers for "${title}" to SSC?`);
  if (!confirmed) return;

  const fd = new FormData();
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.getAttribute('content'));
  fd.append('action', 'announce_officers');
  fd.append('election_id', electionId);

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      if (typeof window.showToastNotification === 'function') {
        window.showToastNotification(data.message, 'success');
      } else {
        alert(data.message);
      }
      setTimeout(() => window.location.reload(), 600);
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error announcing elected officers.');
  }
}

async function submitVerifyOfficers(electionId) {
  const el = (typeof electionsData !== 'undefined') ? electionsData.find(e => e.id == electionId) : null;
  const title = el ? el.title : 'this election';

  const confirmed = typeof window.showConfirmModal === 'function'
    ? await window.showConfirmModal(
        'Verify & Certify Elected Officers?',
        `Does the Supreme Student Council (SSC) officially verify and certify the announced elected officers for "${title}"? Once verified, SSC will proceed with assigning official officer roles in the club roster.`,
        { type: 'decision', confirmText: 'Yes, Verify Elected Officers' }
      )
    : confirm(`Verify announced elected officers for "${title}"?`);
  if (!confirmed) return;

  const fd = new FormData();
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.getAttribute('content'));
  fd.append('action', 'verify_officers');
  fd.append('election_id', electionId);

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      if (typeof window.showToastNotification === 'function') {
        window.showToastNotification(data.message, 'success');
      } else {
        alert(data.message);
      }
      setTimeout(() => window.location.reload(), 600);
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error verifying elected officers.');
  }
}

async function rejectElection(electionId, title) {
  const reason = prompt(`Enter rejection feedback/reason for "${title || 'this election'}":`, 'Please review and adjust election guidelines before resubmission.');
  if (reason === null || !reason.trim()) return;

  const fd = new FormData();
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.getAttribute('content'));
  fd.append('action', 'reject_election');
  fd.append('election_id', electionId);
  fd.append('rejection_reason', reason.trim());

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error returning election proposal.');
  }
}

async function toggleElectionLock(electionId, title, currentStatus) {
  const isCurrentlyOpen = (currentStatus === 'open' || currentStatus === 'active');
  const actionQuestion = isCurrentlyOpen
    ? `Do you want to lock and close election "${title}"? Active voting will be closed and ballots frozen.`
    : `Do you want to unlock and reopen election "${title}" for active balloting?`;

  const confirmed = await window.showConfirmModal(
    isCurrentlyOpen ? 'Lock Election?' : 'Unlock Election?',
    actionQuestion,
    {
      type: isCurrentlyOpen ? 'warning' : 'info',
      confirmText: isCurrentlyOpen ? 'Yes, Lock Election' : 'Yes, Unlock Election'
    }
  );
  if (!confirmed) return;

  const fd = new FormData();
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.getAttribute('content'));
  fd.append('action', 'toggle_lock');
  fd.append('election_id', electionId);

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error while updating election security state.');
  }
}

function openAdminVerifyModal(electionId, title) {
  document.getElementById('adminVerifyElectionId').value = electionId;
  document.getElementById('adminVerifyTitleDisplay').textContent = title || ('Election #' + electionId);
  document.getElementById('adminVerifyNotes').value = 'Official institutional verification clearance executed by Administration.';
  document.getElementById('adminVerifyElectionModal').style.display = 'flex';
}

async function handleAdminVerify(e) {
  e.preventDefault();
  const form = e.target;
  const submitBtn = document.getElementById('adminVerifySubmitBtn');
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Certifying...';
  }

  const fd = new FormData(form);
  fd.append('action', 'verify_election');

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-stamp"></i> Certify & Verify Election';
      }
    }
  } catch (err) {
    alert('Network error while verifying election.');
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fa-solid fa-stamp"></i> Certify & Verify Election';
    }
  }
}

function openAdminResultsModal(electionId) {
  showResultsModal(electionId);
}

let currentAdminAuditLogs = [];

async function openAdminAuditModal(electionId, title) {
  const subtitle = document.getElementById('adminAuditTargetSubtitle');
  if (subtitle) {
    subtitle.textContent = electionId > 0
      ? `Audit Trail for: ${title} (Real-time inspection of balloting, locks & updates)`
      : `All Campus Elections Activity & Access Logs`;
  }

  const tbody = document.getElementById('adminAuditTableBody');
  if (tbody) {
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:30px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Loading audit logs...</td></tr>`;
  }
  const filterInput = document.getElementById('adminAuditFilterInput');
  if (filterInput) filterInput.value = '';
  document.getElementById('adminAuditLogsModal').style.display = 'flex';

  try {
    const res = await fetch(`../shared/election_actions.php?action=get_election_audit&election_id=${electionId}`);
    const data = await res.json();
    if (data.success) {
      currentAdminAuditLogs = data.logs || [];
      renderAdminAuditList(currentAdminAuditLogs);
    } else {
      if (tbody) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:24px; color:#ef4444;"><i class="fa-solid fa-triangle-exclamation"></i> Error loading audit logs: ${data.message}</td></tr>`;
      }
    }
  } catch (err) {
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:24px; color:#ef4444;"><i class="fa-solid fa-triangle-exclamation"></i> Network error fetching audit trail.</td></tr>`;
    }
  }
}

function renderAdminAuditList(logs) {
  const tbody = document.getElementById('adminAuditTableBody');
  const countBadge = document.getElementById('adminAuditCountBadge');
  if (!tbody) return;

  if (countBadge) {
    countBadge.textContent = `Showing ${logs.length} event${logs.length === 1 ? '' : 's'}`;
  }

  if (logs.length === 0) {
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:32px 14px; color:#64748b;"><i class="fa-solid fa-inbox" style="font-size:1.6rem; color:#cbd5e1; margin-bottom:6px; display:block;"></i>No audit events recorded for this selection.</td></tr>`;
    return;
  }

  tbody.innerHTML = logs.map(l => {
    const actorName = (l.first_name || l.last_name) ? `${l.first_name || ''} ${l.last_name || ''}`.trim() : `User #${l.user_id}`;
    const roleBadge = l.role ? `<span class="badge" style="background:#f1f5f9; color:#475569; font-size:0.65rem; padding:1px 4px; text-transform:uppercase;">${l.role}</span>` : '';
    const actionColor = (l.action && l.action.includes('lock')) ? '#ea580c' : ((l.action && l.action.includes('verify')) ? '#16a34a' : ((l.action && l.action.includes('vote')) ? '#2563eb' : '#7c3aed'));
    
    return `
      <tr>
        <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; font-family:monospace; font-size:0.72rem; color:#475569; white-space:nowrap;">
          ${l.created_at || 'N/A'}
        </td>
        <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0;">
          <div style="font-weight:700; color:#0f172a; font-size:0.78rem;">${actorName}</div>
          <div style="margin-top:2px;">${roleBadge} <span style="font-size:0.68rem; color:#94a3b8;">${l.email || ''}</span></div>
        </td>
        <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0;">
          <span style="font-weight:700; font-size:0.72rem; color:${actionColor}; background:${actionColor}15; padding:2px 6px; border-radius:4px; font-family:monospace;">
            ${l.action || 'audit'}
          </span>
        </td>
        <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; font-family:monospace; font-size:0.70rem; color:#64748b;">
          ${l.ip_address || '127.0.0.1'}
        </td>
        <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; font-size:0.76rem; color:#334155; line-height:1.3;">
          ${l.detail || 'Standard election operation.'}
        </td>
      </tr>
    `;
  }).join('');
}

function filterAdminAuditList() {
  const input = document.getElementById('adminAuditFilterInput');
  if (!input) return;
  const q = input.value.toLowerCase().trim();
  if (!q) {
    renderAdminAuditList(currentAdminAuditLogs);
    return;
  }
  const filtered = currentAdminAuditLogs.filter(l => {
    const actor = `${l.first_name || ''} ${l.last_name || ''} ${l.email || ''} ${l.role || ''}`.toLowerCase();
    const action = (l.action || '').toLowerCase();
    const detail = (l.detail || '').toLowerCase();
    const ip = (l.ip_address || '').toLowerCase();
    return actor.includes(q) || action.includes(q) || detail.includes(q) || ip.includes(q);
  });
  renderAdminAuditList(filtered);
}

function openViewElectionModal(id) {
  const el = sscRegistryData.find(e => e.id == id);
  if (!el) return;

  document.getElementById('viewModalTitle').textContent = el.title;
  document.getElementById('viewModalCode').textContent = el.election_code;
  document.getElementById('viewModalOrg').textContent = el.club_code + ' - ' + el.club_name;
  document.getElementById('viewModalType').textContent = el.election_type;
  document.getElementById('viewModalStart').textContent = el.start_date;
  document.getElementById('viewModalClose').textContent = el.closing_date;
  document.getElementById('viewModalStatus').innerHTML = `
    <span style="font-weight:700; text-transform:capitalize;">${el.status}</span> &bull; 
    <span style="color:#16a34a; font-weight:800;">${el.turnout} Turnout</span> (${el.votes_cast}/${el.eligible_voters})
  `;
  document.getElementById('viewModalDesc').textContent = el.description || 'No specific description provided.';

  // Render Candidates from active elections dataset if available
  const activeEl = electionsData.find(e => e.id == id);
  const candList = document.getElementById('viewModalCandidatesList');
  candList.innerHTML = '';

  if (activeEl && activeEl.candidates && activeEl.candidates.length > 0) {
    activeEl.candidates.forEach(c => {
      const item = document.createElement('div');
      item.style.cssText = 'background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;';
      item.innerHTML = `
        <div>
          <div style="font-weight:700; color:#0f172a; font-size:0.9rem;">${c.name} <span class="badge" style="background:#ede9fe; color:#6d28d9; margin-left:6px;">${c.pos}</span></div>
          <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
            <i class="fa-solid fa-flag"></i> ${c.party} &bull; ${c.year} (${c.prog}) &bull; Platform: "${c.tag || 'Service with Integrity'}"
          </div>
        </div>
        <div style="text-align:right;">
          <span style="font-size:1.1rem; font-weight:800; color:#2563eb;">${c.votes_count}</span>
          <div style="font-size:0.7rem; color:#64748b; text-transform:uppercase;">Votes Recorded</div>
        </div>
      `;
      candList.appendChild(item);
    });
  } else {
    candList.innerHTML = '<div style="color:#94a3b8; font-style:italic; padding:12px; text-align:center;">No candidates officially registered yet.</div>';
  }

  document.getElementById('viewElectionModal').style.display = 'flex';
}

function openAuditElectionModal(id) {
  const el = sscRegistryData.find(e => e.id == id);
  if (!el) return;

  const contentArea = document.getElementById('auditModalContent');
  const isVerified = (el.status === 'verified');
  
  // Digital verification hash for electoral integrity seal
  const certHash = 'SEC-' + btoa(el.election_code + ':' + el.id + ':' + el.votes_cast).substring(0, 24).toUpperCase();

  let verificationHtml = '';
  if (isVerified) {
    verificationHtml = `
      <div style="background:#dcfce7; border:1.5px solid #86efac; border-radius:10px; padding:14px 18px; margin-bottom:16px;">
        <div style="display:flex; align-items:center; gap:8px; color:#15803d; font-weight:800; font-size:0.95rem;">
          <i class="fa-solid fa-circle-check" style="font-size:1.2rem;"></i> Certified &amp; Cleared by Supreme Student Council
        </div>
        <div style="font-size:0.8rem; color:#166534; margin-top:6px;">
          This election has satisfied all constitutional requirements, certified counts, and voter turnout quotas. Certified on <strong>${el.verified_at || 'Official Record'}</strong>.
        </div>
        <div style="font-size:0.78rem; color:#14532d; margin-top:4px; font-style:italic;">
          Verification Clearance Note: "${el.audit_notes || 'Verified compliant with SSC Electoral Code.'}"
        </div>
      </div>
    `;
  } else {
    verificationHtml = `
      <div style="background:#fef3c7; border:1.5px solid #fde68a; border-radius:10px; padding:14px 18px; margin-bottom:16px;">
        <div style="display:flex; align-items:center; gap:8px; color:#b45309; font-weight:800; font-size:0.95rem;">
          <i class="fa-solid fa-clock-rotate-left" style="font-size:1.1rem;"></i> Pending Official SSC Legislative Verification
        </div>
        <div style="font-size:0.8rem; color:#92400e; margin-top:4px;">
          Review the digital turnout ledger below and submit your official endorsement to certify these results into the permanent institutional archive.
        </div>
        <div style="margin-top:12px;">
          <label style="display:block; font-size:0.75rem; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">SSC Verification Endorsement Note</label>
          <input type="text" id="auditNotesInput" value="Official election verification clearance certified by Supreme Student Council." style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.82rem;" />
          <button type="button" class="card-btn" onclick="submitVerifyElection(${el.id})" style="margin-top:10px; height:36px; padding:0 18px; background:#16a34a; color:#fff; font-weight:700; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-stamp"></i> Certify &amp; Verify Election Results
          </button>
        </div>
      </div>
    `;
  }

  contentArea.innerHTML = `
    ${verificationHtml}
    
    <!-- Election Identity Block -->
    <div style="border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:14px; background:#f8fafc;">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
        <div>
          <span class="badge" style="font-family:monospace; background:#e2e8f0; color:#0f172a; font-weight:700;">${el.election_code}</span>
          <h4 style="margin:4px 0 2px 0; font-size:1.05rem; color:#0f172a;">${el.title}</h4>
          <div style="font-size:0.8rem; color:#475569;">${el.club_code} &bull; ${el.club_name} (${el.election_type})</div>
        </div>
        <div style="text-align:right;">
          <span class="badge" style="background:#ede9fe; color:#7c3aed; font-weight:700;">${el.status.toUpperCase()}</span>
          <div style="font-size:0.75rem; color:#64748b; margin-top:3px;">Closes: ${el.closing_date}</div>
        </div>
      </div>
    </div>

    <!-- Verification & Participation Matrix -->
    <div style="border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:14px;">
      <h5 style="margin:0 0 10px 0; font-size:0.78rem; font-weight:800; text-transform:uppercase; color:#475569; letter-spacing:0.5px;">Electoral Participation &amp; Turnout Matrix</h5>
      <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; text-align:center;">
        <div style="background:#f8fafc; padding:10px; border-radius:8px;">
          <div style="font-size:0.7rem; color:#64748b; text-transform:uppercase; font-weight:700;">Eligible Roster</div>
          <div style="font-size:1.2rem; font-weight:800; color:#0f172a; margin-top:2px;">${el.eligible_voters}</div>
        </div>
        <div style="background:#f8fafc; padding:10px; border-radius:8px;">
          <div style="font-size:0.7rem; color:#64748b; text-transform:uppercase; font-weight:700;">Verified Ballots</div>
          <div style="font-size:1.2rem; font-weight:800; color:#2563eb; margin-top:2px;">${el.votes_cast}</div>
        </div>
        <div style="background:#f8fafc; padding:10px; border-radius:8px;">
          <div style="font-size:0.7rem; color:#64748b; text-transform:uppercase; font-weight:700;">Final Quorum</div>
          <div style="font-size:1.2rem; font-weight:800; color:#16a34a; margin-top:2px;">${el.turnout}</div>
        </div>
      </div>
      <div style="margin-top:12px; font-size:0.75rem; color:#64748b; background:#f1f5f9; padding:8px 12px; border-radius:6px; font-family:monospace; word-break:break-all;">
        <i class="fa-solid fa-fingerprint" style="color:#2563eb;"></i> Verification Integrity Hash: <strong>${certHash}</strong>
      </div>
    </div>
  `;

  document.getElementById('auditElectionModal').style.display = 'flex';
}

async function submitVerifyElection(electionId) {
  const notesInput = document.getElementById('auditNotesInput');
  const notes = notesInput ? notesInput.value.trim() : '';

  const confirmed = await window.showConfirmModal(
    'Certify & Verify Election?',
    'Do you want to officially certify and verify this election? This confirms that voter turnout, tallies, and candidate qualifications comply with SSC electoral guidelines.',
    { type: 'decision', confirmText: 'Certify Election' }
  );
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action', 'verify_election');
  fd.append('election_id', electionId);
  fd.append('audit_notes', notes);

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error verifying election.');
  }
}

function printAuditRecord() {
  const content = document.getElementById('auditModalContent').innerHTML;
  const printWindow = window.open('', '_blank');
  printWindow.document.write(`
    <html>
      <head>
        <title>SSC Official Election Audit Clearance</title>
        <link rel="stylesheet" href="../css/dashboard.css">
        <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
        <style>
          body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 30px; color: #0f172a; }
          .badge { padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 0.75rem; }
        </style>
      </head>
      <body>
        <div style="display:flex; align-items:center; justify-content:center; gap:16px; text-align:center; padding-bottom:16px; border-bottom:2px solid #1e3a8a; margin-bottom:20px;">
          <img src="../images/BCP_LOGO.png" style="height:60px; width:auto;" />
          <div style="text-align:left;">
            <div style="font-size:1.15rem; font-weight:800; color:#1e3a8a;">Bestlink College of the Philippines</div>
            <div style="font-size:0.75rem; font-weight:700; color:#475569;">Supreme Student Council &bull; Committee on Electoral Integrity</div>
            <div style="font-size:0.9rem; font-weight:800; color:#2563eb; margin-top:2px;">Official Election Audit &amp; Verification Clearance</div>
          </div>
        </div>
        ${content}
        <div style="margin-top:40px; display:flex; justify-content:space-between; padding-top:20px; border-top:1px solid #cbd5e1;">
          <div>
            <div style="border-bottom:1px solid #000; width:180px; height:30px;"></div>
            <div style="font-size:0.8rem; font-weight:700; margin-top:4px;">SSC Chief Electoral Commissioner</div>
          </div>
          <div>
            <div style="border-bottom:1px solid #000; width:180px; height:30px;"></div>
            <div style="font-size:0.8rem; font-weight:700; margin-top:4px;">OSAS Director / Administration</div>
          </div>
        </div>
        <script>window.onload = function() { window.print(); window.close(); }<\/script>
      </body>
    </html>
  `);
  printWindow.document.close();
}

let draftCandidates = [];
const clubMembersCache = {};

async function fetchClubMembers(clubId) {
  if (clubMembersCache[clubId]) {
    return clubMembersCache[clubId];
  }
  try {
    const res = await fetch(`../shared/election_actions.php?action=get_club_members&club_id=${clubId}`);
    const data = await res.json();
    if (data.success) {
      clubMembersCache[clubId] = data.members || [];
      return clubMembersCache[clubId];
    }
  } catch (e) {
    console.error('Error fetching club members:', e);
  }
  return [];
}

async function populateClubMembersDatalist(clubId, datalistId) {
  const datalist = document.getElementById(datalistId);
  if (!datalist) return;
  datalist.innerHTML = '';
  const members = await fetchClubMembers(clubId);
  members.forEach(m => {
    const opt = document.createElement('option');
    opt.value = m.student_number;
    opt.textContent = `${m.full_name} (${m.course} - ${m.year_level})`;
    datalist.appendChild(opt);
  });
}

function getSelectedCreateElectionClubId() {
  const clubInput = document.getElementById('createElectionClubId');
  return clubInput ? parseInt(clubInput.value) || 0 : 0;
}

async function onElectionClubChanged() {
  const clubId = getSelectedCreateElectionClubId();
  await populateClubMembersDatalist(clubId, 'draftClubMembersList');
  const sIdInput = document.getElementById('draftCandStudentId');
  if (sIdInput && sIdInput.value.trim()) {
    onDraftStudentIdInput(sIdInput.value);
  }
}

async function onDraftStudentIdInput(val) {
  const query = (val || '').trim();
  const nameInput = document.getElementById('draftCandName');
  const progInput = document.getElementById('draftCandProg');
  const yearInput = document.getElementById('draftCandYear');
  const uidInput  = document.getElementById('draftCandUserId');
  const statusDiv = document.getElementById('draftMemberStatus');
  const clubId    = getSelectedCreateElectionClubId();

  if (!query) {
    if (nameInput) nameInput.value = '';
    if (progInput) progInput.value = '';
    if (yearInput) yearInput.value = '';
    if (uidInput)  uidInput.value  = '0';
    if (statusDiv) statusDiv.innerHTML = '';
    return;
  }

  const members = await fetchClubMembers(clubId);
  const matched = members.find(m => 
    (m.student_number && m.student_number.toLowerCase() === query.toLowerCase()) ||
    (m.full_name && m.full_name.toLowerCase() === query.toLowerCase()) ||
    (m.email && m.email.toLowerCase() === query.toLowerCase()) ||
    (String(m.user_id) === query)
  );

  if (matched) {
    if (nameInput) nameInput.value = matched.full_name;
    if (progInput) progInput.value = matched.course || 'BSIT';
    if (yearInput) yearInput.value = matched.year_level || '3rd Year';
    if (uidInput)  uidInput.value  = matched.user_id;
    if (statusDiv) {
      statusDiv.innerHTML = `<span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Verified Active Member: ${matched.full_name} (${matched.student_number})</span>`;
    }
  } else {
    if (nameInput) nameInput.value = '';
    if (progInput) progInput.value = '';
    if (yearInput) yearInput.value = '';
    if (uidInput)  uidInput.value  = '0';

    if (query.length >= 3) {
      try {
        const res = await fetch(`../shared/election_actions.php?action=lookup_student&club_id=${clubId}&student_id=${encodeURIComponent(query)}`);
        const data = await res.json();
        if (statusDiv) {
          if (data.eligible && data.student) {
            if (nameInput) nameInput.value = data.student.full_name;
            if (progInput) progInput.value = data.student.course || 'BSIT';
            if (yearInput) yearInput.value = data.student.year_level || '3rd Year';
            if (uidInput)  uidInput.value  = data.student.user_id;
            statusDiv.innerHTML = `<span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Verified Active Member: ${data.student.full_name} (${data.student.student_number})</span>`;
          } else {
            statusDiv.innerHTML = `<span style="color:#dc2626; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> ${data.message || 'Not an active member of this organization.'}</span>`;
          }
        }
      } catch (e) {
        if (statusDiv) {
          statusDiv.innerHTML = `<span style="color:#dc2626; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> Only active club members can be nominated for voting.</span>`;
        }
      }
    } else {
      if (statusDiv) statusDiv.innerHTML = '';
    }
  }
}

async function onTargetElectionChanged() {
  const sel = document.getElementById('candElectionSelect');
  if (!sel) return;
  updateCandidatePositions();
  const selectedOpt = sel.options[sel.selectedIndex];
  const clubId = selectedOpt ? parseInt(selectedOpt.getAttribute('data-club-id')) || 0 : 0;
  await populateClubMembersDatalist(clubId, 'addCandMembersList');
  const sIdInput = document.getElementById('addCandStudentId');
  if (sIdInput && sIdInput.value.trim()) {
    onAddCandStudentIdInput(sIdInput.value);
  }
}

async function onAddCandStudentIdInput(val) {
  const query = (val || '').trim();
  const nameInput = document.getElementById('addCandName');
  const progInput = document.getElementById('addCandProg');
  const yearInput = document.getElementById('addCandYear');
  const uidInput  = document.getElementById('addCandUserId');
  const statusDiv = document.getElementById('addCandMemberStatus');

  const elSel = document.getElementById('candElectionSelect');
  const selectedOpt = elSel ? elSel.options[elSel.selectedIndex] : null;
  const clubId = selectedOpt ? parseInt(selectedOpt.getAttribute('data-club-id')) || 0 : 0;

  if (!query) {
    if (nameInput) nameInput.value = '';
    if (progInput) progInput.value = 'BSIT';
    if (yearInput) yearInput.value = '3rd Year';
    if (uidInput)  uidInput.value  = '0';
    if (statusDiv) statusDiv.innerHTML = '';
    return;
  }

  const members = await fetchClubMembers(clubId);
  const matched = members.find(m => 
    (m.student_number && m.student_number.toLowerCase() === query.toLowerCase()) ||
    (m.full_name && m.full_name.toLowerCase() === query.toLowerCase()) ||
    (m.email && m.email.toLowerCase() === query.toLowerCase()) ||
    (String(m.user_id) === query)
  );

  if (matched) {
    if (nameInput) nameInput.value = matched.full_name;
    if (progInput) progInput.value = matched.course || 'BSIT';
    if (yearInput) yearInput.value = matched.year_level || '3rd Year';
    if (uidInput)  uidInput.value  = matched.user_id;
    if (statusDiv) {
      statusDiv.innerHTML = `<span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Verified Active Member: ${matched.full_name} (${matched.student_number})</span>`;
    }
  } else {
    if (nameInput) nameInput.value = '';
    if (progInput) progInput.value = '';
    if (yearInput) yearInput.value = '';
    if (uidInput)  uidInput.value  = '0';

    if (query.length >= 3) {
      try {
        const res = await fetch(`../shared/election_actions.php?action=lookup_student&club_id=${clubId}&student_id=${encodeURIComponent(query)}`);
        const data = await res.json();
        if (statusDiv) {
          if (data.eligible && data.student) {
            if (nameInput) nameInput.value = data.student.full_name;
            if (progInput) progInput.value = data.student.course || 'BSIT';
            if (yearInput) yearInput.value = data.student.year_level || '3rd Year';
            if (uidInput)  uidInput.value  = data.student.user_id;
            statusDiv.innerHTML = `<span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Verified Active Member: ${data.student.full_name} (${data.student.student_number})</span>`;
          } else {
            statusDiv.innerHTML = `<span style="color:#dc2626; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> ${data.message || 'Not an active member of this organization.'}</span>`;
          }
        }
      } catch (e) {
        if (statusDiv) {
          statusDiv.innerHTML = `<span style="color:#dc2626; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> Only active club members can be nominated for voting.</span>`;
        }
      }
    } else {
      if (statusDiv) statusDiv.innerHTML = '';
    }
  }
}

function openCreateElectionModal() {
  draftCandidates = [];
  renderDraftCandidates();
  updateDraftPositions();
  const sIdInput = document.getElementById('draftCandStudentId');
  if (sIdInput) sIdInput.value = '';
  const nameInput = document.getElementById('draftCandName');
  if (nameInput) nameInput.value = '';
  const statusDiv = document.getElementById('draftMemberStatus');
  if (statusDiv) statusDiv.innerHTML = '';
  onElectionClubChanged();
  document.getElementById('createElectionModal').style.display = 'flex';
}

function updateDraftPositions() {
  const input = document.getElementById('electionPositionsInput');
  const sel   = document.getElementById('draftCandPosition');
  if (!input || !sel) return;
  const raw = input.value || 'President, Vice President, Secretary, Treasurer, Auditor';
  const positions = raw.split(',').map(s => s.trim()).filter(Boolean);
  sel.innerHTML = '';
  positions.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p;
    opt.textContent = p;
    sel.appendChild(opt);
  });
}

function addDraftCandidate() {
  const sIdInput  = document.getElementById('draftCandStudentId');
  const nameInput = document.getElementById('draftCandName');
  const posInput  = document.getElementById('draftCandPosition');
  const uidInput  = document.getElementById('draftCandUserId');

  const studentNumber = sIdInput ? sIdInput.value.trim() : '';
  const name          = nameInput ? nameInput.value.trim() : '';
  const position      = posInput ? posInput.value.trim() : '';
  const userId        = uidInput ? parseInt(uidInput.value) || 0 : 0;

  if (!studentNumber || !name) {
    alert('Please enter a valid Student ID to select a verified club member as candidate.');
    if (sIdInput) sIdInput.focus();
    return;
  }

  // Check if candidate is already in draftCandidates
  const alreadyAdded = draftCandidates.some(c => 
    (c.user_id > 0 && c.user_id === userId) ||
    (c.student_number && c.student_number.toLowerCase() === studentNumber.toLowerCase())
  );
  if (alreadyAdded) {
    alert(`Candidate ${name} (${studentNumber}) has already been added to the candidate slate.`);
    return;
  }

  const party    = document.getElementById('draftCandParty').value.trim() || 'Independent';
  const program  = document.getElementById('draftCandProg').value.trim()  || 'BSIT';
  const year     = document.getElementById('draftCandYear').value.trim()  || '3rd Year';
  const tag      = document.getElementById('draftCandTag').value.trim();
  const ach      = document.getElementById('draftCandAch').value.trim();

  draftCandidates.push({
    user_id: userId,
    student_number: studentNumber,
    name,
    position,
    party,
    program,
    year_level: year,
    gwa: '1.5',
    platform_tag: tag,
    achievements: ach
  });

  // Clear inputs for next candidate
  sIdInput.value = '';
  nameInput.value = '';
  document.getElementById('draftCandProg').value  = '';
  document.getElementById('draftCandYear').value  = '';
  if (uidInput) uidInput.value = '0';
  document.getElementById('draftCandParty').value = '';
  document.getElementById('draftCandTag').value   = '';
  document.getElementById('draftCandAch').value   = '';
  const statusDiv = document.getElementById('draftMemberStatus');
  if (statusDiv) statusDiv.innerHTML = '';

  renderDraftCandidates();
}

function removeDraftCandidate(index) {
  draftCandidates.splice(index, 1);
  renderDraftCandidates();
}

function renderDraftCandidates() {
  const container = document.getElementById('draftCandidatesList');
  const jsonInput = document.getElementById('candidatesJsonInput');
  if (jsonInput) jsonInput.value = JSON.stringify(draftCandidates);
  if (!container) return;

  if (draftCandidates.length === 0) {
    container.innerHTML = `<div style="font-size:0.8rem; color:#94a3b8; font-style:italic; padding:6px 0;">No candidates added to slate yet. Nominate active members above and click "Add Candidate to Slate".</div>`;
    return;
  }

  let html = `<div style="font-size:0.82rem; font-weight:700; color:#334155; margin-bottom:8px;">Candidates Added to Slate (${draftCandidates.length}):</div>`;
  html += `<div style="display:flex; flex-direction:column; gap:6px;">`;
  draftCandidates.forEach((c, idx) => {
    html += `<div style="display:flex; justify-content:space-between; align-items:center; background:#fff; border:1px solid #cbd5e1; padding:8px 12px; border-radius:6px; font-size:0.83rem;">
      <div>
        <strong style="color:#0f172a;">${c.name}</strong> 
        <span style="color:#64748b; font-size:0.75rem; font-family:monospace;">[${c.student_number || 'ID'}]</span> &bull; 
        <span style="color:#2563eb; font-weight:600;">${c.position}</span>
        <span style="color:#64748b; font-size:0.78rem; margin-left:6px;">(${c.party}) &bull; ${c.program} - ${c.year_level}</span>
      </div>
      <button type="button" onclick="removeDraftCandidate(${idx})" style="background:none; border:none; color:#ef4444; font-size:0.85rem; cursor:pointer;" title="Remove Candidate">
        <i class="fa-solid fa-trash-can"></i>
      </button>
    </div>`;
  });
  html += `</div>`;
  container.innerHTML = html;
}

function openAddCandidateModal(electionId) {
  if (electionId) {
    const sel = document.getElementById('candElectionSelect');
    if (sel) sel.value = electionId;
  }
  const sIdInput = document.getElementById('addCandStudentId');
  if (sIdInput) sIdInput.value = '';
  const nameInput = document.getElementById('addCandName');
  if (nameInput) nameInput.value = '';
  const statusDiv = document.getElementById('addCandMemberStatus');
  if (statusDiv) statusDiv.innerHTML = '';
  onTargetElectionChanged();
  document.getElementById('addCandidateModal').style.display = 'flex';
}

function updateCandidatePositions() {
  const sel = document.getElementById('candElectionSelect');
  const posSel = document.getElementById('candPositionSelect');
  if (!sel || !posSel) return;
  const electionId = sel.value;
  const el = electionsData.find(e => e.id == electionId);
  posSel.innerHTML = '';
  
  const defaultPositions = ['President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor'];
  const positionsToUse = (el && el.positions && el.positions.length) ? el.positions : defaultPositions;
  
  positionsToUse.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p;
    opt.textContent = p;
    posSel.appendChild(opt);
  });
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('active', 'open');
    el.style.display = 'none';
  }
}

async function closeElection(electionId) {
  const confirmed = await window.showConfirmModal(
    'Close Election Pool?',
    'Do you want to close this election pool? Once closed, voting will be disabled.',
    { type: 'warning', warning: true, confirmText: 'Yes, Close Election' }
  );
  if (!confirmed) return;
  const formData = new FormData();
  formData.append('action', 'close_election');
  formData.append('election_id', electionId);
  formData.append('status', 'closed');

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error closing election.');
  }
}

async function handleCreateElection(e) {
  e.preventDefault();
  const form = document.getElementById('createElectionForm');
  const formData = new FormData(form);
  formData.append('action', 'create_election');

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error creating election.');
  }
}

async function handleAddCandidate(e) {
  e.preventDefault();
  const form = document.getElementById('addCandidateForm');
  const nameInput = document.getElementById('addCandName');
  if (!nameInput || !nameInput.value.trim()) {
    alert('Please enter a valid Student ID of an active club member.');
    return;
  }
  const formData = new FormData(form);
  formData.append('action', 'add_candidate');

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error adding candidate.');
  }
}

async function deleteCandidate(candidateId, name) {
  const confirmed = await window.showConfirmModal(
    'Remove Candidate?',
    `Do you want to remove candidate "${name}" from this election?`,
    { type: 'error', danger: true, confirmText: 'Yes, Remove Candidate' }
  );
  if (!confirmed) return;
  const formData = new FormData();
  formData.append('action', 'delete_candidate');
  formData.append('candidate_id', candidateId);

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error deleting candidate.');
  }
}

let currentViewingElection = null;

function showResultsModal(electionId) {
  const el = electionsData.find(e => e.id == electionId);
  if (!el) return;
  currentViewingElection = el;

  const turnoutPct = el.eligible > 0 ? Math.min(100, Math.round((parseInt(el.voted) / parseInt(el.eligible)) * 100)) : 0;
  document.getElementById('modalResHeader').textContent = el.title + ' — Official Results';
  document.getElementById('modalResSub').innerHTML = `
    <strong>Organization:</strong> ${el.org} (${el.acronym || ''}) &bull; 
    <strong>Turnout:</strong> ${el.voted}/${el.eligible} (${turnoutPct}%) &bull; 
    <strong>Status:</strong> <span style="text-transform:uppercase; font-weight:700; color:${el.status === 'open' ? '#16a34a' : '#2563eb'};">${el.status}</span> &bull; 
    <strong>Closing Date:</strong> ${el.closes}
  `;

  // 1. Compute winners for each position
  const winners = [];
  el.positions.forEach(pos => {
    const posCands = (el.candidates || []).filter(c => c.pos === pos);
    let maxVotes = -1;
    let winnerCand = null;
    posCands.forEach(c => {
      if ((c.votes_count || 0) > maxVotes) {
        maxVotes = c.votes_count || 0;
        winnerCand = c;
      }
    });
    if (winnerCand) {
      const totalPosVotes = posCands.reduce((sum, item) => sum + (item.votes_count || 0), 0);
      const baseVoted = Math.max(parseInt(el.voted) || 1, totalPosVotes, 1);
      const pct = Math.min(100, Math.round((winnerCand.votes_count / baseVoted) * 100));
      winners.push({
        cand_id: winnerCand.id,
        pos: pos,
        name: winnerCand.name,
        party: winnerCand.party || 'Independent',
        prog: winnerCand.prog || '',
        year: winnerCand.year || '',
        votes: winnerCand.votes_count,
        pct: pct,
        is_appointed: winnerCand.is_appointed || 0
      });
    }
  });

  let html = '';

  // Multi-Stage Lifecycle Banner
  let bannerHtml = '';
  if (el.status === 'pending_verification') {
    bannerHtml = `
      <div style="background:#fef3c7; border:1.5px solid #fcd34d; border-radius:10px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div style="width:40px; height:40px; border-radius:50%; background:#fde68a; color:#b45309; display:flex; align-items:center; justify-content:center; font-size:1.15rem; flex-shrink:0;">
            <i class="fa-solid fa-hourglass-half"></i>
          </div>
          <div>
            <div style="font-weight:800; color:#92400e; font-size:0.92rem;">Stage 1: Poll Verification Pending</div>
            <div style="font-size:0.82rem; color:#b45309; margin-top:2px;">Created by Faculty Adviser. Supreme Student Council (SSC) must verify and open this election before students can vote.</div>
          </div>
        </div>
        ${(sessRole === 'ssc' || sessRole === 'admin') ? `
          <button type="button" class="card-btn" onclick="verifyPoll(${el.id}, '${(el.title || '').replace(/'/g, "\\'")}')" style="background:#16a34a; color:#fff; font-weight:700; padding:8px 16px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-stamp"></i> Verify Poll &amp; Open Voting
          </button>
        ` : `
          <span style="font-size:0.8rem; font-weight:700; color:#b45309; background:#fffbeb; padding:6px 12px; border-radius:6px; border:1px solid #fde68a; display:inline-flex; align-items:center; gap:5px;">
            <i class="fa-solid fa-lock"></i> Awaiting SSC Verification
          </span>
        `}
      </div>
    `;
  } else if (el.status === 'open') {
    bannerHtml = `
      <div style="background:#f0fdf4; border:1.5px solid #86efac; border-radius:10px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div style="width:40px; height:40px; border-radius:50%; background:#dcfce7; color:#166534; display:flex; align-items:center; justify-content:center; font-size:1.15rem; flex-shrink:0;">
            <i class="fa-solid fa-check-to-slot"></i>
          </div>
          <div>
            <div style="font-weight:800; color:#166534; font-size:0.92rem;">Stage 2: Voting In Progress</div>
            <div style="font-size:0.82rem; color:#15803d; margin-top:2px;">Poll verified by SSC. Students are actively voting. Total votes cast: <strong>${el.voted}</strong> (${turnoutPct}% turnout).</div>
          </div>
        </div>
        ${(sessRole === 'club_adviser' || sessRole === 'admin') ? `
          <button type="button" class="card-btn" onclick="closeElection(${el.id})" style="background:#dc2626; color:#fff; font-weight:700; padding:8px 16px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-power-off"></i> Close Voting
          </button>
        ` : ''}
      </div>
    `;
  } else if (el.status === 'closed') {
    bannerHtml = `
      <div style="background:#faf5ff; border:1.5px solid #d8b4fe; border-radius:10px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div style="width:40px; height:40px; border-radius:50%; background:#f3e8ff; color:#7c3aed; display:flex; align-items:center; justify-content:center; font-size:1.15rem; flex-shrink:0;">
            <i class="fa-solid fa-bullhorn"></i>
          </div>
          <div>
            <div style="font-weight:800; color:#6b21a8; font-size:0.92rem;">Stage 3: Voting Closed — Awaiting Officer Announcement</div>
            <div style="font-size:0.82rem; color:#7e22ce; margin-top:2px;">Voting has concluded. The Faculty Adviser must review the final tally and formally announce the elected officers.</div>
          </div>
        </div>
        ${(sessRole === 'club_adviser' || sessRole === 'admin') ? `
          <button type="button" class="card-btn" onclick="announceOfficers(${el.id})" style="background:#7c3aed; color:#fff; font-weight:700; padding:8px 16px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-bullhorn"></i> Announce Elected Officers
          </button>
        ` : `
          <span style="font-size:0.8rem; font-weight:700; color:#7e22ce; background:#f3e8ff; padding:6px 12px; border-radius:6px; border:1px solid #d8b4fe; display:inline-flex; align-items:center; gap:5px;">
            <i class="fa-solid fa-clock"></i> Awaiting Adviser Announcement
          </span>
        `}
      </div>
    `;
  } else if (el.status === 'announced') {
    bannerHtml = `
      <div style="background:#ecfdf5; border:1.5px solid #6ee7b7; border-radius:10px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div style="width:40px; height:40px; border-radius:50%; background:#d1fae5; color:#059669; display:flex; align-items:center; justify-content:center; font-size:1.15rem; flex-shrink:0;">
            <i class="fa-solid fa-stamp"></i>
          </div>
          <div>
            <div style="font-weight:800; color:#065f46; font-size:0.92rem;">Stage 4: Officers Announced — Awaiting SSC Verification</div>
            <div style="font-size:0.82rem; color:#047857; margin-top:2px;">Faculty Adviser has proclaimed the winners. Supreme Student Council (SSC) must verify the elected officers before role commissioning.</div>
          </div>
        </div>
        ${(sessRole === 'ssc' || sessRole === 'admin') ? `
          <button type="button" class="card-btn" onclick="submitVerifyOfficers(${el.id})" style="background:#059669; color:#fff; font-weight:700; padding:8px 16px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-stamp"></i> Verify Elected Officers
          </button>
        ` : `
          <span style="font-size:0.8rem; font-weight:700; color:#047857; background:#d1fae5; padding:6px 12px; border-radius:6px; border:1px solid #6ee7b7; display:inline-flex; align-items:center; gap:5px;">
            <i class="fa-solid fa-shield-halved"></i> Awaiting SSC Officer Verification
          </span>
        `}
      </div>
    `;
  } else if (el.status === 'verified') {
    bannerHtml = `
      <div style="background:#eff6ff; border:1.5px solid #93c5fd; border-radius:10px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div style="width:40px; height:40px; border-radius:50%; background:#dbeafe; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.15rem; flex-shrink:0;">
            <i class="fa-solid fa-award"></i>
          </div>
          <div>
            <div style="font-weight:800; color:#1e40af; font-size:0.92rem;">Stage 5: Officers Verified — Ready for SSC Role Assignment</div>
            <div style="font-size:0.82rem; color:#1d4ed8; margin-top:2px;">Supreme Student Council (SSC) has verified this election. SSC can now assign the official officer roles in the club roster below.</div>
          </div>
        </div>
        <span style="font-size:0.8rem; font-weight:800; color:#1e40af; background:#dbeafe; padding:6px 14px; border-radius:6px; border:1px solid #93c5fd; display:inline-flex; align-items:center; gap:5px;">
          <i class="fa-solid fa-circle-check"></i> SSC Verified
        </span>
      </div>
    `;
  }

  html += bannerHtml;

  // Proclaimed Winners Table
  if (winners.length > 0) {
    let winnersRows = '';
    winners.forEach((w, idx) => {
      const isAppointed = w.is_appointed == 1;

      let statusBadge = '';
      if (isAppointed) {
        statusBadge = `<span style="background:#dcfce7; color:#166534; font-size:0.72rem; font-weight:800; padding:3px 8px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;"><i class="fa-solid fa-crown"></i> APPOINTED</span>`;
      } else if (el.status === 'verified') {
        statusBadge = `<span style="background:#dcfce7; color:#166534; font-size:0.72rem; font-weight:800; padding:3px 8px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;"><i class="fa-solid fa-trophy"></i> ELECTED</span>`;
      } else if (el.status === 'announced') {
        statusBadge = `<span style="background:#ede9fe; color:#6d28d9; font-size:0.72rem; font-weight:700; padding:3px 8px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;"><i class="fa-solid fa-bullhorn"></i> PROCLAIMED</span>`;
      } else if (el.status === 'closed') {
        statusBadge = `<span style="background:#fef3c7; color:#b45309; font-size:0.72rem; font-weight:700; padding:3px 8px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;"><i class="fa-solid fa-hourglass-half"></i> PROJECTED</span>`;
      } else if (el.status === 'open') {
        statusBadge = `<span style="background:#dbeafe; color:#1e40af; font-size:0.72rem; font-weight:700; padding:3px 8px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;"><i class="fa-solid fa-chart-line"></i> LEADING</span>`;
      } else {
        statusBadge = `<span style="background:#f1f5f9; color:#64748b; font-size:0.72rem; font-weight:700; padding:3px 8px; border-radius:12px;">CANDIDATE</span>`;
      }

      let actionCell = '';
      if (isAppointed) {
        actionCell = `
          <span style="background:#dcfce7; color:#166534; font-size:0.75rem; font-weight:800; padding:4px 10px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;">
            <i class="fa-solid fa-circle-check"></i> Assigned Officer
          </span>`;
      } else if (el.status === 'verified') {
        if (sessRole === 'ssc' || sessRole === 'admin') {
          actionCell = `
            <button type="button" class="card-btn" id="btnAppoint_${w.cand_id}" style="background:#2563eb; color:#fff; font-size:0.75rem; font-weight:700; padding:5px 12px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;" onclick="appointWinnerAsOfficer(${el.id}, ${w.cand_id}, '${w.name.replace(/'/g, "\\'")}', '${w.pos.replace(/'/g, "\\'")}')">
              <i class="fa-solid fa-user-plus"></i> Assign Officer Role
            </button>`;
        } else {
          actionCell = `<span style="color:#2563eb; font-size:0.75rem; font-weight:600;"><i class="fa-solid fa-clock"></i> Pending SSC Role Assignment</span>`;
        }
      } else if (el.status === 'announced') {
        actionCell = `<span style="color:#6d28d9; font-size:0.75rem; font-style:italic;"><i class="fa-solid fa-shield-halved"></i> Awaiting SSC Verification</span>`;
      } else if (el.status === 'closed') {
        actionCell = `<span style="color:#b45309; font-size:0.75rem; font-style:italic;"><i class="fa-solid fa-bullhorn"></i> Awaiting Adviser Announcement</span>`;
      } else {
        actionCell = `<span style="color:#64748b; font-size:0.75rem; font-style:italic;">Voting In Progress</span>`;
      }

      winnersRows += `
        <tr style="background-color:#f0fdf4;">
          <td style="padding:10px 12px; border:1px solid #e2e8f0; text-align:center; font-weight:700; color:#166534;">${idx + 1}</td>
          <td style="padding:10px 12px; border:1px solid #e2e8f0; font-weight:700; color:#1e3a8a;">${w.pos}</td>
          <td style="padding:10px 12px; border:1px solid #e2e8f0;">
            <strong style="color:#0f172a; font-size:0.92rem;">${w.name}</strong>
            <div style="font-size:0.75rem; color:#64748b;">${w.prog} ${w.year}</div>
          </td>
          <td style="padding:10px 12px; border:1px solid #e2e8f0; font-size:0.85rem; color:#475569;">${w.party}</td>
          <td style="padding:10px 12px; border:1px solid #e2e8f0; text-align:right; font-weight:700; color:#0f172a;">${w.votes}</td>
          <td style="padding:10px 12px; border:1px solid #e2e8f0; text-align:right; font-weight:700; color:#16a34a;">${w.pct}%</td>
          <td style="padding:10px 12px; border:1px solid #e2e8f0; text-align:center;">
            ${statusBadge}
          </td>
          <td style="padding:10px 12px; border:1px solid #e2e8f0; text-align:center;" id="cellAppoint_${w.cand_id}">
            ${actionCell}
          </td>
        </tr>
      `;
    });

    const rosterTitle = (el.status === 'verified')
      ? 'Official Roster of Certified &amp; Elected Officers'
      : (el.status === 'announced'
        ? 'Official Roster of Proclaimed Winners (Awaiting SSC Verification)'
        : (el.status === 'closed'
          ? 'Projected Winners (Awaiting Adviser Proclamation)'
          : 'Live Leading Candidates by Position'));

    html += `
      <div style="margin-bottom:24px; border:1.5px solid #86efac; border-radius:10px; overflow:hidden; box-shadow:0 2px 6px rgba(34,197,94,0.08);">
        <div style="background:#dcfce7; padding:10px 16px; font-weight:800; color:#166534; font-size:0.92rem; display:flex; align-items:center; gap:8px;">
          <i class="fa-solid fa-crown" style="color:#eab308;"></i> ${rosterTitle}
        </div>
        <div class="table-wrap">
          <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
              <tr style="background:#f8fafc; color:#334155; font-weight:700; text-align:left;">
                <th style="padding:10px 12px; border:1px solid #e2e8f0; width:35px; text-align:center;">#</th>
                <th style="padding:10px 12px; border:1px solid #e2e8f0;">Position</th>
                <th style="padding:10px 12px; border:1px solid #e2e8f0;">Candidate / Winner</th>
                <th style="padding:10px 12px; border:1px solid #e2e8f0;">Party / Slate</th>
                <th style="padding:10px 12px; border:1px solid #e2e8f0; text-align:right;">Votes</th>
                <th style="padding:10px 12px; border:1px solid #e2e8f0; text-align:right;">Share</th>
                <th style="padding:10px 12px; border:1px solid #e2e8f0; text-align:center;">Official Status</th>
                <th style="padding:10px 12px; border:1px solid #e2e8f0; text-align:center;">Officer Action</th>
              </tr>
            </thead>
            <tbody>
              ${winnersRows}
            </tbody>
          </table>
        </div>
      </div>
    `;
  }

  // 2. Position by Position Breakdown
  html += `<div style="font-size:0.88rem; font-weight:700; color:#1e293b; margin-bottom:12px; border-bottom:1px solid #cbd5e1; padding-bottom:6px;">Full Tally &amp; Candidates Breakdown</div>`;

  el.positions.forEach(pos => {
    const posCands = (el.candidates || []).filter(c => c.pos === pos);
    let maxVotes = -1;
    posCands.forEach(c => { if (c.votes_count > maxVotes) maxVotes = c.votes_count; });

    const totalPosVotes = posCands.reduce((sum, item) => sum + (item.votes_count || 0), 0);
    const baseVoted = Math.max(parseInt(el.voted) || 1, totalPosVotes, 1);

    let rowsHtml = '';
    if (!posCands.length) {
      rowsHtml = `<tr><td colspan="5" style="text-align:center; padding:12px; color:#64748b; font-style:italic;">No candidates registered for this position.</td></tr>`;
    } else {
      posCands.forEach((c, idx) => {
        const pct = Math.min(100, Math.round(((c.votes_count || 0) / baseVoted) * 100));
        const isWinner = (c.votes_count === maxVotes && maxVotes > 0);

        rowsHtml += `
          <tr style="${isWinner ? 'background-color:#f0fdf4;' : ''}">
            <td style="padding:8px 12px; border:1px solid #e2e8f0; text-align:center; width:40px;">${idx + 1}</td>
            <td style="padding:8px 12px; border:1px solid #e2e8f0;">
              <strong>${c.name}</strong>
              ${isWinner ? ' <span style="background:#dcfce7; color:#166534; font-size:0.7rem; font-weight:800; padding:2px 6px; border-radius:10px; margin-left:4px;"><i class="fa-solid fa-trophy"></i> WINNER</span>' : ''}
              <div style="font-size:0.75rem; color:#64748b;">${c.prog || ''} ${c.year || ''}</div>
            </td>
            <td style="padding:8px 12px; border:1px solid #e2e8f0; font-size:0.82rem; color:#475569;">${c.party || 'Independent'}</td>
            <td style="padding:8px 12px; border:1px solid #e2e8f0; text-align:right; font-weight:700; color:#0f172a;">${c.votes_count || 0}</td>
            <td style="padding:8px 12px; border:1px solid #e2e8f0; text-align:right; font-weight:600; color:#2563eb;">${pct}%</td>
          </tr>
        `;
      });
    }

    html += `
      <div style="margin-bottom:16px;">
        <h4 style="margin:0 0 6px 0; color:#1e3a8a; font-size:0.88rem; text-transform:uppercase; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-award" style="color:#2563eb;"></i> Position: ${pos}
        </h4>
        <div class="table-wrap">
          <table style="width:100%; border-collapse:collapse; font-size:0.82rem; border:1px solid #e2e8f0; border-radius:8px;">
            <thead>
              <tr style="background:#f8fafc; color:#334155; font-weight:700; text-align:left;">
                <th style="padding:8px 12px; border:1px solid #e2e8f0; text-align:center;">#</th>
                <th style="padding:8px 12px; border:1px solid #e2e8f0;">Candidate Name</th>
                <th style="padding:8px 12px; border:1px solid #e2e8f0;">Party / Slate</th>
                <th style="padding:8px 12px; border:1px solid #e2e8f0; text-align:right;">Votes</th>
                <th style="padding:8px 12px; border:1px solid #e2e8f0; text-align:right;">Share</th>
              </tr>
            </thead>
            <tbody>
              ${rowsHtml}
            </tbody>
          </table>
        </div>
      </div>
    `;
  });

  document.getElementById('modalResBody').innerHTML = html;
  document.getElementById('resultsModal').style.display = 'flex';
}

function showArchivedResultsModal(electionId, org, winner, votes, date) {
  const el = electionsData.find(e => e.id == electionId) || pastResultsData.find(e => e.id == electionId);
  if (el && el.candidates && el.candidates.length > 0) {
    showResultsModal(electionId);
    return;
  }

  // Display authentic archived record
  currentViewingElection = {
    id: electionId,
    org: org,
    acronym: org,
    title: (el && el.title) ? el.title : org + ' Concluded Election',
    closes: date,
    status: 'closed',
    eligible: (el && el.eligible) ? el.eligible : 0,
    voted: (el && el.voted) ? el.voted : 0,
    positions: (el && el.positions) ? el.positions : ['President'],
    candidates: (el && el.candidates && el.candidates.length > 0) ? el.candidates : [{
      id: 0,
      name: winner,
      pos: 'President',
      party: 'Official Candidate',
      prog: 'Campus Organization',
      year: 'Student Leader',
      votes_count: (el && el.voted) ? el.voted : 0
    }]
  };

  document.getElementById('modalResHeader').textContent = org + ' — Past Election Results & Winners';
  document.getElementById('modalResSub').innerHTML = `
    <strong>Organization:</strong> ${org} &bull; 
    <strong>Concluded Date:</strong> ${date} &bull; 
    <strong>Status:</strong> <span style="color:#16a34a; font-weight:700;">CONCLUDED &amp; CERTIFIED</span>
  `;

  let html = `
    <div style="margin-bottom:20px; border:1.5px solid #86efac; border-radius:10px; overflow:hidden;">
      <div style="background:#dcfce7; padding:10px 16px; font-weight:800; color:#166534; font-size:0.92rem; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-crown" style="color:#eab308;"></i> Official Proclamation of Past Election Winner
      </div>
      <div class="table-wrap">
        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
          <thead>
            <tr style="background:#f8fafc; color:#334155; font-weight:700; text-align:left;">
              <th style="padding:10px 14px; border:1px solid #e2e8f0;">Organization</th>
              <th style="padding:10px 14px; border:1px solid #e2e8f0;">Position</th>
              <th style="padding:10px 14px; border:1px solid #e2e8f0;">Elected Winner</th>
              <th style="padding:10px 14px; border:1px solid #e2e8f0; text-align:right;">Voter Turnout</th>
              <th style="padding:10px 14px; border:1px solid #e2e8f0; text-align:center;">Official Status</th>
            </tr>
          </thead>
          <tbody>
            <tr style="background-color:#f0fdf4;">
              <td style="padding:12px 14px; border:1px solid #e2e8f0;"><strong>${org}</strong></td>
              <td style="padding:12px 14px; border:1px solid #e2e8f0; font-weight:700; color:#1e3a8a;">President</td>
              <td style="padding:12px 14px; border:1px solid #e2e8f0;">
                <strong style="color:#0f172a; font-size:0.92rem;">${winner}</strong>
              </td>
              <td style="padding:12px 14px; border:1px solid #e2e8f0; text-align:right; font-weight:700;">${votes}</td>
              <td style="padding:12px 14px; border:1px solid #e2e8f0; text-align:center;">
                <span style="background:#dcfce7; color:#166534; font-size:0.72rem; font-weight:800; padding:3px 8px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;">
                  <i class="fa-solid fa-trophy"></i> ELECTED
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  `;

  document.getElementById('modalResBody').innerHTML = html;
  document.getElementById('resultsModal').style.display = 'flex';
}

function printSpecificPastElection(electionId, org, winner, votes, date) {
  if (sessRole === 'student') {
    alert('Exporting election certificates is reserved for Club Advisers and Administrators.');
    return;
  }

  const el = electionsData.find(e => e.id == electionId) || pastResultsData.find(e => e.id == electionId);
  if (el) {
    currentViewingElection = el;
    printCurrentElectionReport();
    return;
  }

  currentViewingElection = {
    id: electionId,
    org: org,
    acronym: org,
    title: org + ' Concluded Election',
    closes: date,
    status: 'closed',
    eligible: 0,
    voted: 0,
    positions: ['President'],
    candidates: [{
      id: 0,
      name: winner,
      pos: 'President',
      party: 'Official Candidate',
      prog: 'Campus Organization',
      year: 'Student Leader',
      votes_count: 0
    }]
  };
  printCurrentElectionReport();
}

function printCurrentElectionReport() {
  if (sessRole === 'student') {
    alert('Exporting election certificates is reserved for Club Advisers and Administrators.');
    return;
  }

  if (!currentViewingElection) {
    if (electionsData.length > 0) {
      currentViewingElection = electionsData[0];
    } else {
      window.print();
      return;
    }
  }

  const el = currentViewingElection;
  const turnoutPct = el.eligible > 0 ? Math.min(100, Math.round((parseInt(el.voted) / parseInt(el.eligible)) * 100)) : 0;

  // 1. Build Winners Roster Table
  const winners = [];
  el.positions.forEach(pos => {
    const posCands = (el.candidates || []).filter(c => c.pos === pos);
    let maxVotes = -1;
    let winnerCand = null;
    posCands.forEach(c => {
      if ((c.votes_count || 0) > maxVotes) {
        maxVotes = c.votes_count || 0;
        winnerCand = c;
      }
    });
    if (winnerCand) {
      const totalPosVotes = posCands.reduce((sum, item) => sum + (item.votes_count || 0), 0);
      const baseVoted = Math.max(parseInt(el.voted) || 1, totalPosVotes, 1);
      const pct = Math.min(100, Math.round((winnerCand.votes_count / baseVoted) * 100));
      winners.push({
        pos: pos,
        name: winnerCand.name,
        party: winnerCand.party || 'Independent',
        prog: winnerCand.prog || '',
        year: winnerCand.year || '',
        votes: winnerCand.votes_count,
        pct: pct
      });
    }
  });

  let winnersTableHtml = '';
  if (winners.length > 0) {
    let wRows = '';
    winners.forEach((w, idx) => {
      wRows += `
        <tr style="background-color:#f0fdf4; font-weight:600;">
          <td style="padding:8px 10px; border:1px solid #cbd5e1; text-align:center;">${idx + 1}</td>
          <td style="padding:8px 10px; border:1px solid #cbd5e1; color:#1e3a8a; font-weight:800;">${w.pos}</td>
          <td style="padding:8px 10px; border:1px solid #cbd5e1;">
            <strong>${w.name}</strong>
            ${w.prog ? `<span style="font-size:11px; color:#64748b; margin-left:4px;">(${w.prog} ${w.year})</span>` : ''}
          </td>
          <td style="padding:8px 10px; border:1px solid #cbd5e1;">${w.party}</td>
          <td style="padding:8px 10px; border:1px solid #cbd5e1; text-align:right; font-weight:800;">${w.votes}</td>
          <td style="padding:8px 10px; border:1px solid #cbd5e1; text-align:right; color:#16a34a;">${w.pct}%</td>
          <td style="padding:8px 10px; border:1px solid #cbd5e1; text-align:center;">
            <span style="background:#dcfce7; color:#166534; font-size:10px; font-weight:800; padding:2px 6px; border-radius:4px; display:inline-block;">ELECTED</span>
          </td>
        </tr>
      `;
    });

    winnersTableHtml = `
      <div style="margin-top:14px; margin-bottom:18px; page-break-inside:avoid;">
        <div style="font-size:13px; font-weight:800; color:#166534; text-transform:uppercase; background:#dcfce7; border:1px solid #86efac; padding:6px 10px; border-radius:4px 4px 0 0;">
          👑 Official Proclamation: Roster of Elected Officers &amp; Winners
        </div>
        <div class="table-wrap">
          <table style="width:100%; border-collapse:collapse; font-size:11.5px; border:1px solid #cbd5e1;">
            <thead>
              <tr style="background:#f1f5f9; color:#1e293b; font-weight:700;">
                <th style="padding:8px 10px; border:1px solid #cbd5e1; width:35px; text-align:center;">#</th>
                <th style="padding:8px 10px; border:1px solid #cbd5e1; text-align:left; width:130px;">Position</th>
                <th style="padding:8px 10px; border:1px solid #cbd5e1; text-align:left;">Proclaimed Officer / Winner</th>
                <th style="padding:8px 10px; border:1px solid #cbd5e1; text-align:left;">Party / Slate</th>
                <th style="padding:8px 10px; border:1px solid #cbd5e1; text-align:right; width:70px;">Votes</th>
                <th style="padding:8px 10px; border:1px solid #cbd5e1; text-align:right; width:70px;">Share (%)</th>
                <th style="padding:8px 10px; border:1px solid #cbd5e1; text-align:center; width:80px;">Status</th>
              </tr>
            </thead>
            <tbody>
              ${wRows}
            </tbody>
          </table>
        </div>
      </div>
    `;
  }

  // 2. Position by Position Tabulation
  let breakdownTablesHtml = '';
  el.positions.forEach(pos => {
    const posCands = (el.candidates || []).filter(c => c.pos === pos);
    let maxVotes = -1;
    posCands.forEach(c => { if (c.votes_count > maxVotes) maxVotes = c.votes_count; });
    const totalPosVotes = posCands.reduce((sum, item) => sum + (item.votes_count || 0), 0);
    const baseVoted = Math.max(parseInt(el.voted) || 1, totalPosVotes, 1);

    let rowsHtml = '';
    if (!posCands.length) {
      rowsHtml = `<tr><td colspan="6" style="text-align:center; padding:8px; color:#64748b; font-style:italic;">No candidates registered for this position.</td></tr>`;
    } else {
      posCands.forEach((c, idx) => {
        const pct = Math.min(100, Math.round(((c.votes_count || 0) / baseVoted) * 100));
        const isWinner = (c.votes_count === maxVotes && maxVotes > 0);
        rowsHtml += `
          <tr style="${isWinner ? 'background-color:#f0fdf4; font-weight:600;' : ''}">
            <td style="padding:6px 10px; border:1px solid #cbd5e1; text-align:center;">${idx + 1}</td>
            <td style="padding:6px 10px; border:1px solid #cbd5e1;">
              <strong>${c.name}</strong>
              ${isWinner ? ' <span style="display:inline-block; background:#16a34a; color:#fff; font-size:9.5px; font-weight:800; padding:1px 5px; border-radius:3px; margin-left:4px;">WINNER</span>' : ''}
            </td>
            <td style="padding:6px 10px; border:1px solid #cbd5e1;">${c.party || 'Independent'}</td>
            <td style="padding:6px 10px; border:1px solid #cbd5e1;">${c.prog || ''} ${c.year || ''}</td>
            <td style="padding:6px 10px; border:1px solid #cbd5e1; text-align:right; font-weight:700;">${c.votes_count || 0}</td>
            <td style="padding:6px 10px; border:1px solid #cbd5e1; text-align:right;">${pct}%</td>
          </tr>
        `;
      });
    }

    breakdownTablesHtml += `
      <div style="margin-top:12px; page-break-inside:avoid;">
        <div style="font-size:11.5px; font-weight:800; color:#1e3a8a; text-transform:uppercase; border-bottom:1.5px solid #2563eb; padding-bottom:2px; margin-bottom:4px;">
          Tally for Position: ${pos}
        </div>
        <div class="table-wrap">
          <table style="width:100%; border-collapse:collapse; font-size:11px; margin-bottom:8px;">
            <thead>
              <tr style="background:#f1f5f9; color:#1e293b; font-weight:700;">
                <th style="padding:6px 10px; border:1px solid #cbd5e1; width:30px; text-align:center;">#</th>
                <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:left;">Candidate Name</th>
                <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:left;">Party / Slate</th>
                <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:left;">Program &amp; Year</th>
                <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:right; width:70px;">Votes</th>
                <th style="padding:6px 10px; border:1px solid #cbd5e1; text-align:right; width:70px;">Share (%)</th>
              </tr>
            </thead>
            <tbody>
              ${rowsHtml}
            </tbody>
          </table>
        </div>
      </div>
    `;
  });

  const printWin = window.open('', '_blank', 'width=920,height=780');
  if (!printWin) {
    alert('Please allow popups to export/print the official election report.');
    return;
  }

  printWin.document.write(`
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <title></title>
      <style>
        @page { size: portrait; margin: 12mm 15mm; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #0f172a; margin: 0; padding: 15px; font-size: 11.5px; line-height: 1.35; }
        .header { display: flex; align-items: center; justify-content: center; gap: 14px; border-bottom: 2px solid #1e3a8a; padding-bottom: 12px; margin-bottom: 12px; text-align: center; }
        .logo { width: 62px; height: 62px; object-fit: contain; }
        .header-text h1 { margin: 0; font-size: 16.5px; color: #1e3a8a; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; }
        .header-text p { margin: 2px 0 0; font-size: 11px; color: #475569; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .doc-title { text-align: center; margin: 8px 0 10px; font-size: 14.5px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; background: #f8fafc; }
        .meta-table td { padding: 6px 10px; border: 1px solid #e2e8f0; font-size: 11px; }
        .meta-table td strong { color: #1e293b; }
        .signatures { display: flex; justify-content: space-between; margin-top: 32px; page-break-inside: avoid; }
        .sign-box { width: 30%; text-align: center; font-size: 11px; }
        .sign-line { border-top: 1px solid #334155; margin-top: 40px; padding-top: 4px; font-weight: 700; color: #0f172a; }
        .print-btn-bar { text-align: right; margin-bottom: 12px; }
        .print-btn { background: #2563eb; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 12px; }
        @media print {
          .print-btn-bar { display: none !important; }
          body { padding: 0; }
        }
      </style>
    </head>
    <body>
      <div class="print-btn-bar">
        <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
      </div>

      <div class="header">
        <img class="logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />
        <div class="header-text">
          <h1>Bestlink College of the Philippines</h1>
          <p>Office of Student Affairs &amp; Services &bull; Student Commission on Elections</p>
          <p style="font-size:10px; color:#64748b; margin-top:1px;">Campus Co-Curricular Student Organization Management System</p>
        </div>
      </div>

      <div class="doc-title">Official Certificate of Past Election Results &amp; Winners</div>

      <div class="table-wrap">
        <table class="meta-table">
          <tr>
            <td style="width:50%;"><strong>Organization:</strong> ${el.org} (${el.acronym || ''})</td>
            <td style="width:50%;"><strong>Election Title:</strong> ${el.title}</td>
          </tr>
          <tr>
            <td><strong>Concluded Date:</strong> ${el.closes}</td>
            <td><strong>Status:</strong> CONCLUDED &amp; CERTIFIED</td>
          </tr>
          <tr>
            <td><strong>Voter Turnout:</strong> ${el.voted} / ${el.eligible} Eligible Members (${turnoutPct}%)</td>
            <td><strong>Certification:</strong> Official Electoral Proclamation Record</td>
          </tr>
        </table>
      </div>

      ${winnersTableHtml}

      ${breakdownTablesHtml}

      <div class="signatures">
        <div class="sign-box">
          <div class="sign-line">COMELEC Representative</div>
          <span style="color:#64748b; font-size:10px;">Electoral Board</span>
        </div>
        <div class="sign-box">
          <div class="sign-line">Organization Faculty Adviser</div>
          <span style="color:#64748b; font-size:10px;">Faculty Supervision</span>
        </div>
        <div class="sign-box">
          <div class="sign-line">Dean / Director of Student Affairs</div>
          <span style="color:#64748b; font-size:10px;">Office of Student Affairs</span>
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

function exportHandledOrgReport() {
  if (electionsData.length > 0) {
    showResultsModal(electionsData[0].id);
  } else {
    alert('No election data available to export yet. Please create an election pool first.');
  }
}

// ── Views Switching Handlers ──
function backToLanding() {
  document.getElementById('electionLanding').style.display = 'block';
  document.getElementById('boothView').style.display       = 'none';
  document.getElementById('candidatesView').style.display  = 'none';
}

function openCandidatesView(electionId) {
  const el = electionsData.find(e => e.id == electionId);
  if (!el) return;

  document.getElementById('candsBreadcrumb').textContent = el.org + ' \u203A Candidates';

  let actionHeader = '';
  if (sessRole === 'student') {
    if (el.status === 'pending_verification' || el.status === 'pending_approval' || el.status === 'pending') {
      actionHeader = `
        <span style="background:rgba(254,243,199,0.95); color:#92400e; font-weight:800; font-size:0.78rem; padding:6px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:6px; border:1px solid #fde68a;">
          <i class="fa-solid fa-hourglass-half"></i> Poll Awaiting SSC Verification &bull; Voting Not Open Yet
        </span>`;
    } else if (el.status === 'open' || el.status === 'active') {
      if (el.user_has_voted) {
        actionHeader = `
          <button type="button" class="card-btn" onclick="openBoothView(${el.id})" style="background:#ffffff; color:#166534; font-weight:800; font-size:0.82rem; padding:8px 16px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-receipt"></i> View My Voting Receipt
          </button>`;
      } else {
        actionHeader = `
          <button type="button" class="card-btn" onclick="openBoothView(${el.id})" style="background:#22c55e; color:#ffffff; font-weight:800; font-size:0.82rem; padding:8px 18px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 6px rgba(0,0,0,0.2);">
            <i class="fa-solid fa-check-to-slot"></i> Enter Ballot Booth &amp; Vote
          </button>`;
      }
    } else {
      actionHeader = `
        <button type="button" class="card-btn" onclick="showResultsModal(${el.id})" style="background:#ffffff; color:#1e3a8a; font-weight:800; font-size:0.82rem; padding:8px 16px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-chart-column"></i> View Official Results
        </button>`;
    }
  }

  let html = `<div style="background:${el.color}; border-radius:16px; padding:24px 28px; color:#fff; margin-bottom:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <div>
        <h2 style="margin:0 0 6px 0; font-size:1.3rem;">${el.title}</h2>
        <p style="margin:0; font-size:0.85rem; opacity:0.9;">Official Candidate Slates &amp; Platforms for ${el.org}</p>
      </div>
      <div>${actionHeader}</div>
    </div>
  </div>`;

  el.positions.forEach(pos => {
    const posCands = el.candidates.filter(c => c.pos === pos);
    html += `<h3 style="margin:20px 0 12px 0; color:#1e293b; font-size:1rem; border-bottom:2px solid #e2e8f0; padding-bottom:6px;">${pos}</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin-bottom:24px;">`;

    if (!posCands.length) {
      html += `<div style="font-size:0.85rem; color:#94a3b8;">No candidate profiles registered for this position yet.</div>`;
    } else {
      posCands.forEach(c => {
        const achBadges = c.achievements.map(a => `<span style="background:#eff6ff; color:#1e40af; font-size:0.72rem; font-weight:600; padding:2px 8px; border-radius:12px;">${a}</span>`).join(' ');
        html += `<div style="background:#fff; border-radius:14px; padding:20px; border:1px solid #e2e8f0; box-shadow:0 2px 4px rgba(0,0,0,0.04);">
          <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
            <div style="width:48px; height:48px; border-radius:50%; background:${c.color}; color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">${c.initials}</div>
            <div>
              <div style="font-weight:700; font-size:1rem; color:#0f172a;">${c.name}</div>
              <div style="font-size:0.8rem; color:#2563eb; font-weight:600;">${c.party}</div>
            </div>
          </div>
          <div style="font-size:0.82rem; color:#475569; margin-bottom:10px;">
            <div><strong>Year &amp; Program:</strong> ${c.year} &bull; ${c.prog} (GWA: ${c.gwa})</div>
            <div style="font-style:italic; margin-top:6px; color:#1e293b;">${c.tag}</div>
          </div>
          <div style="display:flex; flex-wrap:wrap; gap:4px;">${achBadges}</div>
        </div>`;
      });
    }
    html += `</div>`;
  });

  document.getElementById('candsContent').innerHTML     = html;
  document.getElementById('electionLanding').style.display = 'none';
  document.getElementById('candidatesView').style.display  = 'block';
}

function openBoothView(electionId) {
  const el = electionsData.find(e => e.id == electionId);
  if (!el) return;

  document.getElementById('boothBreadcrumb').textContent = el.org + ' \u203A Digital Balloting Booth';

  let html = `<div style="background:${el.color}; border-radius:16px; padding:24px 28px; color:#fff; margin-bottom:24px; box-shadow:0 8px 24px -4px rgba(0,0,0,0.15);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <div>
        <div style="font-size:0.75rem; font-weight:800; text-transform:uppercase; letter-spacing:0.8px; opacity:0.9; margin-bottom:4px;">
          <i class="fa-solid fa-shield-halved"></i> Official Confidential Digital Balloting Booth
        </div>
        <h2 style="margin:0; font-size:1.35rem; font-weight:800; color:#fff;">${el.title}</h2>
        <div style="margin-top:4px; font-size:0.85rem; opacity:0.95;">${el.org} (${el.acronym || ''})</div>
      </div>
      <div style="text-align:right;">
        <span class="badge" style="background:rgba(255,255,255,0.2); color:#fff; font-size:0.78rem; padding:5px 12px; border-radius:20px; font-weight:700;">
          <i class="fa-solid fa-clock"></i> Closes: ${el.closes}
        </span>
      </div>
    </div>
  </div>`;

  if (sessRole === 'club_adviser') {
    html += `<div style="background:#fff3cd; color:#856404; border:1px solid #ffeeba; border-radius:12px; padding:24px; text-align:center;">
      <i class="fa-solid fa-user-shield" style="font-size:2.4rem; margin-bottom:10px; display:block; color:#b45309;"></i>
      <h3 style="margin:0 0 6px 0; font-size:1.15rem; font-weight:800;">Faculty Adviser Supervisory View</h3>
      <p style="margin:0; font-size:0.88rem; color:#92400e;">Faculty Advisers supervise candidate slates and monitor voter turnout. Active club student members cast votes during the active voting window.</p>
      <div style="margin-top:16px;">
        <button class="card-btn" style="background:#2563eb; color:#fff; font-weight:700; padding:8px 18px;" onclick="showResultsModal(${el.id})">
          <i class="fa-solid fa-chart-column"></i> Open Live Monitor
        </button>
      </div>
    </div>`;
  } else if (el.status === 'pending_verification' || el.status === 'pending_approval' || el.status === 'pending') {
    html += `
      <div style="background:#fff; border:1.5px solid #fcd34d; border-radius:16px; padding:36px 28px; text-align:center; box-shadow:0 4px 20px rgba(245,158,11,0.08); max-width:680px; margin:0 auto;">
        <div style="width:68px; height:68px; border-radius:50%; background:#fef3c7; color:#b45309; display:flex; align-items:center; justify-content:center; font-size:2rem; margin:0 auto 16px;">
          <i class="fa-solid fa-hourglass-half"></i>
        </div>
        <h3 style="margin:0 0 8px 0; font-size:1.3rem; font-weight:800; color:#92400e;">Poll Awaiting SSC Verification</h3>
        <p style="margin:0 0 20px 0; font-size:0.88rem; color:#475569; line-height:1.5;">
          This election poll was created by the Faculty Adviser and is currently awaiting official verification by the <strong>Supreme Student Council (SSC)</strong>.
        </p>
        <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:16px; text-align:left; margin-bottom:24px; font-size:0.82rem; color:#92400e; display:flex; align-items:flex-start; gap:12px;">
          <i class="fa-solid fa-shield-halved" style="font-size:1.2rem; color:#d97706; margin-top:2px; flex-shrink:0;"></i>
          <div>
            <strong>Student Balloting Protocol:</strong> Per student governance rules, student voting will open immediately once the Supreme Student Council (SSC) verifies and certifies this poll.
          </div>
        </div>
        <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
          <button class="card-btn" onclick="openCandidatesView(${el.id})" style="background:#f1f5f9; color:#334155; font-weight:700; border:1px solid #cbd5e1; padding:10px 20px; font-size:0.85rem;">
            <i class="fa-solid fa-id-card-clip"></i> View Candidate Profiles
          </button>
          <button class="card-btn" onclick="backToLanding()" style="background:#2563eb; color:#fff; font-weight:700; padding:10px 24px; font-size:0.85rem;">
            <i class="fa-solid fa-arrow-left"></i> Back to Elections
          </button>
        </div>
      </div>
    `;
  } else if (el.user_has_voted) {
    // ── VERIFIED DIGITAL BALLOT RECEIPT VIEW ──
    const certCode = 'CONF-' + btoa(el.code + ':' + el.id + ':' + (el.voted_at || 'CAST')).substring(0, 18).toUpperCase();
    html += `
      <div style="background:#fff; border:1.5px solid #86efac; border-radius:16px; padding:32px 28px; text-align:center; box-shadow:0 4px 20px rgba(34,197,94,0.08); max-width:680px; margin:0 auto;">
        <div style="width:68px; height:68px; border-radius:50%; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; font-size:2rem; margin:0 auto 16px;">
          <i class="fa-solid fa-circle-check"></i>
        </div>
        <h3 style="margin:0 0 6px 0; font-size:1.3rem; font-weight:800; color:#166534;">Official Digital Voting Receipt</h3>
        <p style="margin:0 0 20px 0; font-size:0.85rem; color:#475569;">Your secret ballot has been cast and recorded on the database ledger.</p>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px; text-align:left; margin-bottom:22px;">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:0.82rem;">
            <div>
              <div style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Organization</div>
              <div style="font-weight:800; color:#0f172a; margin-top:2px;">${el.org} (${el.acronym || ''})</div>
            </div>
            <div>
              <div style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Election Title</div>
              <div style="font-weight:800; color:#0f172a; margin-top:2px;">${el.title}</div>
            </div>
            <div>
              <div style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Timestamp Verified</div>
              <div style="font-weight:700; color:#16a34a; margin-top:2px;"><i class="fa-regular fa-clock"></i> ${el.voted_at || 'Officially Verified & Recorded'}</div>
            </div>
            <div>
              <div style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Status</div>
              <div style="font-weight:800; color:#16a34a; margin-top:2px;"><i class="fa-solid fa-check-double"></i> Voted &amp; Sealed</div>
            </div>
          </div>
          <div style="border-top:1px dashed #cbd5e1; margin-top:12px; padding-top:10px; font-size:0.75rem; color:#64748b; font-family:monospace; word-break:break-all;">
            <i class="fa-solid fa-fingerprint" style="color:#2563eb;"></i> Ballot Verification Receipt Code: <strong>${certCode}</strong>
          </div>
        </div>

        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:12px 16px; font-size:0.78rem; color:#1e40af; text-align:left; margin-bottom:24px; display:flex; align-items:flex-start; gap:10px;">
          <i class="fa-solid fa-user-secret" style="font-size:1.15rem; color:#2563eb; margin-top:2px; flex-shrink:0;"></i>
          <div>
            <strong>Voter Confidentiality Protected:</strong> In adherence with Supreme Student Council voting protocol, individual voter identities are decoupled from candidate selections to preserve voter confidentiality.
          </div>
        </div>

        <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
          <button class="card-btn" onclick="openCandidatesView(${el.id})" style="background:#f1f5f9; color:#334155; font-weight:700; border:1px solid #cbd5e1; padding:10px 20px; font-size:0.85rem;">
            <i class="fa-solid fa-id-card-clip"></i> View Candidate Profiles
          </button>
          <button class="card-btn" onclick="backToLanding()" style="background:#2563eb; color:#fff; font-weight:700; padding:10px 24px; font-size:0.85rem;">
            <i class="fa-solid fa-arrow-left"></i> Back to Elections
          </button>
        </div>
      </div>
    `;
  } else if (el.status !== 'open' && el.status !== 'active') {
    html += `
      <div style="background:#fff; border:1.5px solid #cbd5e1; border-radius:16px; padding:36px 28px; text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.04); max-width:680px; margin:0 auto;">
        <div style="width:68px; height:68px; border-radius:50%; background:#f1f5f9; color:#64748b; display:flex; align-items:center; justify-content:center; font-size:2rem; margin:0 auto 16px;">
          <i class="fa-solid fa-lock"></i>
        </div>
        <h3 style="margin:0 0 8px 0; font-size:1.3rem; font-weight:800; color:#1e293b;">Voting Concluded</h3>
        <p style="margin:0 0 20px 0; font-size:0.88rem; color:#64748b;">This election is no longer accepting student ballots.</p>
        <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
          <button class="card-btn" onclick="showResultsModal(${el.id})" style="background:#2563eb; color:#fff; font-weight:700; padding:10px 24px; font-size:0.85rem;">
            <i class="fa-solid fa-chart-column"></i> View Official Results
          </button>
          <button class="card-btn" onclick="backToLanding()" style="background:#f1f5f9; color:#334155; font-weight:700; border:1px solid #cbd5e1; padding:10px 20px; font-size:0.85rem;">
            <i class="fa-solid fa-arrow-left"></i> Back to Elections
          </button>
        </div>
      </div>
    `;
  } else {
    // ── ACTIVE VOTING BALLOT INTERFACE (Only when verified by SSC and open) ──
    const positionsSet = new Set();
    (el.positions || []).forEach(p => { if (p && typeof p === 'string' && p.trim()) positionsSet.add(p.trim()); });
    (el.candidates || []).forEach(c => { if (c.pos && typeof c.pos === 'string' && c.pos.trim()) positionsSet.add(c.pos.trim()); });

    const standardOrder = ['President', 'Vice President', 'Secretary', 'Assistant Secretary', 'Treasurer', 'Assistant Treasurer', 'Auditor', 'Public Relations Officer', 'P.R.O.', 'Business Manager', 'Sergeant at Arms', 'Representative'];
    const sortedPositions = Array.from(positionsSet).sort((a, b) => {
      let idxA = standardOrder.findIndex(item => item.toLowerCase() === a.toLowerCase());
      let idxB = standardOrder.findIndex(item => item.toLowerCase() === b.toLowerCase());
      if (idxA === -1) idxA = 999;
      if (idxB === -1) idxB = 999;
      if (idxA !== idxB) return idxA - idxB;
      return a.localeCompare(b);
    });

    html += `<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:14px 18px; margin-bottom:20px; color:#1e40af; font-size:0.82rem; display:flex; align-items:center; gap:12px;">
      <i class="fa-solid fa-circle-info" style="font-size:1.3rem; color:#2563eb; flex-shrink:0;"></i>
      <div>
        <strong>Official Secret Ballot:</strong> Select one candidate for each position on the ballot below. All votes are anonymous, encrypted, and recorded directly into the official tally.
      </div>
    </div>`;

    html += `<form id="activeBallotForm" onsubmit="handleCastVote(event, ${el.id})">`;
    
    sortedPositions.forEach((pos, posIndex) => {
      const posCands = (el.candidates || []).filter(c => (c.pos || '').trim().toLowerCase() === pos.trim().toLowerCase());
      const safePosName = 'vote_' + encodeURIComponent(pos);

      html += `<div style="background:#fff; border-radius:14px; padding:22px; border:1px solid #e2e8f0; margin-bottom:20px; box-shadow:0 2px 6px rgba(0,0,0,0.03);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1.5px solid #f1f5f9; padding-bottom:10px;">
          <h3 style="margin:0; font-size:1.05rem; font-weight:800; color:#1e3a8a; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-award" style="color:#2563eb;"></i> Select ${pos}
          </h3>
          <span class="badge" style="background:#f1f5f9; color:#475569; font-size:0.75rem; font-weight:700;">
            ${posCands.length} Candidate${posCands.length === 1 ? '' : 's'}
          </span>
        </div>`;

      if (!posCands.length) {
        html += `<div style="font-size:0.85rem; color:#94a3b8; font-style:italic; padding:12px 0;">
          <i class="fa-solid fa-circle-exclamation" style="margin-right:4px;"></i> No candidates officially registered for this position.
        </div>`;
      } else {
        html += `<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px;">`;
        posCands.forEach(c => {
          const achTags = Array.isArray(c.achievements) ? c.achievements.map(a => `<span style="background:#f1f5f9; color:#475569; font-size:0.7rem; font-weight:600; padding:1px 6px; border-radius:8px;">${a}</span>`).join(' ') : '';

          html += `
            <label class="ballot-candidate-card" id="card_cand_${c.id}" style="display:flex; align-items:flex-start; gap:12px; padding:14px 16px; border:1.5px solid #cbd5e1; border-radius:12px; cursor:pointer; background:#fff; transition:all 0.18s ease; position:relative;">
              <input type="radio" name="${safePosName}" value="${c.id}" required 
                     onchange="highlightSelectedCandidate('${safePosName}', ${c.id})"
                     style="width:18px; height:18px; accent-color:#2563eb; margin-top:3px; flex-shrink:0; cursor:pointer;"/>
              <div style="width:40px; height:40px; border-radius:50%; background:${c.color || '#1a3a8c'}; color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:0.95rem; flex-shrink:0;">
                ${c.initials || 'C'}
              </div>
              <div style="flex:1; min-width:0;">
                <div style="font-weight:800; font-size:0.92rem; color:#0f172a; line-height:1.2;">${c.name}</div>
                <div style="display:flex; align-items:center; gap:6px; margin-top:3px; flex-wrap:wrap;">
                  <span class="badge" style="background:#ede9fe; color:#6d28d9; font-size:0.68rem; font-weight:700;">${c.party || 'Independent'}</span>
                  <span style="font-size:0.75rem; color:#64748b;">${c.prog || ''} &bull; ${c.year || ''}</span>
                </div>
                ${c.tag ? `<div style="font-size:0.76rem; color:#334155; font-style:italic; margin-top:4px;">"${c.tag}"</div>` : ''}
                ${achTags ? `<div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:6px;">${achTags}</div>` : ''}
              </div>
            </label>
          `;
        });
        html += `</div>`;
      }
      html += `</div>`;
    });

    html += `
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:20px 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
          <div style="font-weight:800; color:#0f172a; font-size:0.95rem;">Ready to Cast Your Official Ballot?</div>
          <div style="font-size:0.78rem; color:#64748b; margin-top:2px;">Verify your selections above before submitting. Your ballot is final and cannot be modified.</div>
        </div>
        <div style="display:flex; gap:10px;">
          <button type="button" class="card-btn" onclick="backToLanding()" style="background:#e2e8f0; color:#475569; font-weight:700; padding:10px 20px;">Cancel</button>
          <button type="submit" id="btnSubmitBallot" class="card-btn" style="background:#2563eb; color:#fff; font-weight:800; padding:12px 28px; font-size:0.95rem; border-radius:10px; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(37,99,235,0.25);">
            <i class="fa-solid fa-paper-plane"></i> Submit Official Secret Ballot
          </button>
        </div>
      </div>
    </form>`;
  }

  document.getElementById('boothContent').innerHTML       = html;
  document.getElementById('electionLanding').style.display = 'none';
  document.getElementById('candidatesView').style.display  = 'none';
  document.getElementById('boothView').style.display       = 'block';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function highlightSelectedCandidate(groupName, candId) {
  const radios = document.querySelectorAll(`input[name="${groupName}"]`);
  radios.forEach(r => {
    const card = document.getElementById(`card_cand_${r.value}`);
    if (card) {
      if (r.checked) {
        card.style.borderColor = '#2563eb';
        card.style.backgroundColor = '#eff6ff';
        card.style.boxShadow = '0 0 0 2px rgba(37,99,235,0.2)';
      } else {
        card.style.borderColor = '#cbd5e1';
        card.style.backgroundColor = '#ffffff';
        card.style.boxShadow = 'none';
      }
    }
  });
}

async function handleCastVote(e, electionId) {
  e.preventDefault();
  const form = e.target;
  const submitBtn = document.getElementById('btnSubmitBallot');

  const confirmed = typeof window.showConfirmModal === 'function'
    ? await window.showConfirmModal(
        'Submit Official Secret Ballot?',
        'Are you ready to cast your official secret ballot? In accordance with student governance guidelines, your vote is permanent, encrypted, and cannot be changed after submission.',
        { type: 'decision', confirmText: 'Yes, Submit Secret Ballot' }
      )
    : confirm('Submit your official secret ballot? Once submitted, your vote cannot be changed.');

  if (!confirmed) return;

  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Encrypting &amp; Casting Ballot...';
  }

  const formData = new FormData(form);
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) formData.append('csrf_token', csrfMeta.getAttribute('content'));
  formData.append('action', 'cast_vote');
  formData.append('election_id', electionId);

  const votes = {};
  for (let pair of formData.entries()) {
    if (pair[0].startsWith('vote_')) {
      const pos = decodeURIComponent(pair[0].substring(5));
      if (pair[1]) {
        votes[pos] = pair[1];
      }
    }
  }
  formData.append('votes', JSON.stringify(votes));

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      if (typeof window.showToastNotification === 'function') {
        window.showToastNotification(data.message, 'success');
      } else {
        alert(data.message);
      }
      // Update local election state
      const el = electionsData.find(item => item.id == electionId);
      if (el) {
        el.user_has_voted = true;
        el.voted = (parseInt(el.voted) || 0) + 1;
        el.voted_at = 'Just now';
      }
      setTimeout(() => window.location.reload(), 600);
    } else {
      alert('Ballot Submission Error: ' + data.message);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Official Secret Ballot';
      }
    }
  } catch (err) {
    alert('Network error while casting secret ballot. Please try again.');
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Official Secret Ballot';
    }
  }
}

async function appointWinnerAsOfficer(electionId, candidateId, candName, pos) {
  const confirmed = typeof window.showConfirmModal === 'function'
    ? await window.showConfirmModal(
        'Assign Officer Role in Club Roster?',
        `As the Supreme Student Council (SSC), do you officially commission and assign "${candName}" as an Officer (${pos}) in the organization roster?`,
        { type: 'decision', confirmText: 'Yes, Assign Officer Role' }
      )
    : confirm(`Assign "${candName}" as an Officer (${pos}) in the organization roster?`);
  if (!confirmed) return;

  const btn = document.getElementById(`btnAppoint_${candidateId}`);
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Assigning...';
  }

  const fd = new FormData();
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.getAttribute('content'));
  fd.append('action', 'appoint_winner');
  fd.append('election_id', electionId);
  fd.append('candidate_id', candidateId);

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      if (typeof window.showToastNotification === 'function') {
        window.showToastNotification(data.message, 'success');
      } else {
        alert(data.message);
      }
      // Update candidate in local data
      const el = electionsData.find(e => e.id == electionId);
      if (el && el.candidates) {
        const cand = el.candidates.find(c => c.id == candidateId);
        if (cand) cand.is_appointed = 1;
      }
      const cell = document.getElementById(`cellAppoint_${candidateId}`);
      if (cell) {
        cell.innerHTML = `
          <span style="background:#dcfce7; color:#166534; font-size:0.75rem; font-weight:800; padding:4px 10px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;">
            <i class="fa-solid fa-circle-check"></i> Assigned Officer
          </span>`;
      }
    } else {
      alert(data.message || 'Error assigning candidate role.');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Assign Officer Role';
      }
    }
  } catch (err) {
    alert('Network error while processing role assignment.');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Assign Officer Role';
    }
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const initAllElectionPagination = () => {
    if (window.initTablePagination) {
      if (document.getElementById('adminElectionTable')) {
        window.initTablePagination('#adminElectionTable', { pageSize: 5, showInfo: false });
      }
      if (document.getElementById('sscElectionRegistryTable')) {
        window.initTablePagination('#sscElectionRegistryTable', { pageSize: 5, showInfo: false });
      }
      if (document.getElementById('pastElectionsTable')) {
        window.initTablePagination('#pastElectionsTable', { pageSize: 5, showInfo: false });
      }
    }
  };
  initAllElectionPagination();
  window.addEventListener('load', initAllElectionPagination);

  // Live countdown timers for open elections
  const updateCountdownTimers = () => {
    const badges = document.querySelectorAll('.countdown-badge[data-closes]');
    const now = new Date().getTime();
    badges.forEach(b => {
      const closesStr = b.getAttribute('data-closes');
      if (!closesStr) return;
      const closesTime = new Date(closesStr.replace(' ', 'T')).getTime();
      if (isNaN(closesTime)) return;
      const diff = closesTime - now;
      if (diff <= 0) {
        b.innerHTML = '<i class="fa-solid fa-hourglass-end" style="color:#ef4444;"></i> Concluded';
        b.style.color = '#b91c1c';
        b.style.backgroundColor = '#fee2e2';
      } else {
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        let timeText = '';
        if (days > 0) {
          timeText = `${days}d ${hours}h left`;
        } else if (hours > 0) {
          timeText = `${hours}h ${mins}m left`;
        } else {
          timeText = `${mins}m left`;
        }
        b.innerHTML = `<i class="fa-solid fa-stopwatch"></i> ${timeText}`;
      }
    });
  };
  updateCountdownTimers();
  setInterval(updateCountdownTimers, 30000);
});
</script>
<script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
<script src="../js/table-pagination.js"></script>
</body>
</html>
