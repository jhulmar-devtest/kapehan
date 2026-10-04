<?php
/**
 * Save Product Add-ons API Endpoint
 * POST: Update product_addons pivot table for a product
 * Returns: JSON response
 */
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

verifyCsrf();

$productId = (int)($_POST['product_id'] ?? 0);
$addonIdsRaw = $_POST['addon_ids'] ?? '[]';
$addonIds = json_decode($addonIdsRaw, true);

// Validation
if ($productId <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Invalid product ID']);
  exit;
}

if (!is_array($addonIds)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Invalid add-on IDs']);
  exit;
}

// Filter to only integers
$addonIds = array_map('intval', $addonIds);
$addonIds = array_filter($addonIds, fn($id) => $id > 0);

try {
  $db = Database::getInstance();
  $db->beginTransaction();

  // Delete existing assignments for this product
  $db->prepare("DELETE FROM product_addons WHERE product_id = ?")
    ->execute([$productId]);

  // Insert new assignments
  if (!empty($addonIds)) {
    $stmt = $db->prepare("INSERT INTO product_addons (product_id, addon_id) VALUES (?, ?)");
    foreach ($addonIds as $addonId) {
      $stmt->execute([$productId, $addonId]);
    }
  }

  $db->commit();

  auditLog(ROLE_ADMIN, currentUserId(), 'update_product_addons', 'products', $productId);

  echo json_encode([
    'success' => true,
    'assigned_count' => count($addonIds)
  ]);
} catch (\Throwable $e) {
  if ($db->inTransaction()) {
    $db->rollBack();
  }
  error_log("Save product add-ons error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to save product add-ons']);
}
