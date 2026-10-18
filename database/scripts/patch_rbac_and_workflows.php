<?php
// ============================================================
//  PATCH_RBAC_AND_WORKFLOWS.PHP
//  Migrates RBAC tables, workflow columns, and seeds permissions
// ============================================================
require_once __DIR__ . '/../../app/shared/db.php';

if (!$db_connected || !$conn) {
    die("Database connection failed: " . ($db_error ?? 'Unknown error') . PHP_EOL);
}

echo "Applying RBAC & Workflow Schema Migration..." . PHP_EOL;

// 1. Execute 12_rbac_and_master_data.sql
$sqlFile = __DIR__ . '/../modules/12_rbac_and_master_data.sql';
if (file_exists($sqlFile)) {
    $sql = file_get_contents($sqlFile);
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        echo "  [OK] RBAC tables created and permissions seeded." . PHP_EOL;
    } else {
        echo "  [ERR] Failed to execute $sqlFile: " . $conn->error . PHP_EOL;
    }
}

// 2. Add columns to budget_requests if missing
$budget_cols = [
    'recommended_amount'     => "DECIMAL(10,2) DEFAULT NULL AFTER amount",
    'final_approved_amount'  => "DECIMAL(10,2) DEFAULT NULL AFTER recommended_amount",
    'disbursed_at'           => "TIMESTAMP NULL DEFAULT NULL AFTER notes",
    'disbursement_reference' => "VARCHAR(100) DEFAULT NULL AFTER disbursed_at",
    'disbursed_by'           => "INT(10) UNSIGNED DEFAULT NULL AFTER disbursement_reference",
    'line_items'             => "JSON DEFAULT NULL AFTER description"
];

foreach ($budget_cols as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM budget_requests LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $alterSql = "ALTER TABLE budget_requests ADD COLUMN `$col` $def";
        if ($conn->query($alterSql)) {
            echo "  [OK] Added column `$col` to budget_requests." . PHP_EOL;
        } else {
            echo "  [ERR] Failed adding column `$col`: " . $conn->error . PHP_EOL;
        }
    }
}

// 3. Add columns to attendance_logs if missing
$att_cols = [
    'override_reason' => "TEXT DEFAULT NULL AFTER logged_by",
    'status'          => "VARCHAR(50) NOT NULL DEFAULT 'Valid' AFTER override_reason"
];

foreach ($att_cols as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM attendance_logs LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $alterSql = "ALTER TABLE attendance_logs ADD COLUMN `$col` $def";
        if ($conn->query($alterSql)) {
            echo "  [OK] Added column `$col` to attendance_logs." . PHP_EOL;
        } else {
            echo "  [ERR] Failed adding column `$col`: " . $conn->error . PHP_EOL;
        }
    }
}

echo "RBAC & Workflow migration completed successfully!" . PHP_EOL;
