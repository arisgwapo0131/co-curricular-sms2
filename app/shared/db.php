<?php
// ============================================================
//  DB.PHP — Database Connection & Environment Loader
// ============================================================

// Bestlink College of the Philippines timezone synchronization
if (date_default_timezone_get() !== 'Asia/Manila') {
    date_default_timezone_set('Asia/Manila');
}

// Disable automatic mysqli exception throwing for safe graceful error handling in PHP 8.1+
if (function_exists('mysqli_report')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}

// 1. Load environment variables from .env if available
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val, " \t\n\r\0\x0B\"'");
                if (getenv($key) === false && !array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                    putenv("$key=$val");
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                }
            }
        }
    }
}

// 2. Parse DATABASE_URL / MYSQL_URL / MYSQL_PUBLIC_URL if provided by Railway or cloud platforms
$db_url = getenv('MYSQL_URL') ?: (getenv('MYSQL_PUBLIC_URL') ?: (getenv('DATABASE_URL') ?: (getenv('CLEARDB_DATABASE_URL') ?: getenv('JAWSDB_URL'))));

// Detect Railway environment
$is_railway = (bool)(
    getenv('RAILWAY_ENVIRONMENT') ||
    getenv('RAILWAY_PROJECT_ID') ||
    getenv('RAILWAY_SERVICE_NAME') ||
    getenv('RAILWAY_PUBLIC_DOMAIN') ||
    getenv('RAILWAY_STATIC_URL') ||
    getenv('RAILWAY_DEPLOYMENT_ID') ||
    getenv('RAILWAY_GIT_COMMIT_SHA') ||
    (getenv('MYSQLHOST') && strpos(getenv('MYSQLHOST'), 'railway') !== false) ||
    ($db_url && strpos($db_url, 'railway') !== false)
);

if ($db_url && strpos($db_url, '://') !== false) {
    $parsed_url = parse_url($db_url);
    if ($parsed_url) {
        if (!empty($parsed_url['host'])) {
            putenv('DB_HOST=' . $parsed_url['host']);
            $_ENV['DB_HOST'] = $parsed_url['host'];
        }
        if (!empty($parsed_url['port'])) {
            putenv('DB_PORT=' . $parsed_url['port']);
            $_ENV['DB_PORT'] = (string)$parsed_url['port'];
        }
        if (!empty($parsed_url['user'])) {
            $u_dec = rawurldecode($parsed_url['user']);
            putenv('DB_USER=' . $u_dec);
            $_ENV['DB_USER'] = $u_dec;
        }
        if (isset($parsed_url['pass'])) {
            $p_dec = rawurldecode($parsed_url['pass']);
            putenv('DB_PASS=' . $p_dec);
            $_ENV['DB_PASS'] = $p_dec;
        }
        if (!empty($parsed_url['path'])) {
            $d_dec = ltrim($parsed_url['path'], '/');
            // On Railway or cloud, if path is 'railway' or empty, use 'sms_db'
            if ($is_railway || $d_dec === 'railway' || $d_dec === 'test' || $d_dec === '') {
                $d_dec = 'sms_db';
            }
            putenv('DB_NAME=' . $d_dec);
            $_ENV['DB_NAME'] = $d_dec;
        }
    }
}

// 3. Normalize all database environment variables & cloud provider aliases (Railway, MariaDB, Docker)
$host = getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: (getenv('MARIADB_HOST') ?: (getenv('MYSQL_HOST') ?: (getenv('DATABASE_HOST') ?: (getenv('DB_HOSTNAME') ?: '127.0.0.1')))));
$port = (int)(getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: (getenv('MARIADB_PORT') ?: (getenv('MYSQL_PORT') ?: (getenv('DATABASE_PORT') ?: 3306)))));
$user = getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: (getenv('MARIADB_USER') ?: (getenv('DB_USERNAME') ?: (getenv('MYSQL_USER') ?: (getenv('DATABASE_USER') ?: 'root')))));
$pass = getenv('MYSQLPASSWORD');
if ($pass === false || $pass === null) {
    $pass = getenv('DB_PASS');
}
if ($pass === false || $pass === null) {
    $pass = getenv('MARIADB_PASSWORD') ?: (getenv('DB_PASSWORD') ?: (getenv('MYSQL_PASSWORD') ?: (getenv('DATABASE_PASSWORD') ?: '')));
}
$db = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: (getenv('MARIADB_DATABASE') ?: (getenv('DB_DATABASE') ?: (getenv('MYSQL_DATABASE') ?: (getenv('DATABASE_NAME') ?: 'sms_db')))));

