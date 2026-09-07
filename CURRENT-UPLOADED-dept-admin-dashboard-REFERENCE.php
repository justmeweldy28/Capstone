<?php
require_once __DIR__ . '/includes/dept-admin-dashboard/auth.php';
require_once __DIR__ . '/includes/dept-admin-dashboard/helpers.php';
require_once __DIR__ . '/includes/dept-admin-dashboard/post-handler.php';
require_once __DIR__ . '/includes/dept-admin-dashboard/data-loader.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc($collegeCode) ?> – Dept Admin Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link
    rel="stylesheet"
    href="assets/css/dept-admin-dashboard.css">
<link rel="stylesheet" href="assets/css/dashboard-polish.css?v=<?= esc((string)(@filemtime(__DIR__ . '/assets/css/dashboard-polish.css') ?: '1')) ?>">
<?php if ($tab === 'dashboard'): ?>
<link
    rel="stylesheet"
    href="assets/css/dept-dashboard-ui-v3.css?v=<?= esc((string)(@filemtime(__DIR__ . '/assets/css/dept-dashboard-ui-v3.css') ?: '1')) ?>">
<?php endif; ?>
<?php if (in_array($tab, array('surveys','analytics','email'), true)): ?>
<link
    rel="stylesheet"
    href="assets/css/dept-admin-survey-version.css?v=<?= esc((string)(@filemtime(__DIR__ . '/assets/css/dept-admin-survey-version.css') ?: '1')) ?>">
<?php endif; ?>
<?php if ($tab === 'email'): ?>
<link rel="stylesheet" href="assets/css/dept-email-reminders.css?v=<?= esc((string)(@filemtime(__DIR__ . '/assets/css/dept-email-reminders.css') ?: '1')) ?>">
<?php endif; ?>
<?php if ($tab === 'analytics'): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="assets/css/dept-workplace-map.css?v=<?= esc((string)(@filemtime(__DIR__ . '/assets/css/dept-workplace-map.css') ?: '1')) ?>">
<link
    rel="stylesheet"
    href="assets/css/dept-ai-analytics.css?v=<?= esc((string)(@filemtime(__DIR__ . '/assets/css/dept-ai-analytics.css') ?: '1')) ?>">
<?php endif; ?>

<style>
/* =========================================================
   TRACEGRAD — TOP-RIGHT ADMIN ACCOUNT MENU
   ========================================================= */
.dash-account-wrap {
  position: relative;
  margin-left: auto;
}

.dash-account-button {
  appearance: none;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 8px 6px 10px;
  border: 1px solid transparent;
  border-radius: 11px;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    background .16s ease,
    border-color .16s ease,
    box-shadow .16s ease;
}

