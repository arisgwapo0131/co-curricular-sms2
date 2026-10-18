<?php
// ============================================================
//  SECURITY.PHP — Central Authentication, RBAC & CSRF Protection
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');
        }
    }
    @session_start();
}

if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 600); // 10 minutes inactivity timeout (600 seconds)
}

/**
 * Check if the active user session has exceeded the inactivity timeout.
 * Automatically destroys the session and redirects or returns 401 JSON.
 */
function check_session_timeout(?string $redirect_to = null): void {
    // Never block authentication or public signin/register operations
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    if (in_array($action, ['login', 'signin', 'register', 'verify_mfa', 'resend_mfa', 'cancel_mfa'])) {
        return;
    }
    $self = $_SERVER['PHP_SELF'] ?? '';
    if (strpos($self, 'signin.php') !== false || strpos($self, 'register.php') !== false) {
        return;
    }

    if (!empty($_SESSION['user_id'])) {
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
            // Session expired due to inactivity
            $_SESSION = [];
            if (!headers_sent() && ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }
            @session_destroy();

            $is_json = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                    || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
                    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

            if ($is_json) {
                http_response_code(401);
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                echo json_encode([
                    'success' => false,
                    'session_timeout' => true,
                    'message' => 'Your session has expired due to inactivity. Please sign in again.'
                ]);
                exit;
            } else {
                if ($redirect_to === null) {
                    if (strpos($self, '/dashboard/') !== false || strpos($self, '/shared/') !== false) {
                        $redirect_to = '../auth/signin.php?timeout=1';
                    } elseif (strpos($self, '/auth/') !== false) {
                        $redirect_to = 'signin.php?timeout=1';
                    } else {
                        $redirect_to = 'app/auth/signin.php?timeout=1';
                    }
                }
                if (!headers_sent()) {
                    header("Location: $redirect_to");
                } else {
                    echo '<script>window.location.href=' . json_encode($redirect_to) . ';</script>';
                }
                exit;
            }
        }
        // Active session: bump last activity
        $_SESSION['last_activity'] = $now;
    }
}

// Auto-check timeout whenever security.php is loaded with an active session
if (!empty($_SESSION['user_id'])) {
    check_session_timeout();
}

/**
 * Global Uncaught Exception Handler
 * Intercepts uncaught exceptions and renders the custom 500 error page or JSON response
 */
if (!function_exists('bcp_global_exception_handler')) {
    function bcp_global_exception_handler(Throwable $e): void {
        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $code = 500;
        $error_message = $e->getMessage();
        if (file_exists(__DIR__ . '/../errors/error.php')) {
            require __DIR__ . '/../errors/error.php';
        } else {
            http_response_code(500);
            echo '<h1>500 Internal Server Error</h1><p>' . htmlspecialchars($error_message) . '</p>';
        }
        exit;
    }
}

/**
 * Global Fatal Error Shutdown Handler
 * Intercepts fatal PHP runtime crashes and cleanly displays the 500 error page
 */
if (!function_exists('bcp_global_shutdown_handler')) {
    function bcp_global_shutdown_handler(): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            if (ob_get_level() > 0) {
                @ob_end_clean();
            }
            $code = 500;
            $error_message = $err['message'] ?? 'An unexpected application error occurred.';
            if (file_exists(__DIR__ . '/../errors/error.php')) {
                require __DIR__ . '/../errors/error.php';
            } else {
                http_response_code(500);
                echo '<h1>500 Internal Server Error</h1><p>' . htmlspecialchars($error_message) . '</p>';
            }
        }
    }
}

if (!defined('BCP_ERROR_HANDLERS_REGISTERED')) {
    define('BCP_ERROR_HANDLERS_REGISTERED', true);
    set_exception_handler('bcp_global_exception_handler');
    register_shutdown_function('bcp_global_shutdown_handler');
}



/**
 * Generate or retrieve CSRF token for the session
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Alias for csrf_token()
 */
function generate_csrf_token(): string {
    return csrf_token();
}

/**
 * Generate hidden input field for forms
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify CSRF token from POST or headers
 */
function verify_csrf(bool $halt = true): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) && function_exists('getallheaders')) {
        $headers = getallheaders();
        $token = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $headers['X-Csrf-Token'] ?? '';
    }
    if (empty($token)) {
        $raw = @file_get_contents('php://input');
        if (!empty($raw)) {
            $json = json_decode($raw, true);
            if (is_array($json) && !empty($json['csrf_token'])) {
                $token = $json['csrf_token'];
            }
        }
    }
    $valid = !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    
    if (!$valid && $halt) {
        http_response_code(419);
        $is_json = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
                || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        
        if ($is_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security validation (CSRF) failed or session expired. Please refresh the page and try again.']);
        } else {
            $code = 419;
            $error_message = 'Security validation (CSRF) token expired. Please refresh the page and submit again.';
            if (file_exists(__DIR__ . '/../errors/error.php')) {
                require __DIR__ . '/../errors/error.php';
            } else {
                echo '<h3>419 Page Expired</h3><p>' . htmlspecialchars($error_message) . '</p>';
            }
        }
        exit;
    }
    return $valid;
}