// CRITICAL: Always prioritize and enforce 'sms_db' on Railway & Cloud deployments
if ($is_railway || empty($db) || $db === 'railway' || $db === 'test') {
    $db = 'sms_db';
    putenv('DB_NAME=sms_db');
    $_ENV['DB_NAME'] = 'sms_db';
}

// Check session override if configured via web setup
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['db_override'])) {
    $ov = $_SESSION['db_override'];
    $host = $ov['host'] ?? $host;
    $port = (int)($ov['port'] ?? $port);
    $user = $ov['user'] ?? $user;
    $pass = $ov['pass'] ?? $pass;
    $db   = $ov['name'] ?? $db;
}

$is_setup_script = (basename($_SERVER['PHP_SELF'] ?? '') === 'setup.php');
$db_connected = false;
$db_error = null;
$conn = null;

// Detect if explicit Railway / Cloud DB environment variables are present
$has_cloud_env = (bool)(
    $is_railway ||
    getenv('MYSQL_URL') ||
    getenv('MYSQL_PUBLIC_URL') ||
    getenv('DATABASE_URL') ||
    getenv('MYSQLHOST') ||
    getenv('RAILWAY_ENVIRONMENT') ||
    getenv('RAILWAY_PROJECT_ID') ||
    (getenv('DB_HOST') && !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true))
);

// 4. Ultra-Fast Resilient Connection Engine with Host Caching & Smart Prioritization
$cache_file = __DIR__ . '/.db_host_cache.json';

