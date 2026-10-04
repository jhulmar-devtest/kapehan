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

$storeOpen   = getSetting('store_open_time', '07:00');
$storeClose  = getSetting('store_close_time', '20:00');
$slotInterval = getSetting('pickup_slot_interval_minutes', '15');
$slotCapacity = getSetting('pickup_slot_capacity', '6');

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