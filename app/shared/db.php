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

// Robust environment helper checking getenv, $_ENV, and $_SERVER
if (!function_exists('bcp_get_env')) {
    function bcp_get_env(string $key, ?string $default = null): ?string {
        $val = getenv($key);
        if ($val !== false && $val !== null && $val !== '') {
            return (string)$val;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }
        return $default;
    }
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

// 2. Parse DATABASE_URL / MYSQL_URL / MYSQL_PRIVATE_URL / MYSQL_PUBLIC_URL if provided by Railway or cloud platforms
$db_url = bcp_get_env('MYSQL_URL')
    ?: (bcp_get_env('MYSQL_PRIVATE_URL')
    ?: (bcp_get_env('MYSQL_PUBLIC_URL')
    ?: (bcp_get_env('DATABASE_URL')
    ?: (bcp_get_env('DATABASE_PRIVATE_URL')
    ?: (bcp_get_env('DATABASE_PUBLIC_URL')
    ?: (bcp_get_env('CLEARDB_DATABASE_URL')
    ?: bcp_get_env('JAWSDB_URL')))))));

// Detect Railway environment
$is_railway = (bool)(
    bcp_get_env('RAILWAY_ENVIRONMENT') ||
    bcp_get_env('RAILWAY_ENVIRONMENT_ID') ||
    bcp_get_env('RAILWAY_ENVIRONMENT_NAME') ||
    bcp_get_env('RAILWAY_PROJECT_ID') ||
    bcp_get_env('RAILWAY_SERVICE_NAME') ||
    bcp_get_env('RAILWAY_SERVICE_ID') ||
    bcp_get_env('RAILWAY_PUBLIC_DOMAIN') ||
    bcp_get_env('RAILWAY_STATIC_URL') ||
    bcp_get_env('RAILWAY_DEPLOYMENT_ID') ||
    bcp_get_env('RAILWAY_GIT_COMMIT_SHA') ||
    (bcp_get_env('MYSQLHOST') && strpos(bcp_get_env('MYSQLHOST'), 'railway') !== false) ||
    ($db_url && strpos($db_url, 'railway') !== false)
);

// 3. Normalize all database environment variables & cloud provider aliases (Railway, MariaDB, Docker)
$host = bcp_get_env('MYSQLHOST') ?: (bcp_get_env('DB_HOST') ?: (bcp_get_env('MARIADB_HOST') ?: (bcp_get_env('MYSQL_HOST') ?: (bcp_get_env('DATABASE_HOST') ?: (bcp_get_env('DB_HOSTNAME') ?: '127.0.0.1')))));
$port = (int)(bcp_get_env('MYSQLPORT') ?: (bcp_get_env('DB_PORT') ?: (bcp_get_env('MARIADB_PORT') ?: (bcp_get_env('MYSQL_PORT') ?: (bcp_get_env('DATABASE_PORT') ?: 3306)))));
$user = bcp_get_env('MYSQLUSER') ?: (bcp_get_env('DB_USER') ?: (bcp_get_env('MARIADB_USER') ?: (bcp_get_env('DB_USERNAME') ?: (bcp_get_env('MYSQL_USER') ?: (bcp_get_env('DATABASE_USER') ?: 'root')))));
$pass = bcp_get_env('MYSQLPASSWORD');
if ($pass === null || $pass === '') {
    $pass = bcp_get_env('MYSQL_ROOT_PASSWORD');
}
if ($pass === null || $pass === '') {
    $pass = bcp_get_env('DB_PASS');
}
if ($pass === null || $pass === '') {
    $pass = bcp_get_env('MARIADB_PASSWORD') ?: (bcp_get_env('DB_PASSWORD') ?: (bcp_get_env('MYSQL_PASSWORD') ?: (bcp_get_env('DATABASE_PASSWORD') ?: '')));
}

$cloud_db = bcp_get_env('MYSQLDATABASE') ?: (bcp_get_env('MARIADB_DATABASE') ?: (bcp_get_env('MYSQL_DATABASE') ?: ''));
$explicit_db = bcp_get_env('DB_NAME') ?: (bcp_get_env('DB_DATABASE') ?: '');

// Directly assign credentials from parsed URL if present
if ($db_url && strpos($db_url, '://') !== false) {
    $parsed_url = parse_url($db_url);
    if ($parsed_url) {
        if (!empty($parsed_url['host'])) {
            $host = $parsed_url['host'];
            putenv('DB_HOST=' . $host);
            $_ENV['DB_HOST'] = $host;
            $_SERVER['DB_HOST'] = $host;
        }
        if (!empty($parsed_url['port'])) {
            $port = (int)$parsed_url['port'];
            putenv('DB_PORT=' . (string)$port);
            $_ENV['DB_PORT'] = (string)$port;
            $_SERVER['DB_PORT'] = (string)$port;
        }
        if (!empty($parsed_url['user'])) {
            $user = rawurldecode($parsed_url['user']);
            putenv('DB_USER=' . $user);
            $_ENV['DB_USER'] = $user;
            $_SERVER['DB_USER'] = $user;
        }
        if (isset($parsed_url['pass'])) {
            $pass = rawurldecode($parsed_url['pass']);
            putenv('DB_PASS=' . $pass);
            $_ENV['DB_PASS'] = $pass;
            $_SERVER['DB_PASS'] = $pass;
        }
        if (!empty($parsed_url['path'])) {
            $d_dec = ltrim($parsed_url['path'], '/');
            if (!empty($d_dec) && $d_dec !== 'test') {
                $cloud_db = $d_dec;
                putenv('DB_NAME=' . $d_dec);
                $_ENV['DB_NAME'] = $d_dec;
                $_SERVER['DB_NAME'] = $d_dec;
            }
        }
    }
}

// Detect if explicit Railway / Cloud DB environment variables are present
$has_cloud_env = (bool)(
    $is_railway ||
    bcp_get_env('MYSQL_URL') ||
    bcp_get_env('MYSQL_PUBLIC_URL') ||
    bcp_get_env('DATABASE_URL') ||
    bcp_get_env('MYSQLHOST') ||
    bcp_get_env('RAILWAY_ENVIRONMENT') ||
    bcp_get_env('RAILWAY_PROJECT_ID') ||
    (bcp_get_env('DB_HOST') && !in_array(bcp_get_env('DB_HOST'), ['127.0.0.1', 'localhost'], true))
);

if ($has_cloud_env && !empty($cloud_db)) {
    $db = $cloud_db;
} elseif (!empty($explicit_db)) {
    $db = $explicit_db;
} else {
    $db = $cloud_db ?: (bcp_get_env('DATABASE_NAME') ?: 'sms_db');
    if (empty($db) || $db === 'test') {
        $db = 'sms_db';
    }
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

// 4. Ultra-Fast Resilient Connection Engine with Host Caching & Smart Prioritization
$cache_file = __DIR__ . '/.db_host_cache.json';

// Helper function to test candidate profiles with strict non-blocking timeout & SSL support
if (!function_exists('bcp_probe_db_profile')) {
    function bcp_probe_db_profile(array $p, bool $is_setup = false, int $timeout = 2): ?mysqli {
        if (empty($p['host'])) return null;
        $host = $p['host'];
        $port = (int)($p['port'] ?? 3306);
        $user = $p['user'] ?? '';
        $pass = $p['pass'] ?? '';
        $target_db = $is_setup ? '' : ($p['db'] ?? '');

        try {
            $use_ssl = (
                !empty($p['ssl']) ||
                bcp_get_env('DB_SSL') === 'true' ||
                bcp_get_env('MYSQL_SSL') === 'true'
            );

            // 1. First attempt: standard connection (or explicit SSL if configured)
            $c = mysqli_init();
            if ($c) {
                @$c->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
                if ($use_ssl) {
                    @$c->ssl_set(NULL, NULL, NULL, NULL, NULL);
                    $flags = defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT') ? MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT : (defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 0);
                    $ok = @$c->real_connect($host, $user, $pass, $target_db, $port, NULL, $flags);
                } else {
                    $ok = @$c->real_connect($host, $user, $pass, $target_db, $port);
                }

                if ($ok && !$c->connect_error) {
                    return $c;
                }

                $err = ($c && $c->connect_error) ? $c->connect_error : ($c && $c->connect_errno ? "code " . $c->connect_errno : "connection failed");
                $GLOBALS['__bcp_last_probe_error'] = "{$host}:{$port} -> {$err}";

                // If standard connection failed and host is remote cloud or container, retry with SSL
                if (!$ok && !$use_ssl && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
                    $cssl = mysqli_init();
                    if ($cssl) {
                        @$cssl->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
                        @$cssl->ssl_set(NULL, NULL, NULL, NULL, NULL);
                        $flags = defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT') ? MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT : (defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 0);
                        if (@$cssl->real_connect($host, $user, $pass, $target_db, $port, NULL, $flags) && !$cssl->connect_error) {
                            return $cssl;
                        }
                        if ($cssl && $cssl->connect_error) {
                            $GLOBALS['__bcp_last_probe_error'] = "{$host}:{$port} (SSL) -> " . $cssl->connect_error;
                        }
                    }
                }
            }

            // 2. If target DB didn't connect, try connecting without DB to select/create it
            if (!$is_setup) {
                $c2 = mysqli_init();
                if ($c2) {
                    @$c2->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
                    if ($use_ssl) {
                        @$c2->ssl_set(NULL, NULL, NULL, NULL, NULL);
                        $flags = defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT') ? MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT : 0;
                        $ok2 = @$c2->real_connect($host, $user, $pass, '', $port, NULL, $flags);
                    } else {
                        $ok2 = @$c2->real_connect($host, $user, $pass, '', $port);
                    }

                    // Retry with SSL if standard connect without DB failed
                    if (!$ok2 && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
                        $c2ssl = mysqli_init();
                        if ($c2ssl) {
                            @$c2ssl->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
                            @$c2ssl->ssl_set(NULL, NULL, NULL, NULL, NULL);
                            $flags = defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT') ? MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT : 0;
                            if (@$c2ssl->real_connect($host, $user, $pass, '', $port, NULL, $flags) && !$c2ssl->connect_error) {
                                $c2 = $c2ssl;
                                $ok2 = true;
                            }
                        }
                    }

                    if ($ok2 && !$c2->connect_error) {
                        $dbs = [];
                        $q = @$c2->query("SHOW DATABASES");
                        if ($q) {
                            while ($r = $q->fetch_row()) $dbs[] = $r[0];
                        }
                        $user_dbs = array_diff($dbs, ['information_schema', 'mysql', 'performance_schema', 'sys']);

                        $chosen_db = null;
                        if (!empty($target_db) && in_array($target_db, $dbs, true)) {
                            $chosen_db = $target_db;
                        } elseif (in_array('railway', $dbs, true)) {
                            $chosen_db = 'railway';
                        } elseif (in_array('sms_db', $dbs, true)) {
                            $chosen_db = 'sms_db';
                        } elseif (!empty($user_dbs)) {
                            $chosen_db = reset($user_dbs);
                        }

                        if ($chosen_db && @$c2->select_db($chosen_db)) {
                            $GLOBALS['__bcp_active_discovered_db'] = $chosen_db;
                            return $c2;
                        }

                        if (!empty($target_db)) {
                            @$c2->query("CREATE DATABASE IF NOT EXISTS `" . $target_db . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                            if (@$c2->select_db($target_db)) {
                                return $c2;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $GLOBALS['__bcp_last_probe_error'] = "{$host}:{$port} -> " . $e->getMessage();
        }
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
        $db_targets = array_unique(array_filter([$db, $cloud_db ?? '', 'sms_db', 'railway', 'defaultdb']));

        if ($host && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            foreach ($db_targets as $t_db) {
                $connection_profiles[] = [
                    'host' => $host,
                    'port' => $port,
                    'user' => $user,
                    'pass' => $pass,
                    'db'   => $t_db
                ];
            }
        }

        // Railway private networking DNS hostnames
        $railway_private_hosts = [
            'mysql.railway.internal',
            'mariadb.railway.internal',
            'database.railway.internal',
            'db.railway.internal'
        ];
        foreach ($railway_private_hosts as $rph) {
            foreach ($db_targets as $t_db) {
                $connection_profiles[] = [
                    'host' => $rph,
                    'port' => $port ?: 3306,
                    'user' => $user ?: 'root',
                    'pass' => $pass ?: '',
                    'db'   => $t_db
                ];
            }
        }

        // Also try MYSQL_PUBLIC_URL if internal host is still warming up
        $pub_url = getenv('MYSQL_PUBLIC_URL');
        if ($pub_url && strpos($pub_url, '://') !== false) {
            $pu = parse_url($pub_url);
            if (!empty($pu['host'])) {
                foreach ($db_targets as $t_db) {
                    $connection_profiles[] = [
                        'host' => $pu['host'],
                        'port' => (int)($pu['port'] ?? 3306),
                        'user' => !empty($pu['user']) ? rawurldecode($pu['user']) : $user,
                        'pass' => isset($pu['pass']) ? rawurldecode($pu['pass']) : $pass,
                        'db'   => $t_db
                    ];
                }
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
            'db'   => $db ?: 'sms_db'
        ];
        $connection_profiles[] = [
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'root',
            'pass' => '',
            'db'   => $db ?: 'sms_db'
        ];
    }

    // 3. Configured Environment / .env Variables
    $connection_profiles[] = [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
        'db'   => $db
    ];

    // 4. Docker Compose fallback (only for local Docker, NOT Railway/Cloud)
    if (!$is_railway && !$has_cloud_env && !$is_local_env) {
        $connection_profiles[] = [
            'host' => 'db',
            'port' => 3306,
            'user' => 'sms_user',
            'pass' => 'sms_secure_password',
            'db'   => $db ?: 'sms_db'
        ];
    }

    // 5. Local fallback if not added previously
    if (!$is_local_env && !$is_railway && !$has_cloud_env) {
        $connection_profiles[] = [
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'pass' => '',
            'db'   => $db ?: 'sms_db'
        ];
    }

    // Test candidate profiles
    $probe_timeout = $has_cloud_env ? 5 : 1;
    $probe_errors = [];
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
            $db   = !empty($GLOBALS['__bcp_active_discovered_db']) ? $GLOBALS['__bcp_active_discovered_db'] : $p['db'];

            // Save winning profile to runtime cache for instant subsequent requests
            $p['db'] = $db;
            @file_put_contents($cache_file, json_encode($p, JSON_UNESCAPED_SLASHES));
            break;
        } else {
            $last_err = $GLOBALS['__bcp_last_probe_error'] ?? 'connection failed';
            $probe_errors[] = $last_err;
        }
    }
    if (!$conn) {
        $db_error = implode('; ', array_unique($probe_errors));
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
        // Auto-initialize fresh database per-host/db OR if 'users' table or critical tables are missing
        $migration_flag_file = __DIR__ . '/.migrated';
        $expected_mig_sig = md5($host . ':' . $port . '/' . $db . '_v3');
        $current_mig_sig = file_exists($migration_flag_file) ? trim((string)@file_get_contents($migration_flag_file)) : '';
        $needs_migration = ($current_mig_sig !== $expected_mig_sig);

        if (!$needs_migration) {
            $critical_tables = ['users', 'qr_sessions', 'attendance_scan_attempts', 'volunteer_logs', 'workflow_history'];
            foreach ($critical_tables as $ct) {
                $cChk = @$conn->query("SHOW TABLES LIKE '$ct'");
                if (!$cChk || $cChk->num_rows === 0) {
                    $needs_migration = true;
                    break;
                }
            }
        }

        if ($needs_migration) {
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
            $hint = '';
            if ($is_railway && empty(bcp_get_env('MYSQL_URL')) && empty(bcp_get_env('MYSQLHOST'))) {
                $hint = " Tip: Link your MySQL service in Railway (go to Web Service -> Variables -> Add Reference -> MYSQL_URL).";
            }
            $err_msg = !empty($db_error) ? " ($db_error)" : (!empty($GLOBALS['__bcp_last_probe_error']) ? " (" . $GLOBALS['__bcp_last_probe_error'] . ")" : "");
            echo json_encode([
                'success' => false,
                'message' => "Database service is currently unreachable or initializing." . $err_msg . $hint . " Please check /db_check.php for details."
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
