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
$type = sanitizeString($data['customer_type'] ?? '');
$identifier = sanitizeString($data['identifier'] ?? '', 30);
if (!loyaltyAccountConfig($type) || $identifier === '') {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'Choose a student or faculty account and enter its ID.']);
  exit;
}

$db = Database::getInstance();
$account = loyaltyFindAccount($db, $type, $identifier);
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