.dash-account-button:hover,
.dash-account-button[aria-expanded="true"] {
  border-color: var(--border, #e2e7ec);
  background: #fff;
  box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
}

.dash-account-button .dash-topbar-user {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0;
  margin: 0;
}

.dash-account-caret {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #8793a3;
  font-size: .85rem;
  transition: transform .16s ease;
}

.dash-account-button[aria-expanded="true"] .dash-account-caret {
  transform: rotate(180deg);
}

.dash-account-menu {
  position: absolute;
  z-index: 1200;
  top: calc(100% + 8px);
  right: 0;
  width: 255px;
  overflow: hidden;
  visibility: hidden;
  opacity: 0;
  transform: translateY(-6px);
  pointer-events: none;
  border: 1px solid var(--border, #e2e7ec);
  border-radius: 13px;
  background: #fff;
  box-shadow: 0 16px 38px rgba(15, 23, 42, .15);
  transition:
    opacity .15s ease,
    transform .15s ease,
    visibility .15s ease;
}

.dash-account-menu.show {
  visibility: visible;
  opacity: 1;
  transform: translateY(0);
  pointer-events: auto;
}

.dash-account-menu-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 13px 14px;
  border-bottom: 1px solid var(--border, #e2e7ec);
  background: #f9fafb;
}

.dash-account-menu-avatar {
  width: 38px;
  height: 38px;
  flex: 0 0 38px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: var(--primary, #0a1929);
  color: #fff;
  font-size: .83rem;
  font-weight: 800;
}

.dash-account-menu-head > div {
  min-width: 0;
}

.dash-account-menu-head strong,
.dash-account-menu-head span {
  display: block;
}

.dash-account-menu-head strong {
  overflow: hidden;
  color: var(--primary, #0a1929);
  font-size: .68rem;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.dash-account-menu-head span {
  margin-top: 2px;
  color: var(--text-muted, #758194);
  font-size: .55rem;
}

.dash-account-menu-links {
  padding: 6px;
}

.dash-account-menu-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 9px 10px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #4d5c70;
  font: inherit;
  font-size: .65rem;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
  transition: background .14s ease, color .14s ease;
}

.dash-account-menu-item i {
  width: 18px;
  flex: 0 0 18px;
  text-align: center;
  font-size: 1rem;
}

.dash-account-menu-item:hover {
  background: #f4f6f8;
  color: var(--primary, #0a1929);
}

.dash-account-menu-separator {
  height: 1px;
  margin: 5px 3px;
  background: var(--border, #e2e7ec);
}

.dash-account-menu-item.signout {
  color: #b33b35;
}

.dash-account-menu-item.signout:hover {
  background: #fff1f0;
  color: #a12f2a;
}

@media (max-width: 640px) {
  .dash-account-button {
    padding: 5px;
  }

  .dash-account-button .dash-topbar-user > div:first-child {
    display: none;
  }

  .dash-account-caret {
    display: none;
  }

  .dash-account-menu {
    width: min(255px, calc(100vw - 24px));
  }
}


/* =========================================================
   TRACEGRAD — EDIT MY PROFILE MODAL
   ========================================================= */
.profile-edit-modal {
  width: 100%;
}

.profile-edit-head {
  display: flex;
  align-items: center;
  gap: 13px;
  margin: -2px -2px 17px;
  padding: 2px 2px 15px;
  border-bottom: 1px solid var(--border, #e2e7ec);
}

.profile-edit-avatar {
  width: 52px;
  height: 52px;
  flex: 0 0 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 13px;
  background: var(--primary, #0a1929);
  color: #fff;
  font-size: 1.05rem;
  font-weight: 800;
  box-shadow: 0 5px 15px rgba(15, 23, 42, .12);
}

.profile-edit-head > div {
  min-width: 0;
}

.profile-edit-head .profile-kicker {
  display: block;
  margin-bottom: 2px;
  color: var(--gold, #c99a2b);
  font-size: .55rem;
  font-weight: 800;
  letter-spacing: .07em;
  text-transform: uppercase;
}

.profile-edit-head h2 {
  margin: 0;
  color: var(--primary, #0a1929);
  font-size: 1.02rem;
  line-height: 1.25;
}

.profile-edit-head p {
  margin: 3px 0 0;
  color: var(--text-muted, #758194);
  font-size: .62rem;
  line-height: 1.4;
}

.profile-edit-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.profile-edit-grid .profile-field-wide {
  grid-column: 1 / -1;
}

.profile-edit-grid .dash-field label {
  margin-bottom: 6px;
  font-size: .6rem;
  font-weight: 800;
  color: #526075;
}

.profile-edit-grid .dash-field input {
  min-height: 42px;
  border-radius: 9px;
}

.profile-edit-grid .dash-field input:disabled {
  border-color: #e2e7ec;
  background: #f5f7f9;
  color: #758194;
  cursor: not-allowed;
}

.profile-protected-note {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-top: 6px;
  padding: 4px 7px;
  border-radius: 7px;
  background: #f1f4f7;
  color: #718095;
  font-size: .52rem;
  font-weight: 700;
}

.profile-edit-info {
  display: flex;
  align-items: flex-start;
  gap: 7px;
  margin-top: 14px;
  padding: 9px 10px;
  border: 1px solid #e4e8ed;
  border-radius: 9px;
  background: #f8fafb;
  color: #768395;
  font-size: .55rem;
  line-height: 1.45;
}

.profile-edit-info i {
  flex: 0 0 auto;
  margin-top: 1px;
  color: var(--gold, #c99a2b);
  font-size: .86rem;
}

.profile-edit-actions {
  display: flex;
  justify-content: flex-end;
  gap: 9px;
  margin-top: 16px;
  padding-top: 13px;
  border-top: 1px solid var(--border, #e2e7ec);
}

@media (max-width: 600px) {
  .profile-edit-grid {
    grid-template-columns: 1fr;
  }

  .profile-edit-grid .profile-field-wide {
    grid-column: auto;
  }

  .profile-edit-actions {
    flex-direction: column-reverse;
  }

  .profile-edit-actions .btn-dash-primary,
  .profile-edit-actions .btn-dash-secondary {
    width: 100%;
    justify-content: center;
  }
}


.dash-avatar img,
.dash-account-menu-avatar img,
.profile-edit-avatar img {
  width:100%;
  height:100%;
  display:block;
  object-fit:cover;
}

.dash-avatar,
.dash-account-menu-avatar,
.profile-edit-avatar {
  overflow:hidden;
}

.profile-photo-editor {
  grid-column:1/-1;
  display:flex;
  align-items:center;
  gap:14px;
  padding:12px;
  border:1px solid #e4e8ed;
  border-radius:11px;
  background:#f9fafb;
}

.profile-photo-editor-preview {
  width:76px;
  height:76px;
  flex:0 0 76px;
  display:flex;
  align-items:center;
  justify-content:center;
  overflow:hidden;
  border:1px solid #dfe5eb;
  border-radius:14px;
  background:#fff;
  color:var(--primary, #0a1929);
  font-size:1.1rem;
  font-weight:800;
}

.profile-photo-editor-preview img {
  width:100%;
  height:100%;
  object-fit:cover;
}

.profile-photo-editor-copy {
  min-width:0;
  flex:1;
}

.profile-photo-editor-copy strong,
.profile-photo-editor-copy small {
  display:block;
}

.profile-photo-editor-copy strong {
  margin-bottom:5px;
  color:#4f5d70;
  font-size:.63rem;
}

.profile-photo-editor-copy input[type="file"] {
  width:100%;
  margin-bottom:4px;
  font-size:.58rem;
}

.profile-photo-editor-copy small {
  color:#8793a3;
  font-size:.52rem;
  line-height:1.4;
}

</style>

</head>
<body>
<?php
$adminProfilePhoto = isset($adminProfile['profile_photo'])
    ? trim((string)$adminProfile['profile_photo'])
    : '';

$adminProfilePhotoUrl = $adminProfilePhoto !== ''
    ? 'assets/admin-profiles/' . rawurlencode($adminProfilePhoto)
    : '';
?>
<div class="dash-shell">

  <!-- ═══ SIDEBAR ═══ -->
  <aside class="dash-sidebar" id="dash-sidebar">
    <div class="dash-brand">
      <div class="lnav-mark dept-logo-mark"><?php if ($collegeLogo !== ''): ?><img src="assets/college-logos/<?= esc($collegeLogo) ?>" alt="<?= esc($collegeCode) ?> logo"><?php else: ?><?= esc(substr($collegeCode,0,3)) ?><?php endif; ?></div>
      <div class="lnav-brand">
        <div class="b1">TRACEGRAD</div>
        <div class="b2"><?= esc($collegeCode) ?> Admin</div>
      </div>
    </div>
    <nav class="dash-nav">
      <div class="dash-nav-label">My College</div>
      <button class="dash-link <?= $tab==='dashboard'?'on':'' ?>" onclick="location.href='?tab=dashboard'"><i class="ti ti-layout-dashboard"></i> Dashboard</button>
      <button class="dash-link <?= $tab==='roster'?'on':'' ?>" onclick="location.href='?tab=roster'"><i class="ti ti-address-book"></i> Alumni Roster</button>
      <button class="dash-link <?= $tab==='verification'?'on':'' ?>" onclick="location.href='?tab=verification'"><i class="ti ti-user-check"></i> Alumni Verification</button>
      <button class="dash-link <?= $tab==='surveys'?'on':'' ?>" onclick="location.href='?tab=surveys'"><i class="ti ti-clipboard-text"></i> Survey Responses</button>
      
      <div class="dash-nav-label">Analytics</div>
      <button class="dash-link <?= $tab==='analytics'?'on':'' ?>" onclick="location.href='?tab=analytics'"><i class="ti ti-chart-pie-2"></i> Program Analytics</button>
      
      <div class="dash-nav-label">Reports</div>
      <button class="dash-link <?= $tab==='reports'?'on':'' ?>" onclick="location.href='?tab=reports'"><i class="ti ti-file-analytics"></i> Generate Report</button>
      
      <div class="dash-nav-label">Communication</div>
      <button class="dash-link <?= $tab==='email'?'on':'' ?>" onclick="location.href='?tab=email'"><i class="ti ti-mail"></i> Email Reminders</button>
      
      <div class="dash-nav-label">Content</div>
      <button class="dash-link <?= $tab==='gallery'?'on':'' ?>" onclick="location.href='?tab=gallery'"><i class="ti ti-photo"></i> Gallery</button>

      <div class="dash-nav-label">Account</div>
      <button class="dash-link <?= $tab==='settings'?'on':'' ?>" onclick="location.href='?tab=settings'"><i class="ti ti-settings"></i> My Settings</button>
    </nav>
  </aside>
  <div class="sidebar-backdrop" id="sidebar-backdrop" onclick="toggleSidebar(false)"></div>

  <!-- ═══ MAIN ═══ -->
  <div class="dash-main">

    <header class="dash-topbar">
      <button class="dash-burger" onclick="toggleSidebar()"><i class="ti ti-menu-2"></i></button>
      <?php
      $deptTabTitles = [
        'dashboard'    => 'Dashboard',
        'roster'       => 'Alumni Roster',
        'verification' => 'Alumni Verification & Follow-Up',
        'surveys'      => 'Survey Responses',
        'analytics'    => 'Program Analytics',
        'reports'      => 'Generate Report',
        'email'        => 'Email Reminders',
        'gallery'      => 'Gallery',
        'settings'     => 'My Settings'
      ];
      ?>
      <div class="dash-topbar-title"><?= esc(isset($deptTabTitles[$tab]) ? $deptTabTitles[$tab] : ucfirst($tab)) ?></div>
      <div class="dash-account-wrap" id="dash-account-wrap">

        <button
          type="button"
          class="dash-account-button"
          id="dash-account-button"
          aria-haspopup="true"
          aria-expanded="false"
          aria-controls="dash-account-menu"
          onclick="toggleAccountMenu(event)"
        >
          <div class="dash-topbar-user">
            <div>
              <div class="dash-user-name"><?= esc($adminName) ?></div>
              <div class="dash-user-role">Dept Admin · <?= esc($collegeCode) ?></div>
            </div>

            <div class="dash-avatar">
              <?php if ($adminProfilePhotoUrl !== ''): ?>
                <img src="<?= esc($adminProfilePhotoUrl) ?>" alt="<?= esc($adminName) ?> profile photo">
              <?php else: ?>
                <?= esc(strtoupper(substr($adminName,0,1))) ?>
              <?php endif; ?>
            </div>
          </div>

          <span class="dash-account-caret" aria-hidden="true">
            <i class="ti ti-chevron-down"></i>
          </span>
        </button>


        <div
          class="dash-account-menu"
          id="dash-account-menu"
          role="menu"
          aria-labelledby="dash-account-button"
        >

          <div class="dash-account-menu-head">
            <div class="dash-account-menu-avatar">
              <?php if ($adminProfilePhotoUrl !== ''): ?>
                <img src="<?= esc($adminProfilePhotoUrl) ?>" alt="<?= esc($adminName) ?> profile photo">
              <?php else: ?>
                <?= esc(strtoupper(substr($adminName,0,1))) ?>
              <?php endif; ?>
            </div>

            <div>
              <strong><?= esc($adminName) ?></strong>
              <span>Department Admin · <?= esc($collegeCode) ?></span>
            </div>
          </div>


          <div class="dash-account-menu-links">

            <button
              type="button"
              class="dash-account-menu-item"
              role="menuitem"
              onclick="setAccountMenu(false); openModal('form-edit-profile')"
            >
              <i class="ti ti-user-edit"></i>
              <span>My Profile</span>
            </button>

            <a
              href="?tab=settings"
              class="dash-account-menu-item"
              role="menuitem"
            >
              <i class="ti ti-settings"></i>
              <span>My Settings</span>
            </a>

            <div class="dash-account-menu-separator"></div>

            <a
              href="admin-logout.php"
              class="dash-account-menu-item signout"
              role="menuitem"
            >
              <i class="ti ti-logout"></i>
              <span>Sign Out</span>
            </a>

          </div>

        </div>

      </div>
    </header>

    <div class="dash-content">

      <?php if ($flash): ?>
        <div class="dash-flash <?= $flash[0]==='ok' ? '' : 'error' ?>" id="dash-flash-msg">
          <i class="ti <?= $flash[0]==='ok' ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i>
          <span><?= $flash[1] ?></span>
          <button type="button" class="flash-close" onclick="dismissFlash()" aria-label="Dismiss"><i class="ti ti-x"></i></button>
        </div>
      <?php endif; ?>

      <!-- ══════════════ DASHBOARD ══════════════ -->
      <?php require __DIR__ . '/includes/dept-admin-dashboard/views/dashboard.php'; ?>

      <?php require __DIR__ . '/includes/dept-admin-dashboard/views/roster.php'; ?>

      <?php
      /* Alumni Verification is visible because its core workflow is functional. */
      require __DIR__ . '/includes/dept-admin-dashboard/views/verification.php';
      ?>

      <?php require __DIR__ . '/includes/dept-admin-dashboard/views/surveys.php'; ?>

      <?php require __DIR__ . '/includes/dept-admin-dashboard/views/analytics.php'; ?>

      <?php require __DIR__ . '/includes/dept-admin-dashboard/views/reports.php'; ?>

      <?php require __DIR__ . '/includes/dept-admin-dashboard/views/email.php'; ?>

      <?php require __DIR__ . '/includes/dept-admin-dashboard/views/gallery.php'; ?>

      <?php require __DIR__ . '/includes/dept-admin-dashboard/views/settings.php'; ?>


    </div>
  </div>
</div>

<!-- ══════════════ MODAL OVERLAY ══════════════ -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this) closeModal()">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal()">&times;</button>
    <div id="modalContent"></div>
  </div>
</div>

<!-- ══════════════ HIDDEN FORMS (Modal Content) ══════════════ -->

<?php require __DIR__ . '/includes/dept-admin-dashboard/modals.php'; ?>

<!-- ══════════════ MY PROFILE — EDITABLE ACCOUNT MODAL ══════════════ -->
<div id="form-edit-profile" style="display:none;">

  <div class="profile-edit-modal">

    <div class="profile-edit-head">

      <div class="profile-edit-avatar">
        <?php if ($adminProfilePhotoUrl !== ''): ?>
          <img src="<?= esc($adminProfilePhotoUrl) ?>" alt="<?= esc($adminName) ?> profile photo">
        <?php else: ?>
          <?= esc(strtoupper(substr($adminName, 0, 1))) ?>
        <?php endif; ?>
      </div>

      <div>
        <span class="profile-kicker">
          Department Administrator
        </span>

        <h2>My Profile</h2>

        <p>
          Update your personal administrator information.
        </p>
      </div>

    </div>


    <form method="post" enctype="multipart/form-data">

      <input
        type="hidden"
        name="action"
        value="profile_update"
      >

      <?= csrf_field() ?>


      <div class="profile-edit-grid">


        <!-- ADMINISTRATOR PROFILE PHOTO -->
        <div class="profile-photo-editor">

          <div class="profile-photo-editor-preview" data-admin-photo-preview>
            <?php if ($adminProfilePhotoUrl !== ''): ?>
              <img
                src="<?= esc($adminProfilePhotoUrl) ?>"
                alt="<?= esc($adminName) ?> profile photo"
                data-admin-photo-preview-image
              >
            <?php else: ?>
              <span data-admin-photo-preview-fallback>
                <?= esc(strtoupper(substr($adminName, 0, 1))) ?>
              </span>
            <?php endif; ?>
          </div>

          <div class="profile-photo-editor-copy">
            <strong>Administrator Profile Photo</strong>

            <input
              type="file"
              name="profile_photo"
              accept="image/jpeg,image/png,image/webp"
              data-admin-photo-input
            >

            <small>
              Optional · JPG, PNG, or WEBP · maximum 10 MB.
              This is your personal administrator photo, not the department logo.
            </small>
          </div>

        </div>


        <!-- FULL NAME -->
        <div class="dash-field">
          <label>Full Name *</label>

          <input
            type="text"
            name="fullname"
            value="<?= esc(isset($adminProfile['fullname']) ? $adminProfile['fullname'] : $adminName) ?>"
            maxlength="150"
            required
          >
        </div>


        <!-- USERNAME / PROTECTED -->
        <?php if (isset($adminProfile) && is_array($adminProfile) && array_key_exists('username', $adminProfile)): ?>

          <div class="dash-field">
            <label>Username</label>

            <input
              type="text"
              value="<?= esc($adminProfile['username']) ?>"
              disabled
            >

            <span class="profile-protected-note">
              <i class="ti ti-lock"></i>
              Managed by Super Admin
            </span>
          </div>

        <?php endif; ?>


        <!-- EMAIL -->
        <?php if (isset($adminProfile) && is_array($adminProfile) && array_key_exists('email', $adminProfile)): ?>

          <div class="dash-field">
            <label>Email Address</label>

            <input
              type="email"
              name="email"
              value="<?= esc(isset($adminProfile['email']) ? $adminProfile['email'] : '') ?>"
              maxlength="190"
              placeholder="name@example.com"
            >
          </div>

        <?php endif; ?>


        <!-- CONTACT NUMBER -->
        <?php if (isset($adminProfile) && is_array($adminProfile) && array_key_exists('contact_number', $adminProfile)): ?>

          <div class="dash-field">
            <label>Contact Number</label>

            <input
              type="text"
              name="contact_number"
              value="<?= esc(isset($adminProfile['contact_number']) ? $adminProfile['contact_number'] : '') ?>"
              maxlength="50"
              placeholder="e.g. 09XX XXX XXXX"
            >
          </div>

        <?php endif; ?>


        <!-- ASSIGNED DEPARTMENT / PROTECTED -->
        <div class="dash-field profile-field-wide">
          <label>Assigned Department</label>

          <input
            type="text"
            value="<?= esc($collegeCode . ' — ' . $collegeName) ?>"
            disabled
          >

          <span class="profile-protected-note">
            <i class="ti ti-building"></i>
            Department assignment can only be changed by Super Admin
          </span>
        </div>

      </div>


      <div class="profile-edit-info">

        <i class="ti ti-info-circle"></i>

        <span>
          Saving changes updates your Department Admin account.
          Your username, role, and assigned department remain protected.
        </span>

      </div>


      <div class="profile-edit-actions">

        <button
          type="button"
          class="btn-dash-secondary"
          onclick="closeModal()"
        >
          Cancel
        </button>

        <button
          type="submit"
          class="btn-dash-primary"
        >
          <i class="ti ti-device-floppy"></i>
          Save Profile
        </button>

      </div>

    </form>

  </div>

</div>
<script>

// ─── TOP-RIGHT ACCOUNT DROPDOWN ─────────────────────────
function setAccountMenu(open) {
  var button = document.getElementById('dash-account-button');
  var menu = document.getElementById('dash-account-menu');

  if (!button || !menu) return;

  menu.classList.toggle('show', !!open);
  button.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function toggleAccountMenu(event) {
  if (event) event.stopPropagation();

  var menu = document.getElementById('dash-account-menu');
  if (!menu) return;

  setAccountMenu(!menu.classList.contains('show'));
}

document.addEventListener('click', function(event) {
  var wrap = document.getElementById('dash-account-wrap');

  if (wrap && !wrap.contains(event.target)) {
    setAccountMenu(false);
  }
});

document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    setAccountMenu(false);
  }
});


// ─── ADMINISTRATOR PROFILE PHOTO PREVIEW ─────────────────
document.addEventListener('change', function(event) {
  var input = event.target.closest('[data-admin-photo-input]');
  if (!input) return;

  var file = input.files && input.files[0] ? input.files[0] : null;
  if (!file) return;

  if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
    input.value = '';
    alert('Please select a JPG, PNG, or WEBP profile photo.');
    return;
  }

  if (file.size > 10 * 1024 * 1024) {
    input.value = '';
    alert('Administrator profile photo must not exceed 10 MB.');
    return;
  }

  var preview = document.querySelector('#modalContent [data-admin-photo-preview]');
  if (!preview) return;

  var oldUrl = preview.getAttribute('data-object-url');
  if (oldUrl) {
    try { URL.revokeObjectURL(oldUrl); } catch (e) {}
  }

  var url = URL.createObjectURL(file);
  preview.setAttribute('data-object-url', url);
  preview.innerHTML =
    '<img src="' + url + '" alt="Administrator profile photo preview">';
});

// ─── MODAL FUNCTIONS ────────────────────────────────────
function openModal(formId) {
  var content = document.getElementById(formId);
  if (!content) return;
  if (formId === 'form-upload-image' && !document.querySelector('#form-upload-image select[name="album_id"] option:not([value=""])')) {
    alert('Please create an album before uploading a photo.');
    return;
  }
  document.getElementById('modalContent').innerHTML = content.innerHTML;
  var modalBox = document.querySelector('#modalOverlay .modal-box');
  if (modalBox) modalBox.classList.toggle('modal-wide', formId.indexOf('view-survey-') === 0);
  document.getElementById('modalOverlay').classList.add('active');
}
function closeModal() {
  document.getElementById('modalOverlay').classList.remove('active');
  document.getElementById('modalContent').innerHTML = '';
  var modalBox = document.querySelector('#modalOverlay .modal-box');
  if (modalBox) modalBox.classList.remove('modal-wide');
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

// ─── SIDEBAR TOGGLE (with backdrop) ─────────────────
function toggleSidebar(force) {
  const sidebar = document.getElementById('dash-sidebar');
  const backdrop = document.getElementById('sidebar-backdrop');
  const open = typeof force === 'boolean' ? force : !sidebar.classList.contains('open');
  sidebar.classList.toggle('open', open);
  if (backdrop) backdrop.classList.toggle('show', open);
}

// ─── SIDEBAR AUTO CLOSE ON MOBILE ──────────────────
document.querySelectorAll('.dash-link, .dash-logout').forEach(link => {
  link.addEventListener('click', function() {
    if (window.innerWidth <= 768) {
      toggleSidebar(false);
    }
  });
});

// ─── FLASH MESSAGE: dismiss + auto-fade ────────────────
function dismissFlash() {
  const el = document.getElementById('dash-flash-msg');
  if (!el) return;
  el.classList.add('leaving');
  setTimeout(() => el.remove(), 350);
}
(function() {
  const flash = document.getElementById('dash-flash-msg');
  if (flash) setTimeout(dismissFlash, 5000);
})();

// ─── GALLERY: stagger index for entrance animation ─────
document.querySelectorAll('.dash-gallerygrid > .dash-gitem').forEach((el, i) => {
  el.style.setProperty('--i', Math.min(i, 10));
});

// ─── GALLERY: open/close album folders ─────────────────
let currentAlbumId = null;

function openAlbum(albumId, albumName) {
  currentAlbumId = albumId;
  const albumsView = document.getElementById('galleryAlbumsView');
  const photosView = document.getElementById('galleryPhotosView');
  if (!albumsView || !photosView) return;

  const items = document.querySelectorAll('#galleryPhotoGrid > .dash-gitem');
  let visible = 0;
  items.forEach(el => {
    const match = String(el.dataset.album) === String(albumId);
    el.style.display = match ? '' : 'none';
    if (match) visible++;
  });

  document.getElementById('galleryActiveAlbumName').textContent = albumName;
  document.getElementById('galleryActiveAlbumCount').textContent = visible === 0 ? '' : '· ' + visible + (visible === 1 ? ' photo' : ' photos');
  const emptyEl = document.getElementById('galleryPhotoEmpty');
  if (emptyEl) emptyEl.style.display = visible === 0 ? 'block' : 'none';

  albumsView.style.display = 'none';
  photosView.style.display = 'block';
  photosView.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function backToAlbums() {
  currentAlbumId = null;
  const albumsView = document.getElementById('galleryAlbumsView');
  const photosView = document.getElementById('galleryPhotosView');
  if (!albumsView || !photosView) return;
  photosView.style.display = 'none';
  albumsView.style.display = 'block';
}

function addPhotoToCurrentAlbum() {
  openModal('form-upload-image');
  if (currentAlbumId == null) return;
  const select = document.querySelector('#modalContent select[name="album_id"]');
  if (select) select.value = currentAlbumId;
}


function filterGalleryAlbums(query) {
  var value = String(query || '').toLowerCase().trim();
  var visible = 0;
  document.querySelectorAll('#galleryAlbumGrid .gallery-album-card').forEach(function(card) {
    var match = !value || String(card.getAttribute('data-album-name') || '').indexOf(value) !== -1;
    card.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  var empty = document.getElementById('galleryAlbumEmpty');
  if (empty) empty.style.display = visible === 0 ? 'block' : 'none';
}

function filterGalleryPhotos(query) {
  var value = String(query || '').toLowerCase().trim();
  var visible = 0;
  document.querySelectorAll('#galleryPhotoGrid > .dash-gitem').forEach(function(card) {
    var albumMatch = currentAlbumId == null || String(card.dataset.album) === String(currentAlbumId);
    var textMatch = !value || String(card.getAttribute('data-search') || '').indexOf(value) !== -1;
    var match = albumMatch && textMatch;
    card.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  var empty = document.getElementById('galleryPhotoEmpty');
  if (empty) empty.style.display = visible === 0 ? 'block' : 'none';
  var count = document.getElementById('galleryActiveAlbumCount');
  if (count && currentAlbumId != null) count.textContent = visible === 0 ? '' : '· ' + visible + (visible === 1 ? ' photo' : ' photos');
}

// Open a response directly when the Roster sends the admin to Survey Responses.
document.addEventListener('DOMContentLoaded', function() {
  <?php if ($tab === 'surveys' && !empty($_GET['focus_id'])): ?>
  var focusModal = document.getElementById('view-survey-<?= (int)$_GET['focus_id'] ?>');
  if (focusModal) openModal('view-survey-<?= (int)$_GET['focus_id'] ?>');
  <?php endif; ?>

  var logoInput = document.querySelector('input[name="department_logo"]');
  if (logoInput) {
    logoInput.addEventListener('change', function() {
      var file = this.files && this.files[0] ? this.files[0] : null;
      var preview = document.querySelector('.department-logo-large');
      if (!file || !preview || !/^image\//i.test(file.type)) return;
      var url = URL.createObjectURL(file);
      preview.innerHTML = '<img src="' + url + '" alt="Department logo preview">';
    });
  }
});

// ─── DONUT CHART HELPER ──────────────────────────────────
function buildDonut(containerId, legendId, data, colors) {
  const container = document.getElementById(containerId);
  const legendEl = document.getElementById(legendId);
  if (!container || !legendEl) return;
  
  const keys = Object.keys(data);
  const hasData = keys.some(k => data[k] > 0);
  
  if (!hasData || keys.length === 0) {
    container.innerHTML = '<div style="font-size:12px;color:var(--text-muted);padding:20px 0;text-align:center;">No data</div>';
    legendEl.innerHTML = '';
    return;
  }
  
  const sum = Object.values(data).reduce((a,b) => a + b, 0) || 1;
  let cumulative = 0;
  let svg = `<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">`;
  
  keys.forEach((key) => {
    const value = data[key] || 0;
    if (value === 0) return;
    const angle = (value / sum) * 360;
    const start = cumulative;
    const end = cumulative + angle;
    const x1 = 50 + 40 * Math.cos((start - 90) * Math.PI / 180);
    const y1 = 50 + 40 * Math.sin((start - 90) * Math.PI / 180);
    const x2 = 50 + 40 * Math.cos((end - 90) * Math.PI / 180);
    const y2 = 50 + 40 * Math.sin((end - 90) * Math.PI / 180);
    const largeArc = angle > 180 ? 1 : 0;
    const color = colors[key] || '#ccc';
    svg += `<path d="M50,50 L${x1},${y1} A40,40 0 ${largeArc},1 ${x2},${y2} Z" fill="${color}" />`;
    cumulative += angle;
  });
  svg += `<circle cx="50" cy="50" r="24" fill="var(--card-bg)" stroke="var(--card-bg)" stroke-width="2"/>`;
  svg += '</svg>';
  container.innerHTML = svg;

  // Legend
  let legend = '';
  keys.forEach(key => {
    const val = data[key] || 0;
    if (val === 0) return;
    const pct = Math.round((val / sum) * 100);
    const color = colors[key] || '#ccc';
    legend += `<div class="dleg-row"><span class="dleg-dot" style="background:${color}"></span><span class="dleg-lbl">${key}</span><span class="dleg-val">${val} <small>(${pct}%)</small></span></div>`;
  });
  legendEl.innerHTML = legend;
}

<?php if ($tab === 'analytics'): ?>
// ─── RENDER DONUT CHARTS ──────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  const colors = {
    'Male': '#2563eb',
    'Female': '#db2777',
    'Employed': '#2b7a4b',
    'Underemployed': '#c8991a',
    'Unemployed': '#e24b4a',
    'Unknown': '#8a9ab5',
    'Completed': '#2563eb',
    'Partial': '#c99a2b',
    'Not started': '#e24b4a',
    'Directly related': '#2b7a4b',
    'Related': '#22d3ee',
    'Not related': '#e24b4a',
    'Self-Employed': '#0e7490',
    'Freelancer': '#7c3aed',
    'Continuing Studies': '#8a9ab5'
  };

  <?php
  $genderColors = ['Male' => '#2563eb', 'Female' => '#db2777'];
  $empColors = ['Employed' => '#2b7a4b', 'Underemployed' => '#c8991a', 'Unemployed' => '#e24b4a', 'Unknown' => '#8a9ab5', 'Self-Employed' => '#0e7490', 'Freelancer' => '#7c3aed', 'Continuing Studies' => '#8a9ab5'];
  $surveyColors = ['Completed' => '#2563eb', 'Partial' => '#c99a2b', 'Not started' => '#e24b4a'];
  $alignColors = ['Directly related' => '#2b7a4b', 'Related' => '#22d3ee', 'Not related' => '#e24b4a', 'Unknown' => '#8a9ab5'];
  ?>
  
  buildDonut('gender-donut', 'leg-gender', <?= json_encode($genderMap) ?>, <?= json_encode($genderColors) ?>);
  buildDonut('emp-donut', 'leg-emp', <?= json_encode($empMap) ?>, <?= json_encode($empColors) ?>);
  buildDonut('survey-donut', 'leg-survey', <?= json_encode($surveyMap) ?>, <?= json_encode($surveyColors) ?>);
  buildDonut('align-donut', 'leg-align', <?= json_encode($alignMap) ?>, <?= json_encode($alignColors) ?>);
});
<?php endif; ?>

<?php if ($tab === 'email'): ?>
// ─── EMAIL WIZARD V2 ───────────────────────────────────
var selectedBatch = null;
var selectedTemplate = 'Polite';
var currentStep = 1;
var recipientsByBatch = <?= json_encode($emailRecipientsByBatch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function selectBatch(batch, hasEligible) {
  if (!hasEligible) {
    alert('This batch has no non-respondents with a valid email address.');
    return;
  }
  selectedBatch = String(batch);
  document.querySelectorAll('.email-batch-card').forEach(function(el) { el.classList.remove('selected'); });
  var card = document.querySelector('.email-batch-card[data-batch="' + batch + '"]');
  if (card) card.classList.add('selected');
  var next = document.getElementById('batch-next');
  if (next) next.disabled = false;
  var batchField = document.getElementById('email-batch-year');
  if (batchField) batchField.value = batch;
  var previewBatch = document.getElementById('preview-batch');
  if (previewBatch) previewBatch.textContent = 'Batch ' + batch;
}

function selectTemplate(template) {
  selectedTemplate = template;
  document.querySelectorAll('.template-card').forEach(function(el) { el.classList.remove('selected'); });
  var card = document.querySelector('.template-card[data-template="' + template + '"]');
  if (card) card.classList.add('selected');
  var templateField = document.getElementById('email-template');
  if (templateField) templateField.value = template;
  var preview = document.getElementById('preview-body');
  if (!preview) return;
  var body = '';
  if (template === 'Polite') {
    body = `Dear [Alumni Name],

Greetings from the <?= esc($collegeName) ?> Alumni Office!

This is a friendly reminder that we have not yet received your CHED Graduate Tracer Survey response for [Batch Year].

Please log in to TRACEGRAD using your Student ID and open the Tracer Survey. Your response helps ISUFST improve academic programs and graduate support.

Thank you for your time.

Warm regards,
<?= esc($collegeName) ?> Alumni Coordinator
ISUFST - San Enrique Campus`;
  } else if (template === 'Urgent') {
    body = `Dear [Alumni Name],

Your CHED Graduate Tracer Survey response for [Batch Year] is still pending.

Please complete the tracer survey as soon as possible through TRACEGRAD using your Student ID. Your participation is important to the department's graduate-outcome monitoring.

Thank you for your cooperation.

Regards,
<?= esc($collegeName) ?> Alumni Coordinator
ISUFST - San Enrique Campus`;
  } else {
    body = `Dear [Alumni Name],

This is a final reminder that we have not yet received your CHED Graduate Tracer Survey response for [Batch Year].

Please sign in to TRACEGRAD and submit the Tracer Survey at your earliest opportunity.

Thank you for supporting ISUFST's graduate tracer initiative.

Sincerely,
<?= esc($collegeName) ?> Alumni Coordinator
ISUFST - San Enrique Campus`;
  }
  preview.textContent = body;
}

function goToStep(step) {
  if (step === 2 && !selectedBatch) {
    alert('Please select an eligible batch first.');
    return;
  }
  if (step === 4 && selectedBatch) loadRecipients(selectedBatch);
  currentStep = step;
  for (var i = 1; i <= 5; i++) {
    var el = document.getElementById('email-step-' + i);
    if (el) el.style.display = i === step ? 'block' : 'none';
    var stepEl = document.querySelector('.email-wizard .step[data-step="' + i + '"]');
    if (stepEl) {
      stepEl.classList.remove('active', 'done');
      if (i < step) stepEl.classList.add('done');
      else if (i === step) stepEl.classList.add('active');
    }
  }
}

function escapeHtml(value) {
  var div = document.createElement('div');
  div.textContent = value == null ? '' : String(value);
  return div.innerHTML;
}

function loadRecipients(batch) {
  var list = document.getElementById('recipient-list');
  var summary = document.getElementById('recipient-summary');
  if (!list) return;
  var recipients = recipientsByBatch[String(batch)] || [];
  if (!recipients.length) {
    list.innerHTML = '<div class="dash-empty"><i class="ti ti-mail-off"></i><strong>No eligible recipients</strong><span>No non-respondents in this batch currently have a usable email address.</span></div>';
    if (summary) summary.textContent = '0 eligible';
    return;
  }
  var html = '';
  recipients.forEach(function(r) {
    html += '<label class="recipient-item recipient-item-v2">' +
      '<input type="checkbox" name="recipients[]" value="' + Number(r.graduate_id) + '" checked>' +
      '<span class="recipient-avatar">' + escapeHtml((r.firstname || '').charAt(0) + (r.lastname || '').charAt(0)) + '</span>' +
      '<span class="recipient-person"><strong>' + escapeHtml(r.lastname + ', ' + r.firstname) + '</strong><small>' + escapeHtml(r.student_id) + ' · Batch ' + escapeHtml(r.batch_year) + '</small></span>' +
      '<span class="recipient-email"><i class="ti ti-mail"></i>' + escapeHtml(r.email_address) + '</span>' +
      '<span class="badge badge-danger">No response</span>' +
      '</label>';
  });
  list.innerHTML = html;
  if (summary) summary.textContent = recipients.length + (recipients.length === 1 ? ' eligible alumnus' : ' eligible alumni');
}

document.addEventListener('DOMContentLoaded', function() {
  selectTemplate('Polite');
  goToStep(1);
});
<?php endif; ?>
</script>
<?php if ($tab === 'email'): ?>
<script src="assets/js/dept-email-reminders.js?v=<?= esc((string)(@filemtime(__DIR__ . '/assets/js/dept-email-reminders.js') ?: '1')) ?>" defer></script>
<?php endif; ?>
<?php if ($tab === 'analytics'): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>
<script src="assets/js/dept-workplace-map.js?v=<?= esc((string)(@filemtime(__DIR__ . '/assets/js/dept-workplace-map.js') ?: '1')) ?>" defer></script>
<script
    src="assets/js/dept-ai-analytics.js?v=<?= esc((string)(@filemtime(__DIR__ . '/assets/js/dept-ai-analytics.js') ?: '1')) ?>"
    defer
></script>
<?php endif; ?>
</body>
</html>