/**
 * Enforce authentication. Redirects to signin if not logged in.
 */
function require_auth(string $redirect_to = '../auth/signin.php'): void {
    $timeout_target = (strpos($redirect_to, '?') === false) ? ($redirect_to . '?timeout=1') : ($redirect_to . '&timeout=1');
    check_session_timeout($timeout_target);
    if (empty($_SESSION['user_id']) || !empty($_SESSION['mfa_pending'])) {
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'mfa_pending' => !empty($_SESSION['mfa_pending']),
                'message' => !empty($_SESSION['mfa_pending']) ? 'Multi-factor verification required.' : 'Unauthenticated session.'
            ]);
            exit;
        }
        $target = (!empty($_SESSION['mfa_pending'])) ? (strpos($redirect_to, '?') === false ? $redirect_to . '?mfa=1' : $redirect_to . '&mfa=1') : $redirect_to;
        header("Location: $target");
        exit;
    }
}

/**
 * Enforce role-based access control (RBAC)
 */
/**
 * Enforce role-based access control (RBAC) via permission keys
 */
function require_role(array|string $allowed_roles, string $forbidden_redirect = '../dashboard/dashboard.php'): void {
    require_auth();
    if (is_string($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    $current_role = $_SESSION['role'] ?? 'student';
    
    // Check role or permission key equivalent
    if (!in_array($current_role, $allowed_roles, true)) {
        // Map common roles to permission capabilities for dynamic permission-based access
        $role_perm_map = [
            'super_admin'  => 'dashboard.view.system',
            'admin'        => 'dashboard.view.system',
            'staff'        => 'dashboard.view.institutional',
            'ssc'          => 'dashboard.view.institutional',
            'club_adviser' => 'dashboard.view.org',
            'student'      => 'dashboard.view.own'
        ];
        $has_perm_access = false;
        foreach ($allowed_roles as $ar) {
            $pk = $role_perm_map[$ar] ?? null;
            if ($pk && has_permission($pk)) {
                $has_perm_access = true;
                break;
            }
        }

        if (!$has_perm_access) {
            if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Unauthorized access. Required permissions not satisfied.']);
                exit;
            }
            header("Location: $forbidden_redirect?error=unauthorized");
            exit;
        }
    }
}

/**
 * Load user permissions from database into session cache
 * Dynamically queries roles, permissions, role_permissions, and user_roles
 */
function load_user_permissions(?mysqli $db = null, ?string $role = null, ?int $user_id = null): array {
    global $conn;
    $db = $db ?? $conn;
    $user_id = $user_id ?? (int)($_SESSION['user_id'] ?? 0);
    $role = $role ?? ($_SESSION['role'] ?? 'student');

    // Super Admin and Admin have all permissions unconditionally
    if ($role === 'super_admin' || $role === 'admin') {
        $all = ['*'];
        if ($db) {
            try {
                $q = $db->query("SELECT permission_key FROM permissions");
                if ($q) {
                    while ($r = $q->fetch_row()) {
                        $all[] = $r[0];
                    }
                }
            } catch (Throwable $e) {}
        }
        $_SESSION['permissions'] = array_values(array_unique($all));
        return $_SESSION['permissions'];
    }

    $perms = [];
    if ($db) {
        try {
            // 1. Check user_roles junction table if present
            if ($user_id > 0) {
                $stmt = $db->prepare("
                    SELECT DISTINCT p.permission_key 
                    FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id
                    JOIN role_permissions rp ON rp.role_id = r.id
                    JOIN permissions p ON p.id = rp.permission_id
                    WHERE ur.user_id = ? AND r.status = 'active'
                ");
                if ($stmt) {
                    $stmt->bind_param('i', $user_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $perms[] = $row['permission_key'];
                    }
                    $stmt->close();
                }
            }

            // 2. Also query by role name in roles and role_permissions
            if (!empty($role)) {
                $stmt = $db->prepare("
                    SELECT DISTINCT p.permission_key 
                    FROM role_permissions rp
                    JOIN permissions p ON p.id = rp.permission_id
                    JOIN roles r ON r.id = rp.role_id
                    WHERE r.name = ? AND r.status = 'active'
                ");
                if ($stmt) {
                    $stmt->bind_param('s', $role);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $perms[] = $row['permission_key'];
                    }
                    $stmt->close();
                }
            }
        } catch (Throwable $e) {
            // DB fallback if tables not yet populated
        }
    }

    // Default baseline matrix according to Specification Section 4.2 (merged with DB permissions)
    $fallbacks = [
        'super_admin' => ['*'],
        'admin' => ['*'],
        'student' => [
            'dashboard.view.own', 'organization.view', 'organization.apply', 'membership.apply',
            'membership.view_own', 'events.view', 'events.register',
            'elections.vote', 'announcements.view', 'users.view_own'
        ],
        'club_adviser' => [
            'dashboard.view.org', 'organization.manage.own', 'membership.review.own',
            'events.view', 'events.create.own', 'events.edit.own', 'budget.create.own',
            'budget.endorse.adviser', 'attendance.track.org', 'elections.manage.org',
            'achievements.view', 'achievements.submit',
            'announcements.create.org', 'reports.view.org', 'reports.export', 'users.view_own'
        ],
        'ssc' => [
            'dashboard.view.institutional', 'organization.view', 'organization.review.all',
            'membership.review.all', 'events.view', 'events.create.institutional',
            'events.review.ssc', 'budget.review.ssc', 'budget.view.all',
            'attendance.view.analytics', 'attendance.override', 'elections.oversight',
            'achievements.view', 'achievements.verify.ssc',
            'announcements.create.council', 'reports.view.institutional', 'reports.export',
            'users.view.directory', 'users.view_own'
        ],
        'staff' => [
            'dashboard.view.institutional', 'organization.view', 'membership.review.all',
            'events.view', 'events.create.institutional', 'events.review.ssc',
            'budget.view.all', 'budget.review.ssc',
            'attendance.view.analytics', 'attendance.override', 'attendance.track.org',
            'achievements.view', 'announcements.view', 'announcements.create.council',
            'reports.view.institutional', 'reports.export',
            'users.view.directory', 'users.view_own', 'volunteer.view', 'volunteer.track',
            'elections.oversight'
        ]
    ];

    if (isset($fallbacks[$role])) {
        $perms = array_merge($perms, $fallbacks[$role]);
    }

    $_SESSION['permissions'] = array_values(array_unique($perms));
    $_SESSION['permissions_ver'] = 3;
    return $_SESSION['permissions'];
}

/**
 * Check if current user has permission key and optional scope
 * Scopes: 'own', 'institutional', 'system'
 */
function has_permission(string $permission_key, ?string $scope = null): bool {
    $current_role = $_SESSION['role'] ?? 'student';
    if ($current_role === 'super_admin' || $current_role === 'admin') {
        return true;
    }

    // Check scope requirement
    if ($scope !== null) {
        if ($scope === 'system' && !in_array($current_role, ['super_admin', 'admin'], true)) {
            return false;
        }
        if ($scope === 'institutional' && !in_array($current_role, ['super_admin', 'admin', 'staff', 'ssc'], true)) {
            return false;
        }
    }

    if (!isset($_SESSION['permissions']) || !is_array($_SESSION['permissions']) || ($_SESSION['permissions_ver'] ?? 0) < 3) {
        load_user_permissions();
    }

    $perms = $_SESSION['permissions'] ?? [];
    if (in_array('*', $perms, true) || in_array($permission_key, $perms, true)) {
        return true;
    }

    return false;
}

/**
 * Clean alias for has_permission()
 */
function can(string $permission_key, ?string $scope = null): bool {
    return has_permission($permission_key, $scope);
}

/**
 * Check if current user has ANY of the provided permission keys
 */
function has_any_permission(array $permission_keys, ?string $scope = null): bool {
    foreach ($permission_keys as $pk) {
        if (has_permission($pk, $scope)) {
            return true;
        }
    }
    return false;
}

/**
 * Alias for has_any_permission()
 */
function can_any(array $permission_keys, ?string $scope = null): bool {
    return has_any_permission($permission_keys, $scope);
}

/**
 * Check if current user has ALL of the provided permission keys
 */
function can_all(array $permission_keys, ?string $scope = null): bool {
    foreach ($permission_keys as $pk) {
        if (!has_permission($pk, $scope)) {
            return false;
        }
    }
    return true;
}

/**
 * Enforce permission requirement, halting or redirecting on failure
 */
function require_permission(string $permission_key, ?string $scope = null, string $forbidden_redirect = '../dashboard/dashboard.php'): void {
    require_auth();
    if (!has_permission($permission_key, $scope)) {
        $is_json = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
                || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

        if ($is_json) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => "Access denied. Missing permission: {$permission_key}" . ($scope ? " [Scope: {$scope}]" : "")
            ]);
            exit;
        }

        header("Location: $forbidden_redirect?error=unauthorized_permission&key=" . urlencode($permission_key));
        exit;
    }
}

