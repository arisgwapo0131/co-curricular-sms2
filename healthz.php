<?php
// ============================================================
//  HEALTHZ.PHP — Ultra-Fast Container Health Check Endpoint
//  Answers instantly with HTTP 200 for HostForge & Load Balancers
// ============================================================

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode([
    'status'    => 'ok',
    'timestamp' => time(),
    'service'   => 'BCP Co-Curricular Management System',
    'php'       => PHP_VERSION
]);
exit;
