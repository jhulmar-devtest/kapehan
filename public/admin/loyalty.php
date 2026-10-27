<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/loyalty.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();
  $action = (string) ($_POST['action'] ?? '');
  try {
    if ($action === 'save_earn_rate') {
      $spendRaw = trim((string) ($_POST['spend_per_point'] ?? ''));
      if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $spendRaw)) {
        throw new RuntimeException('Enter a spend amount from ₱0.00 to ₱100,000.00, using up to two decimal places.');
      }
      $spendPerPoint = (float) $spendRaw;
      if ($spendPerPoint > 100000) {
        throw new RuntimeException('The spend amount cannot exceed ₱100,000.00.');
      }

      setSetting('loyalty_spend_per_point', number_format($spendPerPoint, 2, '.', ''));
      auditLog(ROLE_ADMIN, (int) currentUserId(), 'update_loyalty_settings');
      flash('global', 'Loyalty earn rate updated.', 'success');
      redirect(APP_URL . '/admin/loyalty.php#earn-rate');
    }

    if ($action === 'save_redemption_rate') {
      $pointsStep = filter_var($_POST['redemption_points_step'] ?? null, FILTER_VALIDATE_INT);
      $discount = filter_var($_POST['redemption_discount_amount'] ?? null, FILTER_VALIDATE_FLOAT);
      if ($pointsStep === false || $pointsStep < 1 || $pointsStep > 100000 || $discount === false || $discount <= 0 || $discount > 100000) {
        throw new RuntimeException('Enter 1–100,000 points and a discount from ₱0.01 to ₱100,000.00.');
      }
      setSetting('loyalty_redemption_points_step', (string) $pointsStep);
      setSetting('loyalty_redemption_discount_amount', number_format(round($discount, 2), 2, '.', ''));
      auditLog(ROLE_ADMIN, (int) currentUserId(), 'update_loyalty_redemption_rate');
      flash('global', 'General points redemption rate updated.', 'success');
      redirect(APP_URL . '/admin/loyalty.php#redemption-rate');
    }

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
      : ($action === 'save_earn_rate' ? '#earn-rate' : ($action === 'save_redemption_rate' ? '#redemption-rate' : '#drink-rewards'));
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
$activeRewardCount = (int) $db->query(
  "SELECT COUNT(*) FROM loyalty_product_rewards r
     JOIN products p ON p.id = r.product_id
     JOIN categories c ON c.id = p.category_id
    WHERE r.is_active = 1 AND p.is_available = 1 AND p.archived_at IS NULL AND c.archived_at IS NULL AND c.name != 'Add-ons'"
)->fetchColumn();
$spendPerPoint = loyaltyRules($db)['spend_per_point'];
$redemptionRules = loyaltyRules($db);
$products = $db->query(
  "SELECT p.id, p.name, p.price, p.is_available, r.points_required, r.discount_amount, r.is_active
     FROM products p JOIN categories c ON c.id = p.category_id
     LEFT JOIN loyalty_product_rewards r ON r.product_id = p.id
    WHERE c.name != 'Add-ons' AND p.archived_at IS NULL AND c.archived_at IS NULL ORDER BY p.name"
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
  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0
  }

  .loyalty-page {
    display: grid;
    gap: 20px
  }

  .loyalty-page .page-header {
    margin-bottom: 0
  }

  .loyalty-page-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap
  }

  .loyalty-page-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: 7px
  }

  .loyalty-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px
  }

  .loyalty-stat {
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 98px;
    padding: 16px 18px;
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xs)
  }

  .loyalty-stat-icon {
    display: grid;
    place-items: center;
    flex: 0 0 46px;
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: var(--primary-subtle);
    color: var(--primary-color);
    font-size: 18px
  }

  .loyalty-stat:nth-child(2) .loyalty-stat-icon {
    background: var(--accent-subtle);
    color: var(--secondary-color)
  }

  .loyalty-stat strong {
    display: block;
    font-size: 23px;
    line-height: 1.1;
    letter-spacing: -.03em
  }

  .loyalty-stat .stat-value-text {
    font-size: 19px;
    letter-spacing: -.02em
  }

  .loyalty-stat>div>span {
    display: block;
    margin-top: 5px;
    color: var(--text-muted);
    font-size: 12px
  }

  .loyalty-rule-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px
  }

  .loyalty-rule-panel {
    min-width: 0;
    padding: 18px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--surface-raised)
  }

  .loyalty-rule-panel h3 {
    margin: 0;
    font-size: 14px;
    line-height: 1.4
  }

  .loyalty-rule-panel .rule-description {
    margin: 5px 0 14px;
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.5
  }

  .earn-rate-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    align-items: end;
    gap: 12px
  }

  .earn-rate-form .loyalty-field {
    width: 100%
  }

  .earn-rate-form .btn {
    white-space: nowrap
  }

  .earn-rate-example {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 11px 12px;
    border: 1px solid var(--status-preparing-border);
    border-radius: var(--radius-md);
    background: var(--status-preparing-bg);
    color: var(--text-secondary);
    font-size: 12px;
    line-height: 1.55
  }

  .earn-rate-example i {
    margin-top: 2px;
    color: var(--status-preparing)
  }

  .loyalty-card {
    min-width: 0;
    overflow: hidden;
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xs)
  }

  .loyalty-card[id] {
    scroll-margin-top: 84px
  }

  #redemption-rate,
  #customer-points {
    scroll-margin-top: 84px
  }

  .loyalty-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 20px 22px 16px;
    border-bottom: 1px solid var(--border-color)
  }

  .loyalty-card-head-copy {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    min-width: 0
  }

  .loyalty-section-icon {
    display: grid;
    place-items: center;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: var(--surface-raised);
    color: var(--primary-color)
  }

  .loyalty-card h2 {
    margin: 0;
    font-size: 15px;
    line-height: 1.35;
    letter-spacing: -.01em
  }

  .loyalty-card .sub {
    max-width: 760px;
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.55
  }

  .loyalty-card-body {
    padding: 18px 22px 22px
  }

  .loyalty-card-head .btn {
    flex: 0 0 auto
  }

  .loyalty-search {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 16px
  }

  .loyalty-search-field {
    position: relative;
    flex: 1;
    min-width: 180px;
    max-width: 520px
  }

  .loyalty-search-field i {
    position: absolute;
    top: 50%;
    left: 13px;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 13px;
    pointer-events: none
  }

  .loyalty-search-field .form-control {
    width: 100%;
    padding-left: 37px
  }

  .loyalty-result-count {
    margin-left: auto;
    color: var(--text-muted);
    font-size: 11px;
    white-space: nowrap
  }

  .loyalty-table tbody tr {
    transition: background-color .15s ease
  }

  .loyalty-table-wrap {
    width: 100%;
    overflow-x: auto;
    overscroll-behavior-x: contain
  }

  .loyalty-table {
    width: 100%;
    border-collapse: collapse
  }

  .loyalty-table th,
  .loyalty-table td {
    padding: 12px 13px;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
    vertical-align: middle;
    font-size: 12px
  }

  .loyalty-table th {
    background: var(--surface-raised);
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    white-space: nowrap
  }

  .loyalty-table tbody tr:last-child td {
    border-bottom: 0
  }

  .loyalty-table tbody tr:hover {
    background: var(--surface-raised)
  }

  .loyalty-table tbody tr.is-selected,
  .loyalty-table tbody tr.is-selected:hover {
    background: var(--primary-subtle)
  }

  .loyalty-table td small {
    display: block;
    color: var(--text-muted);
    margin-top: 3px;
    font-size: 11px
  }

  .loyalty-customer {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 190px
  }

  .loyalty-avatar {
    display: grid;
    place-items: center;
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--secondary-subtle);
    color: var(--secondary-color);
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase
  }

  .loyalty-customer strong {
    display: block;
    font-size: 12px
  }

  .loyalty-points {
    font-weight: 800;
    white-space: nowrap;
    font-variant-numeric: tabular-nums
  }

  .loyalty-points.negative {
    color: var(--status-cancelled)
  }

  .loyalty-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border: 1px solid var(--status-ready-border);
    border-radius: var(--radius-full);
    background: var(--status-ready-bg);
    color: var(--status-ready);
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap
  }

  .loyalty-status::before {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    content: ""
  }

  .loyalty-status.inactive {
    border-color: var(--border-color);
    background: var(--surface-raised);
    color: var(--text-muted)
  }

  .loyalty-admin-label {
    display: inline-block;
    padding: 4px 8px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-full);
    background: var(--surface-raised);
    color: var(--text-secondary);
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap
  }

  .loyalty-empty {
    text-align: center !important;
    color: var(--text-muted);
    padding: 34px 18px !important
  }

  .loyalty-ledger-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(250px, .75fr);
    gap: 16px;
    padding: 18px 22px
  }

  .loyalty-balance-panel,
  .loyalty-ledger-help {
    padding: 17px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--surface-raised)
  }

  .loyalty-balance-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px
  }

  .loyalty-balance-top h3 {
    margin: 0;
    font-size: 14px
  }

  .loyalty-balance-top p {
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 11px
  }

  .loyalty-balance-value {
    color: var(--secondary-color);
    font-size: 19px;
    font-weight: 800;
    white-space: nowrap
  }

  .loyalty-adjust-form {
    display: grid;
    grid-template-columns: minmax(120px, .55fr) minmax(180px, 1fr) auto;
    align-items: end;
    gap: 10px;
    margin-top: 16px
  }

  .loyalty-field {
    display: grid;
    gap: 5px;
    min-width: 0
  }

  .loyalty-field label {
    color: var(--text-secondary);
    font-size: 10px;
    font-weight: 700
  }

  .loyalty-field small {
    color: var(--text-muted);
    font-size: 10px;
    line-height: 1.4
  }

  .loyalty-field .form-control {
    width: 100%;
    min-width: 0
  }

  .loyalty-adjust-form .btn {
    white-space: nowrap
  }

  .loyalty-ledger-help {
    display: flex;
    gap: 12px;
    align-items: flex-start
  }

  .loyalty-ledger-help i {
    margin-top: 2px;
    color: var(--primary-color)
  }

  .loyalty-ledger-help strong {
    display: block;
    font-size: 11px
  }

  .loyalty-ledger-help p {
    margin: 5px 0 0;
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.55
  }

  .loyalty-inline {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap
  }

  .reward-cell {
    min-width: 500px
  }

  .reward-edit-form {
    display: grid;
    grid-template-columns: minmax(95px, .7fr) minmax(120px, 1fr) auto auto;
    align-items: end;
    gap: 9px
  }

  .reward-edit-form .form-control {
    width: 100%;
    min-width: 0
  }

  .reward-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    min-height: 38px;
    color: var(--text-secondary);
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap
  }

  .reward-toggle input {
    accent-color: var(--primary-color)
  }

  .reward-price {
    font-weight: 700;
    white-space: nowrap;
    font-variant-numeric: tabular-nums
  }

  .reward-config-state {
    display: inline-flex;
    align-items: center;
    margin-top: 6px;
    padding: 3px 7px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-full);
    background: var(--surface-raised);
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 700
  }

  .reward-config-state.active {
    border-color: var(--status-ready-border);
    background: var(--status-ready-bg);
    color: var(--status-ready)
  }

  .reward-config-state.paused {
    border-color: var(--status-preparing-border);
    background: var(--status-preparing-bg);
    color: var(--status-preparing)
  }

  .reward-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 14px
  }

  .reward-toolbar .loyalty-search-field {
    max-width: 380px
  }

  .reward-toolbar .form-control {
    min-height: 40px
  }

  .reward-filter-count {
    margin-left: auto;
    color: var(--text-muted);
    font-size: 11px;
    white-space: nowrap
  }

  .loyalty-audit {
    overflow: hidden
  }

  .loyalty-audit summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 19px 22px;
    cursor: pointer;
    list-style: none
  }

  .loyalty-audit summary::-webkit-details-marker {
    display: none
  }

  .loyalty-audit summary:focus-visible {
    outline: 3px solid var(--primary-subtle);
    outline-offset: -4px
  }

  .loyalty-audit summary .sub {
    display: block
  }

  .loyalty-audit summary::after {
    color: var(--text-muted);
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    content: "\f078";
    transition: transform .18s ease
  }

  .loyalty-audit[open] summary::after {
    transform: rotate(180deg)
  }

  .loyalty-audit-content {
    padding: 0 22px 22px;
    border-top: 1px solid var(--border-color)
  }

  .reward-drink {
    min-width: 175px
  }

  .reward-audit-table td {
    font-size: 11px
  }

  .reward-term {
    min-width: 130px;
    line-height: 1.5
  }

  @media(max-width:1100px) {
    .loyalty-summary {
      grid-template-columns: repeat(2, minmax(0, 1fr))
    }

    .loyalty-ledger-grid {
      grid-template-columns: 1fr
    }

    .loyalty-adjust-form {
      grid-template-columns: minmax(120px, .55fr) minmax(160px, 1fr) auto
    }
  }

  @media(max-width:900px) {
    .loyalty-rule-grid {
      grid-template-columns: 1fr
    }
  }

  @media(max-width:760px) {
    .loyalty-page {
      gap: 15px
    }

    .loyalty-summary {
      gap: 10px
    }

    .loyalty-stat {
      min-height: 82px;
      padding: 13px
    }

    .loyalty-stat-icon {
      flex-basis: 38px;
      width: 38px;
      height: 38px;
      font-size: 15px
    }

    .loyalty-stat strong {
      font-size: 20px
    }

    .loyalty-card-head {
      padding: 16px
    }

    .loyalty-card-body {
      padding: 14px 16px 17px
    }

    .loyalty-ledger-grid {
      padding: 14px 16px;
      gap: 10px
    }

    .loyalty-search {
      flex-wrap: wrap
    }

    .loyalty-result-count {
      width: 100%;
      margin: 0
    }

    .reward-toolbar {
      flex-wrap: wrap
    }

    .reward-toolbar .loyalty-search-field {
      flex-basis: 100%;
      max-width: none
    }

    .reward-filter-count {
      width: 100%;
      margin: 0
    }

    .loyalty-adjust-form {
      grid-template-columns: 1fr
    }

    .loyalty-adjust-form .btn {
      width: 100%
    }

    .reward-cell {
      min-width: 300px
    }

    .reward-edit-form {
      grid-template-columns: 1fr 1fr
    }
  }

  @media(max-width:480px) {
    .loyalty-summary {
      grid-template-columns: 1fr 1fr
    }

    .loyalty-stat {
      gap: 9px;
      padding: 11px
    }

    .loyalty-stat-icon {
      flex-basis: 32px;
      width: 32px;
      height: 32px;
      border-radius: 9px
    }

    .loyalty-stat>div>span {
      font-size: 10px
    }

    .loyalty-card-head-copy {
      gap: 9px
    }

    .loyalty-section-icon {
      flex-basis: 32px;
      width: 32px;
      height: 32px
    }

    .loyalty-search-field {
      flex-basis: 100%;
      max-width: none
    }

    .loyalty-search>.btn {
      flex: 1
    }

    .loyalty-balance-top {
      display: block
    }

    .loyalty-balance-value {
      display: block;
      margin-top: 8px
    }

    .loyalty-page-actions {
      width: 100%
    }

    .loyalty-page-actions .btn {
      flex: 1
    }

    .loyalty-rule-panel {
      padding: 14px
    }

    .loyalty-audit summary {
      padding: 16px
    }

    .loyalty-audit-content {
      padding: 0 16px 16px
    }
  }
