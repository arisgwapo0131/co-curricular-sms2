<?php
// ============================================================
//  DB_CHECK.PHP — Universal Database Connectivity & Health Diagnostics
//  Tests active database connection, resolves environment profiles,
//  and verifies table integrity for local and cloud deployments.
// ============================================================

$is_cli = (PHP_SAPI === 'cli');
$wants_json = isset($_GET['format']) && $_GET['format'] === 'json' || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

// Suppress automatic error display to keep output clean, capture errors directly
ini_set('display_errors', '0');
error_reporting(E_ALL);

$start_time = microtime(true);
require_once __DIR__ . '/app/shared/db.php';
$duration_ms = round((microtime(true) - $start_time) * 1000, 2);

$status = [
    'connected'     => false,
    'status'        => 'DISCONNECTED',
    'duration_ms'   => $duration_ms,
    'host'          => $host ?? DB_HOST ?? 'unknown',
    'port'          => $port ?? DB_PORT ?? 3306,
    'user'          => $user ?? DB_USER ?? 'unknown',
    'database'      => $db ?? DB_NAME ?? 'unknown',
    'server_info'   => null,
    'charset'       => null,
    'tables_count'  => 0,
    'tables'        => [],
    'key_tables'    => [
        'users'             => false,
        'system_settings'   => false,
        'clubs'             => false,
        'events'            => false,
        'budget_requests'   => false,
        'club_memberships'  => false,
        'audit_logs'        => false,
    ],
    'user_count'    => 0,
    'super_admin'   => false,
    'admin_users'   => [],
    'error'         => null
];

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error && @$conn->ping()) {
    $status['connected'] = true;
    $status['status'] = 'CONNECTED';
    $status['server_info'] = $conn->server_info;
    $status['charset'] = $conn->character_set_name();

    // Query tables
    $tblResult = @$conn->query("SHOW TABLES");
    if ($tblResult) {
        while ($row = $tblResult->fetch_row()) {
            $tableName = $row[0];
            $status['tables'][] = $tableName;
            if (array_key_exists($tableName, $status['key_tables'])) {
                $status['key_tables'][$tableName] = true;
            }
        }
        $status['tables_count'] = count($status['tables']);
    }

    // Check users table
    if ($status['key_tables']['users']) {
        $uRes = @$conn->query("SELECT COUNT(*) FROM users");
        if ($uRes) {
            $status['user_count'] = (int)$uRes->fetch_row()[0];
        }

        $adminRes = @$conn->query("SELECT id, username, email, role, status FROM users WHERE role IN ('super_admin', 'admin', 'ssc') LIMIT 10");
        if ($adminRes) {
            while ($arow = $adminRes->fetch_assoc()) {
                if ($arow['role'] === 'super_admin') {
                    $status['super_admin'] = true;
                }
                $status['admin_users'][] = $arow;
            }
        }
    }
} else {
    $status['error'] = isset($conn) && $conn instanceof mysqli ? $conn->connect_error : ($db_error ?? 'Unable to establish database connection with any candidate profile.');
}

// 1. Output JSON if requested or when in API/diagnostic mode
if ($wants_json || ($is_cli && in_array('--json', $argv ?? []))) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit($status['connected'] ? 0 : 1);
}

// 2. Output CLI text summary if running in terminal
if ($is_cli) {
    echo "============================================================\n";
    echo " BCP Co-Curricular Management System — Database Diagnostics\n";
    echo "============================================================\n";
    if ($status['connected']) {
        echo " Status:          [SUCCESS] CONNECTED\n";
        echo " Host / Port:     {$status['host']}:{$status['port']}\n";
        echo " Database:        {$status['database']}\n";
        echo " User:            {$status['user']}\n";
        echo " Server Version:  {$status['server_info']}\n";
        echo " Character Set:   {$status['charset']}\n";
        echo " Connection Time: {$status['duration_ms']} ms\n";
        echo " Tables Found:    {$status['tables_count']} tables\n";
        echo " Users Found:     {$status['user_count']} accounts (Super Admin: " . ($status['super_admin'] ? 'YES' : 'NO') . ")\n";
        echo " Key Tables Check:\n";
        foreach ($status['key_tables'] as $tbl => $found) {
            echo "   - $tbl: " . ($found ? '[OK]' : '[MISSING]') . "\n";
        }
    } else {
        echo " Status:          [FAILED] DISCONNECTED\n";
        echo " Error:           {$status['error']}\n";
        echo " Attempted Host:  {$status['host']}:{$status['port']}\n";
        echo " Attempted DB:    {$status['database']}\n";
    }
    echo "============================================================\n";
    exit($status['connected'] ? 0 : 1);
}

