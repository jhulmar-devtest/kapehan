<?php
// ============================================================
// includes/paymongo.php
//
// GCash payments through PayMongo Hosted Checkout (API v2).
//
// Flow:
//   1. api/checkout.php creates the order (payment = pending) and calls
//      paymongoCreateCheckout() to get a PayMongo-hosted payment page.
//   2. The customer is redirected there and pays with GCash.
//   3. PayMongo calls api/paymongo-webhook.php (checkout_session.payment.paid).
//      paymongoHandlePaidEvent() marks the payment paid.
//   4. payment-return.php is where the customer lands afterwards.
//
// If PAYMONGO_SECRET_KEY is empty, paymongoEnabled() is false and the shop
// keeps using the manual GCash-reference flow.
//
// paymongoEnabled() itself lives in includes/functions.php.
// ============================================================

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/loyalty.php';

/**
 * Call the PayMongo API. Returns ['status' => int, 'body' => array|null].
 * Throws RuntimeException if the request could not be made at all.
 */
function paymongoApi(string $method, string $path, ?array $body = null): array
{
  if (!function_exists('curl_init')) {
    throw new RuntimeException('The PHP cURL extension is not enabled.');
  }
  $ch = curl_init('https://api.paymongo.com' . $path);
  $options = [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_USERPWD        => PAYMONGO_SECRET_KEY . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
  ];
  if ($body !== null) $options[CURLOPT_POSTFIELDS] = json_encode($body);
  // On Windows/XAMPP, cURL often has no CA bundle and fails with
  // "SSL certificate problem". Point this at a cacert.pem file if so.
  if (defined('PAYMONGO_CA_BUNDLE') && PAYMONGO_CA_BUNDLE !== '') {
    $options[CURLOPT_CAINFO] = PAYMONGO_CA_BUNDLE;
  }
  curl_setopt_array($ch, $options);
  $raw = curl_exec($ch);
  if ($raw === false) {
    $err = curl_error($ch);
    throw new RuntimeException('PayMongo request failed: ' . $err);
  }
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $decoded = json_decode((string) $raw, true);
  return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
}

/**
 * Create a hosted checkout session for one order (GCash only).
 * Returns ['id' => 'cs_...', 'url' => 'https://checkout.paymongo.com/...'].
 * Throws RuntimeException with a customer-safe message on failure.
 */
function paymongoCreateCheckout(string $orderNo, float $total, int $orderId, string $successUrl, string $cancelUrl): array
{
  $payload = ['data' => ['attributes' => [
    'line_items' => [[
      'name'     => 'Kapehan ni Amang order ' . $orderNo,
      'amount'   => (int) round($total * 100), // centavos
      'currency' => 'PHP',
      'quantity' => 1,
    ]],
    'payment_method_types' => ['gcash'],
    'success_url'      => $successUrl,
    'cancel_url'       => $cancelUrl,
    'reference_number' => $orderNo,
    'metadata'         => ['order_id' => (string) $orderId],
  ]]];

  try {
    $res = paymongoApi('POST', '/v2/checkout_sessions', $payload);
  } catch (RuntimeException $e) {
    error_log('PayMongo checkout failed: ' . $e->getMessage());
    throw new RuntimeException('Online payment is not available right now. Please try again in a moment.');
  }

  $data = $res['body']['data'] ?? null;
  $id   = is_array($data) ? (string) ($data['id'] ?? '') : '';
  $url  = is_array($data) ? (string) ($data['attributes']['checkout_url'] ?? '') : '';
  if ($res['status'] >= 400 || $id === '' || $url === '') {
    error_log('PayMongo checkout rejected (HTTP ' . $res['status'] . '): ' . json_encode($res['body']));
    throw new RuntimeException('Could not start the GCash payment. Please try again.');
  }
  return ['id' => $id, 'url' => $url];
}

/**
 * Verify the Paymongo-Signature header of a webhook request.
 * $rawBody must be the exact raw request body (not re-encoded JSON).
 */
