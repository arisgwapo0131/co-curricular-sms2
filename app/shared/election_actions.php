<?php
// ============================================================
//  ELECTION_ACTIONS.PHP  (app/shared/)
//  Backend handler for election creation, candidate management,
//  voting, and results calculation.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/security.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized session. Please sign in.']);
    exit;
}

// CSRF check on mutating requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$user_id   = (int)$_SESSION['user_id'];
$sess_role = $_SESSION['role'] ?? 'student';
$action    = $_REQUEST['action'] ?? '';

// Helper: Get adviser handled club ID
function getAdviserClubId($conn, $user_id) {
    $stmt = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active' LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        return (int)$row['club_id'];
    }
    // Match by username prefix (e.g. cssec.adviser -> CSSEC)
    $sess_uname = $_SESSION['username'] ?? '';
    $prefix = strtoupper(explode('.', $sess_uname)[0] ?? '');
    if (!empty($prefix)) {
        $c_stmt = $conn->prepare("SELECT id FROM clubs WHERE (code = ? OR REPLACE(code, '-', '') = ? OR REPLACE(code, '.', '') = ?) AND status = 'Active' LIMIT 1");
        if ($c_stmt) {
            $c_stmt->bind_param('sss', $prefix, $prefix, $prefix);
            $c_stmt->execute();
            $c_res = $c_stmt->get_result();
            if ($c_res && $row = $c_res->fetch_assoc()) {
                return (int)$row['id'];
            }
            $c_stmt->close();
        }
    }
    return 0;
}

header('Content-Type: application/json');

// ── 0. GET CLUB ACTIVE MEMBERS ─────────────────────────────────────────
if ($action === 'get_club_members') {
    $club_id = (int)($_REQUEST['club_id'] ?? 0);
    if ($sess_role === 'club_adviser' && $club_id <= 0) {
        $club_id = getAdviserClubId($conn, $user_id);
    }
    
    $members = [];
    if ($club_id > 0) {
        $stmt = $conn->prepare("
            SELECT 
                u.id AS user_id,
                CONCAT(u.first_name, ' ', u.last_name) AS full_name,
                u.first_name,
                u.last_name,
                u.email,
                s.id AS student_id,
                COALESCE(s.student_number, u.username) AS student_number,
                COALESCE(s.course, 'BSIT') AS course,
                COALESCE(s.year_level, '3rd Year') AS year_level,
                COALESCE(s.section, '') AS section,
                cm.role AS club_role,
                cm.status AS member_status
            FROM club_memberships cm
            JOIN users u ON u.id = cm.user_id
            LEFT JOIN students s ON s.user_id = u.id
            WHERE cm.club_id = ? AND cm.status = 'Active' AND u.status = 'Active'
              AND u.role NOT IN ('club_adviser', 'admin', 'super_admin', 'staff')
              AND cm.role != 'Adviser'
            ORDER BY u.last_name ASC, u.first_name ASC
        ");
        $stmt->bind_param('i', $club_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $members[] = $row;
        }
        $stmt->close();
    } else {
        // Institutional / campus-wide: all active students
        $res = $conn->query("
            SELECT 
                u.id AS user_id,
                CONCAT(u.first_name, ' ', u.last_name) AS full_name,
                u.first_name,
                u.last_name,
                u.email,
                s.id AS student_id,
                COALESCE(s.student_number, u.username) AS student_number,
                COALESCE(s.course, 'BSIT') AS course,
                COALESCE(s.year_level, '3rd Year') AS year_level,
                COALESCE(s.section, '') AS section,
                'Member' AS club_role,
                'Active' AS member_status
            FROM users u
            LEFT JOIN students s ON s.user_id = u.id
            WHERE u.role = 'student' AND u.status = 'Active'
            ORDER BY u.last_name ASC, u.first_name ASC
        ");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $members[] = $row;
            }
        }
    }
    
    echo json_encode(['success' => true, 'members' => $members, 'club_id' => $club_id]);
    exit;
}

// ── 0.1 LOOKUP STUDENT FOR CANDIDACY / NOMINATION ─────────────────────
if ($action === 'lookup_student') {
    $club_id   = (int)($_REQUEST['club_id'] ?? 0);
    $query_str = trim($_REQUEST['student_id'] ?? $_REQUEST['query'] ?? '');
    
    if (empty($query_str)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a Student ID or number.']);
        exit;
    }
    
    if ($sess_role === 'club_adviser' && $club_id <= 0) {
        $club_id = getAdviserClubId($conn, $user_id);
    }
    
    // Find student in DB
    $stmt = $conn->prepare("
        SELECT 
            u.id AS user_id,
            CONCAT(u.first_name, ' ', u.last_name) AS full_name,
            u.first_name,
            u.last_name,
            u.email,
            u.role AS user_role,
            s.id AS student_id,
            COALESCE(s.student_number, u.username) AS student_number,
            COALESCE(s.course, 'BSIT') AS course,
            COALESCE(s.year_level, '3rd Year') AS year_level,
            COALESCE(s.section, '') AS section
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        WHERE (s.student_number = ? OR u.username = ? OR u.id = ? OR CONCAT(u.first_name, ' ', u.last_name) = ? OR u.email = ?)
          AND u.status = 'Active'
        LIMIT 1
    ");
    $stmt->bind_param('ssiss', $query_str, $query_str, $query_str, $query_str, $query_str);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$student) {
        echo json_encode([
            'success' => false,
            'eligible' => false,
            'message' => "Student '{$query_str}' was not found in the student database."
        ]);
        exit;
    }
    
    // Check membership in club
    if ($club_id > 0) {
        $chk = $conn->prepare("SELECT id, role, status FROM club_memberships WHERE club_id = ? AND user_id = ? LIMIT 1");
        $cand_uid = (int)$student['user_id'];
        $chk->bind_param('ii', $club_id, $cand_uid);
        $chk->execute();
        $membership = $chk->get_result()->fetch_assoc();
        $chk->close();
        
        if (!$membership || $membership['status'] !== 'Active' || $membership['role'] === 'Adviser' || in_array($student['user_role'] ?? '', ['club_adviser', 'admin', 'super_admin', 'staff'])) {
            echo json_encode([
                'success' => false,
                'eligible' => false,
                'student' => $student,
                'message' => "User {$student['full_name']} is not an eligible student candidate. Club Advisers and administrative staff serve in an advisory/governance capacity only."
            ]);
            exit;
        }
    }
    
    echo json_encode([
        'success' => true,
        'eligible' => true,
        'student' => $student,
        'message' => "Verified active member: {$student['full_name']} ({$student['student_number']})"
    ]);
    exit;
}

