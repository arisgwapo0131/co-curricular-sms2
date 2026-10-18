<?php
// ============================================================
//  ORG_DB_MANAGER.PHP — Dedicated Organization Databases Engine
//  Supports per-organization isolated MySQL databases,
//  categorized table schemas, data synchronization, and SQL exports.
// ============================================================

require_once __DIR__ . '/db.php';

/**
 * Format a sanitized MySQL database name for an organization.
 * e.g., 'CSSEC' -> 'sms_org_cssec', 'RCYC-BCP' -> 'sms_org_rcyc_bcp', 'G.A.L.A.W' -> 'sms_org_galaw'
 */
function get_org_db_name(string $club_code): string {
    $slug = strtolower(trim($club_code));
    $slug = str_replace(['.', ' ', '-'], '_', $slug);
    $slug = preg_replace('/[^a-z0-9_]/', '', $slug);
    $slug = trim(preg_replace('/_+/', '_', $slug), '_');
    if (empty($slug)) {
        $slug = 'unnamed';
    }
    return 'sms_org_' . $slug;
}

/**
 * Open a MySQL connection to an organization's dedicated database.
 */
function get_org_db_connection(string|int $club_identifier): ?mysqli {
    global $conn;
    $db_name = '';

    if (is_numeric($club_identifier)) {
        $cid = (int)$club_identifier;
        try {
            $q = $conn->query("SELECT code FROM clubs WHERE id = $cid LIMIT 1");
            if ($q && $row = $q->fetch_assoc()) {
                $db_name = get_org_db_name($row['code']);
            }
        } catch (Throwable $e) {
            return null;
        }
    } else {
        $db_name = get_org_db_name((string)$club_identifier);
    }

    if (empty($db_name)) {
        return null;
    }

    $org_conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, $db_name, (int)DB_PORT);
    if ($org_conn->connect_error) {
        return null;
    }
    $org_conn->set_charset('utf8mb4');
    return $org_conn;
}

/**
 * Provision a dedicated database and categorized tables for a single organization.
 */
