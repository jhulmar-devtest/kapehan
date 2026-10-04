<?php
// ============================================================
// public/cashier-select.php
//
// This IS the cashier login — not a second step after some other
// login. A cashier taps their own profile, enters their password,
// and is taken straight into the POS. No username field, because
// on a shared shop terminal, picking your face is faster and less
// error-prone than typing a username under pressure during a rush.
//
// Adding a new cashier profile requires an admin to type their own
// credentials inline, right here (item 11) — that IS the approval
// step, no separate admin panel visit required.
// ============================================================

require_once __DIR__ . '/../config/init.php';

if (isLoggedIn()) {
  redirectByRole();
}

$db       = Database::getInstance();
$cashiers = $db->query(
  "SELECT id, full_name FROM cashiers WHERE is_active = 1 ORDER BY full_name"
)->fetchAll();

$loginError = $_SESSION['pos_login_error'] ?? '';
unset($_SESSION['pos_login_error']);
$addError   = $_SESSION['pos_add_error'] ?? '';
unset($_SESSION['pos_add_error']);
$addSuccess = $_SESSION['pos_add_success'] ?? '';
unset($_SESSION['pos_add_success']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>POS — Who's using this? — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 32px 20px;
    }

    .heading {
      text-align: center;
      margin-bottom: 28px;
    }

    .heading h1 {
      font-size: 22px;
      margin-bottom: 4px;
    }

    .heading p {
      font-size: 13.5px;
      color: var(--text-muted);
    }

    .profile-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 16px;
      max-width: 640px;
      width: 100%;
    }

    .profile-tile {
      background: #fff;
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 20px 12px;
      text-align: center;
      cursor: pointer;
      transition: transform .15s ease, box-shadow .15s ease;
    }

    .profile-tile:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-sm);
    }

    .avatar-circle {
      width: 58px;
      height: 58px;
      border-radius: 50%;
      background: var(--primary-subtle);
      color: var(--primary-color);
      font-weight: 800;
      font-size: 22px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
    }

    .profile-name {
      font-size: 13.5px;
      font-weight: 600;
    }

    .add-tile {
      border-style: dashed;
      color: var(--text-muted);
    }

    .add-tile .avatar-circle {
      background: var(--surface-raised);
      color: var(--text-muted);
      font-size: 24px;
    }

    .top-error {
      background: #fdecea;
      color: #b3261e;
      border-radius: 10px;
      padding: 11px 16px;
      font-size: 13.5px;
      margin-bottom: 18px;
      max-width: 420px;
      text-align: center;
    }

    .top-success {
      background: #e7f6ec;
      color: #1e7a3d;
      border-radius: 10px;
      padding: 11px 16px;
      font-size: 13.5px;
      margin-bottom: 18px;
      max-width: 420px;
      text-align: center;
    }

    .footer-link {
      margin-top: 26px;
      font-size: 13px;
      color: var(--text-muted);
    }

    .footer-link a {
      color: var(--text-secondary);
      text-decoration: underline;
    }

    /* modals */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .4);
      z-index: 400;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .modal-overlay[hidden] {
      display: none;
    }

    .modal-box {
      background: #fff;
      border-radius: 18px;
      max-width: 380px;
      width: 100%;
      padding: 28px;
      position: relative;
    }

    .modal-close {
      position: absolute;
      top: 14px;
      right: 14px;
      background: none;
      border: none;
      font-size: 22px;
      cursor: pointer;
      color: var(--text-muted);
    }

    .modal-box h2 {
      font-size: 18px;
      margin-bottom: 4px;
    }

    .modal-box .sub {
      font-size: 13px;
      color: var(--text-muted);
      margin-bottom: 18px;
    }

    label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      margin: 12px 0 6px;
      color: var(--text-secondary);
    }

    input {
      width: 100%;
      padding: 11px 13px;
      border: 1px solid var(--border-color);
      border-radius: 10px;
      font: inherit;
    }

    button.submit-btn {
      width: 100%;
      margin-top: 20px;
      padding: 12px;
      border: none;
      border-radius: 10px;
      background: var(--primary-color);
      color: #fff;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
    }

    button.submit-btn:hover {
      background: var(--primary-dark);
    }

    .divider {
      border: none;
      border-top: 1px solid var(--border-color);
      margin: 18px 0 10px;
    }

    .divider-label {
      font-size: 11.5px;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: 6px;
    }
  </style>
