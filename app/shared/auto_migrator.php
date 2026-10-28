<?php
/**
 * AUTO_MIGRATOR.PHP — Instant In-Memory Database Auto-Provisioner
 * Ensures fresh cloud databases (HostForge / Railway / Aiven) automatically
 * provision all tables and official accounts without manual setup.
 */

require_once __DIR__ . '/org_db_manager.php';

function auto_initialize_database(mysqli $conn): bool {
    try {
        // Check table exists first before touching indexes
        $tblChk = @$conn->query("SHOW TABLES LIKE 'users'");
        $need_full_init = (!$tblChk || $tblChk->num_rows === 0);

        // Only alter the index if the 'users' table already exists AND the old unique index is still present
        if (!$need_full_init) {
            $idxCheck = @$conn->query("SHOW INDEX FROM users WHERE Key_name = 'uq_users_email'");
            if ($idxCheck && $idxCheck->num_rows > 0) {
                @$conn->query("ALTER TABLE users DROP INDEX uq_users_email");
                @$conn->query("ALTER TABLE users ADD INDEX idx_users_email (email)");
            }

            // Ensure role ENUM accommodates super_admin and staff
            @$conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'staff', 'ssc', 'club_adviser', 'student') NOT NULL DEFAULT 'student'");

            // Ensure super.admin exists
            $chkSuper = @$conn->query("SELECT id FROM users WHERE username = 'super.admin' LIMIT 1");
            if (!$chkSuper || $chkSuper->num_rows === 0) {
                $super_hash = password_hash('Bcp@SuperAdmin2026!', PASSWORD_DEFAULT);
                @$conn->query("INSERT INTO users (username, email, first_name, last_name, password_hash, role, status) VALUES ('super.admin', 'pangilinanarisguillersaret@gmail.com', 'Super', 'Admin', '$super_hash', 'super_admin', 'Active')");
            }

            // Ensure operational accounts & primary test student route to real Gmail
            @$conn->query("UPDATE users SET email = 'pangilinanarisguillersaret@gmail.com' WHERE role IN ('super_admin', 'admin', 'staff', 'ssc') OR username IN ('super.admin', 'scc.admin', 'osa.staff', 'ssc.officer', 'admin', 'ssc', 'bsit.student')");

            // Ensure primary student test password is valid
            $chkStudentPass = @$conn->query("SELECT password_hash FROM users WHERE username = 'bsit.student' LIMIT 1");
            if ($chkStudentPass && $srow = $chkStudentPass->fetch_assoc()) {
                if (!password_verify('Bcp@Test2026!', $srow['password_hash']) && !password_verify('Student@123', $srow['password_hash'])) {
                    $std_hash = password_hash('Bcp@Test2026!', PASSWORD_DEFAULT);
                    @$conn->query("UPDATE users SET password_hash = '$std_hash' WHERE username = 'bsit.student'");
                }
            }

            // Ensure system_settings has Gmail SMTP configuration
            @$conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES 
                ('smtp_host', 'smtp.gmail.com'),
                ('smtp_port', '587'),
                ('smtp_user', 'cgales923@gmail.com'),
                ('smtp_pass', 'grmm zqtb worn wxie'),
                ('smtp_crypto', 'tls'),
                ('mail_from_name', 'Bestlink College of the Philippines'),
                ('mail_from_email', 'cgales923@gmail.com'),
                ('mfa_enabled', '1'),
                ('mfa_allow_dev_preview', '0'),
                ('mfa_expiry_minutes', '5'),
                ('mfa_resend_cooldown', '60'),
                ('mfa_test_recipient', 'pangilinanarisguillersaret@gmail.com')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

            // Ensure high-performance composite indexes exist
            @$conn->query("ALTER TABLE events ADD INDEX IF NOT EXISTS idx_events_status_del_date (status, deleted_at, event_date)");
            @$conn->query("ALTER TABLE events ADD INDEX IF NOT EXISTS idx_events_del_created (deleted_at, created_at)");
            @$conn->query("ALTER TABLE club_memberships ADD INDEX IF NOT EXISTS idx_cm_user_status (user_id, status)");
            @$conn->query("ALTER TABLE clubs ADD INDEX IF NOT EXISTS idx_clubs_del_status (deleted_at, status)");
            @$conn->query("ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_notif_user_created (user_id, created_at)");
        }

        if (!function_exists('executeSQLScript')) {
            require_once __DIR__ . '/setup.php';
        }

        $messages = [];
        $errors = [];

        if ($need_full_init) {
            // 1. Run master schema
            try {
                $masterSql = dirname(__DIR__, 2) . '/database/sms_db.sql';
                if (file_exists($masterSql) && function_exists('executeSQLScript')) {
                    executeSQLScript($conn, $masterSql, $errors);
                }
            } catch (Throwable $e) {
                error_log("Master schema warning: " . $e->getMessage());
            }

            // 2. Run RBAC schema
            try {
                $rbacSql = dirname(__DIR__, 2) . '/database/modules/12_rbac_and_master_data.sql';
                if (file_exists($rbacSql) && function_exists('executeSQLScript')) {
                    executeSQLScript($conn, $rbacSql, $errors);
                }
            } catch (Throwable $e) {
                error_log("RBAC schema warning: " . $e->getMessage());
            }
        }

        // Always ensure system accounts & clubs from SYSTEM_ACCOUNTS.md are active and populated
        $uCount = @$conn->query("SELECT COUNT(*) FROM users");
        $totalUsers = $uCount ? (int)$uCount->fetch_row()[0] : 0;
        if ($totalUsers < 50) {
            try {
                if (function_exists('seedSystemAccountsAndClubs')) {
                    seedSystemAccountsAndClubs($conn, $messages, $errors);
                }
            } catch (Throwable $e) {
                error_log("Seed accounts warning: " . $e->getMessage());
            }

            try {
                if (function_exists('seedLiveSystemData')) {
                    seedLiveSystemData($conn, $messages, $errors);
                }
            } catch (Throwable $e) {
                error_log("Seed system data warning: " . $e->getMessage());
            }
        }

        return true;
    } catch (Throwable $e) {
        error_log("Auto-migrator notice: " . $e->getMessage());
    }
    return false;
}