// Helper function to test candidate profiles with strict non-blocking timeout
if (!function_exists('bcp_probe_db_profile')) {
    function bcp_probe_db_profile(array $p, bool $is_setup = false, int $timeout = 2): ?mysqli {
        if (empty($p['host'])) return null;
        try {
            $c = mysqli_init();
            if (!$c) return null;
            @$c->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
            $target_db = $is_setup ? '' : ($p['db'] ?? '');
            $port = (int)($p['port'] ?? 3306);
            $ok = @$c->real_connect($p['host'], $p['user'] ?? '', $p['pass'] ?? '', $target_db, $port);
            if ($ok && !$c->connect_error) {
                return $c;
            }
            // If target DB didn't connect, try connecting without DB to create/select it
            if (!$is_setup && !empty($p['db'])) {
                $c2 = mysqli_init();
                if ($c2) {
                    @$c2->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
                    if (@$c2->real_connect($p['host'], $p['user'] ?? '', $p['pass'] ?? '', '', $port)) {
                        @$c2->query("CREATE DATABASE IF NOT EXISTS `" . $p['db'] . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        if (@$c2->select_db($p['db'])) {
                            return $c2;
                        }
                    }
                }
            }
        } catch (Throwable $e) {}
        return null;
    }
}

// Check in-process global connection instance if already established
if (!empty($GLOBALS['__bcp_active_conn']) && $GLOBALS['__bcp_active_conn'] instanceof mysqli && @$GLOBALS['__bcp_active_conn']->ping()) {
    $conn = $GLOBALS['__bcp_active_conn'];
    $db_connected = true;
    $host = $GLOBALS['__bcp_active_host'] ?? $host;
    $port = $GLOBALS['__bcp_active_port'] ?? $port;
    $user = $GLOBALS['__bcp_active_user'] ?? $user;
    $pass = $GLOBALS['__bcp_active_pass'] ?? $pass;
    $db   = $GLOBALS['__bcp_active_db']   ?? $db;
}

// 4.1 Fast-Path: Probe the cached winning connection profile ONLY if it matches our environment
if (!$conn && file_exists($cache_file)) {
    $cached = @json_decode(@file_get_contents($cache_file), true);
    if ($cached && !empty($cached['host'])) {
        // Ignore local 127.0.0.1 cache if we are deployed on Railway / Cloud
        $cached_is_local = in_array($cached['host'], ['127.0.0.1', 'localhost'], true);
        if (!($has_cloud_env && $cached_is_local)) {
            $c = bcp_probe_db_profile($cached, $is_setup_script, 1);
            if ($c) {
                $conn = $c;
                $db_connected = true;
                $host = $cached['host'];
                $port = (int)$cached['port'];
                $user = $cached['user'];
                $pass = $cached['pass'];
                $db   = $cached['db'];
            }
        }
    }
}

// 4.2 Fallback: Determine environment and search profiles
if (!$conn) {
    $is_local_env = !$has_cloud_env && (
        (PHP_OS_FAMILY === 'Windows') ||
        in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true) ||
        in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) ||
        strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost:') === 0 ||
        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1:') === 0
    );

    $connection_profiles = [];

    // 1. If Railway or Cloud environment variables are present, prioritize them FIRST!
    if ($has_cloud_env || $is_railway) {
        if ($host && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $connection_profiles[] = [
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'pass' => $pass,
                'db'   => 'sms_db'
            ];
        }

        // Railway private networking DNS hostnames
        $railway_private_hosts = [
            'mysql.railway.internal',
            'mariadb.railway.internal',
            'database.railway.internal',
            'db.railway.internal'
        ];
        foreach ($railway_private_hosts as $rph) {
            $connection_profiles[] = [
                'host' => $rph,
                'port' => $port ?: 3306,
                'user' => $user ?: 'root',
                'pass' => $pass ?: '',
                'db'   => 'sms_db'
            ];
        }

        // Also try MYSQL_PUBLIC_URL if internal host is still warming up
        $pub_url = getenv('MYSQL_PUBLIC_URL');
        if ($pub_url && strpos($pub_url, '://') !== false) {
            $pu = parse_url($pub_url);
            if (!empty($pu['host'])) {
                $connection_profiles[] = [
                    'host' => $pu['host'],
                    'port' => (int)($pu['port'] ?? 3306),
                    'user' => !empty($pu['user']) ? rawurldecode($pu['user']) : $user,
                    'pass' => isset($pu['pass']) ? rawurldecode($pu['pass']) : $pass,
                    'db'   => 'sms_db'
                ];
            }
        }
    }

    // 2. Local machine priority when running in local development (Windows / localhost)
    if ($is_local_env) {
        $connection_profiles[] = [
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'pass' => '',
            'db'   => 'sms_db'
        ];
        $connection_profiles[] = [
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'root',
            'pass' => '',
            'db'   => 'sms_db'
        ];
    }

    // 3. Configured Environment / .env Variables
    $connection_profiles[] = [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
        'db'   => 'sms_db'
    ];

    // 4. Docker Compose fallback
    $connection_profiles[] = [
        'host' => 'db',
        'port' => 3306,
        'user' => 'sms_user',
        'pass' => 'sms_secure_password',
        'db'   => 'sms_db'
    ];

    // 5. Local fallback if not added previously
    if (!$is_local_env && !$is_railway) {
        $connection_profiles[] = [
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'pass' => '',
            'db'   => 'sms_db'
        ];
    }

    // Test candidate profiles
    $probe_timeout = $has_cloud_env ? 3 : 1;
    foreach ($connection_profiles as $p) {
        if (empty($p['host'])) continue;
        $c = bcp_probe_db_profile($p, $is_setup_script, $probe_timeout);
        if ($c) {
            $conn = $c;
            $db_connected = true;
            $db_error = null;
            $host = $p['host'];
            $port = (int)$p['port'];
            $user = $p['user'];
            $pass = $p['pass'];
            $db   = $p['db'];

            // Save winning profile to runtime cache for instant subsequent requests
            @file_put_contents($cache_file, json_encode($p, JSON_UNESCAPED_SLASHES));
            break;
        }
    }
}

