<?php
// ============================================================
// public/api/checkout.php
//
// Replaces the checkout half of student/cart.php and faculty/cart.php.
// Called via fetch() from the cart sidebar on the unified menu page.
// Works for both ROLE_STUDENT and ROLE_FACULTY — the only difference
// between the two is which FK column on `orders` gets the user's id.
// ============================================================

// Keep PHP diagnostics out of this JSON endpoint's response body. Warnings
// and deprecations are still recorded in the PHP error log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../config/init.php';
ini_set('display_errors', '0');
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

require_once __DIR__ . '/../../includes/paymongo.php';
// Free up pickup slots / ingredients held by online orders that were never paid.
try {
  paymongoExpireStaleOrders($db);
} catch (Throwable $e) {
  error_log('PayMongo cleanup failed: ' . $e->getMessage());
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$items       = $data['items'] ?? [];
$ref         = sanitizeString($data['reference_no'] ?? '', 100);
$notes       = sanitizeString($data['notes'] ?? '', 500);
$deliveryLocation = $role === ROLE_FACULTY
  ? sanitizeString($data['delivery_location'] ?? '', 255)
  : '';
$pickupDate  = sanitizeString($data['pickup_date'] ?? '', 10);
$pickupTime  = sanitizeString($data['pickup_time'] ?? '', 10);
$redeemPoints = filter_var($data['redeem_points'] ?? false, FILTER_VALIDATE_BOOLEAN);
$redeemProductId = filter_var($data['redeem_product_id'] ?? 0, FILTER_VALIDATE_INT);
$redeemProductId = $redeemProductId === false ? 0 : max(0, (int) $redeemProductId);

// Pickup is same-day only. Validate the chosen slot itself rather than
// rejecting orders just because the current time is outside store hours.
$openTime  = getSetting('store_open_time', '07:00');
$closeTime = getSetting('store_close_time', '20:00');

if (empty($items)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'Your cart is empty.']);
  exit;
}
if ($role === ROLE_FACULTY && $deliveryLocation === '') {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'Please enter where your order should be delivered.']);
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
// Online GCash through PayMongo: no reference number, no manual verification.
$viaGateway = paymongoEnabled() && (($data['pay_via'] ?? '') === 'paymongo');

if (!$viaGateway) {
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
  // Keep digits only, check the length, and refuse a reference that was
  // already used for another order (the same receipt can't pay twice).
  $ref = normalizeGcashRef($ref);
  if ($refError = gcashRefError($ref)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $refError]);
    exit;
  }
  if (gcashRefInUse($db, $ref)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'This GCash reference number was already used for another order. Please check your receipt.']);
    exit;
  }
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
    $redemption = loyaltyRedemptionForProduct($db, loyaltyPointsBalance($db, $role, $customerId), $redeemProductId, $details, $total);
    if ($redemption['points'] < 1) throw new RuntimeException($redemption['message'] ?? 'That drink reward is no longer available.');
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
  $orderNo = '';
  for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $orderNo = generateOrderNumber();
    try {
      $db->prepare(
        "INSERT INTO orders (order_number, order_type, status, student_id, faculty_id, total_amount, notes, delivery_location, pickup_time, pickup_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
      )->execute([$orderNo, ORDER_PREORDER, STATUS_PENDING, $studentId, $facultyId, $total, $notes, $deliveryLocation ?: null, $pickupTime, $pickupDate]);
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
  if ($viaGateway) {
    // Stays pending until PayMongo's webhook confirms the payment.
    $db->prepare(
      "INSERT INTO payments (order_id, payment_method, amount_paid, payment_status, gateway)
       VALUES (?, ?, ?, ?, 'paymongo')"
    )->execute([$orderId, $method, $total, $payStatus]);
  } else {
    $db->prepare(
      "INSERT INTO payments (order_id, payment_method, amount_paid, payment_status, reference_number, recipient_name, recipient_number, paid_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$orderId, $method, $total, $payStatus, $ref ?: null, gcashPaymentName(), gcashPaymentNumber(), $paidAt]);
  }

  $redemptionResult = loyaltyRedeemForOrder($db, $role, $customerId, $orderId, array_sum(array_column($details, 'sub')), $redeemPoints, $role, $customerId, $redeemProductId, $details);

  $db->commit();
  auditLog($role, currentUserId(), 'place_preorder', 'orders', $orderId);

  if ($viaGateway) {
    // The order is saved. Now ask PayMongo for the hosted GCash payment page.
    // Handled fully here (never rethrown) because the transaction is closed.
    try {
      $checkout = paymongoCreateCheckout(
        $orderNo,
        (float) $total,
        (int) $orderId,
        APP_URL . '/payment-return.php?order=' . urlencode($orderNo),
        APP_URL . '/payment-return.php?order=' . urlencode($orderNo) . '&cancelled=1'
      );
      $db->prepare('UPDATE payments SET gateway_session_id = ? WHERE order_id = ?')->execute([$checkout['id'], $orderId]);
      echo json_encode([
        'ok'           => true,
        'redirect_url' => $checkout['url'],
        'order_number' => $orderNo,
        'total'        => $total,
        'message'      => 'Redirecting you to GCash to complete payment...',
      ]);
    } catch (Throwable $e) {
      // Could not reach PayMongo: release the order again so nothing is left hanging.
      try {
        paymongoCancelUnpaidOrder($db, (int) $orderId, $role, $customerId);
      } catch (Throwable $e2) {
        error_log('PayMongo rollback failed: ' . $e2->getMessage());
      }
      http_response_code(502);
      echo json_encode(['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not start the online payment. Please try again.']);
    }
    exit;
  }

  echo json_encode([
    'ok'           => true,
    'order_number' => $orderNo,
    'total'        => $total,
    'points_redeemed' => $redemptionResult['points'],
    'loyalty_discount' => $redemptionResult['discount'],
    'loyalty_reward_product' => $redemptionResult['product_name'],
    'loyalty_balance' => $redemptionResult['balance'],
    'points_expected_after_pickup' => loyaltyPointsFromSpend($db, $total),
    'message'      => "Order {$orderNo} placed! Show your school ID when claiming.",
  ]);
} catch (\Throwable $e) {
  $db->rollBack();
  error_log('checkout failed: ' . $e->getMessage());
  http_response_code($e instanceof RuntimeException ? 422 : 500);
  echo json_encode(['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Order failed. Please try again.']);
}
