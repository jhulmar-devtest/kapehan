<?php

/**
 * settings.php — User profile settings for all roles
 *
 * Allows users to edit their profile information and change password.
 * Accessible by: Admin, Cashier, Student, Faculty
 */
require_once __DIR__ . '/../config/init.php';
requireRole(ROLE_ADMIN, ROLE_CASHIER, ROLE_STUDENT, ROLE_FACULTY);

$db = Database::getInstance();
$userId = currentUserId();
$role = currentRole();

// Determine table and editable fields based on role
$table = match ($role) {
  ROLE_ADMIN => 'admins',
  ROLE_CASHIER => 'cashiers',
  ROLE_STUDENT => 'students',
  ROLE_FACULTY => 'faculty',
  default => throw new Exception('Invalid role')
};

// Fetch current user data
$stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
  flash('settings', 'User not found.', 'error');
  redirect(APP_URL . '/login.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // BUG FIX: this used to manually compare
  // $_POST['csrf_token'] !== $_SESSION['csrf_token'] — a plain string
  // comparison, not the timing-safe hash_equals() the shared
  // verifyCsrf() helper (includes/csrf.php) uses everywhere else in the
  // app. A `!==` comparison on a security token reopens exactly the
  // timing side-channel hash_equals() exists to close. No reason for
  // this one file to have its own weaker reimplementation.
  verifyCsrf();

  $action = $_POST['action'] ?? '';

  if ($action === 'update_profile') {
    // Update profile information
    $fullName = sanitizeString($_POST['full_name'] ?? '', 100);
    $email = null;
    $course = null;

    // Validate full name
    if (empty($fullName)) {
      flash('settings', 'Full name is required.', 'error');
      redirect(APP_URL . '/settings.php');
    }

    // Build update query based on role
    $updates = ['full_name = ?'];
    $params = [$fullName];

    // Students can also update email and course
    if ($role === ROLE_STUDENT) {
      $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
      $course = sanitizeString($_POST['course'] ?? '', 100);

      if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('settings', 'Please enter a valid email address.', 'error');
        redirect(APP_URL . '/settings.php');
      }

      $updates[] = 'email = ?';
      $updates[] = 'course = ?';
      $params[] = $email ?: null;
      $params[] = $course;
    }

    $params[] = $userId;

    try {
      $sql = "UPDATE {$table} SET " . implode(', ', $updates) . " WHERE id = ?";
      $stmt = $db->prepare($sql);
      $stmt->execute($params);

      // Update session
      $_SESSION['full_name'] = $fullName;

      // Log the action
      auditLog($role, $userId, 'update_profile');

      flash('settings', 'Profile updated successfully!', 'success');
    } catch (PDOException $e) {
      flash('settings', 'Failed to update profile. Please try again.', 'error');
    }

    redirect(APP_URL . '/settings.php');
  }

  if ($action === 'change_password') {
    // Change password
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate current password
    if (!password_verify($currentPassword, $user['password'])) {
      flash('settings', 'Current password is incorrect.', 'error');
      redirect(APP_URL . '/settings.php');
    }

    // Validate new password
    $passwordError = validatePassword($newPassword);
    if ($passwordError) {
      flash('settings', $passwordError, 'error');
      redirect(APP_URL . '/settings.php');
    }

    // Check confirmation
    if ($newPassword !== $confirmPassword) {
      flash('settings', 'New passwords do not match.', 'error');
      redirect(APP_URL . '/settings.php');
    }

    // Hash and update — same cost factor used everywhere else in the app
    // (register.php, reset-password.php, add-cashier.php); PASSWORD_DEFAULT
    // alone uses PHP's default cost (10), a slightly weaker hash than every
    // other password in this system for no reason.
    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
      $stmt = $db->prepare("UPDATE {$table} SET password = ? WHERE id = ?");
      $stmt->execute([$passwordHash, $userId]);

      // Log the action
      auditLog($role, $userId, 'change_password');

      flash('settings', 'Password changed successfully!', 'success');
    } catch (PDOException $e) {
      flash('settings', 'Failed to change password. Please try again.', 'error');
    }

    redirect(APP_URL . '/settings.php');
  }
}

// Ensure a CSRF token exists for the form below, without clobbering an
// already-valid one. BUG FIX: this used to unconditionally regenerate
// $_SESSION['csrf_token'] on every single page load — unlike every other
// page, which uses the shared csrfToken() (only generates if missing).
// Forcing a fresh token on every load meant opening settings.php in a
// second tab, or navigating back to an earlier render, invalidated the
// token already embedded in the other page — a legitimate submit would
// then fail with "Invalid request" for no reason a user could see.
csrfToken();

// Get role display name
$roleDisplay = match ($role) {
  ROLE_ADMIN => 'Administrator',
  ROLE_CASHIER => 'Cashier',
  ROLE_STUDENT => 'Student',
  ROLE_FACULTY => 'Faculty',
  default => 'User'
};

