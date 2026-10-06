<?php
// ============================================================
// public/admin/settings.php
//
// Tab 4 of 4 (item 13). "Everything that doesn't fit the other
// 3 categories": store hours & ordering rules (backed by
// app_settings), plus quick-stat cards linking out to Cashier
// Accounts (admin/cashiers.php) and Feedback Moderation
// (admin/feedback.php) — both are already complete, working
// pages, so they're linked here rather than duplicated inline.
// ============================================================

require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
  verifyCsrf();
  $fields = [
    'store_open_time'              => $_POST['store_open_time'] ?? '07:00',
    'store_close_time'             => $_POST['store_close_time'] ?? '20:00',
    'pickup_slot_interval_minutes' => (string) max(5, (int) ($_POST['pickup_slot_interval_minutes'] ?? 15)),
    'pickup_slot_capacity'         => (string) max(1, (int) ($_POST['pickup_slot_capacity'] ?? 6)),
  ];
  foreach ($fields as $key => $value) {
    setSetting($key, $value);
  }
  auditLog(ROLE_ADMIN, currentUserId(), 'update_settings');
  flash('global', 'Settings saved.', 'success');
  redirect(APP_URL . '/admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gcash'])) {
  verifyCsrf();
  flashOldInput($_POST); // keep what they typed if validation fails
  $name   = trim(preg_replace('/\s+/', ' ', (string) ($_POST['gcash_payment_name'] ?? '')));
  $number = normalizePhMobile((string) ($_POST['gcash_payment_number'] ?? ''));

  // Re-confirm the admin's own password: this changes where customer money goes,
  // so a stolen/unattended logged-in session alone shouldn't be enough.
  $throttleKey = 'gcash_recipient_' . currentUserId();
  $pwOk = false;
  if (!checkLoginAttempts($throttleKey)) {
    flash('global', 'Too many failed password attempts. Please wait 15 minutes.', 'error');
    redirect(APP_URL . '/admin/settings.php');
  }
  $adminRow = $db->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
  $adminRow->execute([currentUserId()]);
  $hash = $adminRow->fetchColumn();
  if ($hash && password_verify((string) ($_POST['confirm_password'] ?? ''), $hash)) {
    $pwOk = true;
    clearLoginAttempts($throttleKey);
  } else {
    recordFailedLogin($throttleKey);
  }

  if (!$pwOk) {
    flash('global', 'Incorrect password. The GCash recipient was not changed.', 'error');
  } elseif ($name === '' || mb_strlen($name) > 100) {
    flash('global', 'Enter the GCash account name (up to 100 characters).', 'error');
  } elseif ($number === null) {
    flash('global', 'Enter a valid Philippine mobile number, e.g. 09171234567.', 'error');
  } else {
    $oldName   = gcashPaymentName();
    $oldNumber = gcashPaymentNumber();
    setSetting('gcash_payment_name', $name);
    setSetting('gcash_payment_number', $number);
    if ($oldName !== $name || $oldNumber !== $number) {
      auditLog(ROLE_ADMIN, currentUserId(), 'update_gcash_recipient', 'was ' . $oldName . ' / ' . $oldNumber);
    }
    getOldInput(); // saved OK — discard the stashed form values
    flash('global', 'GCash payment recipient updated.', 'success');
  }
  redirect(APP_URL . '/admin/settings.php');
}

$storeOpen   = getSetting('store_open_time', '07:00');
$storeClose  = getSetting('store_close_time', '20:00');
$slotInterval = getSetting('pickup_slot_interval_minutes', '15');
$slotCapacity = getSetting('pickup_slot_capacity', '6');
$oldInput     = getOldInput();
$gcashName    = $oldInput['gcash_payment_name']   ?? gcashPaymentName();
$gcashNumber  = $oldInput['gcash_payment_number'] ?? gcashPaymentNumber();

$activeCashiers = (int) $db->query("SELECT COUNT(*) FROM cashiers WHERE is_active = 1")->fetchColumn();
$totalCashiers  = (int) $db->query("SELECT COUNT(*) FROM cashiers")->fetchColumn();

$fb = $db->query("SELECT COUNT(*) AS total, ROUND(AVG(rating),2) AS avg_rating FROM order_feedback")->fetch();
$feedbackTotal = (int) ($fb['total'] ?? 0);
$feedbackAvg   = $fb['avg_rating'] ?? null;

