<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/loyalty.php';
header('Content-Type: application/json');
requireRole(ROLE_CASHIER);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Use POST to look up a customer.']);
  exit;
}
verifyCsrfAjax();
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$db = Database::getInstance();
$qrData = trim((string) ($data['qr_data'] ?? ''));
$type = sanitizeString($data['customer_type'] ?? '');
$identifier = sanitizeString($data['identifier'] ?? '', 30);
$account = null;

if ($qrData !== '') {
  $parts = explode('|', $qrData);
  if (count($parts) !== 4 || $parts[0] !== 'KAPEHAN-LOYALTY' || !in_array($parts[1], [ROLE_STUDENT, ROLE_FACULTY], true) || !ctype_digit($parts[2]) || (int) $parts[2] < 1 || !defined('APP_KEY')) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'This is not a valid Kapehan loyalty QR.']);
    exit;
  }
  [, $type, $rawId, $receivedHmac] = $parts;
  $accountId = (int) $rawId;
  $expectedHmac = hash_hmac('sha256', 'loyalty|' . $type . '|' . $accountId, APP_KEY);
  if (!hash_equals($expectedHmac, $receivedHmac)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'This loyalty QR could not be verified. Ask the customer to open it again from their account.']);
    exit;
  }
  $config = loyaltyAccountConfig($type);
  $stmt = $db->prepare('SELECT id, full_name, ' . $config['identifier'] . ' AS account_identifier FROM ' . $config['table'] . ' WHERE id = ? AND is_active = 1 LIMIT 1');
  $stmt->execute([$accountId]);
  $account = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
  if (!loyaltyAccountConfig($type) || $identifier === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Choose a student or faculty account and enter its ID.']);
    exit;
  }
  $account = loyaltyFindAccount($db, $type, $identifier);
}

if (!$account) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'message' => 'No active account matched that ID.']);
  exit;
}
echo json_encode([
  'ok' => true,
  'account' => [
    'type' => $type,
    'id' => (int) $account['id'],
    'identifier' => $account['account_identifier'],
    'name' => $account['full_name'],
    'balance' => loyaltyPointsBalance($db, $type, (int) $account['id']),
  ],
]);
