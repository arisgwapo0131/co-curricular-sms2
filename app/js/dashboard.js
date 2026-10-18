// ============================================================
//  DASHBOARD.JS
//  Interactive behaviour for all dashboard pages.
//
//  SECTIONS (use Ctrl+F to jump):
//    1. SIDEBAR TOGGLE & OUTSIDE-CLICK CLOSE
//    2. SIDEBAR DROPDOWNS
//    3. CHART DATA
//    4. MODAL HELPERS
//    5. TOAST NOTIFICATIONS
//    6. BELL / NOTIFICATION PANEL
// ============================================================


// ============================================================
//  1. SIDEBAR TOGGLE & OUTSIDE-CLICK CLOSE
//  Clicking the hamburger icon collapses/expands the sidebar.
//  On mobile (<= 900px), clicking outside the sidebar also closes it.
// ============================================================
const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebar        = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');

// On mobile (<= 900px) the sidebar is collapsed by default
if (window.innerWidth <= 900 && sidebar) {
    sidebar.classList.add('collapsed');
}

// Window resize handler to maintain clean responsive states
window.addEventListener('resize', () => {
    if (window.innerWidth <= 900) {
        if (sidebar && !sidebar.classList.contains('collapsed')) {
            sidebar.classList.add('collapsed');
            if (sidebarOverlay) sidebarOverlay.classList.remove('active');
        }
    } else {
        if (sidebarOverlay) sidebarOverlay.classList.remove('active');
    }
});

hamburgerBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    if (sidebar) {
        sidebar.classList.toggle('collapsed');
        if (sidebarOverlay) {
            sidebarOverlay.classList.toggle('active', !sidebar.classList.contains('collapsed'));
        }
    }
});

// Tap the overlay to close sidebar on mobile
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => {
        sidebar.classList.add('collapsed');
        sidebarOverlay.classList.remove('active');
    });
}

// Prevent sidebar clicks from bubbling up to document
if (sidebar) {
    sidebar.addEventListener('click', (e) => e.stopPropagation());
}

// Close sidebar when clicking anywhere in main content on mobile overlay mode
document.addEventListener('click', () => {
    if (window.innerWidth <= 900 && sidebar && !sidebar.classList.contains('collapsed')) {
        sidebar.classList.add('collapsed');
        if (sidebarOverlay) sidebarOverlay.classList.remove('active');
    }
});


// ============================================================
//  2. SIDEBAR DROPDOWNS
//  Each nav item expands a dropdown when clicked.
// ============================================================
document.querySelectorAll('.dropdown-trigger').forEach(button => {
    button.addEventListener('click', function () {
        const targetMenu = document.getElementById(this.dataset.target);
        const isOpen     = targetMenu.classList.contains('open');

        // Close all open dropdowns first
        document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.sidebar-item.open').forEach(b => b.classList.remove('open'));

        // Then open the clicked one (if it was closed)
        if (!isOpen) {
            targetMenu.classList.add('open');
            this.classList.add('open');
        }
    });
});




// ============================================================
//  4. CHART DATA
//  Edit the labels array and the data arrays to change the chart.
//  Each dataset is one coloured bar group.
// ============================================================

// Dashboard Chart (Co-Curricular Engagement Metrics)
const dashboardChart = document.getElementById('dashboardChart');

if (dashboardChart) {
    const engagementLabels = [
        'Club Participation', 'Event Attendance', 'Achievement Submissions',
        'Community Service', 'Leadership Roles', 'Award Recognitions'
    ];

    const engagementData = [
        {
            label: 'Current Year',
            data: [78, 85, 42, 65, 55, 38],
            backgroundColor: '#2563eb'
        },
        {
            label: 'Previous Year',
            data: [65, 72, 35, 58, 48, 30],
            backgroundColor: '#10b981'
        }
    ];

    new Chart(dashboardChart.getContext('2d'), {
        type: 'bar',
        data: { labels: engagementLabels, datasets: engagementData },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 }, padding: 12 }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: { font: { size: 10 } },
                    grid:  { color: '#f0f0f0' }
                },
                x: {
                    ticks: { font: { size: 10 } },
                    grid:  { display: false }
                }
            }
        }
    });
}








