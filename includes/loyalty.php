<?php

/**
 * Loyalty point rules and ledger operations.
 *
 * Earn rates live in app_settings; redemptions are configured per drink.
 * Every earn, redemption, reversal, and manual adjustment is stored in the ledger.
 */

function loyaltyLedgerAvailable(PDO $db): bool
{
  static $available = null;
  if ($available === null) {
    try {
      $available = (bool) $db->query("SHOW TABLES LIKE 'loyalty_points_ledger'")->fetchColumn();
    } catch (Throwable $e) {
      $available = false;
    }
  }
  return $available;
}

function loyaltyAccountConfig(string $type): ?array
{
  return match ($type) {
    ROLE_STUDENT => ['table' => 'students', 'identifier' => 'student_id_no', 'role' => ROLE_STUDENT],
    ROLE_FACULTY => ['table' => 'faculty', 'identifier' => 'faculty_id_no', 'role' => ROLE_FACULTY],
    default => null,
  };
}

function loyaltyRules(PDO $db): array
{
  $pointsPerTen = filter_var(getSetting('loyalty_points_per_10_spent', '1'), FILTER_VALIDATE_INT);
  return [
    'points_per_10_spent' => max(0, min(100, $pointsPerTen === false ? 1 : $pointsPerTen)),
  ];
}

function loyaltyPointsFromSpend(PDO $db, float $amount): int
{
  $cents = max(0, (int) round($amount * 100));
  return intdiv($cents, 1000) * loyaltyRules($db)['points_per_10_spent'];
}

