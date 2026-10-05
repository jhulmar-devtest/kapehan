<?php
// ============================================================
// public/account.php
//
// Reached from the profile dropdown on menu.php ("My Profile").
// Student/Faculty only — admin/cashier have their own settings.php
// reachable from their sidebar, unrelated to this page.
//
// Deliberately NOT in a subfolder (public/account.php, not
// public/student/account.php) since the same file serves both
// roles, same as menu.php itself.
// ============================================================

require_once __DIR__ . '/../config/init.php';
requireRole(ROLE_STUDENT, ROLE_FACULTY);

$db   = Database::getInstance();
$role = currentRole();
$userId = currentUserId();
$table = $role === ROLE_STUDENT ? 'students' : 'faculty';

$stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
  redirect(APP_URL . '/logout.php');
}

// ---- Update profile fields ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
  verifyCsrf();

  $fullName = sanitizeString($_POST['full_name'] ?? '', 100);
  $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
  $course   = $role === ROLE_STUDENT ? sanitizeString($_POST['course'] ?? '', 100) : null;

  $errors = [];
  if (empty($fullName)) $errors[] = 'Full name is required.';
  if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
  if ($role === ROLE_STUDENT && empty($course)) $errors[] = 'Course/Program is required.';

  // If the email actually changed, re-verification is required — this
  // mirrors how registration works, so there's no backdoor around OTP.
  $emailChanged = !empty($email) && $email !== $user['email'];
  if ($emailChanged) {
    $chk = $db->prepare("SELECT id FROM {$table} WHERE email = ? AND id != ?");
    $chk->execute([$email, $userId]);
    if ($chk->fetch()) $errors[] = 'That email is already in use by another account.';
  }

  if (!empty($errors)) {
    flash('global', implode(' ', $errors), 'error');
  } else {
    $updates = ['full_name = ?'];
    $params  = [$fullName];
    if ($role === ROLE_STUDENT) {
      $updates[] = 'course = ?';
      $params[] = $course;
    }
    if ($emailChanged) {
      $updates[] = 'email = ?';
      $updates[] = 'email_verified = 0';
      $params[]  = $email;
    }
    $params[] = $userId;

    $db->prepare("UPDATE {$table} SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
    $_SESSION['full_name'] = $fullName;
    auditLog($role, $userId, 'update_profile');

    if ($emailChanged) {
      $otp = generateOtp();
      storeOtp($role, $userId, $email, $otp, 'verification');
      sendOtpEmail($email, $otp, 'verification');
      $_SESSION['pending_verification'] = ['user_type' => $role, 'user_id' => $userId, 'email' => $email, 'purpose' => 'verification'];
      flash('global', 'Profile updated. Please verify your new email address.', 'success');
      redirect(APP_URL . '/verify-email.php');
    }
    flash('global', 'Profile updated.', 'success');
  }
  redirect(APP_URL . '/account.php');
}

// ---- Change password ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
  verifyCsrf();
  $current = $_POST['current_password'] ?? '';
  $new     = $_POST['new_password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';

  if (!password_verify($current, $user['password'])) {
    flash('global', 'Current password is incorrect.', 'error');
  } elseif ($err = validatePassword($new)) {
    flash('global', $err, 'error');
  } elseif ($new !== $confirm) {
    flash('global', 'New passwords do not match.', 'error');
  } else {
    $db->prepare("UPDATE {$table} SET password = ? WHERE id = ?")
      ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $userId]);
    auditLog($role, $userId, 'change_password');
    flash('global', 'Password changed.', 'success');
  }
  redirect(APP_URL . '/account.php');
}

