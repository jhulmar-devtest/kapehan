<?php
// ============================================================
// public/api/checkout.php
//
// Replaces the checkout half of student/cart.php and faculty/cart.php.
// Called via fetch() from the cart sidebar on the unified menu page.
// Works for both ROLE_STUDENT and ROLE_FACULTY — the only difference
// between the two is which FK column on `orders` gets the user's id.
// ============================================================

require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/loyalty.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/order-pricing.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array(currentRole(), [ROLE_STUDENT, ROLE_FACULTY], true)) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'reason' => 'auth_required']);
  exit;
}

verifyCsrfAjax();
date_default_timezone_set('Asia/Manila');

$db   = Database::getInstance();
$role = currentRole();

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$items       = $data['items'] ?? [];
$ref         = sanitizeString($data['reference_no'] ?? '', 100);
$notes       = sanitizeString($data['notes'] ?? '', 500);
$pickupDate  = sanitizeString($data['pickup_date'] ?? '', 10);
$pickupTime  = sanitizeString($data['pickup_time'] ?? '', 10);
$redeemPoints = filter_var($data['redeem_points'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Pickup is same-day only. Validate the chosen slot itself rather than
// rejecting orders just because the current time is outside store hours.
$openTime  = getSetting('store_open_time', '07:00');
$closeTime = getSetting('store_close_time', '20:00');

if (empty($items)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'Your cart is empty.']);
  exit;
}
if ($pickupDate !== date('Y-m-d') || empty($pickupTime)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'reason' => 'closed', 'message' => 'Orders are available for pickup today only.']);
  exit;
}

$slotTimestamp = DateTime::createFromFormat('!Y-m-d H:i', $pickupDate . ' ' . $pickupTime);
$openTimestamp = DateTime::createFromFormat('!Y-m-d H:i', $pickupDate . ' ' . $openTime);
$closeTimestamp = DateTime::createFromFormat('!Y-m-d H:i', $pickupDate . ' ' . $closeTime);
$slotIntervalSeconds = max(5, (int) getSetting('pickup_slot_interval_minutes', '15')) * 60;
if (
  !$slotTimestamp || !$openTimestamp || !$closeTimestamp ||
  $slotTimestamp->format('H:i') !== $pickupTime ||
  $slotTimestamp < $openTimestamp || $slotTimestamp >= $closeTimestamp ||
  (($slotTimestamp->getTimestamp() - $openTimestamp->getTimestamp()) % $slotIntervalSeconds !== 0) ||
  $slotTimestamp->getTimestamp() < (time() + PICKUP_MIN_LEAD_MINUTES * 60)
) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'reason' => 'slot_unavailable', 'message' => 'That pickup time has passed or is outside store hours. Please choose a current available time.']);
  exit;
}

// Pre-orders are GCash-only — no cash, no other e-wallets/banks. A
// reference number is always required since there's no cash fallback.
$method = 'GCash';
if (gcashPaymentNumber() === '' || gcashPaymentName() === '') {
  http_response_code(503);
  echo json_encode(['ok' => false, 'message' => 'Online ordering is temporarily unavailable while the shop configures its GCash account.']);
  exit;
}
if (empty($ref)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'GCash reference number is required.']);
  exit;
}

// Catalog prices, size adjustments and add-ons are re-read from the database.
$total = 0;
$details = [];
try {
  foreach ($items as $item) {
    $line = priceOrderLine($db, is_array($item) ? $item : []);
    $total += $line['sub'];
    $details[] = $line;
  }
} catch (RuntimeException $e) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
  exit;
}

