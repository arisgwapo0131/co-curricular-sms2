<?php
// ============================================================
//  ACCOUNT.PHP  (dashboard/)
//  Account Settings & Profile Management — Fully Integrated Layout
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_auth();
$first_name     = htmlspecialchars($_SESSION['first_name'] ?? '');
$last_name      = htmlspecialchars($_SESSION['last_name']  ?? '');
$email          = htmlspecialchars($_SESSION['email']      ?? '');
$username       = htmlspecialchars($_SESSION['username']   ?? '');
$sess_role      = $_SESSION['role'] ?? 'student';
$sess_initial   = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$user_id        = (int)$_SESSION['user_id'];

// Fetch latest user details from DB
$u_stmt = $conn->prepare("SELECT first_name, last_name, email, username, role, profile_pic FROM users WHERE id = ? LIMIT 1");
$u_stmt->bind_param('i', $user_id);
$u_stmt->execute();
$current_user = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

if ($current_user) {
    $first_name   = htmlspecialchars($current_user['first_name'] ?? '');
    $last_name    = htmlspecialchars($current_user['last_name'] ?? '');
    $email        = htmlspecialchars($current_user['email'] ?? '');
    $username     = htmlspecialchars($current_user['username'] ?? '');
    $sess_role    = $current_user['role'] ?? 'student';
    $profile_pic  = $current_user['profile_pic'] ?? null;
    $_SESSION['first_name']  = $current_user['first_name'];
    $_SESSION['last_name']   = $current_user['last_name'];
    $_SESSION['email']       = $current_user['email'];
    $_SESSION['username']    = $current_user['username'];
    $_SESSION['role']        = $current_user['role'];
    $_SESSION['profile_pic'] = $profile_pic;
} else {
    $first_name   = htmlspecialchars($_SESSION['first_name'] ?? '');
    $last_name    = htmlspecialchars($_SESSION['last_name']  ?? '');
    $email        = htmlspecialchars($_SESSION['email']      ?? '');
    $username     = htmlspecialchars($_SESSION['username']   ?? '');
    $sess_role    = $_SESSION['role'] ?? 'student';
    $profile_pic  = $_SESSION['profile_pic'] ?? null;
}
$sess_initial   = strtoupper(substr($first_name ?: 'U', 0, 1));

// Role Titles map
$role_labels = [
    'student'         => 'General Student',
    'club_adviser'    => 'Organization Adviser (Faculty Member)',
    'ssc'             => 'Supreme Student Council (SSC)',
    'admin'           => 'System Administrator',
    'super_admin'     => 'Root Super Administrator',
    'staff'           => 'Office of Student Affairs (Staff)'
];
$role = $role_labels[$sess_role] ?? 'User';

$adv_club = null;
if ($sess_role === 'club_adviser') {
    $stmt = $conn->prepare("SELECT c.id, c.name, c.code FROM clubs c WHERE (c.adviser_user_id = ? OR c.id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active')) AND c.deleted_at IS NULL ORDER BY c.name ASC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $user_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $adv_club = $res->fetch_assoc();
        }
        $stmt->close();
    }
}

$std_info = null;
if ($sess_role === 'student') {
    $st_stmt = $conn->prepare("SELECT student_number, course, year_level, section FROM students WHERE user_id = ? OR (first_name = ? AND last_name = ?) LIMIT 1");
    if ($st_stmt) {
        $st_stmt->bind_param('iss', $user_id, $_SESSION['first_name'], $_SESSION['last_name']);
        $st_stmt->execute();
        $std_info = $st_stmt->get_result()->fetch_assoc();
        $st_stmt->close();
    }
}

// Connection remains open for sidebar and qr_modal usage
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Account Settings – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/account.css?v=<?= filemtime(__DIR__ . '/../css/account.css') ?>"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'account';
require_once __DIR__ . '/../shared/sidebar.php';
?>

