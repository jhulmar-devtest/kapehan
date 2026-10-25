<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/loyalty.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();
  $action = (string) ($_POST['action'] ?? '');
  try {
    if ($action === 'adjust_points') {
      $type = sanitizeString($_POST['customer_type'] ?? '');
      $customerId = (int) ($_POST['customer_id'] ?? 0);
      $delta = filter_var($_POST['points_delta'] ?? null, FILTER_VALIDATE_INT);
      $note = trim(sanitizeString($_POST['note'] ?? '', 255));
      $config = loyaltyAccountConfig($type);
      if (!$config || $customerId < 1 || $delta === false || $delta === 0 || abs($delta) > 100000 || mb_strlen($note) < 5) {
        throw new RuntimeException('Enter a valid customer, a non-zero adjustment up to 100,000 points, and a reason of at least 5 characters.');
      }

      $db->beginTransaction();
      $lock = $db->prepare('SELECT id FROM ' . $config['table'] . ' WHERE id = ? FOR UPDATE');
      $lock->execute([$customerId]);
      if (!$lock->fetchColumn()) throw new RuntimeException('That customer account no longer exists.');
      $balance = loyaltyPointsBalance($db, $type, $customerId);
      if ($balance + $delta < 0) throw new RuntimeException('This adjustment would make the customer’s point balance negative.');
      $db->prepare(
        "INSERT INTO loyalty_points_ledger (customer_type, customer_id, order_id, reward_product_id, entry_type, points_delta, amount_value, actor_type, actor_id, note)
         VALUES (?, ?, NULL, NULL, 'adjustment', ?, 0, 'admin', ?, ?)"
      )->execute([$type, $customerId, $delta, (int) currentUserId(), $note]);
      $db->commit();
      auditLog(ROLE_ADMIN, (int) currentUserId(), 'loyalty_points_adjustment', $type, $customerId);
      flash('global', 'Points adjusted and recorded in the customer ledger.', 'success');
      redirect(APP_URL . '/admin/loyalty.php?customer_type=' . urlencode($type) . '&customer_id=' . $customerId . '#customer-ledger');
    }

    if ($action === 'save_drink_reward') {
      $productId = (int) ($_POST['product_id'] ?? 0);
      $points = filter_var($_POST['points_required'] ?? null, FILTER_VALIDATE_INT);
      $discount = filter_var($_POST['discount_amount'] ?? null, FILTER_VALIDATE_FLOAT);
      $active = isset($_POST['is_active']) ? 1 : 0;
      if ($productId < 1 || $points === false || $points < 1 || $points > 100000 || $discount === false || round($discount, 2) <= 0) {
        throw new RuntimeException('Enter a drink, 1–100,000 points, and a positive discount amount.');
      }

      $db->beginTransaction();
      $productStmt = $db->prepare('SELECT id, name, price FROM products WHERE id = ? FOR UPDATE');
      $productStmt->execute([$productId]);
      $product = $productStmt->fetch(PDO::FETCH_ASSOC);
      if (!$product) throw new RuntimeException('That drink no longer exists.');
      if (round($discount, 2) > (float) $product['price']) {
        throw new RuntimeException('The reward discount cannot exceed this drink’s base price (₱' . number_format((float) $product['price'], 2) . ').');
      }

      $oldStmt = $db->prepare('SELECT points_required, discount_amount, is_active FROM loyalty_product_rewards WHERE product_id = ? FOR UPDATE');
      $oldStmt->execute([$productId]);
      $old = $oldStmt->fetch(PDO::FETCH_ASSOC) ?: null;
      $db->prepare(
        'INSERT INTO loyalty_product_rewards (product_id, points_required, discount_amount, is_active, updated_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE points_required = VALUES(points_required), discount_amount = VALUES(discount_amount), is_active = VALUES(is_active), updated_by = VALUES(updated_by)'
      )->execute([$productId, $points, number_format(round($discount, 2), 2, '.', ''), $active, (int) currentUserId()]);
      $db->prepare(
        'INSERT INTO loyalty_product_reward_audit (product_id, product_name_snapshot, old_points_required, new_points_required, old_discount_amount, new_discount_amount, old_is_active, new_is_active, actor_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
      )->execute([
        $productId,
        $product['name'],
        $old['points_required'] ?? null,
        $points,
        $old['discount_amount'] ?? null,
        number_format(round($discount, 2), 2, '.', ''),
        $old['is_active'] ?? null,
        $active,
        (int) currentUserId(),
      ]);
      $db->commit();
      auditLog(ROLE_ADMIN, (int) currentUserId(), 'loyalty_drink_reward_updated', 'products', $productId);
      flash('global', 'Drink reward saved. The previous and new terms are recorded in the reward audit.', 'success');
      redirect(APP_URL . '/admin/loyalty.php#drink-rewards');
    }

    throw new RuntimeException('Choose a valid loyalty action.');
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Admin loyalty action failed: ' . $e->getMessage());
    flash('global', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save the loyalty change.', 'error');
    $suffix = $action === 'adjust_points'
      ? '?customer_type=' . urlencode((string) ($_POST['customer_type'] ?? '')) . '&customer_id=' . (int) ($_POST['customer_id'] ?? 0) . '#customer-ledger'
      : '#drink-rewards';
    redirect(APP_URL . '/admin/loyalty.php' . $suffix);
  }
}

