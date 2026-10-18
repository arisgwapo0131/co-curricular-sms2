<?php
// ============================================================
//  ERROR.PHP — Centralized HTTP Error Dispatcher & UI Renderer
//  Handles 400, 401, 403, 404, 419, 500, 502, 503, 504 gracefully
// ============================================================

// 1. Resolve HTTP Status Code
$code = (int)($code ?? ($_SERVER['REDIRECT_STATUS'] ?? ($_GET['code'] ?? http_response_code())));
if ($code < 400 || $code > 599) {
    $code = 500;
}

// Ensure header status matches resolved code
if (!headers_sent()) {
    http_response_code($code);
}

// 2. Check if the request is an AJAX / JSON API call
$is_json = (
    (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
    (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (strpos($_SERVER['REQUEST_URI'] ?? '', '_actions.php') !== false)
);

// Error details mapping
$error_meta = [
    400 => [
        'title'       => 'Bad Request',
        'subtitle'    => 'Invalid or Malformed Request',
        'icon'        => 'fa-triangle-exclamation',
        'color'       => '#d97706',
        'badge_bg'    => '#fef3c7',
        'badge_color' => '#92400e',
        'description' => 'The server cannot process this request due to invalid syntax, missing parameters, or corrupted payload.',
        'action'      => 'Please review the submitted parameters or return to the main dashboard.'
    ],
    401 => [
        'title'       => 'Authentication Required',
        'subtitle'    => 'Session Expired or Not Signed In',
        'icon'        => 'fa-lock',
        'color'       => '#2563eb',
        'badge_bg'    => '#eff6ff',
        'badge_color' => '#1e40af',
        'description' => 'Your active session has expired due to inactivity or you have not authenticated into the portal yet.',
        'action'      => 'Please sign in with your student, adviser, SSC, or administrator credentials to proceed.'
    ],
    403 => [
        'title'       => 'Access Forbidden',
        'subtitle'    => 'Insufficient Role Permissions',
        'icon'        => 'fa-shield-halved',
        'color'       => '#dc2626',
        'badge_bg'    => '#fee2e2',
        'badge_color' => '#991b1b',
        'description' => 'You do not have the required role privileges or permissions to view or perform operations on this resource.',
        'action'      => 'If you believe this is in error, contact the Student Affairs Office or System Administrator.'
    ],
    404 => [
        'title'       => 'Page Not Found',
        'subtitle'    => 'Resource Unavailable',
        'icon'        => 'fa-compass',
        'color'       => '#6366f1',
        'badge_bg'    => '#e0e7ff',
        'badge_color' => '#3730a3',
        'description' => 'The page, module, or document you requested could not be located on the BCP Co-Curricular Management server.',
        'action'      => 'Check the URL address for typos or return to your active dashboard module.'
    ],
    419 => [
        'title'       => 'Page Expired',
        'subtitle'    => 'CSRF Security Token Mismatch',
        'icon'        => 'fa-clock-rotate-left',
        'color'       => '#d97706',
        'badge_bg'    => '#fef3c7',
        'badge_color' => '#92400e',
        'description' => 'Your security session token has expired. This happens when a form has been open for an extended duration.',
        'action'      => 'Please reload the page and submit your form again.'
    ],
    500 => [
        'title'       => 'Internal Server Error',
        'subtitle'    => 'Temporary Application Exception',
        'icon'        => 'fa-server',
        'color'       => '#e11d48',
        'badge_bg'    => '#ffe4e6',
        'badge_color' => '#9f1239',
        'description' => 'An unexpected server-side condition was encountered while processing your request on the cloud node.',
        'action'      => 'Our automated health monitor has logged this event. You can refresh or try again shortly.'
    ],
    502 => [
        'title'       => 'Bad Gateway',
        'subtitle'    => 'Upstream Proxy Failure',
        'icon'        => 'fa-network-wired',
        'color'       => '#ea580c',
        'badge_bg'    => '#ffedd5',
        'badge_color' => '#9a3412',
        'description' => 'The server received an invalid response from an upstream database or container gateway.',
        'action'      => 'Please retry your request in a few moments.'
    ],
    503 => [
        'title'       => 'Service Unavailable',
        'subtitle'    => 'Maintenance or High Server Load',
        'icon'        => 'fa-screwdriver-wrench',
        'color'       => '#7c3aed',
        'badge_bg'    => '#f3e8ff',
        'badge_color' => '#5b21b6',
        'description' => 'The portal is currently undergoing scheduled database maintenance or is temporarily handling high traffic volume.',
        'action'      => 'Please check the portal health status and refresh the page in 1–2 minutes.'
    ],
    504 => [
        'title'       => 'Gateway Timeout',
        'subtitle'    => 'Upstream Timeout',
        'icon'        => 'fa-hourglass-end',
        'color'       => '#ca8a04',
        'badge_bg'    => '#fef9c3',
        'badge_color' => '#854d0e',
        'description' => 'The gateway did not receive a timely response from the backend application runtime.',
        'action'      => 'Please reload the page to re-establish connection.'
    ]
];

$info = $error_meta[$code] ?? $error_meta[500];
$custom_msg = !empty($error_message) ? htmlspecialchars($error_message) : (!empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : null);
$ref_id = 'ERR-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));

// 3. Return JSON if API / AJAX
if ($is_json) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success'       => false,
        'http_status'   => $code,
        'error_title'   => $info['title'],
        'error_message' => $custom_msg ?: $info['description'],
        'reference_id'  => $ref_id,
        'timestamp'     => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 4. Resolve correct relative path to assets
$doc_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
$script_path = $_SERVER['SCRIPT_NAME'] ?? '';
$asset_base = (strpos($script_path, '/app/') !== false) ? '/app/' : '/app/';
$logo_path = $asset_base . 'images/BCP_LOGO.png';
$signin_url = $asset_base . 'auth/signin.php';
$dash_url   = $asset_base . 'dashboard/dashboard.php';
$events_url = $asset_base . 'dashboard/events.php?view=calendar';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $code ?> — <?= htmlspecialchars($info['title']) ?> | BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #1e3a8a;
      --primary-accent: #2563eb;
      --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
      --card-bg: rgba(255, 255, 255, 0.98);
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
      background: var(--bg-gradient);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      color: var(--text-main);
      position: relative;
      overflow-x: hidden;
    }

    /* Ambient background glow */
    .bg-glow {
      position: absolute;
      width: 500px;
      height: 500px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(37, 99, 235, 0.22) 0%, rgba(37, 99, 235, 0) 70%);
      top: -100px;
      right: -100px;
      pointer-events: none;
      z-index: 1;
    }
    .bg-glow-2 {
      position: absolute;
      width: 450px;
      height: 450px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(124, 58, 237, 0.18) 0%, rgba(124, 58, 237, 0) 70%);
      bottom: -100px;
      left: -100px;
      pointer-events: none;
      z-index: 1;
    }

    .error-card {
      position: relative;
      z-index: 10;
      background: var(--card-bg);
      width: 100%;
      max-width: 580px;
      border-radius: 20px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.1);
      padding: 40px 36px;
      text-align: center;
      animation: cardEnter 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes cardEnter {
      0% { transform: translateY(16px) scale(0.97); opacity: 0; }
      100% { transform: translateY(0) scale(1); opacity: 1; }
    }

    .brand-header {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      margin-bottom: 24px;
    }
    .brand-logo {
      width: 38px;
      height: 38px;
      object-fit: contain;
    }
    .brand-title {
      font-size: 0.95rem;
      font-weight: 700;
      color: #1e293b;
      letter-spacing: -0.2px;
      text-align: left;
    }
    .brand-subtitle {
      font-size: 0.72rem;
      color: var(--text-muted);
      font-weight: 500;
      display: block;
    }

    .status-badge-wrap {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 76px;
      height: 76px;
      border-radius: 50%;
      background: <?= $info['badge_bg'] ?>;
      color: <?= $info['color'] ?>;
      font-size: 2rem;
      margin-bottom: 18px;
      box-shadow: 0 8px 20px -6px <?= $info['color'] ?>40;
    }

    .status-code {
      font-size: 3.2rem;
      font-weight: 800;
      line-height: 1;
      letter-spacing: -1.5px;
      color: #0f172a;
      margin-bottom: 6px;
    }

    .status-title {
      font-size: 1.35rem;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 12px;
      letter-spacing: -0.3px;
    }

    .status-desc {
      font-size: 0.92rem;
      line-height: 1.55;
      color: #475569;
      margin-bottom: 20px;
    }

    .action-callout {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 14px 16px;
      font-size: 0.84rem;
      color: #334155;
      text-align: left;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      margin-bottom: 26px;
    }
    .action-callout i {
      color: <?= $info['color'] ?>;
      margin-top: 2px;
      font-size: 1rem;
      flex-shrink: 0;
    }

    .actions-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 20px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 12px 18px;
      font-size: 0.88rem;
      font-weight: 600;
      border-radius: 10px;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.15s ease;
      border: none;
    }

    .btn-primary {
      background: linear-gradient(135deg, #1e3a8a, #2563eb);
      color: #ffffff;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .btn-primary:hover {
      background: linear-gradient(135deg, #172554, #1d4ed8);
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35);
    }

    .btn-secondary {
      background: #f1f5f9;
      color: #334155;
      border: 1px solid #cbd5e1;
    }
    .btn-secondary:hover {
      background: #e2e8f0;
      color: #0f172a;
      transform: translateY(-1px);
    }

    .btn-full {
      grid-column: 1 / -1;
    }

    .footer-ref {
      font-size: 0.75rem;
      color: #94a3b8;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-top: 1px solid #f1f5f9;
      padding-top: 16px;
      margin-top: 10px;
    }
    .ref-badge {
      font-family: monospace;
      background: #f1f5f9;
      color: #475569;
      padding: 3px 8px;
      border-radius: 6px;
      font-weight: 600;
      letter-spacing: 0.5px;
    }

    @media (max-width: 540px) {
      .error-card { padding: 28px 20px; }
      .actions-grid { grid-template-columns: 1fr; }
      .status-code { font-size: 2.6rem; }
      .status-title { font-size: 1.2rem; }
    }
  </style>
</head>
<body>
  <div class="bg-glow"></div>
  <div class="bg-glow-2"></div>

  <div class="error-card">
    <div class="brand-header">
      <img src="<?= $logo_path ?>" alt="BCP Logo" class="brand-logo" onerror="this.style.display='none'"/>
      <div>
        <div class="brand-title">Bestlink College of the Philippines</div>
        <span class="brand-subtitle">Student Co-Curricular &amp; Activity Management</span>
      </div>
    </div>

    <div class="status-badge-wrap">
      <i class="fa-solid <?= $info['icon'] ?>"></i>
    </div>

    <div class="status-code"><?= $code ?></div>
    <h1 class="status-title"><?= htmlspecialchars($info['title']) ?></h1>
    <p class="status-desc">
      <?= $custom_msg ?: htmlspecialchars($info['description']) ?>
    </p>

    <div class="action-callout">
      <i class="fa-solid fa-circle-info"></i>
      <div>
        <strong>Recommended Action:</strong><br/>
        <span><?= htmlspecialchars($info['action']) ?></span>
      </div>
    </div>

    <div class="actions-grid">
      <?php if ($code === 401): ?>
        <a href="<?= $signin_url ?>" class="btn btn-primary btn-full">
          <i class="fa-solid fa-right-to-bracket"></i> Sign In to Portal
        </a>
      <?php elseif ($code === 403): ?>
        <a href="<?= $dash_url ?>" class="btn btn-primary">
          <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>
        <a href="<?= $signin_url ?>" class="btn btn-secondary">
          <i class="fa-solid fa-user-switch"></i> Switch Account
        </a>
      <?php elseif ($code === 404): ?>
        <a href="<?= $dash_url ?>" class="btn btn-primary">
          <i class="fa-solid fa-house"></i> Portal Home
        </a>
        <a href="<?= $events_url ?>" class="btn btn-secondary">
          <i class="fa-solid fa-calendar-days"></i> Event Calendar
        </a>
      <?php elseif ($code === 500 || $code === 502 || $code === 503 || $code === 504): ?>
        <button type="button" onclick="window.location.reload();" class="btn btn-primary">
          <i class="fa-solid fa-rotate-right"></i> Try Again
        </button>
        <a href="<?= $dash_url ?>" class="btn btn-secondary">
          <i class="fa-solid fa-house"></i> Dashboard
        </a>
      <?php else: ?>
        <a href="<?= $dash_url ?>" class="btn btn-primary">
          <i class="fa-solid fa-house"></i> Dashboard
        </a>
        <button type="button" onclick="window.history.back();" class="btn btn-secondary">
          <i class="fa-solid fa-arrow-left"></i> Go Back
        </button>
      <?php endif; ?>
    </div>

    <div class="footer-ref">
      <span>Reference ID: <span class="ref-badge"><?= $ref_id ?></span></span>
      <span><?= date('Y-m-d H:i') ?> PHT</span>
    </div>
  </div>
</body>
</html>
