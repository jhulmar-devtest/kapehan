<?php

/**
 * public/api/qr-verify.php
 *
 * Verifies a scanned QR payload and returns the order's details WITHOUT
 * changing its status. This is step 1 of a two-step scan flow:
 *   1. Scan -> qr-verify.php -> show order details for the cashier to check
 *   2. Cashier taps "Confirm Handover" -> qr-claim.php -> status becomes 'claimed'
 *
 * Splitting these means a scan alone never finalizes a pickup — the
 * cashier gets a chance to see what's being handed over (and catch a
 * wrong/accidental scan) before the order is marked claimed.
 *
 * POST body: qr_data=<scanned string>
 */
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_CASHIER);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'message' => 'POST required']);
  exit;
}

$qrData = trim($_POST['qr_data'] ?? '');

if (!$qrData) {
  echo json_encode(['success' => false, 'message' => 'No QR data received']);
  exit;
}

$parts = explode('|', $qrData);
if (count($parts) !== 3) {
  echo json_encode(['success' => false, 'message' => 'Invalid QR format']);
  exit;
}

[$orderId, $timestamp, $receivedHmac] = $parts;
$orderId   = (int) $orderId;
$timestamp = (int) $timestamp;

if ((time() - $timestamp) > 600) {
  echo json_encode(['success' => false, 'message' => 'QR code has expired. Ask the customer to refresh.']);
  exit;
}

if (!defined('APP_KEY')) {
  error_log('qr-verify.php: APP_KEY is not defined — refusing to validate against a guessable placeholder.');
  echo json_encode(['success' => false, 'message' => 'Server configuration error. Please contact staff.']);
  exit;
}
$expectedHmac = hash_hmac('sha256', $orderId . '|' . $timestamp, APP_KEY);

if (!hash_equals($expectedHmac, $receivedHmac)) {
  echo json_encode(['success' => false, 'message' => 'Invalid or tampered QR code']);
  exit;
}

$db = Database::getInstance();

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
   GROUP BY o.id, o.order_number, o.status, o.total_amount, customer_name, customer_id_no"
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

echo json_encode([
  'success' => true,
  'order'   => $order,
]);
