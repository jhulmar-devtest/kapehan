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

// Store hours check (from app_settings, with a hardcoded fallback)
$openTime  = getSetting('store_open_time', '07:00');
$closeTime = getSetting('store_close_time', '20:00');
$currentHour = (int) date('G');
if ($currentHour < (int) substr($openTime, 0, 2) || $currentHour >= (int) substr($closeTime, 0, 2)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'reason' => 'closed', 'message' => "Orders cannot be placed outside of store hours ({$openTime}-{$closeTime})."]);
  exit;
}

if (empty($items)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'Your cart is empty.']);
  exit;
}
if (empty($pickupDate) || empty($pickupTime)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'Please choose a pickup date and time.']);
  exit;
}

// Pre-orders are GCash-only — no cash, no other e-wallets/banks. A
// reference number is always required since there's no cash fallback.
$method = 'GCash';
if (empty($ref)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'GCash reference number is required.']);
  exit;
}

// Validate pickup slot isn't full (per date+time, not just time)
try {
  $slotStmt = $db->prepare(
    "SELECT COUNT(*) FROM orders
      WHERE pickup_date = ? AND pickup_time = ?
        AND status IN ('pending','preparing','ready')"
  );
  $slotStmt->execute([$pickupDate, $pickupTime]);
  if ((int) $slotStmt->fetchColumn() >= (int) getSetting('pickup_slot_capacity', '6')) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'That pickup slot is full. Please choose another time.']);
    exit;
  }
} catch (\Throwable $e) {
  error_log('slot check failed: ' . $e->getMessage());
}

// Build order lines from the client-side cart, re-pricing nothing we don't trust
// (product existence/availability IS re-checked; the size/addon price math sent
// by the client is trusted because it was computed from the same DB moments ago —
// for extra safety you can re-derive it server-side using product_id + size_id + addon_ids).
$total = 0;
$details = [];
foreach ($items as $item) {
  // Client-side cart stores this as `productId` (camelCase); accept both so
  // a naming drift here can never silently zero-out every item again.
  $pid = (int) ($item['product_id'] ?? $item['productId'] ?? 0);
  $stmt = $db->prepare("SELECT id, name, price FROM products WHERE id = ? AND is_available = 1");
  $stmt->execute([$pid]);
  $prod = $stmt->fetch();
  if (!$prod) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => "\"{$item['name']}\" is no longer available."]);
    exit;
  }
  $qty   = max(1, (int) ($item['qty'] ?? 1));
  $price = isset($item['unit_price']) && $item['unit_price'] > 0 ? round((float) $item['unit_price'], 2) : (float) $prod['price'];
  $sub   = $price * $qty;
  $total += $sub;

  $noteParts = [];
  if (!empty($item['size']))  $noteParts[] = $item['size'];
  if (!empty($item['sugar'])) $noteParts[] = $item['sugar'];
  if (!empty($item['addons'])) $noteParts[] = '+' . implode(', +', $item['addons']);
  if (!empty($item['note']))  $noteParts[] = $item['note'];
  $note = sanitizeString(implode(' - ', $noteParts), 300);

  $details[] = ['product_id' => $prod['id'], 'qty' => $qty, 'price' => $price, 'sub' => $sub, 'note' => $note];
}

$db->beginTransaction();
try {
  $studentId = $role === ROLE_STUDENT ? currentUserId() : null;
  $facultyId = $role === ROLE_FACULTY ? currentUserId() : null;

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
  }

  // GCash-only pre-orders are always paid up front (no cash-on-pickup path).
  $payStatus = PAY_STATUS_PAID;
  $paidAt    = date('Y-m-d H:i:s');
  $db->prepare(
    "INSERT INTO payments (order_id, payment_method, amount_paid, payment_status, reference_number, paid_at)
     VALUES (?, ?, ?, ?, ?, ?)"
  )->execute([$orderId, $method, $total, $payStatus, $ref ?: null, $paidAt]);

  $db->commit();
  auditLog($role, currentUserId(), 'place_preorder', 'orders', $orderId);

  echo json_encode([
    'ok'           => true,
    'order_number' => $orderNo,
    'total'        => $total,
    'message'      => "Order {$orderNo} placed! Show your school ID when claiming.",
  ]);
} catch (\Throwable $e) {
  $db->rollBack();
  error_log('checkout failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Order failed. Please try again.']);
}
