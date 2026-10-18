<?php
session_start();

// If explicitly timed out, expired, or logged out, completely destroy existing session & cookie
if (!empty($_GET['timeout']) || !empty($_GET['expired']) || !empty($_GET['logout']) || !empty($_GET['signout'])) {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params['path'], $params['domain'],
      $params['secure'], $params['httponly']
    );
  }
  @session_destroy();
  session_start();
}

// Check session timeout if user was logged in
if (!empty($_SESSION['user_id'])) {
  $sess_timeout = 300; // 5 minutes
  $last_act = (int)($_SESSION['last_activity'] ?? 0);
  if ($last_act <= 0 || (time() - $last_act) > $sess_timeout) {
    // Expired or stale session - cleanly destroy without looping
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
      );
    }
    @session_destroy();
    session_start();
  } else {
    // Still active and valid session
    header('Location: ../dashboard/dashboard.php');
    exit;
  }
}

// Check if user has an active pending Multi-Factor Authentication challenge (strictly 'super_admin', 'ssc', 'admin' or 'staff' roles)
$mfa_pending = !empty($_SESSION['mfa_pending']) && !empty($_SESSION['mfa_user_id']);
if ($mfa_pending && !in_array($_SESSION['mfa_role'] ?? '', ['super_admin', 'admin', 'ssc', 'staff'], true)) {
  unset(
    $_SESSION['mfa_pending'], $_SESSION['mfa_user_id'], $_SESSION['mfa_username'],
    $_SESSION['mfa_email'], $_SESSION['mfa_name'], $_SESSION['mfa_role'],
    $_SESSION['mfa_profile_pic'], $_SESSION['mfa_created_at']
  );
  $mfa_pending = false;
}

// Load database engine to verify connectivity & handle pending MFA challenges
require_once __DIR__ . '/../shared/db.php';

$mfa_pending_email = '';
$mfa_remaining_seconds = 600;