function paymongoVerifySignature(string $rawBody, string $header, string $secret): bool
{
  if ($secret === '' || $header === '') return false;
  $parts = [];
  foreach (explode(',', $header) as $piece) {
    $kv = explode('=', trim($piece), 2);
    if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
  }
  $timestamp = $parts['t'] ?? '';
  if ($timestamp === '') return false;
  $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
  // 'te' is the test-mode signature, 'li' the live-mode one.
  foreach (['te', 'li'] as $key) {
    if (!empty($parts[$key]) && hash_equals($expected, $parts[$key])) return true;
  }
  return false;
}

/**
 * Normalize a webhook payload. PayMongo's docs show two envelope shapes, so
 * both are accepted. Returns ['type', 'resource', 'event_id', 'livemode'].
 */
function paymongoParseEvent(array $payload): array
{
  $d = is_array($payload['data'] ?? null) ? $payload['data'] : [];
  if (is_array($d['attributes'] ?? null) && isset($d['attributes']['type'])) {
    // Classic shape: data.attributes.type / data.attributes.data
    return [
      'type'     => (string) $d['attributes']['type'],
      'resource' => is_array($d['attributes']['data'] ?? null) ? $d['attributes']['data'] : [],
      'event_id' => (string) ($d['id'] ?? ''),
      'livemode' => $d['attributes']['livemode'] ?? null,
    ];
  }
  // Newer shape: data.type / data.data
  return [
    'type'     => (string) ($d['type'] ?? ''),
    'resource' => is_array($d['data'] ?? null) ? $d['data'] : [],
    'event_id' => '',
    'livemode' => $d['livemode'] ?? null,
  ];
}

/**
 * Apply a checkout_session.payment.paid event.
 * Returns a short status word (used for logging): paid, duplicate,
 * unknown_order, amount_mismatch, already_paid, paid_but_order_cancelled.
 * Throws on database errors so the webhook answers 500 and PayMongo retries.
 */
function paymongoHandlePaidEvent(PDO $db, array $event): string
{
  $resource  = $event['resource'];
  $sessionId = (string) ($resource['id'] ?? '');
  $attrs     = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
  $orderNo   = (string) ($attrs['reference_number'] ?? '');
  if ($sessionId === '' && $orderNo === '') return 'unknown_order';

  $paidPayment = null;
  foreach ((array) ($attrs['payments'] ?? []) as $p) {
    if (is_array($p) && (($p['attributes']['status'] ?? '') === 'paid')) {
      $paidPayment = $p;
      break;
    }
  }
  $payId     = (string) ($paidPayment['id'] ?? '');
  $paidCents = isset($paidPayment['attributes']['amount']) ? (int) $paidPayment['attributes']['amount'] : null;

  $eventKey = $event['event_id'] !== '' ? $event['event_id'] : ('paid:' . ($sessionId !== '' ? $sessionId : $orderNo));

  $db->beginTransaction();
  try {
    $ins = $db->prepare('INSERT IGNORE INTO payment_webhook_events (event_key, event_type) VALUES (?, ?)');
    $ins->execute([substr($eventKey, 0, 190), substr($event['type'], 0, 100)]);
    if ($ins->rowCount() === 0) {
      $db->rollBack();
      return 'duplicate';
    }

    $stmt = $db->prepare(
      "SELECT p.id AS payment_id, p.order_id, p.amount_paid, p.payment_status,
              o.status AS order_status, o.student_id, o.faculty_id
         FROM payments p JOIN orders o ON o.id = p.order_id
        WHERE p.gateway = 'paymongo' AND (p.gateway_session_id = ? OR o.order_number = ?)
        LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$sessionId, $orderNo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      $db->rollBack();
      error_log('PayMongo webhook: no matching order for session ' . $sessionId . ' / ' . $orderNo);
      return 'unknown_order';
    }

    if ($row['payment_status'] === PAY_STATUS_PAID) {
      $db->commit();
      return 'already_paid';
    }

    $expectedCents = (int) round(((float) $row['amount_paid']) * 100);
    if ($paidCents !== null && $paidCents < $expectedCents) {
      $db->rollBack();
      error_log('PayMongo webhook: amount mismatch for order ' . $row['order_id'] . " (paid {$paidCents}, expected {$expectedCents})");
      return 'amount_mismatch';
    }

    $db->prepare(
      "UPDATE payments SET payment_status = ?, paid_at = NOW(), gateway_payment_id = ?, reference_number = ?
        WHERE id = ? AND payment_status = ?"
    )->execute([PAY_STATUS_PAID, $payId !== '' ? $payId : null, $payId !== '' ? $payId : null, $row['payment_id'], PAY_STATUS_PENDING]);

    $db->commit();
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }

  $actorType = !empty($row['student_id']) ? ROLE_STUDENT : ROLE_FACULTY;
  $actorId   = (int) (!empty($row['student_id']) ? $row['student_id'] : $row['faculty_id']);
  if ($row['order_status'] === STATUS_CANCELLED) {
    // Money arrived for an order that was already released. Staff must refund.
    error_log('PayMongo: PAID BUT ORDER CANCELLED - order id ' . $row['order_id'] . ' needs a manual refund.');
    auditLog($actorType, $actorId, 'paymongo_paid_order_cancelled', 'orders', (int) $row['order_id']);
    return 'paid_but_order_cancelled';
  }
  auditLog($actorType, $actorId, 'paymongo_paid', 'orders', (int) $row['order_id']);
  return 'paid';
}

