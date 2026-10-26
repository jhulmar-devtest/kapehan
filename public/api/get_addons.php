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
            SELECT a.id, a.name, a.price, a.status, i.name AS inventory_name, i.unit AS inventory_unit, ai.qty_per_unit,
                   CASE WHEN pa.product_id IS NOT NULL THEN 1 ELSE 0 END AS is_checked
            FROM addons a
            INNER JOIN addon_ingredients ai ON ai.addon_id = a.id
            INNER JOIN inventory_items i ON i.id = ai.inventory_item_id
            LEFT JOIN product_addons pa ON a.id = pa.addon_id AND pa.product_id = ?
            WHERE a.status = 'active' AND a.archived_at IS NULL AND i.archived_at IS NULL
            ORDER BY a.name
        ");
    $stmt->execute([$productId]);
    $addons = $stmt->fetchAll();
  } else {
    $stmt = $db->query("
            SELECT a.id, a.name, a.price, a.status,
                   GROUP_CONCAT(DISTINCT CONCAT(i.name, ' · ', FORMAT(ai.qty_per_unit, 3), ' ', i.unit) ORDER BY i.name SEPARATOR ', ') AS stock_summary,
                   COUNT(DISTINCT ai.inventory_item_id) AS stock_item_count,
                   COUNT(DISTINCT CASE WHEN i.archived_at IS NULL THEN ai.inventory_item_id END) AS active_stock_item_count,
                   COUNT(DISTINCT CASE WHEN p.archived_at IS NULL AND c.archived_at IS NULL THEN pa.product_id END) AS product_count
            FROM addons a
            LEFT JOIN addon_ingredients ai ON ai.addon_id = a.id
            LEFT JOIN inventory_items i ON i.id = ai.inventory_item_id
            LEFT JOIN product_addons pa ON pa.addon_id = a.id
            LEFT JOIN products p ON p.id = pa.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE a.archived_at IS NULL
            GROUP BY a.id, a.name, a.price, a.status
            ORDER BY a.name
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
      'inventory_name' => $a['inventory_name'] ?? null,
      'inventory_unit' => $a['inventory_unit'] ?? null,
      'qty_per_unit' => isset($a['qty_per_unit']) ? (float)$a['qty_per_unit'] : null,
      'stock_summary' => $a['stock_summary'] ?? null,
      'stock_item_count' => isset($a['stock_item_count']) ? (int)$a['stock_item_count'] : 0,
      'active_stock_item_count' => isset($a['active_stock_item_count']) ? (int)$a['active_stock_item_count'] : 0,
      'product_count' => isset($a['product_count']) ? (int)$a['product_count'] : 0,
      'is_checked' => isset($a['is_checked']) ? (bool)$a['is_checked'] : null,
    ], $addons)
  ]);
} catch (\Throwable $e) {
  error_log("Get addons error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to fetch add-ons']);
}
