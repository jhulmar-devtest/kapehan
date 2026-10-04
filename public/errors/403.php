<?php
// ============================================================
// public/errors/403.php
//
// Shown by requireRole() (includes/auth.php) when a logged-in user's
// role doesn't match what a page requires. This file previously did
// not exist at all — every role-mismatch hit a raw PHP "failed to
// open stream" include warning instead of an actual message, since
// requireRole() had already called http_response_code(403) and this
// was the only thing left to render before exit.
//
// Deliberately self-contained (no layoutHeader()): the whole point of
// this page is that the visitor may not be entitled to whatever
// chrome/nav layoutHeader() would try to build for their session.
// ============================================================
$backUrl = APP_URL . '/menu.php';
if (function_exists('currentRole')) {
  switch (currentRole()) {
    case defined('ROLE_ADMIN') ? ROLE_ADMIN : '__none__':
      $backUrl = APP_URL . '/admin/sales.php';
      break;
    case defined('ROLE_CASHIER') ? ROLE_CASHIER : '__none__':
      $backUrl = APP_URL . '/cashier/walkin.php';
      break;
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Denied — <?= defined('APP_NAME') ? APP_NAME : 'Kapehan ni Amang' ?></title>
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
      padding: 24px;
    }

    .error-card {
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-md);
      max-width: 420px;
      width: 100%;
      padding: 40px 32px;
      text-align: center;
    }

    .error-icon {
      width: 64px;
      height: 64px;
      border-radius: var(--radius-full);
      background: var(--color-error-bg);
      color: var(--color-error);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      margin: 0 auto 20px;
    }

    h1 {
      font-size: 20px;
      font-weight: 700;
      margin-bottom: 8px;
    }

    p {
      font-size: 14px;
      color: var(--text-muted);
      line-height: 1.5;
      margin-bottom: 24px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--primary-color);
      color: var(--text-on-primary);
      border: none;
      border-radius: var(--radius-sm);
      padding: 11px 22px;
      font: inherit;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      transition: background var(--transition-fast);
    }

    .btn:hover {
      background: var(--primary-dark);
    }

    .btn:focus-visible {
      outline: 2px solid var(--primary-color);
      outline-offset: 2px;
    }
  </style>
</head>

<body>
  <div class="error-card">
    <div class="error-icon"><i class="fa-solid fa-lock"></i></div>
    <h1>Access Denied</h1>
    <p>You don't have permission to view this page. If you think this is a mistake, please contact staff.</p>
    <a class="btn" href="<?= e($backUrl) ?>"><i class="fa-solid fa-arrow-left"></i> Back to safety</a>
  </div>
</body>

</html>