</head>

<body>

  <div class="heading">
    <h1>Who's using this?</h1>
    <p>Tap your profile to sign in to the POS.</p>
  </div>

  <?php if ($loginError): ?><div class="top-error"><?= e($loginError) ?></div><?php endif; ?>
  <?php if ($addError): ?><div class="top-error"><?= e($addError) ?></div><?php endif; ?>
  <?php if ($addSuccess): ?><div class="top-success"><?= e($addSuccess) ?></div><?php endif; ?>

  <div class="profile-grid">
    <?php foreach ($cashiers as $c): ?>
      <div class="profile-tile" onclick="openPasswordModal(<?= $c['id'] ?>, <?= htmlspecialchars(json_encode($c['full_name'])) ?>)">
        <div class="avatar-circle"><?= strtoupper(substr($c['full_name'], 0, 1)) ?></div>
        <div class="profile-name"><?= e($c['full_name']) ?></div>
      </div>
    <?php endforeach; ?>
    <div class="profile-tile add-tile" onclick="openAddModal()">
      <div class="avatar-circle"><i class="fa-solid fa-plus"></i></div>
      <div class="profile-name">Add Cashier</div>
    </div>
  </div>

  <div class="footer-link">
    <a href="<?= APP_URL ?>/staff-login.php">Admin login</a> &nbsp;&middot;&nbsp;
    <a href="<?= APP_URL ?>/menu.php">Back to the shop</a>
  </div>

  <!-- Password modal -->
  <div class="modal-overlay" id="pwModal" hidden>
    <form class="modal-box" method="POST" action="<?= APP_URL ?>/api/cashier-login.php">
      <?= csrfField() ?>
      <button type="button" class="modal-close" onclick="closeModal('pwModal')">&times;</button>
      <h2 id="pwModalName">&nbsp;</h2>
      <p class="sub">Enter your password to continue.</p>
      <input type="hidden" name="cashier_id" id="pwCashierId">
      <label>Password</label>
      <input type="password" name="password" required autofocus>
      <button type="submit" class="submit-btn">Sign In</button>
    </form>
  </div>

  <!-- Add cashier modal -->
  <div class="modal-overlay" id="addModal" hidden>
    <form class="modal-box" method="POST" action="<?= APP_URL ?>/api/add-cashier.php">
      <?= csrfField() ?>
      <button type="button" class="modal-close" onclick="closeModal('addModal')">&times;</button>
      <h2>Add Cashier</h2>
      <p class="sub">New profile details:</p>
      <label>Full Name</label>
      <input type="text" name="full_name" required>
      <label>Username</label>
      <input type="text" name="username" required>
      <label>Password</label>
      <input type="password" name="password" required minlength="6">

      <hr class="divider">
      <div class="divider-label">Admin verification required</div>
      <label>Admin Username</label>
      <input type="text" name="admin_username" required>
      <label>Admin Password</label>
      <input type="password" name="admin_password" required>

      <button type="submit" class="submit-btn">Create Cashier Account</button>
    </form>
  </div>

  <script>
    function openPasswordModal(id, name) {
      document.getElementById('pwCashierId').value = id;
      document.getElementById('pwModalName').textContent = name;
      document.getElementById('pwModal').hidden = false;
      document.getElementById('pwModal').querySelector('input[name=password]').focus();
    }

    function openAddModal() {
      document.getElementById('addModal').hidden = false;
    }

    function closeModal(id) {
      document.getElementById(id).hidden = true;
    }
  </script>
</body>

</html>