// ============================================================
//  6. MODAL HELPERS
//  openModal / closeModal show or hide a popup dialog.
//  Clicking outside the modal box or the × button also closes it.
// ============================================================

// Opens a modal by its HTML id
function openModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) {
        el.classList.add('active');
        el.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

// Closes a modal by its HTML id
function closeModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) {
        el.classList.remove('active');
        el.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Listen for any click on a close button or the overlay background
document.addEventListener('click', event => {
    // 1. Check data-close attribute
    const dataCloseBtn = event.target.closest('[data-close]');
    if (dataCloseBtn) {
        closeModal(dataCloseBtn.dataset.close);
        return;
    }

    // 2. Check any button/element with close classes
    const closeBtn = event.target.closest('.modal-close, .notif-close, .close-modal, .opm-close, .afm-close, .btn-modal-close, [data-dismiss="modal"], #closeQrModalBtn, #notifClose, #closeAchModal, #cancelAchBtn, #closeOverrideModal, #cancelOverride');
    if (closeBtn) {
        if (closeBtn.id === 'closeQrModalBtn' || closeBtn.matches('[data-close-qr]')) {
            if (typeof window.closeGlobalQrModal === 'function') {
                window.closeGlobalQrModal();
            }
        }
        if (closeBtn.id === 'afmClose' && typeof window.closeAppForm === 'function') {
            window.closeAppForm();
        }
        if (closeBtn.id === 'opmClose' && typeof window.closeOrgProfile === 'function') {
            window.closeOrgProfile();
        }
        const parentModal = closeBtn.closest('.modal-overlay, .qr-modal-overlay, .org-profile-overlay, .app-form-overlay, .notif-panel, .notif-overlay');
        if (parentModal) {
            parentModal.classList.remove('active', 'open');
            parentModal.style.display = 'none';
        }
        document.body.style.overflow = '';
        return;
    }

    // 3. Click directly on the dark overlay (not the modal box)
    if (event.target.classList.contains('modal-overlay') || 
        event.target.classList.contains('qr-modal-overlay') ||
        event.target.classList.contains('org-profile-overlay') ||
        event.target.classList.contains('app-form-overlay') ||
        event.target.classList.contains('modal-backdrop')) {
        event.target.classList.remove('active', 'open');
        event.target.style.display = 'none';
        document.body.style.overflow = '';
        if (event.target.id === 'qrModalOverlay' && typeof window.closeGlobalQrModal === 'function') {
            window.closeGlobalQrModal();
        }
        if (event.target.id === 'appFormOverlay' && typeof window.closeAppForm === 'function') {
            window.closeAppForm();
        }
        if (event.target.id === 'orgProfileOverlay' && typeof window.closeOrgProfile === 'function') {
            window.closeOrgProfile();
        }
    }
});

// ESC key to close all open modals/drawers
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active, .qr-modal-overlay.active, .org-profile-overlay.active, .app-form-overlay.active, .notif-panel.active, .notif-panel.open, .modal-overlay[style*="display: flex"], [id$="Modal"][style*="display: flex"]').forEach(m => {
            m.classList.remove('active', 'open');
            m.style.display = 'none';
        });
        document.body.style.overflow = '';
        if (typeof window.closeGlobalQrModal === 'function') {
            window.closeGlobalQrModal();
        }
        if (typeof window.closeAppForm === 'function') {
            window.closeAppForm();
        }
        if (typeof window.closeOrgProfile === 'function') {
            window.closeOrgProfile();
        }
    }
});


// ============================================================
//  7. TOAST NOTIFICATIONS
//  Shows a card-style popup in the top-right corner.
//
//  Types:
//    'success'  → green  "Submitted"
//    'updated'  → blue   "Updated"
//    'warning'  → yellow "Warning"
//    'error'    → red    "Error"
//
//  Usage: showToast('Your message here', 'success')
// ============================================================

