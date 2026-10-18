<?php
// ============================================================
//  MAIL_HELPER.PHP — Email & Multi-Factor Authentication (MFA)
//  Co-Curricular Management System — Bestlink College of the Philippines
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

/**
 * Mask an email address for secure public display (e.g. j***s@student.bcp.edu.ph).
 */
function mask_email(string $email): string {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '••••••••@bcp.edu.ph';
    }
    list($local, $domain) = explode('@', $email, 2);
    $len = strlen($local);
    if ($len <= 2) {
        $maskedLocal = substr($local, 0, 1) . '***';
    } elseif ($len <= 4) {
        $maskedLocal = substr($local, 0, 1) . '***' . substr($local, -1);
    } else {
        $maskedLocal = substr($local, 0, 2) . '***' . substr($local, -1);
    }
    return $maskedLocal . '@' . $domain;
}

/**
 * Send an email using configured SMTP or PHP mail() fallback.
 */
function send_system_email(
    string $to_email,
    string $to_name,
    string $subject,
    string $html_body,
    string $text_body = '',
    ?mysqli $conn = null
): array {
    $db = $conn;
    if (!$db) {
        global $conn;
        $db = $conn;
    }

    // Load mail settings from system_settings if available
    $smtp_host = '';
    $smtp_port = 587;
    $smtp_user = '';
    $smtp_pass = '';
    $smtp_crypto = 'tls';
    $mail_from_name = 'BCP Co-Curricular Management System';
    $mail_from_email = 'no-reply@bcp.edu.ph';

    if ($db) {
        try {
            $res = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_crypto', 'mail_from_name', 'mail_from_email')");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $k = $row['setting_key'];
                    $v = $row['setting_value'];
                    if ($k === 'smtp_host' && !empty($v)) $smtp_host = trim($v);
                    if ($k === 'smtp_port' && !empty($v)) $smtp_port = (int)$v;
                    if ($k === 'smtp_user' && !empty($v)) $smtp_user = trim($v);
                    if ($k === 'smtp_pass' && !empty($v)) $smtp_pass = trim($v);
                    if ($k === 'smtp_crypto' && !empty($v)) $smtp_crypto = strtolower(trim($v));
                    if ($k === 'mail_from_name' && !empty($v)) $mail_from_name = trim($v);
                    if ($k === 'mail_from_email' && !empty($v)) $mail_from_email = trim($v);
                }
            }
        } catch (Throwable $e) {}
    }

    // Check environment variable overrides
    if (empty($smtp_host) && getenv('MAIL_HOST') && getenv('MAIL_HOST') !== '127.0.0.1') {
        $smtp_host = getenv('MAIL_HOST');
    }
    if (getenv('MAIL_PORT')) {
        $smtp_port = (int)getenv('MAIL_PORT');
    }
    if (empty($smtp_user) && getenv('MAIL_USERNAME')) {
        $smtp_user = getenv('MAIL_USERNAME');
    }
    if (empty($smtp_pass) && getenv('MAIL_PASSWORD')) {
        $smtp_pass = getenv('MAIL_PASSWORD');
    }
    if (getenv('MAIL_FROM_ADDRESS')) {
        $mail_from_email = getenv('MAIL_FROM_ADDRESS');
    }
    if (getenv('MAIL_FROM_NAME')) {
        $mail_from_name = getenv('MAIL_FROM_NAME');
    }
    if (getenv('MAIL_ENCRYPTION')) {
        $smtp_crypto = strtolower(trim(getenv('MAIL_ENCRYPTION')));
    }
    if (empty($smtp_crypto)) {
        $smtp_crypto = ($smtp_port === 465) ? 'ssl' : 'tls';
    }

    // 1. Attempt SMTP if host is provided
    if (!empty($smtp_host)) {
        $active_from = (!empty($smtp_user) && stripos($smtp_host, 'gmail') !== false) ? $smtp_user : $mail_from_email;
        $smtpResult = send_via_socket_smtp($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_crypto, $active_from, $mail_from_name, $to_email, $to_name, $subject, $html_body, $text_body);
        if ($smtpResult['success']) {
            return $smtpResult;
        }
        // If SMTP failed, fall through to native mail()
    }

    // 2. Fallback to PHP native mail()
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($mail_from_name), $mail_from_email);
    $headers[] = 'Reply-To: ' . $mail_from_email;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $headerStr = implode("\r\n", $headers);
    $sent = @mail($to_email, $encodedSubject, $html_body, $headerStr);

    return [
        'success' => $sent,
        'method'  => 'mail',
        'message' => $sent ? 'Email delivered via PHP mail().' : 'PHP mail() returned false (server mail transport offline).'
    ];
}

