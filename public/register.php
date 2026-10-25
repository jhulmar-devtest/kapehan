<?php
// ============================================================
// public/register.php
//
// Self-registration for BOTH students and faculty. The account type is
// no longer chosen by the user: it is inferred from the ID number format
// (student 2316-00001C vs faculty 2023-0001 — the two never overlap).
// Course is no longer collected at signup (students can add it later
// from their profile). Replaces the old student-only register.php
// plus register-faculty.php.
//
// GET requests bounce to the shop page with the register modal open.
// ============================================================

require_once __DIR__ . '/../config/init.php';

if (isLoggedIn()) redirectByRole();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect(APP_URL . '/menu.php?auth=register');
}

verifyCsrf();

$old = [];
$old['full_name'] = sanitizeString($_POST['full_name'] ?? '');
$old['id_no']      = strtoupper(sanitizeString($_POST['id_no'] ?? ''));
$old['email']      = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$password  = $_POST['password'] ?? '';
$confirm   = $_POST['confirm_password'] ?? '';
$declared  = isset($_POST['id_declaration']);
$privacyNoticeAccepted = isset($_POST['privacy_notice_accepted']);

// Infer the account type from the ID format. The role is decided here on
// the server from the ID itself — never from a submitted form field.
$accountType = null;
if (validateStudentId($old['id_no']) === null)      $accountType = ROLE_STUDENT;
elseif (validateFacultyId($old['id_no']) === null)  $accountType = ROLE_FACULTY;

$errors = [];
if (empty($old['full_name'])) $errors[] = 'Full name is required.';
if (empty($old['id_no']))     $errors[] = 'ID number is required.';
if (empty($old['email']))     $errors[] = 'Email address is required.';
if (!$declared)                $errors[] = 'You must confirm that the ID provided is correct.';
if (!$privacyNoticeAccepted)   $errors[] = 'Please review and acknowledge the information use notice.';

if (empty($errors) && $accountType === null) {
  $errors[] = 'ID number not recognized. Students use a format like 2316-00001C; faculty use a format like 2023-0001.';
}

if (empty($errors) && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
  $errors[] = 'Please enter a valid email address.';
}

$pwError = validatePassword($password);
if ($pwError) $errors[] = $pwError;
if ($password !== $confirm) $errors[] = 'Passwords do not match.';

if (empty($errors)) {
  $db = Database::getInstance();
  expireUnverifiedRegistrations($db);
  $table    = $accountType === ROLE_STUDENT ? 'students' : 'faculty';
  $idColumn = $accountType === ROLE_STUDENT ? 'student_id_no' : 'faculty_id_no';

  $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$idColumn} = ? LIMIT 1");
  $stmt->execute([$old['id_no']]);
  if ($stmt->fetch()) {
    $errors[] = 'That ID number is already registered. Please sign in or report an ID conflict if you believe it is being used by someone else.';
  }
}

if (empty($errors) && !empty($old['email'])) {
  $table = $accountType === ROLE_STUDENT ? 'students' : 'faculty';
  $db   = Database::getInstance();
  $stmt = $db->prepare("SELECT id FROM {$table} WHERE email = ? LIMIT 1");
  $stmt->execute([$old['email']]);
  if ($stmt->fetch()) {
    $errors[] = 'That email is already registered. Please use a different email.';
  }
}

if (!empty($errors)) {
  flash('global', implode(' ', $errors), 'error');
  $_SESSION['register_old'] = $old;
  redirect(APP_URL . '/menu.php?auth=register');
}

$db   = Database::getInstance();
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

try {
  if ($accountType === ROLE_STUDENT) {
    $stmt = $db->prepare(
      // course is NOT NULL in the schema and is no longer asked at signup;
      // store '' (shown as "Not set" in the profile) so no migration is needed.
      "INSERT INTO students (full_name, student_id_no, course, email, password, id_declaration, privacy_notice_accepted_at, email_verified, registration_expires_at)
       VALUES (?, ?, '', ?, ?, 1, NOW(), 0, DATE_ADD(NOW(), INTERVAL 24 HOUR))"
    );
    $stmt->execute([$old['full_name'], $old['id_no'], $old['email'], $hash]);
  } else {
    $stmt = $db->prepare(
      "INSERT INTO faculty (full_name, faculty_id_no, email, password, id_declaration, privacy_notice_accepted_at, email_verified, registration_expires_at)
       VALUES (?, ?, ?, ?, 1, NOW(), 0, DATE_ADD(NOW(), INTERVAL 24 HOUR))"
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
