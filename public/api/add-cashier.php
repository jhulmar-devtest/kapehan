<?php
// ============================================================
// public/api/add-cashier.php
//
// The ONLY way a new cashier account gets created. An admin must
// type their own username+password inline, right on this form —
// that IS the approval step (item 11). No separate "pending
// approval" queue to check later; either an admin is standing
// there authorizing it right now, or the account doesn't get made.
// ============================================================

require_once __DIR__ . '/../../config/init.php';

if (isLoggedIn()) redirectByRole();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect(APP_URL . '/cashier-select.php');
}

verifyCsrf();

$db = Database::getInstance();

$fullName = sanitizeString($_POST['full_name'] ?? '');
$username = sanitizeString($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$adminUsername = sanitizeString($_POST['admin_username'] ?? '');
$adminPassword = $_POST['admin_password'] ?? '';

$errors = [];
if (empty($fullName)) $errors[] = 'Full name is required.';
if (empty($username))  $errors[] = 'Username is required.';
if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

if (empty($errors)) {
  $stmt = $db->prepare("SELECT id FROM cashiers WHERE username = ? LIMIT 1");
  $stmt->execute([$username]);
  if ($stmt->fetch()) $errors[] = 'That username is already taken.';
}

if (!empty($errors)) {
  $_SESSION['pos_add_error'] = implode(' ', $errors);
  redirect(APP_URL . '/cashier-select.php');
}

// This IS the approval step — verify real admin credentials right here.
if (!checkLoginAttempts('admin_verify_' . $adminUsername)) {
  $_SESSION['pos_add_error'] = 'Too many failed admin verification attempts. Please wait 15 minutes.';
  redirect(APP_URL . '/cashier-select.php');
}

$stmt = $db->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
$stmt->execute([$adminUsername]);
$admin = $stmt->fetch();

if (!$admin || !password_verify($adminPassword, $admin['password'])) {
  recordFailedLogin('admin_verify_' . $adminUsername);
  $_SESSION['pos_add_error'] = 'Admin verification failed. Cashier account not created.';
  redirect(APP_URL . '/cashier-select.php');
}
clearLoginAttempts('admin_verify_' . $adminUsername);

try {
  $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
  $stmt = $db->prepare(
    "INSERT INTO cashiers (full_name, username, password, is_active, created_by)
     VALUES (?, ?, ?, 1, ?)"
  );
  $stmt->execute([$fullName, $username, $hash, $admin['id']]);
  $newId = (int) $db->lastInsertId();

  auditLog(ROLE_ADMIN, $admin['id'], 'create_cashier', 'cashiers', $newId);
  $_SESSION['pos_add_success'] = "Cashier account for {$fullName} created.";
} catch (PDOException $e) {
  error_log('add-cashier failed: ' . $e->getMessage());
  $_SESSION['pos_add_error'] = 'Something went wrong creating the account. Please try again.';
}

redirect(APP_URL . '/cashier-select.php');
