<?php
// ============================================================
//  CHAT_MODAL.PHP (app/shared/)
//  Modern Messenger-style Dual-Pane Inter-Club Communication Modal
//  Embedded in sidebar.php for universal availability
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$cm_user_id   = (int)($_SESSION['user_id'] ?? 0);
$cm_role      = $_SESSION['role'] ?? 'student';
$cm_first     = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$cm_last      = htmlspecialchars($_SESSION['last_name']  ?? '');
$cm_full_name = trim($cm_first . ' ' . $cm_last);
$cm_initial   = strtoupper(substr($cm_first ?: 'U', 0, 1));
$cm_pic       = $_SESSION['profile_pic'] ?? '';
$cm_app_root  = $APP_ROOT ?? '../';
?>

<!-- ── INTER-CLUB MESSENGER MODAL OVERLAY ── -->
<div class="chat-modal-overlay" id="chatModalOverlay" role="dialog" aria-modal="true" aria-labelledby="chatModalHeading" style="display:none;">
  <div class="chat-modal-card" id="chatModalCard">
    
    <!-- Universal Top-Right Exit Button -->
    <button type="button" class="chat-modal-global-exit-btn" onclick="closeChatModal()" title="Exit Inter-Club Chat" aria-label="Exit Inter-Club Chat">
      <i class="fa-solid fa-xmark"></i>
    </button>

    <!-- DUAL-PANE MESSENGER LAYOUT -->
    <div class="chat-messenger-layout">
      
      <!-- ── LEFT PANE: CONVERSATIONS & DIRECTORY ── -->
      <aside class="chat-sidebar-pane" id="chatSidebarPane">
        
        <!-- Sidebar Header -->
        <div class="chat-sidebar-header">
          <div class="chat-brand-title" id="chatModalHeading">
            <div class="chat-brand-icon">
              <i class="fa-solid fa-comments"></i>
            </div>
            <div>
              <h3>Inter-Club Chat</h3>
              <p>Multi-Tier Campus Communication</p>
            </div>
          </div>
          <div class="chat-header-actions">
            <button type="button" class="chat-icon-btn" id="btnNewChatCompose" onclick="composeNewChat()" title="New Conversation">
              <i class="fa-solid fa-pen-to-square"></i>
            </button>
            <button type="button" class="chat-icon-btn chat-sidebar-exit-btn" onclick="closeChatModal()" title="Exit Inter-Club Chat" aria-label="Exit Inter-Club Chat">
              <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </button>
          </div>
        </div>

        <!-- Search Input -->
        <div class="chat-search-wrap">
          <i class="fa-solid fa-magnifying-glass chat-search-icon"></i>
          <input type="text" id="chatSearchInput" class="chat-search-input" placeholder="Search groups, officers, council..." oninput="filterChatList()" autocomplete="off" />
          <button type="button" class="chat-search-clear" id="chatSearchClearBtn" onclick="clearChatSearch()" style="display:none;">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>

        <!-- Segmented Tab Switcher: Groups vs Direct vs Directory -->
        <div class="chat-nav-tabs">
          <button type="button" class="chat-tab-btn active" id="tabGroupsBtn" onclick="switchChatTab('groups')">
            <i class="fa-solid fa-users"></i> Groups
            <span class="chat-tab-badge" id="chatTabGroupsUnreadCount" style="display:none;"></span>
          </button>
          <button type="button" class="chat-tab-btn" id="tabChatsBtn" onclick="switchChatTab('chats')">
            <i class="fa-solid fa-comment-dots"></i> Direct
            <span class="chat-tab-badge" id="chatTabUnreadCount" style="display:none;"></span>
          </button>
          <button type="button" class="chat-tab-btn" id="tabContactsBtn" onclick="switchChatTab('contacts')">
            <i class="fa-solid fa-address-book"></i> Directory
          </button>
        </div>

        <!-- 1. Groups Scroll List (Default View) -->
        <div class="chat-list-container active" id="chatGroupsContainer">
          <div class="chat-list-loading" id="chatGroupsLoading">
            <i class="fa-solid fa-circle-notch fa-spin"></i> Loading group channels...
          </div>
          <div class="chat-list-empty" id="chatGroupsEmpty" style="display:none;">
            <div class="empty-icon-wrap"><i class="fa-solid fa-users-slash"></i></div>
            <h4>No group chats found</h4>
            <p>You will be added automatically upon club membership or adviser assignment.</p>
          </div>
          <ul class="chat-item-list" id="chatGroupsList"></ul>
        </div>

        <!-- 2. Direct Conversations Scroll List -->
        <div class="chat-list-container" id="chatConversationsContainer" style="display:none;">
          <div class="chat-list-loading" id="chatConvLoading">
            <i class="fa-solid fa-circle-notch fa-spin"></i> Loading conversations...
          </div>
          <div class="chat-list-empty" id="chatConvEmpty" style="display:none;">
            <div class="empty-icon-wrap"><i class="fa-regular fa-comment-dots"></i></div>
            <h4>No direct conversations yet</h4>
            <p>Start a 1-on-1 inter-club message with organization officers or advisers.</p>
            <button type="button" class="btn-chat-primary-sm" onclick="switchChatTab('contacts')">
              <i class="fa-solid fa-user-plus"></i> Browse Directory
            </button>
          </div>
          <ul class="chat-item-list" id="chatConversationsList"></ul>
        </div>

        <!-- 3. Contacts Directory Scroll List -->
        <div class="chat-list-container" id="chatContactsContainer" style="display:none;">
          <div class="chat-list-loading" id="chatContactsLoading">
            <i class="fa-solid fa-circle-notch fa-spin"></i> Loading directory...
          </div>
          <div class="chat-list-empty" id="chatContactsEmpty" style="display:none;">
            <div class="empty-icon-wrap"><i class="fa-solid fa-users-slash"></i></div>
            <h4>No contacts found</h4>
            <p>No eligible recipients match your search query.</p>
          </div>
          <div class="chat-contacts-grouped" id="chatContactsList"></div>
        </div>

      </aside>

      <!-- ── RIGHT PANE: ACTIVE CHAT VIEW ── -->
      <main class="chat-main-pane" id="chatMainPane">
        
        <!-- Empty State (No Active Conversation Selected) -->
        <div class="chat-blank-state" id="chatBlankState">
          <div class="chat-blank-card">
            <div class="chat-blank-illustration">
              <i class="fa-solid fa-comments"></i>
            </div>
            <h3>Inter-Club Communication Portal</h3>
            <p>Connect and coordinate directly with student club officers, faculty advisers, council leaders, and administration.</p>
            <div class="chat-blank-features">
              <div class="cbf-item">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Role-Governed Inter-Club Messaging</span>
              </div>
              <div class="cbf-item">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Real-Time Audit &amp; Direct Delivery</span>
              </div>
              <div class="cbf-item">
                <i class="fa-solid fa-building-columns"></i>
                <span>Campus Coordination Across All Clubs</span>
              </div>
            </div>
            <div class="chat-blank-actions">
              <button type="button" class="btn-start-chat" onclick="switchChatTab('contacts')">
                <i class="fa-solid fa-paper-plane"></i> Start a Conversation
              </button>
              <button type="button" class="btn-exit-chat-secondary" onclick="closeChatModal()">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Exit Chat
              </button>
            </div>
          </div>
        </div>

        <!-- Active Chat Window -->
        <div class="chat-active-window" id="chatActiveWindow" style="display:none;">
          
          <!-- Top Chat Header -->
          <div class="chat-active-header">
            <div class="chat-header-user-info">
              <button type="button" class="chat-back-mobile-btn" onclick="returnToSidebarMobile()" title="Back to list">
                <i class="fa-solid fa-arrow-left"></i>
              </button>
              <div class="chat-avatar-wrap">
                <div class="chat-avatar-circle" id="chatActiveAvatar">U</div>
                <span class="chat-online-dot" title="Active channel"></span>
              </div>
              <div class="chat-user-meta">
                <div class="chat-user-name" id="chatActivePartnerName">Select Channel</div>
                <div class="chat-user-sub">
                  <span class="chat-role-chip" id="chatActivePartnerRole">Channel</span>
                  <span class="chat-status-text" id="chatActivePartnerUsername">@channel</span>
                </div>
              </div>
            </div>

          </div>

          <!-- Messages Scrollable Body -->
          <div class="chat-messages-body" id="chatMessagesBody">
            <div class="chat-loading-history" id="chatHistoryLoading">
              <i class="fa-solid fa-circle-notch fa-spin"></i> Loading messages...
            </div>
            <div class="chat-messages-flow" id="chatMessagesFlow"></div>
          </div>

          <!-- Message Composer Area -->
          <div class="chat-composer-wrap">
            <form id="chatMessageForm" onsubmit="submitChatMessage(event)">
              <div class="chat-composer-inner">
                <textarea id="chatInputMessage" 
                          class="chat-textarea" 
                          placeholder="Type an inter-club message... (Press Enter to send)" 
                          rows="1" 
                          onkeydown="onChatInputKeyDown(event)"
                          oninput="autoResizeChatInput(this)"></textarea>
                <button type="submit" class="chat-send-btn" id="chatSendBtn" title="Send Message">
                  <i class="fa-solid fa-paper-plane"></i>
                </button>
              </div>
              <div class="chat-composer-tip">
                <span><i class="fa-solid fa-keyboard"></i> Press <strong>Enter</strong> to send, <strong>Shift + Enter</strong> for a new line</span>
              </div>
            </form>
          </div>

        </div>

      </main>

    </div>


  </div>
