<?php
// ============================================================
//  SESSION_TIMEOUT_MODAL.PHP (shared/)
//  Enterprise-grade Inactivity Monitor & Warning Modal
//  Handles cross-tab sync, countdown alert, keepalive pings,
//  and graceful timeout redirects.
// ============================================================

$app_root_rel = $APP_ROOT ?? '../';
$sess_timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 600; // 10 minutes default (600 seconds)
$warn_lead    = 60; // Show warning 60 seconds (1 minute) before expiration
?>

<!-- Session Timeout Warning Modal -->
<div id="sessionTimeoutOverlay" class="session-timeout-overlay" aria-hidden="true" style="display:none;">
  <div class="session-timeout-modal" role="dialog" aria-modal="true" aria-labelledby="timeoutModalTitle">
    <div class="timeout-header">
      <div class="timeout-icon-pulse">
        <div class="timeout-pulse-ring"></div>
        <div class="timeout-pulse-ring ring-2"></div>
        <div class="timeout-icon-circle">
          <i class="fa-solid fa-hourglass-half"></i>
        </div>
      </div>
      <h3 id="timeoutModalTitle">Session Expiring Soon</h3>
      <p class="timeout-desc">You have been inactive for a while. For your security and to safeguard institutional data, you will be automatically signed out in:</p>
    </div>

    <div class="timeout-body">
      <div class="timeout-clock-wrapper">
        <div class="timeout-clock-value" id="sessionClockDisplay">01:00</div>
        <div class="timeout-clock-label">Time Remaining</div>
      </div>
      <div class="timeout-progress-track">
        <div class="timeout-progress-bar" id="sessionProgressBar" style="width: 100%;"></div>
      </div>
    </div>

    <div class="timeout-actions">
      <button type="button" class="timeout-btn timeout-btn-primary" id="btnExtendSession">
        <i class="fa-solid fa-arrows-rotate"></i>
        <span>Stay Signed In</span>
      </button>
      <button type="button" class="timeout-btn timeout-btn-secondary" id="btnSignOutSession">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>Sign Out Now</span>
      </button>
    </div>
  </div>
</div>

<!-- Session Extended Quick Toast -->
<div id="sessionToast" class="session-toast" style="display:none;">
  <i class="fa-solid fa-circle-check"></i>
  <span>Session extended. You are still signed in.</span>
</div>

<style>
/* ============================================================
   SESSION TIMEOUT MODAL STYLES (Modern BCP Aesthetics)
   ============================================================ */
