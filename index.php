<?php
// ============================================================
//  INDEX.PHP — Central Web Entry Point & Session Router
//  Directs incoming traffic to the authentic Co-Curricular System
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$query_str = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';

// If authenticated, route directly to the role dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: app/dashboard/dashboard.php' . $query_str);
    exit;
}

// Otherwise, route to the Sign-In portal
header('Location: app/auth/signin.php' . $query_str);
exit;
