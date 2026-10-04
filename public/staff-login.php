<?php
// ============================================================
// public/staff-login.php
//
// ADMIN LOGIN ONLY. Cashiers do not use this page — they sign
// in on public/cashier-select.php by picking their profile and
// entering their password (Phase 4 of the rebuild guide). Keeping
// admin and cashier entry points fully separate means a cashier
// never sees (or needs) a username field, and an admin never sees
// the POS profile grid.
// ============================================================

require_once __DIR__ . '/../config/init.php';

if (isLoggedIn()) {
  redirectByRole();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();

  $username = sanitizeString($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if (empty($username) || empty($password)) {
    $error = 'Please fill in all fields.';
  } elseif (!checkLoginAttempts('staff_' . $username)) {
    $error = 'Too many failed attempts. Please wait 15 minutes.';
  } else {
    $db   = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
      clearLoginAttempts('staff_' . $username);
      loginUser($user, ROLE_ADMIN);
      auditLog(ROLE_ADMIN, $user['id'], 'login');
      redirectByRole();
    } else {
      recordFailedLogin('staff_' . $username);
      $error = 'Incorrect username or password.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Login — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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

    body {
      font-family: 'DM Sans', system-ui, sans-serif;
      background: var(--background-color);
      color: var(--text-color);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .card {
      background: #fff;
      border: 1px solid var(--border-color);
      border-radius: 18px;
      padding: 32px;
      width: 100%;
      max-width: 380px;
    }

    .card-icon {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      background: var(--surface-raised);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      color: var(--text-secondary);
      margin-bottom: 18px;
    }

    h1 {
      font-size: 19px;
      margin-bottom: 4px;
    }

    .subtitle {
      font-size: 13.5px;
      color: var(--text-muted);
      margin-bottom: 22px;
    }

    label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      margin: 14px 0 6px;
      color: var(--text-secondary);
    }

    input {
      width: 100%;
      padding: 11px 13px;
      border: 1px solid var(--border-color);
      border-radius: 10px;
      font: inherit;
    }

    button {
      width: 100%;
      margin-top: 22px;
      padding: 12px;
      border: none;
      border-radius: 10px;
      background: var(--primary-color);
      color: #fff;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
    }

    button:hover {
      background: var(--primary-dark);
    }

    .error-box {
      background: #fdecea;
      color: #b3261e;
      border-radius: 10px;
      padding: 11px 14px;
      font-size: 13.5px;
      margin-bottom: 6px;
    }

    .back-link {
      display: block;
      text-align: center;
      margin-top: 18px;
      font-size: 13px;
      color: var(--text-muted);
    }
  </style>
</head>

<body>
  <form class="card" method="POST" action="<?= APP_URL ?>/staff-login.php">
    <?= csrfField() ?>
    <div class="card-icon"><i class="fa-solid fa-user-shield"></i></div>
    <h1>Admin Login</h1>
    <p class="subtitle">Cashiers: use the POS profile screen instead.</p>

    <?php if ($error): ?>
      <div class="error-box"><?= e($error) ?></div>
    <?php endif; ?>

    <label>Username</label>
    <input type="text" name="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
    <label>Password</label>
    <input type="password" name="password" required>

    <button type="submit">Sign In</button>
    <a href="<?= APP_URL ?>/cashier-select.php" class="back-link">Cashier? Go to POS profile screen &rarr;</a>
    <a href="<?= APP_URL ?>/menu.php" class="back-link">&larr; Back to the shop</a>
  </form>
</body>

</html>