// Maps each type to the bold label shown at the top of the card
const TOAST_LABELS = {
    success : 'Success',
    updated : 'Updated',
    warning : 'Warning',
    error   : 'Error'
};

function showToast(message, type = 'success') {
    // Create the toast element once and reuse it
    let toast = document.querySelector('.toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.className = 'toast';
        toast.innerHTML = `
            <div class="toast-label">
                <span class="toast-dot"></span>
                <span class="toast-title"></span>
            </div>
            <div class="toast-msg"></div>`;
        document.body.appendChild(toast);
    }

    // Set the label and message
    toast.querySelector('.toast-title').textContent = TOAST_LABELS[type] ?? (type.charAt(0).toUpperCase() + type.slice(1));
    toast.querySelector('.toast-msg').textContent   = message;

    // Swap the colour class
    toast.className = `toast ${type}`;

    // Trigger animation (force reflow so class change restarts transition)
    void toast.offsetWidth;
    toast.classList.add('show');

    // Auto-hide after 3.5 seconds
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => toast.classList.remove('show'), 3500);
}

// ── Save a toast to show AFTER a page reload ─────────────────
// Because location.reload() happens before the toast is visible,
// we store the message in sessionStorage and read it back on load.
function reloadWithToast(message, type) {
    sessionStorage.setItem('pending_toast', JSON.stringify({ message, type }));
    location.reload();
}

// On every page load: check if there's a pending toast and show it
window.addEventListener('DOMContentLoaded', () => {
    const pending = sessionStorage.getItem('pending_toast');
    if (pending) {
        sessionStorage.removeItem('pending_toast');
        const { message, type } = JSON.parse(pending);
        // Small delay so the DOM is fully painted before the toast appears
        setTimeout(() => showToast(message, type), 150);
    }
});

// Avatar initial is rendered server-side from the PHP session — no JS needed.


// ============================================================
//  BELL / NOTIFICATION PANEL
//  Sidebar bell icon toggles the notification panel.
//  Clicking an unread item or "Mark all as read" clears the badge.
// ============================================================
const bellBtn      = document.getElementById('bellBtn');
const bellBadge    = document.getElementById('bellBadge');
const notifPanel   = document.getElementById('notifPanel');
const notifOverlay = document.getElementById('notifOverlay');
const notifClose   = document.getElementById('notifClose');
const notifMarkAll = document.getElementById('notifMarkAll');

function openNotifPanel() {
    if (typeof window.openNotifPanel === 'function') {
        window.openNotifPanel();
        return;
    }
    if (notifPanel) {
        notifPanel.style.display = '';
        notifPanel.classList.add('active', 'open');
    }
    if (notifOverlay) {
        notifOverlay.style.display = '';
        notifOverlay.classList.add('active', 'open');
    }
}

function closeNotifPanel() {
    if (typeof window.closeNotifPanel === 'function') {
        window.closeNotifPanel();
        return;
    }
    if (notifPanel) {
        notifPanel.classList.remove('active', 'open');
    }
    if (notifOverlay) {
        notifOverlay.classList.remove('active', 'open');
    }
}

function updateBellBadge() {
    const hasUnread = document.querySelector('#notifList .notif-item.unread');
    bellBadge?.classList.toggle('has-notif', !!hasUnread);
}

// Set initial badge state on page load
updateBellBadge();

// If window.openNotifPanel is not already attached to bellBtn by sidebar.php
if (!window.openNotifPanel) {
    bellBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = notifPanel?.classList.contains('active') || notifPanel?.classList.contains('open');
        isOpen ? closeNotifPanel() : openNotifPanel();
    });
    notifClose?.addEventListener('click', closeNotifPanel);
    notifOverlay?.addEventListener('click', closeNotifPanel);
}

// Mark individual item as read on click
document.getElementById('notifList')?.addEventListener('click', (e) => {
    const item = e.target.closest('.notif-item');
    if (item) {
        item.classList.remove('unread');
        updateBellBadge();
    }
});

// Mark all as read
notifMarkAll?.addEventListener('click', () => {
    document.querySelectorAll('#notifList .notif-item.unread')
        .forEach(el => el.classList.remove('unread'));
    updateBellBadge();
});

