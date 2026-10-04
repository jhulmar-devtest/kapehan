<?php
// ============================================================
// public/register.php
//
// Self-registration for BOTH students and faculty — branches on
// $_POST['account_type']. Replaces the old student-only register.php
// plus register-faculty.php (that file can now be deleted once this
// is confirmed working; nothing links to it anymore).
//
// GET requests bounce to the shop page with the register modal open.
// ============================================================

require_once __DIR__ . '/../config/init.php';

if (isLoggedIn()) redirectByRole();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect(APP_URL . '/menu.php?auth=register');
}

verifyCsrf();

$accountType = ($_POST['account_type'] ?? '') === 'faculty' ? ROLE_FACULTY : ROLE_STUDENT;

$old = [];
$old['full_name'] = sanitizeString($_POST['full_name'] ?? '');
$old['id_no']      = sanitizeString($_POST['id_no'] ?? '');
$old['course']     = sanitizeString($_POST['course'] ?? ''); // students only
$old['email']      = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$password  = $_POST['password'] ?? '';
$confirm   = $_POST['confirm_password'] ?? '';
$declared  = isset($_POST['id_declaration']);

$errors = [];
if (empty($old['full_name'])) $errors[] = 'Full name is required.';
if (empty($old['id_no']))     $errors[] = ($accountType === ROLE_STUDENT ? 'Student' : 'Faculty') . ' ID number is required.';
if (empty($old['email']))     $errors[] = 'Email address is required.';
if (!$declared)                $errors[] = 'You must confirm that the ID provided is correct.';

if (empty($errors) && !empty($old['id_no'])) {
  $idError = $accountType === ROLE_STUDENT
    ? validateStudentId($old['id_no'])
    : validateFacultyId($old['id_no']);
  if ($idError) $errors[] = $idError;
}

if (empty($errors) && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
  $errors[] = 'Please enter a valid email address.';
}

$pwError = validatePassword($password);
if ($pwError) $errors[] = $pwError;
if ($password !== $confirm) $errors[] = 'Passwords do not match.';

$table    = $accountType === ROLE_STUDENT ? 'students' : 'faculty';
$idColumn = $accountType === ROLE_STUDENT ? 'student_id_no' : 'faculty_id_no';

if (empty($errors)) {
  $db   = Database::getInstance();
  $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$idColumn} = ? LIMIT 1");
  $stmt->execute([$old['id_no']]);
  if ($stmt->fetch()) {
    $errors[] = 'That ID number is already registered. Please sign in instead.';
  }
}

if (empty($errors) && !empty($old['email'])) {
  $db   = Database::getInstance();
  $stmt = $db->prepare("SELECT id FROM {$table} WHERE email = ? LIMIT 1");
  $stmt->execute([$old['email']]);
  if ($stmt->fetch()) {
    $errors[] = 'That email is already registered. Please use a different email.';
  }
}

if (!empty($errors)) {
  flash('global', implode(' ', $errors), 'error');
  $_SESSION['register_old'] = $old + ['account_type' => $accountType];
  redirect(APP_URL . '/menu.php?auth=register');
}

$db   = Database::getInstance();
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

try {
  if ($accountType === ROLE_STUDENT) {
    $stmt = $db->prepare(
      "INSERT INTO students (full_name, student_id_no, course, email, password, id_declaration, email_verified)
       VALUES (?, ?, ?, ?, ?, 1, 0)"
    );
    $stmt->execute([$old['full_name'], $old['id_no'], $old['course'], $old['email'], $hash]);
  } else {
    $stmt = $db->prepare(
      "INSERT INTO faculty (full_name, faculty_id_no, email, password, id_declaration, email_verified)
       VALUES (?, ?, ?, ?, 1, 0)"
    );
    $stmt->execute([$old['full_name'], $old['id_no'], $old['email'], $hash]);
  }

  $newId = (int) $db->lastInsertId();

  $otp = generateOtp();
  storeOtp($accountType, $newId, $old['email'], $otp, 'verification');
  $emailResult = sendOtpEmail($old['email'], $otp, 'verification');
  if (!$emailResult['success']) {
    flash('global', 'Account created but the verification email could not be sent. Please contact support.', 'warning');
  }

  $_SESSION['pending_verification'] = [
    'user_type' => $accountType,
    'user_id'   => $newId,
    'email'     => $old['email'],
    'purpose'   => 'verification',
  ];

  auditLog($accountType, $newId, 'register');
  redirect(APP_URL . '/verify-email.php');
} catch (PDOException $e) {
  error_log('Registration error: ' . $e->getMessage());
  flash('global', 'Registration failed. Please try again.', 'error');
  redirect(APP_URL . '/menu.php?auth=register');
}
