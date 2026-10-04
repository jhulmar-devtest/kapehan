<?php
// ============================================================
// public/api/cashier-login.php
//
// Authenticates the cashier picked on cashier-select.php against
// their own password. This is the cashier's real, sole login step —
// not a secondary PIN check after some other login.
// ============================================================

require_once __DIR__ . '/../../config/init.php';

if (isLoggedIn()) redirectByRole();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect(APP_URL . '/cashier-select.php');
}

verifyCsrf();

$cashierId = (int) ($_POST['cashier_id'] ?? 0);
$password  = $_POST['password'] ?? '';
$rateKey   = 'cashier_' . $cashierId;

if (!$cashierId || empty($password)) {
  $_SESSION['pos_login_error'] = 'Please enter your password.';
  redirect(APP_URL . '/cashier-select.php');
}

if (!checkLoginAttempts($rateKey)) {
  $_SESSION['pos_login_error'] = 'Too many failed attempts. Please wait 15 minutes, or ask an admin for help.';
  redirect(APP_URL . '/cashier-select.php');
}

$db   = Database::getInstance();
$stmt = $db->prepare("SELECT * FROM cashiers WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$cashierId]);
$cashier = $stmt->fetch();

if ($cashier && password_verify($password, $cashier['password'])) {
  clearLoginAttempts($rateKey);
  loginUser($cashier, ROLE_CASHIER);
  auditLog(ROLE_CASHIER, $cashier['id'], 'login');
  redirectByRole();
} else {
  recordFailedLogin($rateKey);
  $_SESSION['pos_login_error'] = 'Incorrect password. Please try again.';
  redirect(APP_URL . '/cashier-select.php');
}