</div>

<!-- ── STYLES FOR MULTI-TIER GROUP CHATS & ENHANCEMENTS ── -->
<style>
/* Group Type Badges */
.gc-type-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.66rem;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 9999px;
  line-height: 1.2;
}
.gc-type-general {
  background: #ecfdf5 !important;
  color: #047857 !important;
  border: 1px solid #a7f3d0 !important;
}
.gc-type-exec {
  background: #f5f3ff !important;
  color: #6d28d9 !important;
  border: 1px solid #ddd6fe !important;
}
.gc-type-ssc {
  background: #fffbeb !important;
  color: #b45309 !important;
  border: 1px solid #fde68a !important;
}
.gc-type-default {
  background: #f1f5f9 !important;
  color: #475569 !important;
  border: 1px solid #cbd5e1 !important;
}

/* Group Card in Sidebar */
.chat-group-item {
  display: flex !important;
  align-items: center !important;
  gap: 12px !important;
  padding: 11px 16px !important;
  cursor: pointer !important;
  transition: all 0.15s ease !important;
  border-left: 3px solid transparent !important;
}
.chat-group-item:hover {
  background: #f1f5f9 !important;
}
.chat-group-item.active {
  background: #eff6ff !important;
  border-left-color: #2563eb !important;
}

.chat-group-avatar-icon {
  width: 42px !important;
  height: 42px !important;
  border-radius: 12px !important;
  background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
  color: #ffffff !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  font-size: 1.1rem !important;
  box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25) !important;
  flex-shrink: 0 !important;
}
.chat-group-avatar-exec {
  background: linear-gradient(135deg, #4c1d95, #7c3aed) !important;
  box-shadow: 0 2px 6px rgba(124, 58, 237, 0.25) !important;
}
.chat-group-avatar-ssc {
  background: linear-gradient(135deg, #b45309, #f59e0b) !important;
  box-shadow: 0 2px 6px rgba(245, 158, 11, 0.25) !important;
}

/* Sender metadata for incoming group chat messages */
.chat-msg-sender-header {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 3px;
  font-size: 0.73rem;
  padding-left: 2px;
}
.chat-msg-sender-name {
  font-weight: 700;
  color: #0f172a;
}
.chat-msg-sender-badge {
  font-size: 0.62rem;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 4px;
  background: #e2e8f0;
  color: #334155;
}

/* Smooth message entry animation & layout */
@keyframes chatBubblePop {
  0% { opacity: 0; transform: translateY(6px) scale(0.98); }
  100% { opacity: 1; transform: translateY(0) scale(1); }
}
.chat-msg-row {
  animation: chatBubblePop 0.16s cubic-bezier(0.16, 1, 0.3, 1) !important;
  will-change: transform, opacity !important;
}
.chat-bubble-wrap {
  display: flex !important;
  flex-direction: column !important;
}

/* Custom Sleek Scrollbars */
.chat-messages-body {
  scroll-behavior: smooth !important;
  position: relative !important;
}
.chat-messages-body::-webkit-scrollbar,
.chat-sidebar-scroll::-webkit-scrollbar,
.chat-contacts-scroll::-webkit-scrollbar {
  width: 5px !important;
}
.chat-messages-body::-webkit-scrollbar-track,
.chat-sidebar-scroll::-webkit-scrollbar-track,
.chat-contacts-scroll::-webkit-scrollbar-track {
  background: transparent !important;
}
.chat-messages-body::-webkit-scrollbar-thumb,
.chat-sidebar-scroll::-webkit-scrollbar-thumb,
.chat-contacts-scroll::-webkit-scrollbar-thumb {
  background: #cbd5e1 !important;
  border-radius: 9999px !important;
}
.chat-messages-body::-webkit-scrollbar-thumb:hover,
.chat-sidebar-scroll::-webkit-scrollbar-thumb:hover,
.chat-contacts-scroll::-webkit-scrollbar-thumb:hover {
  background: #94a3b8 !important;
}

/* Optimistic & Pending Message Indicators */
.chat-check-icon.pending {
  color: #94a3b8 !important;
  animation: fa-spin 2s linear infinite !important;
}
.chat-msg-row.msg-pending .chat-bubble {
  opacity: 0.88 !important;
}
.chat-msg-error-hint {
  font-size: 0.68rem;
  color: #ef4444;
  margin-top: 3px;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* Clean Message Composer Textarea (no ugly 1-line scrollbar arrows) */
.chat-textarea {
  overflow-y: hidden !important;
  resize: none !important;
  scrollbar-width: thin;
}
.chat-textarea::-webkit-scrollbar {
  width: 4px;
}
.chat-textarea::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 9999px;
}
</style>

<!-- ── CLIENT LOGIC FOR INTER-CLUB MESSENGER ── -->
<script>
(function() {
  const CHAT_ENDPOINT = '<?= $cm_app_root ?>shared/chat_actions.php';
  const CURRENT_USER_ID = <?= $cm_user_id ?>;
  const CURRENT_USER_ROLE = '<?= $cm_role ?>';

  // Helper to decode HTML entities (e.g. &amp; -> &, &#039; -> ') when assigning to textContent or placeholder
  const _entityDecoder = document.createElement('textarea');
  function decodeChatEntities(str) {
    if (!str) return '';
    _entityDecoder.innerHTML = String(str);
    return _entityDecoder.value;
  }

  let activeGroupId = null;
  let activePartnerId = null;
  let activeTab = 'groups'; // 'groups' | 'chats' | 'contacts'
  let cachedGroups = [];
  let cachedConversations = [];
  let cachedContacts = [];
  let pollTimerActive = null;
  let unreadBadgeTimer = null;
  let isSending = false;

  // Smooth DOM reconciliation and scroll tracking state
  let currentThreadKey = null; // 'group_12' or 'direct_5'
  let renderedMsgIds = new Set();
  let lastKnownDate = '';
  let searchDebounceTimer = null;

  function showBlankState() {
    activeGroupId = null;
    activePartnerId = null;
    currentThreadKey = null;
    renderedMsgIds.clear();
    lastKnownDate = '';
    hideJumpBtn();
    const blank = document.getElementById('chatBlankState');
    const active = document.getElementById('chatActiveWindow');
    if (blank) blank.classList.remove('d-none');
    if (active) active.classList.remove('active');
    document.querySelectorAll('.chat-conversation-item, .chat-group-item').forEach(el => el.classList.remove('active'));
  }

  function showActiveWindow() {
    const blank = document.getElementById('chatBlankState');
    const active = document.getElementById('chatActiveWindow');
    if (blank) blank.classList.add('d-none');
    if (active) active.classList.add('active');
  }

  window.composeNewChat = function() {
    switchChatTab('contacts');
    showBlankState();
  };

  // ── Open / Close Messenger Modal ──
  window.openChatModal = function(targetRecipientId = null, targetGroupId = null) {
    const overlay = document.getElementById('chatModalOverlay');
    if (!overlay) return;
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';

    loadGroups();
    loadConversations();
    loadContactsDirectory();

    if (targetGroupId) {
      switchChatTab('groups');
      loadGroupThread(targetGroupId);
    } else if (targetRecipientId && targetRecipientId !== CURRENT_USER_ID) {
      switchChatTab('chats');
      loadChatThread(targetRecipientId);
    } else if (activeGroupId) {
      loadGroupThread(activeGroupId);
    } else if (activePartnerId) {
      loadChatThread(activePartnerId);
    } else {
      showBlankState();
      setTimeout(() => {
        if (!activeGroupId && !activePartnerId && cachedGroups.length > 0) {
          loadGroupThread(cachedGroups[0].id);
        }
      }, 350);
    }

    startActivePolling();
  };

  window.closeChatModal = function() {
    const overlay = document.getElementById('chatModalOverlay');
    if (!overlay) return;
    overlay.classList.remove('active');
    document.body.style.overflow = '';
    stopActivePolling();
    refreshUnreadBadge();
  };

  // Close modal when clicking on overlay background
  document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('chatModalOverlay');
    if (overlay) {
      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
          closeChatModal();
        }
      });
    }

    // Attach click handler to any topbar chat triggers
    document.querySelectorAll('.topbar-chat-btn, #chatFabBtn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        openChatModal();
      });
    });

    // Close modal on Escape key press
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        const overlay = document.getElementById('chatModalOverlay');
        if (overlay && overlay.classList.contains('active')) {
          closeChatModal();
        }
      }
    });

    // Tab visibility handling: pause/resume polling to save resources when tab is idle
    document.addEventListener('visibilitychange', function() {
      if (!document.hidden) {
        const overlay = document.getElementById('chatModalOverlay');
        if (overlay && overlay.classList.contains('active')) {
          pollActiveMessages();
          refreshUnreadBadge();
        }
      }
    });

    // Start background badge polling
    refreshUnreadBadge();
    unreadBadgeTimer = setInterval(refreshUnreadBadge, 10000);
  });

  // Switch tabs between 'groups', 'chats', and 'contacts'
  window.switchChatTab = function(tabName) {
    activeTab = tabName;
    const tabGroupsBtn   = document.getElementById('tabGroupsBtn');
    const tabChatsBtn    = document.getElementById('tabChatsBtn');
    const tabContactsBtn = document.getElementById('tabContactsBtn');

    const grpCont  = document.getElementById('chatGroupsContainer');
    const convCont = document.getElementById('chatConversationsContainer');
    const contCont = document.getElementById('chatContactsContainer');

    if (tabGroupsBtn)   tabGroupsBtn.classList.toggle('active', tabName === 'groups');
    if (tabChatsBtn)    tabChatsBtn.classList.toggle('active', tabName === 'chats');
    if (tabContactsBtn) tabContactsBtn.classList.toggle('active', tabName === 'contacts');

    if (grpCont)  grpCont.style.display  = (tabName === 'groups') ? 'block' : 'none';
    if (convCont) convCont.style.display = (tabName === 'chats') ? 'block' : 'none';
    if (contCont) contCont.style.display = (tabName === 'contacts') ? 'block' : 'none';

    filterChatList();
  };

  // ── 1. Fetch Authorized Group Chats ──
  function loadGroups() {
    const loading = document.getElementById('chatGroupsLoading');
    const emptyEl = document.getElementById('chatGroupsEmpty');

    fetch(CHAT_ENDPOINT + '?action=get_groups')
      .then(res => res.json())
      .then(data => {
        if (loading) loading.style.display = 'none';
        if (!data.success) return;

        cachedGroups = data.groups || [];
        renderGroups(cachedGroups);
        updateTabUnreadBadge();
      })
      .catch(err => {
        if (loading) loading.style.display = 'none';
        console.error('Failed to load groups:', err);
      });
  }

  function renderGroups(list) {
    const listEl  = document.getElementById('chatGroupsList');
    const emptyEl = document.getElementById('chatGroupsEmpty');
    if (!listEl) return;
    listEl.innerHTML = '';

    if (!list || list.length === 0) {
      if (emptyEl) emptyEl.style.display = 'block';
      return;
    }
    if (emptyEl) emptyEl.style.display = 'none';

    list.forEach(g => {
      const li = document.createElement('li');
      li.className = 'chat-group-item' + (g.id === activeGroupId ? ' active' : '');
      li.dataset.groupId = g.id;
      li.onclick = () => loadGroupThread(g.id);

      const unreadBadge = g.unread_count > 0 
        ? `<span class="chat-conv-unread-pill">${g.unread_count}</span>` 
        : '';

      const lastSnippet = g.last_message 
        ? `${g.last_sender_name ? g.last_sender_name + ': ' : ''}${g.last_message}`
        : 'Welcome to this group channel.';

      const avatarClass = g.group_type === 'advisers_ssc' 
        ? 'chat-group-avatar-ssc' 
        : (g.group_type === 'club_executive' ? 'chat-group-avatar-exec' : '');

      li.innerHTML = `
        <div class="chat-conv-avatar-wrap">
          <div class="chat-group-avatar-icon ${avatarClass}">
            <i class="fa-solid ${g.icon}"></i>
          </div>
        </div>
        <div class="chat-conv-meta">
          <div class="chat-conv-top">
            <span class="chat-conv-name">${g.name}</span>
            <span class="chat-conv-time">${g.last_message_time}</span>
          </div>
          <div style="display:flex; align-items:center; gap:6px; margin: 2px 0;">
            <span class="gc-type-badge ${g.type_badge_class}">${g.type_label}</span>
            <span style="font-size:0.68rem; color:#64748b;"><i class="fa-solid fa-users"></i> ${g.member_count}</span>
          </div>
          <div class="chat-conv-bottom">
            <span class="chat-conv-snippet ${g.unread_count > 0 ? 'unread' : ''}">${lastSnippet}</span>
            ${unreadBadge}
          </div>
        </div>
      `;
      listEl.appendChild(li);
    });
  }

  // ── 2. Fetch Direct Conversations List ──
  function loadConversations() {
    const loading = document.getElementById('chatConvLoading');
    const emptyEl = document.getElementById('chatConvEmpty');

    fetch(CHAT_ENDPOINT + '?action=get_conversations')
      .then(res => res.json())
      .then(data => {
        if (loading) loading.style.display = 'none';
        if (!data.success) return;

        cachedConversations = data.conversations || [];
        renderConversations(cachedConversations);
        updateTabUnreadBadge();
      })
      .catch(err => {
        if (loading) loading.style.display = 'none';
        console.error('Failed to load conversations:', err);
      });
  }

  function renderConversations(list) {
    const listEl  = document.getElementById('chatConversationsList');
    const emptyEl = document.getElementById('chatConvEmpty');
    if (!listEl) return;
    listEl.innerHTML = '';

    if (!list || list.length === 0) {
      if (emptyEl) emptyEl.style.display = 'block';
      return;
    }
    if (emptyEl) emptyEl.style.display = 'none';

    list.forEach(c => {
      const li = document.createElement('li');
      li.className = 'chat-conversation-item' + (c.partner_id === activePartnerId ? ' active' : '');
      li.dataset.partnerId = c.partner_id;
      li.onclick = () => loadChatThread(c.partner_id);

      const avatarHtml = c.profile_pic 
        ? `<img src="<?= $cm_app_root ?>uploads/avatars/${c.profile_pic}" alt="${c.full_name}" class="chat-conv-avatar-img"/>`
        : `<div class="chat-conv-avatar-initial">${c.avatar_initial}</div>`;

      const unreadBadge = c.unread_count > 0 
        ? `<span class="chat-conv-unread-pill">${c.unread_count}</span>` 
        : '';

      li.innerHTML = `
        <div class="chat-conv-avatar-wrap">
          ${avatarHtml}
          <span class="chat-online-dot"></span>
        </div>
        <div class="chat-conv-meta">
          <div class="chat-conv-top">
            <span class="chat-conv-name">${c.full_name}</span>
            <span class="chat-conv-time">${c.last_message_time}</span>
          </div>
          <div class="chat-conv-role">${c.role_badge}</div>
          <div class="chat-conv-bottom">
            <span class="chat-conv-snippet ${c.unread_count > 0 ? 'unread' : ''}">${c.last_message || 'Started a conversation.'}</span>
            ${unreadBadge}
          </div>
        </div>
      `;
      listEl.appendChild(li);
    });
  }

  // ── 3. Fetch Contacts Directory ──
  function loadContactsDirectory() {
    const loading = document.getElementById('chatContactsLoading');
    fetch(CHAT_ENDPOINT + '?action=get_contacts')
      .then(res => res.json())
      .then(data => {
        if (loading) loading.style.display = 'none';
        if (!data.success) return;
        cachedContacts = data.contacts || [];
        renderContacts(cachedContacts);
      })
      .catch(err => {
        if (loading) loading.style.display = 'none';
        console.error('Failed to load contacts directory:', err);
      });
  }

  function renderContacts(list) {
    const contList = document.getElementById('chatContactsList');
    const emptyEl  = document.getElementById('chatContactsEmpty');
    if (!contList) return;
    contList.innerHTML = '';

    if (!list || list.length === 0) {
      if (emptyEl) emptyEl.style.display = 'block';
      return;
    }
    if (emptyEl) emptyEl.style.display = 'none';

    // Group contacts by category
    const grouped = {};
    list.forEach(item => {
      const cat = item.category || 'Other Contacts';
      if (!grouped[cat]) grouped[cat] = [];
      grouped[cat].push(item);
    });

    for (const [catName, members] of Object.entries(grouped)) {
      const groupEl = document.createElement('div');
      groupEl.className = 'chat-contacts-group';

      const catHeader = document.createElement('div');
      catHeader.className = 'chat-contacts-cat-heading';
      catHeader.innerHTML = `<i class="fa-solid fa-folder-open"></i> ${catName} <span class="chat-cat-count">(${members.length})</span>`;
      groupEl.appendChild(catHeader);

      const ul = document.createElement('ul');
      ul.className = 'chat-contact-items';

      members.forEach(m => {
        const itemLi = document.createElement('li');
        itemLi.className = 'chat-contact-row';
        itemLi.onclick = () => {
          loadChatThread(m.id);
          switchChatTab('chats');
        };

        const avatarHtml = m.profile_pic 
          ? `<img src="<?= $cm_app_root ?>uploads/avatars/${m.profile_pic}" alt="${m.name}" class="chat-conv-avatar-img"/>`
          : `<div class="chat-conv-avatar-initial">${m.avatar_initial}</div>`;

        itemLi.innerHTML = `
          <div class="chat-conv-avatar-wrap">
            ${avatarHtml}
          </div>
          <div class="chat-contact-info">
            <div class="chat-contact-name">${m.name}</div>
            <div class="chat-contact-role"><i class="fa-solid fa-id-badge"></i> ${m.role_label}</div>
            <div class="chat-contact-club">${m.club_name}</div>
          </div>
          <button type="button" class="btn-chat-row-act" title="Message ${m.name}">
            <i class="fa-solid fa-paper-plane"></i>
          </button>
        `;
        ul.appendChild(itemLi);
      });

      groupEl.appendChild(ul);
      contList.appendChild(groupEl);
    }
  }

  // ── 4. Load Group Thread ──
  window.loadGroupThread = function(groupId) {
    if (!groupId) return;
    activeGroupId = groupId;
    activePartnerId = null;

    showActiveWindow();

    // Highlight in group list
    document.querySelectorAll('.chat-group-item').forEach(el => {
      if (parseInt(el.dataset.groupId, 10) === groupId) {
        el.classList.add('active');
        const pill = el.querySelector('.chat-conv-unread-pill');
        if (pill) pill.remove();
      } else {
        el.classList.remove('active');
      }
    });
    document.querySelectorAll('.chat-conversation-item').forEach(el => el.classList.remove('active'));

    // Mobile: show main pane
    const sidebar = document.getElementById('chatSidebarPane');
    const mainPane = document.getElementById('chatMainPane');
    if (window.innerWidth <= 768) {
      if (sidebar) sidebar.classList.add('hidden-mobile');
      if (mainPane) mainPane.classList.add('active-mobile');
    }

    const flowEl = document.getElementById('chatMessagesFlow');
    const loadingEl = document.getElementById('chatHistoryLoading');
    if (loadingEl) loadingEl.style.display = 'block';

    fetch(CHAT_ENDPOINT + '?action=get_messages&group_id=' + groupId)
      .then(res => res.json())
      .then(data => {
        if (loadingEl) loadingEl.style.display = 'none';
        if (!data.success) {
          alert(data.message || 'Failed to load group channel.');
          return;
        }

        renderGroupHeader(data.group);
        renderMessagesFlow(data.messages, true);
        scrollToBottom();
        refreshUnreadBadge();
      })
      .catch(err => {
        if (loadingEl) loadingEl.style.display = 'none';
        console.error('Error fetching group messages:', err);
      });
  };

  function renderGroupHeader(group) {
    const avatarEl  = document.getElementById('chatActiveAvatar');
    const nameEl    = document.getElementById('chatActivePartnerName');
    const roleEl    = document.getElementById('chatActivePartnerRole');
    const unameEl   = document.getElementById('chatActivePartnerUsername');

    const cleanName = decodeChatEntities(group.name || 'Group Chat');
    const cleanType = decodeChatEntities(group.type_label || 'Group Channel');

    if (avatarEl) {
      avatarEl.innerHTML = `<i class="fa-solid ${group.icon}"></i>`;
    }
    if (nameEl) nameEl.textContent = cleanName;
    if (roleEl) {
      roleEl.textContent = cleanType;
      roleEl.className = 'chat-role-chip';
    }
    if (unameEl) unameEl.style.display = 'none';

    const input = document.getElementById('chatInputMessage');
    if (input) {
      input.placeholder = `Type a message to ${cleanName}... (Press Enter to send)`;
    }
  }

  // ── 5. Load Direct Chat Thread ──
  window.loadChatThread = function(partnerId) {
    if (!partnerId) return;
    activePartnerId = partnerId;
    activeGroupId = null;

    showActiveWindow();

    // Highlight in direct list
    document.querySelectorAll('.chat-conversation-item').forEach(el => {
      if (parseInt(el.dataset.partnerId, 10) === partnerId) {
        el.classList.add('active');
        const pill = el.querySelector('.chat-conv-unread-pill');
        if (pill) pill.remove();
      } else {
        el.classList.remove('active');
      }
    });
    document.querySelectorAll('.chat-group-item').forEach(el => el.classList.remove('active'));

    // Mobile: show main pane
    const sidebar = document.getElementById('chatSidebarPane');
    const mainPane = document.getElementById('chatMainPane');
    if (window.innerWidth <= 768) {
      if (sidebar) sidebar.classList.add('hidden-mobile');
      if (mainPane) mainPane.classList.add('active-mobile');
    }

    const flowEl = document.getElementById('chatMessagesFlow');
    const loadingEl = document.getElementById('chatHistoryLoading');
    if (loadingEl) loadingEl.style.display = 'block';

    fetch(CHAT_ENDPOINT + '?action=get_messages&recipient_id=' + partnerId)
      .then(res => res.json())
      .then(data => {
        if (loadingEl) loadingEl.style.display = 'none';
        if (!data.success) {
          alert(data.message || 'Failed to load thread.');
          return;
        }

        renderPartnerHeader(data.partner);
        renderMessagesFlow(data.messages, false);
        scrollToBottom();
        refreshUnreadBadge();
      })
      .catch(err => {
        if (loadingEl) loadingEl.style.display = 'none';
        console.error('Error fetching messages:', err);
      });
  };

  function renderPartnerHeader(partner) {
    const avatarEl  = document.getElementById('chatActiveAvatar');
    const nameEl    = document.getElementById('chatActivePartnerName');
    const roleEl    = document.getElementById('chatActivePartnerRole');
    const unameEl   = document.getElementById('chatActivePartnerUsername');

    const cleanName = decodeChatEntities(partner.name || 'User');
    const cleanBadge = decodeChatEntities(partner.role_badge || 'Partner');

    if (avatarEl) {
      if (partner.profile_pic) {
        avatarEl.innerHTML = `<img src="<?= $cm_app_root ?>uploads/avatars/${partner.profile_pic}" alt="${partner.name}" class="chat-conv-avatar-img"/>`;
      } else {
        avatarEl.innerHTML = partner.avatar_initial || 'U';
      }
    }
    if (nameEl)  nameEl.textContent = cleanName;
    if (roleEl)  roleEl.textContent = cleanBadge;
    if (unameEl) {
      unameEl.style.display = 'inline';
      unameEl.textContent = '@' + decodeChatEntities(partner.username || '');
    }

    const input = document.getElementById('chatInputMessage');
    if (input) {
      input.placeholder = `Message ${cleanName}... (Press Enter to send)`;
    }
  }

  // ── Helper: Create Message Row Element ──
  function createMessageRowElement(m, isGroup) {
    const msgRow = document.createElement('div');
    msgRow.className = 'chat-msg-row ' + (m.is_me ? 'msg-outgoing' : 'msg-incoming') + (m.is_pending ? ' msg-pending' : '');
    msgRow.dataset.msgId = m.id;

    let senderHeaderHtml = '';
    if (isGroup && !m.is_me) {
      senderHeaderHtml = `
        <div class="chat-msg-sender-header">
          <span class="chat-msg-sender-name">${m.sender_name}</span>
          <span class="chat-msg-sender-badge">${m.sender_badge}</span>
        </div>
      `;
    }

    const bubbleWrap = document.createElement('div');
    bubbleWrap.className = 'chat-bubble-wrap';

    if (senderHeaderHtml) {
      bubbleWrap.innerHTML = senderHeaderHtml;
    }

    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble';
    bubble.textContent = decodeChatEntities(m.message);
    bubbleWrap.appendChild(bubble);

    let checkIconHtml = '';
    if (m.is_me) {
      if (m.is_pending) {
        checkIconHtml = ` <i class="fa-regular fa-clock chat-check-icon pending" title="Sending..."></i>`;
      } else {
        checkIconHtml = ` <i class="fa-solid fa-check-double chat-check-icon ${m.is_read ? 'read' : ''}" title="${m.is_read ? 'Read' : 'Delivered'}"></i>`;
      }
    }

    const meta = document.createElement('div');
    meta.className = 'chat-msg-meta';
    meta.innerHTML = `<span>${m.timestamp || 'Just now'}</span>` + checkIconHtml;
    bubbleWrap.appendChild(meta);

    msgRow.appendChild(bubbleWrap);
    return msgRow;
  }

  // ── Render Messages Flow (Smooth Incremental Reconciled Rendering) ──
  function renderMessagesFlow(messages, isGroup = false) {
    const flowEl = document.getElementById('chatMessagesFlow');
    if (!flowEl) return;

    const newThreadKey = isGroup ? ('group_' + activeGroupId) : ('direct_' + activePartnerId);
    const isNewThread = (newThreadKey !== currentThreadKey);

    if (isNewThread) {
      currentThreadKey = newThreadKey;
      renderedMsgIds.clear();
      lastKnownDate = '';
      flowEl.innerHTML = '';

      if (!messages || messages.length === 0) {
        flowEl.innerHTML = `
          <div class="chat-empty-thread">
            <i class="fa-solid fa-comments"></i>
            <p>${isGroup ? 'No messages in this group channel yet.<br>Send a greeting to start coordinating!' : 'No messages yet in this thread.<br>Send a message to start communicating!'}</p>
          </div>
        `;
        return;
      }

      const fragment = document.createDocumentFragment();
      messages.forEach(m => {
        if (m.date && m.date !== lastKnownDate) {
          lastKnownDate = m.date;
          const divider = document.createElement('div');
          divider.className = 'chat-date-divider';
          divider.innerHTML = `<span>${m.date}</span>`;
          fragment.appendChild(divider);
        }
        const row = createMessageRowElement(m, isGroup);
        fragment.appendChild(row);
        renderedMsgIds.add(m.id);
      });
      flowEl.appendChild(fragment);
      scrollToBottom(false);
      return;
    }

    // Same active thread: perform smooth incremental append (zero flickering / zero DOM rebuilds)
    if (!messages || messages.length === 0) return;

    // Remove empty thread placeholder if new messages arrived
    const emptyThread = flowEl.querySelector('.chat-empty-thread');
    if (emptyThread) emptyThread.remove();

    // Reconcile read receipts for direct messages
    if (!isGroup) {
      messages.forEach(m => {
        if (m.is_me && m.is_read) {
          const row = flowEl.querySelector(`.chat-msg-row[data-msg-id="${m.id}"]`);
          if (row) {
            const check = row.querySelector('.chat-check-icon');
            if (check && !check.classList.contains('read')) {
              check.classList.add('read');
              check.title = 'Read';
            }
          }
        }
      });
    }

    // Identify unrendered new messages
    const newMsgs = messages.filter(m => !renderedMsgIds.has(m.id));
    if (newMsgs.length === 0) return; // Zero DOM work required

    const nearBottom = isScrollNearBottom();
    let hasMyMsg = false;

    newMsgs.forEach(m => {
      // Reconcile optimistic temporary bubbles
      const pendingBubble = flowEl.querySelector('.chat-msg-row.msg-pending');
      if (pendingBubble && m.is_me && pendingBubble.querySelector('.chat-bubble')?.textContent === decodeChatEntities(m.message)) {
        pendingBubble.dataset.msgId = m.id;
        pendingBubble.classList.remove('msg-pending');
        const check = pendingBubble.querySelector('.chat-check-icon');
        if (check) {
          check.className = 'fa-solid fa-check chat-check-icon';
          check.title = 'Sent';
        }
        const timeSpan = pendingBubble.querySelector('.chat-msg-meta span');
        if (timeSpan) timeSpan.textContent = m.timestamp;
        renderedMsgIds.add(m.id);
        hasMyMsg = true;
        return;
      }

      if (m.date && m.date !== lastKnownDate) {
        lastKnownDate = m.date;
        const divider = document.createElement('div');
        divider.className = 'chat-date-divider';
        divider.innerHTML = `<span>${m.date}</span>`;
        flowEl.appendChild(divider);
      }

      const row = createMessageRowElement(m, isGroup);
      flowEl.appendChild(row);
      renderedMsgIds.add(m.id);
      if (m.is_me) hasMyMsg = true;
    });

    if (nearBottom || hasMyMsg) {
      scrollToBottom(true);
    }
  }

  // ── 6. Send Message (Direct or Group with Instant Optimistic UI) ──
  window.submitChatMessage = function(e) {
    if (e) e.preventDefault();
    if (isSending) return;
    if (!activeGroupId && !activePartnerId) return;

    const input = document.getElementById('chatInputMessage');
    const msgText = (input.value || '').trim();
    if (!msgText) return;

    const targetGroupId = activeGroupId;
    const targetPartnerId = activePartnerId;
    const isGroup = !!targetGroupId;
    const tempId = 'temp_' + Date.now();

    // 1. Instantly clear input and smoothly reset height
    input.value = '';
    autoResizeChatInput(input);
    input.focus();

    // 2. Render optimistic bubble immediately
    const flowEl = document.getElementById('chatMessagesFlow');
    const emptyThread = flowEl ? flowEl.querySelector('.chat-empty-thread') : null;
    if (emptyThread) emptyThread.remove();

    const optimisticMsg = {
      id: tempId,
      sender_id: CURRENT_USER_ID,
      sender_name: 'Me',
      sender_role: CURRENT_USER_ROLE,
      sender_badge: 'Me',
      is_me: true,
      is_pending: true,
      message: msgText,
      timestamp: 'Just now',
      date: 'Today',
      is_read: false
    };

    const tempRow = createMessageRowElement(optimisticMsg, isGroup);
    if (flowEl) {
      flowEl.appendChild(tempRow);
      scrollToBottom(true);
    }

    isSending = true;
    const sendBtn = document.getElementById('chatSendBtn');
    if (sendBtn) sendBtn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('message', msgText);
    if (targetGroupId) {
      formData.append('group_id', targetGroupId);
    } else {
      formData.append('recipient_id', targetPartnerId);
    }

    fetch(CHAT_ENDPOINT, {
      method: 'POST',
      body: formData,
      headers: {
        'X-CSRF-Token': window.CSRF_TOKEN || ''
      }
    })
    .then(res => res.json())
    .then(data => {
      isSending = false;
      if (sendBtn) sendBtn.disabled = false;

      if (!data.success) {
        if (tempRow) {
          tempRow.classList.remove('msg-pending');
          const meta = tempRow.querySelector('.chat-msg-meta');
          if (meta) {
            meta.innerHTML = `<span class="chat-msg-error-hint"><i class="fa-solid fa-circle-exclamation"></i> ${data.message || 'Failed to send'}</span>`;
          }
        }
        return;
      }

      // Upgrade optimistic bubble with server-confirmed message
      if (tempRow && data.new_message) {
        const m = data.new_message;
        tempRow.dataset.msgId = m.id;
        tempRow.classList.remove('msg-pending');
        const check = tempRow.querySelector('.chat-check-icon');
        if (check) {
          check.className = 'fa-solid fa-check chat-check-icon';
          check.title = 'Sent';
        }
        const timeSpan = tempRow.querySelector('.chat-msg-meta span');
        if (timeSpan) timeSpan.textContent = m.timestamp;
        renderedMsgIds.add(m.id);
      }

      if (targetGroupId) {
        loadGroups();
      } else {
        loadConversations();
      }
    })
    .catch(err => {
      isSending = false;
      if (sendBtn) sendBtn.disabled = false;
      if (tempRow) {
        tempRow.classList.remove('msg-pending');
        const meta = tempRow.querySelector('.chat-msg-meta');
        if (meta) {
          meta.innerHTML = `<span class="chat-msg-error-hint"><i class="fa-solid fa-triangle-exclamation"></i> Network error</span>`;
        }
      }
      console.error('Failed to send message:', err);
    });
  };

  // Keyboard shortcut: Enter to send, Shift+Enter for newline
  window.onChatInputKeyDown = function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      submitChatMessage();
    }
  };

  window.autoResizeChatInput = function(textarea) {
    if (!textarea) return;
    textarea.style.height = 'auto';
    const nextHeight = Math.min(textarea.scrollHeight, 120);
    textarea.style.height = nextHeight + 'px';
    textarea.style.overflowY = textarea.scrollHeight > 120 ? 'auto' : 'hidden';
  };

  // ── Scroll Helpers ──
  window.scrollToBottom = function(smooth = false) {
    const body = document.getElementById('chatMessagesBody');
    if (body) {
      if (smooth) {
        body.scrollTo({ top: body.scrollHeight, behavior: 'smooth' });
      } else {
        body.scrollTop = body.scrollHeight;
      }
    }
  };

  function isScrollNearBottom() {
    const body = document.getElementById('chatMessagesBody');
    if (!body) return true;
    return (body.scrollHeight - body.scrollTop - body.clientHeight) < 120;
  }

  function hideJumpBtn() {}
  function showJumpBtn() {}

  // ── 8. Polling & Auto-Refresh ──
  function startActivePolling() {
    stopActivePolling();
    pollTimerActive = setInterval(pollActiveMessages, 2500);
  }

  function stopActivePolling() {
    if (pollTimerActive) {
      clearInterval(pollTimerActive);
      pollTimerActive = null;
    }
  }

  function pollActiveMessages() {
    const overlay = document.getElementById('chatModalOverlay');
    if (!overlay || !overlay.classList.contains('active')) return;
    if (document.hidden) return; // Tab in background, avoid wasted polling

    if (activeGroupId) {
      fetch(CHAT_ENDPOINT + '?action=get_messages&group_id=' + activeGroupId)
        .then(res => res.json())
        .then(data => {
          if (!data.success) return;
          renderMessagesFlow(data.messages, true);
        })
        .catch(() => {});
    } else if (activePartnerId) {
      fetch(CHAT_ENDPOINT + '?action=get_messages&recipient_id=' + activePartnerId)
        .then(res => res.json())
        .then(data => {
          if (!data.success) return;
          renderMessagesFlow(data.messages, false);
        })
        .catch(() => {});
    }
  }

  window.refreshActiveMessages = function() {
    if (activeGroupId) {
      loadGroupThread(activeGroupId);
    } else if (activePartnerId) {
      loadChatThread(activePartnerId);
    }
  };

  // ── 9. Badge Counters ──
  function refreshUnreadBadge() {
    fetch(CHAT_ENDPOINT + '?action=get_unread_count')
      .then(res => res.json())
      .then(data => {
        if (!data.success) return;
        const total = parseInt(data.unread_count, 10) || 0;
        const direct = parseInt(data.unread_direct_count, 10) || 0;
        const group = parseInt(data.unread_group_count, 10) || 0;

        // Topbar badge
        document.querySelectorAll('.chat-unread-badge, #chatUnreadBadge').forEach(badge => {
          if (total > 0) {
            badge.textContent = total > 99 ? '99+' : total;
            badge.style.display = 'inline-flex';
          } else {
            badge.textContent = '';
            badge.style.display = 'none';
          }
        });

        // Tab badges
        const tabGroupsBadge = document.getElementById('chatTabGroupsUnreadCount');
        if (tabGroupsBadge) {
          if (group > 0) {
            tabGroupsBadge.textContent = group > 99 ? '99+' : group;
            tabGroupsBadge.style.display = 'inline-flex';
          } else {
            tabGroupsBadge.textContent = '';
            tabGroupsBadge.style.display = 'none';
          }
        }

        const tabDirectBadge = document.getElementById('chatTabUnreadCount');
        if (tabDirectBadge) {
          if (direct > 0) {
            tabDirectBadge.textContent = direct > 99 ? '99+' : direct;
            tabDirectBadge.style.display = 'inline-flex';
          } else {
            tabDirectBadge.textContent = '';
            tabDirectBadge.style.display = 'none';
          }
        }
      })
      .catch(() => {});
  }

  function updateTabUnreadBadge() {
    let directTot = 0;
    cachedConversations.forEach(c => { directTot += (c.unread_count || 0); });
    let groupTot = 0;
    cachedGroups.forEach(g => { groupTot += (g.unread_count || 0); });

    const tabDirectBadge = document.getElementById('chatTabUnreadCount');
    if (tabDirectBadge) {
      if (directTot > 0) {
        tabDirectBadge.textContent = directTot > 99 ? '99+' : directTot;
        tabDirectBadge.style.display = 'inline-flex';
      } else {
        tabDirectBadge.textContent = '';
        tabDirectBadge.style.display = 'none';
      }
    }

    const tabGroupsBadge = document.getElementById('chatTabGroupsUnreadCount');
    if (tabGroupsBadge) {
      if (groupTot > 0) {
        tabGroupsBadge.textContent = groupTot > 99 ? '99+' : groupTot;
        tabGroupsBadge.style.display = 'inline-flex';
      } else {
        tabGroupsBadge.textContent = '';
        tabGroupsBadge.style.display = 'none';
      }
    }
  }

  // ── 10. Search & Filter (Debounced for 60fps responsiveness) ──
  window.filterChatList = function(immediate = false) {
    if (immediate) {
      runFilterChatList();
      return;
    }
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(runFilterChatList, 120);
  };

  function runFilterChatList() {
    const input = document.getElementById('chatSearchInput');
    const query = (input ? input.value : '').toLowerCase().trim();
    const clearBtn = document.getElementById('chatSearchClearBtn');
    if (clearBtn) clearBtn.style.display = query ? 'inline-flex' : 'none';

    if (activeTab === 'groups') {
      const filtered = cachedGroups.filter(g => {
        return (g.name && g.name.toLowerCase().includes(query)) ||
               (g.type_label && g.type_label.toLowerCase().includes(query)) ||
               (g.club_code && g.club_code.toLowerCase().includes(query)) ||
               (g.description && g.description.toLowerCase().includes(query)) ||
               (g.last_message && g.last_message.toLowerCase().includes(query));
      });
      renderGroups(filtered);
    } else if (activeTab === 'chats') {
      const filtered = cachedConversations.filter(c => {
        return (c.full_name && c.full_name.toLowerCase().includes(query)) ||
               (c.role_badge && c.role_badge.toLowerCase().includes(query)) ||
               (c.last_message && c.last_message.toLowerCase().includes(query));
      });
      renderConversations(filtered);
    } else {
      const filtered = cachedContacts.filter(m => {
        return (m.name && m.name.toLowerCase().includes(query)) ||
               (m.role_label && m.role_label.toLowerCase().includes(query)) ||
               (m.club_name && m.club_name.toLowerCase().includes(query));
      });
      renderContacts(filtered);
    }
  }

  window.clearChatSearch = function() {
    const input = document.getElementById('chatSearchInput');
    if (input) input.value = '';
    filterChatList(true);
  };

  // Mobile Back Button
  window.returnToSidebarMobile = function() {
    const sidebar = document.getElementById('chatSidebarPane');
    const mainPane = document.getElementById('chatMainPane');
    if (sidebar) sidebar.classList.remove('hidden-mobile');
    if (mainPane) mainPane.classList.remove('active-mobile');
    if (activeTab === 'groups') {
      loadGroups();
    } else {
      loadConversations();
    }
  };

})();
</script>
