<?php
// ============================================================
// public/verify-email.php
//
// WHAT THIS FILE DOES:
//   OTP verification page for students and faculty after registration.
//   Displays masked email and accepts 6-digit OTP input.
//   Redirects to login after successful verification.
// ============================================================

require_once __DIR__ . '/../config/init.php';

// Must have pending verification in session
if (!isset($_SESSION['pending_verification'])) {
  redirect(APP_URL . '/login.php');
}

$pending = $_SESSION['pending_verification'];
$userType = $pending['user_type'];
$userId   = $pending['user_id'];
$email    = $pending['email'];
$purpose  = $pending['purpose'];

$error    = '';
$success  = '';
$cooldown = getOtpCooldownRemaining($userType, $userId, $purpose);
$maskedEmail = maskEmail($email);

// Handle OTP submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();

  $action = $_POST['action'] ?? '';

  if ($action === 'verify') {
    $otp = trim($_POST['otp'] ?? '');
    $otp = preg_replace('/[^0-9]/', '', $otp); // Remove non-digits

    if (strlen($otp) !== OTP_LENGTH) {
      $error = 'Please enter all ' . OTP_LENGTH . ' digits of the verification code.';
    } else {
      $result = validateOtp($userType, $userId, $otp, $purpose);

      if ($result['valid']) {
        // Mark email as verified in database
        $db = Database::getInstance();
        $table = match ($userType) {
          ROLE_STUDENT => 'students',
          ROLE_FACULTY => 'faculty',
          ROLE_CASHIER => 'cashiers',
          default => null
        };

        if ($table) {
          $stmt = $db->prepare("UPDATE {$table} SET email_verified = 1, email_verified_at = NOW() WHERE id = ?");
          $stmt->execute([$userId]);
        }

        // Clear pending verification from session
        unset($_SESSION['pending_verification']);

        auditLog($userType, $userId, 'email_verified');
        flash('global', 'Email verified successfully! You can now log in.');
        redirect(APP_URL . '/login.php');
      } else {
        $error = $result['message'];
      }
    }
  }

  if ($action === 'resend') {
    if ($cooldown > 0) {
      $error = "Please wait {$cooldown} seconds before requesting a new code.";
    } else {
      // Generate and send new OTP
      $otp = generateOtp();
      storeOtp($userType, $userId, $email, $otp, $purpose);
      $emailResult = sendOtpEmail($email, $otp, $purpose);

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
  <title>Verify Email — <?= APP_NAME ?></title>
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

    body::after {
      content: '';
      position: fixed;
      top: 30%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 600px;
      height: 500px;
      background: radial-gradient(ellipse, rgba(192, 57, 43, 0.05) 0%, transparent 70%);
      pointer-events: none;
      z-index: 0;
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

    .auth-top::before {
      content: '';
      position: absolute;
      top: -40px;
      left: 50%;
      transform: translateX(-50%);
      width: 200px;
      height: 200px;
      background: radial-gradient(circle, rgba(192, 57, 43, 0.07), transparent 70%);
      pointer-events: none;
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
      position: relative;
      z-index: 1;
    }

    .auth-sub {
      font-size: 0.76rem;
      color: var(--land-muted);
      font-weight: 300;
      position: relative;
      z-index: 1;
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

    .otp-input::-webkit-outer-spin-button,
    .otp-input::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
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

    .btn-submit:active:not(:disabled) {
      transform: translateY(0);
    }

    .btn-submit:disabled {
      opacity: 0.6;
      cursor: not-allowed;
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
    <a href="<?= APP_URL ?>/login.php" class="nav-back" style="font-size: 0.78rem; font-weight: 500; color: var(--land-muted); display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px; border-radius: 7px; border: 1px solid var(--land-border);">
      <i class="fa-solid fa-arrow-left"></i> Back to Login
    </a>
  </nav>

  <div class="page-wrap">
    <div class="auth-card">

      <div class="auth-top">
        <div class="auth-logo"><i class="fa-solid fa-envelope-circle-check"></i></div>
        <div class="auth-title">Verify your <em>email</em></div>
        <div class="auth-sub">Enter the code sent to your email</div>
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

        <div class="email-hint">
          We sent a verification code to<br>
          <strong><?= e($maskedEmail) ?></strong>
        </div>

        <form method="POST" action="" id="otp-form">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="verify">

          <div class="otp-container">
            <?php for ($i = 0; $i < OTP_LENGTH; $i++): ?>
              <input type="text" class="otp-input" name="otp_<?= $i ?>" maxlength="1"
                inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code"
                <?= $i === 0 ? 'autofocus' : '' ?>>
            <?php endfor; ?>
          </div>

          <button type="submit" class="btn-submit">
            <i class="fa-solid fa-check"></i> Verify Email
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

        <div class="auth-footer">
          Didn't receive the code? Check your spam folder or <a href="<?= APP_URL ?>/login.php">try logging in</a>
        </div>

      </div>
    </div>
  </div>

  <script>
    // OTP input auto-focus and paste handling
    const otpInputs = document.querySelectorAll('.otp-input');

    otpInputs.forEach((input, index) => {
      // Auto-focus next input
      input.addEventListener('input', function(e) {
        const value = e.target.value;
        if (value.length === 1 && index < otpInputs.length - 1) {
          otpInputs[index + 1].focus();
        }
      });

      // Handle backspace
      input.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && !e.target.value && index > 0) {
          otpInputs[index - 1].focus();
        }
      });

      // Handle paste
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

    // Form submission - collect all digits
    document.getElementById('otp-form').addEventListener('submit', function(e) {
      const otp = Array.from(otpInputs).map(input => input.value).join('');
      const hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = 'otp';
      hiddenInput.value = otp;
      this.appendChild(hiddenInput);
    });

    // Cooldown timer
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
  </script>

</body>

</html>