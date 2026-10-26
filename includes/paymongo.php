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
function paymongoApi(string $method, string $path, ?array $body = null, int $timeout = 25): array
{
  if (!function_exists('curl_init')) {
    throw new RuntimeException('The PHP cURL extension is not enabled.');
  }
  $ch = curl_init('https://api.paymongo.com' . $path);
  $options = [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => max(3, $timeout),
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
    curl_close($ch);
    throw new RuntimeException('PayMongo request failed: ' . $err);
  }
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
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
 * Fetch a checkout session from PayMongo (GET /v1/checkout_sessions/{id}).
 * Returns the session resource (the "data" object) or null if PayMongo could
 * not be reached or did not return a session. Short timeout: this is called
 * from page loads, never from a place where a slow API should block for long.
 */
function paymongoFetchSession(string $sessionId): ?array
{
  if ($sessionId === '') return null;
  try {
    $res = paymongoApi('GET', '/v1/checkout_sessions/' . rawurlencode($sessionId), null, 10);
  } catch (RuntimeException $e) {
    error_log('PayMongo session lookup failed: ' . $e->getMessage());
    return null;
  }
  $data = $res['body']['data'] ?? null;
  if ($res['status'] >= 200 && $res['status'] < 300 && is_array($data)) return $data;
  error_log('PayMongo session lookup rejected (HTTP ' . $res['status'] . ') for ' . $sessionId);
  return null;
}

/** The paid payment inside a checkout session resource, or null if none is paid. */
function paymongoPaidPayment(array $session): ?array
{
  $attrs = is_array($session['attributes'] ?? null) ? $session['attributes'] : [];
  foreach ((array) ($attrs['payments'] ?? []) as $p) {
    if (is_array($p) && (($p['attributes']['status'] ?? '') === 'paid')) return $p;
  }
  return null;
}

/** Hosted payment page URL for a session (prefers the URL PayMongo reports). */
function paymongoSessionUrl(array $session, string $sessionId): string
{
  $url = (string) ($session['attributes']['checkout_url'] ?? '');
  if (!str_starts_with($url, 'https://checkout.paymongo.com/')) {
    $url = 'https://checkout.paymongo.com/' . rawurlencode($sessionId);
  }
  return $url;
}

/**
 * Close the hosted payment page so it can no longer take money
 * (POST /v1/checkout_sessions/{id}/expire).
 * Returns 'closed' (expired now, already expired, or unknown to PayMongo),
 * 'paid' (the customer had already paid), or 'error' (could not confirm:
 * the caller must NOT cancel the order, because money may still arrive).
 */
function paymongoCloseSession(string $sessionId): string
{
  try {
    $res = paymongoApi('POST', '/v1/checkout_sessions/' . rawurlencode($sessionId) . '/expire', null, 10);
  } catch (RuntimeException $e) {
    error_log('PayMongo expire failed: ' . $e->getMessage());
    return 'error';
  }
  if ($res['status'] >= 200 && $res['status'] < 300) return 'closed';
  if ($res['status'] === 404) return 'closed';

  // 400 means already expired, already paid, or a payment is in progress.
  $session = paymongoFetchSession($sessionId);
  if ($session === null) return 'error';
  if (paymongoPaidPayment($session) !== null) return 'paid';
  if ((string) ($session['attributes']['status'] ?? '') === 'expired') return 'closed';
  return 'error'; // a payment is in flight: let it settle first
}

/**
 * Ask PayMongo whether this order's payment really went through and, if so,
 * settle it exactly as the webhook would (same idempotency key logic, same
 * amount check). This is the safety net for a webhook that never arrived.
 * Returns 'paid', 'unpaid', or 'unknown' (PayMongo unreachable / not a gateway order).
 */
function paymongoReconcileOrder(PDO $db, int $orderId): string
{
  $stmt = $db->prepare('SELECT gateway, gateway_session_id, payment_status FROM payments WHERE order_id = ? LIMIT 1');
  $stmt->execute([$orderId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || $row['gateway'] !== 'paymongo') return 'unknown';
  if ($row['payment_status'] === PAY_STATUS_PAID) return 'paid';
  $sessionId = (string) ($row['gateway_session_id'] ?? '');
  if ($sessionId === '') return 'unknown';

  $session = paymongoFetchSession($sessionId);
  if ($session === null) return 'unknown';
  if (paymongoPaidPayment($session) === null) return 'unpaid';

  try {
    paymongoHandlePaidEvent($db, [
      'type'     => 'checkout_session.payment.paid',
      'resource' => $session,
      'event_id' => '',
      'livemode' => null,
    ]);
  } catch (Throwable $e) {
    error_log('PayMongo reconcile failed for order ' . $orderId . ': ' . $e->getMessage());
    return 'unknown';
  }
  $stmt->execute([$orderId]);
  $after = $stmt->fetch(PDO::FETCH_ASSOC);
  return ($after && $after['payment_status'] === PAY_STATUS_PAID) ? 'paid' : 'unknown';
}

/**
 * reconcile, but at most once per $minSeconds per order per browser session.
 * Used by pages that are polled, so one waiting customer cannot hammer the API.
 */
function paymongoReconcileThrottled(PDO $db, int $orderId, int $minSeconds = 15): string
{
  $key = 'pm_recon_' . $orderId;
  $now = time();
  if (isset($_SESSION[$key]) && ($now - (int) $_SESSION[$key]) < $minSeconds) return 'unknown';
  $_SESSION[$key] = $now;
  return paymongoReconcileOrder($db, $orderId);
}

/**
 * If this order is waiting on an online payment, close PayMongo's payment
 * page for it. Returns 'n/a' (nothing to close), 'closed', 'paid' (the
 * customer had already paid: the order was settled instead) or 'error'.
 */
function paymongoCloseOrderPaymentPage(PDO $db, int $orderId): string
{
  $stmt = $db->prepare('SELECT gateway, gateway_session_id, payment_status FROM payments WHERE order_id = ? LIMIT 1');
  $stmt->execute([$orderId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || $row['gateway'] !== 'paymongo' || $row['payment_status'] !== PAY_STATUS_PENDING) return 'n/a';
  $sessionId = (string) ($row['gateway_session_id'] ?? '');
  if ($sessionId === '') return 'n/a'; // the session was never created

  $result = paymongoCloseSession($sessionId);
  if ($result === 'paid') paymongoReconcileOrder($db, $orderId);
  return $result;
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
 * Cancel an order whose online payment was never completed: closes the
 * PayMongo payment page, then releases the
 * reserved ingredients and any redeemed loyalty points. Returns true if the
 * order was cancelled, false if it was not eligible (already paid, etc.).
 */
function paymongoCancelUnpaidOrder(PDO $db, int $orderId, string $actorRole, ?int $actorId, bool $closeSession = true): bool
{
  // Close PayMongo's payment page BEFORE touching the order. Sessions never
  // expire on their own, so without this a customer could still pay for an
  // order that no longer exists. If the page cannot be closed, or the customer
  // turns out to have paid, the order is left alone (the payment is settled
  // instead) and nothing is cancelled. Skipped when the caller already holds
  // a transaction, so no network call happens under a row lock.
  if ($closeSession && !$db->inTransaction()) {
    $closed = paymongoCloseOrderPaymentPage($db, $orderId);
    if ($closed === 'paid') return false;
    if ($closed === 'error') {
      throw new RuntimeException('Could not close the online payment page right now. Please try again in a moment.');
    }
  }

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
      LIMIT 5"
  );
  $count = 0;
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $role = !empty($o['student_id']) ? ROLE_STUDENT : ROLE_FACULTY;
    $uid  = (int) (!empty($o['student_id']) ? $o['student_id'] : $o['faculty_id']);
    try {
      if (paymongoCancelUnpaidOrder($db, (int) $o['id'], $role, $uid)) $count++;
    } catch (Throwable $e) {
      error_log('PayMongo stale-order cleanup failed for order ' . $o['id'] . ': ' . $e->getMessage());
      // PayMongo unreachable: stop here instead of waiting on every remaining order.
      if ($e instanceof RuntimeException) break;
    }
  }
  return $count;
}
