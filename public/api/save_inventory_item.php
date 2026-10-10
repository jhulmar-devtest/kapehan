<?php
/**
 * Save Inventory Item API Endpoint
 * POST: Insert new inventory item (+ optional starting-stock restock log entry)
 * Returns: JSON response
 *
 * Mirrors api/save_addon.php's pattern. Added so the "Add Inventory Item"
 * modal can stay open and reset after each save (AJAX) instead of doing a
 * full page POST+redirect per item — the old approach meant an admin
 * entering a dozen ingredients had to reopen the modal a dozen times.
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

$name    = sanitizeString($_POST['name'] ?? '', 120);
$unit    = sanitizeString($_POST['unit'] ?? '', 20);
$qty     = round((float) ($_POST['quantity_on_hand'] ?? 0), 3);
$reorder = round((float) ($_POST['reorder_level'] ?? 0), 3);
$cost    = round((float) ($_POST['cost_per_unit'] ?? 0), 2);

if (empty($name) || empty($unit)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Name and unit are required']);
  exit;
}

try {
  $db = Database::getInstance();
  $db->prepare(
    "INSERT INTO inventory_items (name, unit, quantity_on_hand, reorder_level, cost_per_unit) VALUES (?, ?, ?, ?, ?)"
  )->execute([$name, $unit, $qty, $reorder, $cost]);
  $newId = (int) $db->lastInsertId();

  if ($qty > 0) {
    $db->prepare(
      "INSERT INTO inventory_log (inventory_item_id, change_amount, reason, actor_role, actor_id) VALUES (?, ?, 'restock', ?, ?)"
    )->execute([$newId, $qty, ROLE_ADMIN, currentUserId()]);
  }

  auditLog(ROLE_ADMIN, currentUserId(), 'add_inventory_item', 'inventory_items', $newId);

  echo json_encode([
    'success' => true,
    'item' => [
      'id' => $newId,
      'name' => $name,
      'unit' => $unit,
      'quantity_on_hand' => $qty,
      'reorder_level' => $reorder,
      'cost_per_unit' => $cost,
    ],
  ]);
} catch (\Throwable $e) {
  error_log('Save inventory item error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to save item']);
}