$idLabel = $role === ROLE_STUDENT ? 'Student ID' : 'Faculty ID';
$idValue = $role === ROLE_STUDENT ? ($user['student_id_no'] ?? '') : ($user['faculty_id_no'] ?? '');
$ordersUrl = APP_URL . '/orders.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrfToken() ?>">
  <title>My Profile — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/variables.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/account-shell.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/cart-drawer.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/cart-drawer.css') ?>">
  <style>
    /* ── Page-specific styles only — shared header/sidebar/subnav now live in account-shell.css ── */
    .profile-card {
      flex: 1;
      background: #fff;
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 28px;
      min-width: 0;
    }

    .profile-card-title {
      font-size: 20px;
      font-weight: 800;
      margin-bottom: 2px;
    }

    .profile-card-sub {
      font-size: 13.5px;
      color: var(--text-muted);
      margin-bottom: 24px;
    }

    .profile-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 18px 0;
      border-bottom: 1px solid var(--border-color);
    }

    .profile-row:last-of-type {
      border-bottom: none;
    }

    .profile-row-label {
      font-size: 12.5px;
      color: var(--text-muted);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .03em;
      flex: 0 0 160px;
    }

    .profile-row-main {
      flex: 1;
      min-width: 0;
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .profile-row-value {
      font-size: 15px;
      font-weight: 600;
    }

    .verified-badge {
      font-size: 11px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 999px;
      background: #e7f6ec;
      color: #1e7a3d;
    }

    .unverified-badge {
      font-size: 11px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 999px;
      background: #fdecea;
      color: #b3261e;
    }

    .row-icon-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      border: 1px solid var(--border-color);
      background: var(--surface-color);
      color: var(--text-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      flex-shrink: 0;
    }

    .row-icon-btn:hover {
      border-color: var(--primary-color);
      color: var(--primary-color);
    }

    .profile-row-input {
      display: none;
      width: 100%;
      max-width: 320px;
      padding: 9px 12px;
      border: 1px solid var(--primary-color);
      border-radius: 9px;
      font: inherit;
    }

    .profile-row.editing .profile-row-value,
    .profile-row.editing .verified-badge,
    .profile-row.editing .unverified-badge {
      display: none;
    }

    .profile-row.editing .profile-row-input {
      display: block;
    }

    .lock-icon {
      color: var(--text-placeholder);
      font-size: 14px;
    }

    .save-bar {
      display: flex;
      justify-content: flex-end;
      margin-top: 20px;
      gap: 10px;
    }

    .change-pw-link {
      font-size: 13.5px;
      color: var(--primary-color);
      font-weight: 600;
      cursor: pointer;
      margin-top: 22px;
      display: inline-block;
    }

    /* ── Password modal ── */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .4);
      z-index: 400;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .modal-overlay[hidden] {
      display: none;
    }

    .modal-box {
      background: #fff;
      border-radius: 18px;
      max-width: 400px;
      width: 100%;
      padding: 26px;
      position: relative;
    }

    .modal-close {
      position: absolute;
      top: 16px;
      right: 16px;
      background: none;
      border: none;
      font-size: 22px;
      cursor: pointer;
      color: var(--text-muted);
    }

    .modal-box label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      margin: 14px 0 6px;
      color: var(--text-secondary);
    }

    .modal-box input {
      width: 100%;
      padding: 11px 13px;
      border: 1px solid var(--border-color);
      border-radius: 10px;
      font: inherit;
    }

    .modal-box .btn-primary {
      width: 100%;
      margin-top: 18px;
      padding: 12px;
    }

    @media (max-width:760px) {
      .header-search {
        display: none;
      }

      .account-layout {
        flex-direction: column;
      }

      .account-sidebar {
        width: 100%;
        display: flex;
        gap: 4px;
        padding: 8px;
      }

      .account-nav-item {
        flex: 1;
        flex-direction: column;
        font-size: 12px;
        gap: 6px;
        text-align: center;
      }

      .account-nav-item.logout {
        border-top: none;
        padding-top: 12px;
      }

      .profile-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
      }

      .profile-row-label {
        flex: none;
      }
    }
  </style>
</head>