/**
 * Lightweight, robust SMTP client using stream_socket_client.
 */
function send_via_socket_smtp(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $crypto,
    string $from_email,
    string $from_name,
    string $to_email,
    string $to_name,
    string $subject,
    string $html_body,
    string $text_body = ''
): array {
    $timeout = 8; // Sufficient timeout for external Gmail handshake
    $prefix = ($crypto === 'ssl') ? 'ssl://' : '';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ]);
    $socket = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

    if (!$socket) {
        return ['success' => false, 'message' => "SMTP Connection to $host:$port failed: $errstr ($errno)"];
    }

    stream_set_timeout($socket, $timeout);

    $read = function() use ($socket) {
        $data = '';
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $data;
    };

    $send = function($cmd) use ($socket) {
        fputs($socket, $cmd . "\r\n");
    };

    $res = $read();
    if (substr($res, 0, 3) !== '220') {
        fclose($socket);
        return ['success' => false, 'message' => "SMTP Greeting failed: $res"];
    }

    $hostname = gethostname() ?: 'localhost';
    $send("EHLO $hostname");
    $res = $read();

    // STARTTLS if requested and supported
    if ($crypto === 'tls' && strpos($res, 'STARTTLS') !== false) {
        $send("STARTTLS");
        $res = $read();
        if (substr($res, 0, 3) === '220') {
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                fclose($socket);
                return ['success' => false, 'message' => "TLS handshake negotiation failed."];
            }
            $send("EHLO $hostname");
            $read();
        }
    }

    // AUTH LOGIN if user/pass provided
    if (!empty($user) && !empty($pass)) {
        $cleanPass = (stripos($host, 'gmail') !== false) ? str_replace(' ', '', $pass) : $pass;
        $send("AUTH LOGIN");
        $res = $read();
        if (substr($res, 0, 3) === '334') {
            $send(base64_encode($user));
            $read();
            $send(base64_encode($cleanPass));
            $res = $read();
            if (substr($res, 0, 3) !== '235') {
                fclose($socket);
                return ['success' => false, 'message' => "SMTP Authentication failed: $res"];
            }
        }
    }

    // MAIL FROM
    $send("MAIL FROM:<$from_email>");
    $res = $read();
    if (substr($res, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'message' => "MAIL FROM rejected: $res"];
    }

    // RCPT TO
    $send("RCPT TO:<$to_email>");
    $res = $read();
    if (substr($res, 0, 3) !== '250' && substr($res, 0, 3) !== '251') {
        fclose($socket);
        return ['success' => false, 'message' => "RCPT TO rejected: $res"];
    }

    // DATA
    $send("DATA");
    $res = $read();
    if (substr($res, 0, 3) !== '354') {
        fclose($socket);
        return ['success' => false, 'message' => "DATA handshake rejected: $res"];
    }

    // Headers & Payload (RFC 5322, RFC 2046 & RFC 2387 compliant)
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromHeader = sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($from_name), $from_email);
    $toHeader = sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($to_name), $to_email);
    $fromDomain = substr(strrchr($from_email, "@"), 1) ?: 'gmail.com';
    $msgId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . $fromDomain . '>';

    if (empty($text_body)) {
        $text_body = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html_body));
        $text_body = trim(preg_replace("/[\r\n]+/", "\n", $text_body));
    }

    static $cachedLogoData = null;
    if ($cachedLogoData === null) {
        $logoPath = dirname(__DIR__) . '/images/BCP_LOGO.png';
        if (file_exists($logoPath) && is_readable($logoPath)) {
            $cachedLogoData = chunk_split(base64_encode(file_get_contents($logoPath)));
        } else {
            $cachedLogoData = false;
        }
    }
    $hasLogo = ($cachedLogoData !== false);

    $msg = "From: $fromHeader\r\n";
    $msg .= "To: $toHeader\r\n";
    $msg .= "Reply-To: <$from_email>\r\n";
    $msg .= "Subject: $encodedSubject\r\n";
    $msg .= "Date: " . date('r') . "\r\n";
    $msg .= "Message-ID: $msgId\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "X-Mailer: BCP-Portal-Mailer/2.0\r\n";

    if ($hasLogo) {
        $relBoundary = 'bcp_rel_' . bin2hex(random_bytes(10));
        $altBoundary = 'bcp_alt_' . bin2hex(random_bytes(10));

        $msg .= "Content-Type: multipart/related; boundary=\"$relBoundary\"\r\n\r\n";

        // Nested multipart/alternative (Plain text + HTML)
        $msg .= "--$relBoundary\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";

        // 1. Plain text alternative
        $msg .= "--$altBoundary\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($text_body)) . "\r\n";

        // 2. HTML alternative (references cid:bcp_school_logo)
        $msg .= "--$altBoundary\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($html_body)) . "\r\n";

        $msg .= "--$altBoundary--\r\n\r\n";

        // 3. Inline BCP School Logo
        $msg .= "--$relBoundary\r\n";
        $msg .= "Content-Type: image/png; name=\"BCP_LOGO.png\"\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "Content-ID: <bcp_school_logo>\r\n";
        $msg .= "Content-Disposition: inline; filename=\"BCP_LOGO.png\"\r\n\r\n";
        $msg .= $cachedLogoData . "\r\n";

        $msg .= "--$relBoundary--\r\n.";
    } else {
        $boundary = 'bcp_boundary_' . bin2hex(random_bytes(12));
        $msg .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";

        $msg .= "--$boundary\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($text_body)) . "\r\n";

        $msg .= "--$boundary\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($html_body)) . "\r\n";

        $msg .= "--$boundary--\r\n.";
    }

    $send($msg);
    $res = $read();
    $send("QUIT");
    fclose($socket);

    if (substr($res, 0, 3) === '250') {
        return ['success' => true, 'method' => 'smtp', 'message' => 'Email delivered via SMTP.'];
    }
    return ['success' => false, 'method' => 'smtp', 'message' => "Message rejected by SMTP server: $res"];
}

