<?php

/**
 * Loyalty point rules and ledger operations.
 *
 * Loyalty rules are stored in app_settings. One redemption is allowed per
 * order, and the discount is always capped at P10.
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
  $redeemPoints = filter_var(getSetting('loyalty_redeem_points', '100'), FILTER_VALIDATE_INT);
  $pointsPerTen = filter_var(getSetting('loyalty_points_per_10_spent', '1'), FILTER_VALIDATE_INT);
  $discount = filter_var(getSetting('loyalty_redeem_discount', '10.00'), FILTER_VALIDATE_FLOAT);
  return [
    'redeem_points' => max(1, min(100000, $redeemPoints === false ? 100 : $redeemPoints)),
    'discount_cents' => max(1, min(1000, (int) round(($discount === false ? 10 : $discount) * 100))),
    'points_per_10_spent' => max(0, min(100, $pointsPerTen === false ? 1 : $pointsPerTen)),
  ];
}

function loyaltyPointsFromSpend(PDO $db, float $amount): int
{
  $cents = max(0, (int) round($amount * 100));
  return intdiv($cents, 1000) * loyaltyRules($db)['points_per_10_spent'];
}

function loyaltyRedemptionFor(PDO $db, int $balance, float $amount): array
{
  $rules = loyaltyRules($db);
  $amountCents = max(0, (int) round($amount * 100));
  $canRedeem = $balance >= $rules['redeem_points'] && $amountCents >= $rules['discount_cents'];
  return [
    'points' => $canRedeem ? $rules['redeem_points'] : 0,
    'discount' => $canRedeem ? $rules['discount_cents'] / 100 : 0.0,
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
  ?int $actorId
): array {
  if (!$db->inTransaction()) throw new LogicException('Loyalty redemption must run inside the order transaction.');
  if (!$requested) return ['points' => 0, 'discount' => 0.0, 'balance' => loyaltyPointsBalance($db, $customerType, $customerId)];
  if (!loyaltyLedgerAvailable($db)) throw new RuntimeException('Loyalty points are unavailable until the database migration is applied.');

  $config = loyaltyAccountConfig($customerType);
  if (!$config || $customerId < 1) throw new RuntimeException('Choose a valid student or faculty account before redeeming points.');
  $lock = $db->prepare('SELECT id FROM ' . $config['table'] . ' WHERE id = ? AND is_active = 1 FOR UPDATE');
  $lock->execute([$customerId]);
  if (!$lock->fetchColumn()) throw new RuntimeException('That loyalty account is no longer active.');

  $balance = loyaltyPointsBalance($db, $customerType, $customerId);
  $rules = loyaltyRules($db);
  $redemption = loyaltyRedemptionFor($db, $balance, $amount);
  if ($redemption['points'] < $rules['redeem_points']) {
    throw new RuntimeException($balance < $rules['redeem_points']
      ? 'This account no longer has enough points to redeem.'
      : 'The order total must be at least ₱' . number_format($rules['discount_cents'] / 100, 2) . ' to redeem points.');
  }

  $db->prepare(
    "INSERT INTO loyalty_points_ledger (customer_type, customer_id, order_id, entry_type, points_delta, amount_value, actor_type, actor_id, note)
     VALUES (?, ?, ?, 'redeem', ?, ?, ?, ?, ?)"
  )->execute([
    $customerType,
    $customerId,
    $orderId,
    -$redemption['points'],
    $redemption['discount'],
    $actorType,
    $actorId,
    'Redeemed at checkout',
  ]);

  return [
    'points' => $redemption['points'],
    'discount' => $redemption['discount'],
    'balance' => $balance - $redemption['points'],
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
      'INSERT INTO loyalty_points_ledger (customer_type, customer_id, order_id, entry_type, points_delta, amount_value, actor_type, actor_id, note)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
      $entry['customer_type'],
      $entry['customer_id'],
      $orderId,
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
    'SELECT l.entry_type, l.points_delta, l.amount_value, l.note, l.created_at, o.order_number
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
    'SELECT entry_type, points_delta, amount_value FROM loyalty_points_ledger WHERE order_id = ? ORDER BY id ASC'
  );
  $stmt->execute([$orderId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
