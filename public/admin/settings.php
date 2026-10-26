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

$activeCashiers = (int) $db->query("SELECT COUNT(*) FROM cashiers WHERE is_active = 1 AND deleted_at IS NULL")->fetchColumn();
$totalCashiers  = (int) $db->query("SELECT COUNT(*) FROM cashiers WHERE deleted_at IS NULL")->fetchColumn();

$fb = $db->query("SELECT COUNT(*) AS total, ROUND(AVG(rating),2) AS avg_rating FROM order_feedback")->fetch();
$feedbackTotal = (int) ($fb['total'] ?? 0);
$feedbackAvg   = $fb['avg_rating'] ?? null;

layoutHeader('Settings');
?>
<style>
  .admin-settings {
    display: grid;
    gap: 18px
  }

  .admin-settings .page-header {
    margin-bottom: 0
  }

  .settings-section {
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-xs);
  }

  .settings-section-head {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 19px 22px 16px;
    border-bottom: 1px solid var(--border-color)
  }

  .settings-section-icon {
    display: grid;
    place-items: center;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: var(--primary-subtle);
    color: var(--primary-color)
  }

  .settings-section-head h2 {
    margin: 0;
    font-size: 15px;
    line-height: 1.35
  }

  .settings-section-head .sub {
    max-width: 720px;
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.55
  }

  .settings-section-body {
    padding: 19px 22px 22px
  }

  .settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(205px, 1fr));
    gap: 16px;
  }

  .settings-grid .form-group {
    min-width: 0;
    margin: 0
  }

  .settings-form-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 18px
  }

  .settings-form-actions .btn {
    min-width: 140px
  }

  .settings-confirm {
    max-width: 460px;
    margin-top: 17px;
    padding: 15px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--surface-raised)
  }

  .settings-confirm-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
    color: var(--text-secondary);
    font-size: 11px;
    font-weight: 800
  }

  .settings-confirm-title i {
    color: var(--primary-color)
  }

  .settings-confirm .form-hint {
    margin-bottom: 10px;
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.45
  }

  .settings-shortcuts {
    display: grid;
    gap: 12px
  }

  .settings-shortcuts-title {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px
  }

  .settings-shortcuts-title h2 {
    margin: 0;
    font-size: 15px
  }

  .settings-shortcuts-title p {
    margin: 0;
    color: var(--text-muted);
    font-size: 11px
  }

  .link-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
  }

  .link-card {
    position: relative;
    display: block;
    min-height: 150px;
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 19px;
    transition: box-shadow var(--transition-fast), transform var(--transition-fast), border-color var(--transition-fast);
  }

  .link-card:hover {
    border-color: var(--border-strong);
    box-shadow: var(--shadow-sm);
    transform: translateY(-2px)
  }

  .link-card:focus-visible {
    outline: 2px solid var(--primary-color);
    outline-offset: 2px
  }

  .link-card-icon {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    background: var(--primary-subtle);
    color: var(--primary-color);
    margin-bottom: 13px;
    font-size: 16px;
  }

  .link-card:nth-child(2) .link-card-icon {
    background: var(--accent-subtle);
    color: var(--secondary-color)
  }

  .link-card:nth-child(3) .link-card-icon {
    background: var(--status-preparing-bg);
    color: var(--status-preparing)
  }

  .link-card-title {
    margin-bottom: 4px;
    font-size: 13px;
    font-weight: 800
  }

  .link-card-stat {
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.5
  }

  .link-card-arrow {
    position: absolute;
    top: 21px;
    right: 20px;
    color: var(--text-muted);
    font-size: 12px;
    transition: transform var(--transition-fast), color var(--transition-fast)
  }

  .link-card:hover .link-card-arrow {
    transform: translateX(3px);
    color: var(--primary-color)
  }

  @media(max-width:640px) {
    .settings-section-head {
      padding: 16px
    }

    .settings-section-body {
      padding: 16px
    }

    .settings-grid {
      grid-template-columns: 1fr 1fr;
      gap: 12px
    }

    .settings-form-actions {
      flex-wrap: wrap
    }

    .settings-form-actions .btn {
      flex: 1
    }

    .settings-shortcuts-title {
      align-items: flex-start;
      flex-direction: column;
      gap: 3px
    }
  }

  @media(max-width:420px) {
    .settings-grid {
      grid-template-columns: 1fr
    }

    .settings-form-actions .btn {
      width: 100%
    }
  }
</style>