/**
 * Generate and store a fresh 6-digit MFA verification code record in the database.
 * Executes in ~2ms without blocking on external SMTP network latency.
 */
function issue_mfa_code(
    mysqli $conn,
    int $user_id,
    string $user_email,
    string $user_name,
    string $purpose = 'login'
): array {
    // 1. Fetch system MFA settings
    $expiry_minutes = 10;
    $resend_cooldown = 60;
    try {
        $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('mfa_expiry_minutes', 'mfa_resend_cooldown')");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if ($r['setting_key'] === 'mfa_expiry_minutes') $expiry_minutes = max(1, (int)$r['setting_value']);
                if ($r['setting_key'] === 'mfa_resend_cooldown') $resend_cooldown = max(10, (int)$r['setting_value']);
            }
        }
    } catch (Throwable $e) {}

    // 2. Check resend cooldown
    $chk_stmt = $conn->prepare("SELECT id, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds FROM mfa_codes WHERE user_id = ? AND purpose = ? AND is_used = 0 ORDER BY id DESC LIMIT 1");
    if ($chk_stmt) {
        $chk_stmt->bind_param('is', $user_id, $purpose);
        $chk_stmt->execute();
        $latest = $chk_stmt->get_result()->fetch_assoc();
        $chk_stmt->close();

        if ($latest && isset($latest['elapsed_seconds'])) {
            $elapsed = (int)$latest['elapsed_seconds'];
            if ($elapsed >= 0 && $elapsed < $resend_cooldown) {
                $wait = $resend_cooldown - $elapsed;
                return [
                    'success'  => false,
                    'cooldown' => true,
                    'wait'     => $wait,
                    'message'  => "Please wait {$wait} seconds before requesting a new verification code."
                ];
            }
        }
    }

    // 3. Invalidate prior active codes for this user & purpose
    $up_stmt = $conn->prepare("UPDATE mfa_codes SET is_used = 1 WHERE user_id = ? AND purpose = ? AND is_used = 0");
    if ($up_stmt) {
        $up_stmt->bind_param('is', $user_id, $purpose);
        $up_stmt->execute();
        $up_stmt->close();
    }

    // 4. Generate cryptographically secure 6-digit numeric OTP code
    $code = sprintf('%06d', random_int(100000, 999999));
    $expires_at = date('Y-m-d H:i:s', time() + ($expiry_minutes * 60));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Agent', 0, 255);

    // 5. Store in database
    $ins_stmt = $conn->prepare("INSERT INTO mfa_codes (user_id, email, code, purpose, expires_at, is_used, attempts, max_attempts, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, 0, 0, 5, ?, ?)");
    if (!$ins_stmt) {
        return ['success' => false, 'message' => 'Database error preparing MFA verification record: ' . $conn->error];
    }
    $ins_stmt->bind_param('issssss', $user_id, $user_email, $code, $purpose, $expires_at, $ip, $ua);
    $ins_stmt->execute();
    $code_id = $conn->insert_id;
    $ins_stmt->close();

    $masked_email = mask_email($user_email);

    return [
        'success'         => true,
        'code_id'         => $code_id,
        'code'            => $code,
        'email'           => $user_email,
        'email_masked'    => $masked_email,
        'expires_in'      => $expiry_minutes * 60,
        'expiry_minutes'  => $expiry_minutes,
        'resend_cooldown' => $resend_cooldown,
        'message'         => "Verification code generated for {$masked_email}."
    ];
}

