<?php
require_once __DIR__ . '/../config/init.php';

$errors = [];
$submitted = false;
$fullName = '';
$schoolId = '';
$contactEmail = '';
$details = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();
  $fullName = sanitizeString($_POST['full_name'] ?? '', 100);
  $schoolId = strtoupper(sanitizeString($_POST['school_id_no'] ?? '', 30));
  $contactEmail = filter_var(trim((string) ($_POST['contact_email'] ?? '')), FILTER_SANITIZE_EMAIL);
  $details = sanitizeString($_POST['details'] ?? '', 1000);

  $studentIdError = validateStudentId($schoolId);
  $facultyIdError = validateFacultyId($schoolId);
  $userType = $studentIdError === null ? ROLE_STUDENT : ($facultyIdError === null ? ROLE_FACULTY : null);

  if ($fullName === '') $errors[] = 'Enter your name.';
  if ($userType === null) $errors[] = 'Enter a valid student or faculty ID.';
  if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address where staff can contact you.';
  if (!empty($_SESSION['id_conflict_reported_at']) && time() - (int) $_SESSION['id_conflict_reported_at'] < 60) {
    $errors[] = 'Please wait a minute before submitting another report.';
  }

  if (!$errors) {
    $db = Database::getInstance();
    $config = $userType === ROLE_STUDENT
      ? ['table' => 'students', 'identifier' => 'student_id_no']
      : ['table' => 'faculty', 'identifier' => 'faculty_id_no'];
    $account = $db->prepare("SELECT id FROM {$config['table']} WHERE {$config['identifier']} = ? LIMIT 1");
    $account->execute([$schoolId]);

    if (!$account->fetchColumn()) {
      $errors[] = 'We could not find an account currently using that ID. Try registering again; if the problem continues, contact an administrator.';
    } else {
      $existingReport = $db->prepare(
        "SELECT id FROM registration_conflict_reports
         WHERE user_type = ? AND school_id_no = ? AND contact_email = ? AND status = 'pending' LIMIT 1"
      );
      $existingReport->execute([$userType, $schoolId, $contactEmail]);
      if ($existingReport->fetchColumn()) {
        $submitted = true;
      } else {
        $db->prepare(
          'INSERT INTO registration_conflict_reports (user_type, school_id_no, full_name, contact_email, details) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userType, $schoolId, $fullName, $contactEmail, $details !== '' ? $details : null]);
        $_SESSION['id_conflict_reported_at'] = time();
        $submitted = true;
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
  <title>Report an ID Conflict — <?= e(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/variables.css">
  <style>
    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      min-height: 100vh;
      padding: 32px 16px;
      display: grid;
      place-items: center;
      background: var(--background-color);
      color: var(--text-color);
      font-family: "DM Sans", system-ui, sans-serif
    }

    .report-card {
      width: min(100%, 560px);
      padding: 28px;
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lg)
    }

    .report-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 24px
    }

    .report-brand img {
      width: 44px;
      height: 44px;
      object-fit: contain;
      border-radius: 50%;
      background: var(--primary-color)
    }

    .report-brand strong {
      display: block
    }

    .report-brand small {
      color: var(--text-muted)
    }

    h1 {
      margin: 0 0 8px;
      font-size: 1.45rem
    }

    .intro {
      margin: 0 0 22px;
      color: var(--text-secondary);
      font-size: .92rem;
      line-height: 1.55
    }

    .form-grid {
      display: grid;
      gap: 15px
    }

    .field {
      display: grid;
      gap: 6px
    }

    .field label {
      font-size: .83rem;
      font-weight: 700
    }

    .field input,
    .field textarea {
      width: 100%;
      padding: 11px 12px;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      font: inherit;
      color: var(--text-color);
      background: var(--surface-color)
    }

    .field textarea {
      min-height: 95px;
      resize: vertical
    }

    .notice,
    .alert {
      padding: 12px 14px;
      border-radius: var(--radius-md);
      font-size: .85rem;
      line-height: 1.5
    }

    .notice {
      margin-top: 16px;
      background: var(--surface-raised);
      color: var(--text-secondary)
    }

    .alert {
      margin: 0 0 16px
    }

    .alert-error {
      background: var(--color-error-bg);
      color: var(--color-error)
    }

    .alert-success {
      background: var(--color-success-bg);
      color: var(--color-success)
    }

    .actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-top: 20px
    }

    .actions a {
      color: var(--primary-color);
      font-size: .88rem;
      font-weight: 700
    }

    .submit {
      border: 0;
      border-radius: var(--radius-md);
      padding: 11px 16px;
      background: var(--primary-color);
      color: var(--text-on-primary);
      font: inherit;
      font-weight: 700;
      cursor: pointer
    }

    .submit:hover {
      background: var(--primary-dark)
    }
  </style>
</head>

<body>
  <main class="report-card">
    <div class="report-brand"><img src="<?= APP_URL ?>/../assets/images/logo.png" alt="">
      <div><strong><?= e(APP_NAME) ?></strong><small>Account access help</small></div>
    </div>
    <h1>Report an ID conflict</h1>
    <p class="intro">Use this form if registration says your student or faculty ID is already in use, but you believe you should be able to register.</p>
    <?php if ($submitted): ?>
      <div class="alert alert-success" role="status">Your report was sent for administrator review. Staff must verify your claim through the school’s normal process before changing an account. This form does not release the ID automatically.</div>
      <div class="actions"><a href="<?= APP_URL ?>/menu.php?auth=register">Back to registration</a></div>
    <?php else: ?>
      <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
      <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <div class="field"><label for="conflict-name">Your full name</label><input id="conflict-name" name="full_name" maxlength="100" value="<?= e($fullName) ?>" autocomplete="name" required></div>
        <div class="field"><label for="conflict-id">Student or faculty ID</label><input id="conflict-id" name="school_id_no" maxlength="30" value="<?= e($schoolId) ?>" required></div>
        <div class="field"><label for="conflict-email">Email for follow-up</label><input id="conflict-email" type="email" name="contact_email" maxlength="150" value="<?= e($contactEmail) ?>" autocomplete="email" required></div>
        <div class="field"><label for="conflict-details">Additional details <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label><textarea id="conflict-details" name="details" maxlength="1000" placeholder="Tell staff when you tried to register or how they can reach you."><?= e($details) ?></textarea></div>
        <div class="notice">Do not include your password, verification code, or sensitive documents. Staff will verify your identity separately before releasing an ID.</div>
        <div class="actions"><a href="<?= APP_URL ?>/menu.php?auth=register">Back to registration</a><button class="submit" type="submit">Submit report</button></div>
      </form>
    <?php endif; ?>
  </main>
</body>

</html>