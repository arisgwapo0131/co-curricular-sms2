/**
 * ============================================================
 * SYSTEM-NOTIFICATIONS.JS
 * Universal Modern Centered Notification & Decision Engine
 * Replaces crude browser "localhost says" alerts with premium,
 * screen-centered modern decision modals throughout the system.
 * ============================================================
 */
(function () {
    'use strict';

    // Inject styles immediately if not already present
    if (!document.getElementById('sysNotificationStyles')) {
        const style = document.createElement('style');
        style.id = 'sysNotificationStyles';
        style.textContent = `
            .sys-notification-overlay {
                position: fixed !important;
                inset: 0 !important;
                width: 100vw !important;
                height: 100vh !important;
                background: rgba(15, 23, 42, 0.72) !important;
                backdrop-filter: blur(8px) !important;
                -webkit-backdrop-filter: blur(8px) !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                z-index: 99999999 !important;
                padding: 16px !important;
                box-sizing: border-box !important;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.22s ease, visibility 0.22s ease !important;
            }
            .sys-notification-overlay.active {
                opacity: 1 !important;
                visibility: visible !important;
            }
            .sys-notification-card {
                position: relative !important;
                background: #ffffff !important;
                width: min(92vw, 440px) !important;
                max-width: 440px !important;
                border-radius: 20px !important;
                box-shadow: 0 25px 60px -12px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(226, 232, 240, 0.95) !important;
                padding: 30px 24px 24px !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
                box-sizing: border-box !important;
                transform: scale(0.92) translateY(12px) !important;
                transition: transform 0.24s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.2s ease !important;
                font-family: inherit !important;
            }
            .sys-notification-overlay.active .sys-notification-card {
                transform: scale(1) translateY(0) !important;
            }
            .sys-notification-close {
                position: absolute !important;
                top: 14px !important;
                right: 14px !important;
                width: 32px !important;
                height: 32px !important;
                border-radius: 50% !important;
                border: none !important;
                background: #f1f5f9 !important;
                color: #64748b !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                cursor: pointer !important;
                font-size: 0.9rem !important;
                transition: all 0.15s ease !important;
            }
            .sys-notification-close:hover {
                background: #e2e8f0 !important;
                color: #0f172a !important;
            }
            .sys-notification-icon-wrap {
                width: 64px !important;
                height: 64px !important;
                border-radius: 50% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 1.8rem !important;
                margin-bottom: 18px !important;
                flex-shrink: 0 !important;
                transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
            }
            .sys-notification-overlay.active .sys-notification-icon-wrap {
                transform: scale(1.05) !important;
            }
            .sys-notification-icon-wrap.type-warning {
                background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%) !important;
                color: #d97706 !important;
                box-shadow: 0 10px 22px -6px rgba(217, 119, 6, 0.35) !important;
            }
            .sys-notification-icon-wrap.type-error {
                background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%) !important;
                color: #dc2626 !important;
                box-shadow: 0 10px 22px -6px rgba(220, 38, 38, 0.35) !important;
            }
            .sys-notification-icon-wrap.type-success {
                background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%) !important;
                color: #16a34a !important;
                box-shadow: 0 10px 22px -6px rgba(22, 163, 74, 0.35) !important;
            }
            .sys-notification-icon-wrap.type-info {
                background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%) !important;
                color: #2563eb !important;
                box-shadow: 0 10px 22px -6px rgba(37, 99, 235, 0.35) !important;
            }
            .sys-notification-icon-wrap.type-decision {
                background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%) !important;
                color: #7c3aed !important;
                box-shadow: 0 10px 22px -6px rgba(124, 58, 237, 0.35) !important;
            }
            .sys-notification-content {
                width: 100% !important;
            }
            .sys-notification-title {
                font-size: 1.2rem !important;
                font-weight: 800 !important;
                color: #0f172a !important;
                margin: 0 0 8px 0 !important;
                letter-spacing: -0.015em !important;
            }
            .sys-notification-message {
                font-size: 0.92rem !important;
                color: #475569 !important;
                line-height: 1.55 !important;
                margin: 0 0 18px 0 !important;
                word-break: break-word !important;
            }
            .sys-notif-input-wrap {
                margin: 0 0 18px 0 !important;
                width: 100% !important;
                text-align: left !important;
            }
            .sys-notif-textarea {
                width: 100% !important;
                min-height: 84px !important;
                padding: 10px 12px !important;
                border: 1.5px solid #cbd5e1 !important;
                border-radius: 10px !important;
                font-size: 0.86rem !important;
                color: #0f172a !important;
                box-sizing: border-box !important;
                font-family: inherit !important;
                outline: none !important;
                resize: vertical !important;
                transition: border-color 0.15s ease !important;
            }
            .sys-notif-textarea:focus {
                border-color: #2563eb !important;
                box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12) !important;
            }
            .sys-notification-actions {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 10px !important;
                width: 100% !important;
            }
            .btn-sys-notif {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 6px !important;
                height: 42px !important;
                padding: 0 20px !important;
                font-size: 0.88rem !important;
                font-weight: 700 !important;
                border-radius: 10px !important;
                border: 1px solid transparent !important;
                cursor: pointer !important;
                transition: all 0.15s ease !important;
                text-decoration: none !important;
                white-space: nowrap !important;
            }
            .btn-sys-primary {
                background: #1e3a8a !important;
                color: #ffffff !important;
                box-shadow: 0 4px 12px rgba(30, 58, 138, 0.28) !important;
            }
            .btn-sys-primary:hover {
                background: #172554 !important;
            }
            .btn-sys-secondary {
                background: #f1f5f9 !important;
                color: #475569 !important;
                border-color: #cbd5e1 !important;
            }
            .btn-sys-secondary:hover {
                background: #e2e8f0 !important;
                color: #0f172a !important;
            }
            .btn-sys-danger {
                background: #dc2626 !important;
                color: #ffffff !important;
                box-shadow: 0 4px 12px rgba(220, 38, 38, 0.28) !important;
            }
            .btn-sys-danger:hover {
                background: #b91c1c !important;
            }
            .btn-sys-warning {
                background: #d97706 !important;
                color: #ffffff !important;
                box-shadow: 0 4px 12px rgba(217, 119, 6, 0.28) !important;
            }
            .btn-sys-warning:hover {
                background: #b45309 !important;
            }
        `;
        document.head.appendChild(style);
    }

    let _activeModalResolve = null;

    function ensureModalElement() {
        let overlay = document.getElementById('sysNotificationModal');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'sysNotificationModal';
            overlay.className = 'sys-notification-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.innerHTML = `
                <div class="sys-notification-card" id="sysNotifCard">
                    <button type="button" class="sys-notification-close" id="sysNotifCloseBtn" aria-label="Close" onclick="window.closeSysNotification(false)">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                    <div class="sys-notification-icon-wrap type-warning" id="sysNotifIconWrap">
                        <i id="sysNotifIcon" class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div class="sys-notification-content">
                        <h3 class="sys-notification-title" id="sysNotifTitle">Action Required</h3>
                        <p class="sys-notification-message" id="sysNotifMessage"></p>
                        <div id="sysNotifInputWrap" class="sys-notif-input-wrap" style="display:none;">
                            <textarea id="sysNotifInput" class="sys-notif-textarea" rows="3" placeholder="Enter remarks or justification..."></textarea>
                        </div>
                    </div>
                    <div class="sys-notification-actions" id="sysNotifActions">
                        <button type="button" class="btn-sys-notif btn-sys-secondary" id="sysNotifCancelBtn" style="display:none;" onclick="window.closeSysNotification(false)">Cancel</button>
                        <button type="button" class="btn-sys-notif btn-sys-primary" id="sysNotifConfirmBtn" onclick="window.handleSysNotificationConfirm()">Understood</button>
                    </div>
                </div>
            `;
            if (document.body) {
                document.body.appendChild(overlay);
            } else {
                document.addEventListener('DOMContentLoaded', () => document.body.appendChild(overlay));
            }

            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    window.closeSysNotification(false);
                }
            });
        }
        return overlay;
    }

    window.closeSysNotification = function (result) {
        const overlay = document.getElementById('sysNotificationModal');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(() => {
                overlay.style.display = 'none';
                if (document.body) document.body.style.overflow = '';
            }, 200);
        }
        if (typeof _activeModalResolve === 'function') {
            const cb = _activeModalResolve;
            _activeModalResolve = null;
            cb(result);
        }
    };

    window.handleSysNotificationConfirm = function () {
        const inputWrap = document.getElementById('sysNotifInputWrap');
        const inputEl = document.getElementById('sysNotifInput');
        if (inputWrap && inputWrap.style.display !== 'none') {
            const val = inputEl.value.trim();
            if (inputEl.dataset.required === 'true' && !val) {
                inputEl.style.borderColor = '#dc2626';
                inputEl.focus();
                return;
            }
            window.closeSysNotification(val || true);
            return;
        }
        window.closeSysNotification(true);
    };

    window.showSystemModal = function (options = {}) {
        return new Promise((resolve) => {
            _activeModalResolve = resolve;

            const overlay = ensureModalElement();
            const iconWrap = document.getElementById('sysNotifIconWrap');
            const icon = document.getElementById('sysNotifIcon');
            const titleEl = document.getElementById('sysNotifTitle');
            const msgEl = document.getElementById('sysNotifMessage');
            const inputWrap = document.getElementById('sysNotifInputWrap');
            const inputEl = document.getElementById('sysNotifInput');
            const cancelBtn = document.getElementById('sysNotifCancelBtn');
            const confirmBtn = document.getElementById('sysNotifConfirmBtn');

            let cleanMsg = String(options.message ?? '');
            if (cleanMsg.startsWith('✓ ') || cleanMsg.startsWith('✗ ')) {
                cleanMsg = cleanMsg.slice(2);
            }

            let type = (options.type || 'info').toLowerCase();
            const lower = cleanMsg.toLowerCase();
            if (!options.type) {
                if (lower.includes('please') || lower.includes('required') || lower.includes('must') || lower.includes('enter') || lower.includes('select') || lower.includes('remarks') || lower.includes('instruction')) {
                    type = 'warning';
                } else if (lower.includes('error') || lower.includes('failed') || lower.includes('invalid') || lower.includes('denied') || lower.includes('cannot') || lower.includes('reject')) {
                    type = 'error';
                } else if (lower.includes('success') || lower.includes('approved') || lower.includes('endorsed') || lower.includes('saved') || lower.includes('completed')) {
                    type = 'success';
                }
            }

            let title = options.title;
            if (!title) {
                if (type === 'warning') title = 'Action Required';
                else if (type === 'error') title = 'Attention Required';
                else if (type === 'success') title = 'Operation Successful';
                else if (type === 'decision') title = 'Please Confirm Decision';
                else title = 'System Notification';
            }
            titleEl.textContent = title;
            msgEl.textContent = cleanMsg;

            iconWrap.className = 'sys-notification-icon-wrap type-' + type;
            if (type === 'warning') {
                icon.className = 'fa-solid fa-triangle-exclamation';
            } else if (type === 'error') {
                icon.className = 'fa-solid fa-circle-xmark';
            } else if (type === 'success') {
                icon.className = 'fa-solid fa-circle-check';
            } else if (type === 'decision') {
                icon.className = 'fa-solid fa-shield-halved';
            } else {
                icon.className = 'fa-solid fa-circle-info';
            }

            const isDecision = Boolean(options.cancelText || options.isDecision || options.input);
            cancelBtn.style.display = isDecision ? 'inline-flex' : 'none';
            cancelBtn.textContent = options.cancelText || 'Cancel';

            confirmBtn.textContent = options.confirmText || (isDecision ? 'Confirm' : 'Understood');
            confirmBtn.className = 'btn-sys-notif ' + (options.danger ? 'btn-sys-danger' : (options.warning ? 'btn-sys-warning' : 'btn-sys-primary'));

            if (options.input) {
                inputWrap.style.display = 'block';
                inputEl.value = options.defaultValue || '';
                inputEl.placeholder = options.placeholder || 'Enter instructions or reason...';
                inputEl.dataset.required = options.requireInput ? 'true' : 'false';
            } else {
                inputWrap.style.display = 'none';
                inputEl.value = '';
                delete inputEl.dataset.required;
            }

            overlay.style.display = 'flex';
            if (document.body) document.body.style.overflow = 'hidden';
            void overlay.offsetWidth;
            overlay.classList.add('active');

            setTimeout(() => {
                if (options.input) {
                    inputEl.focus();
                } else {
                    confirmBtn.focus();
                }
            }, 60);
        });
    };

    window.showConfirmModal = function (title, message, options = {}) {
        return window.showSystemModal({
            title: title || 'Please Confirm Decision',
            message: message,
            type: options.type || (options.danger ? 'error' : (options.warning ? 'warning' : 'decision')),
            confirmText: options.confirmText || 'Confirm & Proceed',
            cancelText: options.cancelText || 'Cancel',
            danger: options.danger || false,
            warning: options.warning || false,
            isDecision: true
        });
    };

    window.showDecisionModal = function (title, message, options = {}) {
        return window.showSystemModal({
            title: title || 'Decision & Instructions',
            message: message,
            type: options.type || 'decision',
            confirmText: options.confirmText || 'Submit Decision',
            cancelText: options.cancelText || 'Cancel',
            input: true,
            placeholder: options.placeholder || 'Enter remarks or instructions...',
            defaultValue: options.defaultValue || '',
            requireInput: options.requireInput !== false,
            danger: options.danger || false,
            warning: options.warning || false,
            isDecision: true
        });
    };

    // Global interception of native browser alert
    window.alert = function (message, titleOrType) {
        return window.showSystemModal({
            message: message,
            type: typeof titleOrType === 'string' && ['warning', 'error', 'success', 'info', 'decision'].includes(titleOrType.toLowerCase())
                ? titleOrType.toLowerCase()
                : null,
            title: typeof titleOrType === 'string' && !['warning', 'error', 'success', 'info', 'decision'].includes(titleOrType.toLowerCase())
                ? titleOrType
                : null,
            confirmText: 'Understood'
        });
    };

    document.addEventListener('keydown', (e) => {
        const overlay = document.getElementById('sysNotificationModal');
        if (overlay && overlay.classList.contains('active')) {
            if (e.key === 'Escape') {
                e.preventDefault();
                window.closeSysNotification(false);
            } else if (e.key === 'Enter' && !e.shiftKey) {
                const inputEl = document.getElementById('sysNotifInput');
                if (document.activeElement !== inputEl) {
                    e.preventDefault();
                    window.handleSysNotificationConfirm();
                }
            }
        }
    });

})();
