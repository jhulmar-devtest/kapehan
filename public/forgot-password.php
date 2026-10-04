<?php
// ============================================================
// public/forgot-password.php
//
// WHAT THIS FILE DOES:
//   Password reset request page for students, faculty, and cashiers.
//   Admin password reset is NOT included (admin manages their own).
//
// FLOW:
//   User selects role → enters email → receives OTP → redirected to reset page
// ============================================================

require_once __DIR__ . '/../config/init.php';

if (isLoggedIn()) redirectByRole();

$error    = '';
$success  = '';
$selected = $_GET['role'] ?? ROLE_STUDENT;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();

  $role  = $_POST['role'] ?? '';
  $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);

  // Validate role (not admin)
  if (!in_array($role, [ROLE_STUDENT, ROLE_FACULTY, ROLE_CASHIER], true)) {
    $error = 'Invalid role selected.';
  } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please enter a valid email address.';
  } else {
    // Look up user by email
    $db = Database::getInstance();
    $table = match ($role) {
      ROLE_STUDENT => 'students',
      ROLE_FACULTY => 'faculty',
      ROLE_CASHIER => 'cashiers',
      default => null
    };

    if ($table) {
      $stmt = $db->prepare("SELECT id, email, full_name FROM {$table} WHERE email = ? LIMIT 1");
      $stmt->execute([$email]);
      $user = $stmt->fetch();

      if ($user) {
        // Generate and send OTP
        $otp = generateOtp();
        storeOtp($role, (int)$user['id'], $email, $otp, 'password_reset');
        $emailResult = sendOtpEmail($email, $otp, 'password_reset');

        // Store in session for reset page
        $_SESSION['password_reset'] = [
          'user_type' => $role,
          'user_id'   => (int)$user['id'],
          'email'     => $email,
        ];

        // Redirect to reset page (always, to prevent email enumeration)
        redirect(APP_URL . '/reset-password.php');
      }
    }

    // Generic message to prevent email enumeration
    $success = 'If an account exists with that email, a verification code has been sent.';
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password — <?= APP_NAME ?></title>
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

    .role-label {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--land-muted);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 10px;
    }

    .role-tabs {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      margin-bottom: 24px;
    }

    .role-tab {
      padding: 10px 6px;
      border-radius: 10px;
      border: 1.5px solid var(--land-border);
      background: rgba(107, 62, 38, 0.04);
      cursor: pointer;
      font-size: 0.74rem;
      font-weight: 600;
      text-align: center;
      color: var(--land-muted);
      transition: all 0.15s;
      font-family: inherit;
    }

    .role-tab i {
      display: block;
      font-size: 16px;
      margin-bottom: 5px;
      opacity: 0.7;
    }

    .role-tab:hover:not(.active) {
      border-color: rgba(107, 62, 38, 0.25);
      color: var(--land-text);
      background: rgba(107, 62, 38, 0.07);
    }

    .role-tab.active {
      border-color: var(--primary-color);
      background: rgba(192, 57, 43, 0.12);
      color: var(--land-text);
      box-shadow: 0 0 0 1px rgba(192, 57, 43, 0.3);
    }

    .role-tab.active i {
      opacity: 1;
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
      margin-top: 8px;
      letter-spacing: 0.01em;
    }

    .btn-submit:hover {
      background: var(--primary-dark);
      transform: translateY(-1px);
      box-shadow: 0 8px 26px rgba(192, 57, 43, 0.50);
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
        <div class="auth-logo"><i class="fa-solid fa-key"></i></div>
        <div class="auth-title">Forgot your <em>password</em>?</div>
        <div class="auth-sub">Enter your email to receive a reset code</div>
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

        <form method="POST" action="">
          <?= csrfField() ?>

          <div class="role-label">I am a&hellip;</div>
          <div class="role-tabs">
            <button type="button" class="role-tab <?= $selected === ROLE_STUDENT ? 'active' : '' ?>"
              onclick="selectRole('<?= ROLE_STUDENT ?>')">
              <i class="fa-solid fa-graduation-cap"></i>Student
            </button>
            <button type="button" class="role-tab <?= $selected === ROLE_FACULTY ? 'active' : '' ?>"
              onclick="selectRole('<?= ROLE_FACULTY ?>')">
              <i class="fa-solid fa-chalkboard-user"></i>Faculty
            </button>
            <button type="button" class="role-tab <?= $selected === ROLE_CASHIER ? 'active' : '' ?>"
              onclick="selectRole('<?= ROLE_CASHIER ?>')">
              <i class="fa-solid fa-cash-register"></i>Cashier
            </button>
          </div>
          <input type="hidden" name="role" id="role-input" value="<?= e($selected) ?>">

          <div class="field-group">
            <label class="field-label">Email Address</label>
            <input type="email" name="email" class="field-input"
              placeholder="your.email@example.com" required
              value="<?= e($_POST['email'] ?? '') ?>">
          </div>

          <button type="submit" class="btn-submit">
            <i class="fa-solid fa-paper-plane"></i> Send Reset Code
          </button>
        </form>

        <div class="auth-footer">
          Remember your password? <a href="<?= APP_URL ?>/login.php">Sign in</a>
        </div>

      </div>
    </div>
  </div>

  <script>
    function selectRole(role) {
      document.getElementById('role-input').value = role;
      document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
      event.currentTarget.classList.add('active');
    }
  </script>

</body>

</html>