/**
 * Deliver the issued 6-digit MFA verification code to user email via SMTP/mail.
 * Can run synchronously or in the background without holding up the credentials check.
 */
function dispatch_mfa_email(
    mysqli $conn,
    int $code_id,
    string $user_email,
    string $user_name,
    string $code,
    int $expiry_minutes = 1
): array {
    $masked_email = mask_email($user_email);
    $formatted_code = substr($code, 0, 3) . ' ' . substr($code, 3, 3);
    $email_html = "
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset='UTF-8'/>
      <meta name='viewport' content='width=device-width, initial-scale=1.0'/>
      <title>Two-Factor Authentication Security Code</title>
    </head>
    <body style='margin:0; padding:20px; font-family:-apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color:#f8fafc; color:#1e293b;'>
      <table align='center' border='0' cellpadding='0' cellspacing='0' width='100%' style='max-width:540px; margin:0 auto; background:#ffffff; border-radius:14px; border:1px solid #e2e8f0; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,0.06);'>
        <!-- Header -->
        <tr>
          <td style='background:linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); padding:28px 24px; text-align:center;'>
            <div style='display:inline-block; background:#ffffff; border-radius:50%; padding:8px; box-shadow:0 4px 14px rgba(0,0,0,0.18); margin-bottom:12px;'>
              <img src='cid:bcp_school_logo' alt='Bestlink College of the Philippines Logo' width='60' height='60' style='display:block; width:60px; height:60px; object-fit:contain;' />
            </div>
            <h1 style='color:#ffffff; font-size:1.25rem; font-weight:800; margin:0 0 4px 0; letter-spacing:0.3px;'>Bestlink College of the Philippines</h1>
            <p style='color:#bfdbfe; font-size:0.84rem; margin:0; font-weight:600;'>Co-Curricular Management System</p>
          </td>
        </tr>
        <!-- Content -->
        <tr>
          <td style='padding:32px 28px;'>
            <div style='display:flex; align-items:center; gap:8px; margin-bottom:16px;'>
              <span style='background:#eff6ff; color:#2563eb; font-size:0.75rem; font-weight:800; padding:4px 10px; border-radius:9999px; text-transform:uppercase; letter-spacing:0.5px;'>Security Verification</span>
            </div>
            <h2 style='font-size:1.18rem; font-weight:800; color:#0f172a; margin:0 0 12px 0;'>Two-Step Sign In Verification</h2>
            <p style='font-size:0.92rem; color:#475569; line-height:1.55; margin:0 0 20px 0;'>
              Hello <strong>" . htmlspecialchars($user_name) . "</strong>,<br/>
              A request was made to sign in to your BCP Co-Curricular Management System account. Use the authorization code below to complete your sign in:
            </p>

            <!-- Verification Code Box -->
            <div style='background:#f8fafc; border:2px dashed #2563eb; border-radius:12px; padding:22px 16px; text-align:center; margin:22px 0;'>
              <div style='font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;'>6-Digit Authorization Code</div>
              <div style='font-family:Courier, monospace, monospace; font-size:2.2rem; font-weight:800; letter-spacing:10px; color:#1e3a8a; line-height:1;'>
                {$formatted_code}
              </div>
              <div style='font-size:0.80rem; font-weight:600; color:#2563eb; margin-top:10px;'>
                Expires in {$expiry_minutes} " . ($expiry_minutes === 1 ? 'minute' : 'minutes') . "
              </div>
            </div>

            <p style='font-size:0.85rem; color:#64748b; line-height:1.5; margin:0 0 20px 0;'>
              Enter this code on the verification screen to authorize your session. If you did not make this sign-in request, someone may be trying to access your account. Please change your password immediately.
            </p>

            <div style='border-top:1px solid #e2e8f0; padding-top:18px; margin-top:20px; font-size:0.78rem; color:#94a3b8; line-height:1.4;'>
              <strong>Security Notice:</strong> BCP Staff or System Administrators will never ask for your verification code. Never share this code with anyone.
            </div>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style='background:#f1f5f9; padding:16px 24px; text-align:center; font-size:0.75rem; color:#64748b; border-top:1px solid #e2e8f0;'>
            Bestlink College of the Philippines &bull; Student Affairs &amp; Services<br/>
            Intelligent Report &amp; AI-Based Activity Recommendations Portal
          </td>
        </tr>
      </table>
    </body>
    </html>
    ";

    // Dispatch Email
    $mailRes = send_system_email(
        $user_email,
        $user_name,
        "Your Verification Code: {$code} — BCP Co-Curricular Portal",
        $email_html,
        "Your verification code is: {$code}. It expires in {$expiry_minutes} minute" . ($expiry_minutes === 1 ? '' : 's') . ".",
        $conn
    );

    // Audit Log
    require_once __DIR__ . '/notification_actions.php';
    log_audit(
        $conn,
        0,
        'AUTH_MFA_CODE_SENT',
        'mfa_codes',
        $code_id,
        "Dispatched 6-digit MFA verification code to {$user_email} via " . ($mailRes['method'] ?? 'mail'),
        'info',
        $user_name
    );

    return $mailRes;
}