function provision_org_database(mysqli $masterConn, int $club_id): bool {
    $q = $masterConn->query("SELECT * FROM clubs WHERE id = $club_id LIMIT 1");
    if (!$q || !($club = $q->fetch_assoc())) {
        return false;
    }

    $db_name = get_org_db_name($club['code']);

    // 1. Create dedicated database
    if (!$masterConn->query("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        return false;
    }

    $org_conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, $db_name, (int)DB_PORT);
    if ($org_conn->connect_error) {
        return false;
    }
    $org_conn->set_charset('utf8mb4');

    // 2. Create Categorized Organization Tables
    $table_queries = [
        // Organization Profile & Identity
        "CREATE TABLE IF NOT EXISTS `org_profile` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `master_club_id` INT(10) UNSIGNED NOT NULL,
            `code` VARCHAR(50) NOT NULL,
            `name` VARCHAR(150) NOT NULL,
            `category` VARCHAR(50) NOT NULL,
            `sub_category` VARCHAR(150) DEFAULT NULL,
            `description` TEXT DEFAULT NULL,
            `adviser_name` VARCHAR(150) DEFAULT NULL,
            `program` VARCHAR(100) DEFAULT NULL,
            `status` VARCHAR(30) DEFAULT 'Active',
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Roster & Leadership Roles
        "CREATE TABLE IF NOT EXISTS `org_members` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `master_membership_id` INT(10) UNSIGNED NOT NULL,
            `user_id` INT(10) UNSIGNED NOT NULL,
            `student_number` VARCHAR(50) DEFAULT NULL,
            `full_name` VARCHAR(150) NOT NULL,
            `profile_pic` VARCHAR(255) DEFAULT NULL,
            `email` VARCHAR(150) DEFAULT NULL,
            `course` VARCHAR(150) DEFAULT NULL,
            `year_level` VARCHAR(50) DEFAULT NULL,
            `section` VARCHAR(50) DEFAULT NULL,
            `role` VARCHAR(80) NOT NULL DEFAULT 'Member',
            `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
            `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user` (`user_id`),
            KEY `idx_role` (`role`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Events & Activities
        "CREATE TABLE IF NOT EXISTS `org_events` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `master_event_id` INT(10) UNSIGNED NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `event_date` DATETIME NOT NULL,
            `venue` VARCHAR(150) NOT NULL,
            `event_type` VARCHAR(50) DEFAULT 'Club',
            `status` VARCHAR(50) DEFAULT 'Approved',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mevent` (`master_event_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Attendance & QR Code Usages
        "CREATE TABLE IF NOT EXISTS `org_attendance` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `master_attendance_id` INT(10) UNSIGNED DEFAULT NULL,
            `event_id` INT(10) UNSIGNED NOT NULL,
            `event_title` VARCHAR(200) DEFAULT NULL,
            `student_number` VARCHAR(50) DEFAULT NULL,
            `student_name` VARCHAR(150) NOT NULL,
            `method` ENUM('QR', 'RFID', 'Manual', 'QR_SELF') NOT NULL DEFAULT 'QR',
            `check_in` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_event` (`event_id`),
            KEY `idx_method` (`method`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Budget & Finances
        "CREATE TABLE IF NOT EXISTS `org_budgets` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `master_budget_id` INT(10) UNSIGNED NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Pending Adviser',
            `requested_by` VARCHAR(150) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mbudget` (`master_budget_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Verified Achievements & Awards
        "CREATE TABLE IF NOT EXISTS `org_achievements` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `master_achievement_id` INT(10) UNSIGNED NOT NULL,
            `title` VARCHAR(250) NOT NULL,
            `competition` VARCHAR(250) NOT NULL,
            `award_date` DATE NOT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Verified',
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mach` (`master_achievement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Announcements & Broadcasts
        "CREATE TABLE IF NOT EXISTS `org_announcements` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `master_announcement_id` INT(10) UNSIGNED NOT NULL,
            `title` VARCHAR(250) NOT NULL,
            `category` VARCHAR(50) DEFAULT 'General',
            `priority` VARCHAR(30) DEFAULT 'Normal',
            `content` TEXT NOT NULL,
            `target_group` VARCHAR(100) DEFAULT 'All Members',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mann` (`master_announcement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Audit Trail
        "CREATE TABLE IF NOT EXISTS `org_audit_trail` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `action` VARCHAR(100) NOT NULL,
            `detail` TEXT DEFAULT NULL,
            `performed_by` VARCHAR(150) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($table_queries as $sql) {
        $org_conn->query($sql);
    }

    $org_conn->close();
    return true;
}

/**
 * Provision dedicated MySQL databases for ALL accredited organizations.
 */
function provision_all_org_databases(mysqli $masterConn): array {
    $results = [];
    try {
        $q = $masterConn->query("SELECT id, code, name FROM clubs WHERE deleted_at IS NULL ORDER BY code ASC");
        if ($q) {
            while ($c = $q->fetch_assoc()) {
                $cid = (int)$c['id'];
                $db_name = get_org_db_name($c['code']);
                $ok = provision_org_database($masterConn, $cid);
                if ($ok) {
                    sync_org_data_to_dedicated_db($masterConn, $cid);
                }
                $results[$c['code']] = [
                    'id'       => $cid,
                    'name'     => $c['name'],
                    'database' => $db_name,
                    'status'   => $ok ? 'Ready' : 'Failed'
                ];
            }
        }
    } catch (Throwable $e) {
        // clubs table doesn't exist yet
    }
    return $results;
}

/**
 * Synchronize all data from master DB into the dedicated organization database.
 */
function sync_org_data_to_dedicated_db(mysqli $masterConn, int $club_id): bool {
    $q = $masterConn->query("SELECT * FROM clubs WHERE id = $club_id LIMIT 1");
    if (!$q || !($club = $q->fetch_assoc())) {
        return false;
    }

    $org_conn = get_org_db_connection($club['code']);
    if (!$org_conn) {
        return false;
    }

    // 1. Sync org_profile
    $p_stmt = $org_conn->prepare("INSERT INTO `org_profile` (`master_club_id`, `code`, `name`, `category`, `sub_category`, `description`, `adviser_name`, `program`, `status`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `category`=VALUES(`category`), `sub_category`=VALUES(`sub_category`), `description`=VALUES(`description`), `adviser_name`=VALUES(`adviser_name`), `program`=VALUES(`program`), `status`=VALUES(`status`)");
    if ($p_stmt) {
        $p_stmt->bind_param('issssssss', $club['id'], $club['code'], $club['name'], $club['category'], $club['sub_category'], $club['description'], $club['adviser_name'], $club['program'], $club['status']);
        $p_stmt->execute();
        $p_stmt->close();
    }

    // 2. Sync org_members
    $m_q = $masterConn->query("
        SELECT cm.id AS cm_id, cm.user_id, cm.role, cm.status, cm.joined_at,
               u.first_name, u.last_name, u.email, u.profile_pic,
               s.student_number, s.course, s.year_level, s.section
        FROM club_memberships cm
        JOIN users u ON u.id = cm.user_id
        LEFT JOIN students s ON s.user_id = u.id
        WHERE cm.club_id = $club_id
    ");
    $synced_m_ids = [];
    if ($m_q) {
        $m_stmt = $org_conn->prepare("INSERT INTO `org_members` (`master_membership_id`, `user_id`, `student_number`, `full_name`, `profile_pic`, `email`, `course`, `year_level`, `section`, `role`, `status`, `joined_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `role`=VALUES(`role`), `status`=VALUES(`status`), `profile_pic`=VALUES(`profile_pic`), `student_number`=VALUES(`student_number`), `course`=VALUES(`course`), `year_level`=VALUES(`year_level`), `section`=VALUES(`section`)");
        while ($mem = $m_q->fetch_assoc()) {
            $synced_m_ids[] = (int)$mem['cm_id'];
            $fullName = trim($mem['first_name'] . ' ' . $mem['last_name']);
            $m_stmt->bind_param('iissssssssss',
                $mem['cm_id'],
                $mem['user_id'],
                $mem['student_number'],
                $fullName,
                $mem['profile_pic'],
                $mem['email'],
                $mem['course'],
                $mem['year_level'],
                $mem['section'],
                $mem['role'],
                $mem['status'],
                $mem['joined_at']
            );
            $m_stmt->execute();
        }
        $m_stmt->close();
    }

    if (!empty($synced_m_ids)) {
        $org_conn->query("DELETE FROM `org_members` WHERE `master_membership_id` NOT IN (" . implode(',', $synced_m_ids) . ")");
    } else {
        $org_conn->query("TRUNCATE TABLE `org_members`");
    }

    // 3. Sync org_events
    $ev_q = $masterConn->query("SELECT * FROM events WHERE club_id = $club_id AND deleted_at IS NULL");
    $synced_ev_ids = [];
    if ($ev_q) {
        $ev_stmt = $org_conn->prepare("INSERT INTO `org_events` (`master_event_id`, `title`, `description`, `event_date`, `venue`, `event_type`, `status`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `title`=VALUES(`title`), `description`=VALUES(`description`), `event_date`=VALUES(`event_date`), `venue`=VALUES(`venue`), `status`=VALUES(`status`)");
        while ($ev = $ev_q->fetch_assoc()) {
            $synced_ev_ids[] = (int)$ev['id'];
            $ev_stmt->bind_param('isssssss',
                $ev['id'],
                $ev['title'],
                $ev['description'],
                $ev['event_date'],
                $ev['venue'],
                $ev['event_type'],
                $ev['status'],
                $ev['created_at']
            );
            $ev_stmt->execute();
        }
        $ev_stmt->close();
    }
    if (!empty($synced_ev_ids)) {
        $org_conn->query("DELETE FROM `org_events` WHERE `master_event_id` NOT IN (" . implode(',', $synced_ev_ids) . ")");
    } else {
        $org_conn->query("TRUNCATE TABLE `org_events`");
    }

    // 4. Sync org_attendance
    $att_q = $masterConn->query("
        SELECT al.id AS al_id, al.event_id, al.check_in, al.method,
               e.title AS event_title,
               u.first_name, u.last_name, s.student_number
        FROM attendance_logs al
        JOIN events e ON e.id = al.event_id
        JOIN users u ON u.id = al.user_id
        LEFT JOIN students s ON s.user_id = u.id
        WHERE e.club_id = $club_id
    ");
    $synced_att_ids = [];
    if ($att_q) {
        $att_stmt = $org_conn->prepare("INSERT INTO `org_attendance` (`master_attendance_id`, `event_id`, `event_title`, `student_number`, `student_name`, `method`, `check_in`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `method`=VALUES(`method`), `check_in`=VALUES(`check_in`)");
        while ($att = $att_q->fetch_assoc()) {
            $synced_att_ids[] = (int)$att['al_id'];
            $studentName = trim($att['first_name'] . ' ' . $att['last_name']);
            $att_stmt->bind_param('iisssss',
                $att['al_id'],
                $att['event_id'],
                $att['event_title'],
                $att['student_number'],
                $studentName,
                $att['method'],
                $att['check_in']
            );
            $att_stmt->execute();
        }
        $att_stmt->close();
    }
    if (!empty($synced_att_ids)) {
        $org_conn->query("DELETE FROM `org_attendance` WHERE `master_attendance_id` NOT IN (" . implode(',', $synced_att_ids) . ")");
    } else {
        $org_conn->query("TRUNCATE TABLE `org_attendance`");
    }

    // 5. Sync org_budgets
    $b_q = $masterConn->query("
        SELECT br.*, u.first_name, u.last_name
        FROM budget_requests br
        LEFT JOIN users u ON u.id = br.requested_by
        WHERE br.club_id = $club_id AND br.deleted_at IS NULL
    ");
    $synced_b_ids = [];
    if ($b_q) {
        $b_stmt = $org_conn->prepare("INSERT INTO `org_budgets` (`master_budget_id`, `title`, `description`, `amount`, `status`, `requested_by`, `notes`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `title`=VALUES(`title`), `description`=VALUES(`description`), `amount`=VALUES(`amount`), `status`=VALUES(`status`), `notes`=VALUES(`notes`)");
        while ($b = $b_q->fetch_assoc()) {
            $synced_b_ids[] = (int)$b['id'];
            $reqName = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
            $b_stmt->bind_param('issdssss',
                $b['id'],
                $b['title'],
                $b['description'],
                $b['amount'],
                $b['status'],
                $reqName,
                $b['notes'],
                $b['created_at']
            );
            $b_stmt->execute();
        }
        $b_stmt->close();
    }
    if (!empty($synced_b_ids)) {
        $org_conn->query("DELETE FROM `org_budgets` WHERE `master_budget_id` NOT IN (" . implode(',', $synced_b_ids) . ")");
    } else {
        $org_conn->query("TRUNCATE TABLE `org_budgets`");
    }

    // 6. Sync org_achievements
    $ach_q = $masterConn->query("SELECT * FROM achievements WHERE club_id = $club_id");
    $synced_ach_ids = [];
    if ($ach_q) {
        $ach_stmt = $org_conn->prepare("INSERT INTO `org_achievements` (`master_achievement_id`, `title`, `competition`, `award_date`, `status`, `notes`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `title`=VALUES(`title`), `competition`=VALUES(`competition`), `award_date`=VALUES(`award_date`), `status`=VALUES(`status`), `notes`=VALUES(`notes`)");
        while ($ach = $ach_q->fetch_assoc()) {
            $synced_ach_ids[] = (int)$ach['id'];
            $ach_stmt->bind_param('issssss',
                $ach['id'],
                $ach['title'],
                $ach['competition'],
                $ach['award_date'],
                $ach['status'],
                $ach['notes'],
                $ach['created_at']
            );
            $ach_stmt->execute();
        }
        $ach_stmt->close();
    }
    if (!empty($synced_ach_ids)) {
        $org_conn->query("DELETE FROM `org_achievements` WHERE `master_achievement_id` NOT IN (" . implode(',', $synced_ach_ids) . ")");
    } else {
        $org_conn->query("TRUNCATE TABLE `org_achievements`");
    }

    // 7. Sync org_announcements
    $ann_q = $masterConn->query("SELECT * FROM org_announcements WHERE club_id = $club_id");
    $synced_ann_ids = [];
    if ($ann_q) {
        $ann_stmt = $org_conn->prepare("INSERT INTO `org_announcements` (`master_announcement_id`, `title`, `category`, `priority`, `content`, `target_group`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `title`=VALUES(`title`), `category`=VALUES(`category`), `priority`=VALUES(`priority`), `content`=VALUES(`content`), `target_group`=VALUES(`target_group`)");
        while ($ann = $ann_q->fetch_assoc()) {
            $synced_ann_ids[] = (int)$ann['id'];
            $ann_stmt->bind_param('issssss',
                $ann['id'],
                $ann['title'],
                $ann['category'],
                $ann['priority'],
                $ann['content'],
                $ann['target_group'],
                $ann['created_at']
            );
            $ann_stmt->execute();
        }
        $ann_stmt->close();
    }
    if (!empty($synced_ann_ids)) {
        $org_conn->query("DELETE FROM `org_announcements` WHERE `master_announcement_id` NOT IN (" . implode(',', $synced_ann_ids) . ")");
    } else {
        $org_conn->query("TRUNCATE TABLE `org_announcements`");
    }

    $org_conn->close();
    return true;
}


/**
 * Synchronize all data across all organizations.
 */
function sync_all_org_data(mysqli $masterConn): void {
    try {
        $q = $masterConn->query("SELECT id FROM clubs WHERE deleted_at IS NULL");
        if ($q) {
            while ($row = $q->fetch_assoc()) {
                sync_org_data_to_dedicated_db($masterConn, (int)$row['id']);
            }
        }
    } catch (Throwable $e) {
        // clubs table doesn't exist yet
    }
}

/**
 * Generate a complete SQL export dump of an organization's dedicated database.
 */
function export_org_database_sql(string $club_code): string {
    $db_name = get_org_db_name($club_code);
    $org_conn = get_org_db_connection($club_code);
    if (!$org_conn) {
        return "-- Could not connect to organization database: {$db_name}\n";
    }

    $sql = "-- ============================================================\n";
    $sql .= "-- Dedicated Organization Database Export: `{$db_name}`\n";
    $sql .= "-- Organization: {$club_code}\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    $sql .= "USE `{$db_name}`;\n\n";

    $tables = ['org_profile', 'org_members', 'org_events', 'org_attendance', 'org_budgets', 'org_achievements', 'org_announcements', 'org_audit_trail'];
    foreach ($tables as $tbl) {
        $c_res = $org_conn->query("SHOW CREATE TABLE `{$tbl}`");
        if ($c_res && $c_row = $c_res->fetch_row()) {
            $sql .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
            $sql .= $c_row[1] . ";\n\n";

            $d_res = $org_conn->query("SELECT * FROM `{$tbl}`");
            if ($d_res && $d_res->num_rows > 0) {
                while ($row = $d_res->fetch_assoc()) {
                    $cols = array_map(function($c) { return "`" . $c . "`"; }, array_keys($row));
                    $vals = array_map(function($v) use ($org_conn) {
                        return $v === null ? "NULL" : "'" . $org_conn->real_escape_string($v) . "'";
                    }, array_values($row));
                    $sql .= "INSERT INTO `{$tbl}` (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ");\n";
                }
                $sql .= "\n";
            }
        }
    }

    $org_conn->close();
    return $sql;
}
