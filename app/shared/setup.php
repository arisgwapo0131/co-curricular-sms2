<?php
// ============================================================
//  SETUP.PHP — Co-Curricular Management System Setup & Installer
//  Supports Localhost & Multi-Device Network Deployments
//  URL: http://localhost/sms/app/shared/setup.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$configFile = __DIR__ . '/db.php';
require_once $configFile;
require_once __DIR__ . '/org_db_manager.php';

$messages = [];
$errors   = [];

$lockFile = __DIR__ . '/install.lock';
$has_installed_admin = false;
if (!empty($db_connected) && !empty($conn)) {
    try {
        $tblChk = $conn->query("SHOW TABLES LIKE 'users'");
        if ($tblChk && $tblChk->num_rows > 0) {
            $adminCheck = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
            if ($adminCheck && $adminCheck->num_rows > 0) {
                $has_installed_admin = true;
            }
        }
    } catch (Throwable $e) {
        $has_installed_admin = false;
    }
}
// Only lock if lockfile exists AND an admin user exists in database
$is_locked = file_exists($lockFile) && $has_installed_admin;
$is_admin = !empty($_SESSION['user_id']) && (($_SESSION['role'] ?? '') === 'admin' || ($_SESSION['real_role'] ?? '') === 'admin');

// Helper to run raw SQL safely
function executeSQLScript(mysqli $conn, string $sqlFilePath, array &$errors): bool {
    if (!file_exists($sqlFilePath)) {
        $errors[] = "SQL script not found: $sqlFilePath";
        return false;
    }

    $content = file_get_contents($sqlFilePath);
    if (empty(trim($content))) {
        $errors[] = "SQL script is empty: $sqlFilePath";
        return false;
    }

    // Enable multi-query execution with exception safety for PHP 8.1+
    try {
        if ($conn->multi_query($content)) {
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->more_results() && $conn->next_result());

            if ($conn->errno) {
                $errors[] = "Error executing $sqlFilePath: " . $conn->error;
                return false;
            }
            return true;
        } else {
            $errors[] = "Multi-query failed for $sqlFilePath: " . $conn->error;
            return false;
        }
    } catch (Throwable $e) {
        $errors[] = "Database execution error in $sqlFilePath: " . $e->getMessage();
        return false;
    }
}

// Passwords configurable via environment variables with default fallback
$admin_plain = getenv('ADMIN_DEFAULT_PASSWORD') ?: ($_ENV['ADMIN_DEFAULT_PASSWORD'] ?? 'Bcp@Admin2026!');
$ssc_plain   = getenv('SSC_DEFAULT_PASSWORD')   ?: ($_ENV['SSC_DEFAULT_PASSWORD']   ?? 'Bcp@SSC2026!');
$std_plain   = getenv('STUDENT_DEFAULT_PASSWORD')?: ($_ENV['STUDENT_DEFAULT_PASSWORD'] ?? 'Bcp@Test2026!');
$adv_plain   = getenv('ADVISER_DEFAULT_PASSWORD')?: ($_ENV['ADVISER_DEFAULT_PASSWORD'] ?? 'Bcp@Adviser2026!');