// ============================================================
//  7. GLOBAL SPOTLIGHT SEARCH AUTO-LOADER
// ============================================================
if (!window.openGlobalSpotlight) {
    const searchScript = document.createElement('script');
    searchScript.src = '../js/global-search.js';
    document.head.appendChild(searchScript);
}

// ============================================================
//  8. RESPONSIVE TABLES AUTO-LABELER
//  Automatically binds column headers to data-label attributes
//  allowing mobile tables to render as stacked cards with no 
//  horizontal/landscape scrollbar and without cut off text.
// ============================================================
function initResponsiveTables() {
    const tableSelectors = [
        '.table-wrap table',
        '.table-responsive table',
        '.resp-table-wrap table',
        '.ledger-table-wrap table',
        'table.table-wide',
        'table.responsive-table',
        'table.mobile-card-table',
        'table.data-table',
        'table.catalog-table',
        'table.custom-table'
    ];
    
    document.querySelectorAll(tableSelectors.join(', ')).forEach(table => {
        // Skip tables explicitly opted out (like print layouts or matrix grids)
        if (table.classList.contains('no-card-resp') || table.classList.contains('matrix-table') || table.classList.contains('meta-table') || table.classList.contains('export-table')) {
            return;
        }

        let thElements = Array.from(table.querySelectorAll('thead th'));
        if (thElements.length === 0) {
            thElements = Array.from(table.querySelectorAll('tr:first-child th'));
        }
        if (thElements.length === 0) return;

        const headers = thElements.map((th, colIdx) => {
            let txt = th.textContent.replace(/\s+/g, ' ').trim();
            // If header is empty or generic, detect if it's the action column
            if (!txt) {
                if (colIdx === thElements.length - 1 || th.classList.contains('actions-col') || th.querySelector('i, svg')) {
                    txt = 'Action';
                }
            }
            return txt;
        });

        table.classList.add('mobile-card-table');
        const wrap = table.closest('.table-wrap, .table-responsive, .resp-table-wrap, .ledger-table-wrap');
        if (wrap) {
            wrap.classList.add('mobile-cards-wrap');
        }

        table.querySelectorAll('tbody tr').forEach(tr => {
            // Skip header tr if accidentally inside tbody
            if (tr.querySelector('th')) return;

            const cells = tr.querySelectorAll('td');
            if (cells.length === 1 && (cells[0].hasAttribute('colspan') || cells[0].classList.contains('empty-state-cell') || cells[0].classList.contains('table-empty-cell'))) {
                cells[0].classList.add('table-empty-cell');
                tr.classList.add('empty-card-row');
                return;
            }

            cells.forEach((td, idx) => {
                const headerText = headers[idx] || (idx === cells.length - 1 ? 'Action' : '');
                if (headerText && !td.getAttribute('data-label')) {
                    td.setAttribute('data-label', headerText);
                }

                // Detect action cells
                const isActionCol = (headerText.toLowerCase().includes('action') || idx === cells.length - 1);
                const hasActionBtn = td.querySelector('button, .card-btn, .act-btn, a.btn, .actions-group, .action-btn-group, .act-btns, .btn-group');
                if (isActionCol && hasActionBtn) {
                    td.classList.add('actions-cell');
                }
            });
        });
    });
}

window.initResponsiveTables = initResponsiveTables;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initResponsiveTables);
} else {
    initResponsiveTables();
}

// Auto-reapply when DOM mutations add new rows or switch views
if (typeof MutationObserver !== 'undefined' && document.body) {
    const tableObserver = new MutationObserver((mutations) => {
        let shouldRefresh = false;
        for (const m of mutations) {
            if (m.addedNodes.length > 0) {
                for (let i = 0; i < m.addedNodes.length; i++) {
                    const node = m.addedNodes[i];
                    if (node.nodeType === 1 && (node.tagName === 'TR' || node.querySelector?.('tr, table'))) {
                        shouldRefresh = true;
                        break;
                    }
                }
            }
            if (shouldRefresh) break;
        }
        if (shouldRefresh) {
            initResponsiveTables();
        }
    });
    tableObserver.observe(document.body, { childList: true, subtree: true });
}