/**
 * Generate and dispatch a 6-digit Multi-Factor Authentication (MFA) verification code.
 * Backward compatible wrapper for issue_mfa_code + dispatch_mfa_email.
 */
function send_mfa_verification_code(
    mysqli $conn,
    int $user_id,
    string $user_email,
    string $user_name,
    string $purpose = 'login',
    bool $async = false
): array {
    $res = issue_mfa_code($conn, $user_id, $user_email, $user_name, $purpose);
    if (!$res['success']) {
        return $res;
    }

    if ($async) {
        return $res;
    }

    $mailRes = dispatch_mfa_email(
        $conn,
        (int)$res['code_id'],
        $user_email,
        $user_name,
        $res['code'],
        (int)($res['expiry_minutes'] ?? 1)
    );

    $res['mail_result'] = $mailRes;
    $res['message'] = "Verification code has been dispatched to {$res['email_masked']}.";
    return $res;
}

/**
 * Verify submitted MFA code against the canonical database record.
 */
function verify_mfa_code(
    mysqli $conn,
    int $user_id,
    string $code,
    string $purpose = 'login'
): array {
    $code = preg_replace('/\s+/', '', trim($code));

    if (empty($code) || !preg_match('/^[0-9]{6}$/', $code)) {
        return [
            'success' => false,
            'message' => 'Verification code must be exactly 6 numeric digits.'
        ];
    }

    $stmt = $conn->prepare("
        SELECT id, code, expires_at, attempts, max_attempts, is_used
        FROM mfa_codes
        WHERE user_id = ? AND purpose = ? AND is_used = 0
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }

    $stmt->bind_param('is', $user_id, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return [
            'success' => false,
            'message' => 'No active verification code found. Please request a new code.'
        ];
    }

    // Check expiration
    if (strtotime($row['expires_at']) < time()) {
        $conn->query("UPDATE mfa_codes SET is_used = 1 WHERE id = " . (int)$row['id']);
        return [
            'success' => false,
            'expired' => true,
            'message' => 'The verification code has expired. Please request a new code.'
        ];
    }

    // Check max attempts
    if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
        $conn->query("UPDATE mfa_codes SET is_used = 1 WHERE id = " . (int)$row['id']);
        return [
            'success' => false,
            'locked'  => true,
            'message' => 'Maximum verification attempts exceeded. This code is locked. Please request a new code.'
        ];
    }

    // Compare code
    if ($code !== $row['code']) {
        $new_attempts = (int)$row['attempts'] + 1;
        $conn->query("UPDATE mfa_codes SET attempts = {$new_attempts} WHERE id = " . (int)$row['id']);
        $remaining = max(0, (int)$row['max_attempts'] - $new_attempts);

        require_once __DIR__ . '/notification_actions.php';
        log_audit(
            $conn,
            $user_id,
            'AUTH_MFA_FAILED',
            'mfa_codes',
            (int)$row['id'],
            "Failed verification attempt with invalid code. Remaining attempts: {$remaining}",
            'warning'
        );

        if ($remaining === 0) {
            $conn->query("UPDATE mfa_codes SET is_used = 1 WHERE id = " . (int)$row['id']);
            return [
                'success' => false,
                'locked'  => true,
                'message' => 'Incorrect verification code. Maximum attempts reached. Please request a new code.'
            ];
        }

        return [
            'success'   => false,
            'remaining' => $remaining,
            'message'   => "Incorrect verification code. You have {$remaining} attempt(s) remaining."
        ];
    }

    // Success! Mark code as used
    $conn->query("UPDATE mfa_codes SET is_used = 1 WHERE id = " . (int)$row['id']);
    $conn->query("UPDATE users SET last_mfa_verified_at = NOW() WHERE id = {$user_id}");

    require_once __DIR__ . '/notification_actions.php';
    log_audit(
        $conn,
        $user_id,
        'AUTH_MFA_VERIFIED',
        'mfa_codes',
        (int)$row['id'],
        "Multi-factor email authorization verified successfully.",
        'info'
    );

    return [
        'success' => true,
        'message' => 'Authentication verified successfully!'
    ];
}
