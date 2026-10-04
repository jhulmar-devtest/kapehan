<?php
// ============================================================
// public/reset-password.php
//
// WHAT THIS FILE DOES:
//   Password reset page - verifies OTP and allows new password entry.
//   Accessible only after forgot-password.php sets session data.
//
// FLOW:
//   User enters OTP → verifies → enters new password → redirects to login
// ============================================================

require_once __DIR__ . '/../config/init.php';

if (isLoggedIn()) redirectByRole();

// Must have password reset session data
if (!isset($_SESSION['password_reset'])) {
  redirect(APP_URL . '/forgot-password.php');
}

$reset   = $_SESSION['password_reset'];
$userType = $reset['user_type'];
$userId   = $reset['user_id'];
$email    = $reset['email'];

$error    = '';
$success  = '';
$step     = $_GET['step'] ?? 'otp'; // 'otp' or 'password'
$cooldown = getOtpCooldownRemaining($userType, $userId, 'password_reset');
$maskedEmail = maskEmail($email);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();

  $action = $_POST['action'] ?? '';

  if ($action === 'verify_otp') {
    $otp = trim($_POST['otp'] ?? '');
    $otp = preg_replace('/[^0-9]/', '', $otp);

    if (strlen($otp) !== OTP_LENGTH) {
      $error = 'Please enter all ' . OTP_LENGTH . ' digits of the verification code.';
    } else {
      $result = validateOtp($userType, $userId, $otp, 'password_reset');

      if ($result['valid']) {
        $step = 'password';
        // SECURITY FIX: this flag is the only thing that gates the
        // password-change branch below. Previously $step was a local
        // variable used purely to decide which HTML form to render —
        // nothing server-side ever confirmed the OTP had actually been
        // verified before accepting a new password. That meant anyone
        // could submit a target's email on forgot-password.php (no
        // login required) and then POST action=reset_password directly
        // to this page in their own session, changing that account's
        // password without ever knowing the OTP. Setting a real
        // session flag here, and requiring it below, closes that.
        $_SESSION['password_reset']['otp_verified'] = true;
      } else {
        $error = $result['message'];
      }
    }
  }

  if ($action === 'reset_password') {
    if (empty($_SESSION['password_reset']['otp_verified'])) {
      // No verified OTP on record for this session — do not touch the
      // password. Send them back to the OTP step instead of erroring
      // silently, since this is also the normal path for someone who
      // reloaded straight to ?step=password without verifying yet.
      $error = 'Please verify your code first.';
      $step  = 'otp';
    } else {
      $newPassword = $_POST['new_password'] ?? '';
      $confirmPassword = $_POST['confirm_password'] ?? '';

      // Validate password
      $pwError = validatePassword($newPassword);
      if ($pwError) {
        $error = $pwError;
        $step = 'password';
      } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
        $step = 'password';
      } else {
        // Update password in database
        $db = Database::getInstance();
        $table = match ($userType) {
          ROLE_STUDENT => 'students',
          ROLE_FACULTY => 'faculty',
          ROLE_CASHIER => 'cashiers',
          default => null
        };

        if ($table) {
          $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
          $stmt = $db->prepare("UPDATE {$table} SET password = ? WHERE id = ?");
          $stmt->execute([$hash, $userId]);

          // Clear session and redirect
          unset($_SESSION['password_reset']);
          auditLog($userType, $userId, 'password_reset');
          flash('global', 'Password reset successfully! You can now log in with your new password.');
          redirect(APP_URL . '/login.php');
        } else {
          $error = 'An error occurred. Please try again.';
        }
      }
    }
  }

  if ($action === 'resend') {
    if ($cooldown > 0) {
      $error = "Please wait {$cooldown} seconds before requesting a new code.";
    } else {
      $otp = generateOtp();
      storeOtp($userType, $userId, $email, $otp, 'password_reset');
      $emailResult = sendOtpEmail($email, $otp, 'password_reset');

      if ($emailResult['success']) {
        $success = 'A new verification code has been sent to your email.';
        $cooldown = OTP_RESEND_COOLDOWN;
      } else {
        $error = 'Failed to send email. Please try again later.';
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password — <?= APP_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/variables.css">
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --land-bg: #faf8f5;
      --land-surface: #f2ede8;
      --land-card: #ffffff;
      --land-border: rgba(107, 62, 38, 0.12);
      --land-text: #1a1008;
      --land-muted: rgba(26, 16, 8, 0.50);
      --land-dim: rgba(26, 16, 8, 0.28);
    }

    html {
      font-size: 16px;
    }

    body {
      font-family: 'DM Sans', system-ui, sans-serif;
      background: var(--land-bg);
      color: var(--land-text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      -webkit-font-smoothing: antialiased;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.035'/%3E%3C/svg%3E");
      background-size: 200px 200px;
      pointer-events: none;
      z-index: 1000;
      opacity: 0.25;
    }

    .nav {
      padding: 18px 40px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: relative;
      z-index: 10;
      border-bottom: 1px solid rgba(107, 62, 38, 0.12);
      background: rgba(250, 248, 245, 0.80);
      backdrop-filter: blur(12px);
    }

    .nav-logo {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .nav-logo-icon {
      width: 32px;
      height: 32px;
      background: var(--primary-color);
      border-radius: var(--radius-full);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      color: #fff;
      box-shadow: 0 3px 10px rgba(192, 57, 43, 0.38);
      flex-shrink: 0;
    }

    .nav-logo-icon img {
      width: 32px;
      height: 32px;
      object-fit: contain;
    }

    .nav-logo-name {
      font-size: 0.84rem;
      font-weight: 700;
      color: var(--land-text);
    }

    .nav-back {
      font-size: 0.78rem;
      font-weight: 500;
      color: var(--land-muted);
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 6px 12px;
      border-radius: 7px;
      border: 1px solid var(--land-border);
      transition: all 0.15s;
    }

    .nav-back:hover {
      color: var(--land-text);
      background: rgba(107, 62, 38, 0.06);
      border-color: rgba(107, 62, 38, 0.28);
    }

    .page-wrap {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 48px 24px;
      position: relative;
      z-index: 1;
    }

    .auth-card {
      width: 100%;
      max-width: 420px;
      background: var(--land-card);
      border: 1px solid rgba(107, 62, 38, 0.12);
      border-radius: 20px;
      box-shadow: 0 12px 48px rgba(107, 62, 38, 0.12);
      overflow: hidden;
      animation: cardIn 0.45s cubic-bezier(0.34, 1.1, 0.64, 1) both;
    }

    @keyframes cardIn {
      from {
        opacity: 0;
        transform: translateY(24px) scale(0.97);
      }

      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .auth-top {
      padding: 36px 36px 28px;
      text-align: center;
      border-bottom: 1px solid rgba(107, 62, 38, 0.12);
      position: relative;
      overflow: hidden;
    }

    .auth-logo {
      width: 52px;
      height: 52px;
      background: var(--primary-color);
      border-radius: var(--radius-full);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      color: #fff;
      margin-bottom: 16px;
      box-shadow: 0 6px 20px rgba(192, 57, 43, 0.45);
      position: relative;
      z-index: 1;
    }

    .auth-title {
      font-family: 'Instrument Serif', serif;
      font-size: 1.45rem;
      font-weight: 400;
      color: var(--land-text);
      letter-spacing: -0.01em;
      margin-bottom: 6px;
    }

    .auth-title em {
      font-style: italic;
      color: var(--primary-color);
    }

    .auth-sub {
      font-size: 0.76rem;
      color: var(--land-muted);
      font-weight: 300;
    }

    .auth-body {
      padding: 28px 32px 32px;
    }

    .auth-alert {
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 0.82rem;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-bottom: 20px;
      border: 1px solid transparent;
      line-height: 1.5;
    }

    .auth-alert i {
      flex-shrink: 0;
      margin-top: 1px;
    }

    .auth-alert-danger {
      background: rgba(239, 68, 68, 0.08);
      color: #b91c1c;
      border-color: rgba(239, 68, 68, 0.20);
    }

    .auth-alert-success {
      background: rgba(16, 185, 129, 0.07);
      color: #065f46;
      border-color: rgba(16, 185, 129, 0.20);
    }

    .email-hint {
      text-align: center;
      font-size: 0.82rem;
      color: var(--land-muted);
      margin-bottom: 24px;
    }

    .email-hint strong {
      color: var(--land-text);
    }

    .otp-container {
      display: flex;
      justify-content: center;
      gap: 10px;
      margin-bottom: 24px;
    }

    .otp-input {
      width: 48px;
      height: 56px;
      text-align: center;
      font-size: 1.5rem;
      font-weight: 700;
      background: rgba(107, 62, 38, 0.04);
      border: 2px solid rgba(107, 62, 38, 0.15);
      border-radius: 12px;
      color: var(--land-text);
      outline: none;
      transition: all 0.15s;
    }

    .otp-input:focus {
      border-color: var(--primary-color);
      background: rgba(192, 57, 43, 0.06);
      box-shadow: 0 0 0 3px rgba(192, 57, 43, 0.12);
    }

    .field-group {
      margin-bottom: 18px;
    }

    .field-label {
      display: block;
      font-size: 0.74rem;
      font-weight: 600;
      color: var(--land-muted);
      margin-bottom: 7px;
      letter-spacing: 0.01em;
    }

    .field-input {
      width: 100%;
      height: 42px;
      padding: 0 14px;
      background: rgba(107, 62, 38, 0.04);
      border: 1.5px solid rgba(107, 62, 38, 0.15);
      border-radius: 10px;
      font-size: 0.86rem;
      font-family: inherit;
      color: var(--land-text);
      outline: none;
      transition: border-color 0.15s, background 0.15s;
    }

    .field-input::placeholder {
      color: var(--land-dim);
    }

    .field-input:hover {
      border-color: rgba(107, 62, 38, 0.28);
    }

    .field-input:focus {
      border-color: var(--primary-color);
      background: rgba(192, 57, 43, 0.06);
      box-shadow: 0 0 0 3px rgba(192, 57, 43, 0.12);
    }

    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .password-wrapper .field-input {
      padding-right: 40px;
    }

    .btn-toggle-password {
      position: absolute;
      right: 12px;
      background: none;
      border: none;
      color: var(--land-dim);
      cursor: pointer;
      font-size: 0.95rem;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color 0.15s;
    }

    .btn-toggle-password:hover {
      color: var(--land-text);
    }

    .btn-submit {
      width: 100%;
      height: 44px;
      border-radius: 10px;
      border: none;
      background: var(--primary-color);
      color: #fff;
      font-size: 0.88rem;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 9px;
      box-shadow: 0 6px 20px rgba(192, 57, 43, 0.42);
      transition: all 0.15s;
      letter-spacing: 0.01em;
    }

    .btn-submit:hover:not(:disabled) {
      background: var(--primary-dark);
      transform: translateY(-1px);
      box-shadow: 0 8px 26px rgba(192, 57, 43, 0.50);
    }

    .btn-resend {
      width: 100%;
      height: 44px;
      border-radius: 10px;
      border: 2px solid rgba(107, 62, 38, 0.2);
      background: transparent;
      color: var(--land-text);
      font-size: 0.85rem;
      font-weight: 600;
      font-family: inherit;
      cursor: pointer;
      transition: all 0.15s;
      margin-top: 12px;
    }

    .btn-resend:hover:not(:disabled) {
      border-color: var(--primary-color);
      background: rgba(192, 57, 43, 0.06);
    }

    .btn-resend:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .auth-footer {
      text-align: center;
      margin-top: 20px;
      font-size: 0.78rem;
      color: var(--land-muted);
    }

    .auth-footer a {
      color: var(--accent-color);
      font-weight: 600;
    }

    .auth-footer a:hover {
      text-decoration: underline;
    }

    @media (max-width: 480px) {
      .nav {
        padding: 14px 20px;
      }

      .auth-top {
        padding: 28px 24px 22px;
      }

      .auth-body {
        padding: 22px 24px 28px;
      }

      .otp-input {
        width: 42px;
        height: 48px;
        font-size: 1.25rem;
      }
    }
  </style>
</head>

<body>

  <nav class="nav">
    <a href="<?= APP_URL ?>/menu.php" class="nav-logo">
      <div class="nav-logo-icon"><img src="../assets/images/logo.png" alt=""></div>
      <div>
        <div class="nav-logo-name"><?= APP_NAME ?></div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/login.php" class="nav-back">
      <i class="fa-solid fa-arrow-left"></i> Back to Login
    </a>
  </nav>

  <div class="page-wrap">
    <div class="auth-card">

      <div class="auth-top">
        <div class="auth-logo">
          <?php if ($step === 'otp'): ?>
            <i class="fa-solid fa-envelope-circle-check"></i>
          <?php else: ?>
            <i class="fa-solid fa-lock"></i>
          <?php endif; ?>
        </div>
        <div class="auth-title">
          <?php if ($step === 'otp'): ?>
            Verify your <em>identity</em>
          <?php else: ?>
            Create new <em>password</em>
          <?php endif; ?>
        </div>
        <div class="auth-sub">
          <?php if ($step === 'otp'): ?>
            Enter the code sent to your email
          <?php else: ?>
            Enter your new password below
          <?php endif; ?>
        </div>
      </div>

      <div class="auth-body">

        <?php if (!empty($error)): ?>
          <div class="auth-alert auth-alert-danger">
            <i class="fa-solid fa-circle-xmark"></i> <?= e($error) ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
          <div class="auth-alert auth-alert-success">
            <i class="fa-solid fa-circle-check"></i> <?= e($success) ?>
          </div>
        <?php endif; ?>

        <?php if ($step === 'otp'): ?>
          <!-- OTP Step -->
          <div class="email-hint">
            We sent a verification code to<br>
            <strong><?= e($maskedEmail) ?></strong>
          </div>

          <form method="POST" action="" id="otp-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="verify_otp">

            <div class="otp-container">
              <?php for ($i = 0; $i < OTP_LENGTH; $i++): ?>
                <input type="text" class="otp-input" name="otp_<?= $i ?>" maxlength="1"
                  inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code"
                  <?= $i === 0 ? 'autofocus' : '' ?>>
              <?php endfor; ?>
            </div>

            <button type="submit" class="btn-submit">
              <i class="fa-solid fa-check"></i> Verify Code
            </button>
          </form>

          <form method="POST" action="" id="resend-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn-resend" id="resend-btn" <?= $cooldown > 0 ? 'disabled' : '' ?>>
              <?php if ($cooldown > 0): ?>
                Resend code in <span id="cooldown-timer"><?= $cooldown ?></span>s
              <?php else: ?>
                <i class="fa-solid fa-rotate"></i> Resend Code
              <?php endif; ?>
            </button>
          </form>

        <?php else: ?>
          <!-- Password Step -->
          <form method="POST" action="" id="password-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_password">

            <div class="field-group">
              <label class="field-label">New Password</label>
              <div class="password-wrapper">
                <input type="password" name="new_password" id="new-password" class="field-input"
                  placeholder="At least 8 characters" required autocomplete="new-password">
                <button type="button" class="btn-toggle-password" onclick="togglePassword('new-password', this)" tabindex="-1">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Confirm New Password</label>
              <div class="password-wrapper">
                <input type="password" name="confirm_password" id="confirm-password" class="field-input"
                  placeholder="Repeat password" required autocomplete="new-password">
                <button type="button" class="btn-toggle-password" onclick="togglePassword('confirm-password', this)" tabindex="-1">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>

            <button type="submit" class="btn-submit">
              <i class="fa-solid fa-key"></i> Reset Password
            </button>
          </form>
        <?php endif; ?>

        <div class="auth-footer">
          Remember your password? <a href="<?= APP_URL ?>/login.php">Sign in</a>
        </div>

      </div>
    </div>
  </div>

  <script>
    // OTP input handling (only if in OTP step)
    <?php if ($step === 'otp'): ?>
      const otpInputs = document.querySelectorAll('.otp-input');

      otpInputs.forEach((input, index) => {
        input.addEventListener('input', function(e) {
          const value = e.target.value;
          if (value.length === 1 && index < otpInputs.length - 1) {
            otpInputs[index + 1].focus();
          }
        });

        input.addEventListener('keydown', function(e) {
          if (e.key === 'Backspace' && !e.target.value && index > 0) {
            otpInputs[index - 1].focus();
          }
        });

        input.addEventListener('paste', function(e) {
          e.preventDefault();
          const paste = (e.clipboardData || window.clipboardData).getData('text');
          const digits = paste.replace(/\D/g, '').slice(0, <?= OTP_LENGTH ?>);

          digits.split('').forEach((digit, i) => {
            if (otpInputs[i]) {
              otpInputs[i].value = digit;
            }
          });

          if (digits.length > 0) {
            otpInputs[Math.min(digits.length, otpInputs.length - 1)].focus();
          }
        });
      });

      document.getElementById('otp-form').addEventListener('submit', function(e) {
        const otp = Array.from(otpInputs).map(input => input.value).join('');
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'otp';
        hiddenInput.value = otp;
        this.appendChild(hiddenInput);
      });

      <?php if ($cooldown > 0): ?>
        let remaining = <?= $cooldown ?>;
        const timerEl = document.getElementById('cooldown-timer');
        const btnEl = document.getElementById('resend-btn');

        const interval = setInterval(() => {
          remaining--;
          if (remaining <= 0) {
            clearInterval(interval);
            btnEl.disabled = false;
            btnEl.innerHTML = '<i class="fa-solid fa-rotate"></i> Resend Code';
          } else {
            timerEl.textContent = remaining;
          }
        }, 1000);
      <?php endif; ?>
    <?php endif; ?>

    // Password toggle
    function togglePassword(inputId, btn) {
      const input = document.getElementById(inputId);
      const icon = btn.querySelector('i');

      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    }
  </script>

</body>

</html>