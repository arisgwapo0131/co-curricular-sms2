<?php
// ============================================================
// SYSTEM_NOTIF_HEAD.PHP
// Shared script & stylesheet inclusion to ensure all browser
// alerts and decisions are rendered centered on screen.
// ============================================================
$notif_js_path = __DIR__ . '/../js/system-notifications.js';
$notif_v = file_exists($notif_js_path) ? filemtime($notif_js_path) : time();
?>
<script src="../js/system-notifications.js?v=<?= $notif_v ?>"></script>