$search = trim(sanitizeString($_GET['q'] ?? '', 80));
$selectedType = sanitizeString($_GET['customer_type'] ?? '');
$selectedId = (int) ($_GET['customer_id'] ?? 0);
$accountsSql = "SELECT c.customer_type, c.customer_id, c.full_name, c.identifier, c.email, c.is_active,
                       COALESCE(SUM(l.points_delta), 0) AS balance
                  FROM (
                    SELECT 'student' AS customer_type, id AS customer_id, full_name, student_id_no AS identifier, email, is_active FROM students
                    UNION ALL
                    SELECT 'faculty' AS customer_type, id AS customer_id, full_name, faculty_id_no AS identifier, email, is_active FROM faculty
                  ) c
             LEFT JOIN loyalty_points_ledger l ON l.customer_type = c.customer_type AND l.customer_id = c.customer_id";
$params = [];
if ($search !== '') {
  $accountsSql .= ' WHERE c.full_name LIKE ? OR c.identifier LIKE ? OR c.email LIKE ?';
  $like = '%' . $search . '%';
  $params = [$like, $like, $like];
}
$accountsSql .= ' GROUP BY c.customer_type, c.customer_id, c.full_name, c.identifier, c.email, c.is_active ORDER BY balance DESC, c.full_name LIMIT 250';
$accountStmt = $db->prepare($accountsSql);
$accountStmt->execute($params);
$customers = $accountStmt->fetchAll(PDO::FETCH_ASSOC);
$totalCustomers = (int) $db->query('SELECT (SELECT COUNT(*) FROM students) + (SELECT COUNT(*) FROM faculty)')->fetchColumn();
$activeRewardCount = (int) $db->query('SELECT COUNT(*) FROM loyalty_product_rewards WHERE is_active = 1')->fetchColumn();
$products = $db->query(
  "SELECT p.id, p.name, p.price, p.is_available, r.points_required, r.discount_amount, r.is_active
     FROM products p JOIN categories c ON c.id = p.category_id
     LEFT JOIN loyalty_product_rewards r ON r.product_id = p.id
    WHERE c.name != 'Add-ons' ORDER BY p.name"
)->fetchAll(PDO::FETCH_ASSOC);
$rewardAudit = $db->query(
  'SELECT product_name_snapshot, old_points_required, new_points_required, old_discount_amount, new_discount_amount, old_is_active, new_is_active, actor_id, created_at
     FROM loyalty_product_reward_audit ORDER BY id DESC LIMIT 100'
)->fetchAll(PDO::FETCH_ASSOC);

$selectedCustomer = null;
$customerHistory = [];
if (in_array($selectedType, [ROLE_STUDENT, ROLE_FACULTY], true) && $selectedId > 0) {
  $config = loyaltyAccountConfig($selectedType);
  $stmt = $db->prepare('SELECT id, full_name, ' . $config['identifier'] . ' AS identifier, email, is_active FROM ' . $config['table'] . ' WHERE id = ? LIMIT 1');
  $stmt->execute([$selectedId]);
  $selectedCustomer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($selectedCustomer) {
    $selectedCustomer['type'] = $selectedType;
    $selectedCustomer['balance'] = loyaltyPointsBalance($db, $selectedType, $selectedId);
    $customerHistory = loyaltyHistory($db, $selectedType, $selectedId, 100);
  }
}

