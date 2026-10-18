<?php
require_once dirname(__DIR__, 2) . '/app/shared/db.php';
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("DROP TABLE IF EXISTS `certificate_upload_staging`");
$conn->query("DROP TABLE IF EXISTS `certificates`");
$conn->query("DROP TABLE IF EXISTS `certificate_templates`");
$conn->query("DELETE FROM `role_permissions` WHERE `permission_id` IN (SELECT `id` FROM `permissions` WHERE `module` = 'certificates')");
$conn->query("DELETE FROM `permissions` WHERE `module` = 'certificates'");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");
echo "Certificate tables and permissions removed successfully from MySQL.\n";