function loyaltyProductRewards(PDO $db): array
{
  if (!loyaltyLedgerAvailable($db)) return [];
  $stmt = $db->query(
    "SELECT r.product_id, p.name, p.price, r.points_required, r.discount_amount
       FROM loyalty_product_rewards r
       JOIN products p ON p.id = r.product_id
       JOIN categories c ON c.id = p.category_id
      WHERE r.is_active = 1 AND p.is_available = 1 AND c.name != 'Add-ons'
      ORDER BY p.name, p.id"
  );
  return array_map(static fn(array $row): array => [
    'product_id' => (int) $row['product_id'],
    'name' => (string) $row['name'],
    'price' => (float) $row['price'],
    'points_required' => (int) $row['points_required'],
    'discount_amount' => (float) $row['discount_amount'],
  ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Validate a reward against a priced order detail, never client-supplied prices. */
function loyaltyRedemptionForProduct(PDO $db, int $balance, int $productId, array $details, float $availableTotal): array
{
  $empty = ['points' => 0, 'discount' => 0.0, 'product_id' => null, 'product_name' => null, 'message' => 'Choose a valid drink reward.'];
  if ($productId < 1) return $empty;
  $stmt = $db->prepare(
    "SELECT r.product_id, r.points_required, r.discount_amount, p.name, p.price
       FROM loyalty_product_rewards r
       JOIN products p ON p.id = r.product_id
       JOIN categories c ON c.id = p.category_id
      WHERE r.product_id = ? AND r.is_active = 1 AND p.is_available = 1 AND c.name != 'Add-ons'" . ($db->inTransaction() ? ' FOR UPDATE' : '')
  );
  $stmt->execute([$productId]);
  $reward = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$reward) return [...$empty, 'message' => 'That drink has no active loyalty reward.'];

  $line = null;
  foreach ($details as $detail) {
    if ((int) ($detail['product_id'] ?? 0) === $productId) {
      $line = $detail;
      break;
    }
  }
  if ($line === null) return [...$empty, 'message' => 'Add the selected reward drink to the order first.'];
  if ($balance < (int) $reward['points_required']) {
    return [...$empty, 'message' => 'This account no longer has enough points for that drink reward.'];
  }

  $discountCents = (int) round((float) $reward['discount_amount'] * 100);
  $productCents = (int) round((float) $reward['price'] * 100);
  $lineCents = (int) round((float) ($line['price'] ?? $line['unit_price'] ?? $line['sub'] ?? 0) * 100);
  $availableCents = max(0, (int) round($availableTotal * 100));
  if ($discountCents < 1 || $discountCents > $productCents || $discountCents > $lineCents || $discountCents > $availableCents) {
    return [...$empty, 'message' => 'The selected reward no longer matches this drink price or order total.'];
  }

  return [
    'points' => (int) $reward['points_required'],
    'discount' => $discountCents / 100,
    'product_id' => (int) $reward['product_id'],
    'product_name' => (string) $reward['name'],
    'message' => null,
  ];
}

function loyaltyPointsBalance(PDO $db, string $customerType, int $customerId): int
{
  $config = loyaltyAccountConfig($customerType);
  if (!$config || $customerId < 1 || !loyaltyLedgerAvailable($db)) return 0;
  $stmt = $db->prepare(
    'SELECT COALESCE(SUM(points_delta), 0) FROM loyalty_points_ledger WHERE customer_type = ? AND customer_id = ?'
  );
  $stmt->execute([$customerType, $customerId]);
  return max(0, (int) $stmt->fetchColumn());
}

/** Find a student/faculty account by the ID used at the shop counter. */
function loyaltyFindAccount(PDO $db, string $customerType, string $identifier, bool $forUpdate = false): ?array
{
  $config = loyaltyAccountConfig($customerType);
  $identifier = trim($identifier);
  if (!$config || $identifier === '' || mb_strlen($identifier) > 30) return null;

  $sql = 'SELECT id, full_name, ' . $config['identifier'] . ' AS account_identifier'
    . ' FROM ' . $config['table'] . ' WHERE ' . $config['identifier'] . ' = ? AND is_active = 1 LIMIT 1';
  if ($forUpdate) $sql .= ' FOR UPDATE';
  $stmt = $db->prepare($sql);
  $stmt->execute([$identifier]);
  $account = $stmt->fetch(PDO::FETCH_ASSOC);
  return $account ?: null;
}

/** Reserve redeemed points inside the order transaction. */
function loyaltyRedeemForOrder(
  PDO $db,
  string $customerType,
  int $customerId,
  int $orderId,
  float $amount,
  bool $requested,
  string $actorType,
  ?int $actorId,
  ?int $rewardProductId = null,
  array $details = []
): array {
  if (!$db->inTransaction()) throw new LogicException('Loyalty redemption must run inside the order transaction.');
  if (!$requested) return ['points' => 0, 'discount' => 0.0, 'balance' => loyaltyPointsBalance($db, $customerType, $customerId), 'product_id' => null, 'product_name' => null];
  if (!loyaltyLedgerAvailable($db)) throw new RuntimeException('Loyalty points are unavailable until the database migration is applied.');

  $config = loyaltyAccountConfig($customerType);
  if (!$config || $customerId < 1) throw new RuntimeException('Choose a valid student or faculty account before redeeming points.');
  $lock = $db->prepare('SELECT id FROM ' . $config['table'] . ' WHERE id = ? AND is_active = 1 FOR UPDATE');
  $lock->execute([$customerId]);
  if (!$lock->fetchColumn()) throw new RuntimeException('That loyalty account is no longer active.');

  $balance = loyaltyPointsBalance($db, $customerType, $customerId);
  $redemption = loyaltyRedemptionForProduct($db, $balance, (int) $rewardProductId, $details, $amount);
  if ($redemption['points'] < 1) throw new RuntimeException($redemption['message'] ?? 'That drink reward is no longer available.');

  $db->prepare(
    "INSERT INTO loyalty_points_ledger (customer_type, customer_id, order_id, reward_product_id, entry_type, points_delta, amount_value, actor_type, actor_id, note)
     VALUES (?, ?, ?, ?, 'redeem', ?, ?, ?, ?, ?)"
  )->execute([
    $customerType,
    $customerId,
    $orderId,
    $redemption['product_id'],
    -$redemption['points'],
    $redemption['discount'],
    $actorType,
    $actorId,
    'Redeemed for ' . $redemption['product_name'] . ' (product #' . $redemption['product_id'] . ')',
  ]);

  return [
    'points' => $redemption['points'],
    'discount' => $redemption['discount'],
    'balance' => $balance - $redemption['points'],
    'product_id' => $redemption['product_id'],
    'product_name' => $redemption['product_name'],
  ];
}

/** Award points once, after a paid order has been handed over. */
function loyaltyAwardOrder(PDO $db, int $orderId, string $actorType, ?int $actorId): int
{
  if (!$db->inTransaction()) throw new LogicException('Loyalty awards must run inside the order transaction.');
  if (!loyaltyLedgerAvailable($db)) return 0;
  $stmt = $db->prepare(
    'SELECT o.status, o.total_amount, o.student_id, o.faculty_id, p.payment_status
       FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.id = ? FOR UPDATE'
  );
  $stmt->execute([$orderId]);
  $order = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$order || $order['status'] !== STATUS_CLAIMED || $order['payment_status'] !== PAY_STATUS_PAID) return 0;

  if (!empty($order['student_id']) && !empty($order['faculty_id'])) {
    throw new RuntimeException('This order is linked to more than one loyalty account.');
  }
  if (!empty($order['student_id'])) {
    $customerType = ROLE_STUDENT;
    $customerId = (int) $order['student_id'];
  } elseif (!empty($order['faculty_id'])) {
    $customerType = ROLE_FACULTY;
    $customerId = (int) $order['faculty_id'];
  } else {
    return 0;
  }

  $config = loyaltyAccountConfig($customerType);
  $lock = $db->prepare('SELECT id FROM ' . $config['table'] . ' WHERE id = ? FOR UPDATE');
  $lock->execute([$customerId]);
  if (!$lock->fetchColumn()) return 0;

  $exists = $db->prepare("SELECT id FROM loyalty_points_ledger WHERE order_id = ? AND entry_type = 'earn' LIMIT 1");
  $exists->execute([$orderId]);
  if ($exists->fetchColumn()) return 0;

  $points = loyaltyPointsFromSpend($db, (float) $order['total_amount']);
  if ($points < 1) return 0;
  $db->prepare(
    "INSERT INTO loyalty_points_ledger (customer_type, customer_id, order_id, entry_type, points_delta, amount_value, actor_type, actor_id, note)
     VALUES (?, ?, ?, 'earn', ?, ?, ?, ?, ?)"
  )->execute([
    $customerType,
    $customerId,
    $orderId,
    $points,
    $order['total_amount'],
    $actorType,
    $actorId,
    'Earned from paid order',
  ]);
  return $points;
}

/** Restore an order's redemption if a cashier cancels it. Also reverses an
 * award if a completed order is later cancelled through the same route. */
function loyaltyReverseOrder(PDO $db, int $orderId, string $actorType, ?int $actorId): void
{
  if (!$db->inTransaction()) throw new LogicException('Loyalty reversals must run inside the order transaction.');
  if (!loyaltyLedgerAvailable($db)) return;
  $stmt = $db->prepare("SELECT * FROM loyalty_points_ledger WHERE order_id = ? AND entry_type IN ('redeem', 'earn')");
  $stmt->execute([$orderId]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
    $reverseType = $entry['entry_type'] === 'redeem' ? 'redeem_reversal' : 'earn_reversal';
    $exists = $db->prepare('SELECT id FROM loyalty_points_ledger WHERE order_id = ? AND entry_type = ? LIMIT 1');
    $exists->execute([$orderId, $reverseType]);
    if ($exists->fetchColumn()) continue;
    $delta = (int) $entry['points_delta'];
    $db->prepare(
      'INSERT INTO loyalty_points_ledger (customer_type, customer_id, order_id, reward_product_id, entry_type, points_delta, amount_value, actor_type, actor_id, note)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
      $entry['customer_type'],
      $entry['customer_id'],
      $orderId,
      $entry['reward_product_id'],
      $reverseType,
      -$delta,
      $entry['amount_value'],
      $actorType,
      $actorId,
      $entry['entry_type'] === 'redeem' ? 'Points restored because the order was cancelled' : 'Points reversed because the order was cancelled',
    ]);
  }
}

function loyaltyHistory(PDO $db, string $customerType, int $customerId, int $limit = 30): array
{
  $config = loyaltyAccountConfig($customerType);
  if (!$config || $customerId < 1 || !loyaltyLedgerAvailable($db)) return [];
  $limit = max(1, min(100, $limit));
  $stmt = $db->prepare(
    'SELECT l.entry_type, l.points_delta, l.amount_value, l.reward_product_id, l.note, l.actor_type, l.actor_id, l.created_at, o.order_number
       FROM loyalty_points_ledger l LEFT JOIN orders o ON o.id = l.order_id
      WHERE l.customer_type = ? AND l.customer_id = ?
      ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $limit
  );
  $stmt->execute([$customerType, $customerId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loyaltyHistoryForOrder(PDO $db, int $orderId): array
{
  if (!loyaltyLedgerAvailable($db)) return [];
  $stmt = $db->prepare(
    'SELECT entry_type, points_delta, amount_value, reward_product_id, note FROM loyalty_points_ledger WHERE order_id = ? ORDER BY id ASC'
  );
  $stmt->execute([$orderId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