// 3. Web UI HTML Display for browser visits
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Database Health Diagnostics — BCP Co-Curricular SMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: #0f172a;
      color: #f8fafc;
      margin: 0;
      padding: 30px 20px;
      display: flex;
      justify-content: center;
    }
    .card {
      background: #1e293b;
      border: 1px solid #334155;
      border-radius: 16px;
      max-width: 780px;
      width: 100%;
      padding: 32px;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
    }
    .badge {
      display: inline-block;
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .badge-success { background: #065f46; color: #34d399; }
    .badge-danger { background: #7f1d1d; color: #f87171; }
    h1 { margin-top: 0; font-size: 1.5rem; display: flex; align-items: center; justify-content: space-between; }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin: 24px 0;
    }
    .item {
      background: #0f172a;
      padding: 14px 18px;
      border-radius: 10px;
      border: 1px solid #334155;
    }
    .item-label { font-size: 0.75rem; text-transform: uppercase; color: #94a3b8; font-weight: 600; margin-bottom: 4px; }
    .item-value { font-size: 1.05rem; font-weight: 700; color: #ffffff; }
    .check-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; margin: 16px 0; }
    .check-item { background: #0f172a; padding: 8px 12px; border-radius: 8px; font-size: 0.85rem; border: 1px solid #334155; }
    .ok { color: #34d399; font-weight: 700; }
    .missing { color: #f87171; font-weight: 700; }
    .btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; margin-top: 20px; }
    .btn:hover { background: #1d4ed8; }
  </style>
</head>
<body>
  <div class="card">
    <h1>
      <span>Database Health Diagnostics</span>
      <?php if ($status['connected']): ?>
        <span class="badge badge-success">&#10003; Connected</span>
      <?php else: ?>
        <span class="badge badge-danger">&#10007; Disconnected</span>
      <?php endif; ?>
    </h1>
    <p style="color:#94a3b8; margin-top: -6px;">Bestlink College of the Philippines — Co-Curricular SMS</p>

    <div class="grid">
      <div class="item">
        <div class="item-label">Active Host &amp; Port</div>
        <div class="item-value"><?= htmlspecialchars($status['host'] . ':' . $status['port']) ?></div>
      </div>
      <div class="item">
        <div class="item-label">Database Name</div>
        <div class="item-value"><?= htmlspecialchars($status['database']) ?></div>
      </div>
      <div class="item">
        <div class="item-label">Database User</div>
        <div class="item-value"><?= htmlspecialchars($status['user']) ?></div>
      </div>
      <div class="item">
        <div class="item-label">Response Time</div>
        <div class="item-value"><?= htmlspecialchars($status['duration_ms']) ?> ms</div>
      </div>
      <div class="item">
        <div class="item-label">Tables Count</div>
        <div class="item-value"><?= htmlspecialchars($status['tables_count']) ?> tables</div>
      </div>
      <div class="item">
        <div class="item-label">Accounts Registered</div>
        <div class="item-value"><?= htmlspecialchars($status['user_count']) ?> accounts</div>
      </div>
    </div>

    <?php if ($status['connected']): ?>
      <h3 style="font-size:1rem; margin-bottom:8px; color:#cbd5e1;">Key Architectural Tables:</h3>
      <div class="check-list">
        <?php foreach ($status['key_tables'] as $tbl => $found): ?>
          <div class="check-item">
            <?= htmlspecialchars($tbl) ?>: <?= $found ? '<span class="ok">&#10003; OK</span>' : '<span class="missing">&#10007; Missing</span>' ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:20px;">
        <a href="app/auth/signin.php" class="btn">Proceed to Sign In &rarr;</a>
        <a href="db_check.php?format=json" class="btn" style="background:#475569; margin-left:8px;">View Raw JSON</a>
      </div>
    <?php else: ?>
      <div style="background:#450a0a; border:1px solid #991b1b; padding:16px; border-radius:10px; margin-top:20px;">
        <strong style="color:#f87171;">Connection Failed:</strong>
        <p style="color:#fecaca; margin:8px 0 0 0;"><?= htmlspecialchars($status['error']) ?></p>
      </div>
      <div style="margin-top:20px;">
        <a href="app/shared/setup.php" class="btn" style="background:#d97706;">Run System Setup &rarr;</a>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
