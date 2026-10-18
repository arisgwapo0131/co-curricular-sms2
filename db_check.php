<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$env = [];
foreach (['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DATABASE_URL', 'MYSQL_URL', 'MYSQL_HOST'] as $k) {
    $env[$k] = getenv($k) !== false ? (strpos($k, 'PASS') !== false ? '***' : getenv($k)) : null;
}

$candidates = [
    ['host' => getenv('DB_HOST') ?: 'mariadb-u4q86rul.internal', 'port' => (int)(getenv('DB_PORT') ?: 3306), 'user' => getenv('DB_USER') ?: 'hf_b9q1pogqoz', 'pass' => getenv('DB_PASS') ?: '9K8GvRhxAWioQQGE1m3iyXitghmBpZFA', 'db' => getenv('DB_NAME') ?: 'sms_db'],
    ['host' => 'mariadb-u4q86rul.internal', 'port' => 3306, 'user' => 'hf_b9q1pogqoz', 'pass' => '9K8GvRhxAWioQQGE1m3iyXitghmBpZFA', 'db' => 'sms_db'],
    ['host' => 'mariadb-u4q86rul.internal', 'port' => 3306, 'user' => 'hf_b9q1pogqoz', 'pass' => '9K8GvRhxAWioQQGE1m3iyXitghmBpZFA', 'db' => 'hf_db_u4q86rul'],
    ['host' => 'mariadb-zm6yg1bc.internal', 'port' => 3306, 'user' => 'hf_egibt4ecge', 'pass' => '9K8GvRhxAWioQQGE1m3iyXitghmBpZFA', 'db' => 'sms_db'],
    ['host' => 'mariadb-zm6yg1bc.internal', 'port' => 3306, 'user' => 'hf_egibt4ecge', 'pass' => '9K8GvRhxAWioQQGE1m3iyXitghmBpZFA', 'db' => 'hf_db_zm6yg1bc'],
    ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => '', 'db' => 'sms_db'],
];

$results = [];
foreach ($candidates as $c) {
    $entry = ['host' => $c['host'], 'user' => $c['user'], 'db' => $c['db']];
    try {
        $conn = @new mysqli($c['host'], $c['user'], $c['pass'], $c['db'], (int)$c['port']);
        if ($conn->connect_error) {
            $entry['status'] = 'connect_error: ' . $conn->connect_error;
            // Try without DB
            $c_nodb = @new mysqli($c['host'], $c['user'], $c['pass'], '', (int)$c['port']);
            if ($c_nodb && !$c_nodb->connect_error) {
                $entry['status_no_db'] = 'CONNECTED (no DB)';
                $dbs = [];
                $q = $c_nodb->query("SHOW DATABASES");
                if ($q) {
                    while ($r = $q->fetch_row()) $dbs[] = $r[0];
                }
                $entry['available_databases'] = $dbs;
                $c_nodb->close();
            }
        } else {
            $entry['status'] = 'CONNECTED';
            $t = $conn->query("SHOW TABLES");
            $tables = [];
            if ($t) {
                while ($r = $t->fetch_row()) $tables[] = $r[0];
            }
            $entry['tables'] = $tables;
            
            // Check users table count
            if (in_array('users', $tables)) {
                $u = $conn->query("SELECT id, username, role FROM users LIMIT 10");
                $users = [];
                if ($u) {
                    while ($ur = $u->fetch_assoc()) $users[] = $ur;
                }
                $entry['users'] = $users;
            }
            $conn->close();
        }
    } catch (Throwable $e) {
        $entry['status'] = 'exception: ' . $e->getMessage();
    }
    $results[] = $entry;
}

echo json_encode(['env' => $env, 'connections' => $results], JSON_PRETTY_PRINT);
