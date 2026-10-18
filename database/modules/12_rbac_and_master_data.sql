-- ============================================================
-- 12_RBAC_AND_MASTER_DATA.SQL
-- Co-Curricular Management System — RBAC Engine & Workflow Schema
-- Implements Specification Section 4: Recommended RBAC Model
-- ============================================================

-- 1. Roles Definition
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `display_name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Permissions Definition
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `module` VARCHAR(50) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `permission_key` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perm_key` (`permission_key`),
  KEY `idx_perm_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Role-Permission Junction
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` INT(10) UNSIGNED NOT NULL,
  `permission_id` INT(10) UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_perm` (`role_id`, `permission_id`),
  KEY `idx_rp_role` (`role_id`),
  KEY `idx_rp_perm` (`permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Seed Standard Roles
INSERT INTO `roles` (`id`, `name`, `display_name`, `description`, `status`) VALUES
(1, 'student', 'General Student', 'Campus learner participating in registered co-curricular activities, voting in elections, and viewing achievements.', 'active'),
(2, 'club_adviser', 'Faculty Club Adviser', 'Assigned faculty adviser governing club operations, endorsing budget/events, and maintaining the club member roster.', 'active'),
(3, 'ssc', 'Supreme Student Council Officer', 'Council officer with institutional governance, event endorsement, budget review/revisions, and activity oversight.', 'active'),
(4, 'admin', 'System Administrator', 'Central administrator with full access to user management, RBAC, master data, budget disbursement, security, and technical configuration.', 'active')
ON DUPLICATE KEY UPDATE `display_name`=VALUES(`display_name`), `description`=VALUES(`description`), `status`=VALUES(`status`);

-- 5. Seed Permissions Matrix
INSERT INTO `permissions` (`module`, `action`, `permission_key`, `description`) VALUES
-- Dashboard
('dashboard', 'view_own', 'dashboard.view.own', 'View personal student dashboard'),
('dashboard', 'view_org', 'dashboard.view.org', 'View organization adviser dashboard'),
('dashboard', 'view_institutional', 'dashboard.view.institutional', 'View institutional SSC executive dashboard'),
('dashboard', 'view_system', 'dashboard.view.system', 'View system-wide administrator dashboard'),

-- Organization Directory & Databases
('organization', 'view', 'organization.view', 'View accredited organization directory'),
('organization', 'apply', 'organization.apply', 'Submit application to join student organization'),
('organization', 'manage_own', 'organization.manage.own', 'Manage own assigned organization profile and documents'),
('organization', 'review_all', 'organization.review.all', 'Review charter compliance and databases across all organizations'),
('organization', 'crud', 'organization.crud', 'Full create, edit, suspend, reactivate, and archive of organizations'),

-- Membership & Roster
('membership', 'apply', 'membership.apply', 'Apply for organization membership'),
('membership', 'view_own', 'membership.view.own', 'View own membership status and history'),
('membership', 'review_own', 'membership.review.own', 'Adviser review and endorse club applications'),
('membership', 'review_all', 'membership.review.all', 'SSC institutional oversight and endorsement of member applications'),
('membership', 'manage_all', 'membership.manage.all', 'Administrator full control over memberships and rosters'),

-- Events & Activities
('events', 'view', 'events.view', 'View upcoming approved events and details'),
('events', 'register', 'events.register', 'Register for event participation'),
('events', 'create_own', 'events.create.own', 'Create event proposals for assigned organization'),
('events', 'edit_own', 'events.edit.own', 'Edit event proposals for assigned organization'),
('events', 'review_ssc', 'events.review.ssc', 'SSC review and endorse organization event proposals'),
('events', 'create_institutional', 'events.create.institutional', 'Create council/institutional campus-wide events'),
('events', 'approve_admin', 'events.approve.admin', 'Admin final clearance and publishing to campus calendar'),
('events', 'override', 'events.override', 'Admin conflict and schedule override with audit trail'),

-- Budget & Finance
('budget', 'create_own', 'budget.create.own', 'Submit budget requisition with itemized breakdown for assigned club'),
('budget', 'endorse_adviser', 'budget.endorse.adviser', 'Adviser endorse budget request to SSC'),
('budget', 'review_ssc', 'budget.review.ssc', 'SSC review, revise recommended amount, and forward budget proposal'),
('budget', 'disburse_admin', 'budget.disburse.admin', 'Admin final approval and financial disbursement recording'),
('budget', 'view_all', 'budget.view.all', 'View institution-wide budget ledger and financial analytics'),

-- Attendance & Tracking
('attendance', 'checkin', 'attendance.checkin', 'Self-check in via QR or participant scanner'),
('attendance', 'track_org', 'attendance.track.org', 'Generate event QR code and scan attendees for assigned club'),
('attendance', 'view_analytics', 'attendance.view.analytics', 'View campus-wide attendance and absenteeism analytics'),
('attendance', 'override', 'attendance.override', 'Manual attendance override with required reason and audit log'),