<div class="admin-settings">
  <div class="page-header">
    <div>
      <div class="page-header-title">Settings</div>
      <div class="page-header-sub">Manage store operations and payment details. Loyalty earning and rewards are managed on the Loyalty page.</div>
    </div>
  </div>
  <?php showFlash('global'); ?>

  <div class="settings-section">
    <div class="settings-section-head"><span class="settings-section-icon"><i class="fa-solid fa-clock"></i></span>
      <div>
        <h2>Store and ordering rules</h2>
        <p class="sub">Set the hours shown to customers and control pickup slot timing and capacity.</p>
      </div>
    </div>
    <div class="settings-section-body">
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="save_settings" value="1">
        <div class="settings-grid">
          <div class="form-group">
            <label class="form-label" for="store-open-time">Store opens</label>
            <input id="store-open-time" type="time" name="store_open_time" class="form-control" value="<?= e($storeOpen) ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="store-close-time">Store closes</label>
            <input id="store-close-time" type="time" name="store_close_time" class="form-control" value="<?= e($storeClose) ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="pickup-slot-length">Pickup slot length</label>
            <input id="pickup-slot-length" type="number" name="pickup_slot_interval_minutes" class="form-control" min="5" step="5" value="<?= e($slotInterval) ?>"><small class="form-hint">Minutes per pickup window</small>
          </div>
          <div class="form-group">
            <label class="form-label" for="pickup-slot-capacity">Orders per pickup slot</label>
            <input id="pickup-slot-capacity" type="number" name="pickup_slot_capacity" class="form-control" min="1" value="<?= e($slotCapacity) ?>"><small class="form-hint">Maximum scheduled orders in each window</small>
          </div>
        </div>
        <div class="settings-form-actions"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save store rules</button></div>
      </form>
    </div>
  </div>

  <div class="settings-section">
    <div class="settings-section-head"><span class="settings-section-icon"><i class="fa-solid fa-wallet"></i></span>
      <div>
        <h2>GCash payment recipient</h2>
        <p class="sub">Customers use this account to pay for pre-orders. Verify the name and number carefully before saving.</p>
      </div>
    </div>
    <div class="settings-section-body">
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="save_gcash" value="1">
        <div class="settings-grid">
          <div class="form-group">
            <label class="form-label" for="gcash-name">Account name</label>
            <input id="gcash-name" type="text" name="gcash_payment_name" class="form-control" maxlength="100" required value="<?= e($gcashName) ?>" placeholder="e.g. Juan D.">
          </div>
          <div class="form-group">
            <label class="form-label" for="gcash-number">GCash number</label>
            <input id="gcash-number" type="tel" name="gcash_payment_number" class="form-control" inputmode="numeric" required value="<?= e($gcashNumber) ?>" placeholder="09XXXXXXXXX">
          </div>
        </div>
        <div class="settings-confirm">
          <div class="settings-confirm-title"><i class="fa-solid fa-shield-halved"></i> Confirm it’s you</div>
          <p class="form-hint">Enter your admin password to protect changes to where customer payments are sent.</p>
          <div class="form-group"><label class="form-label" for="gcash-confirm-password">Admin password</label><input id="gcash-confirm-password" type="password" name="confirm_password" class="form-control" autocomplete="current-password" required placeholder="Enter your password"></div>
        </div>
        <div class="settings-form-actions"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save payment recipient</button></div>
      </form>
    </div>
  </div>

  <section class="settings-shortcuts">
    <div class="settings-shortcuts-title">
      <h2>Management shortcuts</h2>
      <p>Related admin tools</p>
    </div>
    <div class="link-card-grid">
      <a href="<?= APP_URL ?>/admin/loyalty.php" class="link-card">
        <i class="fa-solid fa-arrow-up-right-from-square link-card-arrow" aria-hidden="true"></i>
        <div class="link-card-icon"><i class="fa-solid fa-award"></i></div>
        <div class="link-card-title">Loyalty Program</div>
        <div class="link-card-stat">Set spend per point, drink rewards, and customer adjustments</div>
      </a>

      <a href="<?= APP_URL ?>/admin/cashiers.php" class="link-card">
        <i class="fa-solid fa-arrow-up-right-from-square link-card-arrow" aria-hidden="true"></i>
        <div class="link-card-icon"><i class="fa-solid fa-users"></i></div>
        <div class="link-card-title">Cashier Accounts</div>
        <div class="link-card-stat"><?= $activeCashiers ?> active of <?= $totalCashiers ?> total</div>
      </a>

      <a href="<?= APP_URL ?>/admin/feedback.php" class="link-card">
        <i class="fa-solid fa-arrow-up-right-from-square link-card-arrow" aria-hidden="true"></i>
        <div class="link-card-icon"><i class="fa-solid fa-star"></i></div>
        <div class="link-card-title">Customer Feedback</div>
        <div class="link-card-stat">
          <?= $feedbackTotal ?> review<?= $feedbackTotal !== 1 ? 's' : '' ?>
          <?php if ($feedbackAvg !== null): ?> &middot; <?= $feedbackAvg ?> ★ avg<?php endif; ?>
        </div>
      </a>

      <a href="<?= APP_URL ?>/settings.php" class="link-card">
        <i class="fa-solid fa-arrow-up-right-from-square link-card-arrow" aria-hidden="true"></i>
        <div class="link-card-icon"><i class="fa-solid fa-id-badge"></i></div>
        <div class="link-card-title">My Admin Profile</div>
        <div class="link-card-stat">Change your name, email, or password</div>
      </a>
    </div>
  </section>
</div>

<?php layoutFooter(); ?>