<body>

  <header class="site-header">
    <div class="container header-inner">
      <a href="<?= APP_URL ?>/menu.php" class="logo">
        <img src="<?= APP_URL ?>/../assets/images/logo.png" alt="<?= APP_NAME ?>" onerror="this.style.display='none'">
      </a>

      <div class="header-search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" placeholder="Search the menu...">
      </div>

      <div class="header-actions">
        <div class="user-menu">
          <button class="user-menu-btn" onclick="toggleUserMenu()">
            <i class="fa-solid fa-circle-user" style="font-size:20px"></i>
          </button>
          <div class="user-menu-dropdown" id="userMenuDropdown">
            <a href="<?= APP_URL ?>/account.php" class="user-menu-item active"><i class="fa-solid fa-user"></i> My Profile</a>
            <a href="<?= $ordersUrl ?>" class="user-menu-item"><i class="fa-solid fa-box"></i> My Orders</a>
            <hr style="border:0;border-top:1px solid var(--border-color);margin:4px 0">
            <a href="<?= APP_URL ?>/logout.php" class="user-menu-item"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>
          </div>
        </div>

        <button class="cart-btn" onclick="openCart()" aria-label="Cart">
          <i class="fa-solid fa-bag-shopping"></i>
          <span class="cart-badge" id="cartBadge" style="display:none">0</span>
        </button>
      </div>
    </div>

    <!-- ── This row takes the exact place of the category nav on menu.php ── -->
    <nav class="account-subnav">
      <div class="container account-subnav-inner">
        <a href="<?= APP_URL ?>/menu.php" class="back-btn" aria-label="Back to shop"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="account-subnav-title">Account Management</div>
      </div>
    </nav>
  </header>

  <main class="container">
    <?php showFlashAsToast('global'); ?>

    <div class="account-layout">
      <aside class="account-sidebar">
        <div class="account-nav-item active"><i class="fa-solid fa-user"></i> My Profile</div>
        <a href="<?= $ordersUrl ?>" class="account-nav-item"><i class="fa-solid fa-box"></i> My Orders</a>
        <a href="<?= APP_URL ?>/logout.php" class="account-nav-item logout"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>
      </aside>

      <div class="profile-card">
        <div class="profile-card-title">My Profile</div>
        <div class="profile-card-sub">Edit your personal details</div>

        <form method="POST" id="profileForm">
          <?= csrfField() ?>
          <input type="hidden" name="update_profile" value="1">

          <div class="profile-row">
            <div class="profile-row-label">Name</div>
            <div class="profile-row-main">
              <span class="profile-row-value" id="display-full_name"><?= e($user['full_name']) ?></span>
              <input type="text" name="full_name" class="profile-row-input" id="input-full_name" value="<?= e($user['full_name']) ?>" maxlength="100">
            </div>
            <button type="button" class="row-icon-btn" onclick="editRow('full_name')"><i class="fa-solid fa-pen"></i></button>
          </div>

          <div class="profile-row">
            <div class="profile-row-label"><?= e($idLabel) ?></div>
            <div class="profile-row-main">
              <span class="profile-row-value"><?= e($idValue) ?></span>
            </div>
            <i class="fa-solid fa-lock lock-icon" title="This cannot be changed"></i>
          </div>

          <div class="profile-row">
            <div class="profile-row-label">Email Address</div>
            <div class="profile-row-main">
              <span class="profile-row-value" id="display-email"><?= e($user['email'] ?? 'Not set') ?></span>
              <?php if (!empty($user['email'])): ?>
                <?php if (!empty($user['email_verified'])): ?>
                  <span class="verified-badge">Verified</span>
                <?php else: ?>
                  <span class="unverified-badge">Not Verified</span>
                <?php endif; ?>
              <?php endif; ?>
              <input type="email" name="email" class="profile-row-input" id="input-email" value="<?= e($user['email'] ?? '') ?>" maxlength="150">
            </div>
            <button type="button" class="row-icon-btn" onclick="editRow('email')"><i class="fa-solid fa-pen"></i></button>
          </div>

          <?php if ($role === ROLE_STUDENT): ?>
            <div class="profile-row">
              <div class="profile-row-label">Course / Program</div>
              <div class="profile-row-main">
                <span class="profile-row-value" id="display-course"><?= e($user['course'] ?? 'Not set') ?></span>
                <input type="text" name="course" class="profile-row-input" id="input-course" value="<?= e($user['course'] ?? '') ?>" maxlength="100">
              </div>
              <button type="button" class="row-icon-btn" onclick="editRow('course')"><i class="fa-solid fa-pen"></i></button>
            </div>
          <?php endif; ?>

          <div class="save-bar" id="saveBar" style="display:none">
            <button type="button" class="btn btn-ghost" onclick="cancelEdits()">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>

        <span class="change-pw-link" onclick="document.getElementById('pwModal').hidden=false">
          <i class="fa-solid fa-key"></i> Change Password
        </span>
      </div>
    </div>
  </main>

  <?php require __DIR__ . '/../includes/cart-drawer.php'; ?>

  <!-- Change Password modal -->
  <div class="modal-overlay" id="pwModal" hidden>
    <div class="modal-box">
      <button class="modal-close" onclick="document.getElementById('pwModal').hidden=true">&times;</button>
      <div style="font-size:18px;font-weight:800;margin-bottom:4px">Change Password</div>
      <p style="font-size:13px;color:var(--text-muted)">Choose a new password for your account.</p>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="change_password" value="1">
        <label>Current Password</label>
        <input type="password" name="current_password" required>
        <label>New Password</label>
        <input type="password" name="new_password" required minlength="8">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" required minlength="8">
        <button type="submit" class="btn btn-primary">Update Password</button>
      </form>
    </div>
  </div>

  <script>
    const APP_URL = '<?= APP_URL ?>';
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

    /* ── Profile inline-edit ── */
    const editableFields = ['full_name', 'email'
      <?= $role === ROLE_STUDENT ? ", 'course'" : '' ?>
    ];

    function editRow(field) {
      document.getElementById('input-' + field).closest('.profile-row').classList.add('editing');
      document.getElementById('input-' + field).focus();
      document.getElementById('saveBar').style.display = 'flex';
    }

    function cancelEdits() {
      editableFields.forEach(f => document.getElementById('input-' + f).closest('.profile-row').classList.remove('editing'));
      document.getElementById('saveBar').style.display = 'none';
      document.getElementById('profileForm').reset();
    }

    /* ── User menu dropdown (same as menu.php) ── */
    function toggleUserMenu() {
      document.getElementById('userMenuDropdown').classList.toggle('open');
    }
    window.addEventListener('click', function(e) {
      if (!e.target.closest('.user-menu')) {
        document.getElementById('userMenuDropdown')?.classList.remove('open');
      }
    });

    /* ── Cart, checkout, and toast logic now lives in assets/js/cart-drawer.js
       (shared with orders.php / menu.php) — loaded below. ── */
  </script>
  <script src="<?= APP_URL ?>/../assets/js/cart-drawer.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/cart-drawer.js') ?>"></script>
</body>

</html>