// Cache global instance for in-process script reuse
if ($conn && $db_connected) {
    $GLOBALS['__bcp_active_conn'] = $conn;
    $GLOBALS['__bcp_active_host'] = $host;
    $GLOBALS['__bcp_active_port'] = $port;
    $GLOBALS['__bcp_active_user'] = $user;
    $GLOBALS['__bcp_active_pass'] = $pass;
    $GLOBALS['__bcp_active_db']   = $db;
}

// Define DB constants for backward compatibility across modules
if (!defined('DB_HOST')) define('DB_HOST', $host);
if (!defined('DB_USER')) define('DB_USER', $user);
if (!defined('DB_PASS')) define('DB_PASS', $pass);
if (!defined('DB_NAME')) define('DB_NAME', $db);
if (!defined('DB_PORT')) define('DB_PORT', $port);

if ($db_connected && $conn && !$conn->connect_error) {
    @$conn->query("SET time_zone = '+08:00'");
    $conn->set_charset('utf8mb4');

    if ($is_setup_script) {
        $conn->query("CREATE DATABASE IF NOT EXISTS `" . $db . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->select_db($db);
    } else {
        // Auto-initialize fresh database per-host/db OR if 'users' table is missing
        $migration_flag_file = __DIR__ . '/.migrated';
        $expected_mig_sig = md5($host . ':' . $port . '/' . $db . '_v2');
        $current_mig_sig = file_exists($migration_flag_file) ? trim((string)@file_get_contents($migration_flag_file)) : '';
        if ($current_mig_sig !== $expected_mig_sig) {
            $migrator_path = __DIR__ . '/auto_migrator.php';
            if (file_exists($migrator_path)) {
                try {
                    require_once $migrator_path;
                    if (function_exists('auto_initialize_database')) {
                        auto_initialize_database($conn);
                        @file_put_contents($migration_flag_file, $expected_mig_sig);
                    }
                } catch (Throwable $e) {
                    error_log('auto_migrator failed: ' . $e->getMessage());
                }
            } else {
                error_log('auto_migrator.php not found at ' . $migrator_path);
            }
        }
    }
} else {
    // Database connection failed gracefully
    $db_connected = false;
    $conn = null;

    if (!$is_setup_script) {
        $is_action_script = (
            strpos($_SERVER['SCRIPT_NAME'] ?? '', '_actions.php') !== false ||
            ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ||
            (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        );

        if ($is_action_script) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            $setupUrl = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/auth/') !== false || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/shared/') !== false)
                ? '../shared/setup.php'
                : 'app/shared/setup.php';
            $env_hint = $is_railway 
                ? "Please link your Railway MySQL service variables (e.g. reference \${{MySQL.MYSQL_URL}} or MYSQLHOST/MYSQLUSER/MYSQLPASSWORD with DB_NAME=sms_db), or <a href=\"{$setupUrl}\" style=\"color:#2563eb;text-decoration:underline;font-weight:bold;\">click here to configure database</a>."
                : "Please verify DB_HOST, DB_USER, DB_PASS, and DB_NAME environment variables, or <a href=\"{$setupUrl}\" style=\"color:#2563eb;text-decoration:underline;font-weight:bold;\">configure via Setup</a>.";

            echo json_encode([
                'success' => false,
                'message' => "Database connection unavailable to {$host}:{$port} (" . ($db_error ?: 'Connection failed') . "). {$env_hint}"
            ]);
            exit;
        }
    }
}

/**
 * Helper to get a dedicated connection to an organization's isolated database.
 * Accepts club ID or club code (e.g. 1, 'CSSEC', 'RCYC-BCP').
 */
if (!function_exists('getOrgDb')) {
    function getOrgDb(string|int $club_identifier): ?mysqli {
        require_once __DIR__ . '/org_db_manager.php';
        return get_org_db_connection($club_identifier);
    }
}