// Helper: Seed Official System Accounts & Accredited Clubs from SYSTEM_ACCOUNTS.md
function seedSystemAccountsAndClubs(mysqli $conn, array &$messages, array &$errors): bool {
    global $super_admin_plain, $admin_plain, $staff_plain, $ssc_plain, $std_plain, $adv_plain;
    $super_admin_plain = $super_admin_plain ?? (getenv('SUPER_ADMIN_DEFAULT_PASSWORD') ?: ($_ENV['SUPER_ADMIN_DEFAULT_PASSWORD'] ?? 'Bcp@SuperAdmin2026!'));
    $admin_plain = $admin_plain ?? (getenv('ADMIN_DEFAULT_PASSWORD') ?: ($_ENV['ADMIN_DEFAULT_PASSWORD'] ?? 'Bcp@Admin2026!'));
    $staff_plain = $staff_plain ?? (getenv('STAFF_DEFAULT_PASSWORD') ?: ($_ENV['STAFF_DEFAULT_PASSWORD'] ?? 'Bcp@Staff2026!'));
    $ssc_plain   = $ssc_plain   ?? (getenv('SSC_DEFAULT_PASSWORD')   ?: ($_ENV['SSC_DEFAULT_PASSWORD']   ?? 'Bcp@SSC2026!'));
    $std_plain   = $std_plain   ?? (getenv('STUDENT_DEFAULT_PASSWORD')?: ($_ENV['STUDENT_DEFAULT_PASSWORD'] ?? 'Bcp@Test2026!'));
    $adv_plain   = $adv_plain   ?? (getenv('ADVISER_DEFAULT_PASSWORD')?: ($_ENV['ADVISER_DEFAULT_PASSWORD'] ?? 'Bcp@Adviser2026!'));

    // Remove legacy generic testing accounts
    $conn->query("DELETE FROM users WHERE username IN ('student', 'adviser', 'ssc', 'admin')");

    // Password hashes based on configurable environment values or defaults
    $super_admin_hash = password_hash($super_admin_plain, PASSWORD_DEFAULT);
    $admin_hash       = password_hash($admin_plain,       PASSWORD_DEFAULT);
    $staff_hash       = password_hash($staff_plain,       PASSWORD_DEFAULT);
    $ssc_hash         = password_hash($ssc_plain,         PASSWORD_DEFAULT);
    $std_hash         = password_hash($std_plain,         PASSWORD_DEFAULT);
    $adv_hash         = password_hash($adv_plain,         PASSWORD_DEFAULT);

    // 1. Central Admin, Super Admin, Staff & SSC
    $admins = [
        ['super.admin', 'pangilinanarisguillersaret@gmail.com', 'Super',  'Administrator', $super_admin_hash, 'super_admin'],
        ['scc.admin',   'pangilinanarisguillersaret@gmail.com', 'System', 'Admin',         $admin_hash,       'admin'],
        ['osa.staff',   'pangilinanarisguillersaret@gmail.com', 'OSA',    'Staff',         $staff_hash,       'staff'],
        ['ssc.officer', 'pangilinanarisguillersaret@gmail.com', 'SSC',    'Officer',       $ssc_hash,         'ssc']
    ];
    $u_stmt = $conn->prepare("INSERT INTO users (username, email, first_name, last_name, password_hash, role) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE email=VALUES(email), first_name=VALUES(first_name), last_name=VALUES(last_name), password_hash=VALUES(password_hash), role=VALUES(role)");
    foreach ($admins as $adm) {
        $u_stmt->bind_param('ssssss', $adm[0], $adm[1], $adm[2], $adm[3], $adm[4], $adm[5]);
        $u_stmt->execute();
    }

    // 2. 16 Official Program Students
    $students_list = [
        ['2024-10001', 'Bachelor of Science in Information Technology', 'Juan', 'Santos', 'bsit.student', 'bsit@student.bcp.edu.ph', '2nd Year', 'IT-2A'],
        ['2024-10002', 'Bachelor of Science in Hospitality Management', 'Maria', 'Cruz', 'bshm.student', 'bshm@student.bcp.edu.ph', '1st Year', 'HM-1B'],
        ['2024-10003', 'Bachelor of Science in Accounting Information System', 'Jose', 'Reyes', 'bsais.student', 'bsais@student.bcp.edu.ph', '3rd Year', 'AIS-3A'],
        ['2024-10004', 'Bachelor of Science in Tourism Management', 'Ana', 'Dela Cruz', 'bstm.student', 'bstm@student.bcp.edu.ph', '2nd Year', 'TM-2C'],
        ['2024-10005', 'Bachelor of Science in Office Administration', 'Carlos', 'Garcia', 'bsoa.student', 'bsoa@student.bcp.edu.ph', '1st Year', 'OA-1A'],
        ['2024-10006', 'Bachelor of Science in Entrepreneurship', 'Liza', 'Ramos', 'bse.student', 'bse@student.bcp.edu.ph', '3rd Year', 'ENT-3B'],
        ['2024-10007', 'Bachelor of Science in Business Administration', 'Ramon', 'Villanueva', 'bsba.student', 'bsba@student.bcp.edu.ph', '2nd Year', 'BA-2A'],
        ['2024-10008', 'Bachelor of Science in Information Science', 'Patricia', 'Aquino', 'bsis.student', 'bsis@student.bcp.edu.ph', '1st Year', 'IS-1A'],
        ['2024-10009', 'Bachelor of Science in Computer Engineering', 'Mark', 'Bautista', 'bscpe.student', 'bscpe@student.bcp.edu.ph', '3rd Year', 'CPE-3A'],
        ['2024-10010', 'Bachelor of Science in Psychology', 'Jenny', 'Navarro', 'bspsych.student', 'bspsych@student.bcp.edu.ph', '2nd Year', 'PSY-2B'],
        ['2024-10011', 'Bachelor of Science in Criminology', 'Rico', 'Fernandez', 'bscrim.student', 'bscrim@student.bcp.edu.ph', '4th Year', 'CRIM-4A'],
        ['2024-10012', 'Bachelor of Science in Physical Education', 'Sheila', 'Santos', 'bspe.student', 'bspe@student.bcp.edu.ph', '2nd Year', 'PE-2A'],
        ['2024-10013', 'Technological and Livelihood Education', 'Angelo', 'Torres', 'tle.student', 'tle@student.bcp.edu.ph', '1st Year', 'TLE-1B'],
        ['2024-10014', 'Bachelor of Science in Elementary Education', 'Claire', 'Mendoza', 'bseled.student', 'bseled@student.bcp.edu.ph', '3rd Year', 'ELED-3A'],
        ['2024-10015', 'Bachelor of Science in Secondary Education', 'Danilo', 'Pascual', 'bsseed.student', 'bsseed@student.bcp.edu.ph', '2nd Year', 'SEED-2C'],
        ['2024-10016', 'Bachelor of Science in Library Information Science', 'Rowena', 'Espinosa', 'bslis.student', 'bslis@student.bcp.edu.ph', '3rd Year', 'LIS-3A']
    ];

    $role_student = 'student';
    foreach ($students_list as [$sno, $course, $fn, $ln, $un, $em, $yr, $sec]) {
        $u_stmt->bind_param('ssssss', $un, $em, $fn, $ln, $std_hash, $role_student);
        $u_stmt->execute();

        $uidRes = $conn->query("SELECT id FROM users WHERE username = '{$un}' LIMIT 1");
        $userId = ($uidRes && $row = $uidRes->fetch_assoc()) ? (int)$row['id'] : null;

        // Sync student profile
        $chk = $conn->query("SELECT id FROM students WHERE student_number = '{$sno}' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $sRow = $chk->fetch_assoc();
            $conn->query("UPDATE students SET user_id = {$userId}, first_name = '" . $conn->real_escape_string($fn) . "', last_name = '" . $conn->real_escape_string($ln) . "', course = '" . $conn->real_escape_string($course) . "', year_level = '" . $conn->real_escape_string($yr) . "', section = '" . $conn->real_escape_string($sec) . "' WHERE id = {$sRow['id']}");
        } else {
            $conn->query("INSERT INTO students (user_id, student_number, first_name, last_name, birthday, course, year_level, section, phone, status) VALUES ({$userId}, '{$sno}', '" . $conn->real_escape_string($fn) . "', '" . $conn->real_escape_string($ln) . "', '2004-01-01', '" . $conn->real_escape_string($course) . "', '" . $conn->real_escape_string($yr) . "', '" . $conn->real_escape_string($sec) . "', '09123456789', 'Active')");
        }
    }

    // 3. 40 Official Faculty Advisers & Clubs
    $advisers_list = [
        ['cssec.adviser',    'cssec@adviser.bcp.edu.ph',    'Alex',   'Reyes',   'CSSEC',   'Computer Science Student Exec Council', 'Academic', 'BSCS'],
        ['acads.adviser',    'acads@adviser.bcp.edu.ph',    'Mark',   'Velo',    'ACADS',   'Association of Computer Eng Driven Students', 'Academic', 'BSCpE'],
        ['aces.adviser',     'aces@adviser.bcp.edu.ph',     'Elena',  'Ramos',   'ACES',    'Association of Computer Engineering Students', 'Academic', 'BSCpE'],
        ['aiss.adviser',     'aiss@adviser.bcp.edu.ph',     'Clara',  'Tan',     'AISS',    'Accounting Info System Society', 'Academic', 'BSAIS'],
        ['bliss.adviser',    'bliss@adviser.bcp.edu.ph',    'Robert', 'Cruz',    'BLISS',   'Bestlink Library & Info Science Society', 'Academic', 'BLIS'],
        ['brave.adviser',    'brave@adviser.bcp.edu.ph',    'Diana',  'Gomez',   'BRAVE',   'Values Education & Accountability Org', 'Academic', 'BSEd'],
        ['cjsu.adviser',     'cjsu@adviser.bcp.edu.ph',     'Jose',   'Mercado', 'CJSU',    'Criminal Justice Student Unit', 'Academic', 'BSCrim'],
        ['eyo.adviser',      'eyo@adviser.bcp.edu.ph',      'Lisa',   'Santos',  'EYO',     'Entrepreyouth Organization', 'Academic', 'BSBA'],
        ['galaw.adviser',    'galaw@adviser.bcp.edu.ph',    'Manuel', 'Cruz',    'G.A.L.A.W', 'Athletes & Leaders Wellness Assoc', 'Sports', 'Institutional'],
        ['gems.adviser',     'gems@adviser.bcp.edu.ph',     'Anna',   'Reyes',   'GEMs',    'Guild of English Majors', 'Academic', 'BSEd'],
        ['gold.adviser',     'gold@adviser.bcp.edu.ph',     'Karen',  'Lim',     'GOLD',    'Guild of Officers to Lead Development', 'Academic', 'Institutional'],
        ['jfinex.adviser',   'jfinex@adviser.bcp.edu.ph',   'Manuel', 'Cruz',    'JFINEX',  'Junior Financial Executives', 'Academic', 'BSBA'],
        ['hrs.adviser',      'hrs@adviser.bcp.edu.ph',      'Alex',   'Reyes',   'HRS',     'Human Resources Society', 'Academic', 'BSBA'],
        ['jma.adviser',      'jma@adviser.bcp.edu.ph',      'Sarah',  'Mercado', 'J.M.A',   'Junior Marketing Association', 'Academic', 'BSBA'],
        ['lakas.adviser',    'lakas@adviser.bcp.edu.ph',    'Clara',  'Tan',     'LAKAS',   'Liga ng Aktibong Kabataan sa Araling Panlipunan', 'Academic', 'BSEd'],
        ['lapis.adviser',    'lapis@adviser.bcp.edu.ph',    'Diana',  'Gomez',   'L.A.P.I.S', 'Leadership Assoc Program & Services', 'Advocacy', 'Institutional'],
        ['libro.adviser',    'libro@adviser.bcp.edu.ph',    'Robert', 'Cruz',    'LIBRO',   'Lucid of Bright & Righteous Officers', 'Advocacy', 'BLIS'],
        ['omega.adviser',    'omega@adviser.bcp.edu.ph',    'Jose',   'Mercado', 'OMEGA',   'Org for Mathematics in Engineering', 'Academic', 'BSCpE'],
        ['psychsoc.adviser', 'psychsoc@adviser.bcp.edu.ph', 'Lisa',   'Santos',  'PsychSoc','Psychology Society', 'Academic', 'BSPsych'],
        ['rsd.adviser',      'rsd@adviser.bcp.edu.ph',      'Anna',   'Reyes',   'RSD',     'Regnum Scientiae Discipulus', 'Academic', 'Institutional'],
        ['sigma.adviser',    'sigma@adviser.bcp.edu.ph',    'Karen',  'Lim',     'SIGMA',   'Guild for Mathematics Majors', 'Academic', 'BSEd'],
        ['techs.adviser',    'techs@adviser.bcp.edu.ph',    'Alex',   'Reyes',   'TECHs',   'Tech, Exploratory & Hospitality Skills', 'Academic', 'BSHM'],
        ['tts.adviser',      'tts@adviser.bcp.edu.ph',      'Sarah',  'Mercado', 'TTS',     'Tourism Student Society', 'Academic', 'BSTM'],
        ['wika.adviser',     'wika@adviser.bcp.edu.ph',     'Clara',  'Tan',     'WIKA',    'Wikang Filipino sa Akademya', 'Cultural', 'BSEd'],
        ['acac.adviser',     'acac@adviser.bcp.edu.ph',     'Sarah',  'Mercado', 'ACAC',    'Association of Cultural Art Club', 'Cultural', 'Institutional'],
        ['cesc.adviser',     'cesc@adviser.bcp.edu.ph',     'Mark',   'Velo',    'CESC',    'Computer Engineering Sports Club', 'Sports', 'BSCpE'],
        ['ebcpct.adviser',   'ebcpct@adviser.bcp.edu.ph',   'Mark',   'Velo',    'EBCPCT',  'Elite BCP Chess Team', 'Sports', 'Institutional'],
        ['rcyc.adviser',     'rcyc@adviser.bcp.edu.ph',     'Elena',  'Cruz',    'RCYC-BCP','Red Cross Youth Council - BCP', 'Advocacy', 'Institutional'],
        ['smc.adviser',      'smc@adviser.bcp.edu.ph',      'Mark',   'Velo',    'SMC',     'Shuttle Master Club', 'Sports', 'Institutional'],
        ['allstar.adviser',  'allstar@adviser.bcp.edu.ph',  'Sarah',  'Mercado', 'ALL STAR','All Star Talent Group', 'Cultural', 'Institutional'],
        ['bforce.adviser',   'bforce@adviser.bcp.edu.ph',   'Sarah',  'Mercado', 'B-FORCE', 'B-Force Hip-Hop Crew', 'Cultural', 'Institutional'],
        ['creative.adviser', 'creative@adviser.bcp.edu.ph', 'Sarah',  'Mercado', 'CREATIVE','Creative Arts Group', 'Cultural', 'Institutional'],
        ['cdc.adviser',      'cdc@adviser.bcp.edu.ph',      'Sarah',  'Mercado', 'CDC',     'Criminology Dance Company', 'Cultural', 'BSCrim'],
        ['dlc.adviser',      'dlc@adviser.bcp.edu.ph',      'Sarah',  'Mercado', 'DLC',     'Drum and Lyre Corporation', 'Cultural', 'Institutional'],
        ['ikatlong.adviser', 'ikatlong@adviser.bcp.edu.ph', 'Sarah',  'Mercado', 'IKATLONG','Ikatlong Lahi Royalties', 'Cultural', 'Institutional'],
        ['image.adviser',    'image@adviser.bcp.edu.ph',    'Sarah',  'Mercado', 'IMAGE',   'Image Alchemy Media Crew', 'Cultural', 'Institutional'],
        ['sikat.adviser',    'sikat@adviser.bcp.edu.ph',    'Sarah',  'Mercado', 'S.I.K.A.T','Theater & Acting Group', 'Cultural', 'Institutional'],
        ['uv.adviser',       'uv@adviser.bcp.edu.ph',       'Sarah',  'Mercado', 'UV',      'Unlimited Voice Choir', 'Cultural', 'Institutional'],
        ['peer.adviser',     'peer@adviser.bcp.edu.ph',     'Elena',  'Cruz',    'PEER',    'Peer Counselor Support Group', 'Advocacy', 'Institutional'],
        ['newslink.adviser', 'newslink@adviser.bcp.edu.ph', 'Elena',  'Cruz',    'NEWSLINK','School Publications', 'Advocacy', 'Institutional'],
        ['bsit.adviser',     'bsit@adviser.bcp.edu.ph',     'Danilo', 'Santos',  'ITS',     'Information Technology Society', 'Academic', 'BSIT']
    ];

    $role_adviser = 'club_adviser';
    $c_stmt = $conn->prepare("INSERT INTO clubs (code, name, category, description, adviser_name, status, program) VALUES (?, ?, ?, ?, ?, 'Active', ?) ON DUPLICATE KEY UPDATE name=VALUES(name), adviser_name=VALUES(adviser_name), status='Active', program=VALUES(program)");

    foreach ($advisers_list as [$un, $em, $fn, $ln, $code, $cname, $cat, $prog]) {
        $u_stmt->bind_param('ssssss', $un, $em, $fn, $ln, $adv_hash, $role_adviser);
        $u_stmt->execute();

        $advFullName = "Prof. {$fn} {$ln}";
        $desc = "Official accredited campus student organization for {$cname}.";
        $c_stmt->bind_param('ssssss', $code, $cname, $cat, $desc, $advFullName, $prog);
        $c_stmt->execute();
    }

    $u_stmt->close();
    $c_stmt->close();

    // Link faculty advisers to their accredited clubs in club_memberships
    $conn->query("
        INSERT INTO club_memberships (club_id, user_id, role, status)
        SELECT c.id, u.id, 'Adviser', 'Active'
        FROM users u
        JOIN clubs c ON (
          UPPER(SUBSTRING_INDEX(u.username, '.', 1)) = UPPER(c.code)
          OR UPPER(SUBSTRING_INDEX(u.username, '.', 1)) = UPPER(REPLACE(c.code, '-', ''))
          OR UPPER(SUBSTRING_INDEX(u.username, '.', 1)) = UPPER(REPLACE(c.code, '.', ''))
          OR UPPER(SUBSTRING_INDEX(u.username, '.', 1)) = UPPER(REPLACE(c.code, ' ', ''))
          OR UPPER(SUBSTRING_INDEX(u.username, '.', 1)) = UPPER(REPLACE(REPLACE(c.code, '.', ''), '-', ''))
          OR (u.username = 'rcyc.adviser' AND c.code = 'RCYC-BCP')
        )
    ");
    // Ensure adviser_user_id column exists in clubs and populate it
    $chkCol = $conn->query("SHOW COLUMNS FROM clubs LIKE 'adviser_user_id'");
    if (!$chkCol || $chkCol->num_rows === 0) {
        $conn->query("ALTER TABLE clubs ADD COLUMN adviser_user_id INT(10) UNSIGNED NULL AFTER adviser_name");
    }
    $conn->query("
        UPDATE clubs c
        JOIN club_memberships cm ON cm.club_id = c.id AND (cm.role = 'Adviser' OR cm.role = 'Club Adviser') AND cm.status = 'Active'
        SET c.adviser_user_id = cm.user_id
        WHERE c.adviser_user_id IS NULL
    ");

    $messages[] = "✨ Official system accounts (Admin, SSC, 16 Program Students, and 40 Faculty Advisers) successfully seeded from SYSTEM_ACCOUNTS.md!";
    return true;
}


// Helper: Automatically Patch and Synchronize System Database Schemas
function patchSystemDatabaseSchemas(mysqli $conn, array &$messages, array &$errors): bool {
    // 0. Users Core Identity & Security Columns
    $user_cols = [
        'status'               => "ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' AFTER `role`",
        'last_login'           => "DATETIME NULL DEFAULT NULL AFTER `profile_pic`",
        'last_password_change' => "DATETIME NULL DEFAULT NULL AFTER `last_login`",
        'last_mfa_verified_at' => "DATETIME NULL DEFAULT NULL AFTER `last_password_change`"
    ];
    foreach ($user_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `users` LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `users` ADD COLUMN `$col` $def");
        }
    }

    // 1. RBAC Tables
    $rbacSql = dirname(__DIR__, 2) . '/database/modules/12_rbac_and_master_data.sql';
    if (file_exists($rbacSql)) {
        $chkRoles = $conn->query("SHOW TABLES LIKE 'roles'");
        if (!$chkRoles || $chkRoles->num_rows === 0) {
            executeSQLScript($conn, $rbacSql, $errors);
        }
    }
    $chkUserRoles = $conn->query("SHOW TABLES LIKE 'user_roles'");
    if (!$chkUserRoles || $chkUserRoles->num_rows === 0) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS `user_roles` (
              `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT(10) UNSIGNED NOT NULL,
              `role_id` INT(10) UNSIGNED NOT NULL,
              `assigned_by` INT(10) UNSIGNED DEFAULT NULL,
              `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_user_role` (`user_id`, `role_id`),
              KEY `idx_ur_user` (`user_id`),
              KEY `idx_ur_role` (`role_id`),
              CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $conn->query("
            INSERT INTO `user_roles` (`user_id`, `role_id`)
            SELECT u.id, r.id 
            FROM `users` u
            JOIN `roles` r ON r.name = u.role
            ON DUPLICATE KEY UPDATE `role_id`=`role_id`
        ");
    }


    // 2. Budget Requests Workflow Columns
    $budget_cols = [
        'line_items'             => "JSON DEFAULT NULL AFTER description",
        'recommended_amount'     => "DECIMAL(10,2) DEFAULT NULL AFTER amount",
        'final_approved_amount'  => "DECIMAL(10,2) DEFAULT NULL AFTER recommended_amount",
        'disbursed_at'           => "TIMESTAMP NULL DEFAULT NULL AFTER notes",
        'disbursement_reference' => "VARCHAR(100) DEFAULT NULL AFTER disbursed_at",
        'disbursed_by'           => "INT(10) UNSIGNED DEFAULT NULL AFTER disbursement_reference"
    ];
    foreach ($budget_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `budget_requests` LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `budget_requests` ADD COLUMN `$col` $def");
        }
    }
    // Update budget_requests status enum
    $conn->query("ALTER TABLE `budget_requests` MODIFY COLUMN `status` ENUM('Pending Adviser', 'Pending SSC', 'Pending Admin', 'Approved', 'Disbursed', 'Rejected', 'Returned') NOT NULL DEFAULT 'Pending Adviser'");

    // 3. Events Workflow & Documentary Requirement Columns
    $event_cols = [
        'event_type'           => "ENUM('Club', 'Institutional') NOT NULL DEFAULT 'Club' AFTER club_id",
        'start_time'           => "TIME DEFAULT '08:00:00' AFTER event_date",
        'end_time'             => "TIME DEFAULT '17:00:00' AFTER start_time",
        'target_course'        => "VARCHAR(255) DEFAULT 'All Courses' AFTER venue",
        'target_org'           => "VARCHAR(255) DEFAULT 'All Organizations' AFTER target_course",
        'expected_attendees'   => "INT(10) UNSIGNED DEFAULT 0 AFTER target_org",
        'requires_budget'      => "TINYINT(1) NOT NULL DEFAULT 1 AFTER expected_attendees",
        'budget_amount'        => "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER requires_budget",
        'attachment'           => "VARCHAR(255) DEFAULT NULL AFTER budget_amount",
        'doc_letter'           => "VARCHAR(255) DEFAULT NULL AFTER attachment",
        'doc_board_resolution' => "VARCHAR(255) DEFAULT NULL AFTER doc_letter",
        'doc_liquidation'      => "VARCHAR(255) DEFAULT NULL AFTER doc_board_resolution",
        'doc_budget_proposal'  => "VARCHAR(255) DEFAULT NULL AFTER doc_liquidation",
        'endorsement_notes'    => "TEXT DEFAULT NULL AFTER created_by",
        'rejection_note'       => "TEXT DEFAULT NULL AFTER endorsement_notes"
    ];
    foreach ($event_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `events` LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `events` ADD COLUMN `$col` $def");
        }
    }
    // Update events status enum
    $conn->query("ALTER TABLE `events` MODIFY COLUMN `status` ENUM('Upcoming', 'Approved', 'Completed', 'Pending SSC', 'Pending Admin', 'Returned', 'Rejected') NOT NULL DEFAULT 'Pending SSC'");

    // 4. Club Memberships Workflow Columns
    $cm_cols = [
        'adviser_review' => "VARCHAR(50) NOT NULL DEFAULT 'Pending Adviser' AFTER letter_endorsement",
        'ssc_review'     => "VARCHAR(50) NOT NULL DEFAULT 'Pending SSC' AFTER adviser_review",
        'review_notes'   => "TEXT DEFAULT NULL AFTER ssc_review"
    ];
    foreach ($cm_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `club_memberships` LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `club_memberships` ADD COLUMN `$col` $def");
        }
    }
    // Update club_memberships status enum
    $conn->query("ALTER TABLE `club_memberships` MODIFY COLUMN `status` ENUM('Active', 'Pending', 'Rejected', 'Returned') NOT NULL DEFAULT 'Pending'");

    // 4b. Club Applications Workflow Columns
    $ca_cols = [
        'student_id_no'      => "VARCHAR(50) DEFAULT NULL AFTER last_name",
        'course'             => "VARCHAR(150) DEFAULT NULL AFTER student_id_no",
        'year_level'         => "VARCHAR(50) DEFAULT NULL AFTER course",
        'phone'              => "VARCHAR(50) DEFAULT NULL AFTER email",
        'sex'                => "VARCHAR(20) DEFAULT NULL AFTER phone",
        'dob'                => "DATE DEFAULT NULL AFTER sex",
        'address'            => "TEXT DEFAULT NULL AFTER dob",
        'motivation'         => "TEXT DEFAULT NULL AFTER address",
        'letter_intent'      => "VARCHAR(255) DEFAULT NULL AFTER motivation",
        'letter_endorsement' => "VARCHAR(255) DEFAULT NULL AFTER letter_intent",
        'rejection_reason'   => "TEXT DEFAULT NULL AFTER status",
        'adviser_status'     => "ENUM('Pending', 'Endorsed', 'Rejected') DEFAULT 'Pending' AFTER rejection_reason",
        'ssc_status'         => "ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending' AFTER adviser_status",
        'adviser_review'     => "VARCHAR(50) DEFAULT 'Pending Adviser' AFTER ssc_status",
        'ssc_review'         => "VARCHAR(50) DEFAULT 'Pending SSC' AFTER adviser_review",
        'review_notes'       => "TEXT DEFAULT NULL AFTER ssc_review",
        'adviser_reviewed_at'=> "DATETIME DEFAULT NULL AFTER reviewed_at",
        'ssc_reviewed_at'    => "DATETIME DEFAULT NULL AFTER adviser_reviewed_at"
    ];
    foreach ($ca_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `club_applications` LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `club_applications` ADD COLUMN `$col` $def");
        }
    }

    // 5. Attendance Logs Columns & Key Adjustments
    $conn->query("ALTER TABLE `attendance_logs` MODIFY COLUMN `user_id` INT(10) UNSIGNED DEFAULT NULL");
    $chkIdx = $conn->query("SHOW INDEX FROM `attendance_logs` WHERE Key_name = 'uq_event_user'");
    if ($chkIdx && $chkIdx->num_rows > 0) {
        $conn->query("ALTER TABLE `attendance_logs` DROP INDEX `uq_event_user`");
        $conn->query("ALTER TABLE `attendance_logs` ADD KEY `idx_event_user` (`event_id`, `user_id`)");
    }

    $att_cols = [
        'override_reason' => "TEXT DEFAULT NULL AFTER logged_by",
        'status'          => "VARCHAR(50) NOT NULL DEFAULT 'Valid' AFTER override_reason"
    ];
    foreach ($att_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `attendance_logs` LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `attendance_logs` ADD COLUMN `$col` $def");
        }
    }

    // 6. Security Monitoring & Audit Trail Columns
    try {
        $conn->query("ALTER TABLE `audit_logs` DROP FOREIGN KEY `fk_audit_user`");
    } catch (Throwable $e) {}
    $conn->query("ALTER TABLE `audit_logs` MODIFY COLUMN `user_id` INT(10) UNSIGNED DEFAULT NULL");
    try {
        $conn->query("ALTER TABLE `audit_logs` ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL");
    } catch (Throwable $e) {}

    $audit_cols = [
        'user_display'      => "VARCHAR(150) DEFAULT NULL AFTER `user_id`",
        'severity'          => "ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'info' AFTER `detail`",
        'resolution_status' => "ENUM('unresolved', 'investigating', 'resolved') NOT NULL DEFAULT 'unresolved' AFTER `severity`",
        'resolved_by'       => "INT(10) UNSIGNED DEFAULT NULL AFTER `resolution_status`",
        'resolution_notes'  => "TEXT DEFAULT NULL AFTER `resolved_by`",
        'resolved_at'       => "TIMESTAMP NULL DEFAULT NULL AFTER `resolution_notes`"
    ];
    foreach ($audit_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `audit_logs` LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `audit_logs` ADD COLUMN `$col` $def");
        }
    }

    // 6b. Notifications Columns
    $notif_cols = [
        'priority'       => "ENUM('Normal', 'Important', 'Urgent') DEFAULT 'Normal' AFTER `type`",
        'reference_type' => "VARCHAR(100) NULL AFTER `priority`",
        'reference_id'   => "INT(10) UNSIGNED NULL AFTER `reference_type`",
        'link'           => "VARCHAR(255) NULL AFTER `reference_id`"
    ];
    foreach ($notif_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM `notifications` LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `notifications` ADD COLUMN `$col` $def");
        }
    }

    // 7. System Settings Master Table & Defaults
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $default_settings = [
        'academic_year'                 => '2025-2026',
        'active_semester'               => '1st Semester',
        'org_categories'                => "Academic\nAdvocacy\nCultural\nSports\nSpecial Interest\nReligious\nCommunity Outreach",
        'allowed_org_types'             => "Departmental Council\nInstitutional Club\nFraternity / Sorority\nSpecial Interest Guild\nStudent Publication\nHonor Society\nSports Varsity Club",
        'notification_templates'        => "Event Approval Notice\nBudget Disbursement Notice\nCouncil Endorsement Notice\nAccount Credentials Reset\nCharter Accreditation Update\nAttendance Check-in Alert",
        'notification_default_priority' => 'Normal',
        'notification_retention_days'   => '90 Days',
        'ai_provider'                   => 'Google Gemini',
        'ai_model'                      => 'gemini-2.0-flash',
        'mfa_enabled'                   => '1',
        'mfa_expiry_minutes'            => '10',
        'mfa_resend_cooldown'           => '60',
        'mfa_allow_dev_preview'         => '1',
        'smtp_host'                     => 'smtp.gmail.com',
        'smtp_port'                     => '587',
        'smtp_user'                     => '',
        'smtp_pass'                     => '',
        'smtp_crypto'                   => 'tls',
        'mail_from_name'                => 'BCP Co-Curricular Management System',
        'mail_from_email'               => 'no-reply@bcp.edu.ph'
    ];
    $s_stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    if ($s_stmt) {
        foreach ($default_settings as $sk => $sv) {
            $s_stmt->bind_param('ss', $sk, $sv);
            $s_stmt->execute();
        }
        $s_stmt->close();
    }

    // 8. Clubs adviser_user_id (Section 5.4 & 20.1)
    $colChk = $conn->query("SHOW COLUMNS FROM clubs LIKE 'adviser_user_id'");
    if ($colChk && $colChk->num_rows === 0) {
        $conn->query("ALTER TABLE clubs ADD COLUMN adviser_user_id INT UNSIGNED NULL AFTER description");
        $conn->query("ALTER TABLE clubs ADD INDEX idx_clubs_adviser_user_id (adviser_user_id)");
    }
    // Link clubs to official Faculty Advisers
    $conn->query("
        UPDATE clubs c
        JOIN users u ON (
            u.role = 'club_adviser' AND (
                u.username = CONCAT(LOWER(REPLACE(REPLACE(REPLACE(c.code, '.', ''), '-', ''), ' ', '')), '.adviser')
                OR u.username = CONCAT(LOWER(c.code), '.adviser')
            )
        )
        SET c.adviser_user_id = u.id
        WHERE c.adviser_user_id IS NULL
    ");

    // 9. Attendance Scan Attempts Table (Section 10.1 & 20.2)
    $conn->query("
        CREATE TABLE IF NOT EXISTS `attendance_scan_attempts` (
          `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
          `event_id` INT UNSIGNED NULL,
          `user_id` INT UNSIGNED NULL,
          `scanner_id` INT UNSIGNED NULL,
          `token_hash` VARCHAR(255) NULL,
          `result` VARCHAR(50) NOT NULL,
          `reason` VARCHAR(255) NULL,
          `scanned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_scan_event` (`event_id`),
          INDEX `idx_scan_user` (`user_id`),
          INDEX `idx_scan_scanner` (`scanner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 9b. QR Sessions Table (Dynamic token rotation and session control)
    $conn->query("
        CREATE TABLE IF NOT EXISTS `qr_sessions` (
          `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
          `event_id` INT UNSIGNED NOT NULL,
          `created_by` INT UNSIGNED NOT NULL,
          `current_token` VARCHAR(64) NOT NULL,
          `token_seed` VARCHAR(64) NULL,
          `status` ENUM('active', 'paused', 'closed', 'terminated') NOT NULL DEFAULT 'active',
          `refresh_seconds` INT UNSIGNED NOT NULL DEFAULT 60,
          `late_after_minutes` INT UNSIGNED NOT NULL DEFAULT 15,
          `opened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `expires_at` DATETIME NULL,
          `closed_at` DATETIME NULL,
          `closed_by` INT UNSIGNED NULL,
          `close_reason` VARCHAR(255) NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_qrs_event` (`event_id`),
          INDEX `idx_qrs_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 10. Workflow History Table (Section 14.2 & 20.3)
    $conn->query("
        CREATE TABLE IF NOT EXISTS `workflow_history` (
          `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
          `module` VARCHAR(80) NOT NULL,
          `record_id` BIGINT NOT NULL,
          `from_status` VARCHAR(50) NULL,
          `to_status` VARCHAR(50) NOT NULL,
          `action` VARCHAR(80) NOT NULL,
          `performed_by` INT UNSIGNED NULL,
          `remarks` TEXT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_workflow_record` (`module`, `record_id`),
          INDEX `idx_workflow_user` (`performed_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 11. Election Voters Table (Section 11.1)
    $conn->query("
        CREATE TABLE IF NOT EXISTS `election_voters` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `election_id` INT NOT NULL,
          `user_id` INT NOT NULL,
          `eligibility_status` VARCHAR(50) DEFAULT 'eligible',
          `voted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uq_election_voter` (`election_id`, `user_id`),
          INDEX `idx_ev_election` (`election_id`),
          INDEX `idx_ev_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 12. MFA Verification Codes Table (Multi-Factor Authentication & OTPs)
    $conn->query("
        CREATE TABLE IF NOT EXISTS `mfa_codes` (
          `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` INT(10) UNSIGNED NOT NULL,
          `email` VARCHAR(150) NOT NULL,
          `code` VARCHAR(10) NOT NULL,
          `purpose` ENUM('login', 'password_reset', 'account_update') NOT NULL DEFAULT 'login',
          `expires_at` DATETIME NOT NULL,
          `is_used` TINYINT(1) NOT NULL DEFAULT 0,
          `attempts` INT NOT NULL DEFAULT 0,
          `max_attempts` INT NOT NULL DEFAULT 5,
          `ip_address` VARCHAR(45) NULL,
          `user_agent` VARCHAR(255) NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_mfa_user_purpose` (`user_id`, `purpose`, `is_used`, `expires_at`),
          KEY `idx_mfa_code` (`code`),
          CONSTRAINT `fk_mfa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 13. Inter-Club Communication & Messenger Messages Table
    $conn->query("
        CREATE TABLE IF NOT EXISTS `chat_messages` (
          `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          `sender_id` INT(10) UNSIGNED NOT NULL,
          `receiver_id` INT(10) UNSIGNED NULL,
          `club_id` INT(10) UNSIGNED NULL,
          `group_id` INT(10) UNSIGNED NULL,
          `message` TEXT NOT NULL,
          `is_read` TINYINT(1) NOT NULL DEFAULT 0,
          `read_at` DATETIME NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_chat_pair` (`sender_id`, `receiver_id`),
          KEY `idx_chat_receiver_read` (`receiver_id`, `is_read`),
          KEY `idx_chat_group` (`group_id`),
          KEY `idx_chat_created` (`created_at`),
          CONSTRAINT `fk_chat_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 14. Inter-Club Multi-Tier Group Chats Table
    $conn->query("
        CREATE TABLE IF NOT EXISTS `chat_groups` (
          `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          `club_id` INT(10) UNSIGNED NULL,
          `group_type` ENUM('club_general', 'club_executive', 'advisers_ssc') NOT NULL,
          `name` VARCHAR(150) NOT NULL,
          `description` VARCHAR(255) NULL,
          `icon` VARCHAR(50) DEFAULT 'fa-users',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_club_group_type` (`club_id`, `group_type`),
          KEY `idx_cg_club` (`club_id`),
          KEY `idx_cg_type` (`group_type`),
          CONSTRAINT `fk_cg_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Ensure columns on existing chat_messages table if already created earlier
    $chkColRecv = $conn->query("SHOW COLUMNS FROM `chat_messages` LIKE 'receiver_id'");
    if ($chkColRecv && $colR = $chkColRecv->fetch_assoc()) {
        if ($colR['Null'] === 'NO') {
            $conn->query("ALTER TABLE `chat_messages` MODIFY COLUMN `receiver_id` INT(10) UNSIGNED NULL");
        }
    }
    $chkColGrp = $conn->query("SHOW COLUMNS FROM `chat_messages` LIKE 'group_id'");
    if ($chkColGrp && $chkColGrp->num_rows === 0) {
        $conn->query("ALTER TABLE `chat_messages` ADD COLUMN `group_id` INT(10) UNSIGNED NULL AFTER `club_id`, ADD KEY `idx_chat_group` (`group_id`)");
    }

    // High-performance composite indexes for instant message retrieval in production
    $chkIdxGrpFlow = $conn->query("SHOW INDEX FROM `chat_messages` WHERE Key_name = 'idx_chat_group_flow'");
    if ($chkIdxGrpFlow && $chkIdxGrpFlow->num_rows === 0) {
        $conn->query("ALTER TABLE `chat_messages` ADD KEY `idx_chat_group_flow` (`group_id`, `created_at`, `id`)");
    }
    $chkIdxDirFlow = $conn->query("SHOW INDEX FROM `chat_messages` WHERE Key_name = 'idx_chat_direct_flow'");
    if ($chkIdxDirFlow && $chkIdxDirFlow->num_rows === 0) {
        $conn->query("ALTER TABLE `chat_messages` ADD KEY `idx_chat_direct_flow` (`receiver_id`, `sender_id`, `created_at`, `id`)");
    }

    // 15. Group Chat Read Tracking Table
    $conn->query("
        CREATE TABLE IF NOT EXISTS `chat_group_reads` (
          `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          `group_id` INT(10) UNSIGNED NOT NULL,
          `user_id` INT(10) UNSIGNED NOT NULL,
          `last_read_message_id` INT(10) UNSIGNED DEFAULT 0,
          `last_read_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_group_user` (`group_id`, `user_id`),
          KEY `idx_cgr_user` (`user_id`),
          CONSTRAINT `fk_cgr_group` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_cgr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $messages[] = "🛠️ Database schemas and multi-stage workflow columns successfully verified and synchronized!";
    return true;
}

// Helper: Seed Live System Data Across All Modules (Elections, Budgets, Events, QR Usages, Achievements, Officers)
function seedLiveSystemData(mysqli $conn, array &$messages, array &$errors): bool {
    // 1. Ensure schemas are up to date
    patchSystemDatabaseSchemas($conn, $messages, $errors);

    // 2. Ensure clubs sub_category and comprehensive descriptions
    $clubs_data = [
        ['CSSEC', 'Academic', 'CS & IT', 'Official executive council of Computer Science and IT students, fostering technical skills, collaborative software engineering, open-source innovation, and industry competitive programming.'],
        ['ACADS', 'Academic', 'Computer Engineering', 'Association dedicated to academic rigor and engineering excellence through peer tutorials, embedded system research, circuit design workshops, and regional technical competitions.'],
        ['ACES', 'Academic', 'Computer Engineering', 'Professional student chapter promoting hardware innovation, robotics, networking infrastructure, and industry certifications for computer engineering students.'],
        ['AISS', 'Academic', 'Accounting & Finance', 'Advances accounting information literacy, enterprise resource planning, and financial technology integration across academic and commercial business applications.'],
        ['BLISS', 'Academic', 'Library & Information Science', 'Fosters modern digital library science, information curation, scholarly database research, and knowledge preservation across BCP faculties.'],
        ['BRAVE', 'Academic', 'Education & Values', 'Champions ethics, character formation, civic responsibility, and values-centered pedagogy among future educators and campus leaders.'],
        ['CJSU', 'Academic', 'Criminal Justice', 'Strengthens criminology students through criminalistics workshops, tactical drills, moot court trials, and community law enforcement partnerships.'],
        ['EYO', 'Academic', 'Business & Entrepreneurship', 'Incubates youth-led commercial startups, sustainable social ventures, and retail product exhibitions with professional mentorship.'],
        ['G.A.L.A.W', 'Academic', 'Physical Education & Wellness', 'Promotes physical literacy, athletic conditioning, sports science education, and holistic campus wellness initiatives.'],
        ['GEMs', 'Academic', 'Language & Humanities', 'Fosters English linguistic excellence, literary appreciation, forensic speech, parliamentary debate, and creative writing.'],
        ['GOLD', 'Academic', 'Leadership & Governance', 'Develops executive leadership, organizational diplomacy, parliamentary procedure, and administrative excellence among campus leaders.'],
        ['JFINEX', 'Academic', 'Accounting & Finance', 'Enhances financial literacy, stock exchange analytics, corporate financial management, and investment principles for business students.'],
        ['HRS', 'Academic', 'Management & Administration', 'Equips office administration and business majors with modern talent management, labor law compliance, and organizational behavior skills.'],
        ['J.M.A', 'Academic', 'Business & Entrepreneurship', 'Specializes in strategic brand marketing, digital media campaigns, market research, and consumer engagement case studies.'],
        ['LAKAS', 'Academic', 'Education & Values', 'Promotes civic engagement, Philippine social history, political awareness, and transformative social studies pedagogy.'],
        ['L.A.P.I.S', 'Academic', 'Leadership & Governance', 'Provides servant leadership training, grassroots outreach missions, disaster relief coordination, and youth volunteerism.'],
        ['LIBRO', 'Academic', 'Library & Information Science', 'Champions reading campaigns, archival preservation, community mobile libraries, and digital book accessibility across campuses.'],
        ['OMEGA', 'Academic', 'Mathematics & Applied Sciences', 'Applies advanced mathematical modeling, calculus, and computational algorithms to solving complex engineering and scientific problems.'],
        ['PsychSoc', 'Academic', 'Social Sciences', 'Advances psychological awareness, mental health advocacy, behavioral research, and peer counseling support across the student community.'],
        ['RSD', 'Academic', 'Mathematics & Applied Sciences', 'Fosters scientific inquiry, experimental laboratory research, STEM innovation, and peer tutoring in biological and physical sciences.'],
        ['SIGMA', 'Academic', 'Mathematics & Applied Sciences', 'Explores pure and applied mathematics, statistics, competition mathematics, and quantitative problem-solving strategies.'],
        ['TECHs', 'Academic', 'Hospitality & Culinary Arts', 'Develops culinary arts mastery, hotel operations proficiency, food service safety, and front-of-house hospitality standards.'],
        ['TTS', 'Academic', 'Tourism & Travel', 'Equips tourism management students with airline ticketing, tour packaging, ecotourism development, and cultural heritage skills.'],
        ['WIKA', 'Academic', 'Language & Humanities', 'Itinataguyod ang pananaliksik, pagpapahalaga, at pagpapaunlad ng wikang Filipino at panitikang pambansa sa antas tersyarya.'],
        ['ACAC', 'Cultural', 'Department Based Talent Group', 'Visual and cultural arts collective representing folk arts, painting, theatrical installations, and cultural exhibitions.'],
        ['CESC', 'Sports', 'Department Based Talent Group', 'Coordinates sports tournaments, e-sports invitationals, and athletic wellness leagues specifically for engineering students.'],
        ['EBCPCT', 'Sports', 'Department Based Talent Group', 'Elite competitive chess team competing in inter-collegiate board championships, rapid chess tourneys, and strategic clinics.'],
        ['RCYC-BCP', 'Advocacy', 'Department Based Talent Group', 'Official Red Cross youth chapter specializing in emergency first aid, blood donation drives, disaster relief, and safety education.'],
        ['SMC', 'Sports', 'Department Based Talent Group', 'Competitive badminton club providing collegiate training, tactical drills, and inter-university tournament participation.'],
        ['ALL STAR', 'Cultural', 'Talent Center', 'BCP premier multi-disciplinary talent troupe specializing in variety stage performances, singing, and modern choreography.'],
        ['B-FORCE', 'Cultural', 'Talent Center', 'Award-winning campus hip-hop dance crew representing BCP in collegiate streetdance battles, choreography showcases, and concert stages.'],
        ['CREATIVE', 'Cultural', 'Talent Center', 'Graphic design, digital illustration, and multimedia arts collective crafting visual branding and creative showcases.'],
        ['CDC', 'Cultural', 'Talent Center', 'Dynamic performing dance troupe from the College of Criminology blending precision drill steps with modern street dance.'],
        ['DLC', 'Cultural', 'Talent Center', 'BCP official marching drum and lyre corporation providing ceremonial music, parade fanfares, and collegiate rally anthems.'],
        ['IKATLONG', 'Cultural', 'Talent Center', 'Cultural arts guild dedicated to celebrating Philippine indigenous heritage, traditional folk dances, and cultural storytelling.'],
        ['IMAGE', 'Cultural', 'Talent Center', 'Student photojournalism, cinematic videography, and digital media production crew documenting campus events and student life.'],
        ['S.I.K.A.T', 'Cultural', 'Talent Center', 'BCP premier theatrical and acting company producing stage dramas, musical plays, monologue workshops, and improvisational theater.'],
        ['UV', 'Cultural', 'Talent Center', 'The official university chorale delivering classical choral arrangements, liturgical hymns, and contemporary vocal harmonies.'],
        ['PEER', 'Advocacy', 'Campus-Wide Independent Bodies', 'Student-led emotional wellness and mental health peer counseling network working under Guidance and Counseling Services.'],
        ['NEWSLINK', 'Advocacy', 'Campus-Wide Independent Bodies', 'The official autonomous student publication of Bestlink College of the Philippines, committed to responsible campus journalism.'],
        ['GAD-CG', 'Advocacy', 'Campus-Wide Independent Bodies', 'Campus core group advancing gender equality, anti-discrimination initiatives, Safe Spaces Act awareness, and women empowerment.']
    ];

    $upd_stmt = $conn->prepare("UPDATE clubs SET category = ?, sub_category = ?, description = ? WHERE code = ?");
    if ($upd_stmt) {
        foreach ($clubs_data as [$code, $cat, $sub, $desc]) {
            $upd_stmt->bind_param('ssss', $cat, $sub, $desc, $code);
            $upd_stmt->execute();
        }
        $upd_stmt->close();
    }

    // 3. Ensure genuine Faculty Advisers are attached to their respective clubs
    $conn->query("DELETE FROM club_memberships WHERE role = 'Adviser'");
    $conn->query("
        INSERT INTO club_memberships (club_id, user_id, role, status, adviser_review, ssc_review)
        SELECT c.id, u.id, 'Adviser', 'Active', 'Endorsed', 'Endorsed'
        FROM clubs c
        JOIN users u ON (u.username = CONCAT(LOWER(REPLACE(REPLACE(REPLACE(c.code, '.', ''), '-', ''), ' ', '')), '.adviser') OR u.username = CONCAT(LOWER(c.code), '.adviser'))
        WHERE u.role = 'club_adviser'
        ON DUPLICATE KEY UPDATE role='Adviser', status='Active', adviser_review='Endorsed', ssc_review='Endorsed'
    ");

    // 3.1 Ensure recognized Student Club Officers are seeded with leadership roles for Inter-Club Communication
    $student_officers = [
        ['bsit.student',    'CSSEC',    'President'],
        ['bscpe.student',   'ACES',     'President'],
        ['bsais.student',   'AISS',     'Vice President'],
        ['bsis.student',    'BLISS',    'President'],
        ['bslis.student',   'BLISS',    'Vice President'],
        ['bscrim.student',  'CJSU',     'President'],
        ['bse.student',     'EYO',      'President'],
        ['bspe.student',    'G.A.L.A.W','President'],
        ['bseled.student',  'GEMs',     'President'],
        ['bsoa.student',    'HRS',      'President'],
        ['bsba.student',    'JFINEX',   'Vice President'],
        ['bsseed.student',  'LAKAS',    'President'],
        ['bspsych.student', 'PsychSoc', 'President'],
        ['bshm.student',    'TECHs',    'Secretary'],
        ['tle.student',     'TECHs',    'Treasurer'],
        ['bstm.student',    'TTS',      'President'],
    ];
    foreach ($student_officers as [$u_uname, $c_code, $pos]) {
        $conn->query("
            INSERT INTO club_memberships (club_id, user_id, role, status, adviser_review, ssc_review)
            SELECT c.id, u.id, '$pos', 'Active', 'Endorsed', 'Endorsed'
            FROM clubs c, users u
            WHERE c.code = '$c_code' AND u.username = '$u_uname'
            ON DUPLICATE KEY UPDATE role = '$pos', status = 'Active', adviser_review = 'Endorsed', ssc_review = 'Endorsed'
        ");
    }

    // 4. Ensure achievements schema exists (Clean Slate — Populated through live user submissions)
    $conn->query("
        CREATE TABLE IF NOT EXISTS `achievements` (
          `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          `club_id` INT(10) UNSIGNED NOT NULL,
          `submitted_by` INT(10) UNSIGNED NOT NULL,
          `title` VARCHAR(250) NOT NULL,
          `competition` VARCHAR(250) NOT NULL,
          `award_date` DATE NOT NULL,
          `proof_file` VARCHAR(300) DEFAULT NULL,
          `status` ENUM('Pending', 'Verified', 'Rejected') NOT NULL DEFAULT 'Pending',
          `verified_by` INT(10) UNSIGNED DEFAULT NULL,
          `notes` TEXT DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_ach_club` (`club_id`),
          KEY `idx_ach_submitter` (`submitted_by`),
          KEY `idx_ach_status` (`status`),
          CONSTRAINT `fk_ach_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_ach_user` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_ach_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 5. Ensure elections, candidates, and votes tables exist
    $conn->query("CREATE TABLE IF NOT EXISTS `elections` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `election_code` VARCHAR(50) UNIQUE NOT NULL,
        `club_id` INT NOT NULL,
        `scope` VARCHAR(50) DEFAULT 'Club',
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT NULL,
        `election_type` VARCHAR(100) DEFAULT 'Student Governance',
        `starts_at` DATETIME NULL,
        `closes_at` DATETIME NULL,
        `status` VARCHAR(50) DEFAULT 'active',
        `eligible_voters` INT DEFAULT 0,
        `positions` TEXT NULL,
        `verified_at` DATETIME NULL,
        `verified_by` INT NULL,
        `audit_notes` TEXT NULL,
        `created_by` INT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->query("CREATE TABLE IF NOT EXISTS `election_candidates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `election_id` INT NOT NULL,
        `user_id` INT NULL,
        `candidate_code` VARCHAR(50) NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `position` VARCHAR(100) NOT NULL,
        `party` VARCHAR(150) NULL,
        `year_level` VARCHAR(50) NULL,
        `program` VARCHAR(50) NULL,
        `gwa` VARCHAR(20) NULL,
        `platform_tag` TEXT NULL,
        `achievements` TEXT NULL,
        `votes_count` INT DEFAULT 0,
        `is_appointed` TINYINT(1) DEFAULT 0,
        `status` VARCHAR(50) DEFAULT 'Active',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->query("CREATE TABLE IF NOT EXISTS `election_votes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `election_id` INT NOT NULL,
        `user_id` INT NULL,
        `ballot_token` VARCHAR(255) NULL,
        `ballot_data` TEXT NULL,
        `votes_json` TEXT NOT NULL,
        `cast_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_vote_election` (`election_id`),
        KEY `idx_ballot_token` (`ballot_token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 8. Notification Templates Table & Defaults
    $conn->query("CREATE TABLE IF NOT EXISTS `notification_templates` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(50) UNIQUE NOT NULL,
        `title` VARCHAR(150) NOT NULL,
        `category` VARCHAR(100) NOT NULL DEFAULT 'System Notice',
        `subject_template` VARCHAR(255) NOT NULL,
        `body_template` TEXT NOT NULL,
        `default_priority` ENUM('Normal', 'Important', 'Urgent') DEFAULT 'Normal',
        `default_target` VARCHAR(100) DEFAULT 'All Campus Users',
        `status` ENUM('Active', 'Archived') DEFAULT 'Active',
        `created_by` INT UNSIGNED NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_tpl_cat` (`category`),
        INDEX `idx_tpl_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $chk_tpl = $conn->query("SELECT COUNT(*) FROM `notification_templates`");
    if ($chk_tpl && (int)$chk_tpl->fetch_row()[0] === 0) {
        $default_tpls = [
            ['EVT_APPRV', 'Event Approval Notice', 'Activity & Event Operations', 'Official Approval: {{event_title}}', 'Greetings {{organizer_name}}, your proposed campus activity {{event_title}} scheduled for {{event_date}} at {{venue}} has received official administrative clearance and calendar placement.', 'Important', 'Club Leaders'],
            ['BUD_DISB', 'Budget Disbursement Notice', 'Financial Administration', 'Fund Release Authorized: Requisition #{{req_id}}', 'Notice to {{club_name}} Executive Board: Funding in the amount of ₱{{amount}} for {{purpose}} has been officially authorized and disbursed.', 'Important', 'Treasurers & Officers'],
            ['SSC_ENDRS', 'Council Endorsement Notice', 'Governance & Accreditation', 'SSC Endorsement Granted: {{proposal_title}}', 'The Supreme Student Council Executive Committee has officially reviewed and endorsed proposal {{proposal_title}} to the Office of Student Affairs.', 'Normal', 'Club Advisers & Leads'],
            ['ACC_RESET', 'Account Credentials Reset', 'Security & Access', 'Temporary Access Pass Issued: {{user_name}}', 'Your account credentials for the BCP Co-Curricular Management Portal have been updated. Temporary login: {{temp_pass}}.', 'Urgent', 'Specific Users'],
            ['CHR_ACCR', 'Charter Accreditation Update', 'Governance & Accreditation', 'Annual Charter Accreditation: {{org_name}}', 'Official resolution regarding {{org_name}} charter accreditation for AY {{academic_year}} has been released by the Office of Student Affairs.', 'Normal', 'Club Presidents & Advisers'],
            ['ATT_ALERT', 'Attendance Check-in Alert', 'Event Attendance & Tracking', 'Attendance Confirmation: {{event_title}}', 'You have been recorded as an attendee for {{event_title}} at {{timestamp}}. Your co-curricular participation ledger has been credited.', 'Normal', 'All Students']
        ];
        $t_stmt = $conn->prepare("INSERT IGNORE INTO `notification_templates` (`code`, `title`, `category`, `subject_template`, `body_template`, `default_priority`, `default_target`, `created_by`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'Active')");
        if ($t_stmt) {
            foreach ($default_tpls as $t) {
                $t_stmt->bind_param('sssssss', $t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $t[6]);
                $t_stmt->execute();
            }
            $t_stmt->close();
        }
    }

    // 9. Seed Authentic Student Applications with Uploaded Documents (Clean seeding if none exist)
    $chk_apps = $conn->query("SELECT COUNT(*) FROM `club_applications`");
    if ($chk_apps && (int)$chk_apps->fetch_row()[0] === 0) {
        $sample_apps = [
            [1, 7, 'Ramon', 'Villanueva', '2024-10007', 'Bachelor of Science in Business Administration', '2nd Year', 'bsba@student.bcp.edu.ph', '09171234567', 'Male', '2004-01-01', 'Quezon City, Metro Manila', 'I am enthusiastic about joining the Computer Science Student Executive Council to contribute my organizational, administrative, and event coordination skills to student life initiatives.', 'intent_7_1790074124_6f2270d3.pdf', 'endorsement_7_1790074489_4608d4be.docx'],
            [3, 2, 'Maria', 'Cruz', '2024-10002', 'Bachelor of Science in Hospitality Management', '1st Year', 'bshm@student.bcp.edu.ph', '09189876543', 'Female', '2005-05-15', 'Novaliches, Quezon City', 'I aspire to be an active member of ACES, supporting inter-disciplinary events and campus hospitality initiatives.', 'intent_7_1790074130_113a6786.pdf', 'intent_7_1790074489_6d53dbc1.docx']
        ];
        $app_stmt = $conn->prepare("INSERT INTO club_applications (club_id, user_id, first_name, last_name, student_id_no, course, year_level, email, phone, sex, dob, address, motivation, letter_intent, letter_endorsement, status, applied_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())");
        $mem_stmt = $conn->prepare("INSERT INTO club_memberships (club_id, user_id, role, status, joined_at, letter_intent, letter_endorsement, adviser_review, ssc_review, review_notes) VALUES (?, ?, 'Member', 'Pending', NOW(), ?, ?, 'Pending Adviser', 'Pending SSC', 'Initial submission awaiting review.') ON DUPLICATE KEY UPDATE status='Pending', letter_intent=VALUES(letter_intent), letter_endorsement=VALUES(letter_endorsement)");
        foreach ($sample_apps as $sa) {
            if ($app_stmt) {
                $app_stmt->bind_param('iisssssssssssss', $sa[0], $sa[1], $sa[2], $sa[3], $sa[4], $sa[5], $sa[6], $sa[7], $sa[8], $sa[9], $sa[10], $sa[11], $sa[12], $sa[13], $sa[14]);
                $app_stmt->execute();
            }
            if ($mem_stmt) {
                $mem_stmt->bind_param('iiss', $sa[0], $sa[1], $sa[13], $sa[14]);
                $mem_stmt->execute();
            }
        }
        if ($app_stmt) $app_stmt->close();
        if ($mem_stmt) $mem_stmt->close();
    }

    // 10. Seed Authentic Events & Calendar Activities (Clean seeding if none exist)
    $chk_evs = $conn->query("SELECT COUNT(*) FROM `events`");
    if ($chk_evs && (int)$chk_evs->fetch_row()[0] === 0) {
        $clubs_res = $conn->query("SELECT id, code FROM clubs ORDER BY id");
        $clubs_map = [];
        while ($r = $clubs_res->fetch_assoc()) {
            $clubs_map[$r['code']] = (int)$r['id'];
        }

        $events_to_seed = [
            // BSIT — Information Technology
            [
                $clubs_map['ITS'] ?? 82, 'Club', 'BSIT',
                'BSIT Cloud Architecture & Cybersecurity Summit 2026',
                'Hands-on conference on cloud infrastructure, penetration testing, defensive networking, and modern cybersecurity practices.',
                '2026-10-15 09:00:00', 'IT Laboratory 1 & Tech Center', 250, 'Approved', 118,
                'Endorsed by Adviser Danilo Santos & SSC: Full computer lab safety clearance provided.'
            ],
            [
                $clubs_map['ITS'] ?? 82, 'Club', 'BSIT',
                'Mobile App & Cross-Platform UI/UX Hackfest',
                'Collegiate application development sprint creating user-centric mobile applications for campus operations.',
                '2026-10-24 08:30:00', 'Advanced Computing Lab 2', 150, 'Approved', 118,
                'Endorsed by Adviser Danilo Santos & SSC: Technical resources approved.'
            ],
            // BSCS — Computer Science
            [
                $clubs_map['CSSEC'] ?? 1, 'Club', 'BSCS',
                'BCP Collegiate Artificial Intelligence & Algorithm Expo',
                'Exposition on machine learning architectures, neural networks, computer vision applications, and algorithmic research.',
                '2026-10-16 09:00:00', 'Main Auditorium', 280, 'Approved', 17,
                'Endorsed by Adviser Alex Reyes & SSC.'
            ],
            [
                $clubs_map['CSSEC'] ?? 1, 'Club', 'BSCS',
                'Open-Source Software & Data Structure Competitive Coding',
                'Timed algorithmic coding battle focused on optimized graph traversals, dynamic programming, and systems design.',
                '2026-10-28 10:00:00', 'Computer Science Laboratory 3', 120, 'Approved', 17,
                'Endorsed by SSC: Server access granted.'
            ],
            // BSCpE — Computer Engineering
            [
                $clubs_map['ACES'] ?? 3, 'Club', 'BSCpE',
                'Robotics, Microcontroller & Embedded Systems Challenge',
                'Autonomous robotics tournament, PCB circuit design display, and hardware-software integration competition.',
                '2026-10-20 09:00:00', 'Engineering Hardware Lab', 180, 'Approved', 19,
                'Endorsed by Adviser Elena Ramos & SSC.'
            ],
            [
                $clubs_map['ACADS'] ?? 2, 'Club', 'BSCpE',
                'IoT Smart Automation & Circuit Prototyping Showcase',
                'Live demonstration of sensor-driven IoT devices for energy monitoring and industrial automation.',
                '2026-11-02 13:00:00', 'Engineering Tech Hub', 160, 'Approved', 18,
                'Endorsed by Adviser Mark Velo & SSC.'
            ],
            // BSAIS — Accounting Info Systems
            [
                $clubs_map['AISS'] ?? 4, 'Club', 'BSAIS',
                'FinTech, Cloud ERP & Digital Audit Solutions Colloquium',
                'Forum on automated financial enterprise systems, blockchain ledgers, and digital auditing frameworks.',
                '2026-10-21 09:30:00', 'Audio Visual Theater 1', 200, 'Approved', 20,
                'Endorsed by Adviser Clara Tan & SSC.'
            ],
            [
                $clubs_map['AISS'] ?? 4, 'Club', 'BSAIS',
                'Automated Tax Filing & Forensic Accounting Workshop',
                'Simulation on data-driven forensic investigation and computerized tax computation tools.',
                '2026-11-04 10:00:00', 'Business Simulation Center', 140, 'Approved', 20,
                'Endorsed by SSC: Software simulation lab reserved.'
            ],
            // BSHM — Hospitality Management
            [
                $clubs_map['TECHs'] ?? 22, 'Club', 'BSHM',
                'Hospitality & Culinary Arts Skills Championship',
                'Live culinary cooking challenge, table setup exhibition, and front-of-house hospitality competitive showcase.',
                '2026-11-06 09:00:00', 'Culinary Arts Pavilion', 220, 'Approved', 38,
                'Endorsed by Adviser Alex Reyes & SSC.'
            ],
            [
                $clubs_map['TECHs'] ?? 22, 'Club', 'BSHM',
                'Hotel Operations & International Mixology Masterclass',
                'Executive masterclass on hotel guest hospitality standards, bar operations, and tourism dining etiquette.',
                '2026-11-18 13:00:00', 'Hospitality Training Suite', 170, 'Approved', 38,
                'Endorsed by SSC: Sanitation protocols verified.'
            ],
            // BSTM — Tourism Management
            [
                $clubs_map['TTS'] ?? 23, 'Club', 'BSTM',
                'Philippine Eco-Tourism & Airline Cabin Operations Summit',
                'Conference on sustainable community tourism management, flight operations, and airport service logistics.',
                '2026-10-23 09:00:00', 'Tourism Mock Airport Center', 240, 'Approved', 39,
                'Endorsed by Adviser Sarah Mercado & SSC.'
            ],
            [
                $clubs_map['TTS'] ?? 23, 'Club', 'BSTM',
                'Global Destination Planning & Cultural Tour Expo',
                'Interactive tourism booth exhibition mapping heritage tours, visa facilitation, and eco-travel packages.',
                '2026-11-10 10:00:00', 'Campus Grand Atrium', 300, 'Approved', 39,
                'Endorsed by SSC: Atrium space allocated.'
            ],
            // BSCrim — Criminology
            [
                $clubs_map['CJSU'] ?? 7, 'Club', 'BSCrim',
                'Forensic Ballistics & Crime Scene Investigation Demonstration',
                'Real-time demonstration of latent fingerprint lifting, forensic ballistics trajectory analysis, and chain of custody.',
                '2026-10-19 08:30:00', 'Criminology Forensic Laboratory', 210, 'Approved', 23,
                'Endorsed by Adviser Jose Mercado & SSC.'
            ],
            [
                $clubs_map['CJSU'] ?? 7, 'Club', 'BSCrim',
                'Law Enforcement Tactical Defense & Crisis Seminar',
                'Workshop on non-lethal defensive tactics, constitutional rights in police procedure, and emergency response.',
                '2026-11-12 13:30:00', 'University Grounds & Range', 250, 'Approved', 23,
                'Endorsed by SSC: Safety officer on duty.'
            ],
            // BSPsych — Psychology
            [
                $clubs_map['PsychSoc'] ?? 19, 'Club', 'BSPsych',
                'Applied Psychometrics & Behavioral Neuroscience Forum',
                'Symposium discussing psychological test development, cognitive behavioral techniques, and neurobiological insights.',
                '2026-10-27 09:00:00', 'Psychology Guidance Pavilion', 190, 'Approved', 35,
                'Endorsed by Adviser Lisa Santos & SSC.'
            ],
            [
                $clubs_map['PsychSoc'] ?? 19, 'Club', 'BSPsych',
                'Youth Mental Health & Peer Counseling Colloquium',
                'Empowering students through psychological first-aid training, emotional resilience seminars, and counseling networks.',
                '2026-11-09 13:30:00', 'Audio Visual Theater 2', 180, 'Approved', 35,
                'Endorsed by SSC: Mental health awareness week.'
            ],
            // BSBA — Business Administration
            [
                $clubs_map['EYO'] ?? 8, 'Club', 'BSBA',
                'Startup Incubation & Young Entrepreneurs Fair',
                'Student business venture exposition featuring student-led commercial stalls, marketing pitches, and product showcases.',
                '2026-11-14 09:00:00', 'Central Quadrangle', 400, 'Approved', 24,
                'Endorsed by Adviser Lisa Santos & SSC.'
            ],
            [
                $clubs_map['JFINEX'] ?? 12, 'Club', 'BSBA',
                'Collegiate Stock Market & Corporate Marketing Summit',
                'Executive panel on capital markets, equity valuation, brand management, and consumer market trends.',
                '2026-11-21 09:30:00', 'Executive Conference Center', 220, 'Approved', 28,
                'Endorsed by Adviser Manuel Cruz & SSC.'
            ],
            // BSEd — Teacher Education
            [
                $clubs_map['GEMs'] ?? 10, 'Club', 'BSEd',
                'Pedagogical Innovation & Digital Teaching Methodologies Forum',
                'Teacher education conference exploring interactive classroom technologies, differentiated learning, and modern assessment.',
                '2026-10-30 09:00:00', 'Education Multi-Purpose Hall', 210, 'Approved', 26,
                'Endorsed by Adviser Anna Reyes & SSC.'
            ],
            [
                $clubs_map['SIGMA'] ?? 21, 'Club', 'BSEd',
                'Language & Mathematics Instructional Materials Festival',
                'Exhibit of student-created gamified learning kits, interactive math boards, and literature teaching modules.',
                '2026-11-16 13:00:00', 'Bulwagang Sibol', 160, 'Approved', 37,
                'Endorsed by Adviser Karen Lim & SSC.'
            ],
            // Institutional
            [
                NULL, 'Institutional', 'All Programs',
                'Campus Leadership Summit & Officer Synergy Forum',
                'Mandatory university-wide leadership development summit for all recognized student organization officers and delegates.',
                '2026-10-14 09:00:00', 'Bulwagang Balagtas', 350, 'Approved', 58,
                'Institutional Council Event: Fully endorsed by SSC.'
            ],
            [
                $clubs_map['ALL STAR'] ?? 30, 'Institutional', 'All Programs',
                'Philippine Cultural Heritage & Folk Dance Showcase',
                'Live performing arts festival presenting traditional Philippine folk dances and indigenous music.',
                '2026-10-29 13:00:00', 'Campus Plaza', 250, 'Approved', 46,
                'Endorsed by SSC: Sound permits verified.'
            ],
            [
                $clubs_map['RCYC-BCP'] ?? 28, 'Institutional', 'All Programs',
                'Red Cross Emergency First Aid & Voluntary Blood Drive',
                'Campus-wide voluntary blood donation drive and certified emergency disaster response workshop.',
                '2026-11-05 08:00:00', 'Health Services Center', 200, 'Approved', 44,
                'Endorsed by SSC: Medical staff arranged.'
            ]
        ];

        $ev_stmt = $conn->prepare("INSERT INTO `events` (`club_id`, `event_type`, `target_course`, `title`, `description`, `event_date`, `venue`, `expected_attendees`, `status`, `created_by`, `endorsement_notes`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($ev_stmt) {
            foreach ($events_to_seed as $ev) {
                $ev_stmt->bind_param('issssssisis', $ev[0], $ev[1], $ev[2], $ev[3], $ev[4], $ev[5], $ev[6], $ev[7], $ev[8], $ev[9], $ev[10]);
                $ev_stmt->execute();
            }
            $ev_stmt->close();
        }

        $conn->query("INSERT IGNORE INTO `event_registrations` (`event_id`, `user_id`, `status`) SELECT id, 1, 'Registered' FROM `events` WHERE `status` = 'Approved' LIMIT 5");
    }

    // 10b. Ensure Event Proposal Documentary Requirements (Letter, Board Resolution, Liquidation, Optional Budget Proposal) are populated
    $ev_up_dir = __DIR__ . '/../uploads/events/';
    if (!is_dir($ev_up_dir)) {
        @mkdir($ev_up_dir, 0777, true);
    }
    $sample_ev_docs = [
        'Official_Event_Request_Letter_2026.html' => "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Formal Event Request Letter</title><style>body{font-family:'Segoe UI',Arial,sans-serif;max-width:760px;margin:40px auto;padding:32px;border:1px solid #cbd5e1;border-radius:12px;color:#0f172a;line-height:1.6;}h1{color:#1e3a8a;margin:0 0 4px;font-size:20px;text-transform:uppercase;}p.sub{margin:0 0 20px;color:#475569;font-size:13px;border-bottom:2px solid #1e3a8a;padding-bottom:12px;}</style></head><body><h1>Bestlink College of the Philippines</h1><p class='sub'>Office of Student Affairs &amp; Supreme Student Council &bull; Official Activity Request Letter</p><p><strong>Date:</strong> October 2026</p><p><strong>To:</strong> Supreme Student Council &amp; Office of Student Affairs</p><p><strong>Subject:</strong> Formal Letter of Intent &amp; Request to Conduct Campus Co-Curricular Activity</p><p>Respectfully submitting this formal request letter for institutional endorsement and calendar clearance of our proposed student organization activity. All safety, venue, and academic guidelines have been strictly observed.</p><br><p><strong>Signed:</strong><br>Organization Executive President &amp; Faculty Adviser</p></body></html>",
        'Organization_Board_Resolution_2026.html'  => "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Organization Board Resolution</title><style>body{font-family:'Segoe UI',Arial,sans-serif;max-width:760px;margin:40px auto;padding:32px;border:1px solid #cbd5e1;border-radius:12px;color:#0f172a;line-height:1.6;}h1{color:#1e3a8a;margin:0 0 4px;font-size:20px;text-transform:uppercase;}p.sub{margin:0 0 20px;color:#475569;font-size:13px;border-bottom:2px solid #1e3a8a;padding-bottom:12px;}</style></head><body><h1>Bestlink College of the Philippines</h1><p class='sub'>Accredited Student Organization Executive Board &bull; Board Resolution No. 2026-04</p><p><strong>WHEREAS,</strong> the Executive Board convened in a regular session with quorum present;</p><p><strong>WHEREAS,</strong> the proposed co-curricular event aligns with the academic and leadership development objectives of the organization;</p><p><strong>NOW THEREFORE, BE IT RESOLVED,</strong> that the Executive Officers unanimously approve the implementation, schedule, and documentary compliance of this campus event.</p><br><p><strong>Attested by:</strong><br>Executive Secretary, Organization President &amp; Club Faculty Adviser</p></body></html>",
        'Event_Liquidation_Report_Form_2026.html' => "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Event Liquidation Report</title><style>body{font-family:'Segoe UI',Arial,sans-serif;max-width:760px;margin:40px auto;padding:32px;border:1px solid #cbd5e1;border-radius:12px;color:#0f172a;line-height:1.6;}h1{color:#1e3a8a;margin:0 0 4px;font-size:20px;text-transform:uppercase;}p.sub{margin:0 0 20px;color:#475569;font-size:13px;border-bottom:2px solid #1e3a8a;padding-bottom:12px;}table{width:100%;border-collapse:collapse;margin-top:14px;}th,td{border:1px solid #cbd5e1;padding:8px 10px;text-align:left;font-size:13px;}th{background:#f1f5f9;}</style></head><body><h1>Bestlink College of the Philippines</h1><p class='sub'>Student Organization Financial &amp; Activity Liquidation Sheet</p><p><strong>Compliance Status:</strong> Verified by Organization Treasurer &amp; Faculty Adviser</p><table><thead><tr><th>Particulars / Expense Category</th><th>Official Receipt Ref</th><th>Status</th></tr></thead><tbody><tr><td>Venue Setup, Sound System &amp; Logistics</td><td>OR-2026-8841</td><td>Accounted / Liquidated</td></tr><tr><td>Participant Certificates, Kits &amp; Materials</td><td>OR-2026-8842</td><td>Accounted / Liquidated</td></tr></tbody></table><br><p><strong>Certified Correct:</strong> Organization Treasurer &amp; Club Adviser</p></body></html>",
        'Event_Budget_Proposal_Breakdown_2026.html'=> "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Event Budget Proposal</title><style>body{font-family:'Segoe UI',Arial,sans-serif;max-width:760px;margin:40px auto;padding:32px;border:1px solid #cbd5e1;border-radius:12px;color:#0f172a;line-height:1.6;}h1{color:#1e3a8a;margin:0 0 4px;font-size:20px;text-transform:uppercase;}p.sub{margin:0 0 20px;color:#475569;font-size:13px;border-bottom:2px solid #1e3a8a;padding-bottom:12px;}table{width:100%;border-collapse:collapse;margin-top:14px;}th,td{border:1px solid #cbd5e1;padding:8px 10px;text-align:left;font-size:13px;}th{background:#f1f5f9;}</style></head><body><h1>Bestlink College of the Philippines</h1><p class='sub'>Co-Curricular Event Itemized Budget Proposal</p><p>This itemized budget proposal details the estimated expenses required to execute the proposed campus event.</p><table><thead><tr><th>Line Item</th><th>Description</th><th>Allocation</th></tr></thead><tbody><tr><td>Logistics &amp; Technical Equipment</td><td>Audio-visual setup, cables, and technical peripherals</td><td>45%</td></tr><tr><td>Training Kits &amp; Certificates</td><td>Printed handouts, badges, and speaker tokens</td><td>35%</td></tr><tr><td>Safety &amp; Sanitation Supplies</td><td>First-aid kit standby and venue sanitation</td><td>20%</td></tr></tbody></table><br><p><strong>Prepared by:</strong> Organization Treasurer &bull; <strong>Endorsed by:</strong> Club Adviser</p></body></html>"
    ];
    foreach ($sample_ev_docs as $fn => $html_content) {
        if (!file_exists($ev_up_dir . $fn)) {
            @file_put_contents($ev_up_dir . $fn, $html_content);
        }
    }

    // Populate events with the 4 documentary requirements (making every 4th event a zero-budget event to demonstrate optional Budget Proposal)
    $conn->query("
        UPDATE `events`
        SET `requires_budget`      = CASE WHEN (id % 4 = 0) THEN 0 ELSE 1 END,
            `budget_amount`        = CASE WHEN (id % 4 = 0) THEN 0.00 WHEN `budget_amount` <= 0 THEN (8500.00 + (id * 750.00)) ELSE `budget_amount` END,
            `doc_letter`           = COALESCE(`doc_letter`, 'Official_Event_Request_Letter_2026.html'),
            `doc_board_resolution` = COALESCE(`doc_board_resolution`, 'Organization_Board_Resolution_2026.html'),
            `doc_liquidation`      = COALESCE(`doc_liquidation`, 'Event_Liquidation_Report_Form_2026.html'),
            `doc_budget_proposal`  = CASE WHEN (id % 4 = 0) THEN NULL ELSE COALESCE(`doc_budget_proposal`, 'Event_Budget_Proposal_Breakdown_2026.html') END,
            `attachment`           = COALESCE(`attachment`, 'Official_Event_Request_Letter_2026.html')
        WHERE `doc_letter` IS NULL
    ");

    // 11. Seed Authentic Verified & Pending Achievements (If empty)
    $chk_ach = $conn->query("SELECT COUNT(*) FROM `achievements`");
    if ($chk_ach && (int)$chk_ach->fetch_row()[0] === 0) {
        $ach_data = [
            [1, 1, 'National Cybersecurity Defense Invitational - 1st Place Champion', 'Philippine Collegiate Cyber Challenge 2026', '2026-09-18', 'Verified', 59, 'Outstanding technical defense performance and cryptography analysis.'],
            [1, 1, 'Regional Collegiate Hackathon & AI Innovation Summit - Best Technical Solution', 'NCR Universities AI Expo 2026', '2026-10-02', 'Verified', 58, 'Autonomous emergency response platform built in under 24 hours.'],
            [28, 1, 'Philippine Red Cross National Youth Humanitarian Leadership Award', 'PRC 77th National Youth Convention', '2026-08-25', 'Verified', 238, 'Excellence in organizing campus voluntary blood drives and first aid response.'],
            [22, 2, 'National Hospitality Skills Olympics - Gold Medal Mixology & Dining', 'CHED Hospitality Skills League 2026', '2026-09-12', 'Verified', 59, 'Flawless international cocktail styling and front-of-house service.'],
            [30, 2, 'Metro Manila Inter-University Performing Arts Festival - Best Choreography', 'NCR Cultural Guild Festival', '2026-09-30', 'Verified', 238, 'Exceptional staging of contemporary Philippine folk modern choreography.'],
            [7, 1, 'National Criminology Moot Court & Forensics Invitational - Outstanding Defense', 'National Criminalistics Assembly 2026', '2026-10-05', 'Verified', 58, 'Excellence in ballistic chain of custody and moot court presentation.'],
            [8, 6, 'National Youth Venture & Business Case Challenge - Regional Finalist', 'Philippine Young Entrepreneurs Forum', '2026-10-10', 'Pending', NULL, 'E-commerce micro-franchise model currently under review.'],
            [19, 1, 'Philippine Psychological Association Research Poster Award', 'PAP 60th Annual Convention', '2026-10-12', 'Pending', NULL, 'Study on collegiate cognitive resilience and peer counseling efficacy.']
        ];
        $ach_stmt = $conn->prepare("INSERT INTO `achievements` (`club_id`, `submitted_by`, `title`, `competition`, `award_date`, `status`, `verified_by`, `notes`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($ach_stmt) {
            foreach ($ach_data as $a) {
                $ach_stmt->bind_param('iissssis', $a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $a[6], $a[7]);
                $ach_stmt->execute();
            }
            $ach_stmt->close();
        }
    }

    // 12. Seed Authentic Multi-Tier Budget Requisitions & Disbursements
    $chk_bud = $conn->query("SELECT COUNT(*) FROM `budget_requests`");
    if ($chk_bud && (int)$chk_bud->fetch_row()[0] < 20) {
        $clubs_res_b = $conn->query("SELECT id, code FROM clubs");
        $cm_b = [];
        if ($clubs_res_b) {
            while ($cr = $clubs_res_b->fetch_assoc()) {
                $cm_b[$cr['code']] = (int)$cr['id'];
            }
        }
        $adv_res_b = $conn->query("SELECT id, username FROM users WHERE role IN ('club_adviser','ssc','admin')");
        $um_b = [];
        if ($adv_res_b) {
            while ($ur = $adv_res_b->fetch_assoc()) {
                $um_b[$ur['username']] = (int)$ur['id'];
            }
        }
        $def_admin_id = $um_b['scc.admin'] ?? 59;
        $def_ssc_id   = $um_b['ssc.officer'] ?? 58;

        $bud_data = [
            // Disbursed (Released)
            [
                $cm_b['CSSEC'] ?? 1,
                'Cloud Architecture Hackathon Venue & Server Resources',
                'Server cloud credits, network routers, and lab venue logistics for CSSEC collegiate hackathon.',
                json_encode([
                    ['item' => 'AWS & Cloud Compute Credits', 'description' => 'Sandbox server instances for 25 competing teams', 'qty' => 25, 'unit_cost' => 500, 'total' => 12500],
                    ['item' => 'Gigabit Managed Switches & Patch Cables', 'description' => 'Local area network setup in Main Auditorium', 'qty' => 5, 'unit_cost' => 1500, 'total' => 7500],
                    ['item' => 'Hackathon Medals, Certificates & ID Lanyards', 'description' => 'Official participant kits and winner plaques', 'qty' => 100, 'unit_cost' => 50, 'total' => 5000]
                ]),
                25000.00, 25000.00, 25000.00, 'Disbursed', $um_b['cssec.adviser'] ?? 1,
                'Official clearance granted by Admin & Disbursed via Accounting Check #0041.', '2026-09-15 14:00:00', 'CHK-2026-0041', $def_admin_id
            ],
            [
                $cm_b['RCYC-BCP'] ?? 28,
                'Red Cross Blood Drive Medical Supplies & Donor Care Kits',
                'Clinical sterile test kits, post-donation vitamins, recovery refreshments, and folding cots.',
                json_encode([
                    ['item' => 'Sterile Blood Typing & Hemoglobin Lancets', 'description' => 'Pre-screening supplies for 200 student donors', 'qty' => 4, 'unit_cost' => 2000, 'total' => 8000],
                    ['item' => 'Donor Recovery Nutrition Packs', 'description' => 'Iron supplements, juice boxes, and energy biscuits', 'qty' => 200, 'unit_cost' => 35, 'total' => 7000],
                    ['item' => 'Medical Triage Tarpaulins & Sanitation Alcohol', 'description' => 'Disinfectant gallons and directional signage', 'qty' => 7, 'unit_cost' => 500, 'total' => 3500]
                ]),
                18500.00, 18500.00, 18500.00, 'Disbursed', $um_b['rcyc.adviser'] ?? 1,
                'Co-funded by Health Services. Official receipt verified.', '2026-09-28 11:30:00', 'CHK-2026-0052', $def_admin_id
            ],
            [
                $cm_b['ITS'] ?? 41,
                'BSIT Cybersecurity & Cloud Defense Workshop Lab Kits',
                'Hardware security keys, packet sniffing lab adapters, and guest speaker honorarium for BSIT summit.',
                json_encode([
                    ['item' => 'Dual-Band Packet Injection Wireless Adapters', 'description' => 'Cybersecurity defensive lab exercises', 'qty' => 15, 'unit_cost' => 800, 'total' => 12000],
                    ['item' => 'Certified Ethical Hacker Speaker Honorarium', 'description' => 'Industry keynote speaker token and plaque', 'qty' => 2, 'unit_cost' => 3500, 'total' => 7000],
                    ['item' => 'Printed Lab Manuals & Reference Guides', 'description' => 'Spiral-bound network defense workbooks', 'qty' => 100, 'unit_cost' => 45, 'total' => 4500]
                ]),
                23500.00, 23500.00, 23500.00, 'Disbursed', $um_b['bsit.adviser'] ?? 1,
                'Approved & Disbursed by System Admin: Lab equipment logged in IT inventory.', '2026-10-02 10:15:00', 'CHK-2026-0064', $def_admin_id
            ],
            [
                $cm_b['CJSU'] ?? 7,
                'Forensic Ballistics & Crime Scene Investigation Field Kit',
                'Latent fingerprint dusting powders, evidence markers, barrier tapes, and UV inspection lamps.',
                json_encode([
                    ['item' => 'Magnetic Latent Fingerprint Powder Kits', 'description' => 'Forensic lab demonstration kits with brushes', 'qty' => 10, 'unit_cost' => 950, 'total' => 9500],
                    ['item' => 'Numbered Crime Scene Evidence Tents & Tapes', 'description' => 'Acrylic photo markers (1-50) and perimeter rolls', 'qty' => 5, 'unit_cost' => 1100, 'total' => 5500],
                    ['item' => 'Forensic UV Light Sources & Protective Goggles', 'description' => 'Trace evidence detection tools', 'qty' => 6, 'unit_cost' => 800, 'total' => 4800]
                ]),
                19800.00, 19000.00, 19000.00, 'Disbursed', $um_b['cjsu.adviser'] ?? 1,
                'SSC vetted at ₱19,000.00. Disbursed by Accounting for Criminology Week.', '2026-10-05 15:20:00', 'CHK-2026-0071', $def_admin_id
            ],
            [
                $cm_b['G.A.L.A.W'] ?? 9,
                'Inter-Collegiate Athletics Conditioning & First Aid Gear',
                'Agility ladders, digital stopwatches, sports hydration coolers, and elastic kinesiology tapes.',
                json_encode([
                    ['item' => 'Speed & Agility Training Ladders + Cones', 'description' => 'Varsity & PE conditioning equipment set', 'qty' => 6, 'unit_cost' => 1200, 'total' => 7200],
                    ['item' => 'Insulated 20L Hydration Coolers', 'description' => 'Courtside hydration stations for athletes', 'qty' => 4, 'unit_cost' => 1600, 'total' => 6400],
                    ['item' => 'Athletic Ice Packs & Kinesiology Tape Rolls', 'description' => 'Sports injury prevention supplies', 'qty' => 20, 'unit_cost' => 180, 'total' => 3600]
                ]),
                17200.00, 17200.00, 17200.00, 'Disbursed', $um_b['galaw.adviser'] ?? 1,
                'Disbursed for 1st Semester Intramurals & Wellness Camp.', '2026-10-08 09:45:00', 'CHK-2026-0079', $def_admin_id
            ],
            [
                $cm_b['NEWSLINK'] ?? 40,
                'Campus Journalism Press Conference & Publication Layout Issue',
                'Offset printing of broadsheet campus newsletter, press ID badges, and photography SD cards.',
                json_encode([
                    ['item' => 'Campus Broadsheet Issue Printing (1,000 copies)', 'description' => '12-page full-color newsprint layout', 'qty' => 1000, 'unit_cost' => 18, 'total' => 18000],
                    ['item' => 'High-Speed SDXC Cards for Photojournalists', 'description' => '128GB UHS-II memory cards for event coverage', 'qty' => 4, 'unit_cost' => 1250, 'total' => 5000],
                    ['item' => 'Official Press Corps Lanyards & PVC Badges', 'description' => 'Accredited student media credentials', 'qty' => 40, 'unit_cost' => 75, 'total' => 3000]
                ]),
                26000.00, 25000.00, 25000.00, 'Disbursed', $um_b['newslink.adviser'] ?? 1,
                'Released via Voucher #CHK-2026-0085 for 1st Semester Press Issue.', '2026-10-10 16:00:00', 'CHK-2026-0085', $def_admin_id
            ],

            // Approved / Pending Admin (Stage 2: Awaiting Final Admin Release)
            [
                $cm_b['ALL STAR'] ?? 30,
                'Philippine Cultural Heritage Festival Costumes & Sound Equipment',
                'Traditional Mindanao and Cordillera hand-woven textiles and stage monitor rentals.',
                json_encode([
                    ['item' => 'Indigenous Folk Dance Costume Ensemble', 'description' => 'Traditional attire rental & accessories for 24 performers', 'qty' => 24, 'unit_cost' => 550, 'total' => 13200],
                    ['item' => 'Stage Line-Array Monitors & Wireless Microphones', 'description' => 'Professional acoustic reinforcement for Campus Plaza', 'qty' => 1, 'unit_cost' => 6800, 'total' => 6800],
                    ['item' => 'Stage Backdrop Tarpaulin & Cultural Props', 'description' => 'Bamboo props, fans, and stage banner', 'qty' => 1, 'unit_cost' => 2000, 'total' => 2000]
                ]),
                22000.00, 22000.00, 22000.00, 'Approved', $um_b['allstar.adviser'] ?? 1,
                'Fully endorsed by SSC & approved for disbursement clearance.', NULL, NULL, NULL
            ],
            [
                $cm_b['TECHs'] ?? 22,
                'Hospitality Championship Culinary Ingredients & Uniforms',
                'Culinary competition grade raw ingredients, chef aprons, and food safety sanitation kits.',
                json_encode([
                    ['item' => 'Culinary Competition Proteins & Fresh Produce', 'description' => 'Perishable ingredients for 12 competing culinary teams', 'qty' => 12, 'unit_cost' => 750, 'total' => 9000],
                    ['item' => 'Embroidered Chef Aprons & Hairnets', 'description' => 'Standard kitchen safety attire for participants', 'qty' => 24, 'unit_cost' => 180, 'total' => 4320],
                    ['item' => 'Food-Grade Sanitizer & Butane Canisters', 'description' => 'Portable stove fuel and workstation hygiene', 'qty' => 12, 'unit_cost' => 140, 'total' => 1680]
                ]),
                15000.00, 15000.00, NULL, 'Pending Admin', $um_b['techs.adviser'] ?? 1,
                'Adviser endorsed & SSC reviewed. Pending final Admin clearance.', NULL, NULL, NULL
            ],
            [
                $cm_b['CSSEC'] ?? 1,
                'Campus Leadership Summit Delegation Portfolios & Badges',
                'Conferencing kits, executive notebooks, certificates, and floor microphones.',
                json_encode([
                    ['item' => 'Delegate Folder Kits & Ballpens', 'description' => 'Printed leadership handbook, notepad, and ID badge', 'qty' => 350, 'unit_cost' => 60, 'total' => 21000],
                    ['item' => 'Plenary Hall Audio & Projector Setup', 'description' => 'Wireless gooseneck podium mics and LED wall calibration', 'qty' => 1, 'unit_cost' => 8000, 'total' => 8000],
                    ['item' => 'Gold-Foiled Leadership Certificates & Frames', 'description' => 'Official certificates for 40 accredited club presidents', 'qty' => 40, 'unit_cost' => 150, 'total' => 6000]
                ]),
                35000.00, 32000.00, NULL, 'Pending Admin', $def_ssc_id,
                'Institutional student governance summit logistics. SSC endorsed at ₱32,000.00.', NULL, NULL, NULL
            ],
            [
                $cm_b['TTS'] ?? 23,
                'Eco-Tourism Summit & Mock Airline Cabin Setup Materials',
                'Cabin service training carts, boarding pass printing, and tourism heritage booth panels.',
                json_encode([
                    ['item' => 'Regional Heritage Tourism Exhibition Booths', 'description' => 'Modular display panels and cultural decor for 8 regions', 'qty' => 8, 'unit_cost' => 1500, 'total' => 12000],
                    ['item' => 'Mock Cabin In-Flight Demonstration Supplies', 'description' => 'Life vests, safety cards, and meal tray props', 'qty' => 10, 'unit_cost' => 450, 'total' => 4500],
                    ['item' => 'Tourism Keynote Speakers Token & Lei', 'description' => 'DOT & airline industry guest speakers hospitality', 'qty' => 3, 'unit_cost' => 1000, 'total' => 3000]
                ]),
                19500.00, 18500.00, NULL, 'Pending Admin', $um_b['tts.adviser'] ?? 1,
                'SSC vetted line items and recommended ₱18,500.00 for Admin release.', NULL, NULL, NULL
            ],
            [
                $cm_b['AISS'] ?? 4,
                'FinTech & Digital Audit Simulation Software Licenses',
                'Cloud ERP student sandbox licenses, forensic accounting casebooks, and seminar certificates.',
                json_encode([
                    ['item' => 'Enterprise Accounting Sandbox Subscriptions', 'description' => '30-day multi-user academic cloud ERP seats', 'qty' => 50, 'unit_cost' => 200, 'total' => 10000],
                    ['item' => 'Forensic Audit Case Study Workbooks', 'description' => 'Printed ledger audit packets for workshop', 'qty' => 120, 'unit_cost' => 35, 'total' => 4200],
                    ['item' => 'CPA Guest Lecturer Honorarium & Plaque', 'description' => 'Resource person token of appreciation', 'qty' => 1, 'unit_cost' => 3300, 'total' => 3300]
                ]),
                17500.00, 17500.00, NULL, 'Pending Admin', $um_b['aiss.adviser'] ?? 1,
                'Reviewed & forwarded by SSC: Full amount recommended for academic lab simulation.', NULL, NULL, NULL
            ],
            [
                $cm_b['EYO'] ?? 8,
                'Young Entrepreneurs Startup Fair Booth Tents & Electricals',
                'Collapsible bazaar tents, heavy-duty extension cords, and Best Startup Pitch cash awards.',
                json_encode([
                    ['item' => 'Heavy-Duty 3x3m Collapsible Canopy Tents', 'description' => 'Outdoor quadrangle stalls for 15 student startups', 'qty' => 15, 'unit_cost' => 900, 'total' => 13500],
                    ['item' => 'Industrial Extension Reels & Circuit Breakers', 'description' => 'Safe power distribution for food & tech stalls', 'qty' => 6, 'unit_cost' => 850, 'total' => 5100],
                    ['item' => 'Best Venture Pitch Trophies & Judges Kits', 'description' => 'Acrylic plaques and scoring portfolios', 'qty' => 1, 'unit_cost' => 3400, 'total' => 3400]
                ]),
                22000.00, 20500.00, NULL, 'Pending Admin', $um_b['eyo.adviser'] ?? 1,
                'SSC reviewed & endorsed ₱20,500.00 to Administration.', NULL, NULL, NULL
            ],

            // Pending SSC (Stage 1: In SSC Review Queue)
            [
                $cm_b['PsychSoc'] ?? 19,
                'Youth Mental Health Awareness Kits & Resource Speaker Honorarium',
                'Psychological first aid brochures, self-care kits, and accredited clinical speaker fee.',
                json_encode([
                    ['item' => 'Student Wellness & Mindfulness Care Kits', 'description' => 'Stress relief journals, hotline cards, and ribbons', 'qty' => 200, 'unit_cost' => 35, 'total' => 7000],
                    ['item' => 'Licensed Clinical Psychologist Honorarium', 'description' => 'Keynote speaker fee for Mental Health Week', 'qty' => 1, 'unit_cost' => 3500, 'total' => 3500],
                    ['item' => 'Peer Counseling Booth Tarpaulins & Forms', 'description' => 'Intake sheets and privacy partition banners', 'qty' => 3, 'unit_cost' => 500, 'total' => 1500]
                ]),
                12000.00, NULL, NULL, 'Pending SSC', $um_b['psychsoc.adviser'] ?? 1,
                'Adviser endorsed. Submitted to SSC Executive Committee for review.', NULL, NULL, NULL
            ],
            [
                $cm_b['ACES'] ?? 3,
                'Robotics Tournament Microcontroller Kits & Actuator Parts',
                'Procurement of sensor arrays, motor controllers, and development boards for ACES challenge.',
                json_encode([
                    ['item' => 'ESP32 & Arduino Mega Development Kits', 'description' => 'Microcontroller boards with ultrasonic & IR sensors', 'qty' => 10, 'unit_cost' => 950, 'total' => 9500],
                    ['item' => 'High-Torque Servo Motors & LiPo Battery Packs', 'description' => 'Actuators and rechargeable power cells for line-followers', 'qty' => 10, 'unit_cost' => 450, 'total' => 4500],
                    ['item' => 'Robotics Arena Track Mat & Obstacle Walls', 'description' => 'Vinyl printed competition arena track', 'qty' => 1, 'unit_cost' => 2000, 'total' => 2000]
                ]),
                16000.00, NULL, NULL, 'Pending SSC', $um_b['aces.adviser'] ?? 1,
                'Submitted by ACES Executive Board. Endorsed by Adviser Elena Ramos.', NULL, NULL, NULL
            ],
            [
                $cm_b['JFINEX'] ?? 12,
                'Collegiate Stock Market & Equity Valuation Trading Terminal',
                'Live market data feed pass, financial calculator units, and corporate summit snacks.',
                json_encode([
                    ['item' => 'PSE Stock Market Simulator Team Passes', 'description' => 'Real-time virtual trading platform access for 30 teams', 'qty' => 30, 'unit_cost' => 250, 'total' => 7500],
                    ['item' => 'Financial Analyst Summit Delegate Refreshments', 'description' => 'AM/PM snacks for 150 business administration delegates', 'qty' => 150, 'unit_cost' => 40, 'total' => 6000],
                    ['item' => 'Certificates, Plaques & Stage Tarpaulin', 'description' => 'Recognition awards for Top 3 Portfolio Managers', 'qty' => 1, 'unit_cost' => 2500, 'total' => 2500]
                ]),
                16000.00, NULL, NULL, 'Pending SSC', $um_b['jfinex.adviser'] ?? 1,
                'Endorsed by Club Adviser Prof. Manuel Cruz. Awaiting SSC line-item review.', NULL, NULL, NULL
            ],
            [
                $cm_b['GEMs'] ?? 10,
                'Parliamentary Debate & Literary Folio Printing Requisition',
                'Publication of annual student literary anthology, adjudication sheets, and debate timers.',
                json_encode([
                    ['item' => 'Literary Folio Book Printing (250 copies)', 'description' => '80-page perfect-bound student poetry & prose anthology', 'qty' => 250, 'unit_cost' => 48, 'total' => 12000],
                    ['item' => 'Debate Championship Trophies & Gavels', 'description' => 'Best Speaker & Champion Team awards', 'qty' => 4, 'unit_cost' => 650, 'total' => 2600],
                    ['item' => 'Adjudication Scorecards & Timekeeper Bells', 'description' => 'Official British Parliamentary scoring packets', 'qty' => 1, 'unit_cost' => 1200, 'total' => 1200]
                ]),
                15800.00, NULL, NULL, 'Pending SSC', $um_b['gems.adviser'] ?? 1,
                'Endorsed by Adviser Prof. Anna Reyes. Forwarded to SSC queue.', NULL, NULL, NULL
            ],
            [
                $cm_b['SIGMA'] ?? 21,
                'Mathematics Olympiad & Gamified Instructional Kits Exhibit',
                'Manipulative geometry sets, scientific calculators for quiz bee, and exhibit boards.',
                json_encode([
                    ['item' => 'Manipulative Algebra & Geometry Teaching Kits', 'description' => '3D geometric solids, fraction tiles, and probability sets', 'qty' => 12, 'unit_cost' => 650, 'total' => 7800],
                    ['item' => 'Math Olympiad Medals & Cash Prize Pool', 'description' => 'Gold, Silver, Bronze medals and quiz bee materials', 'qty' => 3, 'unit_cost' => 1500, 'total' => 4500],
                    ['item' => 'Illustration Boards, Markers & Display Easels', 'description' => 'Instructional materials festival booth supplies', 'qty' => 15, 'unit_cost' => 140, 'total' => 2100]
                ]),
                14400.00, NULL, NULL, 'Pending SSC', $um_b['sigma.adviser'] ?? 1,
                'Endorsed by Adviser Prof. Karen Lim. Pending SSC budget audit.', NULL, NULL, NULL
            ],
            [
                $cm_b['ACADS'] ?? 2,
                'IoT Smart Campus Energy Meter Prototyping Components',
                'Non-invasive current sensors, solar charge controllers, and PCB fabrication for engineering showcase.',
                json_encode([
                    ['item' => 'SCT-013 Current Transformers & LoRa Modules', 'description' => 'Wireless telemetry nodes for building power monitoring', 'qty' => 12, 'unit_cost' => 750, 'total' => 9000],
                    ['item' => 'Custom Double-Layer PCB Fabrication & Solder Paste', 'description' => 'Sensor breakout boards and weatherproof enclosures', 'qty' => 10, 'unit_cost' => 520, 'total' => 5200],
                    ['item' => '20W Polycrystalline Solar Panels & LiFePO4 Cells', 'description' => 'Self-powered outdoor gateway power supply', 'qty' => 4, 'unit_cost' => 1100, 'total' => 4400]
                ]),
                18600.00, NULL, NULL, 'Pending SSC', $um_b['acads.adviser'] ?? 1,
                'Endorsed by Adviser Prof. Mark Velo. Submitted to SSC for technical review.', NULL, NULL, NULL
            ],
            [
                $cm_b['PEER'] ?? 39,
                'Peer Counselors Training Camp & Crisis Hotline Materials',
                'Training modules on active listening, referral directory booklets, and volunteer vests.',
                json_encode([
                    ['item' => 'Psychological First Aid Training Workbooks', 'description' => 'Accredited peer facilitator manuals', 'qty' => 60, 'unit_cost' => 95, 'total' => 5700],
                    ['item' => 'Peer Counselor Identification Vests & Pins', 'description' => 'High-visibility campus support vests', 'qty' => 30, 'unit_cost' => 180, 'total' => 5400],
                    ['item' => 'Campus Crisis Support Pocket Directories', 'description' => 'Laminated emergency referral cards for students', 'qty' => 500, 'unit_cost' => 5, 'total' => 2500]
                ]),
                13600.00, NULL, NULL, 'Pending SSC', $um_b['peer.adviser'] ?? 1,
                'Adviser endorsed — submitted to SSC for review & vetting.', NULL, NULL, NULL
            ],

            // Pending Adviser (Stage 0: Awaiting Faculty Adviser Endorsement)
            [
                $cm_b['BLISS'] ?? 5,
                'Digital Archiving & Open-Access Cataloging Workshop Supplies',
                'Barcode label rolls, book repair archival tape, and KOHA ILS training handouts.',
                json_encode([
                    ['item' => 'Archival Bookbinding Cloth & Acid-Free Tape', 'description' => 'Preservation materials for rare reference collection', 'qty' => 10, 'unit_cost' => 480, 'total' => 4800],
                    ['item' => 'Thermal Barcode Labels & Scanner Stand', 'description' => 'Automated library cataloging lab consumables', 'qty' => 8, 'unit_cost' => 550, 'total' => 4400],
                    ['item' => 'MARC21 & RDA Cataloging Reference Guides', 'description' => 'Workshop manuals for BLIS students', 'qty' => 50, 'unit_cost' => 40, 'total' => 2000]
                ]),
                11200.00, NULL, NULL, 'Pending Adviser', $um_b['bliss.adviser'] ?? 1,
                'Drafted by BLISS Treasurer. Awaiting Faculty Adviser endorsement.', NULL, NULL, NULL
            ],
            [
                $cm_b['J.M.A'] ?? 14,
                'Digital Brand Marketing & Social Media Case Competition',
                'Studio ring lights, wireless lapel microphones, and marketing case competition prizes.',
                json_encode([
                    ['item' => 'LED Softbox Lighting & Dual Wireless Lavalier Mics', 'description' => 'Ad campaign production gear for marketing students', 'qty' => 2, 'unit_cost' => 3200, 'total' => 6400],
                    ['item' => 'Best Brand Campaign Plaques & Certificates', 'description' => 'Awards for top student marketing agencies', 'qty' => 5, 'unit_cost' => 700, 'total' => 3500],
                    ['item' => 'Industry Panel Judges Hospitality & Tokens', 'description' => 'Gift bags and refreshments for ad agency panelists', 'qty' => 4, 'unit_cost' => 750, 'total' => 3000]
                ]),
                12900.00, NULL, NULL, 'Pending Adviser', $um_b['jma.adviser'] ?? 1,
                'Filed by J.M.A Executive Board. Pending Adviser endorsement.', NULL, NULL, NULL
            ],

            // Returned for Revision
            [
                $cm_b['B-FORCE'] ?? 31,
                'Inter-University Hip-Hop Dance Crew Uniforms & Studio Rental',
                'Custom sublimated jerseys, knee pads, and off-campus rehearsal studio booking.',
                json_encode([
                    ['item' => 'Custom Sublimated Performance Warm-Up Jerseys', 'description' => 'Full team uniform set for 20 dancers', 'qty' => 20, 'unit_cost' => 650, 'total' => 13000],
                    ['item' => 'External Dance Studio Rehearsal Hours', 'description' => 'Mirrored studio rental (20 hours)', 'qty' => 20, 'unit_cost' => 400, 'total' => 8000]
                ]),
                21000.00, 13000.00, NULL, 'Returned', $um_b['bforce.adviser'] ?? 1,
                'Returned by SSC: Campus PE Dance Hall is available free of charge; please remove off-campus studio rental and resubmit.', NULL, NULL, NULL
            ],
            [
                $cm_b['WIKA'] ?? 24,
                'Buwan ng Wika Balagtasan & Sabayang Pagbigkas Props',
                'Filipiniana barong rentals, stage decoration parols, and judges honoraria.',
                json_encode([
                    ['item' => 'Barong Tagalog & Saya Ensemble Rentals', 'description' => 'Attire for lakandiwa and mambabalagtas', 'qty' => 15, 'unit_cost' => 500, 'total' => 7500],
                    ['item' => 'Stage Bamboo Arch & Native Capiz Decors', 'description' => 'Bulwagang Balagtas stage styling', 'qty' => 1, 'unit_cost' => 4500, 'total' => 4500],
                    ['item' => 'Lupon ng Inampalan Tokens & Certificates', 'description' => 'Tokens for 3 guest Filipino literature professors', 'qty' => 3, 'unit_cost' => 800, 'total' => 2400]
                ]),
                14400.00, 12000.00, NULL, 'Returned', $um_b['wika.adviser'] ?? 1,
                'Returned by Admin: Please attach 3 supplier quotations for stage bamboo arch materials.', NULL, NULL, NULL
            ],

            // Rejected
            [
                $cm_b['SMC'] ?? 29,
                'Off-Campus Overnight Badminton Resort Team Building',
                'Private resort pool reservation, van charter, and catering for club social outing.',
                json_encode([
                    ['item' => 'Private Resort Overnight Venue Reservation', 'description' => 'Off-campus recreational resort booking', 'qty' => 1, 'unit_cost' => 18000, 'total' => 18000],
                    ['item' => 'Chartered Van Transportation', 'description' => 'Roundtrip transport for 25 members', 'qty' => 2, 'unit_cost' => 4500, 'total' => 9000]
                ]),
                27000.00, NULL, NULL, 'Rejected', $um_b['smc.adviser'] ?? 1,
                'Rejected by SSC: Co-curricular funds cannot be allocated for non-academic private resort outings per OSA Financial Guidelines Sec. 4.2.', NULL, NULL, NULL
            ],
            [
                $cm_b['DLC'] ?? 34,
                'Unitemized Miscellaneous Marching Band Contingency Fund',
                'Lump-sum cash advance for miscellaneous drum and lyre expenses without supplier quotes.',
                json_encode([
                    ['item' => 'Miscellaneous Contingency Cash Advance', 'description' => 'Unitemized operational buffer', 'qty' => 1, 'unit_cost' => 15000, 'total' => 15000]
                ]),
                15000.00, NULL, NULL, 'Rejected', $um_b['dlc.adviser'] ?? 1,
                'Rejected by Admin: All requisitions must contain specific line-item quantities and unit costs. Lump-sum cash advances are not permitted.', NULL, NULL, NULL
            ]
        ];

        $chk_title_stmt = $conn->prepare("SELECT id FROM `budget_requests` WHERE `title` = ? LIMIT 1");
        $bud_stmt = $conn->prepare("INSERT INTO `budget_requests` (`club_id`, `title`, `description`, `line_items`, `amount`, `recommended_amount`, `final_approved_amount`, `status`, `requested_by`, `notes`, `disbursed_at`, `disbursement_reference`, `disbursed_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $upd_li_stmt = $conn->prepare("UPDATE `budget_requests` SET `line_items` = ? WHERE `id` = ? AND (`line_items` IS NULL OR `line_items` = '')");

        if ($bud_stmt && $chk_title_stmt) {
            foreach ($bud_data as $b) {
                $chk_title_stmt->bind_param('s', $b[1]);
                $chk_title_stmt->execute();
                $res_t = $chk_title_stmt->get_result();
                if ($res_t && $existing_row = $res_t->fetch_assoc()) {
                    if ($upd_li_stmt) {
                        $ex_id = (int)$existing_row['id'];
                        $upd_li_stmt->bind_param('si', $b[3], $ex_id);
                        $upd_li_stmt->execute();
                    }
                } else {
                    $bud_stmt->bind_param('isssdddsssssi', $b[0], $b[1], $b[2], $b[3], $b[4], $b[5], $b[6], $b[7], $b[8], $b[9], $b[10], $b[11], $b[12]);
                    $bud_stmt->execute();
                }
            }
            $bud_stmt->close();
            $chk_title_stmt->close();
            if ($upd_li_stmt) $upd_li_stmt->close();
        }
    }

    // 13. Seed Authentic Student Volunteer Logs & Hours (If empty)
    $chk_vol = $conn->query("SELECT COUNT(*) FROM `volunteer_logs`");
    if ($chk_vol && (int)$chk_vol->fetch_row()[0] === 0) {
        $vol_data = [
            [1, 2, 1, 1, 'Juan Santos', '2024-10001', 'Bachelor of Science in Information Technology', '2nd Year', 'BCP Inter-College Hackathon & Code Fest', 'Technical Support & Network Crew', 'Assisted 15 competing hacker teams with network patch cables, switches, and projector calibration.', '2026-10-15 08:00:00', '2026-10-15 14:00:00', 6.00, 'Verified', 238, '2026-10-15 15:30:00', 'Completed full 6-hour shift. Commended by IT head.'],
            [2, 5, 28, 5, 'Maria Cruz', '2024-10002', 'Bachelor of Science in Hospitality Management', '1st Year', 'Red Cross Emergency First Aid & Voluntary Blood Drive', 'Donor Registration & Triage Usher', 'Organized donor forms, verified valid IDs, and coordinated donor queue numbers.', '2026-10-05 08:30:00', '2026-10-05 13:30:00', 5.00, 'Verified', 238, '2026-10-05 14:00:00', 'Verified by OSA staff. Excellent interpersonal communication.'],
            [3, 5, 28, 6, 'Jose Reyes', '2024-10003', 'Bachelor of Science in Accounting Information System', '3rd Year', 'Red Cross Emergency First Aid & Voluntary Blood Drive', 'Refreshment & Logistics Aide', 'Handled hydration logistics, recovery station juice boxes, and biscuit replenishments.', '2026-10-05 09:00:00', '2026-10-05 13:00:00', 4.00, 'Verified', 238, '2026-10-05 14:15:00', 'Full volunteer duty completed.'],
            [4, 4, 30, 7, 'Ana Dela Cruz', '2024-10004', 'Bachelor of Science in Tourism Management', '2nd Year', 'Philippine Cultural Heritage & Folk Dance Showcase', 'Stage Management & Backstage Usher', 'Assisted performing artists with costume props and backstage call-times.', '2026-09-30 12:30:00', '2026-09-30 17:30:00', 5.00, 'Verified', 238, '2026-09-30 18:00:00', 'Verified by OSA staff coordinator.'],
            [5, 4, 30, 8, 'Carlos Garcia', '2024-10005', 'Bachelor of Science in Office Administration', '1st Year', 'Philippine Cultural Heritage & Folk Dance Showcase', 'Guest Reception & Program Usher', 'Distributed official program booklets and ushered VIP faculty members to reserved rows.', '2026-09-30 13:00:00', '2026-09-30 17:00:00', 4.00, 'Verified', 238, '2026-09-30 18:00:00', 'Verified.'],
            [6, 3, 1, 9, 'Liza Ramos', '2024-10006', 'Bachelor of Science in Entrepreneurship', '3rd Year', 'Campus Leadership Summit & Officer Synergy Forum', 'Summit Secretariat & Materials Logistics', 'Packaged delegate kits, prepared floor microphones, and logged session attendance.', '2026-10-14 08:30:00', '2026-10-14 14:30:00', 6.00, 'Verified', 238, '2026-10-14 15:00:00', 'Verified by OSA coordinator.'],
            [7, 2, 1, 2, 'Ramon Villanueva', '2024-10007', 'Bachelor of Science in Business Administration', '2nd Year', 'BCP Inter-College Hackathon & Code Fest', 'Registration Desk & Hacker Check-in', 'Verified registration QR tickets and handed out participant badges.', '2026-10-15 08:00:00', '2026-10-15 12:00:00', 4.00, 'Pending Verification', NULL, NULL, 'Shift logged. Awaiting final supervisor sign-off.'],
            [8, 5, 28, 4, 'Patricia Aquino', '2024-10008', 'Bachelor of Science in Information Science', '1st Year', 'Red Cross Emergency First Aid & Voluntary Blood Drive', 'First Aid & Donor Care Assistant', 'Assisted PRC nurses with observation cots and health checks.', '2026-10-05 08:00:00', '2026-10-05 16:00:00', 8.00, 'Pending Verification', NULL, NULL, 'Awaiting blood drive coordinator verification.']
        ];
        $vol_stmt = $conn->prepare("INSERT INTO `volunteer_logs` (`user_id`, `event_id`, `club_id`, `opportunity_id`, `student_name`, `student_number`, `course`, `year_level`, `event_title`, `volunteer_role`, `notes`, `check_in`, `check_out`, `hours`, `status`, `verified_by`, `verified_at`, `verification_notes`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($vol_stmt) {
            foreach ($vol_data as $v) {
                $vol_stmt->bind_param('iiiisssssssssdsiss', $v[0], $v[1], $v[2], $v[3], $v[4], $v[5], $v[6], $v[7], $v[8], $v[9], $v[10], $v[11], $v[12], $v[13], $v[14], $v[15], $v[16], $v[17]);
                $vol_stmt->execute();
            }
            $vol_stmt->close();
        }
    }

    // 14. Seed Authentic Verified Event Attendance Records (If low/empty)
    $chk_att = $conn->query("SELECT COUNT(*) FROM `attendance_logs`");
    if ($chk_att && (int)$chk_att->fetch_row()[0] < 15) {
        $att_samples = [
            [2, 1, '2026-10-15 08:45:00', 'QR', 1, 'Verified QR Check-In'],
            [2, 3, '2026-10-15 08:52:00', 'QR', 1, 'Verified QR Check-In'],
            [2, 7, '2026-10-15 08:55:00', 'QR', 1, 'Verified QR Check-In'],
            [2, 8, '2026-10-15 09:02:00', 'QR', 1, 'Verified QR Check-In'],
            [3, 1, '2026-10-14 08:35:00', 'QR', 58, 'Verified QR Check-In'],
            [3, 2, '2026-10-14 08:40:00', 'QR', 58, 'Verified QR Check-In'],
            [3, 4, '2026-10-14 08:48:00', 'QR', 58, 'Verified QR Check-In'],
            [3, 5, '2026-10-14 08:50:00', 'QR', 58, 'Verified QR Check-In'],
            [3, 6, '2026-10-14 08:55:00', 'QR', 58, 'Verified QR Check-In'],
            [4, 2, '2026-09-30 12:45:00', 'QR', 46, 'Verified QR Check-In'],
            [4, 4, '2026-09-30 12:50:00', 'QR', 46, 'Verified QR Check-In'],
            [4, 6, '2026-09-30 12:58:00', 'QR', 46, 'Verified QR Check-In'],
            [5, 1, '2026-10-05 08:15:00', 'QR', 44, 'Verified QR Check-In'],
            [5, 3, '2026-10-05 08:22:00', 'QR', 44, 'Verified QR Check-In'],
            [5, 5, '2026-10-05 08:30:00', 'QR', 44, 'Verified QR Check-In'],
            [5, 7, '2026-10-05 08:35:00', 'QR', 44, 'Verified QR Check-In'],
            [7, 2, '2026-10-09 13:10:00', 'QR', 35, 'Verified QR Check-In'],
            [7, 3, '2026-10-09 13:15:00', 'QR', 35, 'Verified QR Check-In'],
            [7, 8, '2026-10-09 13:20:00', 'QR', 35, 'Verified QR Check-In'],
            [8, 2, '2026-10-06 08:45:00', 'QR', 38, 'Verified QR Check-In'],
            [8, 4, '2026-10-06 08:50:00', 'QR', 38, 'Verified QR Check-In'],
            [8, 5, '2026-10-06 08:55:00', 'QR', 38, 'Verified QR Check-In']
        ];
        $att_stmt = $conn->prepare("INSERT INTO `attendance_logs` (`event_id`, `user_id`, `check_in`, `method`, `logged_by`, `status`) VALUES (?, ?, ?, ?, ?, ?)");
        if ($att_stmt) {
            foreach ($att_samples as $at) {
                $att_stmt->bind_param('iissis', $at[0], $at[1], $at[2], $at[3], $at[4], $at[5]);
                $att_stmt->execute();
            }
            $att_stmt->close();
        }
    }

    // 8. Seed Multi-Tier Inter-Club Group Chats (Club General, Club Executive, Advisers & SSC)
    seedChatGroupsAndStarterMessages($conn, $messages, $errors);

    $messages[] = "✨ Database schemas patched, club metadata categorized, Faculty Advisers linked, authentic events seeded, and clean transactional tables ready for live user input.";
    return true;
}

// Helper: Seed Multi-Tier Inter-Club Group Chats and Starter Database Messages
function seedChatGroupsAndStarterMessages(mysqli $conn, array &$messages, array &$errors): bool {
    // 1. Ensure Institutional Advisers & SSC Group Chat exists without duplicating NULL club_id rows
    $sscRes = $conn->query("SELECT id FROM `chat_groups` WHERE `group_type` = 'advisers_ssc' ORDER BY id ASC");
    $sscGroupId = null;
    $dupSscIds = [];
    if ($sscRes) {
        while ($r = $sscRes->fetch_assoc()) {
            if ($sscGroupId === null) {
                $sscGroupId = (int)$r['id'];
            } else {
                $dupSscIds[] = (int)$r['id'];
            }
        }
    }
    if ($sscGroupId === null) {
        $conn->query("
            INSERT INTO `chat_groups` (`club_id`, `group_type`, `name`, `description`, `icon`)
            VALUES (NULL, 'advisers_ssc', 'Campus Advisers & Supreme Student Council', 'Official inter-communication channel for all club faculty advisers and the SSC leadership.', 'fa-building-columns')
        ");
        $sscGroupId = (int)$conn->insert_id;
    } else {
        $conn->query("
            UPDATE `chat_groups`
            SET `name` = 'Campus Advisers & Supreme Student Council',
                `description` = 'Official inter-communication channel for all club faculty advisers and the SSC leadership.',
                `icon` = 'fa-building-columns'
            WHERE `id` = {$sscGroupId}
        ");
        if (!empty($dupSscIds)) {
            $dupList = implode(',', $dupSscIds);
            $conn->query("UPDATE `chat_messages` SET `group_id` = {$sscGroupId} WHERE `group_id` IN ({$dupList})");
            $conn->query("DELETE FROM `chat_group_reads` WHERE `group_id` IN ({$dupList})");
            $conn->query("DELETE FROM `chat_groups` WHERE `id` IN ({$dupList})");
        }
    }

    // 2. Ensure each active club has both General GC (Adviser + Officers + All Members) and Executive GC (Adviser + Officers)
    $clubsRes = $conn->query("SELECT id, code, name FROM clubs WHERE status = 'Active'");
    if ($clubsRes) {
        $insGrp = $conn->prepare("
            INSERT INTO `chat_groups` (`club_id`, `group_type`, `name`, `description`, `icon`)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), icon=VALUES(icon)
        ");
        while ($c = $clubsRes->fetch_assoc()) {
            $cid = (int)$c['id'];
            $code = $c['code'];
            $cname = $c['name'];

            // General GC: Adviser + Officers + Members
            $genType = 'club_general';
            $genName = "{$code} • General Club Chat";
            $genDesc = "Official group chat for {$cname} faculty adviser, student officers, and all active members.";
            $genIcon = 'fa-comments';
            $insGrp->bind_param('issss', $cid, $genType, $genName, $genDesc, $genIcon);
            $insGrp->execute();

            // Executive GC: Adviser + Officers
            $execType = 'club_executive';
            $execName = "{$code} • Executive Board";
            $execDesc = "Leadership coordination channel for {$cname} faculty adviser and student officers.";
            $execIcon = 'fa-shield-halved';
            $insGrp->bind_param('issss', $cid, $execType, $execName, $execDesc, $execIcon);
            $insGrp->execute();
        }
        $insGrp->close();
    }

    // 3. Ensure a few active student members exist in CSSEC and sample clubs so all 3 roles are active
    $conn->query("
        UPDATE club_memberships cm
        JOIN clubs c ON c.id = cm.club_id
        SET cm.status = 'Active'
        WHERE c.code = 'CSSEC' AND cm.role = 'Member'
    ");
    // Ensure bsba.student is an active member of CSSEC for realistic multi-role testing
    $cssecIdRes = $conn->query("SELECT id FROM clubs WHERE code = 'CSSEC' LIMIT 1");
    if ($cssecIdRes && $row = $cssecIdRes->fetch_assoc()) {
        $cssecId = (int)$row['id'];
        $conn->query("
            INSERT INTO club_memberships (club_id, user_id, role, status, adviser_review, ssc_review)
            SELECT {$cssecId}, u.id, 'Member', 'Active', 'Endorsed', 'Endorsed'
            FROM users u
            WHERE u.username = 'bsba.student'
            ON DUPLICATE KEY UPDATE role = 'Member', status = 'Active', adviser_review = 'Endorsed', ssc_review = 'Endorsed'
        ");
    }

    // 4. Seed initial authentic starter messages in groups if not already present
    // A. Advisers & SSC Group
    if ($sscGroupId) {
        $chkMsg = $conn->query("SELECT id FROM chat_messages WHERE group_id = {$sscGroupId} LIMIT 1");
        if ($chkMsg && $chkMsg->num_rows === 0) {
            $sscUser = $conn->query("SELECT id FROM users WHERE role = 'ssc' LIMIT 1")->fetch_assoc();
            $advUser = $conn->query("SELECT id FROM users WHERE role = 'club_adviser' AND username = 'cssec.adviser' LIMIT 1")->fetch_assoc();
            if ($sscUser && $advUser) {
                $uidSsc = (int)$sscUser['id'];
                $uidAdv = (int)$advUser['id'];
                $conn->query("INSERT INTO chat_messages (sender_id, receiver_id, club_id, group_id, message, is_read, created_at) VALUES 
                    ({$uidSsc}, NULL, NULL, {$sscGroupId}, 'Good day respected faculty advisers and SSC council members! This institutional channel is dedicated to multi-tier campus coordination, activity approvals, and semester calendar synchronization.', 0, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
                    ({$uidAdv}, NULL, NULL, {$sscGroupId}, 'Acknowledged, Supreme Student Council! CSSEC and academic faculty advisers are prepared to coordinate upcoming activity schedules.', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR))");
            }
        }
    }

    // B. CSSEC General Chat & Executive Chat
    if (!empty($cssecId)) {
        // CSSEC General GC
        $cssecGenGrp = $conn->query("SELECT id FROM chat_groups WHERE club_id = {$cssecId} AND group_type = 'club_general' LIMIT 1")->fetch_assoc();
        if ($cssecGenGrp) {
            $gidGen = (int)$cssecGenGrp['id'];
            $chkGen = $conn->query("SELECT id FROM chat_messages WHERE group_id = {$gidGen} LIMIT 1");
            if ($chkGen && $chkGen->num_rows === 0) {
                $adv = $conn->query("SELECT id FROM users WHERE username = 'cssec.adviser' LIMIT 1")->fetch_assoc();
                $pres = $conn->query("SELECT id FROM users WHERE username = 'bsit.student' LIMIT 1")->fetch_assoc();
                $mem = $conn->query("SELECT id FROM users WHERE username = 'bsba.student' LIMIT 1")->fetch_assoc();
                if ($adv && $pres) {
                    $uAdv = (int)$adv['id'];
                    $uPres = (int)$pres['id'];
                    $conn->query("INSERT INTO chat_messages (sender_id, receiver_id, club_id, group_id, message, is_read, created_at) VALUES
                        ({$uAdv}, NULL, {$cssecId}, {$gidGen}, 'Welcome to the CSSEC General Club Chat! All active members, student officers, and faculty advisers can connect here for announcements and general organization discussions.', 0, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
                        ({$uPres}, NULL, {$cssecId}, {$gidGen}, 'Good day everyone! Welcome to all new and returning CSSEC members. Our upcoming general orientation details will be posted here soon.', 0, DATE_SUB(NOW(), INTERVAL 2 HOUR))");
                    if ($mem) {
                        $uMem = (int)$mem['id'];
                        $conn->query("INSERT INTO chat_messages (sender_id, receiver_id, club_id, group_id, message, is_read, created_at) VALUES
                            ({$uMem}, NULL, {$cssecId}, {$gidGen}, 'Hello Prof. Alex and Officers! Excited for this semester’s CSSEC activities!', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR))");
                    }
                }
            }
        }

        // CSSEC Executive GC
        $cssecExecGrp = $conn->query("SELECT id FROM chat_groups WHERE club_id = {$cssecId} AND group_type = 'club_executive' LIMIT 1")->fetch_assoc();
        if ($cssecExecGrp) {
            $gidExec = (int)$cssecExecGrp['id'];
            $chkExec = $conn->query("SELECT id FROM chat_messages WHERE group_id = {$gidExec} LIMIT 1");
            if ($chkExec && $chkExec->num_rows === 0) {
                $adv = $conn->query("SELECT id FROM users WHERE username = 'cssec.adviser' LIMIT 1")->fetch_assoc();
                $pres = $conn->query("SELECT id FROM users WHERE username = 'bsit.student' LIMIT 1")->fetch_assoc();
                if ($adv && $pres) {
                    $uAdv = (int)$adv['id'];
                    $uPres = (int)$pres['id'];
                    $conn->query("INSERT INTO chat_messages (sender_id, receiver_id, club_id, group_id, message, is_read, created_at) VALUES
                        ({$uAdv}, NULL, {$cssecId}, {$gidExec}, 'CSSEC Executive Board: Please finalize the activity proposal and budget breakdown for our upcoming tech symposium prior to submission.', 0, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
                        ({$uPres}, NULL, {$cssecId}, {$gidExec}, 'Noted Prof. Alex! The student executive committee has reviewed the venue requirements and the requisition is ready for endorsement.', 0, DATE_SUB(NOW(), INTERVAL 45 MINUTE))");
                }
            }
        }
    }

    $messages[] = "💬 Inter-club group chats (General Club GC, Executive Board GC, and Campus Advisers & SSC GC) successfully synchronized and seeded in database!";
    return true;
}

// Helper: Ensure Upload Folders
function ensureUploadDirectories(array $uploadDirs): void {
    foreach ($uploadDirs as $rel => $abs) {
        if (!is_dir($abs)) {
            @mkdir($abs, 0755, true);
        }
    }
}

// ------------------------------------------------------------
// ONLY PROCEED IF DIRECTLY ACCESSED AS STANDALONE SETUP WIZARD
// ------------------------------------------------------------
$is_standalone_setup = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'setup.php' || basename($_SERVER['PHP_SELF'] ?? '') === 'setup.php');
if (!$is_standalone_setup) {
    return;
}

// ------------------------------------------------------------
// POST ACTION DISPATCHER
// ------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // If locked, only authenticated admin can perform actions (or unlock)
    if ($is_locked && !$is_admin && !in_array($action, ['unlock_setup'])) {
        http_response_code(403);
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => 'Installer is locked. System Administrator authentication is required.']));
        }
        die('<div style="padding:40px;font-family:sans-serif;color:#991b1b;background:#fef2f2;border-radius:12px;max-width:600px;margin:50px auto;border:1px solid #f87171;"><h2>🔒 Setup Locked</h2><p>This system has finalized installation and is locked for security. Only authenticated System Administrators may modify database configurations or execute migrations.</p><p><a href="../auth/signin.php" style="color:#2563eb;font-weight:bold;">Sign In to System</a></p></div>');
    }

    // Action 0a: Lock Setup
    if ($action === 'lock_setup') {
        if ($is_admin || $has_installed_admin) {
            @touch($lockFile);
            $is_locked = true;
            $messages[] = "🔒 Setup has been finalized and locked to prevent unauthorized tampering.";
        }
    }

    // Action 0b: Unlock Setup
    if ($action === 'unlock_setup') {
        if ($is_admin) {
            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
            $is_locked = false;
            $messages[] = "🔓 Setup unlocked successfully. Migrations and configuration adjustments are now enabled.";
        } else {
            $errors[] = "Authentication required: Only an active System Administrator can unlock the installer.";
        }
    }

    // Action 1: Save & Test Database Connection Credentials
    if ($action === 'save_config') {
        $new_host = trim($_POST['db_host'] ?? '127.0.0.1');
        $new_port = (int)($_POST['db_port'] ?? 3306);
        $new_user = trim($_POST['db_user'] ?? 'root');
        $new_pass = $_POST['db_pass'] ?? '';
        $new_name = trim($_POST['db_name'] ?? 'sms_db');

        // Test connection
        $testConn = @new mysqli($new_host, $new_user, $new_pass, '', $new_port);
        if ($testConn->connect_error) {
            $errors[] = "Connection failed to {$new_host}:{$new_port} with user '{$new_user}': " . $testConn->connect_error;
        } else {
            // Auto-create database if not exists
            $testConn->query("CREATE DATABASE IF NOT EXISTS `{$new_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $testConn->close();

            // Also update .env file if it exists
            $envPath = dirname(__DIR__, 2) . '/.env';
            if (file_exists($envPath)) {
                $envContent = file_get_contents($envPath);
                $pairs = [
                    'DB_HOST' => $new_host,
                    'DB_PORT' => $new_port,
                    'DB_NAME' => $new_name,
                    'DB_USER' => $new_user,
                    'DB_PASS' => $new_pass,
                    'DB_DATABASE' => $new_name,
                    'DB_USERNAME' => $new_user,
                    'DB_PASSWORD' => $new_pass,
                ];
                foreach ($pairs as $k => $v) {
                    if (preg_match('/^' . $k . '=.*/m', $envContent)) {
                        $envContent = preg_replace('/^' . $k . '=.*/m', $k . '=' . $v, $envContent);
                    } else {
                        $envContent .= "\n{$k}={$v}";
                    }
                }
                @file_put_contents($envPath, $envContent);
            }

            $envFile = dirname(__DIR__, 2) . '/.env';
            $escaped_pass = addcslashes($new_pass, "\"\\");
            $envLines = [
                "DB_HOST={$new_host}",
                "DB_PORT={$new_port}",
                "DB_USER={$new_user}",
                "DB_PASS=\"{$escaped_pass}\"",
                "DB_NAME={$new_name}"
            ];
            @file_put_contents($envFile, implode("\n", $envLines) . "\n");

            putenv("DB_HOST={$new_host}");
            putenv("DB_PORT={$new_port}");
            putenv("DB_USER={$new_user}");
            putenv("DB_PASS={$new_pass}");
            putenv("DB_NAME={$new_name}");
            $_ENV['DB_HOST'] = $new_host;
            $_ENV['DB_PORT'] = (string)$new_port;
            $_ENV['DB_USER'] = $new_user;
            $_ENV['DB_PASS'] = $new_pass;
            $_ENV['DB_NAME'] = $new_name;

            // Cache profile for instant lookup in db.php
            $cacheFile = __DIR__ . '/.db_host_cache.json';
            @file_put_contents($cacheFile, json_encode([
                'host' => $new_host,
                'port' => $new_port,
                'user' => $new_user,
                'pass' => $new_pass,
                'db'   => $new_name
            ], JSON_UNESCAPED_SLASHES));

            $messages[] = "✅ Database connection successfully verified and saved for Railway and system modules!";

            $conn = @new mysqli($new_host, $new_user, $new_pass, $new_name, $new_port);
            if (!$conn->connect_error) {
                $db_connected = true;
                $db_error = null;
                $conn->set_charset('utf8mb4');
            }
            $_SESSION['db_override'] = [
                'host' => $new_host,
                'port' => $new_port,
                'user' => $new_user,
                'pass' => $new_pass,
                'name' => $new_name
            ];
        }
    }

    // Action 2: One-Click Automated Full Setup (Recommended for new devices)
    if ($action === 'full_setup') {
        if (!$db_connected && $conn->connect_error) {
            $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int)DB_PORT);
        }
        if ($conn->connect_error) {
            $errors[] = "Cannot perform full setup: MySQL connection failed (" . $conn->connect_error . "). Check database parameters.";
        } else {
            $db_connected = true;
            $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $conn->select_db(DB_NAME);
            $conn->set_charset('utf8mb4');

            $masterSql = dirname(__DIR__, 2) . '/database/sms_db.sql';
            if (executeSQLScript($conn, $masterSql, $errors)) {
                $rbacSql = dirname(__DIR__, 2) . '/database/modules/12_rbac_and_master_data.sql';
                if (file_exists($rbacSql)) {
                    executeSQLScript($conn, $rbacSql, $errors);
                }
                seedSystemAccountsAndClubs($conn, $messages, $errors);
                seedLiveSystemData($conn, $messages, $errors);
                ensureUploadDirectories($uploadDirs ?? [
                    'app/uploads/achievements/'   => dirname(__DIR__) . '/uploads/achievements/',
                    'app/uploads/applications/'   => dirname(__DIR__) . '/uploads/applications/',
                    'app/uploads/avatars/'        => dirname(__DIR__) . '/uploads/avatars/',
                    'app/uploads/signatures/'     => dirname(__DIR__) . '/uploads/signatures/',
                    'app/uploads/stamps/'         => dirname(__DIR__) . '/uploads/stamps/',
                ]);
                // Provision all 42 dedicated organization databases
                $org_res = provision_all_org_databases($conn);
                $org_ready = count(array_filter($org_res, fn($o) => $o['status'] === 'Ready'));
                $messages[] = "🎉 One-Click Full Setup Completed! All database tables, verified accounts (Admin, SSC, 16 Students, 40 Advisers), accredited clubs, and {$org_ready} dedicated organization databases are installed and ready for live user input.";
            }
        }
    }

    // Action 3: Initialize / Rebuild Tables Only
    if ($action === 'init_database') {
        if (!$db_connected && $conn->connect_error) {
            $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int)DB_PORT);
        }
        if ($conn->connect_error) {
            $errors[] = "Cannot initialize database: MySQL is not connected. Check connection parameters first.";
        } else {
            $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $conn->select_db(DB_NAME);
            $conn->set_charset('utf8mb4');

            $masterSql = dirname(__DIR__, 2) . '/database/sms_db.sql';
            if (executeSQLScript($conn, $masterSql, $errors)) {
                $rbacSql = dirname(__DIR__, 2) . '/database/modules/12_rbac_and_master_data.sql';
                if (file_exists($rbacSql)) {
                    executeSQLScript($conn, $rbacSql, $errors);
                }
                seedSystemAccountsAndClubs($conn, $messages, $errors);
                seedLiveSystemData($conn, $messages, $errors);
                $org_res = provision_all_org_databases($conn);
                $org_ready = count(array_filter($org_res, fn($o) => $o['status'] === 'Ready'));
                $messages[] = "🎉 Master database schemas, tables, accredited clubs, official accounts, and {$org_ready} dedicated organization databases successfully initialized with clean transactional tables!";
            }
        }
    }

    // Action 4: Seed Official System Accounts, Clubs & Live System Data
    if ($action === 'seed_demo') {
        if (!$db_connected) {
            $errors[] = "Cannot seed data: MySQL is not connected.";
        } else {
            seedSystemAccountsAndClubs($conn, $messages, $errors);
            seedLiveSystemData($conn, $messages, $errors);
        }
    }

    // Action 6: Provision / Resync All Dedicated Organization Databases
    if ($action === 'provision_org_dbs') {
        if (!$db_connected) {
            $errors[] = "Cannot provision organization databases: MySQL is not connected.";
        } else {
            $org_res = provision_all_org_databases($conn);
            $org_ready = count(array_filter($org_res, fn($o) => $o['status'] === 'Ready'));
            $messages[] = "🏢 Dedicated Organization Databases Provisioned: {$org_ready} of " . count($org_res) . " organization databases are online and synchronized with categorized tables!";
        }
    }

    // Action 7: Wipe All Simulation Data (Clean Slate for Real User Input)
    if ($action === 'wipe_simulation') {
        if (!$db_connected) {
            $errors[] = "Cannot wipe simulation data: MySQL is not connected.";
        } else {
            require_once __DIR__ . '/org_db_manager.php';
            try {
                // 1. Wipe master database transactional tables
                $conn->query("SET FOREIGN_KEY_CHECKS = 0;");
                $tables_to_wipe = [
                    'events',
                    'event_registrations',
                    'budget_requests',
                    'attendance_logs',
                    'achievements',
                    'org_announcements',
                    'notifications',
                    'elections',
                    'election_candidates',
                    'election_votes',
                    'club_applications',
                    'audit_logs',
                    'ai_recommendation_logs'
                ];
                foreach ($tables_to_wipe as $tbl) {
                    $chk = $conn->query("SHOW TABLES LIKE '$tbl'");
                    if ($chk && $chk->num_rows > 0) {
                        $conn->query("TRUNCATE TABLE `$tbl`");
                    }
                }
                // Clear any non-adviser dummy memberships
                $conn->query("DELETE FROM club_memberships WHERE role != 'Adviser'");
                $conn->query("SET FOREIGN_KEY_CHECKS = 1;");

                // 2. Wipe simulation data across all dedicated org databases
                $c_res = $conn->query("SELECT id, code FROM clubs WHERE deleted_at IS NULL");
                $w_cnt = 0;
                if ($c_res) {
                    while ($cl = $c_res->fetch_assoc()) {
                        $org_c = get_org_db_connection($cl['code']);
                        if ($org_c) {
                            $org_c->query("SET FOREIGN_KEY_CHECKS = 0;");
                            foreach (['org_events', 'org_attendance', 'org_budgets', 'org_achievements', 'org_announcements', 'org_audit_trail'] as $tbl) {
                                $org_c->query("TRUNCATE TABLE `$tbl`");
                            }
                            $org_c->query("DELETE FROM org_members WHERE role != 'Adviser'");
                            $org_c->query("SET FOREIGN_KEY_CHECKS = 1;");
                            $org_c->close();
                            $w_cnt++;
                        }
                    }
                }
                $messages[] = "🧹 All simulation, testing, and hardcoded data has been completely wiped across master and all {$w_cnt} organization databases! System is in a clean slate ready for authentic database-driven user input.";
            } catch (Throwable $e) {
                $errors[] = "Failed to wipe simulation data: " . $e->getMessage();
            }
        }
    }

    // Action 8: Patch & Synchronize Database Schemas & Multi-Stage Workflows
    if ($action === 'patch_schemas') {
        if (!$db_connected) {
            $errors[] = "Cannot patch schemas: MySQL is not connected.";
        } else {
            patchSystemDatabaseSchemas($conn, $messages, $errors);
        }
    }
}

// ------------------------------------------------------------
// GATHER SYSTEM & TABLES TELEMETRY
// ------------------------------------------------------------
$expected_tables = [
    'users'                     => 'Identity, Authentication & 4 Core Roles (student, adviser, ssc, admin)',
    'students'                  => 'Student Directory, Academic Profiles & Program Mapping',
    'academic_programs'         => 'Academic Degree Programs, Colleges & Department Directory',
    'clubs'                     => 'Accredited Campus Student Organizations & Academic Programs',
    'club_memberships'          => 'Active Roster, Leadership Roles & Advisers',
    'club_applications'         => 'Student Application Submissions & Endorsement Letters',
    'events'                    => 'Campus Events, Activity Lifecycle & SSC/Admin Endorsements',
    'event_registrations'       => 'Pre-Registrations & Attendee Participation Roster',
    'budget_requests'           => 'Multi-Tier Budget Proposals (Adviser → SSC → Admin Approval)',
    'attendance_logs'           => 'QR Code, RFID, Self-Check-in & Manual Verification Logs',
    'elections'                 => 'Digital Elections Management & Timeline Control',
    'election_candidates'       => 'Candidate Profiles, Platforms & Taglines',
    'election_votes'            => 'Audited, Tamper-Resistant Digital Ballots',
    'achievements'              => 'External Competitions, Awards & Verification System',
    'org_announcements'         => 'Targeted Club Announcements & Urgent Broadcasts',
    'notification_templates'    => 'Official Notification Templates, System Notices & Standard Layouts',
    'notifications'             => 'System-Wide Alert & Communication Center',
    'audit_logs'                => 'Security, Access & Action Audit Trail',
    'ai_recommendation_logs'    => 'AI Telemetry & Co-Curricular Analytics',
    'system_settings'           => 'System Configurations & Integration API Keys',
    'profile_photos'            => 'User Uploaded Profile Pictures & Avatars History',
    'roles'                     => 'Database-Driven RBAC Roles (student, adviser, ssc, admin)',
    'permissions'               => 'Granular Module & Action Permissions Catalog',
    'role_permissions'          => 'Role-to-Permission Mapping Matrix',
    'user_roles'                => 'User-to-Role Granular Assignment Ledger',
    'mfa_codes'                 => 'Multi-Factor Authentication (Email Verification Codes & OTP Security)'
];

$existing_tables = [];
$table_counts = [];
if ($db_connected && $conn) {
    try {
        $res = $conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_TYPE = 'BASE TABLE'");
        if ($res) {
            while ($row = $res->fetch_row()) {
                $existing_tables[] = $row[0];
                try {
                    $cnt = $conn->query("SELECT COUNT(*) FROM `{$row[0]}`");
                    $table_counts[$row[0]] = $cnt ? (int)$cnt->fetch_row()[0] : 0;
                } catch (Throwable $e) {
                    $table_counts[$row[0]] = 0;
                }
            }
        }
    } catch (Throwable $e) {
        $existing_tables = [];
    }
}

// Organization Dedicated Databases Telemetry
$org_dbs_telemetry = [];
$total_org_dbs_ready = 0;
if ($db_connected && $conn && in_array('clubs', $existing_tables)) {
    try {
        $c_res = $conn->query("SELECT id, code, name FROM clubs WHERE deleted_at IS NULL ORDER BY code ASC");
        if ($c_res) {
            while ($c = $c_res->fetch_assoc()) {
                $expectedDb = get_org_db_name($c['code']);
                $dbCheck = $conn->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$expectedDb}'");
                $exists = ($dbCheck && $dbCheck->num_rows > 0);
                $tableCount = 0;
                if ($exists) {
                    $tRes = $conn->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$expectedDb}'");
                    $tableCount = $tRes ? (int)$tRes->fetch_row()[0] : 0;
                    if ($tableCount >= 8) {
                        $total_org_dbs_ready++;
                    }
                }
                $org_dbs_telemetry[] = [
                    'id'       => (int)$c['id'],
                    'code'     => $c['code'],
                    'name'     => $c['name'],
                    'database' => $expectedDb,
                    'exists'   => $exists,
                    'tables'   => $tableCount
                ];
            }
        }
    } catch (Throwable $e) {
        $org_dbs_telemetry = [];
    }
}

// Uploads directories check
$uploadDirs = [
    'app/uploads/achievements/'   => dirname(__DIR__) . '/uploads/achievements/',
    'app/uploads/applications/'   => dirname(__DIR__) . '/uploads/applications/',
    'app/uploads/avatars/'        => dirname(__DIR__) . '/uploads/avatars/',
    'app/uploads/signatures/'     => dirname(__DIR__) . '/uploads/signatures/',
    'app/uploads/stamps/'         => dirname(__DIR__) . '/uploads/stamps/',
];
ensureUploadDirectories($uploadDirs);

// Network and LAN IP Detection
$hostName = gethostname();
$lanIp = gethostbyname($hostName);
$serverPort = $_SERVER['SERVER_PORT'] ?? 80;
$portSuffix = ($serverPort != 80 && $serverPort != 443) ? ":{$serverPort}" : "";
$proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';

$scriptWebDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
$webBase = '/' . trim(str_replace('\\', '/', $scriptWebDir), '/');
$webBase = ($webBase === '/' || $webBase === '') ? '/' : "{$webBase}/";

$localUrl = "{$proto}://localhost{$portSuffix}{$webBase}";
$lanUrl   = "{$proto}://{$lanIp}{$portSuffix}{$webBase}";
$setupLanUrl = "{$proto}://{$lanIp}{$portSuffix}{$webBase}app/shared/setup.php";

$totalExpected = count($expected_tables);
$installedCount = count(array_intersect(array_keys($expected_tables), $existing_tables));
$allInstalled = ($installedCount === $totalExpected);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SMS Database Setup &amp; Deployment Wizard</title>
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet"/>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <!-- Modern Centered System Notification & Decision Engine -->
  <script src="../js/system-notifications.js?v=<?= filemtime(__DIR__ . '/../js/system-notifications.js') ?>"></script>
  <!-- QR Code Generator -->
  <script src="../../app/js/qrcode.min.js"></script>

  <style>
    :root {
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --primary-subtle: #eff6ff;
      --primary-border: #bfdbfe;
      --secondary: #475569;
      --success: #10b981;
      --success-subtle: #ecfdf5;
      --warning: #f59e0b;
      --warning-subtle: #fffbeb;
      --danger: #ef4444;
      --danger-subtle: #fef2f2;
      --bg: #f8fafc;
      --card-bg: #ffffff;
      --border: #e2e8f0;
      --text: #0f172a;
      --text-muted: #64748b;
      --radius: 14px;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
      --shadow-md: 0 4px 14px rgba(0,0,0,0.08);
      --shadow-lg: 0 10px 30px rgba(0,0,0,0.1);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.5;
      padding: 40px 20px;
    }

    .container {
      max-width: 1040px;
      margin: 0 auto;
    }

    /* Header */
    .header-banner {
      background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
      border-radius: var(--radius);
      padding: 32px 36px;
      color: white;
      box-shadow: var(--shadow-md);
      margin-bottom: 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 20px;
    }
    .header-title h1 {
      font-size: 1.75rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .header-title p {
      color: #bfdbfe;
      font-size: 0.95rem;
      margin-top: 6px;
    }
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(10px);
      padding: 8px 16px;
      border-radius: 9999px;
      font-size: 0.88rem;
      font-weight: 600;
      border: 1px solid rgba(255, 255, 255, 0.25);
    }
    .status-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
    }
    .status-dot.online { background: #34d399; box-shadow: 0 0 10px #34d399; }
    .status-dot.offline { background: #f87171; box-shadow: 0 0 10px #f87171; }

    /* Alerts */
    .alert {
      padding: 16px 20px;
      border-radius: 10px;
      margin-bottom: 20px;
      font-size: 0.92rem;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .alert-success { background: var(--success-subtle); color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: var(--danger-subtle); color: #991b1b; border: 1px solid #fecaca; }

    /* Grid Layout */
    .grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
      margin-bottom: 24px;
    }
    @media (max-width: 860px) {
      .grid-2 { grid-template-columns: 1fr; }
    }

    /* Cards */
    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 24px;
      box-shadow: var(--shadow-sm);
    }
    .card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 20px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--border);
    }
    .card-header h3 {
      font-size: 1.15rem;
      font-weight: 700;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* Forms */
    .form-group {
      margin-bottom: 16px;
    }
    .form-group label {
      display: block;
      font-size: 0.82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      margin-bottom: 6px;
    }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.9rem;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: #f8fafc;
      color: var(--text);
      transition: all 0.2s ease;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      background: white;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s;
      border: 1px solid transparent;
    }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-success { background: var(--success); color: white; }
    .btn-success:hover { background: #059669; }
    .btn-secondary { background: white; border-color: var(--border); color: var(--text); }
    .btn-secondary:hover { background: #f1f5f9; }
    .btn-block { width: 100%; }

    /* Tables Grid */
    .table-list {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }
    .table-list th, .table-list td {
      padding: 12px 14px;
      font-size: 0.88rem;
      text-align: left;
      border-bottom: 1px solid #f1f5f9;
    }
    .table-list th {
      background: #f8fafc;
      color: var(--text-muted);
      font-weight: 700;
      text-transform: uppercase;
      font-size: 0.76rem;
      letter-spacing: 0.5px;
    }
    .table-list tr:hover td {
      background: #f8fafc;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 0.78rem;
      font-weight: 700;
    }
    .badge-success { background: #dcfce7; color: #166534; }
    .badge-danger  { background: #fee2e2; color: #991b1b; }
    .badge-info    { background: #e0f2fe; color: #0369a1; }

    /* Network URL box */
    .net-box {
      background: #f1f5f9;
      border: 1px dashed #cbd5e1;
      border-radius: 10px;
      padding: 14px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .net-url {
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.95rem;
      font-weight: 600;
      color: #1e3a8a;
      word-break: break-all;
    }
    .copy-btn {
      padding: 6px 12px;
      font-size: 0.8rem;
      background: white;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
    }
    .copy-btn:hover { background: #e2e8f0; }

    /* QR Code Display */
    .qr-container {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 16px;
      background: white;
      border: 1px solid var(--border);
      border-radius: 12px;
      margin-top: 14px;
    }
    #qrcode img {
      margin: 0 auto;
    }

    /* Accounts Grid */
    .accounts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
      margin-top: 14px;
    }
    .account-card {
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 14px;
    }
    .account-role {
      font-size: 0.76rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 6px;
    }
    .account-cred {
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.86rem;
      color: #334155;
    }
  </style>
</head>
<body>

<div class="container">

  <!-- Header Banner -->
  <div class="header-banner">
    <div class="header-title">
      <h1><i class="fa-solid fa-server"></i> SMS Database Setup &amp; Deployment</h1>
      <p>Configure local database, run schema migrations, or deploy to other devices on the network.</p>
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
      <?php if ($db_connected): ?>
        <div class="status-pill"><span class="status-dot online"></span> MySQL Connected (<?= htmlspecialchars(DB_NAME) ?>)</div>
      <?php else: ?>
        <div class="status-pill"><span class="status-dot offline"></span> MySQL Disconnected</div>
      <?php endif; ?>
      <?php if ($is_locked): ?>
        <div class="status-pill" style="background: rgba(239, 68, 68, 0.25); border-color: rgba(239, 68, 68, 0.5);"><i class="fa-solid fa-lock text-danger"></i> Installer Locked</div>
      <?php else: ?>
        <div class="status-pill" style="background: rgba(16, 185, 129, 0.25); border-color: rgba(16, 185, 129, 0.5);"><i class="fa-solid fa-lock-open text-success"></i> Installer Unlocked</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Messages & Errors -->
  <?php foreach ($messages as $msg): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <!-- Two Column Layout: DB Configuration & Network Access -->
  <div class="grid-2">

    <!-- Column 1: Database Settings Form -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fa-solid fa-sliders text-primary"></i> Database Parameters</h3>
        <span class="badge badge-info"><?= $db_connected ? 'Active' : 'Action Required' ?></span>
      </div>
      <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 16px;">
        Adjust host, user, password, or port if running on a separate machine, remote server, or custom XAMPP setup.
      </p>

      <form method="POST">
        <input type="hidden" name="action" value="save_config"/>
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>Database Host</label>
            <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars(defined('DB_HOST') ? DB_HOST : '127.0.0.1') ?>" required/>
          </div>
          <div class="form-group">
            <label>Port</label>
            <input type="number" name="db_port" class="form-control" value="<?= htmlspecialchars(defined('DB_PORT') ? DB_PORT : 3306) ?>" required/>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>User</label>
            <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars(defined('DB_USER') ? DB_USER : 'root') ?>" required/>
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="db_pass" class="form-control" value="<?= htmlspecialchars(defined('DB_PASS') ? DB_PASS : '') ?>" placeholder="Empty if default XAMPP"/>
          </div>
        </div>

        <div class="form-group">
          <label>Database Name</label>
          <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'sms_db') ?>" required/>
        </div>

        <button type="submit" class="btn btn-primary btn-block">
          <i class="fa-solid fa-floppy-disk"></i> Test &amp; Save Configuration
        </button>
      </form>
    </div>

    <!-- Column 2: Multi-Device & Mobile Access -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fa-solid fa-network-wired text-primary"></i> Multi-Device &amp; LAN Access</h3>
        <span class="badge badge-success"><i class="fa-solid fa-wifi"></i> LAN Ready</span>
      </div>
      <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 12px;">
        To open this SMS portal from other phones, tablets, or laptops on the same Wi-Fi:
      </p>

      <div class="net-box">
        <div>
          <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">NETWORK ACCESS URL:</div>
          <div class="net-url" id="lanUrlText"><?= htmlspecialchars($lanUrl) ?></div>
        </div>
        <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($lanUrl) ?>')">
          <i class="fa-regular fa-copy"></i> Copy
        </button>
      </div>

      <!-- QR Code Container -->
      <div class="qr-container">
        <div id="qrcode"></div>
        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 10px; font-weight: 600;">
          <i class="fa-solid fa-qrcode"></i> Scan with phone camera to connect instantly
        </div>
      </div>
    </div>

  </div>

  <!-- Schema Installation & Seeding Controls -->
  <div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
      <h3><i class="fa-solid fa-database text-primary"></i> Schema Installation &amp; Seed Actions</h3>
      <div>
        <span class="badge <?= $allInstalled ? 'badge-success' : 'badge-danger' ?>">
          <?= $installedCount ?> / <?= $totalExpected ?> Tables Initialized
        </span>
      </div>
    </div>

    <!-- ONE-CLICK AUTOMATED FULL SETUP HERO BANNER -->
    <div style="background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%); color: white; padding: 22px 24px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 14px rgba(37,99,235,0.25);">
      <div>
        <div style="display: flex; align-items: center; gap: 10px; font-size: 1.15rem; font-weight: 800;">
          <i class="fa-solid fa-wand-magic-sparkles"></i> 🚀 One-Click Automated Full Setup
          <span style="font-size: 0.72rem; background: rgba(255,255,255,0.25); padding: 3px 10px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.5px;">Recommended for New Devices</span>
        </div>
        <p style="font-size: 0.88rem; color: #dbeafe; margin-top: 6px; max-width: 660px; line-height: 1.45;">
          Zero-configuration automated installer. Automatically creates database <code><?= htmlspecialchars(DB_NAME) ?></code> if missing, executes master schema, seeds verified accounts &amp; clubs from <code>SYSTEM_ACCOUNTS.md</code>, and provisions file storage in one click.
        </p>
      </div>
      <div>
        <form method="POST" style="margin: 0;">
          <input type="hidden" name="action" value="full_setup"/>
          <button type="submit" class="btn" style="background: #ffffff; color: #1e3a8a; font-weight: 800; font-size: 0.96rem; padding: 13px 24px; border-radius: 9px; box-shadow: 0 4px 12px rgba(0,0,0,0.18);" onclick="event.preventDefault(); window.showConfirmModal('Run One-Click Full Setup?', 'Do you want to run one-click full setup? This will automatically initialize master database tables, seed verified accounts, and provision accredited clubs.', { type: 'decision', confirmText: 'Yes, Run Full Setup' }).then(yes => { if(yes) this.form.submit(); });">
            <i class="fa-solid fa-bolt" style="color: #f59e0b;"></i> Run One-Click Full Setup
          </button>
        </form>
      </div>
    </div>

    <!-- GRANULAR CONTROLS -->
    <div style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center; justify-content: space-between; background: #f8fafc; padding: 18px; border-radius: 10px; border: 1px solid var(--border);">
      <div>
        <h4 style="font-size: 0.98rem; font-weight: 700; margin-bottom: 4px;">Granular Setup &amp; Maintenance Tools</h4>
        <p style="font-size: 0.84rem; color: var(--text-muted);">
          Perform individual schema migrations, seed verified accounts, update templates, or toggle security lock.
        </p>
      </div>
      <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <form method="POST" style="display: inline-block;">
          <input type="hidden" name="action" value="init_database"/>
          <button type="submit" class="btn btn-primary" onclick="event.preventDefault(); window.showConfirmModal('Initialize / Synchronize Tables?', 'Do you want to initialize and synchronize all <?= $totalExpected ?> database tables?', { type: 'info', confirmText: 'Yes, Initialize Tables' }).then(yes => { if(yes) this.form.submit(); });">
            <i class="fa-solid fa-rocket"></i> <?= $allInstalled ? "Re-Sync {$totalExpected} Tables" : "Install All {$totalExpected} Tables" ?>
          </button>
        </form>

        <form method="POST" style="display: inline-block;">
          <input type="hidden" name="action" value="patch_schemas"/>
          <button type="submit" class="btn btn-secondary" onclick="event.preventDefault(); window.showConfirmModal('Patch Schemas & Workflows?', 'Do you want to apply schema and multi-stage workflow column patches?', { type: 'info', confirmText: 'Yes, Patch Schemas' }).then(yes => { if(yes) this.form.submit(); });">
            <i class="fa-solid fa-screwdriver-wrench text-info"></i> Patch Workflows &amp; Schemas
          </button>
        </form>

        <form method="POST" style="display: inline-block;">
          <input type="hidden" name="action" value="seed_demo"/>
          <button type="submit" class="btn btn-secondary" onclick="event.preventDefault(); window.showConfirmModal('Seed Official Accounts?', 'Do you want to seed official system accounts (Admin, SSC, 16 Students, 40 Advisers) and accredited club master data?', { type: 'info', confirmText: 'Yes, Seed Accounts' }).then(yes => { if(yes) this.form.submit(); });">
            <i class="fa-solid fa-users text-primary"></i> Seed Official Accounts &amp; Master Data
          </button>
        </form>

        <form method="POST" style="display: inline-block;">
          <input type="hidden" name="action" value="provision_org_dbs"/>
          <button type="submit" class="btn btn-secondary" onclick="event.preventDefault(); window.showConfirmModal('Provision Dedicated Org Databases?', 'Do you want to provision and sync all dedicated organization databases?', { type: 'info', confirmText: 'Yes, Provision Org DBs' }).then(yes => { if(yes) this.form.submit(); });">
            <i class="fa-solid fa-server text-success"></i> Provision / Resync Org DBs
          </button>
        </form>

        <form method="POST" style="display: inline-block;">
          <input type="hidden" name="action" value="wipe_simulation"/>
          <button type="submit" class="btn btn-secondary" style="color: #b91c1c; border-color: #fca5a5;" onclick="event.preventDefault(); window.showConfirmModal('Wipe All Simulation Data?', 'Do you want to wipe all simulation events, budgets, attendance, achievements, announcements, and mock memberships across master and all 42 organization databases?', { type: 'error', danger: true, confirmText: 'Yes, Wipe Simulation Data' }).then(yes => { if(yes) this.form.submit(); });">
            <i class="fa-solid fa-broom text-danger"></i> Wipe All Simulation Data
          </button>
        </form>


        <?php if ($is_locked): ?>
          <form method="POST" style="display: inline-block;">
            <input type="hidden" name="action" value="unlock_setup"/>
            <button type="submit" class="btn btn-secondary" onclick="event.preventDefault(); window.showConfirmModal('Unlock Setup?', 'Do you want to unlock the installer to enable schema migrations and adjustments?', { type: 'warning', confirmText: 'Yes, Unlock Setup' }).then(yes => { if(yes) this.form.submit(); });">
              <i class="fa-solid fa-lock-open text-warning"></i> Unlock Setup
            </button>
          </form>
        <?php else: ?>
          <form method="POST" style="display: inline-block;">
            <input type="hidden" name="action" value="lock_setup"/>
            <button type="submit" class="btn btn-secondary" onclick="event.preventDefault(); window.showConfirmModal('Lock Setup?', 'Do you want to lock the setup to prevent unauthorized changes or migrations?', { type: 'warning', confirmText: 'Yes, Lock Setup' }).then(yes => { if(yes) this.form.submit(); });">
              <i class="fa-solid fa-lock text-primary"></i> Lock Setup
            </button>
          </form>
        <?php endif; ?>

        <a href="../auth/signin.php" class="btn btn-success">
          <i class="fa-solid fa-right-to-bracket"></i> Go to Sign In
        </a>
      </div>
    </div>

    <!-- Table Health Status -->
    <h4 style="font-size: 0.95rem; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">Component Tables Status</h4>
    <div style="overflow-x: auto;">
      <table class="table-list">
        <thead>
          <tr>
            <th>Table Name</th>
            <th>Component Description</th>
            <th>Row Count</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expected_tables as $tbl => $desc): ?>
            <?php
              $is_installed = in_array($tbl, $existing_tables);
              $cnt = $table_counts[$tbl] ?? 0;
            ?>
            <tr>
              <td><strong style="font-family: 'JetBrains Mono', monospace;"><?= htmlspecialchars($tbl) ?></strong></td>
              <td style="color: #475569;"><?= htmlspecialchars($desc) ?></td>
              <td><span style="font-family: 'JetBrains Mono', monospace; font-weight: 600;"><?= $is_installed ? number_format($cnt) : '-' ?></span></td>
              <td>
                <?php if ($is_installed): ?>
                  <span class="badge badge-success"><i class="fa-solid fa-check"></i> Installed</span>
                <?php else: ?>
                  <span class="badge badge-danger"><i class="fa-solid fa-xmark"></i> Missing</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Dedicated Organization Databases Card -->
  <div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
      <h3><i class="fa-solid fa-server text-primary"></i> Dedicated Organization Databases (42 Databases)</h3>
      <div style="display: flex; align-items: center; gap: 10px;">
        <span class="badge <?= $total_org_dbs_ready === count($org_dbs_telemetry) ? 'badge-success' : 'badge-info' ?>">
          <?= $total_org_dbs_ready ?> / <?= count($org_dbs_telemetry) ?> Org DBs Online
        </span>
      </div>
    </div>
    <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 16px;">
      Each accredited student organization has its own isolated, categorized MySQL database (<code>sms_org_*</code>) housing 8 categorized tables: <code>org_profile</code>, <code>org_members</code>, <code>org_events</code>, <code>org_attendance</code>, <code>org_budgets</code>, <code>org_achievements</code>, <code>org_announcements</code>, and <code>org_audit_trail</code>.
    </p>
    <div style="overflow-x: auto; max-height: 380px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px;">
      <table class="table-list" style="margin-top: 0;">
        <thead>
          <tr style="position: sticky; top: 0; background: #f8fafc; z-index: 2;">
            <th>Code</th>
            <th>Organization Name</th>
            <th>Dedicated Database</th>
            <th>Tables</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($org_dbs_telemetry)): ?>
            <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No clubs found in master database. Run One-Click Full Setup first.</td></tr>
          <?php else: ?>
            <?php foreach ($org_dbs_telemetry as $odb): ?>
              <tr>
                <td><strong style="font-family: 'JetBrains Mono', monospace; color: #1e3a8a;"><?= htmlspecialchars($odb['code']) ?></strong></td>
                <td><?= htmlspecialchars($odb['name']) ?></td>
                <td><code style="font-family: 'JetBrains Mono', monospace; font-size: 0.84rem; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($odb['database']) ?></code></td>
                <td><span style="font-family: 'JetBrains Mono', monospace; font-weight: 600;"><?= $odb['tables'] ?>/8</span></td>
                <td>
                  <?php if ($odb['exists'] && $odb['tables'] >= 8): ?>
                    <span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> Online</span>
                  <?php elseif ($odb['exists']): ?>
                    <span class="badge badge-info"><i class="fa-solid fa-triangle-exclamation"></i> Partial (<?= $odb['tables'] ?>)</span>
                  <?php else: ?>
                    <span class="badge badge-danger"><i class="fa-solid fa-circle-xmark"></i> Not Created</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge badge-info" style="font-size:0.75rem;">Provisioned</span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Official System Accounts Directory (Recognized by Database) -->
  <div class="card">
    <div class="card-header">
      <h3><i class="fa-solid fa-id-card-clip text-primary"></i> Official System Accounts Directory</h3>
      <span class="badge badge-success"><i class="fa-solid fa-check-double"></i> SYSTEM_ACCOUNTS.md Verified</span>
    </div>
    <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 16px;">
      These are the official system accounts recognized by the database across Central Admin, SSC Governance, 16 Academic Programs, and 40 Campus Student Organizations. Use the copy buttons for quick sign-in.
    </p>

    <!-- Quick Password Reference Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-bottom: 20px;">
      <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 12px 14px;">
        <span style="font-size: 0.74rem; font-weight: 800; color: #1d4ed8; text-transform: uppercase;">Central Admin Password</span>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
          <code style="font-size: 0.92rem; font-weight: 700; color: #1e3a8a;"><?= htmlspecialchars($admin_plain) ?></code>
          <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($admin_plain, ENT_QUOTES) ?>')"><i class="fa-regular fa-copy"></i></button>
        </div>
      </div>
      <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px 14px;">
        <span style="font-size: 0.74rem; font-weight: 800; color: #b45309; text-transform: uppercase;">SSC Officer Password</span>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
          <code style="font-size: 0.92rem; font-weight: 700; color: #78350f;"><?= htmlspecialchars($ssc_plain) ?></code>
          <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($ssc_plain, ENT_QUOTES) ?>')"><i class="fa-regular fa-copy"></i></button>
        </div>
      </div>
      <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 14px;">
        <span style="font-size: 0.74rem; font-weight: 800; color: #15803d; text-transform: uppercase;">Student Password (16 Programs)</span>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
          <code style="font-size: 0.92rem; font-weight: 700; color: #14532d;"><?= htmlspecialchars($std_plain) ?></code>
          <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($std_plain, ENT_QUOTES) ?>')"><i class="fa-regular fa-copy"></i></button>
        </div>
      </div>
      <div style="background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 10px; padding: 12px 14px;">
        <span style="font-size: 0.74rem; font-weight: 800; color: #7e22ce; text-transform: uppercase;">Faculty Adviser Password (40 Orgs)</span>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
          <code style="font-size: 0.92rem; font-weight: 700; color: #581c87;"><?= htmlspecialchars($adv_plain) ?></code>
          <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($adv_plain, ENT_QUOTES) ?>')"><i class="fa-regular fa-copy"></i></button>
        </div>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <div style="display: flex; gap: 8px; border-bottom: 2px solid var(--border); margin-bottom: 18px; flex-wrap: wrap;">
      <button type="button" id="tabBtnAdmin" class="btn" style="border-radius: 8px 8px 0 0; background: #2563eb; color: white;" onclick="switchAccountTab('admin')">
        <i class="fa-solid fa-crown"></i> Central Admin &amp; SSC (2)
      </button>
      <button type="button" id="tabBtnStudents" class="btn btn-secondary" style="border-radius: 8px 8px 0 0;" onclick="switchAccountTab('students')">
        <i class="fa-solid fa-graduation-cap"></i> Program Students (16)
      </button>
      <button type="button" id="tabBtnAdvisers" class="btn btn-secondary" style="border-radius: 8px 8px 0 0;" onclick="switchAccountTab('advisers')">
        <i class="fa-solid fa-chalkboard-user"></i> Faculty Club Advisers (40)
      </button>
    </div>

    <!-- TAB 1: Central Admin & SSC -->
    <div id="tabContentAdmin">
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
        <div class="account-card" style="border-top: 4px solid #ef4444; background: white;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span class="account-role" style="color: #dc2626;"><i class="fa-solid fa-shield-halved"></i> Institutional Administrator</span>
            <span class="badge badge-danger">Role: admin</span>
          </div>
          <div style="font-size: 1.05rem; font-weight: 700; color: #0f172a; margin-bottom: 6px;">System Admin</div>
          <div class="account-cred" style="margin-bottom: 4px;">Username: <strong>scc.admin</strong> <button type="button" class="copy-btn" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copyText('scc.admin')"><i class="fa-regular fa-copy"></i></button></div>
          <div class="account-cred" style="margin-bottom: 4px;">Email: <strong>pangilinanarisguillersaret@gmail.com</strong></div>
          <div class="account-cred" style="margin-bottom: 6px;">Password: <strong><?= htmlspecialchars($admin_plain) ?></strong> <button type="button" class="copy-btn" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copyText('<?= htmlspecialchars($admin_plain, ENT_QUOTES) ?>')"><i class="fa-regular fa-copy"></i></button></div>
          <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px; border-top: 1px dashed var(--border); padding-top: 6px;">
            Full governance, institutional oversight, system settings, and user management.
          </div>
        </div>

        <div class="account-card" style="border-top: 4px solid #f59e0b; background: white;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span class="account-role" style="color: #d97706;"><i class="fa-solid fa-award"></i> Student Council Governance</span>
            <span class="badge badge-info" style="background: #fef3c7; color: #92400e;">Role: ssc</span>
          </div>
          <div style="font-size: 1.05rem; font-weight: 700; color: #0f172a; margin-bottom: 6px;">SSC Officer</div>
          <div class="account-cred" style="margin-bottom: 4px;">Username: <strong>ssc.officer</strong> <button type="button" class="copy-btn" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copyText('ssc.officer')"><i class="fa-regular fa-copy"></i></button></div>
          <div class="account-cred" style="margin-bottom: 4px;">Email: <strong>pangilinanarisguillersaret@gmail.com</strong></div>
          <div class="account-cred" style="margin-bottom: 6px;">Password: <strong><?= htmlspecialchars($ssc_plain) ?></strong> <button type="button" class="copy-btn" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copyText('<?= htmlspecialchars($ssc_plain, ENT_QUOTES) ?>')"><i class="fa-regular fa-copy"></i></button></div>
          <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px; border-top: 1px dashed var(--border); padding-top: 6px;">
            Inter-club activity approvals, budget allocations, achievement endorsements, and campus elections.
          </div>
        </div>
      </div>
    </div>

    <!-- TAB 2: Students by Academic Program (16 Programs) -->
    <div id="tabContentStudents" style="display: none;">
      <div style="overflow-x: auto;">
        <table class="table-list">
          <thead>
            <tr>
              <th>Program</th>
              <th>Student Name</th>
              <th>Student No.</th>
              <th>Username</th>
              <th>Email</th>
              <th>Year &amp; Section</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $official_students_view = [
              ['BSIT', 'Juan Santos', '2024-10001', 'bsit.student', 'bsit@student.bcp.edu.ph', '2nd Year (IT-2A)'],
              ['BSHM', 'Maria Cruz', '2024-10002', 'bshm.student', 'bshm@student.bcp.edu.ph', '1st Year (HM-1B)'],
              ['BSAIS', 'Jose Reyes', '2024-10003', 'bsais.student', 'bsais@student.bcp.edu.ph', '3rd Year (AIS-3A)'],
              ['BSTM', 'Ana Dela Cruz', '2024-10004', 'bstm.student', 'bstm@student.bcp.edu.ph', '2nd Year (TM-2C)'],
              ['BSOA', 'Carlos Garcia', '2024-10005', 'bsoa.student', 'bsoa@student.bcp.edu.ph', '1st Year (OA-1A)'],
              ['BSE', 'Liza Ramos', '2024-10006', 'bse.student', 'bse@student.bcp.edu.ph', '3rd Year (ENT-3B)'],
              ['BSBA', 'Ramon Villanueva', '2024-10007', 'bsba.student', 'bsba@student.bcp.edu.ph', '2nd Year (BA-2A)'],
              ['BSIS', 'Patricia Aquino', '2024-10008', 'bsis.student', 'bsis@student.bcp.edu.ph', '1st Year (IS-1A)'],
              ['BSCpE', 'Mark Bautista', '2024-10009', 'bscpe.student', 'bscpe@student.bcp.edu.ph', '3rd Year (CPE-3A)'],
              ['BSPsych', 'Jenny Navarro', '2024-10010', 'bspsych.student', 'bspsych@student.bcp.edu.ph', '2nd Year (PSY-2B)'],
              ['BSCrim', 'Rico Fernandez', '2024-10011', 'bscrim.student', 'bscrim@student.bcp.edu.ph', '4th Year (CRIM-4A)'],
              ['BSPE', 'Sheila Santos', '2024-10012', 'bspe.student', 'bspe@student.bcp.edu.ph', '2nd Year (PE-2A)'],
              ['TLE', 'Angelo Torres', '2024-10013', 'tle.student', 'tle@student.bcp.edu.ph', '1st Year (TLE-1B)'],
              ['BSElEd', 'Claire Mendoza', '2024-10014', 'bseled.student', 'bseled@student.bcp.edu.ph', '3rd Year (ELED-3A)'],
              ['BSSecEd', 'Danilo Pascual', '2024-10015', 'bsseed.student', 'bsseed@student.bcp.edu.ph', '2nd Year (SEED-2C)'],
              ['BSLIS', 'Rowena Espinosa', '2024-10016', 'bslis.student', 'bslis@student.bcp.edu.ph', '3rd Year (LIS-3A)']
            ];
            foreach ($official_students_view as [$prog, $sname, $sno, $sun, $sem, $ssec]):
            ?>
              <tr>
                <td><span class="badge badge-info" style="font-size: 0.8rem;"><?= htmlspecialchars($prog) ?></span></td>
                <td><strong><?= htmlspecialchars($sname) ?></strong></td>
                <td><code style="font-size: 0.8rem;"><?= htmlspecialchars($sno) ?></code></td>
                <td><code style="font-weight: 700; color: #1e3a8a;"><?= htmlspecialchars($sun) ?></code></td>
                <td style="color: #64748b; font-size: 0.84rem;"><?= htmlspecialchars($sem) ?></td>
                <td><?= htmlspecialchars($ssec) ?></td>
                <td>
                  <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($sun) ?>')" title="Copy Username">
                    <i class="fa-regular fa-copy"></i>
                  </button>
                  <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($std_plain, ENT_QUOTES) ?>')" title="Copy Password">
                    <i class="fa-solid fa-key"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB 3: Faculty Club Advisers (40 Campus Organizations) -->
    <div id="tabContentAdvisers" style="display: none;">
      <div style="overflow-x: auto;">
        <table class="table-list">
          <thead>
            <tr>
              <th>Org Code</th>
              <th>Campus Organization Name</th>
              <th>Faculty Adviser</th>
              <th>Adviser Username</th>
              <th>Email</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $official_advisers_view = [
              ['CSSEC', 'Computer Science Student Exec Council', 'Prof. Alex Reyes', 'cssec.adviser', 'cssec@adviser.bcp.edu.ph'],
              ['ACADS', 'Association of Computer Eng Driven Students', 'Prof. Mark Velo', 'acads.adviser', 'acads@adviser.bcp.edu.ph'],
              ['ACES', 'Association of Computer Engineering Students', 'Prof. Elena Ramos', 'aces.adviser', 'aces@adviser.bcp.edu.ph'],
              ['AISS', 'Accounting Info System Society', 'Prof. Clara Tan', 'aiss.adviser', 'aiss@adviser.bcp.edu.ph'],
              ['BLISS', 'Bestlink Library & Info Science Society', 'Prof. Robert Cruz', 'bliss.adviser', 'bliss@adviser.bcp.edu.ph'],
              ['BRAVE', 'Values Education & Accountability Org', 'Prof. Diana Gomez', 'brave.adviser', 'brave@adviser.bcp.edu.ph'],
              ['CJSU', 'Criminal Justice Student Unit', 'Prof. Jose Mercado', 'cjsu.adviser', 'cjsu@adviser.bcp.edu.ph'],
              ['EYO', 'Entrepreyouth Organization', 'Prof. Lisa Santos', 'eyo.adviser', 'eyo@adviser.bcp.edu.ph'],
              ['G.A.L.A.W', 'Athletes & Leaders Wellness Assoc', 'Prof. Manuel Cruz', 'galaw.adviser', 'galaw@adviser.bcp.edu.ph'],
              ['GEMs', 'Guild of English Majors', 'Prof. Anna Reyes', 'gems.adviser', 'gems@adviser.bcp.edu.ph'],
              ['GOLD', 'Guild of Officers to Lead Development', 'Prof. Karen Lim', 'gold.adviser', 'gold@adviser.bcp.edu.ph'],
              ['JFINEX', 'Junior Financial Executives', 'Prof. Manuel Cruz', 'jfinex.adviser', 'jfinex@adviser.bcp.edu.ph'],
              ['HRS', 'Human Resources Society', 'Prof. Alex Reyes', 'hrs.adviser', 'hrs@adviser.bcp.edu.ph'],
              ['J.M.A', 'Junior Marketing Association', 'Prof. Sarah Mercado', 'jma.adviser', 'jma@adviser.bcp.edu.ph'],
              ['LAKAS', 'Liga ng Aktibong Kabataan sa Araling Panlipunan', 'Prof. Clara Tan', 'lakas.adviser', 'lakas@adviser.bcp.edu.ph'],
              ['L.A.P.I.S', 'Leadership Assoc Program & Services', 'Prof. Diana Gomez', 'lapis.adviser', 'lapis@adviser.bcp.edu.ph'],
              ['LIBRO', 'Lucid of Bright & Righteous Officers', 'Prof. Robert Cruz', 'libro.adviser', 'libro@adviser.bcp.edu.ph'],
              ['OMEGA', 'Org for Mathematics in Engineering', 'Prof. Jose Mercado', 'omega.adviser', 'omega@adviser.bcp.edu.ph'],
              ['PsychSoc', 'Psychology Society', 'Prof. Lisa Santos', 'psychsoc.adviser', 'psychsoc@adviser.bcp.edu.ph'],
              ['RSD', 'Regnum Scientiae Discipulus', 'Prof. Anna Reyes', 'rsd.adviser', 'rsd@adviser.bcp.edu.ph'],
              ['SIGMA', 'Guild for Mathematics Majors', 'Prof. Karen Lim', 'sigma.adviser', 'sigma@adviser.bcp.edu.ph'],
              ['TECHs', 'Tech, Exploratory & Hospitality Skills', 'Prof. Alex Reyes', 'techs.adviser', 'techs@adviser.bcp.edu.ph'],
              ['TTS', 'Tourism Student Society', 'Prof. Sarah Mercado', 'tts.adviser', 'tts@adviser.bcp.edu.ph'],
              ['WIKA', 'Wikang Filipino sa Akademya', 'Prof. Clara Tan', 'wika.adviser', 'wika@adviser.bcp.edu.ph'],
              ['ACAC', 'Association of Cultural Art Club', 'Prof. Sarah Mercado', 'acac.adviser', 'acac@adviser.bcp.edu.ph'],
              ['CESC', 'Computer Engineering Sports Club', 'Prof. Mark Velo', 'cesc.adviser', 'cesc@adviser.bcp.edu.ph'],
              ['EBCPCT', 'Elite BCP Chess Team', 'Prof. Mark Velo', 'ebcpct.adviser', 'ebcpct@adviser.bcp.edu.ph'],
              ['RCYC-BCP', 'Red Cross Youth Council - BCP', 'Dr. Elena Cruz', 'rcyc.adviser', 'rcyc@adviser.bcp.edu.ph'],
              ['SMC', 'Shuttle Master Club', 'Prof. Mark Velo', 'smc.adviser', 'smc@adviser.bcp.edu.ph'],
              ['ALL STAR', 'All Star Talent Group', 'Prof. Sarah Mercado', 'allstar.adviser', 'allstar@adviser.bcp.edu.ph'],
              ['B-FORCE', 'B-Force Hip-Hop Crew', 'Prof. Sarah Mercado', 'bforce.adviser', 'bforce@adviser.bcp.edu.ph'],
              ['CREATIVE', 'Creative Arts Group', 'Prof. Sarah Mercado', 'creative.adviser', 'creative@adviser.bcp.edu.ph'],
              ['CDC', 'Criminology Dance Company', 'Prof. Sarah Mercado', 'cdc.adviser', 'cdc@adviser.bcp.edu.ph'],
              ['DLC', 'Drum and Lyre Corporation', 'Prof. Sarah Mercado', 'dlc.adviser', 'dlc@adviser.bcp.edu.ph'],
              ['IKATLONG', 'Ikatlong Lahi Royalties', 'Prof. Sarah Mercado', 'ikatlong.adviser', 'ikatlong@adviser.bcp.edu.ph'],
              ['IMAGE', 'Image Alchemy Media Crew', 'Prof. Sarah Mercado', 'image.adviser', 'image@adviser.bcp.edu.ph'],
              ['S.I.K.A.T', 'Theater & Acting Group', 'Prof. Sarah Mercado', 'sikat.adviser', 'sikat@adviser.bcp.edu.ph'],
              ['UV', 'Unlimited Voice Choir', 'Prof. Sarah Mercado', 'uv.adviser', 'uv@adviser.bcp.edu.ph'],
              ['PEER', 'Peer Counselor Support Group', 'Dr. Elena Cruz', 'peer.adviser', 'peer@adviser.bcp.edu.ph'],
              ['NEWSLINK', 'School Publications', 'Dr. Elena Cruz', 'newslink.adviser', 'newslink@adviser.bcp.edu.ph']
            ];
            foreach ($official_advisers_view as [$code, $cname, $adv, $un, $em]):
            ?>
              <tr>
                <td><strong style="color: #1e3a8a;"><?= htmlspecialchars($code) ?></strong></td>
                <td><?= htmlspecialchars($cname) ?></td>
                <td><strong><?= htmlspecialchars($adv) ?></strong></td>
                <td><code style="font-weight: 700; color: #166534;"><?= htmlspecialchars($un) ?></code></td>
                <td style="color: #64748b; font-size: 0.84rem;"><?= htmlspecialchars($em) ?></td>
                <td>
                  <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($un) ?>')" title="Copy Username">
                    <i class="fa-regular fa-copy"></i>
                  </button>
                  <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($adv_plain, ENT_QUOTES) ?>')" title="Copy Password">
                    <i class="fa-solid fa-key"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</div>

<!-- Generate QR Code & Switch Tabs via JS -->
<script>
  function switchAccountTab(tab) {
    var tabAdmin = document.getElementById('tabContentAdmin');
    var tabStudents = document.getElementById('tabContentStudents');
    var tabAdvisers = document.getElementById('tabContentAdvisers');

    var btnAdmin = document.getElementById('tabBtnAdmin');
    var btnStudents = document.getElementById('tabBtnStudents');
    var btnAdvisers = document.getElementById('tabBtnAdvisers');

    tabAdmin.style.display = (tab === 'admin') ? 'block' : 'none';
    tabStudents.style.display = (tab === 'students') ? 'block' : 'none';
    tabAdvisers.style.display = (tab === 'advisers') ? 'block' : 'none';

    btnAdmin.className = (tab === 'admin') ? 'btn btn-primary' : 'btn btn-secondary';
    btnStudents.className = (tab === 'students') ? 'btn btn-primary' : 'btn btn-secondary';
    btnAdvisers.className = (tab === 'advisers') ? 'btn btn-primary' : 'btn btn-secondary';
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(() => {
        window.showSystemModal({ title: 'Copied to Clipboard', message: text, type: 'success' });
      }).catch(() => {
        window.showDecisionModal('Copy Credentials', 'Select and copy the text below:', { defaultValue: text });
      });
    } else {
      window.showDecisionModal('Copy Credentials', 'Select and copy the text below:', { defaultValue: text });
    }
  }

  // Generate QR code for mobile connection
  document.addEventListener('DOMContentLoaded', function() {
    var qrContainer = document.getElementById('qrcode');
    if (qrContainer && typeof QRCode !== 'undefined') {
      new QRCode(qrContainer, {
        text: '<?= $lanUrl ?>',
        width: 140,
        height: 140,
        colorDark: '#1e3a8a',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    }
  });
</script>


</div>

</body>
</html>