layoutHeader('Settings');
?>
<style>
  .settings-section {
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 22px;
    margin-bottom: 20px;
  }

  .settings-section h2 {
    font-size: 16px;
    margin-bottom: 4px;
  }

  .settings-section .sub {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 18px;
  }

  .settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
  }

  .link-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
  }

  .link-card {
    display: block;
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 20px;
    transition: box-shadow .15s ease, transform .15s ease;
  }

  .link-card:hover {
    box-shadow: var(--shadow-sm);
    transform: translateY(-2px);
  }

  .link-card-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    background: var(--primary-subtle);
    color: var(--primary-color);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 12px;
    font-size: 17px;
  }

  .link-card-title {
    font-weight: 700;
    font-size: 14.5px;
    margin-bottom: 4px;
  }

  .link-card-stat {
    font-size: 13px;
    color: var(--text-muted);
  }
</style>

<div class="page-header">
  <div>
    <div class="page-header-title">Settings</div>
    <div class="page-header-sub">Store rules, cashier accounts, feedback, and account access</div>
  </div>
</div>
<?php showFlash('global'); ?>

<div class="settings-section">
  <h2>Store &amp; Ordering Rules</h2>
  <p class="sub">These control the pickup scheduler and the cash pre-order policy on the customer-facing shop.</p>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="save_settings" value="1">
    <div class="settings-grid">
      <div class="form-group">
        <label class="form-label">Store Opens</label>
        <input type="time" name="store_open_time" class="form-control" value="<?= e($storeOpen) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Store Closes</label>
        <input type="time" name="store_close_time" class="form-control" value="<?= e($storeClose) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Pickup Slot Length (minutes)</label>
        <input type="number" name="pickup_slot_interval_minutes" class="form-control" min="5" step="5" value="<?= e($slotInterval) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Orders per Pickup Slot</label>
        <input type="number" name="pickup_slot_capacity" class="form-control" min="1" value="<?= e($slotCapacity) ?>">
      </div>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:18px"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
  </form>
</div>

<div class="settings-section">
  <h2>GCash Payment Recipient</h2>
  <p class="sub">Customers see this name and number when paying for pre-orders. Double-check the number &mdash; payments go straight to this account.</p>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="save_gcash" value="1">
    <div class="settings-grid">
      <div class="form-group">
        <label class="form-label">Account Name</label>
        <input type="text" name="gcash_payment_name" class="form-control" maxlength="100" required value="<?= e($gcashName) ?>" placeholder="e.g. Juan D.">
      </div>
      <div class="form-group">
        <label class="form-label">GCash Number</label>
        <input type="tel" name="gcash_payment_number" class="form-control" inputmode="numeric" required value="<?= e($gcashNumber) ?>" placeholder="09XXXXXXXXX">
      </div>
    </div>
    <div class="settings-grid" style="margin-top:16px">
      <div class="form-group">
        <label class="form-label">Your Password (to confirm)</label>
        <input type="password" name="confirm_password" class="form-control" autocomplete="current-password" required placeholder="Enter your admin password">
      </div>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:18px"><i class="fa-solid fa-floppy-disk"></i> Save Recipient</button>
  </form>
</div>

<div class="link-card-grid">
  <a href="<?= APP_URL ?>/admin/cashiers.php" class="link-card">
    <div class="link-card-icon"><i class="fa-solid fa-users"></i></div>
    <div class="link-card-title">Cashier Accounts</div>
    <div class="link-card-stat"><?= $activeCashiers ?> active of <?= $totalCashiers ?> total</div>
  </a>

  <a href="<?= APP_URL ?>/admin/feedback.php" class="link-card">
    <div class="link-card-icon"><i class="fa-solid fa-star"></i></div>
    <div class="link-card-title">Feedback Moderation</div>
    <div class="link-card-stat">
      <?= $feedbackTotal ?> review<?= $feedbackTotal !== 1 ? 's' : '' ?>
      <?php if ($feedbackAvg !== null): ?> &middot; <?= $feedbackAvg ?> ★ avg<?php endif; ?>
    </div>
  </a>

  <a href="<?= APP_URL ?>/settings.php" class="link-card">
    <div class="link-card-icon"><i class="fa-solid fa-id-badge"></i></div>
    <div class="link-card-title">My Admin Profile</div>
    <div class="link-card-stat">Change your name, email, or password</div>
  </a>
</div>

<?php layoutFooter(); ?>