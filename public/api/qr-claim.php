<?php

/**
 * public/api/qr-claim.php
 * Called by the cashier scanner after a successful QR scan.
 * Verifies the HMAC token, then marks the order as 'claimed'.
 *
 * POST body: qr_data=<scanned string>
 */
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/loyalty.php';
requireRole(ROLE_CASHIER);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'message' => 'POST required']);
  exit;
}

$qrData    = trim($_POST['qr_data'] ?? '');
$cashierId = currentUserId();

if (!$qrData) {
  echo json_encode(['success' => false, 'message' => 'No QR data received']);
  exit;
}

// Parse payload
$parts = explode('|', $qrData);
if (count($parts) !== 3) {
  echo json_encode(['success' => false, 'message' => 'Invalid QR format']);
  exit;
}

[$orderId, $timestamp, $receivedHmac] = $parts;
$orderId   = (int)$orderId;
$timestamp = (int)$timestamp;

// Check expiry — QR codes valid for 10 minutes
if ((time() - $timestamp) > 600) {
  echo json_encode(['success' => false, 'message' => 'QR code has expired. Ask the customer to refresh.']);
  exit;
}

// Verify HMAC
if (!defined('APP_KEY')) {
  error_log('qr-claim.php: APP_KEY is not defined — refusing to validate against a guessable placeholder.');
  echo json_encode(['success' => false, 'message' => 'Server configuration error. Please contact staff.']);
  exit;
}
$secret       = APP_KEY;
$expectedHmac = hash_hmac('sha256', $orderId . '|' . $timestamp, $secret);

if (!hash_equals($expectedHmac, $receivedHmac)) {
  echo json_encode(['success' => false, 'message' => 'Invalid or tampered QR code']);
  exit;
}

$db = Database::getInstance();

// Fetch order
$stmt = $db->prepare(
  "SELECT o.id, o.order_number, o.status, o.total_amount,
            COALESCE(s.full_name, f.full_name) AS customer_name,
            COALESCE(s.student_id_no, f.faculty_id_no) AS customer_id_no,
            GROUP_CONCAT(
                CONCAT(od.quantity,'× ',pr.name,
                    IF(od.customization_note IS NOT NULL AND od.customization_note != '',
                       CONCAT(' (',od.customization_note,')'), ''))
                ORDER BY pr.name SEPARATOR ', '
            ) AS items
     FROM orders o
     LEFT JOIN students    s  ON o.student_id  = s.id
     LEFT JOIN faculty     f  ON o.faculty_id  = f.id
     JOIN  order_details   od ON o.id = od.order_id
     JOIN  products        pr ON od.product_id = pr.id
     WHERE o.id = ?
     GROUP BY o.id, o.order_number, o.status, o.total_amount,
              customer_name, customer_id_no"
);
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
  echo json_encode(['success' => false, 'message' => 'Order not found']);
  exit;
}

if ($order['status'] === 'claimed') {
  echo json_encode(['success' => false, 'message' => 'Order already claimed', 'order' => $order]);
  exit;
}

if ($order['status'] !== 'ready') {
  echo json_encode([
    'success' => false,
    'message' => "Order is '{$order['status']}' — it must be 'ready' to claim via QR",
    'order'   => $order
  ]);
  exit;
}

// Claim and award points atomically; the order row lock protects against two
// cashiers scanning the same QR at the same time.
try {
  $db->beginTransaction();
  $claimLock = $db->prepare(
    "SELECT o.status, p.payment_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.id = ? FOR UPDATE"
  );
  $claimLock->execute([$orderId]);
  $claimState = $claimLock->fetch(PDO::FETCH_ASSOC);
  if (!$claimState || $claimState['status'] !== STATUS_READY || $claimState['payment_status'] !== PAY_STATUS_PAID) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'This order is not ready and paid for handover. Scan again to refresh its status.']);
    exit;
  }
  $db->prepare(
    "UPDATE orders
       SET status='claimed', cashier_id=?, locked_by=NULL, locked_at=NULL, lock_expire_at=NULL, updated_at=NOW()
       WHERE id=?"
  )->execute([$cashierId, $orderId]);
  $pointsAwarded = loyaltyAwardOrder($db, $orderId, ROLE_CASHIER, $cashierId);
  $db->commit();
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('QR claim loyalty update failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Could not complete the handover. Please try again.']);
  exit;
}

// Audit log
auditLog(ROLE_CASHIER, $cashierId, 'status_claimed', 'orders', $orderId);

echo json_encode([
  'success' => true,
  'message' => 'Order claimed successfully!',
  'points_awarded' => $pointsAwarded,
  'order'   => $order,
]);
