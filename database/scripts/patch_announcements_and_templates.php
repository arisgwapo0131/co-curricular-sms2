<?php
require_once dirname(__DIR__, 2) . '/app/shared/db.php';

// 1. Upgrade org_announcements table
// Make club_id nullable so system and council wide announcements don't require a single club
$conn->query("ALTER TABLE `org_announcements` MODIFY COLUMN `club_id` INT(10) UNSIGNED NULL");

// Add scope column if not exists
$chk = $conn->query("SHOW COLUMNS FROM `org_announcements` LIKE 'scope'");
if (!$chk || $chk->num_rows === 0) {
    $conn->query("ALTER TABLE `org_announcements` ADD COLUMN `scope` ENUM('System', 'Council', 'Club') NOT NULL DEFAULT 'Club' AFTER `club_id`");
}

// Add status column (Publication Controls: Draft, Published, Archived)
$chk = $conn->query("SHOW COLUMNS FROM `org_announcements` LIKE 'status'");
if (!$chk || $chk->num_rows === 0) {
    $conn->query("ALTER TABLE `org_announcements` ADD COLUMN `status` ENUM('Draft', 'Published', 'Archived') NOT NULL DEFAULT 'Published' AFTER `priority`");
}

// Add is_pinned column (Publication Controls)
$chk = $conn->query("SHOW COLUMNS FROM `org_announcements` LIKE 'is_pinned'");
if (!$chk || $chk->num_rows === 0) {
    $conn->query("ALTER TABLE `org_announcements` ADD COLUMN `is_pinned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
}

// Add expires_at column (Publication Controls)
$chk = $conn->query("SHOW COLUMNS FROM `org_announcements` LIKE 'expires_at'");
if (!$chk || $chk->num_rows === 0) {
    $conn->query("ALTER TABLE `org_announcements` ADD COLUMN `expires_at` DATETIME NULL AFTER `is_pinned`");
}

// Add channels column (Broad communications)
$chk = $conn->query("SHOW COLUMNS FROM `org_announcements` LIKE 'channels'");
if (!$chk || $chk->num_rows === 0) {
    $conn->query("ALTER TABLE `org_announcements` ADD COLUMN `channels` VARCHAR(100) DEFAULT 'In-App' AFTER `target_group`");
}

// Expand category to VARCHAR(100) to support diverse system, council, and club categories
$conn->query("ALTER TABLE `org_announcements` MODIFY COLUMN `category` VARCHAR(100) NOT NULL DEFAULT 'General'");

// 2. Create notification_templates table
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 3. Seed Standard Notification Templates
$templates = [
    [
        'TPL-SYS-MAINT',
        'Scheduled System Maintenance Advisory',
        'System Notice',
        'Notice: Scheduled Portal Maintenance on [Date] at [Time]',
        'Please be advised that the BCP Co-Curricular Student Portal will undergo scheduled core database and infrastructure maintenance on [Date] starting from [Start Time] until [End Time]. During this window, digital attendance QR scanning, event registrations, and roster verifications will be temporarily offline. Please file offline attendance records through assigned marshals.',
        'Important',
        'All Campus Users'
    ],
    [
        'TPL-EMERG-WEATHER',
        'Campus Weather Advisory & Class Suspension',
        'Emergency Alert',
        'URGENT: Campus Advisory & Co-Curricular Activities Suspension',
        'In accordance with PAGASA weather bulletins and local government executive directives regarding [Weather Disturbance], all physical campus co-curricular events, student organization assemblies, sports practices, and committee hearings are suspended effective immediately until further notice. Stay safe and monitor official university portals.',
        'Urgent',
        'All Campus Users'
    ],
    [
        'TPL-ACAD-CLEARANCE',
        'Semestral Co-Curricular Clearance & Requirements',
        'Academic Deadline',
        'Official Notice: Semester Co-Curricular Activity Clearance Submission Deadline',
        'This is an official administrative reminder to all Student Organization Presidents, Faculty Advisers, and Student Leaders: All term activity accomplishment summaries, liquidated event expenditures, and validated attendee ledgers must be submitted via the Co-Curricular portal on or before [Deadline Date]. Failure to comply will impede organization renewal and clearance release.',
        'Important',
        'Club Advisers & Officers'
    ],
    [
        'TPL-SSC-ASSEMBLY',
        'SSC Institutional Student General Assembly',
        'Council & Governance',
        'Supreme Student Council: Official Notice of Campus-Wide General Assembly',
        'The Supreme Student Council (SSC) formally convokes all student body representatives, club delegates, and active campus learners to the Institutional General Assembly on [Date] at [Venue]. Agenda items include student welfare initiatives, legislative charter revisions, and semester activity calendar ratification.',
        'Normal',
        'All Students'
    ],
    [
        'TPL-ELEC-VOTING',
        'Official Digital Election Voting Window',
        'Elections Notice',
        'Announcement: Campus Digital Elections Voting is Now Open',
        'The Electoral Commission and SSC Oversight Committee announce that the digital ballot window for the upcoming academic year student council elections is officially OPEN. All enrolled students are encouraged to exercise their democratic right and cast their secure digital ballot through the Elections module.',
        'Important',
        'All Students'
    ],
    [
        'TPL-GOV-POLICY',
        'Administrative Directive on Activity Permits',
        'Institutional Policy',
        'Directive: Institutional Guidelines on Off-Campus Activity Clearances',
        'All student organizations planning off-campus community extensions, benchmark tours, or inter-collegiate competitions must secure Stage 1 Adviser Endorsement and Stage 2 Admin Clearance at least fifteen (15) working days prior to proposed travel dates. Late submissions will not be processed.',
        'Normal',
        'Club Advisers & Officers'
    ]
];

$stmt = $conn->prepare("INSERT INTO `notification_templates` (`code`, `title`, `category`, `subject_template`, `body_template`, `default_priority`, `default_target`, `created_by`, `status`) 
VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'Active') 
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`), `category`=VALUES(`category`), `subject_template`=VALUES(`subject_template`), `body_template`=VALUES(`body_template`), `default_priority`=VALUES(`default_priority`), `default_target`=VALUES(`default_target`)");

foreach ($templates as $t) {
    $stmt->bind_param('sssssss', $t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $t[6]);
    $stmt->execute();
}
$stmt->close();

// 4. Seed sample authentic administrative system notices and council notices
// Admin user id
$admin_res = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$admin_id = ($admin_res && $row = $admin_res->fetch_assoc()) ? (int)$row['id'] : 1;

// SSC user id
$ssc_res = $conn->query("SELECT id FROM users WHERE role = 'ssc' LIMIT 1");
$ssc_id = ($ssc_res && $row = $ssc_res->fetch_assoc()) ? (int)$row['id'] : 1;

// Check existing system notices
$chk_sys = $conn->query("SELECT COUNT(*) FROM org_announcements WHERE scope = 'System'");
if ($chk_sys && (int)$chk_sys->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO `org_announcements` (`club_id`, `scope`, `author_id`, `title`, `category`, `priority`, `status`, `is_pinned`, `content`, `target_group`, `channels`) VALUES
    (NULL, 'System', $admin_id, 'System Infrastructure Upgrade & Maintenance Schedule', 'System Maintenance', 'Important', 'Published', 1, 'Please be advised that the institutional co-curricular server infrastructure will undergo scheduled maintenance on Saturday evening from 10:00 PM to 2:00 AM. In-app QR attendance terminals and budget endorsements will be briefly paused. Thank you for your patience.', 'All Campus Users', 'In-App, Dashboard Banner'),
    (NULL, 'System', $admin_id, 'Official Policy on Mid-Year Accreditation & Organization Chartering', 'Institutional Policy', 'Normal', 'Published', 0, 'The Office of Student Affairs and System Administration is now accepting digital charter renewal applications for all probationary and accredited student clubs. Please ensure your adviser endorsements and financial liquidations are updated.', 'All Campus Users', 'In-App'),
    (NULL, 'System', $admin_id, 'Campus Advisory: Protocol for Heat Index & Inclement Weather', 'Campus Advisory', 'Urgent', 'Published', 1, 'In line with local health and weather monitoring advisories regarding extreme heat index, all outdoor strenuous co-curricular sports and drills between 11:00 AM and 3:00 PM are strongly discouraged. Please relocate activities to covered gymnasiums.', 'All Students', 'In-App, Urgent Banner')
    ");
}

// Check existing council notices
$chk_ssc = $conn->query("SELECT COUNT(*) FROM org_announcements WHERE scope = 'Council'");
if ($chk_ssc && (int)$chk_ssc->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO `org_announcements` (`club_id`, `scope`, `author_id`, `title`, `category`, `priority`, `status`, `is_pinned`, `content`, `target_group`, `channels`) VALUES
    (NULL, 'Council', $ssc_id, 'Supreme Student Council: General Assembly & Budget Transparency Hearing', 'Council Assembly', 'Important', 'Published', 1, 'The Supreme Student Council warmly invites all club leaders and student body representatives to the First Semester General Assembly. We will present the semester financial ledger, approved activity allocations, and student grievance resolutions.', 'Student Body (All Enrolled Students)', 'In-App'),
    (NULL, 'Council', $ssc_id, 'SSC Resolution No. 04: Standardized Digital Certificate & Activity Verification', 'Governance & Resolutions', 'Normal', 'Published', 0, 'The Council has ratified Resolution No. 04 establishing unified criteria for student leadership activity verification and inter-club collaborative points across campus organizations.', 'Club Presidents & Student Leaders', 'In-App')
    ");
}

echo "Database schemas patched: org_announcements extended with publication controls, scopes, and notification_templates initialized.\n";
