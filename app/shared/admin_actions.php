<?php
// ============================================================
//  ADMIN_ACTIONS.PHP — System Administration AJAX handler
//  Actions: list_users, create_user, update_user, update_role,
//           reset_password, delete_user, list_logs, list_stuck,
//           override_budget, system_health, export_users_csv
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/security.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';

if (!in_array($user_role, ['super_admin', 'admin', 'staff', 'ssc'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

// CSRF check on mutating requests
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function adRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

switch ($action) {

    // ── LIST all users ────────────────────────────────────────
    case 'list_users': {
        $search = trim($_GET['search'] ?? '');
        $role_filter = trim($_GET['role'] ?? '');

        $where = [];
        $params = [];
        $types = '';

        if (!empty($role_filter)) {
            $where[] = "u.role = ?";
            $params[] = $role_filter;
            $types .= 's';
        }

        if (!empty($search)) {
            $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR s.student_number LIKE ?)";
            $s_term = "%$search%";
            $params = array_merge($params, [$s_term, $s_term, $s_term, $s_term, $s_term]);
            $types .= 'sssss';
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.role, u.created_at,
                       s.student_number, s.course, s.year_level, s.section, s.status AS student_status
                FROM users u
                LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
                $where_sql
                ORDER BY u.role, u.last_name, u.first_name";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Server-side field filtering for SSC (data-layer RBAC)
        if ($user_role === 'ssc') {
            foreach ($rows as &$r) {
                if ($r['role'] === 'admin') {
                    $r['email'] = '[Protected Administrator]';
                }
            }
            unset($r);
        }

        adRespond(true, 'OK', ['users' => $rows]);
    }

    // ── CREATE NEW USER (Admin only) ──────────────────────────
    case 'create_user': {
        if (!in_array($user_role, ['super_admin', 'admin'])) {
            adRespond(false, 'Only System Administrators can create new accounts.');
        }

        $username   = preg_replace('/\s+/', '', strtolower(trim($_POST['username'] ?? '')));
        $email      = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $role       = trim($_POST['role'] ?? 'student');
        $password   = trim($_POST['password'] ?? '');
        $student_no = trim($_POST['student_number'] ?? '');
        $course     = trim($_POST['course'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '1st Year');
        $section    = trim($_POST['section'] ?? '1A');
        $club_id    = (int)($_POST['club_id'] ?? 0);

        $valid_roles = ['super_admin', 'admin', 'staff', 'student', 'club_adviser', 'ssc'];
        if (!$username || !$email || !$first_name || !$last_name || !$password || !in_array($role, $valid_roles)) {
            adRespond(false, 'Please fill in all required fields including password.');
        }
        if (strlen($password) < 8) {
            adRespond(false, 'Password must be at least 8 characters long.');
        }

        // Check if username already exists
        $chk_u = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk_u->bind_param('s', $username);
        $chk_u->execute();
        if ($chk_u->get_result()->num_rows > 0) {
            $chk_u->close();
            adRespond(false, "The username \"$username\" is already taken. Please choose another username.");
        }
        $chk_u->close();

        // Check if email already exists
        $chk_e = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chk_e->bind_param('s', $email);
        $chk_e->execute();
        if ($chk_e->get_result()->num_rows > 0) {
            $chk_e->close();
            adRespond(false, "The email address \"$email\" is already registered in the system.");
        }
        $chk_e->close();

        try {
            $pw_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, first_name, last_name, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
            $stmt->bind_param('ssssss', $username, $email, $first_name, $last_name, $pw_hash, $role);
            if (!$stmt->execute()) {
                adRespond(false, 'Failed to create user account: ' . $stmt->error);
            }
            $new_user_id = (int)$stmt->insert_id;
            $stmt->close();

            // If student role, create or link student record
            if ($role === 'student') {
                $s_no = $student_no !== '' ? $student_no : ('2026-' . rand(10000, 99999));
                $c_val = $course !== '' ? $course : 'Bachelor of Science in Information Technology';
                $yr_val = $year_level !== '' ? $year_level : '1st Year';
                $sec_val = $section !== '' ? $section : '1A';

                // Check if a student with this student number already exists
                $chk_st = $conn->prepare("SELECT id FROM students WHERE student_number = ? LIMIT 1");
                $chk_st->bind_param('s', $s_no);
                $chk_st->execute();
                $st_res = $chk_st->get_result();
                if ($st_res && $st_row = $st_res->fetch_assoc()) {
                    // Update and link student record to new user account
                    $st_id = (int)$st_row['id'];
                    $upd_st = $conn->prepare("UPDATE students SET user_id = ?, first_name = ?, last_name = ?, course = ?, year_level = ?, section = ? WHERE id = ?");
                    $upd_st->bind_param('isssssi', $new_user_id, $first_name, $last_name, $c_val, $yr_val, $sec_val, $st_id);
                    $upd_st->execute();
                    $upd_st->close();
                } else {
                    // Insert new student record with user_id
                    $s_stmt = $conn->prepare("INSERT INTO students (user_id, student_number, first_name, last_name, birthday, course, year_level, section, phone, status) VALUES (?, ?, ?, ?, '2004-01-01', ?, ?, ?, '09123456789', 'Active')");
                    $s_stmt->bind_param('issssss', $new_user_id, $s_no, $first_name, $last_name, $c_val, $yr_val, $sec_val);
                    $s_stmt->execute();
                    $s_stmt->close();
                }
                $chk_st->close();
            }

            // If club adviser and club selected, link adviser to club
            if ($role === 'club_adviser' && $club_id > 0) {
                $full_name = trim("$first_name $last_name");
                $upd_c = $conn->prepare("UPDATE clubs SET adviser_user_id = ?, adviser_name = ? WHERE id = ?");
                $upd_c->bind_param('isi', $new_user_id, $full_name, $club_id);
                $upd_c->execute();
                $upd_c->close();

                // Also record in club_memberships
                $cm = $conn->prepare("INSERT INTO club_memberships (user_id, club_id, role, status) VALUES (?, ?, 'Adviser', 'Active') ON DUPLICATE KEY UPDATE role = 'Adviser', status = 'Active'");
                $cm->bind_param('ii', $new_user_id, $club_id);
                $cm->execute();
                $cm->close();
            }

            log_audit($conn, $user_id, 'admin_create_user', 'users', $new_user_id, "Created new user #$new_user_id ($username, $role)");
            adRespond(true, "User account for \"$first_name $last_name\" created successfully!", ['user_id' => $new_user_id]);
        } catch (Throwable $e) {
            adRespond(false, 'Database error while creating user: ' . $e->getMessage());
        }
    }

    // ── UPDATE USER DETAILS & ROLE (Admin only) ───────────────
    case 'update_user': {
        if (!in_array($user_role, ['super_admin', 'admin'])) {
            adRespond(false, 'Only System Administrators can update user accounts.');
        }

        $target_id  = (int)($_POST['user_id'] ?? 0);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $new_role   = trim($_POST['role'] ?? '');

        $valid_roles = ['super_admin', 'admin', 'staff', 'student', 'club_adviser', 'ssc'];
        if ($target_id <= 0 || !$first_name || !$last_name || !$email || !in_array($new_role, $valid_roles)) {
            adRespond(false, 'Invalid input fields.');
        }

        // Email uniqueness check
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $chk->bind_param('si', $email, $target_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            adRespond(false, 'Email address is already in use by another account.');
        }
        $chk->close();

        $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, role = ? WHERE id = ?");
        $stmt->bind_param('ssssi', $first_name, $last_name, $email, $new_role, $target_id);
        if (!$stmt->execute()) {
            adRespond(false, 'Failed to update user: ' . $stmt->error);
        }
        $stmt->close();

        push_notification($conn, $target_id, 'Account Information Updated',
            "Your profile details and system role have been updated by the System Administrator.", 'info');

        log_audit($conn, $user_id, 'admin_update_user', 'users', $target_id, "Updated user #$target_id ($first_name $last_name, role: $new_role)");
        adRespond(true, 'User details updated successfully.');
    }

    // ── UPDATE user role (Admin only) ────────────────────────
    case 'update_role': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only System Administrators can change roles.');
        $target_id  = (int)($_POST['user_id'] ?? 0);
        $new_role   = trim($_POST['new_role'] ?? '');
        $valid_roles = ['super_admin', 'admin', 'staff', 'student', 'club_adviser', 'ssc'];
        if ($target_id <= 0 || !in_array($new_role, $valid_roles)) adRespond(false, 'Invalid user or role.');
        if ($target_id === $user_id) adRespond(false, 'You cannot change your own role.');

        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->bind_param('si', $new_role, $target_id);
        if (!$stmt->execute()) adRespond(false, 'Failed to update role.');
        $stmt->close();

        push_notification($conn, $target_id, 'Role Updated',
            "Your system role has been updated to: " . ucwords(str_replace('_', ' ', $new_role)), 'info');
        log_audit($conn, $user_id, 'admin_role_change', 'users', $target_id,
            "Changed user #$target_id role to $new_role");

        adRespond(true, 'User role updated successfully.');
    }

    // ── RESET USER PASSWORD (Admin only) ──────────────────────
    case 'reset_password': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only System Administrators can reset passwords.');
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_pass  = trim($_POST['new_password'] ?? '');
        if ($target_id <= 0 || empty($new_pass) || strlen($new_pass) < 8) {
            adRespond(false, 'Invalid user ID or new password must be at least 8 characters.');
        }

        $pw_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $pw_hash, $target_id);
        if (!$stmt->execute()) adRespond(false, 'Failed to reset password.');
        $stmt->close();

        push_notification($conn, $target_id, 'Security Alert: Password Reset',
            "Your account password was reset by the System Administrator.", 'warning');
        log_audit($conn, $user_id, 'admin_reset_password', 'users', $target_id, "Reset password for user #$target_id");

        adRespond(true, "Password for user #$target_id successfully reset!");
    }

    // ── TOGGLE USER STATUS (Active / Inactive) (Admin only) ──
    case 'toggle_status': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only System Administrators can update account status.');
        $target_id  = (int)($_POST['user_id'] ?? 0);
        $new_status = trim($_POST['status'] ?? '');
        if (!in_array($new_status, ['Active', 'Inactive'])) {
            adRespond(false, 'Invalid status value.');
        }
        if ($target_id <= 0) adRespond(false, 'Invalid user ID.');
        if ($target_id === $user_id && $new_status === 'Inactive') {
            adRespond(false, 'You cannot deactivate your own administrative account.');
        }

        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $new_status, $target_id);
        if (!$stmt->execute()) {
            adRespond(false, 'Failed to update user status.');
        }
        $stmt->close();

        log_audit($conn, $user_id, 'admin_toggle_user_status', 'users', $target_id, "Set user #$target_id status to $new_status");
        adRespond(true, "User status successfully changed to {$new_status}.", ['new_status' => $new_status]);
    }

    // ── GET USER PROFILE DETAILS (Admin Profile Drawer) ─────
    case 'get_user_profile': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only System Administrators can inspect profile drawer.');
        $target_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
        if ($target_id <= 0) adRespond(false, 'Invalid user ID.');

        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.role, u.status, u.last_login, u.last_password_change, u.created_at,
                   s.student_number, s.course, s.year_level, s.section, s.status AS student_status,
                   (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = u.id AND cm.status = 'Active') AS club_names,
                   (SELECT GROUP_CONCAT(COALESCE(c.code, c.name) SEPARATOR ', ') FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = u.id AND cm.status = 'Active') AS club_codes,
                   (SELECT GROUP_CONCAT(cm.role SEPARATOR ', ') FROM club_memberships cm WHERE cm.user_id = u.id AND cm.status = 'Active') AS membership_roles
            FROM users u
            LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
            WHERE u.id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $target_id);
        $stmt->execute();
        $user_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user_row) adRespond(false, 'User not found.');

        // Get recent IP and recent audit activity
        $rec_ip = null;
        $recent_actions = [];
        $act_stmt = $conn->prepare("SELECT action, detail, ip_address, created_at FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $act_stmt->bind_param('i', $target_id);
        $act_stmt->execute();
        $act_res = $act_stmt->get_result();
        while ($ar = $act_res->fetch_assoc()) {
            if (!$rec_ip && !empty($ar['ip_address'])) {
                $rec_ip = $ar['ip_address'];
            }
            $recent_actions[] = [
                'action' => $ar['action'],
                'detail' => $ar['detail'],
                'created_at' => date('M d, Y H:i', strtotime($ar['created_at']))
            ];
        }
        $act_stmt->close();

        adRespond(true, 'OK', [
            'user' => $user_row,
            'recent_ip' => $rec_ip ?? '127.0.0.1 (Localhost)',
            'recent_actions' => $recent_actions
        ]);
    }

    // ── GET USER ACTIVITY LOGS (Admin only) ───────────────────
    case 'get_user_activity': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only System Administrators can inspect user activity.');
        $target_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
        if ($target_id <= 0) adRespond(false, 'Invalid user ID.');

        $stmt = $conn->prepare("SELECT action, target_table, target_id, detail, ip_address, created_at FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->bind_param('i', $target_id);
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        adRespond(true, 'OK', ['activity' => $logs]);
    }

    // ── LIST audit logs (Admin only) ───────────────────────────
    case 'list_logs': {
        if (!in_array($user_role, ['super_admin', 'admin'])) {
            adRespond(false, 'Access denied. Audit logs are strictly restricted to System Administrators.');
        }
        $limit  = min((int)($_GET['limit'] ?? 50), 200);
        $filter = trim($_GET['filter'] ?? '');
        $where  = $filter ? "WHERE (al.action LIKE ? OR al.detail LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)" : '';
        $filter_val = "%$filter%";

        $sql = "SELECT al.id, al.action, al.target_table, al.target_id, al.detail,
                       al.ip_address, al.created_at,
                       u.first_name, u.last_name, u.role
                FROM audit_logs al
                JOIN users u ON u.id = al.user_id
                $where
                ORDER BY al.created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        if ($filter) {
            $stmt->bind_param('ssssi', $filter_val, $filter_val, $filter_val, $filter_val, $limit);
        } else {
            $stmt->bind_param('i', $limit);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        adRespond(true, 'OK', ['logs' => $rows]);
    }

    // ── LIST stuck budget requests & events ───────────────────
    case 'list_stuck': {
        $stuck_budgets = $conn->query(
            "SELECT br.id, br.title, br.amount, br.status, br.created_at,
                    c.name AS club_name, u.first_name, u.last_name
             FROM budget_requests br
             JOIN clubs c ON c.id = br.club_id
             JOIN users u ON u.id = br.requested_by
             WHERE br.status NOT IN ('Disbursed','Rejected')
             AND br.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY br.created_at ASC"
        )->fetch_all(MYSQLI_ASSOC);

        $stuck_events = $conn->query(
            "SELECT e.id, e.title, e.event_date, e.status, e.created_at,
                    c.name AS club_name, u.first_name, u.last_name
             FROM events e
             LEFT JOIN clubs c ON c.id = e.club_id
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.status NOT IN ('Approved','Completed','Cancelled','Rejected')
             AND e.deleted_at IS NULL
             AND e.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY e.created_at ASC"
        )->fetch_all(MYSQLI_ASSOC);

        adRespond(true, 'OK', [
            'stuck'         => $stuck_budgets,
            'stuck_budgets' => $stuck_budgets,
            'stuck_events'  => $stuck_events
        ]);
    }

    // ── FORCE APPROVE stuck budget (Admin only) ───────────────
    case 'override_budget': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only Admins can force-approve budgets.');
        $id = (int)($_POST['budget_id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) adRespond(false, 'Invalid request ID.');

        $stmt = $conn->prepare("UPDATE budget_requests SET status='Pending Admin', notes=CONCAT(COALESCE(notes,''), ' [Force-advanced to Admin by System Admin override]') WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $req = $conn->query("SELECT requested_by, title FROM budget_requests WHERE id=$id")->fetch_assoc();
        if ($req) {
            push_notification($conn, (int)$req['requested_by'], 'Budget Override',
                "Your budget request \"{$req['title']}\" was force-advanced by System Administrator.", 'info');
        }
        log_audit($conn, $user_id, 'admin_budget_override', 'budget_requests', $id, "Force-approved budget #$id");
        adRespond(true, 'Budget force-approved and ready for final disbursement.');
    }

    // ── FORCE APPROVE stuck event (Admin only) ────────────────
    case 'override_event': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only Admins can force-approve events.');
        $id = (int)($_POST['event_id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) adRespond(false, 'Invalid event ID.');

        $stmt = $conn->prepare("UPDATE events SET status='Approved', notes=CONCAT(COALESCE(notes,''), ' [Force-cleared by System Admin override]') WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $ev = $conn->query("SELECT created_by, title FROM events WHERE id=$id")->fetch_assoc();
        if ($ev && !empty($ev['created_by'])) {
            push_notification($conn, (int)$ev['created_by'], 'Event Override',
                "Your event proposal \"{$ev['title']}\" was force-cleared and published by System Administrator.", 'info');
        }
        log_audit($conn, $user_id, 'admin_event_override', 'events', $id, "Force-approved event #$id");
        adRespond(true, 'Event proposal force-approved and published to campus calendar.');
    }

    // ── SAVE SYSTEM SETTINGS (Admin only) ─────────────────────
    // ── SAVE SYSTEM SETTINGS (Admin only) ─────────────────────
    case 'save_system_settings': {
        if (!in_array($user_role, ['super_admin', 'admin', 'staff'])) adRespond(false, 'Only System Administrators and authorized staff can update institutional settings.');

        // Ensure table exists
        $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $allowed_keys = [
            'academic_year',
            'active_semester',
            'org_categories',
            'allowed_org_types',
            'notification_templates',
            'notification_default_priority',
            'notification_retention_days',
            'ai_provider',
            'ai_model',
            'gemini_api_key',
            'mfa_enabled',
            'mfa_expiry_minutes',
            'mfa_resend_cooldown',
            'mfa_allow_dev_preview',
            'smtp_host',
            'smtp_port',
            'smtp_user',
            'smtp_pass',
            'smtp_crypto',
            'mail_from_name',
            'mail_from_email'
        ];

        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

        $saved_count = 0;
        foreach ($allowed_keys as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);

                // Secret protection: If API key or SMTP password is empty or masked placeholders, do NOT overwrite existing
                if ($key === 'gemini_api_key' || $key === 'smtp_pass') {
                    if (empty($val) || preg_match('/^[•\*]+$/u', $val) || str_contains($val, '••••')) {
                        continue; // Keep existing stored value
                    }
                    if ($key === 'gemini_api_key') {
                        $_SESSION['gemini_api_key'] = $val;
                    }
                }

                $stmt->bind_param('ss', $key, $val);
                $stmt->execute();
                $saved_count++;
            }
        }
        $stmt->close();

        log_audit($conn, $user_id, 'admin_save_settings', 'system_settings', 0, "Updated {$saved_count} institutional system settings");
        adRespond(true, 'Institutional system settings updated successfully.');
    }

    // ── TEST EMAIL DELIVERY (Admin only) ──────────────────────
    case 'test_email_delivery': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only System Administrators can run email diagnostics.');
        $target_email = trim($_POST['target_email'] ?? $_POST['recipient'] ?? '');
        if (!$target_email || !filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
            adRespond(false, 'Please provide a valid destination email address for testing.');
        }

        require_once __DIR__ . '/mail_helper.php';
        $testCode = sprintf('%06d', random_int(100000, 999999));
        $testHtml = "
        <div style='font-family:sans-serif; max-width:500px; margin:0 auto; padding:20px; border:1px solid #e2e8f0; border-radius:12px;'>
            <h2 style='color:#1e40af; margin-top:0;'>BCP Co-Curricular SMS — Email Test</h2>
            <p>This is a test notification dispatched from the BCP Co-Curricular Management System to verify your SMTP server configuration.</p>
            <div style='background:#eff6ff; border:2px dashed #3b82f6; border-radius:10px; padding:16px; text-align:center; font-family:monospace; font-size:28px; font-weight:800; color:#1e40af; margin:18px 0;'>
                {$testCode}
            </div>
            <p style='font-size:12px; color:#64748b;'>Server Timestamp: " . date('Y-m-d H:i:s T') . "</p>
        </div>";

        $res = send_system_email($target_email, 'BCP Administrator', "BCP SMS SMTP Diagnostic Test [Code: {$testCode}]", $testHtml, "Test verification code: {$testCode}", $conn);

        if ($res['success']) {
            log_audit($conn, $user_id, 'ADMIN_TEST_EMAIL', 'system_settings', 0, "Dispatched test email via {$res['method']} to {$target_email}", 'info');
            adRespond(true, "Test email dispatched successfully to {$target_email} via " . strtoupper($res['method']) . "!");
        } else {
            adRespond(false, "Email dispatch failed: " . ($res['message'] ?? 'Unknown error'));
        }
    }

    // ── EXPORT USERS CSV (Admin only) ─────────────────────────
    case 'export_users_csv': {
        if (!in_array($user_role, ['super_admin', 'admin'])) {
            adRespond(false, 'Export is restricted to System Administrators.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bcp_users_export_' . date('Ymd_His') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Username', 'Role', 'First Name', 'Last Name', 'Email', 'Student Number', 'Course', 'Year Level', 'Section', 'Created At']);

        $res = $conn->query("
            SELECT u.id, u.username, u.role, u.first_name, u.last_name, u.email,
                   s.student_number, s.course, s.year_level, s.section, u.created_at
            FROM users u
            LEFT JOIN students s ON (s.user_id = u.id OR (s.first_name = u.first_name AND s.last_name = u.last_name))
            ORDER BY u.id ASC
        ");
        while ($row = $res->fetch_assoc()) {
            fputcsv($out, $row);
        }
        fclose($out);
        log_audit($conn, $user_id, 'admin_export_users_csv', 'users', 0, 'Exported all users to CSV');
        exit;
    }

    // ── EXPORT AUDIT LOGS CSV (Admin only) ────────────────────
    case 'export_audit_csv': {
        if (!in_array($user_role, ['super_admin', 'admin'])) {
            adRespond(false, 'Export is restricted to System Administrators.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bcp_audit_logs_' . date('Ymd_His') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Log ID', 'Timestamp', 'User ID', 'Actor Name', 'Role', 'Action', 'Target Table', 'Target ID', 'Detail', 'IP Address']);

        $res = $conn->query("
            SELECT al.id, al.created_at, al.user_id, CONCAT(u.first_name, ' ', u.last_name) AS actor_name,
                   u.role, al.action, al.target_table, al.target_id, al.detail, al.ip_address
            FROM audit_logs al
            JOIN users u ON u.id = al.user_id
            ORDER BY al.id DESC
        ");
        while ($row = $res->fetch_assoc()) {
            fputcsv($out, $row);
        }
        fclose($out);
        log_audit($conn, $user_id, 'admin_export_audit_csv', 'audit_logs', 0, 'Exported system audit logs to CSV');
        exit;
    }

    // ── SYSTEM HEALTH & DIAGNOSTICS ───────────────────────────
    case 'system_health': {
        $db_version = $conn->server_info;
        $uploads_ok = is_writable(__DIR__ . '/../uploads/');
        $user_count = (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
        $club_count = (int)$conn->query("SELECT COUNT(*) FROM clubs")->fetch_row()[0];
        $event_count = (int)$conn->query("SELECT COUNT(*) FROM events")->fetch_row()[0];
        $budget_count = (int)$conn->query("SELECT COUNT(*) FROM budget_requests")->fetch_row()[0];
        $log_count = (int)$conn->query("SELECT COUNT(*) FROM audit_logs")->fetch_row()[0];

        $diagnostics = [
            'php_version'    => PHP_VERSION,
            'db_version'     => $db_version,
            'uploads_writable' => $uploads_ok,
            'counts' => [
                'users'   => $user_count,
                'clubs'   => $club_count,
                'events'  => $event_count,
                'budgets' => $budget_count,
                'logs'    => $log_count,
            ],
            'server_time'    => date('Y-m-d H:i:s T'),
            'status'         => 'Healthy & Operational'
        ];
        adRespond(true, 'System health OK', ['diagnostics' => $diagnostics]);
    }

    // ── DELETE user (Admin only) ──────────────────────────────
    case 'delete_user': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only Admins can delete users.');
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id <= 0 || $target_id === $user_id) adRespond(false, 'Invalid user ID or self-delete not allowed.');

        $conn->query("DELETE FROM users WHERE id = $target_id");
        log_audit($conn, $user_id, 'admin_delete_user', 'users', $target_id, "Deleted user #$target_id");
        adRespond(true, 'User deleted.');
    }

    // ── CREATE ACADEMIC PROGRAM (Admin & SSC) ─────────────────
    case 'create_academic_program': {
        if (!in_array($user_role, ['super_admin', 'admin', 'ssc']) && !can('master_data.academic')) {
            adRespond(false, 'Only Administrators or authorized Council Officers can manage academic programs.');
        }
        $code       = strtoupper(trim($_POST['code'] ?? ''));
        $name       = trim($_POST['name'] ?? '');
        $department = !empty(trim($_POST['department'] ?? '')) ? trim($_POST['department']) : 'Academic Affairs';
        $status     = in_array($_POST['status'] ?? '', ['Active', 'Inactive']) ? $_POST['status'] : 'Active';

        if (!$code || !$name) {
            adRespond(false, 'Program Code and Program Name are required.');
        }

        $chk = $conn->prepare("SELECT id FROM academic_programs WHERE code = ? LIMIT 1");
        $chk->bind_param('s', $code);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            adRespond(false, "Program code '$code' already exists in the Academic Programs directory.");
        }
        $chk->close();

        $stmt = $conn->prepare("INSERT INTO academic_programs (code, name, department, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $code, $name, $department, $status);
        if (!$stmt->execute()) {
            adRespond(false, 'Failed to save academic program: ' . $stmt->error);
        }
        $prog_id = $stmt->insert_id;
        $stmt->close();

        log_audit($conn, $user_id, 'admin_create_academic_program', 'academic_programs', $prog_id, "Created academic program $code - $name");
        adRespond(true, "Academic program '$code' created successfully.", ['id' => $prog_id, 'code' => $code]);
    }

    // ── UPDATE ACADEMIC PROGRAM (Admin & SSC) ─────────────────
    case 'update_academic_program': {
        if (!in_array($user_role, ['super_admin', 'admin', 'ssc']) && !can('master_data.academic')) {
            adRespond(false, 'Only Administrators or authorized Council Officers can update academic programs.');
        }
        $id         = (int)($_POST['id'] ?? 0);
        $code       = strtoupper(trim($_POST['code'] ?? ''));
        $name       = trim($_POST['name'] ?? '');
        $department = !empty(trim($_POST['department'] ?? '')) ? trim($_POST['department']) : 'Academic Affairs';
        $status     = in_array($_POST['status'] ?? '', ['Active', 'Inactive']) ? $_POST['status'] : 'Active';

        if ($id <= 0 || !$code || !$name) {
            adRespond(false, 'Invalid academic program data.');
        }

        $chk = $conn->prepare("SELECT id FROM academic_programs WHERE code = ? AND id != ? LIMIT 1");
        $chk->bind_param('si', $code, $id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            adRespond(false, "Program code '$code' is already used by another academic program.");
        }
        $chk->close();

        $stmt = $conn->prepare("UPDATE academic_programs SET code = ?, name = ?, department = ?, status = ? WHERE id = ?");
        $stmt->bind_param('ssssi', $code, $name, $department, $status, $id);
        if (!$stmt->execute()) {
            adRespond(false, 'Failed to update academic program: ' . $stmt->error);
        }
        $stmt->close();

        log_audit($conn, $user_id, 'admin_update_academic_program', 'academic_programs', $id, "Updated academic program $code ($status)");
        adRespond(true, "Academic program '$code' updated successfully.", ['id' => $id, 'code' => $code]);
    }

    // ── DELETE ACADEMIC PROGRAM (Admin & SSC) ─────────────────
    case 'delete_academic_program': {
        if (!in_array($user_role, ['super_admin', 'admin', 'ssc']) && !can('master_data.academic')) {
            adRespond(false, 'Only Administrators or authorized Council Officers can remove academic programs.');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) adRespond(false, 'Invalid academic program ID.');

        $stmt = $conn->prepare("DELETE FROM academic_programs WHERE id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            adRespond(false, 'Failed to delete program: ' . $stmt->error);
        }
        $stmt->close();

        log_audit($conn, $user_id, 'admin_delete_academic_program', 'academic_programs', $id, "Deleted academic program #$id");
        adRespond(true, "Academic program removed successfully.");
    }

    // ── CREATE NEW CLUB MASTER DATA (Admin only) ───────────────
    case 'create_club_master': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only System Administrators can create organizations.');
        $code         = strtoupper(trim($_POST['code'] ?? ''));
        $name         = trim($_POST['name'] ?? '');
        $category     = trim($_POST['category'] ?? 'Academic');
        $sub_category = trim($_POST['sub_category'] ?? '');
        $program      = trim($_POST['program'] ?? 'Institutional');
        $description  = trim($_POST['description'] ?? '');
        $adviser_user_id = !empty($_POST['adviser_user_id']) ? (int)$_POST['adviser_user_id'] : null;
        $status       = trim($_POST['status'] ?? 'Active');

        $valid_categories = ['Academic', 'Cultural', 'Sports', 'Advocacy', 'Religious'];
        $valid_statuses   = ['Active', 'Pending Charter', 'Suspended'];

        if (!$code || !$name) {
            adRespond(false, 'Organization Code and Name are required.');
        }
        if (!in_array($category, $valid_categories)) {
            adRespond(false, 'Invalid category selected.');
        }
        if (!in_array($status, $valid_statuses)) {
            $status = 'Active';
        }

        // Duplicate code check
        $chk = $conn->prepare("SELECT id FROM clubs WHERE code = ? AND deleted_at IS NULL LIMIT 1");
        $chk->bind_param('s', $code);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            adRespond(false, "Organization code \"$code\" is already taken. Please choose another code.");
        }
        $chk->close();

        // Resolve Adviser name
        $adv_name = 'Unassigned';
        if ($adviser_user_id) {
            $u_stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS fullname FROM users WHERE id = ?");
            $u_stmt->bind_param('i', $adviser_user_id);
            $u_stmt->execute();
            $u_row = $u_stmt->get_result()->fetch_assoc();
            $u_stmt->close();
            if ($u_row) {
                $adv_name = $u_row['fullname'];
            } else {
                $adviser_user_id = null;
            }
        }

        // Default sub-category
        if (empty($sub_category)) {
            if ($category === 'Cultural') {
                $sub_category = 'Talent Center';
            } elseif ($category === 'Sports') {
                $sub_category = 'Sports Club';
            } elseif ($category === 'Advocacy') {
                $sub_category = 'Campus-Wide Independent Bodies';
            } elseif ($category === 'Religious') {
                $sub_category = 'Spiritual & Faith Formation';
            } else {
                $sub_category = 'Academic Organization';
            }
        }

        if ($adviser_user_id) {
            $stmt = $conn->prepare("INSERT INTO clubs (code, name, category, sub_category, program, description, adviser_user_id, adviser_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssssiss', $code, $name, $category, $sub_category, $program, $description, $adviser_user_id, $adv_name, $status);
        } else {
            $stmt = $conn->prepare("INSERT INTO clubs (code, name, category, sub_category, program, description, adviser_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssssss', $code, $name, $category, $sub_category, $program, $description, $adv_name, $status);
        }

        if (!$stmt->execute()) {
            adRespond(false, 'Failed to create organization: ' . $stmt->error);
        }
        $new_cid = $stmt->insert_id;
        $stmt->close();

        // Auto-initialize default club chat channels
        $gen_name  = "$code • General Club Chat";
        $gen_desc  = "Official group chat for $name faculty adviser, student officers, and all active members.";
        $exec_name = "$code • Executive Board";
        $exec_desc = "Leadership coordination channel for $name faculty adviser and student officers.";

        $cg_stmt = $conn->prepare("
            INSERT INTO chat_groups (club_id, group_type, name, description, icon)
            VALUES (?, 'club_general', ?, ?, 'fa-comments'),
                   (?, 'club_executive', ?, ?, 'fa-shield-halved')
        ");
        if ($cg_stmt) {
            $cg_stmt->bind_param('isssis', $new_cid, $gen_name, $gen_desc, $new_cid, $exec_name, $exec_desc);
            $cg_stmt->execute();
            $cg_stmt->close();
        }

        // If adviser assigned and status is Active, record active membership
        if ($adviser_user_id && $status === 'Active') {
            $cm_ins = $conn->prepare("
                INSERT INTO club_memberships (club_id, user_id, role, status, adviser_review, ssc_review)
                VALUES (?, ?, 'Adviser', 'Active', 'Approved', 'Approved')
                ON DUPLICATE KEY UPDATE role = 'Adviser', status = 'Active'
            ");
            if ($cm_ins) {
                $cm_ins->bind_param('ii', $new_cid, $adviser_user_id);
                $cm_ins->execute();
                $cm_ins->close();
            }
            push_notification(
                $conn,
                $adviser_user_id,
                'Assigned as Faculty Adviser',
                "You have been assigned as the Faculty Club Adviser for \"$name\" ($code).",
                'info',
                'clubs',
                $new_cid,
                'club_directory.php',
                'Normal'
            );
        }

        log_audit($conn, $user_id, 'admin_create_club_master', 'clubs', $new_cid, "Admin created organization: $name ($code) with status $status");
        adRespond(true, "Organization \"$name\" ($code) created successfully.", ['club_id' => $new_cid]);
    }

    // ── UPDATE CLUB MASTER STATUS & ADVISER (Admin only) ───────
    case 'update_club_master': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only System Administrators can configure organization master data.');
        $club_id    = (int)($_POST['club_id'] ?? 0);
        $status     = trim($_POST['status'] ?? 'Active');
        $adviser_user_id = !empty($_POST['adviser_user_id']) ? (int)$_POST['adviser_user_id'] : null;
        $category        = trim($_POST['category'] ?? 'Academic');

        $valid_statuses = ['Active', 'Suspended', 'Archived', 'Pending'];
        if ($club_id <= 0 || !in_array($status, $valid_statuses)) {
            adRespond(false, 'Invalid club data or status.');
        }

        if ($adviser_user_id) {
            $adv_name = null;
            $u_stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS fullname FROM users WHERE id = ?");
            $u_stmt->bind_param('i', $adviser_user_id);
            $u_stmt->execute();
            $u_row = $u_stmt->get_result()->fetch_assoc();
            $u_stmt->close();

            if ($u_row) {
                $adv_name = $u_row['fullname'];
                $stmt = $conn->prepare("UPDATE clubs SET status = ?, adviser_user_id = ?, adviser_name = ?, category = ? WHERE id = ?");
                $stmt->bind_param('sissi', $status, $adviser_user_id, $adv_name, $category, $club_id);
            } else {
                $stmt = $conn->prepare("UPDATE clubs SET status = ?, adviser_user_id = ?, category = ? WHERE id = ?");
                $stmt->bind_param('sisi', $status, $adviser_user_id, $category, $club_id);
            }
        } else {
            $unassigned = 'Unassigned';
            $stmt = $conn->prepare("UPDATE clubs SET status = ?, adviser_user_id = NULL, adviser_name = ?, category = ? WHERE id = ?");
            $stmt->bind_param('sssi', $status, $unassigned, $category, $club_id);
        }

        if (!$stmt->execute()) {
            adRespond(false, 'Failed to update organization: ' . $stmt->error);
        }
        $stmt->close();

        log_audit($conn, $user_id, 'admin_update_club_master', 'clubs', $club_id, "Updated club #$club_id status: $status, category: $category");
        adRespond(true, "Organization settings updated successfully.");
    }

    // ── RESOLVE / UPDATE SECURITY AUDIT LOG (Admin only) ───────
    case 'resolve_security_log': {
        if (!in_array($user_role, ['super_admin', 'admin'])) adRespond(false, 'Only System Administrators can manage security events.');
        $log_id = (int)($_POST['log_id'] ?? 0);
        $new_status = trim($_POST['status'] ?? 'resolved');
        $notes = trim($_POST['notes'] ?? '');

        if ($log_id <= 0 || !in_array($new_status, ['unresolved', 'investigating', 'resolved'])) {
            adRespond(false, 'Invalid log ID or status value.');
        }

        $stmt = $conn->prepare("UPDATE audit_logs SET resolution_status = ?, resolution_notes = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?");
        $stmt->bind_param('ssii', $new_status, $notes, $user_id, $log_id);
        if (!$stmt->execute()) {
            adRespond(false, 'Failed to update security event: ' . $stmt->error);
        }
        $stmt->close();

        adRespond(true, "Security event status updated to " . ucfirst($new_status) . ".");
    }

    default:
        adRespond(false, 'Unknown action.');
}
$conn->close();