$db->beginTransaction();
try {
  // The per-slot row serializes concurrent checkouts for the same pickup
  // time. The count and insert now happen while holding this row lock.
  $db->prepare(
    'INSERT INTO pickup_slot_locks (pickup_date, pickup_time) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
  )->execute([$pickupDate, $pickupTime]);
  $slotLock = $db->prepare('SELECT pickup_date FROM pickup_slot_locks WHERE pickup_date = ? AND pickup_time = ? FOR UPDATE');
  $slotLock->execute([$pickupDate, $pickupTime]);
  $slotStmt = $db->prepare(
    "SELECT COUNT(*) FROM orders WHERE pickup_date = ? AND pickup_time = ? AND status IN ('pending','preparing','ready')"
  );
  $slotStmt->execute([$pickupDate, $pickupTime]);
  if ((int) $slotStmt->fetchColumn() >= (int) getSetting('pickup_slot_capacity', '6')) {
    throw new RuntimeException('That pickup time just filled up. Please choose another time.');
  }

  $studentId = $role === ROLE_STUDENT ? currentUserId() : null;
  $facultyId = $role === ROLE_FACULTY ? currentUserId() : null;
  $customerId = (int) currentUserId();
  $redemption = ['points' => 0, 'discount' => 0.0];
  if ($redeemPoints) {
    $accountConfig = loyaltyAccountConfig($role);
    $accountLock = $db->prepare('SELECT id FROM ' . $accountConfig['table'] . ' WHERE id = ? AND is_active = 1 FOR UPDATE');
    $accountLock->execute([$customerId]);
    if (!$accountLock->fetchColumn()) throw new RuntimeException('Your account cannot redeem points right now.');
    $redemption = loyaltyRedemptionFor(loyaltyPointsBalance($db, $role, $customerId), $total);
    if ($redemption['points'] < LOYALTY_REDEEM_POINTS_PER_BLOCK) {
      throw new RuntimeException('You need at least 100 points and ₱10 in the order to redeem.');
    }
  }
  $total = round($total - $redemption['discount'], 2);

  // generateOrderNumber() counts today's orders and adds 1 — under
  // concurrent checkouts (a lunch rush, or a customer and a cashier
  // both finishing at the same moment) two requests can read the same
  // count and collide on the UNIQUE order_number constraint, failing
  // an otherwise-valid order with a confusing "please try again."
  // Retry with a freshly generated number on that specific collision
  // instead of surfacing it to the customer.
  $maxAttempts = 5;
  for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $orderNo = generateOrderNumber();
    try {
      $db->prepare(
        "INSERT INTO orders (order_number, order_type, status, student_id, faculty_id, total_amount, notes, pickup_time, pickup_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
      )->execute([$orderNo, ORDER_PREORDER, STATUS_PENDING, $studentId, $facultyId, $total, $notes, $pickupTime, $pickupDate]);
      break; // success
    } catch (\PDOException $e) {
      if ($e->getCode() === '23000' && $attempt < $maxAttempts) {
        continue; // order_number collision — regenerate and retry
      }
      throw $e; // a different failure, or retries exhausted — let the outer catch handle it
    }
  }
  $orderId = (int) $db->lastInsertId();

  $stmt = $db->prepare(
    "INSERT INTO order_details (order_id, product_id, quantity, price_at_time, subtotal, customization_note)
     VALUES (?, ?, ?, ?, ?, ?)"
  );
  foreach ($details as $d) {
    $stmt->execute([$orderId, $d['product_id'], $d['qty'], $d['price'], $d['sub'], $d['note']]);
    saveOrderLineAddonSnapshots($db, (int) $db->lastInsertId(), $d['addons']);
  }

  reserveInventoryForOrder($db, $orderId, $role, (int) currentUserId());

  // The submitted reference is customer-provided and must be verified by
  // cashier staff before it is recorded as paid.
  $payStatus = PAY_STATUS_PENDING;
  $paidAt    = null;
  $db->prepare(
    "INSERT INTO payments (order_id, payment_method, amount_paid, payment_status, reference_number, recipient_name, recipient_number, paid_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
  )->execute([$orderId, $method, $total, $payStatus, $ref ?: null, gcashPaymentName(), gcashPaymentNumber(), $paidAt]);

  $redemptionResult = loyaltyRedeemForOrder($db, $role, $customerId, $orderId, array_sum(array_column($details, 'sub')), $redeemPoints, $role, $customerId);

  $db->commit();
  auditLog($role, currentUserId(), 'place_preorder', 'orders', $orderId);

  echo json_encode([
    'ok'           => true,
    'order_number' => $orderNo,
    'total'        => $total,
    'points_redeemed' => $redemptionResult['points'],
    'loyalty_discount' => $redemptionResult['discount'],
    'loyalty_balance' => $redemptionResult['balance'],
    'points_expected_after_pickup' => loyaltyPointsFromSpend($total),
    'message'      => "Order {$orderNo} placed! Show your school ID when claiming.",
  ]);
} catch (\Throwable $e) {
  $db->rollBack();
  error_log('checkout failed: ' . $e->getMessage());
  http_response_code($e instanceof RuntimeException ? 422 : 500);
  echo json_encode(['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Order failed. Please try again.']);
}
