<?php
require_once __DIR__ . '/../../config/init.php';

if (!in_array(currentRole(), [ROLE_CASHIER, ROLE_STUDENT, ROLE_FACULTY])) {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => 'Unauthorized']);
  exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
if ($productId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Invalid product ID']);
  exit;
}

try {
  $db = Database::getInstance();
  $stmt = $db->prepare("
        SELECT a.id, a.name, a.price
        FROM addons a
        INNER JOIN product_addons pa ON a.id = pa.addon_id
        WHERE pa.product_id = ? AND a.status = 'active'
        ORDER BY a.name
    ");
  $stmt->execute([$productId]);
  $addons = $stmt->fetchAll();

  echo json_encode([
    'success' => true,
    'addons' => array_map(fn($a) => [
      'id'    => (int)$a['id'],
      'name'  => $a['name'],
      'price' => (float)$a['price']
    ], $addons)
  ]);
} catch (\Throwable $e) {
  error_log("Get product add-ons error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to fetch add-ons']);
}
