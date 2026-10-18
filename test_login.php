<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>SMS Live DB Test</title><style>body{font-family:sans-serif;padding:30px;line-height:1.6;background:#f8fafc;color:#1e293b;} code{background:#e2e8f0;padding:2px 6px;border-radius:4px;} .btn{display:inline-block;background:#2563eb;color:white;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:bold;margin-top:15px;}</style></head><body>";
echo "<h1>🚀 Bestlink SMS — Real-Time Database Connection & Diagnostics</h1>";

$candidates = [
    [
        'name' => 'Railway MySQL Private Host (mysql.railway.internal) - sms_db',
        'host' => 'mysql.railway.internal',
        'port' => (int)(getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: 3306)),
        'user' => getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root'),
        'pass' => getenv('MYSQLPASSWORD') ?: (getenv('DB_PASS') ?: ''),
        'db'   => 'sms_db'
    ],
    [
        'name' => 'Configured Environment Variables (sms_db)',
        'host' => getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: '127.0.0.1'),
        'port' => (int)(getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: 3306)),
        'user' => getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root'),
        'pass' => getenv('MYSQLPASSWORD') ?: (getenv('DB_PASS') ?: ''),
        'db'   => 'sms_db'
    ],
    [
        'name' => 'Railway MariaDB Private Host (mariadb.railway.internal) - sms_db',
        'host' => 'mariadb.railway.internal',
        'port' => 3306,
        'user' => getenv('MYSQLUSER') ?: 'root',
        'pass' => getenv('MYSQLPASSWORD') ?: '',
        'db'   => 'sms_db'
    ],
    [
        'name' => 'Localhost Fallback (127.0.0.1:3306) - sms_db',
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'root',
        'pass' => '',
        'db'   => 'sms_db'
    ]
];

$active_conn = null;
$connected_profile = null;

foreach ($candidates as $p) {
    echo "<h3>🔍 Testing {$p['name']} ({$p['host']}:{$p['port']})</h3>";
    $c = @new mysqli($p['host'], $p['user'], $p['pass'], '', (int)$p['port']);
    if ($c->connect_error) {
        echo "<p style='color:#dc2626;'>❌ Connection failed: " . htmlspecialchars($c->connect_error) . "</p>";
    } else {
        echo "<p style='color:#16a34a;'>✅ Connected to MySQL server successfully!</p>";
        
        $dbs = [];
        $r = $c->query("SHOW DATABASES");
        if ($r) {
            while ($row = $r->fetch_row()) $dbs[] = $row[0];
        }
        echo "<p>Available databases: <code>" . implode(', ', $dbs) . "</code></p>";
        
        // Auto-create sms_db if possible
        @$c->query("CREATE DATABASE IF NOT EXISTS `sms_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $targetDb = in_array('sms_db', $dbs) ? 'sms_db' : ($p['db'] ?? ($dbs[0] ?? 'sms_db'));
        @$c->select_db($targetDb);
        $c->set_charset('utf8mb4');
        
        $active_conn = $c;
        $connected_profile = $p;
        $connected_profile['db'] = $targetDb;
        break;
    }
}

if ($active_conn) {
    echo "<hr><h2>🎯 Active Connected Database: <code>{$connected_profile['db']}</code></h2>";
    
    $tRes = $active_conn->query("SHOW TABLES");
    $tables = [];
    if ($tRes) {
        while ($row = $tRes->fetch_row()) $tables[] = $row[0];
    }
    echo "<p>Tables Found (" . count($tables) . "): <code>" . (empty($tables) ? 'None (Empty Database)' : implode(', ', $tables)) . "</code></p>";
    
    // Full setup if empty
    if (!in_array('users', $tables)) {
        echo "<p style='color:#ea580c;'>📦 Running complete automated schema migration and account seeding...</p>";
        $masterSql = __DIR__ . '/database/sms_db.sql';
        $rbacSql   = __DIR__ . '/database/modules/12_rbac_and_master_data.sql';
        
        require_once __DIR__ . '/app/shared/setup.php';
        $msgs = []; $errs = [];
        if (file_exists($masterSql)) executeSQLScript($active_conn, $masterSql, $errs);
        if (file_exists($rbacSql)) executeSQLScript($active_conn, $rbacSql, $errs);
        seedSystemAccountsAndClubs($active_conn, $msgs, $errs);
        seedLiveSystemData($active_conn, $msgs, $errs);
        echo "<p style='color:#16a34a;'>✅ Schema and default accounts seeded successfully!</p>";
    }
    
    // Verify user accounts
    $uRes = $active_conn->query("SELECT id, username, email, role, password_hash FROM users ORDER BY id ASC LIMIT 10");
    if ($uRes && $uRes->num_rows > 0) {
        echo "<p style='color:#16a34a;'>✅ <strong>Ready to Login Accounts in Database:</strong></p><ul>";
        while ($u = $uRes->fetch_assoc()) {
            echo "<li><strong>{$u['username']}</strong> ({$u['role']}) - <code>{$u['email']}</code></li>";
        }
        echo "</ul>";
        echo "<a href='app/auth/signin.php' class='btn'>👉 Go to Sign In Page</a>";
    }
} else {
    echo "<div style='background:#fee2e2;border:1px solid #f87171;padding:15px;border-radius:8px;color:#991b1b;'>";
    echo "<h3>❌ Cannot Reach MySQL / MariaDB Server</h3>";
    echo "<p>Please ensure your MySQL service in Railway is active, or link your MySQL service variables (e.g. <code>MYSQL_URL</code>) into your web service.</p>";
    echo "</div>";
}

echo "</body></html>";
