<?php
/**
 * Save Add-on API Endpoint
 * POST: Insert new add-on
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

$name = sanitizeString($_POST['name'] ?? '');
$price = round((float)($_POST['price'] ?? 0), 2);

// Validation
if (empty($name)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Add-on name is required']);
  exit;
}

if ($price < 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Price cannot be negative']);
  exit;
}

try {
  $db = Database::getInstance();
  $db->prepare("INSERT INTO addons (name, price, status) VALUES (?, ?, 'active')")
    ->execute([$name, $price]);

  $addonId = (int)$db->lastInsertId();

  auditLog(ROLE_ADMIN, currentUserId(), 'create_addon', 'addons', $addonId);

  echo json_encode([
    'success' => true,
    'addon_id' => $addonId,
    'name' => $name,
    'price' => $price
  ]);
} catch (\Throwable $e) {
  error_log("Save add-on error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to save add-on']);
}