/**
 * Cancel an order whose online payment was never completed: releases the
 * reserved ingredients and any redeemed loyalty points. Returns true if the
 * order was cancelled, false if it was not eligible (already paid, etc.).
 */
function paymongoCancelUnpaidOrder(PDO $db, int $orderId, string $actorRole, ?int $actorId): bool
{
  $own = !$db->inTransaction();
  if ($own) $db->beginTransaction();
  try {
    $stmt = $db->prepare(
      "SELECT o.status, p.id AS payment_id, p.gateway, p.payment_status
         FROM orders o JOIN payments p ON p.order_id = o.id
        WHERE o.id = ? FOR UPDATE"
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['gateway'] !== 'paymongo' || $row['payment_status'] !== PAY_STATUS_PENDING || $row['status'] !== STATUS_PENDING) {
      if ($own) $db->rollBack();
      return false;
    }
    $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([STATUS_CANCELLED, $orderId]);
    $db->prepare('UPDATE payments SET payment_status = ? WHERE id = ?')->execute([PAY_STATUS_FAILED, $row['payment_id']]);
    loyaltyReverseOrder($db, $orderId, $actorRole, $actorId);
    restoreInventoryForCancelledOrder($db, $orderId, $actorRole, $actorId);
    if ($own) $db->commit();
    return true;
  } catch (Throwable $e) {
    if ($own && $db->inTransaction()) $db->rollBack();
    throw $e;
  }
}

/**
 * Cancel online orders that were never paid, so they stop holding a pickup
 * slot and reserved ingredients. Cheap; safe to call on page loads.
 */
function paymongoExpireStaleOrders(PDO $db): int
{
  $minutes = max(5, (int) PAYMONGO_UNPAID_ORDER_MINUTES);
  $stmt = $db->query(
    "SELECT o.id, o.student_id, o.faculty_id
       FROM orders o JOIN payments p ON p.order_id = o.id
      WHERE p.gateway = 'paymongo' AND p.payment_status = 'pending' AND o.status = 'pending'
        AND o.created_at < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)
      LIMIT 20"
  );
  $count = 0;
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $role = !empty($o['student_id']) ? ROLE_STUDENT : ROLE_FACULTY;
    $uid  = (int) (!empty($o['student_id']) ? $o['student_id'] : $o['faculty_id']);
    try {
      if (paymongoCancelUnpaidOrder($db, (int) $o['id'], $role, $uid)) $count++;
    } catch (Throwable $e) {
      error_log('PayMongo stale-order cleanup failed for order ' . $o['id'] . ': ' . $e->getMessage());
    }
  }
  return $count;
}