// Handle email verification request for cashiers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_email'])) {
  verifyCsrf();

  if ($role === ROLE_CASHIER && !empty($user['email'])) {
    // Generate and send OTP
    $otp = generateOtp();
    storeOtp(ROLE_CASHIER, $userId, $user['email'], $otp, 'verification');
    $emailResult = sendOtpEmail($user['email'], $otp, 'verification');

    if ($emailResult['success']) {
      // Store pending verification in session
      $_SESSION['pending_verification'] = [
        'user_type' => ROLE_CASHIER,
        'user_id'   => $userId,
        'email'     => $user['email'],
        'purpose'   => 'verification'
      ];
      flash('settings', 'Verification code sent to your email.', 'success');
      redirect(APP_URL . '/verify-email.php');
    } else {
      flash('settings', 'Failed to send verification email. Please try again.', 'error');
    }
    redirect(APP_URL . '/settings.php');
  }
}

layoutHeader('Settings');
?>

<style>
  .settings-container {
    max-width: 1000px;
    margin: 0 auto;
  }

  .settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-5);
  }

  @media (max-width: 900px) {
    .settings-grid {
      grid-template-columns: 1fr;
    }
  }

  .settings-card {
    height: 100%;
    display: flex;
    flex-direction: column;
  }

  .settings-card .card-header {
    flex-shrink: 0;
  }

  .settings-card>div:last-child {
    flex: 1;
    display: flex;
    flex-direction: column;
  }

  .settings-card form {
    display: flex;
    flex-direction: column;
    height: 100%;
  }

  .settings-card form>div:first-child {
    flex: 1;
  }

  .settings-card form>div:last-child {
    flex-shrink: 0;
    margin-top: var(--space-5);
  }

  .settings-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-full);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .settings-icon.profile {
    background: var(--primary-subtle);
    color: var(--primary-color);
  }

  .settings-icon.security {
    background: var(--status-cancelled-bg);
    color: var(--status-cancelled);
  }

  .account-info {
    padding: var(--space-3);
    background: var(--surface-raised);
    border-radius: var(--radius-md);
    font-size: 0.78rem;
    color: var(--text-muted);
  }

  .account-info>div+div {
    margin-top: 4px;
  }

  /* Password toggle wrapper */
  .password-wrapper {
    position: relative;
    display: flex;
    align-items: center;
  }

  .password-wrapper .form-control {
    padding-right: 40px;
  }

  .password-toggle {
    position: absolute;
    right: 12px;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    padding: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.2s;
  }

  .password-toggle:hover {
    color: var(--text);
  }

  /* Disable Microsoft Edge's built-in password reveal button */
  input[type="password"]::-ms-reveal,
  input[type="text"]::-ms-reveal {
    display: none;
  }

  /* Also disable for webkit browsers' native reveal */
  input[type="password"]::-webkit-credentials-auto-fill-button,
  input[type="text"]::-webkit-credentials-auto-fill-button {
    display: none;
  }
</style>

<div class="page-header">
  <div>
    <div class="page-header-title">Account Settings</div>
    <div class="page-header-sub">Manage your profile and security preferences</div>
  </div>
</div>

<?php showFlash('settings'); ?>