</style>

<div class="loyalty-page">
  <div class="page-header">
    <div>
      <div class="page-header-title">Loyalty</div>
      <div class="page-header-sub">Configure earning and redemption, review customer balances, and manage drink rewards.</div>
    </div>
    <div class="loyalty-page-actions"><a class="btn btn-secondary" href="#earn-rate"><i class="fa-solid fa-sliders"></i> Program rules</a><a class="btn btn-outline" href="#customer-points"><i class="fa-solid fa-users"></i> Customers</a><a class="btn btn-outline" href="#drink-rewards"><i class="fa-solid fa-mug-hot"></i> Drink rewards</a></div>
  </div>
  <?php showFlash('global'); ?>
  <div class="loyalty-summary">
    <div class="loyalty-stat"><span class="loyalty-stat-icon"><i class="fa-solid fa-users"></i></span>
      <div><strong><?= number_format($totalCustomers) ?></strong><span>Student and faculty accounts</span></div>
    </div>
    <div class="loyalty-stat"><span class="loyalty-stat-icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
      <div><strong class="stat-value-text"><?= $spendPerPoint > 0 ? '₱' . number_format($spendPerPoint, 2) : 'Off' ?></strong><span>Spend to earn 1 point</span></div>
    </div>
    <div class="loyalty-stat"><span class="loyalty-stat-icon"><i class="fa-solid fa-tag"></i></span>
      <div><strong class="stat-value-text">₱<?= number_format((float) $redemptionRules['redemption_discount_amount'], 2) ?></strong><span>Per <?= number_format((int) $redemptionRules['redemption_points_step']) ?> points</span></div>
    </div>
    <div class="loyalty-stat"><span class="loyalty-stat-icon"><i class="fa-solid fa-mug-hot"></i></span>
      <div><strong><?= number_format($activeRewardCount) ?></strong><span>Active drink rewards</span></div>
    </div>
  </div>

  <section class="loyalty-card" id="earn-rate">
    <div class="loyalty-card-head">
      <div class="loyalty-card-head-copy"><span class="loyalty-section-icon"><i class="fa-solid fa-coins"></i></span>
        <div>
          <h2>Loyalty rules</h2>
          <p class="sub">Set how customers earn points and convert them into discounts.</p>
        </div>
      </div>
    </div>
    <div class="loyalty-card-body">
      <div class="loyalty-rule-grid">
        <div class="loyalty-rule-panel">
          <h3>Points earning rate</h3>
          <p class="rule-description">Set how much a customer spends to earn one point. Points post after a paid order is claimed.</p>
          <form method="POST" class="earn-rate-form"><?= csrfField() ?><input type="hidden" name="action" value="save_earn_rate">
            <div class="loyalty-field"><label for="spend-per-point">Spend required for 1 point</label><input class="form-control" id="spend-per-point" type="number" name="spend_per_point" min="0" max="100000" step="0.01" required value="<?= number_format($spendPerPoint, 2, '.', '') ?>"><small>Enter an amount in pesos. Set to ₱0.00 to pause earning.</small></div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save earning rate</button>
          </form>
          <div class="earn-rate-example" style="margin-top:14px"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span><?= $spendPerPoint > 0 ? 'At ₱' . number_format($spendPerPoint, 2) . ' per point, a ₱30 order earns ' . number_format((int) floor(30 / $spendPerPoint)) . ' points.' : 'Point earning is paused. Customers can still redeem existing points.' ?></span></div>
        </div>
        <div class="loyalty-rule-panel" id="redemption-rate">
          <h3>General points redemption</h3>
          <p class="rule-description">Give customers a simple discount they can use on any order. Drink rewards remain optional alternatives.</p>
          <form method="POST" class="earn-rate-form"><?= csrfField() ?><input type="hidden" name="action" value="save_redemption_rate">
            <div class="loyalty-field"><label for="redemption-points-step">Points per block</label><input class="form-control" id="redemption-points-step" type="number" name="redemption_points_step" min="1" max="100000" step="1" required value="<?= (int) $redemptionRules['redemption_points_step'] ?>"></div>
            <div class="loyalty-field"><label for="redemption-discount-amount">Discount per block (₱)</label><input class="form-control" id="redemption-discount-amount" type="number" name="redemption_discount_amount" min="0.01" max="100000" step="0.01" required value="<?= number_format((float) $redemptionRules['redemption_discount_amount'], 2, '.', '') ?>"></div>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save redemption rate</button>
          </form>
          <div class="earn-rate-example" style="margin-top:14px"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Customers redeem full blocks, up to their balance and order total. Only one redemption type can be used per order.</span></div>
        </div>
      </div>
    </div>
  </section>

  <section class="loyalty-card" id="customer-points">
    <div class="loyalty-card-head">
      <div class="loyalty-card-head-copy"><span class="loyalty-section-icon"><i class="fa-solid fa-users"></i></span>
        <div>
          <h2>Customer points</h2>
          <p class="sub">Balances come from the points ledger. Manual adjustments are recorded with the signed-in admin and a reason.</p>
        </div>
      </div>
    </div>
    <div class="loyalty-card-body">
      <form method="GET" class="loyalty-search">
        <div class="loyalty-search-field"><i class="fa-solid fa-magnifying-glass"></i><input class="form-control" type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name, school ID, or email" aria-label="Search customers"></div><button class="btn btn-secondary" type="submit">Search</button><?php if ($search !== ''): ?><a class="btn btn-ghost" href="<?= APP_URL ?>/admin/loyalty.php">Clear</a><?php endif; ?>
        <span class="loyalty-result-count">
          <?php if ($search === ''): ?>
            Showing <?= number_format(count($customers)) ?> of <?= number_format($totalCustomers) ?> accounts
          <?php else: ?>
            <?= number_format(count($customers)) ?> matching <?= count($customers) === 1 ? 'account' : 'accounts' ?>
          <?php endif; ?>
          <?= count($customers) >= 250 ? ' (list limited to 250)' : '' ?>
        </span>
      </form>
      <div class="loyalty-table-wrap">
        <table class="loyalty-table">
          <thead>
            <tr>
              <th>Customer</th>
              <th>Account</th>
              <th>Points balance</th>
              <th>Status</th>
              <th><span class="sr-only">Ledger</span></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$customers): ?><tr>
                <td colspan="5" class="loyalty-empty"><i class="fa-regular fa-face-meh" aria-hidden="true"></i><br>No customer accounts match this search.</td>
              </tr><?php else: foreach ($customers as $customer): ?>
                <tr class="<?= ($customer['customer_type'] === $selectedType && (int) $customer['customer_id'] === $selectedId) ? 'is-selected' : '' ?>">
                  <td>
                    <div class="loyalty-customer"><span class="loyalty-avatar" aria-hidden="true"><?= e(mb_substr($customer['full_name'], 0, 1)) ?></span><span><strong><?= e($customer['full_name']) ?></strong><small><?= e($customer['email'] ?? '') ?></small></span></div>
                  </td>
                  <td><?= e(ucfirst($customer['customer_type'])) ?><small><?= e($customer['identifier']) ?></small></td>
                  <td class="loyalty-points"><?= number_format((int) $customer['balance']) ?> pts</td>
                  <td><span class="loyalty-status<?= (int) $customer['is_active'] ? '' : ' inactive' ?>"><?= (int) $customer['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                  <td><a class="btn btn-sm btn-secondary" href="<?= APP_URL ?>/admin/loyalty.php?<?= e(http_build_query(array_filter(['q' => $search, 'customer_type' => $customer['customer_type'], 'customer_id' => (int) $customer['customer_id']]))) ?>#customer-ledger">View ledger</a></td>
                </tr>
            <?php endforeach;
                  endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <?php if ($selectedCustomer): ?>
    <section class="loyalty-card" id="customer-ledger">
      <div class="loyalty-card-head">
        <div class="loyalty-card-head-copy"><span class="loyalty-section-icon"><i class="fa-solid fa-clock-rotate-left"></i></span>
          <div>
            <h2><?= e($selectedCustomer['full_name']) ?>’s points ledger</h2>
            <p class="sub"><?= e(ucfirst($selectedType)) ?> · <?= e($selectedCustomer['identifier']) ?><?= !(int) $selectedCustomer['is_active'] ? ' · inactive account' : '' ?></p>
          </div>
        </div>
      </div>
      <div class="loyalty-ledger-grid">
        <div class="loyalty-balance-panel">
          <div class="loyalty-balance-top">
            <div>
              <h3>Current balance</h3>
              <p>Adjustments are signed: use a minus to remove points.</p>
            </div><span class="loyalty-balance-value"><?= number_format($selectedCustomer['balance']) ?> pts</span>
          </div>
          <form method="POST" class="loyalty-adjust-form"><?= csrfField() ?><input type="hidden" name="action" value="adjust_points"><input type="hidden" name="customer_type" value="<?= e($selectedType) ?>"><input type="hidden" name="customer_id" value="<?= (int) $selectedCustomer['id'] ?>">
            <div class="loyalty-field"><label for="points-delta">Points to add or remove</label><input class="form-control" id="points-delta" type="number" name="points_delta" min="-100000" max="100000" step="1" required placeholder="e.g. 50 or -20"><small>Use a positive value to add, negative to remove.</small></div>
            <div class="loyalty-field"><label for="points-note">Reason for adjustment</label><input class="form-control" id="points-note" type="text" name="note" maxlength="255" minlength="5" required placeholder="e.g. Corrected missed order points"></div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus-minus"></i> Post adjustment</button>
          </form>
        </div>
        <aside class="loyalty-ledger-help"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          <div><strong>How points move</strong>
            <p>Points post after paid pickup. Redemptions are deducted at checkout; cancellations add reversal entries. Manual changes are recorded with your reason and admin account.</p>
          </div>
        </aside>
      </div>
      <div class="loyalty-card-body" style="padding-top:0">
        <div class="loyalty-table-wrap">
          <table class="loyalty-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Entry</th>
                <th>Points</th>
                <th>Value</th>
                <th>Order / drink / reason</th>
                <th>Recorded by</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$customerHistory): ?><tr>
                  <td colspan="6" class="loyalty-empty">No ledger entries yet.</td>
                </tr><?php else: foreach ($customerHistory as $entry): ?>
                  <tr>
                    <td><?= e(date('M j, Y g:i A', strtotime($entry['created_at']))) ?></td>
                    <td><?= e(str_replace('_', ' ', ucfirst($entry['entry_type']))) ?></td>
                    <td class="loyalty-points <?= (int) $entry['points_delta'] < 0 ? 'negative' : '' ?>"><?= (int) $entry['points_delta'] > 0 ? '+' : '' ?><?= number_format((int) $entry['points_delta']) ?></td>
                    <td><?= (float) $entry['amount_value'] != 0.0 ? '₱' . number_format((float) $entry['amount_value'], 2) : '—' ?></td>
                    <td><?= $entry['order_number'] ? 'Order ' . e($entry['order_number']) : 'Manual entry' ?><?php if ($entry['reward_product_id']): ?><small><?= e($entry['note'] ?: ('Reward product #' . (int) $entry['reward_product_id'])) ?></small><?php elseif ($entry['note']): ?><small><?= e($entry['note']) ?></small><?php endif; ?></td>
                    <td><?= e(ucfirst($entry['actor_type'])) ?><?= $entry['actor_id'] ? ' #' . (int) $entry['actor_id'] : '' ?></td>
                  </tr>
              <?php endforeach;
                    endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <section class="loyalty-card" id="drink-rewards">
    <div class="loyalty-card-head">
      <div class="loyalty-card-head-copy"><span class="loyalty-section-icon"><i class="fa-solid fa-mug-hot"></i></span>
        <div>
          <h2>Drink-specific rewards</h2>
          <p class="sub">Set the points required and the discount for one unit of a drink. The discount cannot exceed its base price; each change is saved to the audit history.</p>
        </div>
      </div>
    </div>
    <div class="loyalty-card-body">
      <div class="reward-toolbar">
        <div class="loyalty-search-field"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input class="form-control" id="rewardSearch" type="search" placeholder="Search drinks" aria-label="Search drink rewards"></div>
        <select class="form-control" id="rewardStatusFilter" aria-label="Filter drink rewards" style="width:auto;min-width:150px">
          <option value="all">All drinks</option>
          <option value="active">Active rewards</option>
          <option value="paused">Paused rewards</option>
          <option value="unset">No reward set</option>
        </select>
        <span class="reward-filter-count" id="rewardFilterCount"><?= number_format(count($products)) ?> drinks</span>
      </div>
      <div class="loyalty-table-wrap">
        <table class="loyalty-table">
          <thead>
            <tr>
              <th>Drink</th>
              <th>Base price</th>
              <th>Reward setup</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $product): $rewardStatus = $product['points_required'] === null ? 'unset' : ((int) ($product['is_active'] ?? 0) ? 'active' : 'paused'); ?><tr class="reward-row" data-name="<?= e(strtolower($product['name'])) ?>" data-status="<?= e($rewardStatus) ?>">
                <td class="reward-drink"><strong><?= e($product['name']) ?></strong><span class="reward-config-state <?= e($rewardStatus) ?>"><?= $rewardStatus === 'unset' ? 'Not configured' : ($rewardStatus === 'active' ? 'Active reward' : 'Paused') ?></span><?php if (!(int) $product['is_available']): ?><small>Not currently available for sale</small><?php endif; ?></td>
                <td class="reward-price">₱<?= number_format((float) $product['price'], 2) ?></td>
                <td class="reward-cell">
                  <form method="POST" class="reward-edit-form"><?= csrfField() ?><input type="hidden" name="action" value="save_drink_reward"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                    <div class="loyalty-field"><label for="reward-points-<?= (int) $product['id'] ?>">Points required</label><input class="form-control" id="reward-points-<?= (int) $product['id'] ?>" type="number" name="points_required" min="1" max="100000" step="1" required value="<?= $product['points_required'] !== null ? (int) $product['points_required'] : '' ?>" placeholder="e.g. 500"></div>
                    <div class="loyalty-field"><label for="reward-discount-<?= (int) $product['id'] ?>">Discount (₱)</label><input class="form-control" id="reward-discount-<?= (int) $product['id'] ?>" type="number" name="discount_amount" min="0.01" max="<?= number_format(max((float) $product['price'], 0.01), 2, '.', '') ?>" step="0.01" required value="<?= $product['discount_amount'] !== null ? number_format((float) $product['discount_amount'], 2, '.', '') : '' ?>" placeholder="e.g. 20.00"></div><label class="reward-toggle"><input type="checkbox" name="is_active" value="1" <?= (int) ($product['is_active'] ?? 0) ? 'checked' : '' ?>> Active</label><button class="btn btn-sm btn-primary" type="submit">Save</button>
                  </form>
                </td>
              </tr><?php endforeach; ?>
            <tr id="rewardNoResults" hidden><td colspan="3" class="loyalty-empty">No drinks match those filters.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <details class="loyalty-card loyalty-audit">
    <summary>
      <span class="loyalty-card-head-copy"><span class="loyalty-section-icon"><i class="fa-solid fa-list-check"></i></span>
        <span><span style="display:block;font-size:15px;font-weight:700">Reward rule history</span><span class="sub">Latest <?= number_format(count($rewardAudit)) ?> changes, with previous and new terms.</span></span>
      </span>
    </summary>
    <div class="loyalty-audit-content">
      <div class="loyalty-table-wrap">
        <table class="loyalty-table reward-audit-table">
          <thead>
            <tr>
              <th>Changed</th>
              <th>Drink</th>
              <th>Previous rule</th>
              <th>New rule</th>
              <th>Admin</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rewardAudit): ?><tr>
                <td colspan="5" class="loyalty-empty">No drink reward changes recorded.</td>
              </tr><?php else: foreach ($rewardAudit as $audit): ?>
                <tr>
                  <td><?= e(date('M j, Y g:i A', strtotime($audit['created_at']))) ?></td>
                  <td><strong><?= e($audit['product_name_snapshot']) ?></strong></td>
                  <td class="reward-term"><?= $audit['old_points_required'] === null ? 'New rule' : number_format((int) $audit['old_points_required']) . ' pts · ₱' . number_format((float) $audit['old_discount_amount'], 2) . ((int) $audit['old_is_active'] ? ' · active' : ' · paused') ?></td>
                  <td class="reward-term"><?= number_format((int) $audit['new_points_required']) ?> pts · ₱<?= number_format((float) $audit['new_discount_amount'], 2) ?><?= (int) $audit['new_is_active'] ? ' · active' : ' · paused' ?></td>
                  <td><span class="loyalty-admin-label">Admin #<?= (int) $audit['actor_id'] ?></span></td>
                </tr>
            <?php endforeach;
                  endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </details>
</div>
<script>
  (() => {
    const search = document.getElementById('rewardSearch');
    const status = document.getElementById('rewardStatusFilter');
    const count = document.getElementById('rewardFilterCount');
    const empty = document.getElementById('rewardNoResults');
    const rows = Array.from(document.querySelectorAll('.reward-row'));
    if (!search || !status || !count || !empty) return;

    const filterRewards = () => {
      const query = search.value.trim().toLocaleLowerCase();
      const selectedStatus = status.value;
      let visible = 0;
      rows.forEach((row) => {
        const matches = row.dataset.name.includes(query)
          && (selectedStatus === 'all' || row.dataset.status === selectedStatus);
        row.hidden = !matches;
        if (matches) visible += 1;
      });
      empty.hidden = visible > 0;
      count.textContent = `${visible} of ${rows.length} drinks`;
    };

    search.addEventListener('input', filterRewards);
    status.addEventListener('change', filterRewards);
  })();
</script>
<?php layoutFooter(); ?>
