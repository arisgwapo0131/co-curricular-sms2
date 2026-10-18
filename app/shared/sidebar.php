<?php
// ============================================================
//  SIDEBAR.PHP  (shared/)
//  Role-Filtered Categorized Dropdown Sidebar
//  Strictly enforces RBAC visibility based on Institutional Workflow:
//  Student → Adviser → SSC Officer → System Admin
// ============================================================
require_once __DIR__ . '/security.php';

$APP_ROOT   = $APP_ROOT   ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
$user_role  = $_SESSION['role'] ?? 'student';
?>

<!-- Global Screen-Centered Modern Notification & Decision Engine -->
<script src="<?= $APP_ROOT ?>js/system-notifications.js?v=<?= filemtime(__DIR__ . '/../js/system-notifications.js') ?>"></script>

<!-- Global CSRF Token Meta Tag -->
<meta name="csrf-token" content="<?= csrf_token() ?>">

<!-- Global CSRF Auto-Attacher for fetch & AJAX requests -->
<script>
window.CSRF_TOKEN = "<?= csrf_token() ?>";
(function() {
  const origFetch = window.fetch;
  window.fetch = function(url, options = {}) {
    options = options || {};
    options.headers = options.headers || {};
    if (options.headers instanceof Headers) {
      if (!options.headers.has('X-CSRF-Token')) {
        options.headers.append('X-CSRF-Token', window.CSRF_TOKEN);
      }
    } else if (Array.isArray(options.headers)) {
      options.headers.push(['X-CSRF-Token', window.CSRF_TOKEN]);
    } else {
      options.headers['X-CSRF-Token'] = window.CSRF_TOKEN;
    }
    return origFetch(url, options);
  };
})();
</script>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" title="Go to Dashboard">
        <img src="<?= $APP_ROOT ?>images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
      </a>
      <span class="sidebar-notif" id="bellBtn" title="Notifications">
        <i class="fa-solid fa-bell"></i>
        <span class="sidebar-notif-badge has-notif" id="bellBadge">0</span>
      </span>
    </div>
  </div>

  <div class="sidebar-nav">

    <!-- ══════════════════════════════════════════════════════════
         PERMISSION-DRIVEN DYNAMIC NAVIGATION
         Enforces granular RBAC keys loaded from MySQL
    ══════════════════════════════════════════════════════════ -->

    <!-- SECTION 1: DASHBOARD / OVERVIEW -->
    <?php if (can_any(['dashboard.view.system', 'dashboard.view.institutional', 'dashboard.view.org', 'dashboard.view.own'])): ?>
      <div class="sidebar-brand sidebar-brand-2">
        <?php if ($user_role === 'super_admin'): ?>
          <div class="brand-title">Super Admin Command</div>
          <div class="brand-sub">Root &amp; Institutional Authority</div>
        <?php elseif ($user_role === 'staff'): ?>
          <div class="brand-title">OSA Operations Hub</div>
          <div class="brand-sub">Student Affairs Operations</div>
        <?php elseif (can('dashboard.view.system')): ?>
          <div class="brand-title">Control Center</div>
          <div class="brand-sub">Identity &amp; Master Data</div>
        <?php elseif (can('dashboard.view.institutional')): ?>
          <div class="brand-title">Executive Core</div>
          <div class="brand-sub">Council Oversight</div>
        <?php else: ?>
          <div class="brand-title">Core Navigation</div>
          <div class="brand-sub">General &amp; Overview</div>
        <?php endif; ?>
      </div>

      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV==='dashboard'?'active':'' ?>">
          <i class="fa-solid <?= can('dashboard.view.system') ? 'fa-gauge-high' : 'fa-gauge' ?>"></i>
          <span>
            <?= ($user_role === 'super_admin') ? 'Super Admin Dashboard' : (($user_role === 'staff') ? 'OSA Staff Dashboard' : (can('dashboard.view.system') ? 'Dashboard' : (can('dashboard.view.institutional') ? 'Executive Dashboard' : 'Dashboard'))) ?>
          </span>
        </a>
      </div>
    <?php endif; ?>

    <!-- SECTION 2: MASTER DATA & DIRECTORY -->
    <?php if (can_any(['users.manage.all', 'organization.crud', 'master_data.academic', 'organization.review.all', 'organization.view'])): ?>
      <!-- User & Access Management -->
      <?php if (can('users.manage.all')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_system.php" class="sidebar-item <?= in_array($ACTIVE_NAV, ['admin', 'admin_users']) ? 'active' : '' ?>">
            <i class="fa-solid fa-users-gear"></i>
            <span>User &amp; Access Management</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Organization Master Data (Admin) -->
      <?php if (can('organization.crud')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_org_master.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_org_master') ? 'active' : '' ?>">
            <i class="fa-solid fa-sitemap"></i>
            <span>Organization Master Data</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Academic Master Data (Admin) -->
      <?php if (can('master_data.academic')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_academics.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_academics') ? 'active' : '' ?>">
            <i class="fa-solid fa-graduation-cap"></i>
            <span>Academic Master Data</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Organization Directory / Governance (SSC / Student) -->
      <?php if (!can('organization.crud') && can('organization.view')): ?>
        <?php if (can('organization.review.all')): ?>
          <!-- SSC Governance -->
          <div class="nav-group">
            <a href="<?= $APP_ROOT ?>dashboard/club_directory.php" class="sidebar-item <?= $ACTIVE_NAV==='clubs'?'active':'' ?>">
              <i class="fa-solid fa-sitemap"></i>
              <span>Club Directory</span>
            </a>
          </div>
        <?php elseif (!can('organization.manage.own')): ?>
          <!-- Student Directory -->
          <div class="nav-group">
            <a href="<?= $APP_ROOT ?>dashboard/club_directory.php" class="sidebar-item <?= $ACTIVE_NAV==='clubs'?'active':'' ?>">
              <i class="fa-solid fa-sitemap"></i>
              <span>Club Directory</span>
            </a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

    <!-- SECTION 3: WORKFLOW ADMINISTRATION & GOVERNANCE -->
    <?php if (can_any([
        'membership.review.all', 'membership.review.own',
        'events.approve.admin', 'events.review.ssc', 'events.create.own', 'events.create.institutional', 'events.view',
        'budget.disburse.admin', 'budget.review.ssc', 'budget.endorse.adviser', 'budget.create.own', 'budget.view.all',
        'elections.admin', 'elections.oversight', 'elections.manage.org', 'elections.vote'
    ])): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <?php if (can('events.approve.admin')): ?>
          <div class="brand-title">Workflow Administration</div>
          <div class="brand-sub">Approvals &amp; Pipeline</div>
        <?php elseif (can('events.review.ssc')): ?>
          <div class="brand-title">Governance</div>
          <div class="brand-sub">Endorsements &amp; Voting</div>
        <?php elseif (can('events.create.own')): ?>
          <div class="brand-title">Governance</div>
          <div class="brand-sub">Roster &amp; Elections</div>
        <?php else: ?>
          <div class="brand-title">Governance</div>
          <div class="brand-sub">Events &amp; Elections</div>
        <?php endif; ?>
      </div>

      <!-- Roster Management / Oversight / My Memberships -->
      <?php if ($user_role === 'student'): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/roster.php" class="sidebar-item <?= ($ACTIVE_NAV === 'roster') ? 'active' : '' ?>">
            <i class="fa-solid fa-users"></i>
            <span>Student Club Memberships</span>
          </a>
        </div>
      <?php elseif (can_any(['membership.review.all', 'membership.review.own'])): ?>
        <?php
          $is_roster_active = ($ACTIVE_NAV === 'roster');
          $roster_sub = $ACTIVE_SUB ?? ($_GET['view'] ?? 'queue');
        ?>
        <div class="nav-group">
          <button class="sidebar-item <?= $is_roster_active ? 'active open' : '' ?> dropdown-trigger" data-target="dropRoster">
            <i class="fa-solid fa-users"></i>
            <span>Student Club Memberships</span>
            <i class="fa-solid fa-chevron-down arrow"></i>
          </button>
          <div class="dropdown-menu <?= $is_roster_active ? 'open' : '' ?>" id="dropRoster">
            <a href="<?= $APP_ROOT ?>dashboard/roster.php?view=queue" class="dropdown-item <?= ($is_roster_active && $roster_sub === 'queue') ? 'active' : '' ?>">Club Registration Portal</a>
            <a href="<?= $APP_ROOT ?>dashboard/roster.php?view=roster" class="dropdown-item <?= ($is_roster_active && $roster_sub === 'roster') ? 'active' : '' ?>">Member Roster</a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Events and Activities (Student Dropdown) -->
      <?php if ($user_role === 'student'): ?>
        <?php
          $is_events_active = ($ACTIVE_NAV === 'events');
          $events_sub = $ACTIVE_SUB ?? ($_GET['view'] ?? 'calendar');
        ?>
        <div class="nav-group">
          <button class="sidebar-item <?= $is_events_active ? 'active open' : '' ?> dropdown-trigger" data-target="dropStudentEvents">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Event &amp; Activity Logs</span>
            <i class="fa-solid fa-chevron-down arrow"></i>
          </button>
          <div class="dropdown-menu <?= $is_events_active ? 'open' : '' ?>" id="dropStudentEvents">
            <a href="<?= $APP_ROOT ?>dashboard/events.php?view=calendar" class="dropdown-item <?= ($is_events_active && $events_sub === 'calendar') ? 'active' : '' ?>">Event Calendar</a>
            <a href="<?= $APP_ROOT ?>dashboard/events.php?view=posted" class="dropdown-item <?= ($is_events_active && in_array($events_sub, ['posted', 'events_posted', 'list'])) ? 'active' : '' ?>">Events Posted</a>
          </div>
        </div>
      <?php elseif (can_any(['events.approve.admin', 'events.review.ssc', 'events.create.institutional', 'events.create.own', 'events.view'])): ?>
        <?php
          $is_events_active = ($ACTIVE_NAV === 'events');
          $events_sub = $ACTIVE_SUB ?? ($_GET['view'] ?? 'calendar');
        ?>
        <div class="nav-group">
          <button class="sidebar-item <?= $is_events_active ? 'active open' : '' ?> dropdown-trigger" data-target="dropEvents">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Event &amp; Activity Logs</span>
            <i class="fa-solid fa-chevron-down arrow"></i>
          </button>
          <div class="dropdown-menu <?= $is_events_active ? 'open' : '' ?>" id="dropEvents">
            <a href="<?= $APP_ROOT ?>dashboard/events.php?view=calendar" class="dropdown-item <?= ($is_events_active && $events_sub === 'calendar') ? 'active' : '' ?>">Active Calendar</a>
            <a href="<?= $APP_ROOT ?>dashboard/events.php?view=pipeline" class="dropdown-item <?= ($is_events_active && $events_sub === 'pipeline') ? 'active' : '' ?>"><?= ($user_role === 'ssc') ? 'Event Proposals' : 'Event Proposals &amp; Pipeline' ?></a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Elections -->
      <?php if (can('elections.admin')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/elections.php" class="sidebar-item <?= ($ACTIVE_NAV==='elections')?'active':'' ?>">
            <i class="fa-solid fa-check-to-slot"></i>
            <span>Club Officer Elections</span>
          </a>
        </div>
      <?php elseif (can('elections.oversight')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/elections.php" class="sidebar-item <?= ($ACTIVE_NAV==='elections')?'active':'' ?>">
            <i class="fa-solid fa-check-to-slot"></i>
            <span>Club Officer Elections</span>
          </a>
        </div>
      <?php elseif (can_any(['elections.manage.org', 'elections.vote'])): ?>
        <?php
          $show_elections = false;
          if ($user_role === 'student') {
              $show_elections = is_student_organization_member(null, (int)($_SESSION['user_id'] ?? 0));
          } elseif (in_array($user_role, ['club_adviser', 'ssc', 'admin', 'super_admin', 'staff'])) {
              $show_elections = true;
          }
        ?>
        <?php if ($show_elections): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/elections.php" class="sidebar-item <?= ($ACTIVE_NAV==='elections')?'active':'' ?>">
            <i class="fa-solid fa-check-to-slot"></i>
            <span>Club Officer Elections</span>
          </a>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Volunteer Hours (Students must be approved organization member) -->
      <?php
        $show_volunteer = false;
        if ($user_role === 'student') {
            $show_volunteer = is_student_organization_member(null, (int)($_SESSION['user_id'] ?? 0));
        } elseif (in_array($user_role, ['club_adviser', 'ssc', 'admin', 'super_admin', 'staff'])) {
            $show_volunteer = true;
        }
      ?>
      <?php if ($show_volunteer): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/volunteer.php" class="sidebar-item <?= ($ACTIVE_NAV==='volunteer')?'active':'' ?>">
            <i class="fa-solid fa-hand-holding-heart"></i>
            <span>Volunteer Hour Tracking</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Budget Requests -->
      <?php if (can_any(['budget.disburse.admin', 'budget.review.ssc', 'budget.endorse.adviser', 'budget.create.own', 'budget.view.all'])): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/budget.php" class="sidebar-item <?= ($ACTIVE_NAV==='budget')?'active':'' ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span>Budget Requests</span>
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- SECTION 4: RECORDS & TRACKING (Staff & Officers Only) -->
    <?php if ($user_role !== 'student' && can_any(['achievements.view', 'achievements.submit', 'achievements.verify.ssc', 'attendance.view.analytics', 'attendance.track.org', 'attendance.override'])): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Records &amp; Media</div>
        <div class="brand-sub">Validation &amp; Attendance</div>
      </div>

      <!-- Achievements & Awards -->
      <?php if (can_any(['achievements.view', 'achievements.submit', 'achievements.verify.ssc'])): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/achievements.php" class="sidebar-item <?= $ACTIVE_NAV==='achievements'?'active':'' ?>">
            <i class="fa-solid fa-award"></i>
            <span>Club Achievement Records</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Attendance Portal for Admin, Super Admin, Staff, SSC, Club Adviser -->
      <?php if ($user_role !== 'student'): ?>
        <?php
          if ($user_role === 'admin' || $user_role === 'super_admin') {
              $attendance_items = [
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php', 'label' => 'All Attendance', 'id' => 'all'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#sessions', 'label' => 'QR Sessions', 'id' => 'sessions'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_qr_generator.php', 'label' => 'Live QR Sessions', 'id' => 'generator'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#attempts', 'label' => 'Scan Attempts &amp; Audit', 'id' => 'attempts'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_scanner.php', 'label' => 'Scanner Terminal', 'id' => 'scanner'],
              ];
          } elseif ($user_role === 'staff') {
              $attendance_items = [
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php', 'label' => 'Attendance Monitor', 'id' => 'all'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_qr_generator.php', 'label' => 'Live QR Sessions', 'id' => 'generator'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_scanner.php', 'label' => 'Scanner Terminal', 'id' => 'scanner'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#attempts', 'label' => 'Scan Attempts &amp; Logs', 'id' => 'attempts'],
              ];
          } elseif ($user_role === 'ssc') {
              $attendance_items = [
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php', 'label' => 'Attendance Monitor', 'id' => 'monitor'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_qr_generator.php', 'label' => 'Live QR Sessions', 'id' => 'generator'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#attempts', 'label' => 'Scan Attempts', 'id' => 'attempts'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#records', 'label' => 'Verification Records', 'id' => 'records']
              ];
          } else {
              // Club Adviser
              $attendance_items = [
                  ['url' => $APP_ROOT . 'dashboard/tracking_qr_generator.php', 'label' => 'Live Attendance &amp; QR', 'id' => 'generator'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php', 'label' => 'Attendance Records', 'id' => 'attendance_list'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_scanner.php', 'label' => 'Scanner Terminal', 'id' => 'scanner'],
              ];
          }
        ?>
        <div class="nav-group">
          <button class="sidebar-item <?= $ACTIVE_NAV==='attendance'?'active':'' ?> dropdown-trigger" data-target="dropTracking">
            <i class="fa-solid fa-qrcode"></i>
            <span>Attendance Tracker</span>
            <i class="fa-solid fa-chevron-down arrow"></i>
          </button>
          <div class="dropdown-menu" id="dropTracking">
            <?php foreach ($attendance_items as $item): ?>
              <a href="<?= $item['url'] ?>" class="dropdown-item <?= ($ACTIVE_NAV==='attendance' && ($ACTIVE_SUB ?? '') === ($item['id'] ?? '')) ? 'active' : '' ?>"><?= $item['label'] ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- SECTION 5: COMMUNICATION -->
    <?php if (can_any(['announcements.manage.all', 'announcements.create.council', 'announcements.create.org']) || in_array($user_role, ['student', 'club_adviser', 'ssc', 'admin', 'super_admin', 'staff'])): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Inter-club Communication</div>
        <div class="brand-sub">Broadcasts &amp; Messaging</div>
      </div>

      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/announcements.php" class="sidebar-item <?= ($ACTIVE_NAV==='announcements')?'active':'' ?>">
          <i class="fa-solid fa-bullhorn"></i>
          <span>
            <?= can('announcements.manage.all') ? 'Inter-club Communication' : (can('announcements.create.council') ? 'Council Announcements' : 'Inter-club Announcements') ?>
          </span>
        </a>
      </div>
    <?php endif; ?>

    <!-- SECTION 6: ANALYTICS & REPORTS -->
    <?php if (can_any(['reports.view.system', 'reports.view.institutional', 'reports.view.org'])): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Analytics</div>
        <div class="brand-sub">Intelligence &amp; Data</div>
      </div>

      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/reports.php" class="sidebar-item <?= ($ACTIVE_NAV==='reports')?'active':'' ?>">
          <i class="fa-solid fa-chart-column"></i>
          <span>
            <?= can('reports.view.system') ? 'System Reports' : (can('reports.view.institutional') ? 'Reports &amp; Analytics' : 'Organization Reports') ?>
          </span>
        </a>
      </div>
    <?php endif; ?>

    <!-- SECTION 7: SECURITY & AUDIT (Admin & Super Admin only) -->
    <?php if ($user_role === 'admin' || $user_role === 'super_admin'): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title"><?= ($user_role === 'super_admin') ? 'Root Security' : 'Security' ?></div>
        <div class="brand-sub">Logs &amp; Monitoring</div>
      </div>

      <!-- Audit Logs -->
      <?php if (can('audit.manage.full')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_audit.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_audit') ? 'active' : '' ?>">
            <i class="fa-solid fa-list-check"></i>
            <span>Audit Logs</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Security Monitoring -->
      <?php if (can('audit.manage.full')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_security.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_security') ? 'active' : '' ?>">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Security Monitoring</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- System Health -->
      <?php if (can('system.health')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_health.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_health') ? 'active' : '' ?>">
            <i class="fa-solid fa-heart-pulse"></i>
            <span>System Health</span>
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- SECTION 8: CONFIGURATION -->
    <?php if (can('settings.manage')): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Configuration</div>
        <div class="brand-sub">System Setup</div>
      </div>

      <!-- System Settings -->
      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/admin_settings.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_settings') ? 'active' : '' ?>">
          <i class="fa-solid fa-sliders"></i>
          <span>System Settings</span>
        </a>
      </div>

      <!-- Notification Settings -->
      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/admin_notifications.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_notifications') ? 'active' : '' ?>">
          <i class="fa-solid fa-bell"></i>
          <span>Notification Settings</span>
        </a>
      </div>
    <?php endif; ?>

  </div><!-- end sidebar-nav -->
</aside><!-- end sidebar -->

<!-- Notification Panel Overlay & Panel -->
<div class="notif-overlay" id="notifOverlay"></div>
<div class="notif-panel" id="notifPanel">
  <div class="notif-header">
    <span>Notifications</span>
    <div class="notif-header-actions">
      <button class="notif-mark-all" id="notifMarkAll">Mark all read</button>
      <button class="notif-close" id="notifClose">×</button>
    </div>
  </div>
  <div class="notif-list" id="notifList">
    <div style="text-align:center;padding:24px;color:#94a3b8;font-size:0.85rem;">
      <i class="fa-solid fa-bell-slash" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
      Loading notifications...
    </div>
  </div>
</div>

<script>
// ── Live Notification Panel ───────────────────────────────────
(function() {
  const bellBtn   = document.getElementById('bellBtn');
  const badge     = document.getElementById('bellBadge');
  const panel     = document.getElementById('notifPanel');
  const overlay   = document.getElementById('notifOverlay');
  const list      = document.getElementById('notifList');
  const markAllBtn= document.getElementById('notifMarkAll');
  const closeBtn  = document.getElementById('notifClose');

  function timeSince(dateStr) {
    const d = new Date(dateStr.replace(' ','T'));
    const s = Math.floor((Date.now() - d) / 1000);
    if (s < 60)   return 'Just now';
    if (s < 3600) return Math.floor(s/60) + 'm ago';
    if (s < 86400)return Math.floor(s/3600) + 'h ago';
    return Math.floor(s/86400) + 'd ago';
  }

  function loadNotifs() {
    fetch('<?= $APP_ROOT ?>shared/notification_actions.php?action=list&limit=15')
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;
        const count = data.unread || 0;
        badge.textContent = count;
        badge.classList.toggle('has-notif', count > 0);

        if (!data.notifications.length) {
          list.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;font-size:0.85rem;"><i class="fa-solid fa-bell-slash" style="font-size:2rem;display:block;margin-bottom:8px;"></i>No notifications yet.</div>';
          return;
        }

        list.innerHTML = data.notifications.map(n => `
          <div class="notif-item${n.is_read ? '' : ' unread'}" data-id="${n.id}" onclick="markRead(${n.id}, this)">
            <div class="notif-dot"></div>
            <div class="notif-text">
              <div class="notif-title">${n.title}</div>
              <div class="notif-desc">${n.message}</div>
            </div>
            <div class="notif-time">${timeSince(n.created_at)}</div>
          </div>`).join('');
      })
      .catch(() => {});
  }

  function openPanel() { 
    panel.style.display = '';
    overlay.style.display = '';
    panel.classList.add('open', 'active'); 
    overlay.classList.add('active', 'open'); 
    loadNotifs(); 
  }
  function closePanel() { 
    panel.classList.remove('open', 'active'); 
    overlay.classList.remove('active', 'open'); 
  }

  function togglePanel(e) {
    if (e) {
      e.stopPropagation();
      e.preventDefault();
    }
    const isOpen = panel.classList.contains('open') || panel.classList.contains('active');
    if (isOpen) {
      closePanel();
    } else {
      openPanel();
    }
  }

  window.openNotifPanel  = openPanel;
  window.closeNotifPanel = closePanel;
  window.loadLiveNotifs  = loadNotifs;

  bellBtn?.addEventListener('click', togglePanel);
  closeBtn?.addEventListener('click', closePanel);
  overlay?.addEventListener('click', closePanel);

  markAllBtn?.addEventListener('click', () => {
    fetch('<?= $APP_ROOT ?>shared/notification_actions.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'mark_read', id: 0 })
    }).then(() => loadNotifs());
  });

  window.markRead = function(id, el) {
    if (el.classList.contains('unread')) {
      el.classList.remove('unread');
      fetch('<?= $APP_ROOT ?>shared/notification_actions.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'mark_read', id })
      }).then(() => { const c = parseInt(badge.textContent)||0; badge.textContent = Math.max(0,c-1); badge.classList.toggle('has-notif', c-1 > 0); });
    }
  };

  // Initial badge count
  loadNotifs();
})();
</script>

<?php require_once __DIR__ . '/chat_modal.php'; ?>
<?php require_once __DIR__ . '/qr_modal.php'; ?>
<?php require_once __DIR__ . '/session_timeout_modal.php'; ?>
<script src="<?= $APP_ROOT ?>js/global-search.js?v=<?= filemtime(__DIR__ . '/../js/global-search.js') ?>" defer></script>