<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
      <i class="fa-solid fa-bars"></i>
    </button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap" id="topbarSearchWrap">
        <i class="fa-solid fa-magnifying-glass search-icon"></i>
        <input type="text" placeholder="Search modules, events, clubs..." autocomplete="off" />
        <button type="button" class="search-clear-btn" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <button class="topbar-chat-btn" id="chatFabBtn" title="Inter-Club Messenger" type="button"><i class="fa-solid fa-comment-dots"></i><span class="chat-unread-badge" id="chatUnreadBadge" style="display:none;"></span></button>
      <?php if (($sess_role ?? $_SESSION['role'] ?? '') === 'student'): ?>
      <button class="topbar-qr-btn" id="qrFabBtn" title="View Personal Attendance QR Code" type="button">
        <i class="fa-solid fa-qrcode"></i>
      </button>
      <?php endif; ?>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?php if (!empty($profile_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $profile_pic)): ?>
          <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Profile" id="topbarAvatarImg"/>
        <?php else: ?>
          <span id="topbarAvatarInitial"><?= $sess_initial ?></span>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-user-gear"></i>
        Account Settings &amp; Profile
      </h2>
    </div>

    <div class="content-body">

      <div class="account-grid">

        <!-- Left Column: User Profile Overview Card -->
        <div class="profile-hero-card">
          
          <div class="avatar-upload-wrapper">
            <div class="avatar-large-container" id="avatarContainer" title="Click or drop an image to change profile photo">
              <div class="avatar-large" id="avatarCircle">
                <?php if (!empty($profile_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $profile_pic)): ?>
                  <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Profile Picture" class="avatar-large-img" id="avatarImage"/>
                <?php else: ?>
                  <span id="avatarInitialText"><?= $sess_initial ?></span>
                <?php endif; ?>
                <div class="avatar-hover-overlay">
                  <i class="fa-solid fa-camera"></i>
                  <span>Change</span>
                </div>
                <div class="avatar-loading-overlay" id="avatarLoading">
                  <div class="avatar-spinner"></div>
                  <span>Uploading...</span>
                </div>
              </div>
              <div class="avatar-upload-badge" title="Change Photo">
                <i class="fa-solid fa-camera"></i>
              </div>
            </div>

            <!-- Hidden File Input -->
            <input type="file" id="avatarFileInput" accept="image/png, image/jpeg, image/jpg, image/webp, image/gif" style="display:none;"/>

            <div class="avatar-btn-group">
              <button type="button" class="btn-avatar-action danger" id="btnRemoveAvatar" style="<?= (!empty($profile_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $profile_pic)) ? '' : 'display:none;' ?>">
                <i class="fa-solid fa-trash-can"></i> Remove
              </button>
            </div>
          </div>

          <h3 class="profile-user-name" id="displayName"><?= $first_name . ' ' . $last_name ?></h3>
          <span class="profile-user-role">
            <?php if ($sess_role === 'club_adviser' && !empty($adv_club)): ?>
              Adviser &bull; <?= htmlspecialchars($adv_club['code']) ?>
            <?php elseif ($sess_role === 'student' && !empty($std_info)): ?>
              Student &bull; <?= htmlspecialchars($std_info['student_number'] ?? 'N/A') ?>
            <?php else: ?>
              <?= $role ?>
            <?php endif; ?>
          </span>

          <div class="profile-details-list">
            <?php if ($sess_role === 'club_adviser' && !empty($adv_club)): ?>
            <div class="profile-detail-item">
              <i class="fa-solid fa-sitemap"></i>
              <span>Advising: <strong><?= htmlspecialchars($adv_club['name']) ?></strong></span>
            </div>
            <?php elseif ($sess_role === 'student' && !empty($std_info)): ?>
            <div class="profile-detail-item">
              <i class="fa-solid fa-graduation-cap"></i>
              <span><strong><?= htmlspecialchars($std_info['student_number'] ?? 'N/A') ?></strong> &bull; <?= htmlspecialchars($std_info['course'] ?? '') ?></span>
            </div>
            <?php if (!empty($std_info['year_level']) || !empty($std_info['section'])): ?>
            <div class="profile-detail-item">
              <i class="fa-solid fa-chalkboard-user"></i>
              <span><?= htmlspecialchars($std_info['year_level'] ?? '') ?><?= (!empty($std_info['year_level']) && !empty($std_info['section'])) ? ' &bull; ' : '' ?><?= htmlspecialchars($std_info['section'] ?? '') ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="profile-detail-item">
              <i class="fa-solid fa-envelope"></i>
              <span><?= $email ?></span>
            </div>
            <div class="profile-detail-item">
              <i class="fa-solid fa-id-badge"></i>
              <span>@<?= $username ?></span>
            </div>
          </div>
        </div>
        <div class="profile-forms-column">

          <!-- Profile Information Card -->
          <div class="settings-card">
            <div class="settings-card-header">
              <i class="fa-solid fa-user-pen" style="color:#2563eb;"></i>
              <h2>Personal Profile Information</h2>
            </div>
            <div class="settings-card-body">
              <form id="profileForm" onsubmit="return false;">
                <div class="form-grid">
                  <div class="form-field">
                    <label>First Name <span>*</span></label>
                    <input type="text" name="first_name" value="<?= $first_name ?>" placeholder="First name" required/>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field">
                    <label>Last Name <span>*</span></label>
                    <input type="text" name="last_name" value="<?= $last_name ?>" placeholder="Last name" required/>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field full">
                    <label>Email Address <span>*</span></label>
                    <input type="email" name="email" value="<?= $email ?>" placeholder="email@example.com" required/>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field full">
                    <label>Username <span>*</span></label>
                    <input type="text" name="username" value="<?= $username ?>" placeholder="Username" required/>
                    <span class="field-error"></span>
                  </div>
                </div>
              </form>
            </div>
            <div class="settings-card-footer">
              <button class="btn-save" id="btnSaveProfile">
                <i class="fa-solid fa-floppy-disk"></i> Save Profile Changes
              </button>
            </div>
          </div>

          <!-- Change Password Card -->
          <div class="settings-card">
            <div class="settings-card-header">
              <i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i>
              <h2>Security &amp; Password</h2>
            </div>
            <div class="settings-card-body">
              <form id="passwordForm" onsubmit="return false;">
                <div class="form-grid">
                  <div class="form-field full">
                    <label>Current Password <span>*</span></label>
                    <div class="password-wrap">
                      <input type="password" name="current_password" id="fCurrentPw" placeholder="Enter current password" autocomplete="current-password"/>
                      <button type="button" class="toggle-pw" data-target="fCurrentPw" title="Toggle visibility">
                        <i class="fa-solid fa-eye"></i>
                      </button>
                    </div>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field">
                    <label>New Password <span>*</span></label>
                    <div class="password-wrap">
                      <input type="password" name="new_password" id="fNewPw" placeholder="New password (min 6)" autocomplete="new-password"/>
                      <button type="button" class="toggle-pw" data-target="fNewPw" title="Toggle visibility">
                        <i class="fa-solid fa-eye"></i>
                      </button>
                    </div>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field">
                    <label>Confirm Password <span>*</span></label>
                    <div class="password-wrap">
                      <input type="password" name="confirm_password" id="fConfirmPw" placeholder="Repeat new password" autocomplete="new-password"/>
                      <button type="button" class="toggle-pw" data-target="fConfirmPw" title="Toggle visibility">
                        <i class="fa-solid fa-eye"></i>
                      </button>
                    </div>
                    <span class="field-error"></span>
                  </div>
                </div>
              </form>
            </div>
            <div class="settings-card-footer">
              <button class="btn-save" id="btnSavePassword">
                <i class="fa-solid fa-key"></i> Update Password
              </button>
            </div>
          </div>

          <!-- Danger Zone Card -->
          <div class="settings-card" style="border-color:#fecaca;">
            <div class="settings-card-header" style="background:#fff5f5; border-bottom-color:#fee2e2;">
              <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i>
              <h2 style="color:#dc2626;">Account Session &amp; Actions</h2>
            </div>
            <div class="danger-zone">
              <div class="danger-info">
                <h3>Sign Out</h3>
                <p>End your current session and return to the sign-in portal.</p>
              </div>
              <button class="btn-danger" id="btnSignOut">
                <i class="fa-solid fa-right-from-bracket"></i> Sign Out
              </button>
            </div>
            <div class="danger-zone" style="border-top:1px solid #fee2e2;">
              <div class="danger-info">
                <h3>Delete Account</h3>
                <p>Permanently remove your account and all session tokens. This cannot be undone.</p>
              </div>
              <button class="btn-danger" id="btnDeleteAccount">
                <i class="fa-solid fa-trash-can"></i> Delete Account
              </button>
            </div>
          </div>

        </div><!-- end profile-forms-column -->

      </div><!-- end account-grid -->

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- end main -->

