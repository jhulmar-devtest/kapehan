<?php
// ============================================================
// public/api/paymongo-webhook.php
//
// Receives events from PayMongo. NOT for browsers: no login, no CSRF
// token. It is protected by the Paymongo-Signature header, which is
// verified against PAYMONGO_WEBHOOK_SECRET before anything is touched.
//
// Register this URL once in the PayMongo dashboard (test mode while
// developing): Developers > Webhooks > Add endpoint, event
// checkout_session.payment.paid. See docs/PAYMONGO_SETUP.md.
// ============================================================

require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/paymongo.php';

ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false]);
  exit;
}

// Must be the RAW body: the signature is computed over the exact bytes.
$raw    = (string) file_get_contents('php://input');
$header = (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');

if (!paymongoVerifySignature($raw, $header, (string) PAYMONGO_WEBHOOK_SECRET)) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Invalid signature']);
  exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
  http_response_code(200);
  echo json_encode(['ok' => true, 'ignored' => 'unreadable body']);
  exit;
}

$event = paymongoParseEvent($payload);

// Unknown event types get a 200 so PayMongo does not keep retrying them.
if ($event['type'] !== 'checkout_session.payment.paid') {
  http_response_code(200);
  echo json_encode(['ok' => true, 'ignored' => $event['type']]);
  exit;
}

try {
  $result = paymongoHandlePaidEvent(Database::getInstance(), $event);
  http_response_code(200);
  echo json_encode(['ok' => true, 'result' => $result]);
} catch (Throwable $e) {
  error_log('PayMongo webhook error: ' . $e->getMessage());
  http_response_code(500); // PayMongo will retry
  echo json_encode(['ok' => false]);
}
