<?php

/**
 * public/api/qr-generate.php
 * Generates a signed QR payload for a ready order.
 * Place this file at: /api/qr-generate.php  (or adjust APP_URL path below)
 *
 * Returns: JSON { success, qr_data }  where qr_data is a signed string
 */
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_STUDENT); // students AND faculty can call this

header('Content-Type: application/json');

$orderId = (int)($_GET['order_id'] ?? 0);
$uid     = currentUserId();

if (!$orderId) {
  echo json_encode(['success' => false, 'message' => 'Missing order_id']);
  exit;
}

$db = Database::getInstance();

// Fetch order — must belong to this user and be in 'ready' status
$stmt = $db->prepare(
  "SELECT o.id, o.order_number, o.status, o.total_amount,
            COALESCE(s.full_name, f.full_name) AS customer_name,
            COALESCE(s.student_id_no, f.faculty_id_no) AS customer_id_no
     FROM orders o
     LEFT JOIN students  s ON o.student_id  = s.id
     LEFT JOIN faculty   f ON o.faculty_id  = f.id
     WHERE o.id = ?
       AND (o.student_id = ? OR o.faculty_id = ?)
       AND o.status = 'ready'"
);
$stmt->execute([$orderId, $uid, $uid]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
  echo json_encode(['success' => false, 'message' => 'Order not found or not ready']);
  exit;
}

// Build a signed payload: order_id|timestamp|hmac
if (!defined('APP_KEY')) {
  // Fail safely instead of silently signing with a second guessable
  // placeholder — a QR claim endpoint with a known secret is a
  // real "steal someone else's order" vulnerability, not just a config nit.
  error_log('qr-generate.php: APP_KEY is not defined — refusing to issue an unsigned/weakly-signed QR code.');
  echo json_encode(['success' => false, 'message' => 'Server configuration error. Please contact staff.']);
  exit;
}
$secret    = APP_KEY;
$timestamp = time();
$payload   = $order['id'] . '|' . $timestamp;
$hmac      = hash_hmac('sha256', $payload, $secret);
$qrData    = $payload . '|' . $hmac;

echo json_encode([
  'success'      => true,
  'qr_data'      => $qrData,
  'order_number' => $order['order_number'],
  'customer'     => $order['customer_name'],
]);
