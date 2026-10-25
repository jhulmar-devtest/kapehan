<?php

/**
 * Save Inventory Item API Endpoint
 * POST: Insert new inventory item (+ optional starting-stock restock log entry)
 * Returns: JSON response
 *
 * Added so the "Add Inventory Item"
 * modal can stay open and reset after each save (AJAX) instead of doing a
 * full page POST+redirect per item — the old approach meant an admin
 * entering a dozen ingredients had to reopen the modal a dozen times.
 */
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';
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
$category = sanitizeString($_POST['category'] ?? '', 80);
$qty     = round((float) ($_POST['quantity_on_hand'] ?? 0), 3);
$reorder = round((float) ($_POST['reorder_level'] ?? 0), 3);
$cost    = round((float) ($_POST['cost_per_unit'] ?? 0), 2);
$isAddon = ($_POST['is_addon'] ?? '') === '1';
$addonName = sanitizeString($_POST['addon_name'] ?? '', 120);
$addonPrice = round((float) ($_POST['addon_price'] ?? 0), 2);
$addonQty = round((float) ($_POST['addon_quantity_per_unit'] ?? 0), 3);

if (empty($name) || empty($unit)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Name and unit are required']);
  exit;
}

if ($isAddon && ($addonName === '' || $addonPrice < 0 || $addonQty <= 0)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Add-on name, a non-negative price, and a usage quantity above zero are required']);
  exit;
}

try {
  $db = Database::getInstance();
  if (!inventoryCategoryExists($db, $category)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Choose a valid inventory category.']);
    exit;
  }
  $db->beginTransaction();
  $duplicateItem = $db->prepare('SELECT id FROM inventory_items WHERE name = ? AND unit = ? LIMIT 1');
  $duplicateItem->execute([$name, $unit]);
  if ($duplicateItem->fetchColumn()) {
    $db->rollBack();
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'A stock item with that name and unit already exists. Select the existing item instead.']);
    exit;
  }
  if ($isAddon) {
    $duplicate = $db->prepare('SELECT COUNT(*) FROM addons WHERE name = ?');
    $duplicate->execute([$addonName]);
    if ((int) $duplicate->fetchColumn() > 0) {
      $db->rollBack();
      http_response_code(409);
      echo json_encode(['success' => false, 'error' => 'An add-on with that name already exists. Edit its inventory item instead.']);
      exit;
    }
  }
  $db->prepare(
    "INSERT INTO inventory_items (name, category, unit, quantity_on_hand, reorder_level, cost_per_unit) VALUES (?, ?, ?, ?, ?, ?)"
  )->execute([$name, $category, $unit, $qty, $reorder, $cost]);
  $newId = (int) $db->lastInsertId();

  $addonId = null;
  if ($isAddon) {
    $db->prepare("INSERT INTO addons (name, price, status) VALUES (?, ?, 'active')")
      ->execute([$addonName, $addonPrice]);
    $addonId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO addon_ingredients (addon_id, inventory_item_id, qty_per_unit) VALUES (?, ?, ?)")
      ->execute([$addonId, $newId, $addonQty]);
  }

  if ($qty > 0) {
    $db->prepare(
      "INSERT INTO inventory_log (inventory_item_id, change_amount, reason, actor_role, actor_id) VALUES (?, ?, 'restock', ?, ?)"
    )->execute([$newId, $qty, ROLE_ADMIN, currentUserId()]);
  }

  auditLog(ROLE_ADMIN, currentUserId(), 'add_inventory_item', 'inventory_items', $newId);
  $db->commit();

  echo json_encode([
    'success' => true,
    'item' => [
      'id' => $newId,
      'name' => $name,
      'category' => $category,
      'unit' => $unit,
      'quantity_on_hand' => $qty,
      'reorder_level' => $reorder,
      'cost_per_unit' => $cost,
      'is_addon' => $isAddon,
      'addon_name' => $isAddon ? $addonName : null,
      'addon_price' => $isAddon ? $addonPrice : null,
    ],
    'addon' => $isAddon ? [
      'id' => $addonId,
      'name' => $addonName,
      'price' => $addonPrice,
      'stock_summary' => $name . ' · ' . number_format($addonQty, 3) . ' ' . $unit,
    ] : null,
  ]);
} catch (\Throwable $e) {
  if (isset($db) && $db->inTransaction()) $db->rollBack();
  error_log('Save inventory item error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to save item']);
}