// ── 1. CREATE ELECTION (Adviser, SSC, Admin) ─────────────────────────
if ($action === 'create_election') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only Advisers and Officers can establish elections.']);
        exit;
    }

    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $closes_at     = trim($_POST['closes_at'] ?? '');
    $closing_date  = trim($_POST['closing_date'] ?? '');
    $closing_time  = trim($_POST['closing_time'] ?? '23:59');
    $positions_raw = trim($_POST['positions'] ?? 'President, Vice President, Secretary, Treasurer');
    $club_id       = (int)($_POST['club_id'] ?? 0);

    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Election title is required.']);
        exit;
    }

    if ($sess_role === 'club_adviser') {
        $club_id = getAdviserClubId($conn, $user_id);
        if ($club_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'No active organization found assigned to your adviser account.']);
            exit;
        }
    } elseif ($club_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid organization for this election.']);
        exit;
    }

    // Convert positions string to array
    $pos_arr = array_values(array_filter(array_map('trim', explode(',', $positions_raw))));
    if (empty($pos_arr)) {
        $pos_arr = ['President', 'Vice President', 'Secretary', 'Treasurer'];
    }

    $election_code = 'elec_' . time() . '_' . rand(100, 999);
    $positions_json = json_encode($pos_arr);

    if (!empty($closing_date)) {
        if (empty($closing_time)) $closing_time = '23:59';
        $closes_formatted = date('Y-m-d H:i:s', strtotime($closing_date . ' ' . $closing_time));
    } elseif (!empty($closes_at)) {
        $closes_formatted = date('Y-m-d H:i:s', strtotime($closes_at));
    } else {
        $closes_formatted = date('Y-m-d H:i:s', strtotime('+7 days'));
    }

    $initial_status = ($sess_role === 'club_adviser') ? 'pending_verification' : 'open';
    $stmt = $conn->prepare("INSERT INTO elections (election_code, club_id, title, description, closes_at, status, positions, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sisssssi', $election_code, $club_id, $title, $description, $closes_formatted, $initial_status, $positions_json, $user_id);

    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();

        // Process candidates if provided in creation form
        $cands_raw = $_POST['candidates'] ?? '';
        if (!empty($cands_raw)) {
            $cands_arr = is_array($cands_raw) ? $cands_raw : json_decode($cands_raw, true);
            if (is_array($cands_arr)) {
                $cand_stmt = $conn->prepare("INSERT INTO election_candidates (election_id, user_id, candidate_code, name, position, party, year_level, program, gwa, platform_tag, achievements) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($cands_arr as $c) {
                    $c_name       = trim($c['name'] ?? '');
                    $c_pos        = trim($c['position'] ?? '');
                    $c_uid        = (int)($c['user_id'] ?? 0);
                    $c_snum       = trim($c['student_number'] ?? $c['student_id'] ?? '');
                    if (empty($c_name) || empty($c_pos)) continue;

                    // Enforce membership rule: only active club members can be candidates
                    if ($club_id > 0) {
                        $mem_chk = $conn->prepare("
                            SELECT cm.id, u.id AS uid, CONCAT(u.first_name, ' ', u.last_name) AS uname
                            FROM club_memberships cm
                            JOIN users u ON u.id = cm.user_id
                            LEFT JOIN students s ON s.user_id = u.id
                            WHERE cm.club_id = ? AND cm.status = 'Active'
                              AND u.role NOT IN ('club_adviser', 'admin', 'super_admin', 'staff')
                              AND cm.role != 'Adviser'
                              AND (cm.user_id = ? OR s.student_number = ? OR u.username = ? OR CONCAT(u.first_name, ' ', u.last_name) = ?)
                            LIMIT 1
                        ");
                        $mem_chk->bind_param('iisss', $club_id, $c_uid, $c_snum, $c_snum, $c_name);
                        $mem_chk->execute();
                        $mem_res = $mem_chk->get_result()->fetch_assoc();
                        $mem_chk->close();

                        if (!$mem_res) {
                            // Candidate is not in club - delete election and error out
                            $conn->query("DELETE FROM elections WHERE id = {$new_id}");
                            echo json_encode(['success' => false, 'message' => "Candidate '{$c_name}' is not an active member of this organization. Only active club members can be added to the voting slate."]);
                            exit;
                        }
                        if ($c_uid <= 0 && $mem_res) {
                            $c_uid = (int)$mem_res['uid'];
                        }
                    }

                    $c_party      = trim($c['party'] ?? 'Independent');
                    $c_year       = trim($c['year_level'] ?? '3rd Year');
                    $c_prog       = trim($c['program'] ?? 'BSIT');
                    $c_gwa        = trim($c['gwa'] ?? '1.5');
                    $c_tag        = trim($c['platform_tag'] ?? '');
                    $c_ach        = trim($c['achievements'] ?? '');
                    $cand_code    = 'cand_' . time() . '_' . rand(100, 999);
                    $ach_json     = json_encode(array_values(array_filter(array_map('trim', explode(',', $c_ach)))));

                    $cand_stmt->bind_param('iisssssssss', $new_id, $c_uid, $cand_code, $c_name, $c_pos, $c_party, $c_year, $c_prog, $c_gwa, $c_tag, $ach_json);
                    $cand_stmt->execute();
                }
                $cand_stmt->close();
            }
        }

        // When created by Adviser, notify SSC Officers for verification
        if ($sess_role === 'club_adviser' && function_exists('push_notification')) {
            $club_res = $conn->query("SELECT name, code FROM clubs WHERE id = {$club_id} LIMIT 1");
            $c_info = $club_res ? $club_res->fetch_assoc() : null;
            $c_code = $c_info['code'] ?? 'Club';
            
            $ssc_users = $conn->query("SELECT id FROM users WHERE role = 'ssc' AND status = 'Active'");
            if ($ssc_users) {
                while ($s = $ssc_users->fetch_assoc()) {
                    push_notification(
                        $conn,
                        (int)$s['id'],
                        "New Election Poll Awaiting Verification: {$c_code}",
                        "Faculty Adviser has created election poll '{$title}' for {$c_code}. Please verify this poll so student voting can open.",
                        'election'
                    );
                }
            }
            if (function_exists('log_audit')) {
                log_audit($conn, $user_id, 'create_election', 'elections', $new_id, "Adviser submitted election poll '$title' for $c_code (Awaiting SSC Verification)");
            }
        }

        $success_msg = ($sess_role === 'club_adviser')
            ? 'Election pool created and submitted to SSC for verification. Once verified by SSC, club members will be able to vote directly!'
            : 'Election pool established and opened for active balloting!';

        echo json_encode([
            'success' => true,
            'message' => $success_msg,
            'election_id' => $new_id,
            'election_code' => $election_code,
            'status' => $initial_status
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error creating election: ' . $conn->error]);
    }
    exit;
}

// ── 2. ADD CANDIDATE ──────────────────────────────────────────────────
if ($action === 'add_candidate') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $election_id   = (int)($_POST['election_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $position      = trim($_POST['position'] ?? '');
    $cand_user_id  = (int)($_POST['user_id'] ?? 0);
    $cand_snum     = trim($_POST['student_number'] ?? $_POST['student_id'] ?? '');
    $party         = trim($_POST['party'] ?? 'Independent');
    $year_level    = trim($_POST['year_level'] ?? '3rd Year');
    $program       = trim($_POST['program'] ?? 'BSIT');
    $gwa           = trim($_POST['gwa'] ?? '1.5');
    $platform_tag  = trim($_POST['platform_tag'] ?? '');
    $achievements  = trim($_POST['achievements'] ?? '');

    if ($election_id <= 0 || empty($name) || empty($position)) {
        echo json_encode(['success' => false, 'message' => 'Candidate name and position are required.']);
        exit;
    }

    // Fetch election club_id
    $el_stmt = $conn->prepare("SELECT club_id FROM elections WHERE id = ?");
    $el_stmt->bind_param('i', $election_id);
    $el_stmt->execute();
    $el_row = $el_stmt->get_result()->fetch_assoc();
    $el_stmt->close();
    $el_club_id = (int)($el_row['club_id'] ?? 0);

    // Verify Adviser scoping
    if ($sess_role === 'club_adviser') {
        $my_club = getAdviserClubId($conn, $user_id);
        if ($el_club_id !== $my_club) {
            echo json_encode(['success' => false, 'message' => 'You can only add candidates to elections of your handled organization.']);
            exit;
        }
    }

    // Enforce active club membership check
    if ($el_club_id > 0) {
        $mem_chk = $conn->prepare("
            SELECT cm.id, u.id AS uid 
            FROM club_memberships cm 
            JOIN users u ON u.id = cm.user_id 
            LEFT JOIN students s ON s.user_id = u.id 
            WHERE cm.club_id = ? AND cm.status = 'Active' 
              AND u.role NOT IN ('club_adviser', 'admin', 'super_admin', 'staff')
              AND cm.role != 'Adviser'
              AND (cm.user_id = ? OR s.student_number = ? OR u.username = ? OR CONCAT(u.first_name, ' ', u.last_name) = ?)
            LIMIT 1
        ");
        $mem_chk->bind_param('iisss', $el_club_id, $cand_user_id, $cand_snum, $cand_snum, $name);
        $mem_chk->execute();
        $mem_res = $mem_chk->get_result()->fetch_assoc();
        $mem_chk->close();

        if (!$mem_res) {
            echo json_encode(['success' => false, 'message' => "Candidate '{$name}' is not an active member of this organization. Only active members can be nominated for voting."]);
            exit;
        }
        if ($cand_user_id <= 0 && $mem_res) {
            $cand_user_id = (int)$mem_res['uid'];
        }
    }

    $cand_code = 'cand_' . time() . '_' . rand(10, 99);
    $ach_json = json_encode(array_values(array_filter(array_map('trim', explode(',', $achievements)))));

    $stmt = $conn->prepare("INSERT INTO election_candidates (election_id, user_id, candidate_code, name, position, party, year_level, program, gwa, platform_tag, achievements) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iisssssssss', $election_id, $cand_user_id, $cand_code, $name, $position, $party, $year_level, $program, $gwa, $platform_tag, $ach_json);

    if ($stmt->execute()) {
        $cid = $stmt->insert_id;
        $stmt->close();
        echo json_encode(['success' => true, 'message' => "Candidate {$name} added successfully!", 'candidate_id' => $cid]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error adding candidate: ' . $conn->error]);
    }
    exit;
}

// ── 3. DELETE CANDIDATE ───────────────────────────────────────────────
if ($action === 'delete_candidate') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $candidate_id = (int)($_POST['candidate_id'] ?? 0);
    if ($candidate_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid candidate specified.']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM election_candidates WHERE id = ?");
    $stmt->bind_param('i', $candidate_id);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Candidate removed successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete candidate.']);
    }
    exit;
}

// ── 4. CLOSE ELECTION / PUBLISH RESULTS ──────────────────────────────
if ($action === 'close_election') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    $status      = $_POST['status'] ?? 'closed';

    if ($election_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid election ID.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE elections SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $election_id);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Election status updated to ' . ucfirst($status) . '.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating election status.']);
    }
    exit;
}

// ── 5. CAST VOTE (Student Balloting) ─────────────────────────────────
if ($action === 'cast_vote') {
    if ($sess_role !== 'student') {
        echo json_encode(['success' => false, 'message' => 'Club Advisers and administrators oversee elections. Only active student members are eligible to cast ballots in student officer elections.']);
        exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    $votes_data  = $_POST['votes'] ?? [];

    if ($election_id <= 0 || empty($votes_data)) {
        echo json_encode(['success' => false, 'message' => 'Please select your candidate choices before submitting.']);
        exit;
    }

    // Verify election existence and lifecycle state
    $el_stmt = $conn->prepare("SELECT id, status, closes_at, club_id FROM elections WHERE id = ? LIMIT 1");
    $el_stmt->bind_param('i', $election_id);
    $el_stmt->execute();
    $election = $el_stmt->get_result()->fetch_assoc();
    $el_stmt->close();

    if (!$election) {
        echo json_encode(['success' => false, 'message' => 'Election not found.']);
        exit;
    }
    if (!in_array(strtolower($election['status']), ['open', 'active'])) {
        if (in_array(strtolower($election['status']), ['pending_verification', 'pending_approval'])) {
            echo json_encode(['success' => false, 'message' => 'This election poll is awaiting verification by the Supreme Student Council (SSC). Student voting is not open yet.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'This election is currently closed or unverified for balloting.']);
        }
        exit;
    }
    if (!empty($election['closes_at']) && strtotime($election['closes_at']) < time()) {
        echo json_encode(['success' => false, 'message' => 'This election has concluded and is no longer accepting ballots.']);
        exit;
    }

    // Check student org membership if scoped to a club
    if (!empty($election['club_id'])) {
        $chk_scope = $conn->prepare("SELECT scope FROM elections WHERE id = ?");
        $chk_scope->bind_param('i', $election_id);
        $chk_scope->execute();
        $scope_res = $chk_scope->get_result()->fetch_assoc();
        $chk_scope->close();
        $is_campus = ($scope_res && strtolower($scope_res['scope'] ?? '') === 'campus');

        if (!$is_campus) {
            $chk_m = $conn->prepare("SELECT id FROM club_memberships WHERE club_id = ? AND user_id = ? AND status = 'Active' AND role != 'Adviser'");
            $chk_m->bind_param('ii', $election['club_id'], $user_id);
            $chk_m->execute();
            if (!$chk_m->get_result()->fetch_assoc()) {
                echo json_encode(['success' => false, 'message' => 'You must be an approved active student member of this organization to vote in its election.']);
                exit;
            }
            $chk_m->close();
        }
    }

    $conn->begin_transaction();
    try {
        // Enforce single ballot per voter via election_voters table
        $chk_voter = $conn->prepare("SELECT id FROM election_voters WHERE election_id = ? AND user_id = ? FOR UPDATE");
        $chk_voter->bind_param('ii', $election_id, $user_id);
        $chk_voter->execute();
        if ($chk_voter->get_result()->fetch_assoc()) {
            $chk_voter->close();
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'You have already cast your vote in this election.']);
            exit;
        }
        $chk_voter->close();

        // 1. Record voter participation (separated from ballot content for secret balloting)
        $ins_voter = $conn->prepare("INSERT INTO election_voters (election_id, user_id, eligibility_status, voted_at) VALUES (?, ?, 'Voted', NOW())");
        $ins_voter->bind_param('ii', $election_id, $user_id);
        $ins_voter->execute();
        $ins_voter->close();

        // 2. Validate all submitted candidates belong to this election
        $decoded = is_string($votes_data) ? json_decode($votes_data, true) : $votes_data;
        if (!is_array($decoded) || empty($decoded)) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Invalid ballot payload submitted.']);
            exit;
        }

        // Verify each chosen candidate ID belongs to this election
        $cand_check = $conn->prepare("SELECT id, position FROM election_candidates WHERE id = ? AND election_id = ? LIMIT 1");
        $valid_choices = [];
        foreach ($decoded as $pos => $cand_id) {
            $c_id = (int)$cand_id;
            if ($c_id <= 0) continue;
            $cand_check->bind_param('ii', $c_id, $election_id);
            $cand_check->execute();
            $cand_res = $cand_check->get_result()->fetch_assoc();
            if (!$cand_res) {
                $cand_check->close();
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => "Candidate ID #$c_id does not belong to this election."]);
                exit;
            }
            $valid_choices[$pos] = $c_id;
        }
        $cand_check->close();

        if (empty($valid_choices)) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'No valid candidates were selected on your ballot.']);
            exit;
        }

        // 3. Record secret ballot decoupled with secure ballot_token
        $ballot_token = bin2hex(random_bytes(16));
        $votes_json   = json_encode($valid_choices);

        $stmt = $conn->prepare("INSERT INTO election_votes (election_id, ballot_token, ballot_data, cast_at, user_id, votes_json) VALUES (?, ?, ?, NOW(), NULL, ?)");
        $stmt->bind_param('isss', $election_id, $ballot_token, $votes_json, $votes_json);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception("Error recording ballot: " . $err);
        }
        $stmt->close();

        // 4. Increment candidate vote counts safely
        $upd_stmt = $conn->prepare("UPDATE election_candidates SET votes_count = votes_count + 1 WHERE id = ? AND election_id = ?");
        foreach ($valid_choices as $pos => $c_id) {
            $upd_stmt->bind_param('ii', $c_id, $election_id);
            $upd_stmt->execute();
        }
        $upd_stmt->close();

        log_audit($conn, $user_id, 'election_vote', 'election_votes', $election_id, "Cast verified ballot in election #$election_id (Token: $ballot_token)");
        $conn->commit();
        $_SESSION['votes_cast'][] = $election_id;
        echo json_encode(['success' => true, 'message' => 'Your secret ballot has been cast and verified!', 'ballot_token' => $ballot_token]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── 6. ASSIGN ROLE TO VERIFIED ELECTED OFFICER (SSC & Admin Only) ──────
if ($action === 'appoint_winner') {
    if (!in_array($sess_role, ['ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Per student governance protocol, only Supreme Student Council (SSC) Officers can assign roles to verified elected officers.']);
        exit;
    }

    $election_id  = (int)($_POST['election_id'] ?? 0);
    $candidate_id = (int)($_POST['candidate_id'] ?? 0);

    if ($election_id <= 0 || $candidate_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid election or candidate ID.']);
        exit;
    }

    // 1. Get election and candidate info
    $stmt = $conn->prepare("
        SELECT ec.id, ec.user_id, ec.name, ec.position, e.club_id, e.status AS election_status, e.title as election_title, c.name as club_name, c.code as club_code
        FROM election_candidates ec
        JOIN elections e ON e.id = ec.election_id
        JOIN clubs c ON c.id = e.club_id
        WHERE ec.id = ? AND ec.election_id = ?
    ");
    $stmt->bind_param('ii', $candidate_id, $election_id);
    $stmt->execute();
    $cand = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cand) {
        echo json_encode(['success' => false, 'message' => 'Candidate record not found for this election.']);
        exit;
    }

    if (!in_array(strtolower($cand['election_status']), ['verified', 'completed'])) {
        echo json_encode(['success' => false, 'message' => 'The elected officers must first be verified by the Supreme Student Council before roles can be assigned.']);
        exit;
    }

    $club_id   = (int)$cand['club_id'];
    $cand_name = trim($cand['name']);
    $position  = trim($cand['position']);

    // 2. Prefer stable foreign-key user_id if present
    $target_user_id = !empty($cand['user_id']) ? (int)$cand['user_id'] : 0;
    if ($target_user_id <= 0) {
        $parts = preg_split('/\s+/', $cand_name, 2);
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';

        $u_stmt = $conn->prepare("
            SELECT id FROM users
            WHERE (TRIM(CONCAT(first_name, ' ', last_name)) = ?
               OR (first_name = ? AND last_name = ?)
               OR username = ?)
            LIMIT 1
        ");
        $u_stmt->bind_param('ssss', $cand_name, $first, $last, $cand_name);
        $u_stmt->execute();
        $u_res = $u_stmt->get_result()->fetch_assoc();
        $u_stmt->close();

        if ($u_res) {
            $target_user_id = (int)$u_res['id'];
        }
    } else {
        // Fallback: search in students table
        $s_stmt = $conn->prepare("
            SELECT u.id FROM users u
            JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
            WHERE TRIM(CONCAT(s.first_name, ' ', s.last_name)) = ?
               OR s.student_number = ?
            LIMIT 1
        ");
        $s_stmt->bind_param('ss', $cand_name, $cand_name);
        $s_stmt->execute();
        $s_res = $s_stmt->get_result()->fetch_assoc();
        $s_stmt->close();
        if ($s_res) {
            $target_user_id = (int)$s_res['id'];
        }
    }

    if ($target_user_id <= 0) {
        echo json_encode(['success' => false, 'message' => "Could not locate a user account for candidate '$cand_name'."]);
        exit;
    }

    // 3. Update club_memberships role to 'Officer' (Do NOT change global users.role)
    $chk_mem = $conn->prepare("SELECT id, role FROM club_memberships WHERE club_id = ? AND user_id = ?");
    $chk_mem->bind_param('ii', $club_id, $target_user_id);
    $chk_mem->execute();
    $mem_row = $chk_mem->get_result()->fetch_assoc();
    $chk_mem->close();

    if ($mem_row) {
        $upd_mem = $conn->prepare("UPDATE club_memberships SET role = 'Officer', status = 'Active' WHERE id = ?");
        $upd_mem->bind_param('i', $mem_row['id']);
        $upd_mem->execute();
        $upd_mem->close();
    } else {
        $ins_mem = $conn->prepare("INSERT INTO club_memberships (club_id, user_id, role, status) VALUES (?, ?, 'Officer', 'Active')");
        $ins_mem->bind_param('ii', $club_id, $target_user_id);
        $ins_mem->execute();
        $ins_mem->close();
    }

    // 4. Mark candidate as appointed in election_candidates
    $conn->query("UPDATE election_candidates SET is_appointed = 1 WHERE id = $candidate_id");

    // 5. Notify student of officer appointment
    if (function_exists('push_notification')) {
        push_notification(
            $conn,
            $target_user_id,
            "Officer Proclamation: $position",
            "Congratulations! You have been officially proclaimed and appointed as $position of " . ($cand['club_code'] ?? 'your organization') . ".",
            'election'
        );
    }

    // 6. Log audit
    log_audit($conn, $user_id, 'appoint_officer', 'club_memberships', $target_user_id, "Appointed $cand_name as Officer ($position) for club $club_id");

    echo json_encode([
        'success'      => true,
        'message'      => "$cand_name has been successfully appointed as an Organization Officer ($position)!",
        'candidate_id' => $candidate_id,
        'user_id'      => $target_user_id
    ]);
    exit;
}

// ── 6b. VERIFY POLL & OPEN STUDENT VOTING (SSC & Admin) ───────────────
if ($action === 'verify_poll' || $action === 'approve_election') {
    if (!in_array($sess_role, ['ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only Supreme Student Council (SSC) Officers and Administrators can verify election polls.']);
        exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    if ($election_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid election ID.']);
        exit;
    }

    $el_stmt = $conn->prepare("SELECT e.id, e.title, e.club_id, e.created_by, c.name AS club_name, c.code AS club_code FROM elections e JOIN clubs c ON c.id = e.club_id WHERE e.id = ?");
    $el_stmt->bind_param('i', $election_id);
    $el_stmt->execute();
    $election = $el_stmt->get_result()->fetch_assoc();
    $el_stmt->close();

    if (!$election) {
        echo json_encode(['success' => false, 'message' => 'Election record not found.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE elections SET status = 'open', poll_verified_at = NOW(), poll_verified_by = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
    $stmt->bind_param('iii', $user_id, $user_id, $election_id);

    if ($stmt->execute()) {
        $stmt->close();

        // 1. Notify Adviser
        if (!empty($election['created_by']) && function_exists('push_notification')) {
            push_notification(
                $conn,
                (int)$election['created_by'],
                "Election Poll Verified by SSC: {$election['club_code']}",
                "Your election poll '{$election['title']}' for {$election['club_code']} has been verified by the Supreme Student Council. Voting is now live for all club members!",
                'election'
            );
        }

        // 2. Notify Active Student Members of that specific club
        if (function_exists('push_notification')) {
            $m_stmt = $conn->prepare("SELECT user_id FROM club_memberships WHERE club_id = ? AND status = 'Active' AND role != 'Adviser'");
            $m_stmt->bind_param('i', $election['club_id']);
            $m_stmt->execute();
            $m_res = $m_stmt->get_result();
            while ($m = $m_res->fetch_assoc()) {
                push_notification(
                    $conn,
                    (int)$m['user_id'],
                    "Election Voting is Live: {$election['club_code']}",
                    "The election poll for '{$election['title']}' has been verified by the SSC. You may now cast your official ballot!",
                    'election'
                );
            }
            $m_stmt->close();
        }

        if (function_exists('log_audit')) {
            log_audit($conn, $user_id, 'verify_poll', 'elections', $election_id, "SSC Officer verified poll and opened voting for {$election['club_code']} (ID: $election_id)");
        }

        echo json_encode([
            'success' => true,
            'message' => "Election poll for {$election['club_code']} has been verified by SSC! Club members can now cast their votes.",
            'election_id' => $election_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error verifying election poll: ' . $conn->error]);
    }
    exit;
}

// ── 6c. REJECT ELECTION (SSC & Admin) ─────────────────────────────────
if ($action === 'reject_election') {
    if (!in_array($sess_role, ['ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only SSC Officers and Administrators can reject elections.']);
        exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    $reason      = trim($_POST['rejection_reason'] ?? 'Did not meet Supreme Student Council election guidelines.');

    if ($election_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid election ID.']);
        exit;
    }

    $el_stmt = $conn->prepare("SELECT e.id, e.title, e.created_by, c.code AS club_code FROM elections e JOIN clubs c ON c.id = e.club_id WHERE e.id = ?");
    $el_stmt->bind_param('i', $election_id);
    $el_stmt->execute();
    $election = $el_stmt->get_result()->fetch_assoc();
    $el_stmt->close();

    if (!$election) {
        echo json_encode(['success' => false, 'message' => 'Election record not found.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE elections SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
    $stmt->bind_param('isi', $user_id, $reason, $election_id);

    if ($stmt->execute()) {
        $stmt->close();
        if (!empty($election['created_by']) && function_exists('push_notification')) {
            push_notification(
                $conn,
                (int)$election['created_by'],
                "Election Proposal Returned: {$election['club_code']}",
                "Your election '{$election['title']}' was reviewed by SSC and returned: {$reason}",
                'election'
            );
        }
        if (function_exists('log_audit')) {
            log_audit($conn, $user_id, 'reject_election', 'elections', $election_id, "SSC returned election proposal ID: $election_id (Reason: $reason)");
        }
        echo json_encode(['success' => true, 'message' => 'Election proposal returned to adviser.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error returning election proposal: ' . $conn->error]);
    }
    exit;
}

// ── 6d. ANNOUNCE ELECTED OFFICERS (Club Adviser & Admin) ──────────────
if ($action === 'announce_officers') {
    if (!in_array($sess_role, ['club_adviser', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only Faculty Advisers can officially announce elected officers.']);
        exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    if ($election_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid election ID.']);
        exit;
    }

    $el_stmt = $conn->prepare("SELECT e.id, e.title, e.club_id, e.status, c.name AS club_name, c.code AS club_code FROM elections e JOIN clubs c ON c.id = e.club_id WHERE e.id = ?");
    $el_stmt->bind_param('i', $election_id);
    $el_stmt->execute();
    $election = $el_stmt->get_result()->fetch_assoc();
    $el_stmt->close();

    if (!$election) {
        echo json_encode(['success' => false, 'message' => 'Election record not found.']);
        exit;
    }

    // Verify Adviser scoping
    if ($sess_role === 'club_adviser') {
        $my_club = getAdviserClubId($conn, $user_id);
        if ($my_club !== (int)$election['club_id']) {
            echo json_encode(['success' => false, 'message' => 'You can only announce elected officers for your handled organization.']);
            exit;
        }
    }

    $stmt = $conn->prepare("UPDATE elections SET status = 'announced', officers_announced_at = NOW(), officers_announced_by = ? WHERE id = ?");
    $stmt->bind_param('ii', $user_id, $election_id);

    if ($stmt->execute()) {
        $stmt->close();

        // 1. Notify SSC Officers for verification and role assignment
        $ssc_users = $conn->query("SELECT id FROM users WHERE role = 'ssc' AND status = 'Active'");
        if ($ssc_users) {
            while ($s = $ssc_users->fetch_assoc()) {
                push_notification(
                    $conn,
                    (int)$s['id'],
                    "Elected Officers Announced: {$election['club_code']}",
                    "Faculty Adviser has officially announced the elected officers for {$election['club_code']}. Please verify the elected officers to assign their official roles.",
                    'election'
                );
            }
        }

        // 2. Notify club students
        if (function_exists('push_notification')) {
            $m_stmt = $conn->prepare("SELECT user_id FROM club_memberships WHERE club_id = ? AND status = 'Active'");
            $m_stmt->bind_param('i', $election['club_id']);
            $m_stmt->execute();
            $m_res = $m_stmt->get_result();
            while ($m = $m_res->fetch_assoc()) {
                push_notification(
                    $conn,
                    (int)$m['user_id'],
                    "Elected Officers Announced: {$election['club_code']}",
                    "Faculty Adviser has officially announced the proclaimed winners of '{$election['title']}'! Awaiting SSC verification for role commissioning.",
                    'election'
                );
            }
            $m_stmt->close();
        }

        if (function_exists('log_audit')) {
            log_audit($conn, $user_id, 'announce_officers', 'elections', $election_id, "Adviser announced elected officers for {$election['club_code']} (ID: $election_id)");
        }

        echo json_encode([
            'success' => true,
            'message' => "Elected officers for {$election['club_code']} have been announced! Supreme Student Council has been notified to verify the officers and assign their roles.",
            'election_id' => $election_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error announcing elected officers: ' . $conn->error]);
    }
    exit;
}

// ── 7. VERIFY ELECTED OFFICERS (SSC & Admin) ──────────────────────────
if ($action === 'verify_officers' || $action === 'verify_election') {
    if (!in_array($sess_role, ['ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only SSC Officers and Administrators can verify elected officers.']);
        exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    $notes       = trim($_POST['audit_notes'] ?? 'Official election audit clearance and elected officer roster verified by Supreme Student Council.');

    if ($election_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid election ID.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE elections SET status = 'verified', officers_verified_at = NOW(), officers_verified_by = ?, verified_at = NOW(), verified_by = ?, audit_notes = ? WHERE id = ?");
    $stmt->bind_param('iisi', $user_id, $user_id, $notes, $election_id);

    if ($stmt->execute()) {
        $stmt->close();

        // Notify Adviser
        $el_info = $conn->query("SELECT e.title, e.club_id, e.created_by, c.code AS club_code FROM elections e JOIN clubs c ON c.id = e.club_id WHERE e.id = $election_id LIMIT 1")->fetch_assoc();
        if ($el_info && !empty($el_info['created_by']) && function_exists('push_notification')) {
            push_notification(
                $conn,
                (int)$el_info['created_by'],
                "Elected Officers Verified by SSC: {$el_info['club_code']}",
                "Supreme Student Council has verified and certified the elected officers for '{$el_info['title']}'. SSC will now assign their official officer roles.",
                'election'
            );
        }

        if (function_exists('log_audit')) {
            log_audit($conn, $user_id, 'verify_officers', 'elections', $election_id, "SSC verified elected officers for ID: $election_id");
        }
        echo json_encode(['success' => true, 'message' => 'Elected officers have been officially verified by the Supreme Student Council! You can now assign their official officer roles.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error verifying elected officers: ' . $conn->error]);
    }
    exit;
}

// ── 8. CONFIGURE ELECTION (Admin & SSC) ─────────────────────────────
if ($action === 'configure_election') {
    if (!in_array($sess_role, ['ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only Administrators and SSC Officers can configure election records.']);
        exit;
    }

    $election_id     = (int)($_POST['election_id'] ?? 0);
    $title           = trim($_POST['title'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $election_type   = trim($_POST['election_type'] ?? 'Student Governance');
    $closing_date    = trim($_POST['closing_date'] ?? '');
    $closing_time    = trim($_POST['closing_time'] ?? '23:59');
    $eligible_voters = (int)($_POST['eligible_voters'] ?? 0);
    $positions_raw   = trim($_POST['positions'] ?? '');

    if ($election_id <= 0 || empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Valid election ID and title are required.']);
        exit;
    }

    $closes_formatted = null;
    if (!empty($closing_date)) {
        if (empty($closing_time)) $closing_time = '23:59';
        $closes_formatted = date('Y-m-d H:i:s', strtotime($closing_date . ' ' . $closing_time));
    }

    $pos_arr  = array_values(array_filter(array_map('trim', explode(',', $positions_raw))));
    $pos_json = !empty($pos_arr) ? json_encode($pos_arr) : null;

    $stmt = $conn->prepare("UPDATE elections SET title = ?, description = ?, election_type = ?, closes_at = COALESCE(?, closes_at), eligible_voters = ?, positions = COALESCE(?, positions) WHERE id = ?");
    $stmt->bind_param('ssssisi', $title, $description, $election_type, $closes_formatted, $eligible_voters, $pos_json, $election_id);

    if ($stmt->execute()) {
        $stmt->close();
        if (function_exists('log_audit')) {
            log_audit($conn, $user_id, 'election_configure', 'elections', $election_id, "Configured election #$election_id ($title)");
        }
        echo json_encode(['success' => true, 'message' => 'Election record configured and updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error configuring election: ' . $conn->error]);
    }
    exit;
}

// ── 9. SECURITY: LOCK / CLOSE / UNLOCK ELECTION (Admin & SSC) ─────────
if ($action === 'toggle_lock') {
    if (!in_array($sess_role, ['ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only Administrators and SSC Officers can manage election security states.']);
        exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    if ($election_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid election ID.']);
        exit;
    }

    $chk = $conn->prepare("SELECT status, title FROM elections WHERE id = ?");
    $chk->bind_param('i', $election_id);
    $chk->execute();
    $el = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$el) {
        echo json_encode(['success' => false, 'message' => 'Election record not found.']);
        exit;
    }

    $cur_status = strtolower($el['status']);
    $new_status = ($cur_status === 'closed' || $cur_status === 'locked') ? 'open' : 'closed';
    $status_msg = ($new_status === 'closed') ? 'locked and closed to prevent further votes' : 'reopened for active voting';

    $upd = $conn->prepare("UPDATE elections SET status = ? WHERE id = ?");
    $upd->bind_param('si', $new_status, $election_id);
    if ($upd->execute()) {
        $upd->close();
        if (function_exists('log_audit')) {
            log_audit($conn, $user_id, 'election_security_lock', 'elections', $election_id, "Administrator updated security status of '{$el['title']}' to: $new_status");
        }
        echo json_encode(['success' => true, 'message' => "Election has been {$status_msg}.", 'new_status' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error updating security state: ' . $conn->error]);
    }
    exit;
}

// ── 10. AUDIT: INSPECT ELECTION ACTIVITY & ACCESS LOGS (Admin only) ──
if ($action === 'get_election_audit') {
    if ($sess_role !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Audit trail inspection is strictly restricted to System Administrators.']);
        exit;
    }

    $election_id = (int)($_REQUEST['election_id'] ?? 0);
    $where = "WHERE (al.target_table IN ('elections', 'election_votes', 'election_candidates') OR al.action LIKE '%election%')";
    if ($election_id > 0) {
        $where .= " AND (al.target_id = $election_id OR al.detail LIKE '%#$election_id%')";
    }

    $sql = "
        SELECT al.id, al.user_id, al.action, al.target_table, al.target_id, al.detail, al.ip_address, al.created_at,
               u.first_name, u.last_name, u.email, u.role
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        $where
        ORDER BY al.created_at DESC
        LIMIT 100
    ";

    $logs = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'logs' => $logs]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);