<script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
<script>
const API = '../shared/auth_actions.php';
const SIGNIN = '../auth/signin.php';


function validateFields(form, fields) {
    let valid = true;
    fields.forEach(({ name, label }) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (!input) return;
        const field = input.closest('.form-field');
        const err   = field?.querySelector('.field-error');
        if (!input.value.trim()) {
            input.classList.add('input-error');
            field?.classList.add('has-error');
            if (err) err.textContent = `${label} is required.`;
            valid = false;
        } else { clearErr(input); }
    });
    return valid;
}

function clearErr(input) {
    input.classList.remove('input-error');
    input.closest('.form-field')?.classList.remove('has-error');
    const e = input.closest('.form-field')?.querySelector('.field-error');
    if (e) e.textContent = '';
}

document.querySelectorAll('.form-field input').forEach(i => {
    i.addEventListener('input', () => { if (i.value.trim()) clearErr(i); });
});

document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
        const inp = document.getElementById(btn.dataset.target);
        inp.type  = inp.type === 'password' ? 'text' : 'password';
        btn.querySelector('i').className = inp.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
    });
});

async function postAction(fd) {
    return fetch(API, { method: 'POST', body: fd }).then(r => r.json());
}

document.getElementById('btnSaveProfile')?.addEventListener('click', async () => {
    const form = document.getElementById('profileForm');
    if (!validateFields(form, [
        { name: 'first_name', label: 'First Name' },
        { name: 'last_name',  label: 'Last Name'  },
        { name: 'email',      label: 'Email'      },
        { name: 'username',   label: 'Username'   },
    ])) return;
    const fd = new FormData(form);
    fd.set('action', 'update_profile');
    try {
        const data = await postAction(fd);
        if (data.success) {
            const first = form.querySelector('[name="first_name"]').value.trim();
            const last  = form.querySelector('[name="last_name"]').value.trim();
            document.getElementById('displayName').textContent  = `${first} ${last}`;
            const initialText = document.getElementById('avatarInitialText');
            if (initialText) {
                initialText.textContent = first.charAt(0).toUpperCase();
            }
            const topbarInitial = document.getElementById('topbarAvatarInitial');
            if (topbarInitial) {
                topbarInitial.textContent = first.charAt(0).toUpperCase();
            }
            showToast(data.message, 'success');
        } else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});

// ── Profile Avatar Upload & Management ─────────────────────────────
const avatarInput     = document.getElementById('avatarFileInput');
const avatarContainer = document.getElementById('avatarContainer');
const btnRemoveAvatar = document.getElementById('btnRemoveAvatar');
const avatarCircle    = document.getElementById('avatarCircle');
const avatarLoading   = document.getElementById('avatarLoading');
const topbarAvatarBtn = document.getElementById('avatarBtn');

// Open file selector
function triggerAvatarSelect() {
    avatarInput?.click();
}

avatarContainer?.addEventListener('click', (e) => {
    e.preventDefault();
    triggerAvatarSelect();
});

// Drag and drop support
['dragenter', 'dragover'].forEach(eventName => {
    avatarContainer?.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        avatarContainer.classList.add('drag-active');
    }, false);
});

