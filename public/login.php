<?php
// ============================================================
// public/login.php
//
// Handles STUDENT and FACULTY login only (Admin/Cashier use
// public/staff-login.php — see Phase 3 of the rebuild guide).
//
// The identifier field accepts EITHER the student/faculty ID
// number OR the email used at registration — no role dropdown.
// We try the students table, then the faculty table.
//
// GET requests (someone bookmarked/typed this URL directly)
// just bounce to the shop page with the sign-in modal open —
// the actual login form now lives in includes/auth_modal.php.
// ============================================================

require_once __DIR__ . '/../config/init.php';

if (isLoggedIn()) {
  redirectByRole();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect(APP_URL . '/menu.php?auth=login');
}

verifyCsrf();

$identifier = sanitizeString($_POST['identifier'] ?? '');
$password   = $_POST['password'] ?? '';

if (empty($identifier) || empty($password)) {
  flash('global', 'Please fill in all fields.', 'error');
  redirect(APP_URL . '/menu.php?auth=login');
}

if (!checkLoginAttempts($identifier)) {
  flash('global', 'Too many failed attempts. Please wait 15 minutes.', 'error');
  redirect(APP_URL . '/menu.php?auth=login');
}

$db   = Database::getInstance();
$user = null;
$role = null;

$stmt = $db->prepare("SELECT * FROM students WHERE (student_id_no = ? OR email = ?) AND is_active = 1 LIMIT 1");
$stmt->execute([$identifier, $identifier]);
if ($found = $stmt->fetch()) {
  $user = $found;
  $role = ROLE_STUDENT;
}

if (!$user) {
  $stmt = $db->prepare("SELECT * FROM faculty WHERE (faculty_id_no = ? OR email = ?) AND is_active = 1 LIMIT 1");
  $stmt->execute([$identifier, $identifier]);
  if ($found = $stmt->fetch()) {
    $user = $found;
    $role = ROLE_FACULTY;
  }
}

if ($user && password_verify($password, $user['password'])) {
  if (empty($user['email_verified']) || $user['email_verified'] != 1) {
    $_SESSION['pending_verification'] = [
      'user_type' => $role,
      'user_id'   => $user['id'],
      'email'     => $user['email'],
      'purpose'   => 'verification',
    ];
    flash('global', 'Please verify your email address to continue.', 'warning');
    redirect(APP_URL . '/verify-email.php');
  }

  clearLoginAttempts($identifier);
  loginUser($user, $role);
  auditLog($role, $user['id'], 'login');
  redirectByRole();
} else {
  recordFailedLogin($identifier);
  flash('global', 'Incorrect ID/email or password. Please try again.', 'error');
  redirect(APP_URL . '/menu.php?auth=login');
}