// ============================================================
//  8. MODERN CENTERED SYSTEM NOTIFICATION & DECISION MODAL
//  Replaces primitive browser alerts with elegant, centered,
//  accessible system dialogs for all notices and decision making.
// ============================================================
(function () {
    let _activeModalResolve = null;

    function ensureSysNotificationModal() {
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
            document.body.appendChild(overlay);

            // Backdrop click closes
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    window.closeSysNotification(false);
                }
            });
        }
        return overlay;
    }

    /**
     * Show centered modern system notification or decision dialog
     */
    window.showSystemModal = function (options = {}) {
        return new Promise((resolve) => {
            _activeModalResolve = resolve;

            const overlay = ensureSysNotificationModal();
            const iconWrap = document.getElementById('sysNotifIconWrap');
            const icon = document.getElementById('sysNotifIcon');
            const titleEl = document.getElementById('sysNotifTitle');
            const msgEl = document.getElementById('sysNotifMessage');
            const inputWrap = document.getElementById('sysNotifInputWrap');
            const inputEl = document.getElementById('sysNotifInput');
            const cancelBtn = document.getElementById('sysNotifCancelBtn');
            const confirmBtn = document.getElementById('sysNotifConfirmBtn');

            // Format message & clean crude symbols
            let cleanMsg = String(options.message ?? '');
            if (cleanMsg.startsWith('✓ ') || cleanMsg.startsWith('✗ ')) {
                cleanMsg = cleanMsg.slice(2);
            }

            // Determine type
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

            // Set Title
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

            // Set Icon
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

            // Buttons & Inputs
            const isDecision = Boolean(options.cancelText || options.isDecision || options.input);
            cancelBtn.style.display = isDecision ? 'inline-flex' : 'none';
            cancelBtn.textContent = options.cancelText || 'Cancel';

            confirmBtn.textContent = options.confirmText || (isDecision ? 'Confirm' : 'Understood');

            // Button variant
            confirmBtn.className = 'btn-sys-notif ' + (options.danger ? 'btn-sys-danger' : (options.warning ? 'btn-sys-warning' : 'btn-sys-primary'));

            // Optional Text Input
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

            // Show Modal Centered
            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            void overlay.offsetWidth;
            overlay.classList.add('active');

            // Auto-focus
            setTimeout(() => {
                if (options.input) {
                    inputEl.focus();
                } else {
                    confirmBtn.focus();
                }
            }, 50);
        });
    };

    /**
     * Confirm handler supporting input validation
     */
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

    /**
     * Close centered system notification and resolve active promise
     */
    window.closeSysNotification = function (result) {
        const overlay = document.getElementById('sysNotificationModal');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(() => {
                overlay.style.display = 'none';
                document.body.style.overflow = '';
            }, 200);
        }
        if (typeof _activeModalResolve === 'function') {
            const cb = _activeModalResolve;
            _activeModalResolve = null;
            cb(result);
        }
    };

    // Keyboard handling for centered system notifications
    document.addEventListener('keydown', (e) => {
        const overlay = document.getElementById('sysNotificationModal');
        if (overlay && overlay.classList.contains('active')) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                window.closeSysNotification(false);
            } else if (e.key === 'Enter' && !e.shiftKey) {
                const inputEl = document.getElementById('sysNotifInput');
                // Allow enter key to submit if active button or in single-line
                if (document.activeElement !== inputEl) {
                    e.preventDefault();
                    window.handleSysNotificationConfirm();
                }
            }
        }
    });

    /**
     * MODERN CENTERED DECISION CONFIRM MODAL
     * Returns Promise<boolean>
     */
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

    /**
     * MODERN CENTERED DECISION INPUT MODAL
     * Returns Promise<string|false>
     */
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

    // ── Universal Replacement for Native Browser Alert ────────────
    // Directly intercepts alert(...) calls across all application scripts
    // and seamlessly renders the centered modern system dialog.
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

})();