<div class="settings-container">
  <div class="settings-grid">

    <!-- Profile Information Card -->
    <div class="card settings-card">
      <div class="card-header">
        <div style="display: flex; align-items: center; gap: var(--space-3);">
          <div class="settings-icon profile">
            <i class="fa-solid fa-user"></i>
          </div>
          <div>
            <h3 style="margin: 0; font-size: 1rem; font-weight: 700;">Profile Information</h3>
            <p style="margin: 0; font-size: 0.78rem; color: var(--text-muted);">Update your personal details</p>
          </div>
        </div>
      </div>

      <div style="padding: var(--space-5);">
        <form method="POST" action="">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_profile">

          <div style="display: grid; gap: var(--space-4);">
            <!-- Role (read-only) -->
            <div class="form-group">
              <label class="form-label">Account Type</label>
              <input type="text" class="form-control" value="<?= e($roleDisplay) ?>" disabled style="background: var(--surface-raised); cursor: not-allowed;">
            </div>

            <!-- Account Identifier (read-only) -->
            <div class="form-group">
              <label class="form-label">
                <?php if ($role === ROLE_STUDENT): ?>
                  Student ID
                <?php elseif ($role === ROLE_FACULTY): ?>
                  Faculty ID
                <?php else: ?>
                  Username
                <?php endif; ?>
              </label>

              <input type="text" class="form-control"
                value="<?php
                        if ($role === ROLE_STUDENT) {
                          echo e($user['student_id_no'] ?? '');
                        } elseif ($role === ROLE_FACULTY) {
                          echo e($user['faculty_id_no'] ?? '');
                        } else {
                          echo e($user['username'] ?? '');
                        }
                        ?>"
                disabled style="background: var(--surface-raised); cursor: not-allowed;">

              <div class="form-hint">This cannot be changed</div>
            </div>

            <!-- Full Name -->
            <div class="form-group">
              <label class="form-label" for="full_name">Full Name <span style="color: var(--status-cancelled)">*</span></label>
              <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required maxlength="100">
            </div>

            <?php if ($role === ROLE_STUDENT): ?>
              <!-- Email (students only) -->
              <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" maxlength="150" placeholder="your.email@example.com">
                <div class="form-hint">Optional, used for notifications</div>
              </div>

              <!-- Course (students only) -->
              <div class="form-group">
                <label class="form-label" for="course">Course/Program <span style="color: var(--status-cancelled)">*</span></label>
                <input type="text" id="course" name="course" class="form-control" value="<?= e($user['course'] ?? '') ?>" required maxlength="100" placeholder="e.g., BS Computer Science">
              </div>
            <?php endif; ?>


            <?php if ($role === ROLE_CASHIER): ?>
              <!-- Email (cashiers) -->
              <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" maxlength="150" placeholder="your.email@example.com">
                <div class="form-hint">Optional, used for password recovery</div>
              </div>

              <!-- Email verification status -->
              <?php if (!empty($user['email'])): ?>
                <div class="form-group">
                  <label class="form-label">Email Status</label>
                  <div style="display: flex; align-items: center; gap: var(--space-3);">
                    <?php if (!empty($user['email_verified'])): ?>
                      <span class="badge badge-paid" style="padding: 6px 12px;"><i class="fa-solid fa-check"></i> Verified</span>
                    <?php else: ?>
                      <span class="badge badge-pending" style="padding: 6px 12px;"><i class="fa-solid fa-clock"></i> Not Verified</span>
                      <form method="POST" action="" style="display: inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="verify_email" value="1">
                        <button type="submit" class="btn btn-sm btn-primary" style="padding: 4px 12px; font-size: 0.75rem;">
                          <i class="fa-solid fa-envelope-circle-check"></i> Verify Email
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php endif; ?>

            <!-- Account Info -->
            <div class="account-info">
              <div><strong>Account created:</strong> <?= date('M j, Y', strtotime($user['created_at'])) ?></div>
              <div><strong>Last updated:</strong> <?= date('M j, Y g:i A', strtotime($user['updated_at'])) ?></div>
            </div>
          </div>

          <div style="display: flex; gap: var(--space-3);">
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-save"></i> Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Change Password Card -->
    <div class="card settings-card">
      <div class="card-header">
        <div style="display: flex; align-items: center; gap: var(--space-3);">
          <div class="settings-icon security">
            <i class="fa-solid fa-lock"></i>
          </div>
          <div>
            <h3 style="margin: 0; font-size: 1rem; font-weight: 700;">Change Password</h3>
            <p style="margin: 0; font-size: 0.78rem; color: var(--text-muted);">Update your account password</p>
          </div>
        </div>
      </div>

      <div style="padding: var(--space-5);">
        <form method="POST" action="">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="change_password">

          <div style="display: grid; gap: var(--space-4);">
            <div class="form-group">
              <label class="form-label" for="current_password">Current Password <span style="color: var(--status-cancelled)">*</span></label>
              <div class="password-wrapper">
                <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password">
                <button type="button" class="password-toggle" data-target="current_password" aria-label="Toggle password visibility">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="new_password">New Password <span style="color: var(--status-cancelled)">*</span></label>
              <div class="password-wrapper">
                <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                <button type="button" class="password-toggle" data-target="new_password" aria-label="Toggle password visibility">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
              <div class="form-hint">Must be at least 8 characters</div>
            </div>

            <div class="form-group">
              <label class="form-label" for="confirm_password">Confirm New Password <span style="color: var(--status-cancelled)">*</span></label>
              <div class="password-wrapper">
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required autocomplete="new-password">
                <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Toggle password visibility">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>

            <!-- Spacer for alignment -->
            <div style="flex: 1;"></div>

            <!-- Security tip -->
            <div style="padding: var(--space-3); background: var(--surface-raised); border-radius: var(--radius-md); font-size: 0.78rem; color: var(--text-muted);">
              <div><i class="fa-solid fa-shield-halved" style="color: var(--primary-color); margin-right: 6px;"></i><strong>Security Tip</strong></div>
              <div style="margin-top: 4px;">Use a unique password with a mix of letters, numbers, and symbols.</div>
            </div>
          </div>

          <div style="display: flex; gap: var(--space-3);">
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-key"></i> Change Password
            </button>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<?php layoutFooter(); ?>

<script>
  // Password visibility toggle
  document.querySelectorAll('.password-toggle').forEach(function(toggle) {
    toggle.addEventListener('click', function() {
      var inputId = this.getAttribute('data-target');
      var input = document.getElementById(inputId);
      var icon = this.querySelector('i');

      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    });
  });
</script>