['dragleave', 'drop'].forEach(eventName => {
    avatarContainer?.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        avatarContainer.classList.remove('drag-active');
    }, false);
});

avatarContainer?.addEventListener('drop', (e) => {
    const dt = e.dataTransfer;
    const files = dt?.files;
    if (files && files.length > 0) {
        handleAvatarUpload(files[0]);
    }
});

avatarInput?.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (file) {
        handleAvatarUpload(file);
    }
    // Reset input so same file can be reselected if needed
    avatarInput.value = '';
});

async function handleAvatarUpload(file) {
    // Validate file type
    const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    if (!validTypes.includes(file.type)) {
        showToast('Please select a valid image file (JPG, PNG, WEBP, or GIF).', 'error');
        return;
    }

    // Validate size (5MB)
    if (file.size > 5 * 1024 * 1024) {
        showToast('Image file size must be less than 5MB.', 'error');
        return;
    }

    // Show loading spinner overlay
    avatarLoading?.classList.add('active');

    const fd = new FormData();
    fd.append('action', 'upload_avatar');
    fd.append('avatar', file);

    try {
        const res = await fetch(API, {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.success) {
            const cacheBustUrl = `${data.avatar_url}?t=${Date.now()}`;
            
            // Update hero avatar
            let img = document.getElementById('avatarImage');
            if (!img) {
                // If previously initial letter was rendered
                const initialText = document.getElementById('avatarInitialText');
                if (initialText) initialText.remove();
                img = document.createElement('img');
                img.id = 'avatarImage';
                img.className = 'avatar-large-img';
                img.alt = 'Profile Picture';
                avatarCircle.insertBefore(img, avatarCircle.firstChild);
            }
            img.src = cacheBustUrl;

            // Update topbar avatar
            if (topbarAvatarBtn) {
                topbarAvatarBtn.innerHTML = `<img src="${cacheBustUrl}" alt="Profile" id="topbarAvatarImg"/>`;
            }

            // Show remove button
            if (btnRemoveAvatar) {
                btnRemoveAvatar.style.display = 'inline-flex';
            }

            showToast(data.message || 'Profile picture updated!', 'success');
        } else {
            showToast(data.message || 'Upload failed.', 'error');
        }
    } catch (err) {
        showToast('An error occurred during upload. Please try again.', 'error');
    } finally {
        avatarLoading?.classList.remove('active');
    }
}

// Remove Avatar
btnRemoveAvatar?.addEventListener('click', async (e) => {
    e.preventDefault();
    const confirmed = await window.showConfirmModal(
        'Remove Profile Picture?',
        'Do you want to remove your profile picture?',
        { type: 'warning', confirmText: 'Yes, Remove Picture' }
    );
    if (!confirmed) return;

    avatarLoading?.classList.add('active');
    const fd = new FormData();
    fd.set('action', 'remove_avatar');

    try {
        const data = await postAction(fd);
        if (data.success) {
            // Get user's first letter initial
            const first = document.querySelector('[name="first_name"]')?.value.trim() || 'U';
            const initial = first.charAt(0).toUpperCase();

            // Reset hero avatar circle
            const img = document.getElementById('avatarImage');
            if (img) img.remove();

            let initialSpan = document.getElementById('avatarInitialText');
            if (!initialSpan) {
                initialSpan = document.createElement('span');
                initialSpan.id = 'avatarInitialText';
                avatarCircle.insertBefore(initialSpan, avatarCircle.firstChild);
            }
            initialSpan.textContent = initial;

            // Reset topbar avatar
            if (topbarAvatarBtn) {
                topbarAvatarBtn.innerHTML = `<span id="topbarAvatarInitial">${initial}</span>`;
            }

            // Hide remove button
            if (btnRemoveAvatar) {
                btnRemoveAvatar.style.display = 'none';
            }

            showToast(data.message || 'Profile picture removed.', 'success');
        } else {
            showToast(data.message || 'Failed to remove picture.', 'error');
        }
    } catch (err) {
        showToast('Failed to remove profile picture.', 'error');
    } finally {
        avatarLoading?.classList.remove('active');
    }
});

document.getElementById('btnSavePassword')?.addEventListener('click', async () => {
    const form = document.getElementById('passwordForm');
    if (!validateFields(form, [
        { name: 'current_password', label: 'Current Password' },
        { name: 'new_password',     label: 'New Password'     },
        { name: 'confirm_password', label: 'Confirm Password' },
    ])) return;
    const newPw = form.querySelector('[name="new_password"]').value;
    const conf  = form.querySelector('[name="confirm_password"]').value;
    if (newPw !== conf) {
        const inp = form.querySelector('[name="confirm_password"]');
        inp.classList.add('input-error');
        inp.closest('.form-field')?.classList.add('has-error');
        const e = inp.closest('.form-field')?.querySelector('.field-error');
        if (e) e.textContent = 'Passwords do not match.';
        return;
    }
    const fd = new FormData(form);
    fd.set('action', 'change_password');
    try {
        const data = await postAction(fd);
        if (data.success) { form.reset(); showToast(data.message, 'success'); }
        else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});

document.getElementById('btnSignOut')?.addEventListener('click', async () => {
    const confirmed = await window.showConfirmModal(
        'Sign Out?',
        'Do you want to sign out of your account session?',
        { type: 'info', confirmText: 'Yes, Sign Out' }
    );
    if (!confirmed) return;
    const fd = new FormData(); fd.set('action', 'logout');
    await postAction(fd).catch(() => {});
    window.location.href = SIGNIN;
});

document.getElementById('btnDeleteAccount')?.addEventListener('click', async () => {
    const confirmed = await window.showConfirmModal(
        'Permanently Delete Account?',
        'Do you want to permanently delete your account? This action cannot be undone and will revoke all access.',
        { type: 'error', danger: true, confirmText: 'Yes, Delete Account' }
    );
    if (!confirmed) return;
    const fd = new FormData(); fd.set('action', 'delete_account');
    try {
        const data = await postAction(fd);
        if (data.success) {
            showToast('Account deleted. Redirecting…', 'error');
            setTimeout(() => { window.location.href = SIGNIN; }, 2000);
        } else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});
</script>



</body>
</html>