-- Elections & Voting
('elections', 'vote', 'elections.vote', 'Cast digital ballot in student elections'),
('elections', 'manage_org', 'elections.manage.org', 'Configure club officers election and candidate slates'),
('elections', 'oversight', 'elections.oversight', 'SSC governance oversight and monitor voting turnout'),
('elections', 'admin', 'elections.admin', 'Lock, close, verify results, and audit elections'),

-- Achievements & Awards
('achievements', 'view', 'achievements.view', 'View student and organization achievements'),
('achievements', 'submit', 'achievements.submit', 'Submit external competition / achievement documentation'),
('achievements', 'verify_ssc', 'achievements.verify.ssc', 'SSC validation and institutional endorsement of achievements'),
('achievements', 'admin', 'achievements.admin', 'Administrator full validation, deletion, and reporting'),


-- Announcements
('announcements', 'view', 'announcements.view', 'View campus and organization announcements'),
('announcements', 'create_org', 'announcements.create.org', 'Publish announcements targeted to club members'),
('announcements', 'create_council', 'announcements.create.council', 'Publish SSC council broadcasts to programs or campus-wide'),
('announcements', 'manage_all', 'announcements.manage.all', 'Administrator manage all broadcast channels and templates'),

-- Reports & Analytics
('reports', 'view_org', 'reports.view.org', 'View performance reports for assigned organization'),
('reports', 'view_institutional', 'reports.view.institutional', 'View institutional SSC reports across all organizations and programs'),
('reports', 'view_system', 'reports.view.system', 'View full system analytics, data exports, and audit reports'),
('reports', 'export', 'reports.export', 'Export reports to PDF, CSV, and Excel formats'),

-- Audit Logs
('audit', 'view_readonly', 'audit.view.readonly', 'Read-only search and inspection of system activity logs'),
('audit', 'manage_full', 'audit.manage.full', 'Full audit log administration, search, and export'),

-- User & Access Control
('users', 'view_own', 'users.view.own', 'View and update own account profile and photo'),
('users', 'view_directory', 'users.view.directory', 'View user directory in limited context'),
('users', 'manage_all', 'users.manage.all', 'Full CRUD of user accounts, role assignment, and password reset'),

-- RBAC Administration
('rbac', 'manage', 'rbac.manage', 'Configure roles, permissions, and access policy enforcement'),

-- System Administration & Master Data
('master_data', 'academic', 'master_data.academic', 'Manage academic degree programs and college departments'),
('master_data', 'org_categories', 'master_data.org_categories', 'Configure organization categories and accreditation criteria'),
('settings', 'manage', 'settings.manage', 'Configure academic year, semester, AI integration, and notification templates'),
('system', 'health', 'system.health', 'Inspect server status, database metrics, storage, and health checks')
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`), `module`=VALUES(`module`), `action`=VALUES(`action`);

-- 6. Map Role Permissions (Role 1: Student)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions` WHERE `permission_key` IN (
  'dashboard.view.own',
  'organization.view',
  'organization.apply',
  'membership.apply',
  'membership.view_own',
  'events.view',
  'events.register',
  'attendance.checkin',
  'elections.vote',
  'achievements.view',
  'achievements.submit',
  'announcements.view',
  'users.view_own'
) ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- Map Role Permissions (Role 2: Club Adviser)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions` WHERE `permission_key` IN (
  'dashboard.view.org',
  'organization.manage.own',
  'membership.review.own',
  'events.view',
  'events.create.own',
  'events.edit.own',
  'budget.create.own',
  'budget.endorse.adviser',
  'attendance.track.org',
  'elections.manage.org',
  'achievements.view',
  'achievements.submit',
  'announcements.create.org',
  'reports.view.org',
  'reports.export',
  'users.view.own'
) ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- Map Role Permissions (Role 3: SSC Officer)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, id FROM `permissions` WHERE `permission_key` IN (
  'dashboard.view.institutional',
  'organization.view',
  'organization.review.all',
  'membership.review.all',
  'events.view',
  'events.create.institutional',
  'events.review.ssc',
  'budget.review.ssc',
  'budget.view.all',
  'attendance.view.analytics',
  'attendance.override',
  'elections.oversight',
  'achievements.view',
  'achievements.verify.ssc',
  'announcements.create.council',
  'reports.view.institutional',
  'reports.export',
  'users.view.directory',
  'users.view.own'
) ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- Map Role Permissions (Role 4: System Administrator - All Permissions)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, id FROM `permissions`
ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- 6. User-Role Junction (Optional multi-role assignment / dynamic RBAC)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate user_roles from users.role if empty
INSERT INTO `user_roles` (`user_id`, `role_id`)
SELECT u.id, r.id 
FROM `users` u
JOIN `roles` r ON r.name = u.role
ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

