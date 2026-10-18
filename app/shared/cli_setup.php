<?php
/**
 * CLI_SETUP.PHP — Automated Headless CLI Setup & Migration Runner
 * Designed for Docker, HostForge, Cloud Container Deployments & CI/CD
 */

if (php_sapi_name() !== 'cli' && !empty($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    die("This script can only be executed via the command line (CLI).\n");
}

echo "====================================================\n";
echo "  BCP Co-Curricular System — Automated DB Initializer\n";
echo "====================================================\n";

$configFile = __DIR__ . '/db.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "❌ Critical: db.php configuration file not found.\n");
    exit(1);
}

// 1. Load environment and database config
require_once $configFile;
require_once __DIR__ . '/org_db_manager.php';
require_once __DIR__ . '/setup.php';

$host = getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1'));
$port = (int)(getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3306)));
$user = getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: (defined('DB_USER') ? DB_USER : 'root')));
$pass = getenv('MYSQLPASSWORD');
if ($pass === false || $pass === null) {
    $pass = getenv('DB_PASS');
}
if ($pass === false || $pass === null || $pass === '') {
    $pass = getenv('DB_PASSWORD') ?: (defined('DB_PASS') ? DB_PASS : '');
}
$db   = getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: (defined('DB_NAME') ? DB_NAME : 'sms_db')));

echo "🔍 Target Database Host: {$host}:{$port}\n";
echo "🔍 Target Database Name: {$db}\n";
echo "🔍 Connecting user: {$user}\n";

// 2. Retry loop waiting for MySQL availability
$max_retries = 30;
$retry_count = 0;
$connected = false;
$mysqli = null;

while ($retry_count < $max_retries) {
    $mysqli = @new mysqli($host, $user, $pass, '', $port);
    if (!$mysqli->connect_error) {
        $connected = true;
        break;
    }
    $retry_count++;
    echo "⏳ Waiting for MySQL to become ready... (attempt {$retry_count}/{$max_retries})\n";
    sleep(2);
}

if (!$connected || !$mysqli) {
    fwrite(STDERR, "❌ Fatal: Could not connect to MySQL server at {$host}:{$port} after {$max_retries} attempts.\n");
    fwrite(STDERR, "Error: " . ($mysqli ? $mysqli->connect_error : 'Unknown connection error') . "\n");
    exit(1);
}

echo "✅ Connected to MySQL server successfully.\n";

// 3. Ensure database exists
$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$mysqli->select_db($db);
$mysqli->set_charset('utf8mb4');

// 4. Check if tables exist
$tblChk = $mysqli->query("SHOW TABLES LIKE 'users'");
$needs_full_install = (!$tblChk || $tblChk->num_rows === 0);

$messages = [];
$errors = [];

if ($needs_full_install) {
    echo "📦 Database is empty. Running full system installation...\n";
    
    $masterSql = dirname(__DIR__, 2) . '/database/sms_db.sql';
    if (file_exists($masterSql)) {
        echo "📄 Executing master schema: sms_db.sql...\n";
        executeSQLScript($mysqli, $masterSql, $errors);
    }
    
    $rbacSql = dirname(__DIR__, 2) . '/database/modules/12_rbac_and_master_data.sql';
    if (file_exists($rbacSql)) {
        echo "📄 Executing RBAC schema: 12_rbac_and_master_data.sql...\n";
        executeSQLScript($mysqli, $rbacSql, $errors);
    }
    
    echo "🌱 Seeding system accounts and clubs...\n";
    seedSystemAccountsAndClubs($mysqli, $messages, $errors);
    
    echo "🌱 Seeding system data and inter-club communications...\n";
    seedLiveSystemData($mysqli, $messages, $errors);
    
    echo "🏢 Provisioning organization databases...\n";
    $org_res = provision_all_org_databases($mysqli);
    $org_ready = count(array_filter($org_res, fn($o) => $o['status'] === 'Ready'));
    echo "✅ {$org_ready} Organization databases provisioned.\n";
    
} else {
    echo "🔄 Existing database detected. Synchronizing schemas and patches...\n";
    patchSystemDatabaseSchemas($mysqli, $messages, $errors);
    seedSystemAccountsAndClubs($mysqli, $messages, $errors);
    seedLiveSystemData($mysqli, $messages, $errors);
    $org_res = provision_all_org_databases($mysqli);
}

if (!empty($errors)) {
    echo "⚠️ Setup encountered some non-fatal warnings:\n";
    foreach ($errors as $err) {
        echo "   - $err\n";
    }
}

echo "✨ System database initialization completed successfully!\n";
exit(0);
