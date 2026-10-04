<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$db = Database::getInstance();
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

try {
  if ($productId) {
    $stmt = $db->prepare("
            SELECT a.id, a.name, a.price, a.status,
                   CASE WHEN pa.product_id IS NOT NULL THEN 1 ELSE 0 END AS is_checked
            FROM addons a
            LEFT JOIN product_addons pa ON a.id = pa.addon_id AND pa.product_id = ?
            ORDER BY a.name
        ");
    $stmt->execute([$productId]);
    $addons = $stmt->fetchAll();
  } else {
    $stmt = $db->query("
            SELECT id, name, price, status
            FROM addons
            ORDER BY name
        ");
    $addons = $stmt->fetchAll();
  }

  echo json_encode([
    'success' => true,
    'addons' => array_map(fn($a) => [
      'id'         => (int)$a['id'],
      'name'       => $a['name'],
      'price'      => (float)$a['price'],
      'status'     => $a['status'],
      'is_checked' => isset($a['is_checked']) ? (bool)$a['is_checked'] : null,
    ], $addons)
  ]);
} catch (\Throwable $e) {
  error_log("Get addons error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to fetch add-ons']);
}