layoutHeader('Loyalty');
?>
<style>
  .loyalty-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.8fr);gap:18px;margin-bottom:20px}
  .loyalty-card{background:var(--surface-color);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:20px;margin-bottom:18px}
  .loyalty-card h2{font-size:16px;margin:0 0 5px}.loyalty-card .sub{font-size:13px;color:var(--text-muted);margin:0 0 16px}
  .loyalty-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}.loyalty-stat{background:var(--surface-color);border:1px solid var(--border-color);border-radius:12px;padding:12px 16px;min-width:150px}.loyalty-stat strong{display:block;font-size:20px}.loyalty-stat span{color:var(--text-muted);font-size:12px}
  .loyalty-inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.loyalty-inline .form-control{min-width:90px}.loyalty-table-wrap{overflow-x:auto}.loyalty-table{width:100%;border-collapse:collapse}.loyalty-table th,.loyalty-table td{padding:10px 12px;border-bottom:1px solid var(--border-color);text-align:left;vertical-align:top;font-size:13px}.loyalty-table th{color:var(--text-muted);font-weight:700;white-space:nowrap}.loyalty-table td small{display:block;color:var(--text-muted);margin-top:3px}.loyalty-points{font-weight:800;white-space:nowrap}.loyalty-points.negative{color:var(--danger-color,#b42318)}.loyalty-form{min-width:370px}.loyalty-form .form-control{max-width:145px}.loyalty-note{min-width:190px;flex:1}.loyalty-search{display:flex;gap:8px;max-width:560px;margin:14px 0}.loyalty-change{white-space:nowrap}
  @media(max-width:900px){.loyalty-grid{grid-template-columns:1fr}.loyalty-form{min-width:320px}}
</style>

<div class="page-header"><div><div class="page-header-title">Loyalty</div><div class="page-header-sub">Customer balances, point adjustments, and rewards tied to individual drinks.</div></div></div>
<?php showFlash('global'); ?>
<div class="loyalty-summary">
  <div class="loyalty-stat"><strong><?= number_format($totalCustomers) ?></strong><span>Student and faculty accounts</span></div>
  <div class="loyalty-stat"><strong><?= number_format($activeRewardCount) ?></strong><span>Active drink rewards</span></div>
</div>

<section class="loyalty-card">
  <h2>Customer points</h2>
  <p class="sub">Balances are calculated from the append-only points ledger. Adjustments require a reason and record the signed-in admin.</p>
  <form method="GET" class="loyalty-search"><input class="form-control" type="search" name="q" value="<?= e($search) ?>" placeholder="Search customer, school ID, or email"><button class="btn btn-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button><?php if ($search !== ''): ?><a class="btn btn-ghost" href="<?= APP_URL ?>/admin/loyalty.php">Clear</a><?php endif; ?></form>
  <div class="loyalty-table-wrap"><table class="loyalty-table"><thead><tr><th>Customer</th><th>Account</th><th>Balance</th><th>Status</th><th></th></tr></thead><tbody>
  <?php if (!$customers): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:28px">No customer accounts match this search.</td></tr><?php else: foreach ($customers as $customer): ?>
    <tr><td><strong><?= e($customer['full_name']) ?></strong><small><?= e($customer['email'] ?? '') ?></small></td><td><?= e(ucfirst($customer['customer_type'])) ?><small><?= e($customer['identifier']) ?></small></td><td class="loyalty-points"><?= number_format((int) $customer['balance']) ?> pts</td><td><?= (int) $customer['is_active'] ? 'Active' : 'Inactive' ?></td><td><a class="btn btn-sm btn-secondary" href="<?= APP_URL ?>/admin/loyalty.php?customer_type=<?= e($customer['customer_type']) ?>&amp;customer_id=<?= (int) $customer['customer_id'] ?>#customer-ledger">Ledger</a></td></tr>
  <?php endforeach; endif; ?></tbody></table></div>
</section>

<?php if ($selectedCustomer): ?>
<section class="loyalty-card" id="customer-ledger">
  <div class="loyalty-grid">
    <div><h2><?= e($selectedCustomer['full_name']) ?>’s points ledger</h2><p class="sub"><?= e(ucfirst($selectedType)) ?> · <?= e($selectedCustomer['identifier']) ?> · <?= number_format($selectedCustomer['balance']) ?> points<?= !(int) $selectedCustomer['is_active'] ? ' · inactive account' : '' ?></p>
      <form method="POST" class="loyalty-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="adjust_points"><input type="hidden" name="customer_type" value="<?= e($selectedType) ?>"><input type="hidden" name="customer_id" value="<?= (int) $selectedCustomer['id'] ?>">
        <div class="loyalty-inline"><label class="sr-only" for="points-delta">Point adjustment</label><input class="form-control" id="points-delta" type="number" name="points_delta" min="-100000" max="100000" step="1" required placeholder="+/- points"><input class="form-control loyalty-note" type="text" name="note" maxlength="255" minlength="5" required placeholder="Reason for adjustment"><button class="btn btn-primary" type="submit">Post adjustment</button></div>
      </form>
    </div>
    <div><h2>Ledger notes</h2><p class="sub">Earns are added after paid pickup. Redemptions are deducted at checkout and linked to the reward drink; cancellations create reversal entries.</p></div>
  </div>
  <div class="loyalty-table-wrap"><table class="loyalty-table"><thead><tr><th>Date</th><th>Entry</th><th>Points</th><th>Value</th><th>Order / drink / reason</th><th>Recorded by</th></tr></thead><tbody>
  <?php if (!$customerHistory): ?><tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px">No ledger entries yet.</td></tr><?php else: foreach ($customerHistory as $entry): ?>
    <tr><td><?= e(date('M j, Y g:i A', strtotime($entry['created_at']))) ?></td><td><?= e(str_replace('_', ' ', ucfirst($entry['entry_type']))) ?></td><td class="loyalty-points <?= (int) $entry['points_delta'] < 0 ? 'negative' : '' ?>"><?= (int) $entry['points_delta'] > 0 ? '+' : '' ?><?= number_format((int) $entry['points_delta']) ?></td><td><?= (float) $entry['amount_value'] != 0.0 ? '₱' . number_format((float) $entry['amount_value'], 2) : '—' ?></td><td><?= $entry['order_number'] ? 'Order ' . e($entry['order_number']) : 'Manual entry' ?><?php if ($entry['reward_product_id']): ?><small><?= e($entry['note'] ?: ('Reward product #' . (int) $entry['reward_product_id'])) ?></small><?php elseif ($entry['note']): ?><small><?= e($entry['note']) ?></small><?php endif; ?></td><td><?= e(ucfirst($entry['actor_type'])) ?><?= $entry['actor_id'] ? ' #' . (int) $entry['actor_id'] : '' ?></td></tr>
  <?php endforeach; endif; ?></tbody></table></div>
</section>
<?php endif; ?>

<section class="loyalty-card" id="drink-rewards">
  <h2>Drink-specific rewards</h2><p class="sub">Each active rule applies only when that exact drink is in the order. The discount is for one unit and cannot exceed the drink’s base price. Rule changes are preserved in the audit history.</p>
  <div class="loyalty-table-wrap"><table class="loyalty-table"><thead><tr><th>Drink</th><th>Base price</th><th>Points</th><th>Discount on this drink</th><th>Active</th><th></th></tr></thead><tbody>
  <?php foreach ($products as $product): ?><tr><td><strong><?= e($product['name']) ?></strong><?php if (!(int) $product['is_available']): ?><small>Not currently available for sale</small><?php endif; ?></td><td>₱<?= number_format((float) $product['price'], 2) ?></td><td colspan="4"><form method="POST" class="loyalty-inline loyalty-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_drink_reward"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><input class="form-control" aria-label="Points required for <?= e($product['name']) ?>" type="number" name="points_required" min="1" max="100000" step="1" required value="<?= $product['points_required'] !== null ? (int) $product['points_required'] : '' ?>" placeholder="Points"><input class="form-control" aria-label="Discount amount for <?= e($product['name']) ?>" type="number" name="discount_amount" min="0.01" max="<?= number_format((float) $product['price'], 2, '.', '') ?>" step="0.01" required value="<?= $product['discount_amount'] !== null ? number_format((float) $product['discount_amount'], 2, '.', '') : '' ?>" placeholder="₱ discount"><label style="display:flex;align-items:center;gap:5px;white-space:nowrap"><input type="checkbox" name="is_active" value="1" <?= (int) ($product['is_active'] ?? 0) ? 'checked' : '' ?>> Active</label><button class="btn btn-sm btn-primary" type="submit">Save</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="loyalty-card">
  <h2>Drink reward rule audit</h2><p class="sub">Last 100 changes, with the previous and new terms and the admin who made each change.</p>
  <div class="loyalty-table-wrap"><table class="loyalty-table"><thead><tr><th>Changed</th><th>Drink</th><th>Previous rule</th><th>New rule</th><th>Admin</th></tr></thead><tbody>
  <?php if (!$rewardAudit): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">No drink reward changes recorded.</td></tr><?php else: foreach ($rewardAudit as $audit): ?>
    <tr><td><?= e(date('M j, Y g:i A', strtotime($audit['created_at']))) ?></td><td><?= e($audit['product_name_snapshot']) ?></td><td><?= $audit['old_points_required'] === null ? 'New rule' : number_format((int) $audit['old_points_required']) . ' pts · ₱' . number_format((float) $audit['old_discount_amount'], 2) . ((int) $audit['old_is_active'] ? ' · active' : ' · paused') ?></td><td><?= number_format((int) $audit['new_points_required']) ?> pts · ₱<?= number_format((float) $audit['new_discount_amount'], 2) ?><?= (int) $audit['new_is_active'] ? ' · active' : ' · paused' ?></td><td>Admin #<?= (int) $audit['actor_id'] ?></td></tr>
  <?php endforeach; endif; ?></tbody></table></div>
</section>
<?php layoutFooter(); ?>