.session-timeout-overlay {
  position: fixed;
  inset: 0;
  z-index: 999999;
  background: rgba(15, 23, 42, 0.72);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  animation: timeoutFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes timeoutFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.session-timeout-modal {
  background: #ffffff;
  border-radius: 20px;
  max-width: 440px;
  width: 100%;
  box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.4), 0 0 0 1px rgba(226, 232, 240, 0.8);
  padding: 32px 28px 26px;
  text-align: center;
  position: relative;
  overflow: hidden;
  animation: timeoutPopIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes timeoutPopIn {
  from { opacity: 0; transform: scale(0.92) translateY(12px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

/* Glowing Pulsing Icon */
.timeout-icon-pulse {
  position: relative;
  width: 72px;
  height: 72px;
  margin: 0 auto 18px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.timeout-icon-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
  border: 2px solid #f59e0b;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #d97706;
  font-size: 1.6rem;
  box-shadow: 0 10px 20px -5px rgba(245, 158, 11, 0.35);
  position: relative;
  z-index: 2;
}

.timeout-pulse-ring {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: rgba(245, 158, 11, 0.35);
  animation: timeoutPulse 2s cubic-bezier(0.25, 1, 0.5, 1) infinite;
  z-index: 1;
}

.timeout-pulse-ring.ring-2 {
  animation-delay: 0.65s;
}

@keyframes timeoutPulse {
  0% { transform: scale(0.85); opacity: 0.8; }
  100% { transform: scale(1.6); opacity: 0; }
}

.timeout-header h3 {
  font-size: 1.35rem;
  font-weight: 700;
  color: #0f2744;
  margin: 0 0 8px;
  letter-spacing: -0.02em;
}

.timeout-desc {
  font-size: 0.88rem;
  color: #64748b;
  line-height: 1.5;
  margin: 0 0 20px;
}

/* Digital Clock */
.timeout-clock-wrapper {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 14px 20px;
  margin-bottom: 12px;
}

.timeout-clock-value {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  font-size: 2.25rem;
  font-weight: 800;
  color: #b45309;
  letter-spacing: 0.05em;
  line-height: 1.1;
}

.timeout-clock-value.urgent {
  color: #dc2626;
  animation: clockBlink 1s ease infinite;
}

@keyframes clockBlink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.6; }
}

.timeout-clock-label {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #94a3b8;
  margin-top: 4px;
}

/* Progress bar */
.timeout-progress-track {
  width: 100%;
  height: 6px;
  background: #e2e8f0;
  border-radius: 999px;
  overflow: hidden;
  margin-bottom: 24px;
}

.timeout-progress-bar {
  height: 100%;
  background: linear-gradient(90deg, #f59e0b, #ea580c);
  border-radius: 999px;
  transition: width 0.95s linear;
}

.timeout-progress-bar.urgent {
  background: linear-gradient(90deg, #ef4444, #b91c1c);
}

/* Buttons */
.timeout-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.timeout-btn {
  width: 100%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 20px;
  border-radius: 10px;
  font-size: 0.92rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s ease;
  border: none;
  text-decoration: none;
}

.timeout-btn-primary {
  background: linear-gradient(135deg, #1e40af 0%, #0f2744 100%);
  color: #ffffff;
  box-shadow: 0 4px 14px rgba(30, 64, 175, 0.35);
}

.timeout-btn-primary:hover {
  background: linear-gradient(135deg, #1d4ed8 0%, #1e3a8a 100%);
  box-shadow: 0 6px 18px rgba(30, 64, 175, 0.45);
  transform: translateY(-1px);
}

.timeout-btn-primary:active {
  transform: translateY(0);
}

.timeout-btn-secondary {
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #cbd5e1;
}

.timeout-btn-secondary:hover {
  background: #e2e8f0;
  color: #1e293b;
}

/* Quick Toast */
.session-toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 999999;
  background: #0f172a;
  color: #f8fafc;
  padding: 12px 20px;
  border-radius: 12px;
  font-size: 0.88rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
  border: 1px solid rgba(255, 255, 255, 0.12);
  animation: toastSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.session-toast i {
  color: #22c55e;
  font-size: 1.1rem;
}

@keyframes toastSlideUp {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>

<script>
(function() {
  const TIMEOUT_CONFIG = {
    timeoutSeconds: <?= (int)$sess_timeout ?>,
    warningSeconds: <?= (int)$warn_lead ?>,
    keepAliveUrl: '<?= $app_root_rel ?>shared/auth_actions.php',
    logoutUrl: '<?= $app_root_rel ?>auth/signin.php?timeout=1',
    storageKeyActive: 'bcp_sms_last_active',
    storageKeyExpired: 'bcp_sms_session_expired'
  };

  const overlay = document.getElementById('sessionTimeoutOverlay');
  const clockDisplay = document.getElementById('sessionClockDisplay');
  const progressBar = document.getElementById('sessionProgressBar');
  const btnExtend = document.getElementById('btnExtendSession');
  const btnSignOut = document.getElementById('btnSignOutSession');
  const toast = document.getElementById('sessionToast');

  const pageLoadedAt = Date.now();
  let lastActive = pageLoadedAt;
  let lastServerPing = pageLoadedAt;
  let isModalOpen = false;
  let isExpiredTriggered = false;
  let toastTimer = null;
  let throttleTimer = null;

  // On page init, initialize active timestamp and clear any stale expired markers
  try {
    localStorage.removeItem(TIMEOUT_CONFIG.storageKeyExpired);
    localStorage.setItem(TIMEOUT_CONFIG.storageKeyActive, pageLoadedAt.toString());
  } catch (e) {}

  // Format seconds to mm:ss
  function formatSeconds(sec) {
    const s = Math.max(0, Math.floor(sec));
    const mins = Math.floor(s / 60);
    const remainder = s % 60;
    return String(mins).padStart(2, '0') + ':' + String(remainder).padStart(2, '0');
  }

  // Silent server keepalive ping (prevents PHP session from expiring while user is actively working)
  async function pingServerKeepalive() {
    try {
      await fetch(TIMEOUT_CONFIG.keepAliveUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'keepalive' }),
        credentials: 'same-origin'
      });
    } catch (e) {}
  }

  // User activity registrar — throttled, strict expiration check, no silent auto-dismiss when modal is up
  function registerUserActivity(e) {
    if (isExpiredTriggered) return;

    const now = Date.now();

    // STRICT CHECK: If session has already expired based on elapsed time, expire immediately!
    if (lastActive && (now - lastActive) >= (TIMEOUT_CONFIG.timeoutSeconds * 1000)) {
      triggerSessionExpired();
      return;
    }

    // When the warning modal is active, DO NOT silently dismiss on mouse movements or keypresses
    // User must click "Stay Signed In" to confirm presence
    if (isModalOpen) {
      return;
    }

    lastActive = now;

    // Sync to localStorage every 3 seconds during active interaction
    if (!throttleTimer) {
      throttleTimer = setTimeout(function() {
        throttleTimer = null;
        try {
          localStorage.setItem(TIMEOUT_CONFIG.storageKeyActive, Date.now().toString());
        } catch (e) {}
      }, 3000);
    }

    // Auto-ping backend keepalive every 1 minute while user is continuously active
    if (now - lastServerPing > 60000) {
      lastServerPing = now;
      pingServerKeepalive();
    }
  }

  // Full-spectrum activity listeners using capture phase
  const activityEvents = [
    'mousedown', 'mouseup', 'click', 'dblclick',
    'keydown', 'keyup',
    'scroll', 'wheel',
    'touchstart', 'touchend',
    'input', 'change', 'submit'
  ];

  activityEvents.forEach(evt => {
    document.addEventListener(evt, registerUserActivity, { passive: true, capture: true });
    window.addEventListener(evt, registerUserActivity, { passive: true, capture: true });
  });

  // Cross-tab synchronization via localStorage events
  window.addEventListener('storage', function(e) {
    if (e.key === TIMEOUT_CONFIG.storageKeyActive && e.newValue) {
      const remoteActive = parseInt(e.newValue, 10);
      if (remoteActive > lastActive) {
        lastActive = remoteActive;
        if (isModalOpen) {
          hideWarningModal();
        }
      }
    } else if (e.key === TIMEOUT_CONFIG.storageKeyExpired) {
      triggerSessionExpired();
    }
  });

  // Immediate check when tab becomes visible or window gains focus (handles tab switching, laptop wake, unminimizing)
  document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
      try {
        const storedActive = parseInt(localStorage.getItem(TIMEOUT_CONFIG.storageKeyActive), 10);
        if (storedActive && storedActive > lastActive) {
          lastActive = storedActive;
        }
      } catch (e) {}
      monitorInactivity();
    }
  });

  window.addEventListener('pageshow', function(e) {
    monitorInactivity();
  });

  window.addEventListener('focus', function() {
    monitorInactivity();
  });

  function showWarningModal() {
    if (isModalOpen || isExpiredTriggered) return;
    isModalOpen = true;
    overlay.style.display = 'flex';
    overlay.setAttribute('aria-hidden', 'false');
    btnExtend?.focus();
  }

  function hideWarningModal() {
    if (!isModalOpen) return;
    isModalOpen = false;
    overlay.style.display = 'none';
    overlay.setAttribute('aria-hidden', 'true');
    clockDisplay?.classList.remove('urgent');
    progressBar?.classList.remove('urgent');
  }

  function showToast() {
    if (!toast) return;
    clearTimeout(toastTimer);
    toast.style.display = 'flex';
    toastTimer = setTimeout(() => {
      toast.style.display = 'none';
    }, 3500);
  }

  function triggerSessionExpired() {
    if (isExpiredTriggered) return;
    isExpiredTriggered = true;

    try {
      localStorage.setItem(TIMEOUT_CONFIG.storageKeyExpired, Date.now().toString());
      localStorage.removeItem(TIMEOUT_CONFIG.storageKeyActive);
    } catch (e) {}

    // Send beacon to invalidate PHP session synchronously
    try {
      if (navigator.sendBeacon) {
        const fd = new FormData();
        fd.append('action', 'logout');
        navigator.sendBeacon(TIMEOUT_CONFIG.keepAliveUrl, fd);
      } else {
        fetch(TIMEOUT_CONFIG.keepAliveUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ action: 'logout' }),
          credentials: 'same-origin',
          keepalive: true
        });
      }
    } catch (e) {}

    window.location.href = TIMEOUT_CONFIG.logoutUrl;
  }

  // Inactivity check tick (runs every second)
  function monitorInactivity() {
    if (isExpiredTriggered) return;

    // Cross-tab check from storage
    try {
      const storedExpired = localStorage.getItem(TIMEOUT_CONFIG.storageKeyExpired);
      if (storedExpired) {
        triggerSessionExpired();
        return;
      }
      const storedActive = parseInt(localStorage.getItem(TIMEOUT_CONFIG.storageKeyActive), 10);
      if (storedActive && storedActive > lastActive) {
        lastActive = storedActive;
      }
    } catch (e) {}

    const elapsedSec = Math.floor((Date.now() - lastActive) / 1000);
    const remainingSec = TIMEOUT_CONFIG.timeoutSeconds - elapsedSec;

    if (remainingSec <= 0) {
      // Expiration reached
      triggerSessionExpired();
      return;
    }

    if (remainingSec <= TIMEOUT_CONFIG.warningSeconds) {
      // Within warning window
      showWarningModal();

      if (clockDisplay) clockDisplay.textContent = formatSeconds(remainingSec);
      if (progressBar) {
        const pct = Math.max(0, Math.min(100, (remainingSec / TIMEOUT_CONFIG.warningSeconds) * 100));
        progressBar.style.width = pct + '%';

        if (remainingSec <= 30) {
          clockDisplay?.classList.add('urgent');
          progressBar?.classList.add('urgent');
        } else {
          clockDisplay?.classList.remove('urgent');
          progressBar?.classList.remove('urgent');
        }
      }
    } else {
      // Active and safe
      if (isModalOpen) {
        hideWarningModal();
      }
    }
  }

  setInterval(monitorInactivity, 1000);

  // Click on modal backdrop to dismiss & extend
  overlay?.addEventListener('click', function(e) {
    if (e.target === overlay) {
      btnExtend?.click();
    }
  });

  // Button: Stay Signed In (Extend Session)
  btnExtend?.addEventListener('click', async function() {
    const originalText = btnExtend.innerHTML;
    btnExtend.disabled = true;
    btnExtend.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Extending...</span>';

    try {
      const res = await fetch(TIMEOUT_CONFIG.keepAliveUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'keepalive' }),
        credentials: 'same-origin'
      });
      const data = await res.json();

      if (data.success) {
        const now = Date.now();
        lastActive = now;
        lastServerPing = now;
        try {
          localStorage.setItem(TIMEOUT_CONFIG.storageKeyActive, now.toString());
        } catch (e) {}

        hideWarningModal();
        showToast();
      } else {
        triggerSessionExpired();
      }
    } catch (err) {
      const now = Date.now();
      lastActive = now;
      lastServerPing = now;
      hideWarningModal();
    } finally {
      btnExtend.disabled = false;
      btnExtend.innerHTML = originalText;
    }
  });

  // Button: Sign Out Now
  btnSignOut?.addEventListener('click', function() {
    btnSignOut.disabled = true;
    btnSignOut.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Signing out...</span>';

    fetch(TIMEOUT_CONFIG.keepAliveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'logout' }),
      credentials: 'same-origin'
    }).finally(() => {
      triggerSessionExpired();
    });
  });

  // Intercept global fetch responses to catch HTTP 401 Session Timeouts
  const originalFetch = window.fetch;
  window.fetch = async function(...args) {
    const response = await originalFetch.apply(this, args);
    if (response.status === 401) {
      const clone = response.clone();
      try {
        const json = await clone.json();
        if (json && (json.session_timeout || (json.message && json.message.toLowerCase().includes('session')))) {
          triggerSessionExpired();
        }
      } catch (e) {}
    }
    return response;
  };

})();
</script>
