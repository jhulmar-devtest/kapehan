<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';

header('Content-Type: application/json; charset=utf-8');
if (!in_array(currentRole(), [ROLE_CASHIER, ROLE_STUDENT, ROLE_FACULTY], true)) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Unauthorized']);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$productId = (int) ($_GET['product_id'] ?? 0);
if ($productId < 1) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Invalid product ID']);
  exit;
}

$addonIds = $_GET['addon_ids'] ?? [];
if (!is_array($addonIds)) $addonIds = [$addonIds];
$sizeLabel = $_GET['size_label'] ?? '';
if (!is_string($sizeLabel)) $sizeLabel = '';
try {
  $estimate = inventoryProductStockEstimate(
    Database::getInstance(),
    $productId,
    sanitizeString($sizeLabel, 30),
    $addonIds
  );
  echo json_encode(['success' => true] + $estimate);
} catch (RuntimeException $e) {
  http_response_code(422);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
  error_log('Product stock estimate failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Could not estimate product availability.']);
}