/**
 * Enforce that the user has at least one of the specified permission keys
 */
function require_any_permission(array $permission_keys, ?string $scope = null, string $forbidden_redirect = '../dashboard/dashboard.php'): void {
    require_auth();
    if (!has_any_permission($permission_keys, $scope)) {
        $is_json = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
                || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

        if ($is_json) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => "Access denied. Missing required authorization."
            ]);
            exit;
        }

        header("Location: $forbidden_redirect?error=unauthorized_permission");
        exit;
    }
}

/**
 * Escape HTML output
 */
function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Record a state transition in workflow_history (Section 14.2 & 20.3)
 */
function log_workflow_history(mysqli $conn, string $module, int $record_id, ?string $from_status, string $to_status, string $action, ?int $performed_by = null, ?string $remarks = null): bool {
    $uid = $performed_by ?? ($_SESSION['user_id'] ?? null);
    $stmt = $conn->prepare("
        INSERT INTO workflow_history (module, record_id, from_status, to_status, action, performed_by, remarks, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) return false;
    $stmt->bind_param('sisssis', $module, $record_id, $from_status, $to_status, $action, $uid, $remarks);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Verify Organization Scope for Club Advisers (Section 6.3)
 * An adviser can ONLY modify records for their assigned organization.
 */
function verify_club_adviser_scope(mysqli $conn, int $club_id, ?int $user_id = null): bool {
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return false;

    $role = $_SESSION['role'] ?? '';
    if (empty($role)) {
        $r_stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        if ($r_stmt) {
            $r_stmt->bind_param('i', $uid);
            $r_stmt->execute();
            $role = $r_stmt->get_result()->fetch_assoc()['role'] ?? '';
            $r_stmt->close();
        }
    }

    // Admins and SSC officers have broader institutional permissions
    if ($role === 'admin' || $role === 'ssc') {
        return true;
    }
    if ($role !== 'club_adviser') {
        return false;
    }
    $assigned_clubs = get_user_assigned_clubs($conn, $uid, $role);
    $assigned_ids = array_map('intval', array_column($assigned_clubs, 'id'));
    return in_array((int)$club_id, $assigned_ids, true);
}

/**
 * Retrieve all clubs assigned to a user based on their role.
 * - club_adviser: strictly their advised organization(s) (by membership, adviser_user_id, username/program code, or adviser_name).
 * - student: active joined clubs.
 * - admin / ssc: all active recognized clubs.
 */
function get_user_assigned_clubs(?mysqli $db = null, ?int $user_id = null, ?string $role = null): array {
    global $conn;
    if ($db === null) {
        if (!isset($conn) || !$conn) {
            require_once __DIR__ . '/db.php';
        }
        $db = $conn;
    }
    if (!$db) return [];

    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    $user_role = $role ?? ($_SESSION['role'] ?? 'student');

    if (in_array($user_role, ['super_admin', 'admin', 'staff', 'ssc'], true)) {
        $res = $db->query("
            SELECT c.id, c.name, c.code, c.category, c.description, c.adviser_name,
                   (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Active' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS active_member_count,
                   (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Pending' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS pending_member_count
            FROM clubs c 
            WHERE c.deleted_at IS NULL AND c.status = 'Active'
            ORDER BY c.name ASC
        ");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    if ($user_role === 'student') {
        if ($uid <= 0) return [];
        $stmt = $db->prepare("
            SELECT c.id, c.name, c.code, c.category, c.description, c.adviser_name,
                   (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Active' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS active_member_count,
                   (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Pending' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS pending_member_count
            FROM clubs c
            JOIN club_memberships cm2 ON cm2.club_id = c.id
            WHERE cm2.user_id = ? AND cm2.status = 'Active' AND c.deleted_at IS NULL AND c.status = 'Active'
            ORDER BY c.name ASC
        ");
        if (!$stmt) return [];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    // Role: club_adviser
    if ($uid <= 0) return [];

    // 1. Direct active adviser memberships or adviser_user_id
    $stmt = $db->prepare("
        SELECT c.id, c.name, c.code, c.category, c.description, c.adviser_name,
               (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Active' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS active_member_count,
               (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Pending' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS pending_member_count
        FROM clubs c
        WHERE c.deleted_at IS NULL AND c.status = 'Active'
          AND (
              c.id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND (status = 'Active' OR LOWER(role) LIKE '%adviser%'))
              OR c.adviser_user_id = ?
          )
        ORDER BY c.name ASC
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $uid, $uid);
        $stmt->execute();
        $clubs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!empty($clubs)) {
            return $clubs;
        }
    }

    // 2. Lookup adviser user metadata
    $u_stmt = $db->prepare("SELECT username, first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
    if (!$u_stmt) return [];
    $u_stmt->bind_param('i', $uid);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result()->fetch_assoc();
    $u_stmt->close();

    $username = $u_res['username'] ?? '';
    $first_name = trim($u_res['first_name'] ?? '');
    $last_name = trim($u_res['last_name'] ?? '');
    $full_name = trim($first_name . ' ' . $last_name);
    
    $prefix = strtoupper(explode('.', $username)[0] ?? '');
    $prefix_clean = str_replace('-', '', $prefix);
    $prefix_no_dot = str_replace('.', '', $prefix);
    $prefix_like = !empty($prefix) ? ($prefix . '%') : '___NONE___';
    $prog_match = ($prefix === 'BSIT' || $prefix === 'ITS') ? 'BSIT' : '___NONE___';

    $stmt2 = $db->prepare("
        SELECT c.id, c.name, c.code, c.category, c.description, c.adviser_name,
               (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Active' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS active_member_count,
               (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Pending' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS pending_member_count
        FROM clubs c
        WHERE c.deleted_at IS NULL AND c.status = 'Active'
          AND (
              c.code = ? 
              OR REPLACE(c.code, '-', '') = ? 
              OR REPLACE(c.code, '.', '') = ?
              OR c.code LIKE ?
              OR (c.program = ? AND ? != '___NONE___')
          )
        ORDER BY c.name ASC
    ");
    if ($stmt2) {
        $stmt2->bind_param('ssssss', $prefix, $prefix_clean, $prefix_no_dot, $prefix_like, $prog_match, $prog_match);
        $stmt2->execute();
        $clubs = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
        if (!empty($clubs)) {
            return $clubs;
        }
    }

    // 3. Fallback: match by full name in adviser_name
    if (!empty($full_name)) {
        $name_prof = 'Prof. ' . $full_name;
        $name_dr = 'Dr. ' . $full_name;
        $stmt3 = $db->prepare("
            SELECT c.id, c.name, c.code, c.category, c.description, c.adviser_name,
                   (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Active' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS active_member_count,
                   (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Pending' AND LOWER(cm.role) != 'adviser' AND cm.role NOT LIKE '%adviser%') AS pending_member_count
            FROM clubs c
            WHERE c.deleted_at IS NULL AND c.status = 'Active'
              AND (c.adviser_name = ? OR c.adviser_name = ? OR c.adviser_name = ?)
            ORDER BY c.name ASC
        ");
        if ($stmt3) {
            $stmt3->bind_param('sss', $full_name, $name_prof, $name_dr);
            $stmt3->execute();
            $clubs = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt3->close();
            if (!empty($clubs)) {
                return $clubs;
            }
        }
    }

    return [];
}

/**
 * Check if a student is an approved member of any student organization.
 * Condition: Adviser approved / endorsed the student's application (Active or Endorsed).
 */
function is_student_organization_member(?mysqli $db = null, ?int $user_id = null): bool {
    global $conn;
    if ($db === null) {
        if (!isset($conn) || !$conn) {
            require_once __DIR__ . '/db.php';
        }
        $db = $conn;
    }
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    if (!$db || $uid <= 0) return false;

    // Check club_memberships: Active or endorsed by adviser
    $stmt = $db->prepare("
        SELECT 1 FROM club_memberships 
        WHERE user_id = ? 
          AND (status = 'Active' OR adviser_review = 'Endorsed' OR adviser_review = 'Approved')
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $is_member = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($is_member) return true;
    }

    // Check club_applications: Endorsed by adviser or Approved
    $app_stmt = $db->prepare("
        SELECT 1 FROM club_applications 
        WHERE user_id = ? 
          AND (adviser_status = 'Endorsed' OR adviser_review = 'Endorsed' OR status = 'Approved')
        LIMIT 1
    ");
    if ($app_stmt) {
        $app_stmt->bind_param('i', $uid);
        $app_stmt->execute();
        $is_app_approved = $app_stmt->get_result()->num_rows > 0;
        $app_stmt->close();
        if ($is_app_approved) return true;
    }

    return false;
}

/**
 * Apply Standard Security Headers (Section 15)
 */
function apply_security_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Auto-apply security headers
apply_security_headers();
?>