if ($mfa_pending && !empty($conn) && !empty($db_connected)) {
  require_once __DIR__ . '/../shared/mail_helper.php';
  $mfa_pending_email = mask_email($_SESSION['mfa_email'] ?? '');
  $mfa_uid = (int)$_SESSION['mfa_user_id'];
  $c_res = $conn->query("SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) as rem FROM mfa_codes WHERE user_id = $mfa_uid AND purpose = 'login' AND is_used = 0 ORDER BY id DESC LIMIT 1");
  if ($c_res && $crow = $c_res->fetch_assoc()) {
    $mfa_remaining_seconds = max(0, (int)$crow['rem']);
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sign In – BCP Co-Curricular Management System</title>
  <link rel="stylesheet" href="../css/auth.css?v=<?= filemtime(__DIR__ . '/../css/auth.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
</head>

<body>
  <div class="outer">
    <div class="card">
      <!-- Left panel -->
      <div class="left">
        <div class="left-top">
          <img src="../images/BCP_LOGO.png" alt="BCP Logo" class="left-logo" />
          <p class="left-school">Bestlink College of the Philippines</p>
        </div>
        <div class="left-body">
          <h1>Co-Curricular Management System</h1>
          <p class="subtitle" style="font-weight: 600; color: #93c5fd; line-height: 1.4;">with Intelligent Report &amp; AI-Based Activity Recommendations</p>
          <p>
            An intelligent portal for Bestlink College of the Philippines empowering student organizations, dynamic event tracking, <span>AI-based activity recommendations</span>, and <span>intelligent analytical reporting</span>.
          </p>
        </div>
      </div>

      <!-- Right panel -->
      <div class="right">
        <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />

        <!-- ────────────────────────────────────────── -->
        <!-- VIEW 1: USERNAME & PASSWORD CREDENTIALS -->
        <!-- ────────────────────────────────────────── -->
        <div id="credentialsSection" style="<?= $mfa_pending ? 'display:none;' : 'width:100%;' ?>">
          <h2>Sign In Account</h2>

          <?php if (!empty($_GET['timeout'])): ?>
          <div class="auth-warning" style="background:#fef3c7; border:1px solid #f59e0b; color:#92400e; padding:12px 14px; border-radius:10px; font-size:0.86rem; font-weight:600; display:flex; align-items:center; gap:10px; margin-bottom:18px; text-align:left; box-shadow:0 2px 6px rgba(245,158,11,0.15);">
            <i class="fa-solid fa-clock-rotate-left" style="color:#d97706; font-size:1.2rem; flex-shrink:0;"></i>
            <span>Your session has expired due to inactivity. Please sign in again to continue.</span>
          </div>
          <?php endif; ?>

          <?php if (empty($db_connected)): ?>
          <div class="auth-warning" style="background:#fef2f2; border:1px solid #f87171; color:#991b1b; padding:12px 14px; border-radius:10px; font-size:0.86rem; font-weight:500; display:flex; align-items:flex-start; gap:10px; margin-bottom:18px; text-align:left; line-height:1.45; box-shadow:0 2px 6px rgba(239,68,68,0.15);">
            <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444; font-size:1.2rem; flex-shrink:0; margin-top:2px;"></i>
            <div>
              <strong style="display:block; margin-bottom:4px; color:#b91c1c;">Database Not Connected</strong>
              Running on Railway? Make sure your Web service has your MySQL variables linked (<code>MYSQL_URL</code> or <code>MYSQLHOST</code>), or <a href="../shared/setup.php" style="color:#1d4ed8; text-decoration:underline; font-weight:600;">open Setup &amp; DB Configuration</a> to connect.
            </div>
          </div>
          <?php endif; ?>

          <div class="auth-error" id="authError" style="display:none;"></div>

          <form id="signinForm" style="width:100%">
            <div class="form-group">
              <label>
                <i class="fa-solid fa-user"></i>
                Username or Email
              </label>
              <input type="text" id="username" autocomplete="username" />
            </div>

            <div class="form-group">
              <label>
                <i class="fa-solid fa-lock"></i>
                Password
              </label>
              <div class="password-wrap">
                <input type="password" id="password" autocomplete="current-password" />
                <button type="button" class="toggle-pw" data-target="password" title="Toggle visibility">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>

            <button type="submit" class="btn-signin" id="btnSignin">
              Sign In
              <i class="fa-solid fa-arrow-right"></i>
            </button>
          </form>
        </div>

        <!-- ────────────────────────────────────────── -->
        <!-- VIEW 2: MULTI-FACTOR AUTHENTICATION (MFA) -->
        <!-- ────────────────────────────────────────── -->
        <div id="mfaSection" class="mfa-container" style="<?= $mfa_pending ? 'display:flex;' : 'display:none;' ?>">
          <h2>Security Verification</h2>
          <p class="mfa-subtitle">
            Enter the 6-digit verification code sent to<br/>
            <span id="mfaEmailMasked" class="mfa-target-email"><?= htmlspecialchars($mfa_pending_email) ?></span>
          </p>

          <div class="auth-error" id="mfaError" style="display:none; margin-bottom:14px; width:100%;"></div>
          <div class="auth-success" id="mfaSuccess" style="display:none; background:#ecfdf5; border:1px solid #10b981; color:#065f46; padding:10px 14px; border-radius:8px; font-size:0.84rem; font-weight:600; margin-bottom:14px; width:100%; text-align:center;"></div>

          <form id="mfaForm" style="width:100%" onsubmit="handleMfaVerify(event)">
            <!-- 6 Discrete OTP Input Boxes -->
            <div class="otp-inputs-row" id="otpContainer">
              <input type="text" class="otp-box" id="otp1" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code" autofocus />
              <input type="text" class="otp-box" id="otp2" maxlength="1" pattern="[0-9]" inputmode="numeric" />
              <input type="text" class="otp-box" id="otp3" maxlength="1" pattern="[0-9]" inputmode="numeric" />
              <input type="text" class="otp-box" id="otp4" maxlength="1" pattern="[0-9]" inputmode="numeric" />
              <input type="text" class="otp-box" id="otp5" maxlength="1" pattern="[0-9]" inputmode="numeric" />
              <input type="text" class="otp-box" id="otp6" maxlength="1" pattern="[0-9]" inputmode="numeric" />
            </div>

            <!-- Timer & Resend Controls -->
            <div class="mfa-meta-bar">
              <div class="mfa-timer" id="timerDisplayWrap">
                <i class="fa-regular fa-clock"></i>
                <span>Expires: <strong id="mfaTimerCount">10:00</strong></span>
              </div>
              <button type="button" class="btn-resend-link" id="btnResendMfa" onclick="handleMfaResend()">
                <i class="fa-solid fa-rotate-right"></i>
                <span id="resendText">Resend Code</span>
              </button>
            </div>

            <!-- Authorize Button -->
            <button type="submit" class="btn-signin" id="btnVerifyMfa">
              Authorize &amp; Sign In
              <i class="fa-solid fa-check"></i>
            </button>
          </form>

          <!-- Back to Credentials -->
          <div class="mfa-back-wrap">
            <button type="button" class="btn-mfa-back" onclick="handleMfaCancel()">
              <i class="fa-solid fa-arrow-left"></i>
              Use a different account
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
    // Purge any stale session expiry flags from previous browser sessions
    try {
      localStorage.removeItem('bcp_sms_session_expired');
      localStorage.removeItem('bcp_sms_session_active');
    } catch (e) {}

    // ──────────────────────────────────────────
    // 1. SIGN IN (CREDENTIALS SUBMISSION)
    // ──────────────────────────────────────────
    const signinForm = document.getElementById('signinForm');
    const authError = document.getElementById('authError');
    const btnSignin = document.getElementById('btnSignin');

    signinForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      const username = document.getElementById('username').value.trim();
      const password = document.getElementById('password').value;

      authError.style.display = 'none';
      if (!username || !password) return showAuthError('Please enter your username and password.');

      btnSignin.disabled = true;
      btnSignin.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Checking credentials…';

      const fd = new FormData();
      fd.set('action', 'login');
      fd.set('username', username);
      fd.set('password', password);

      try {
        const res = await fetch('../shared/auth_actions.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body: fd
        });
        const data = await res.json().catch(() => null);

        if (data && data.success) {
          if (data.mfa_required) {
            // Smoothly switch to Multi-Factor Authentication view
            switchToMfaView(data.email_masked, data.expires_in, data.resend_cooldown);
          } else {
            // Direct sign in
            try {
              localStorage.removeItem('bcp_sms_session_expired');
              localStorage.setItem('bcp_sms_session_active', Date.now().toString());
            } catch (err) {}
            window.location.href = '../dashboard/loading.php';
          }
        } else {
          showAuthError((data && data.message) ? data.message : 'Unable to connect to authentication server. Please check database configuration.');
          btnSignin.disabled = false;
          btnSignin.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
        }
      } catch (err) {
        showAuthError('Request failed: ' + (err.message || 'Please check your connection and try again.'));
        btnSignin.disabled = false;
        btnSignin.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
      }
    });

    function showAuthError(msg) {
      if (typeof msg === 'string' && (msg.includes('<a ') || msg.includes('setup.php') || msg.includes('Database connection'))) {
        authError.innerHTML = msg;
      } else {
        authError.textContent = msg;
      }
      authError.style.display = 'block';
    }

    // ──────────────────────────────────────────
    // 2. MULTI-FACTOR AUTHENTICATION (MFA)
    // ──────────────────────────────────────────
    const credentialsSection = document.getElementById('credentialsSection');
    const mfaSection = document.getElementById('mfaSection');
    const mfaEmailMasked = document.getElementById('mfaEmailMasked');
    const mfaError = document.getElementById('mfaError');
    const mfaSuccess = document.getElementById('mfaSuccess');
    const btnVerifyMfa = document.getElementById('btnVerifyMfa');
    const btnResendMfa = document.getElementById('btnResendMfa');
    const resendText = document.getElementById('resendText');
    const mfaTimerCount = document.getElementById('mfaTimerCount');

    const otpBoxes = [
      document.getElementById('otp1'),
      document.getElementById('otp2'),
      document.getElementById('otp3'),
      document.getElementById('otp4'),
      document.getElementById('otp5'),
      document.getElementById('otp6')
    ];

    let countdownTimer = null;
    let resendTimer = null;
    let remainingExpirySeconds = <?= (int)$mfa_remaining_seconds ?>;
    let resendCooldownSeconds = 0;

    function switchToMfaView(maskedEmail, expiresIn, resendCooldown) {
      credentialsSection.style.display = 'none';
      mfaSection.style.display = 'flex';
      mfaEmailMasked.textContent = maskedEmail;
      mfaError.style.display = 'none';
      mfaSuccess.style.display = 'none';

      clearOtpBoxes();

      startExpiryCountdown(expiresIn || 600);
      startResendCooldown(resendCooldown || 60);

      setTimeout(() => {
        if (otpBoxes[0]) otpBoxes[0].focus();
      }, 150);
    }

    // Format seconds as MM:SS
    function formatTime(sec) {
      const m = Math.floor(sec / 60);
      const s = sec % 60;
      return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function startExpiryCountdown(sec) {
      clearInterval(countdownTimer);
      remainingExpirySeconds = sec;
      mfaTimerCount.textContent = formatTime(remainingExpirySeconds);
      mfaTimerCount.parentElement.classList.remove('urgent');

      countdownTimer = setInterval(() => {
        remainingExpirySeconds--;
        if (remainingExpirySeconds <= 0) {
          clearInterval(countdownTimer);
          mfaTimerCount.textContent = '00:00';
          mfaTimerCount.parentElement.classList.add('urgent');
          showMfaError('Verification code has expired. Please request a new code.');
        } else {
          mfaTimerCount.textContent = formatTime(remainingExpirySeconds);
          if (remainingExpirySeconds <= 60) {
            mfaTimerCount.parentElement.classList.add('urgent');
          }
        }
      }, 1000);
    }

    function startResendCooldown(sec) {
      clearInterval(resendTimer);
      resendCooldownSeconds = sec;
      btnResendMfa.disabled = true;
      resendText.textContent = `Resend in ${resendCooldownSeconds}s`;

      resendTimer = setInterval(() => {
        resendCooldownSeconds--;
        if (resendCooldownSeconds <= 0) {
          clearInterval(resendTimer);
          btnResendMfa.disabled = false;
          resendText.textContent = 'Resend Code';
        } else {
          resendText.textContent = `Resend in ${resendCooldownSeconds}s`;
        }
      }, 1000);
    }

    function clearOtpBoxes() {
      otpBoxes.forEach(box => {
        box.value = '';
        box.classList.remove('filled');
      });
      if (otpBoxes[0]) otpBoxes[0].focus();
    }

    function getEnteredOtp() {
      return otpBoxes.map(b => b.value.trim()).join('');
    }

    function showMfaError(msg) {
      mfaSuccess.style.display = 'none';
      mfaError.textContent = msg;
      mfaError.style.display = 'block';
    }

    function showMfaSuccess(msg) {
      mfaError.style.display = 'none';
      mfaSuccess.textContent = msg;
      mfaSuccess.style.display = 'block';
    }

    // OTP Input Boxes Event Handling (Auto-advance, Backspace, Arrow keys, Paste & Autofill)
    otpBoxes.forEach((box, idx) => {
      // Input event: handles typing and browser one-time-code autofill
      box.addEventListener('input', function (e) {
        const raw = this.value.replace(/[^0-9]/g, '');

        if (raw.length > 1) {
          // Multi-digit entry (e.g. mobile SMS/Email autofill or rapid input)
          const digits = raw.slice(0, 6).split('');
          for (let i = 0; i < otpBoxes.length; i++) {
            if (i < digits.length) {
              otpBoxes[i].value = digits[i];
              otpBoxes[i].classList.add('filled');
            } else {
              otpBoxes[i].value = '';
              otpBoxes[i].classList.remove('filled');
            }
          }
          const nextIdx = Math.min(digits.length, otpBoxes.length - 1);
          if (otpBoxes[nextIdx]) {
            otpBoxes[nextIdx].focus();
            otpBoxes[nextIdx].select();
          }
          if (getEnteredOtp().length === 6) {
            triggerMfaVerify();
          }
          return;
        }

        // Single digit entry
        this.value = raw ? raw.slice(-1) : '';
        if (this.value) {
          this.classList.add('filled');
          if (idx < otpBoxes.length - 1) {
            otpBoxes[idx + 1].focus();
            otpBoxes[idx + 1].select();
          }
        } else {
          this.classList.remove('filled');
        }

        // Auto verify if all 6 digits entered
        if (getEnteredOtp().length === 6) {
          triggerMfaVerify();
        }
      });

      // Keydown: handle Backspace and Arrow navigation
      box.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace') {
          if (!this.value && idx > 0) {
            otpBoxes[idx - 1].focus();
            otpBoxes[idx - 1].value = '';
            otpBoxes[idx - 1].classList.remove('filled');
          }
        } else if (e.key === 'ArrowLeft' && idx > 0) {
          otpBoxes[idx - 1].focus();
        } else if (e.key === 'ArrowRight' && idx < otpBoxes.length - 1) {
          otpBoxes[idx + 1].focus();
        }
      });

      // Paste: handle pasting complete 6-digit code
      box.addEventListener('paste', function (e) {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text');
        const digits = pasted.replace(/[^0-9]/g, '').slice(0, 6);

        if (digits.length > 0) {
          for (let i = 0; i < otpBoxes.length; i++) {
            if (digits[i]) {
              otpBoxes[i].value = digits[i];
              otpBoxes[i].classList.add('filled');
            } else {
              otpBoxes[i].value = '';
              otpBoxes[i].classList.remove('filled');
            }
          }
          const nextFocus = Math.min(digits.length, otpBoxes.length - 1);
          if (otpBoxes[nextFocus]) otpBoxes[nextFocus].focus();

          if (digits.length === 6) {
            triggerMfaVerify();
          }
        }
      });
    });

    function triggerMfaVerify() {
      if (!isVerifying) {
        btnVerifyMfa.click();
      }
    }

    let isVerifying = false;

    // Verify submission
    window.handleMfaVerify = async function (e) {
      if (e) e.preventDefault();
      if (isVerifying) return;

      const code = getEnteredOtp();
      mfaError.style.display = 'none';
      mfaSuccess.style.display = 'none';

      if (code.length !== 6) {
        return showMfaError('Please enter all 6 digits of your verification code.');
      }

      isVerifying = true;
      btnVerifyMfa.disabled = true;
      btnVerifyMfa.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Verifying…';

      const fd = new FormData();
      fd.set('action', 'verify_mfa');
      fd.set('code', code);

      try {
        const data = await fetch('../shared/auth_actions.php', { method: 'POST', body: fd }).then(r => r.json());

        if (data.success) {
          showMfaSuccess('Verification successful! Access authorized.');
          try {
            localStorage.removeItem('bcp_sms_session_expired');
            localStorage.setItem('bcp_sms_session_active', Date.now().toString());
          } catch (err) {}
          setTimeout(() => {
            window.location.href = '../dashboard/loading.php';
          }, 350);
        } else {
          showMfaError(data.message || 'Incorrect verification code. Please check and try again.');
          btnVerifyMfa.disabled = false;
          btnVerifyMfa.innerHTML = 'Authorize &amp; Sign In <i class="fa-solid fa-check"></i>';
          clearOtpBoxes();
        }
      } catch (err) {
        showMfaError('Network error during verification. Please try again.');
        btnVerifyMfa.disabled = false;
        btnVerifyMfa.innerHTML = 'Authorize &amp; Sign In <i class="fa-solid fa-check"></i>';
      } finally {
        isVerifying = false;
      }
    };

    // Resend verification code
    window.handleMfaResend = async function () {
      if (btnResendMfa.disabled) return;

      mfaError.style.display = 'none';
      btnResendMfa.disabled = true;
      resendText.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Sending…';

      const fd = new FormData();
      fd.set('action', 'resend_mfa');

      try {
        const data = await fetch('../shared/auth_actions.php', { method: 'POST', body: fd }).then(r => r.json());

        if (data.success) {
          showMfaSuccess('A fresh 6-digit code has been dispatched to your email.');
          clearOtpBoxes();
          startExpiryCountdown(data.expires_in || 600);
          startResendCooldown(data.resend_cooldown || 60);
        } else {
          showMfaError(data.message || 'Failed to resend code.');
          if (data.cooldown && data.wait) {
            startResendCooldown(data.wait);
          } else {
            btnResendMfa.disabled = false;
            resendText.textContent = 'Resend Code';
          }
        }
      } catch (err) {
        showMfaError('Failed to resend verification code. Please try again.');
        btnResendMfa.disabled = false;
        resendText.textContent = 'Resend Code';
      }
    };

    // Cancel MFA and return to sign in
    window.handleMfaCancel = async function () {
      try {
        const fd = new FormData();
        fd.set('action', 'cancel_mfa');
        await fetch('../shared/auth_actions.php', { method: 'POST', body: fd });
      } catch (e) {}

      clearInterval(countdownTimer);
      clearInterval(resendTimer);
      clearOtpBoxes();

      mfaSection.style.display = 'none';
      credentialsSection.style.display = 'block';
      btnSignin.disabled = false;
      btnSignin.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
      document.getElementById('password').value = '';
      document.getElementById('username').focus();
    };

    // Initialize timers if loaded with pending MFA
    <?php if ($mfa_pending): ?>
      startExpiryCountdown(<?= (int)$mfa_remaining_seconds ?>);
      startResendCooldown(30);
      setTimeout(() => { if (otpBoxes[0]) otpBoxes[0].focus(); }, 150);
    <?php endif; ?>

    // Toggle password visibility
    document.querySelectorAll('.toggle-pw').forEach(btn => {
      btn.addEventListener('click', () => {
        const inp = document.getElementById(btn.dataset.target);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        btn.querySelector('i').className = inp.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
      });
    });
  </script>
</body>

</html>