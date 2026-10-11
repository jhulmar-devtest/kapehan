<?php
/** Inventory recipes, availability checks, reservations, and deductions. */

function inventorySizeLabels(PDO $db): array
{
  try {
    $labels = $db->query("SELECT label FROM size_options WHERE is_active = 1 ORDER BY sort_order, label")
      ->fetchAll(PDO::FETCH_COLUMN);
    if ($labels) return array_values(array_unique(array_map('strval', $labels)));
  } catch (Throwable $e) {
    // Older installations may not have size_options yet.
  }
  return ['16oz', '22oz'];
}

function inventoryReservationSchemaAvailable(PDO $db): bool
{
  static $available = null;
  if ($available === null) {
    try {
      $available = (bool) $db->query("SHOW TABLES LIKE 'inventory_order_reservations'")->fetchColumn()
        && (bool) $db->query("SHOW TABLES LIKE 'inventory_order_restorations'")->fetchColumn();
    } catch (Throwable $e) {
      $available = false;
    }
  }
  return $available;
}

/** Return the default recipe with size-specific ingredients overriding it. */
function inventoryRecipeForProduct(PDO $db, int $productId, string $sizeLabel = ''): array
{
  $stmt = $db->prepare(
    "SELECT inventory_item_id, qty_per_unit, size_label
       FROM product_ingredients
      WHERE product_id = ? AND (size_label = ? OR size_label = '')
      ORDER BY CASE WHEN size_label = '' THEN 0 ELSE 1 END"
  );
  $stmt->execute([$productId, $sizeLabel]);
  $recipe = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $recipe[(int) $row['inventory_item_id']] = (float) $row['qty_per_unit'];
  }
  return array_filter($recipe, static fn($amount) => (float) $amount > 0);
}

/** Aggregate recipe use for order-detail rows, honoring size labels in notes. */
function inventoryRequirementsForDetails(PDO $db, array $details): array
{
  $sizeLabels = inventorySizeLabels($db);
  $recipeCache = [];
  $trackedProductCache = [];
  $usage = [];
  foreach ($details as $detail) {
    $productId = (int) ($detail['product_id'] ?? 0);
    $quantity = max(0, (int) ($detail['quantity'] ?? $detail['qty'] ?? 0));
    if ($productId < 1 || $quantity < 1) continue;
    $note = trim((string) ($detail['customization_note'] ?? $detail['note'] ?? ''));
    $leadingPart = trim((preg_split('/\s(?:·|-|–)\s/u', $note, 2)[0] ?? ''));
    $sizeLabel = in_array($leadingPart, $sizeLabels, true) ? $leadingPart : '';
    $cacheKey = $productId . '|' . $sizeLabel;
    if (!array_key_exists($cacheKey, $recipeCache)) {
      $recipeCache[$cacheKey] = inventoryRecipeForProduct($db, $productId, $sizeLabel);
    }
    if (!$recipeCache[$cacheKey]) {
      if (!array_key_exists($productId, $trackedProductCache)) {
        $tracked = $db->prepare('SELECT 1 FROM product_ingredients WHERE product_id = ? LIMIT 1');
        $tracked->execute([$productId]);
        $trackedProductCache[$productId] = (bool) $tracked->fetchColumn();
      }
      if ($trackedProductCache[$productId]) {
        throw new RuntimeException('The selected size does not have a complete inventory recipe. Please choose another size or contact the cashier.');
      }
    }
    foreach ($recipeCache[$cacheKey] as $itemId => $amountPerUnit) {
      $usage[$itemId] = ($usage[$itemId] ?? 0.0) + ((float) $amountPerUnit * $quantity);
    }
  }
  foreach ($usage as $itemId => $amount) $usage[$itemId] = round($amount, 3);
  return array_filter($usage, static fn($amount) => $amount > 0);
}

/** Stock available for new orders is on hand minus active pre-order holds. */
function inventoryStockSnapshot(PDO $db, ?array $itemIds = null, bool $forUpdate = false): array
{
  $params = [];
  $where = '';
  if ($itemIds !== null) {
    $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
    if (!$itemIds) return [];
    $where = ' WHERE id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
    $params = $itemIds;
  }
  $sql = 'SELECT id, name, unit, quantity_on_hand FROM inventory_items' . $where . ' ORDER BY id';
  if ($forUpdate) $sql .= ' FOR UPDATE';
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  $items = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $id = (int) $row['id'];
    $items[$id] = [
      'id' => $id, 'name' => $row['name'], 'unit' => $row['unit'],
      'on_hand' => (float) $row['quantity_on_hand'], 'reserved' => 0.0,
    ];
  }
  if ($items && inventoryReservationSchemaAvailable($db)) {
    $ids = array_keys($items);
    $reserved = $db->prepare(
      "SELECT r.inventory_item_id, r.reserved_quantity
         FROM inventory_order_reservations r JOIN orders o ON o.id = r.order_id
        WHERE r.inventory_item_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
          AND o.status IN ('pending','preparing','ready')
          AND NOT EXISTS (SELECT 1 FROM inventory_order_deductions d WHERE d.order_id = r.order_id)"
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $reserved->execute($ids);
    foreach ($reserved->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $id = (int) $row['inventory_item_id'];
      if (isset($items[$id])) $items[$id]['reserved'] += (float) $row['reserved_quantity'];
    }
  }
  foreach ($items as &$item) $item['available'] = $item['on_hand'] - $item['reserved'];
  unset($item);
  return $items;
}

/** A product is available if untracked or at least one active size can be made. */
function inventoryProductAvailabilityMap(PDO $db, array $productIds): array
{
  $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
  if (!$productIds) return [];
  $stmt = $db->prepare(
    'SELECT product_id, inventory_item_id, qty_per_unit, size_label FROM product_ingredients
      WHERE product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')'
  );
  $stmt->execute($productIds);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $availability = array_fill_keys($productIds, true);
  if (!$rows) return $availability;

  $productStmt = $db->prepare(
    'SELECT id, has_sizes FROM products WHERE id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')'
  );
  $productStmt->execute($productIds);
  $hasSizes = [];
  foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
    $hasSizes[(int) $product['id']] = (bool) $product['has_sizes'];
  }

  $stock = inventoryStockSnapshot($db, array_column($rows, 'inventory_item_id'));
  $byProduct = [];
  foreach ($rows as $row) {
    $pid = (int) $row['product_id'];
    $size = (string) $row['size_label'];
    $byProduct[$pid][$size][(int) $row['inventory_item_id']] = (float) $row['qty_per_unit'];
  }
  $activeSizes = inventorySizeLabels($db);
  foreach ($productIds as $pid) {
    if (empty($byProduct[$pid])) continue;
    $sizes = !empty($hasSizes[$pid]) ? $activeSizes : [''];
    $canMake = false;
    foreach ($sizes as $size) {
      $recipe = $byProduct[$pid][''] ?? [];
      foreach (($byProduct[$pid][$size] ?? []) as $itemId => $amount) $recipe[$itemId] = $amount;
      $recipe = array_filter($recipe, static fn($amount) => (float) $amount > 0);
      if (!$recipe) continue;
      $fits = true;
      foreach ($recipe as $itemId => $amount) {
        if (!isset($stock[$itemId]) || $stock[$itemId]['available'] + 0.000001 < (float) $amount) {
          $fits = false;
          break;
        }
      }
      if ($fits) { $canMake = true; break; }
    }
    $availability[$pid] = $canMake;
  }
  return $availability;
}

function inventoryOrderRequirements(PDO $db, int $orderId): array
{
  $stmt = $db->prepare('SELECT product_id, quantity, customization_note FROM order_details WHERE order_id = ?');
  $stmt->execute([$orderId]);
  return inventoryRequirementsForDetails($db, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Reserve ingredients for an online order within its checkout transaction. */
function reserveInventoryForOrder(PDO $db, int $orderId, string $actorRole, ?int $actorId): void
{
  if (!$db->inTransaction()) throw new LogicException('Inventory reservations must run inside the order transaction.');
  $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);
  $usage = inventoryOrderRequirements($db, $orderId);
  if (!$usage) return;
  if (!inventoryReservationSchemaAvailable($db)) {
    throw new RuntimeException('Inventory reservations are not set up yet. Apply the inventory availability migration, then try again.');
  }
  $existing = $db->prepare('SELECT COUNT(*) FROM inventory_order_reservations WHERE order_id = ?');
  $existing->execute([$orderId]);
  if ((int) $existing->fetchColumn() > 0) return;

  $stock = inventoryStockSnapshot($db, array_keys($usage), true);
  foreach ($usage as $itemId => $required) {
    if (!isset($stock[$itemId])) throw new RuntimeException('A recipe ingredient no longer exists. Please contact the cashier.');
    $available = $stock[$itemId]['on_hand'] - $stock[$itemId]['reserved'];
    if ($available + 0.000001 < $required) {
      throw new RuntimeException(sprintf(
        'Not enough %s for this order. Available: %s %s; required: %s %s.',
        $stock[$itemId]['name'], number_format(max(0, $available), 3), $stock[$itemId]['unit'],
        number_format($required, 3), $stock[$itemId]['unit']
      ));
    }
  }
  $insert = $db->prepare(
    'INSERT INTO inventory_order_reservations (order_id, inventory_item_id, reserved_quantity, actor_role, actor_id) VALUES (?, ?, ?, ?, ?)'
  );
  foreach ($usage as $itemId => $required) $insert->execute([$orderId, $itemId, $required, $actorRole, $actorId]);
}

/** Deduct recipes once, converting a pre-order reservation or checking a walk-in. */
function deductInventoryForOrder(PDO $db, int $orderId, string $actorRole, ?int $actorId): void
{
  if (!$db->inTransaction()) throw new LogicException('Inventory deductions must run inside the order transaction.');
  $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);
  $alreadyProcessed = $db->prepare('SELECT 1 FROM inventory_order_deductions WHERE order_id = ?');
  $alreadyProcessed->execute([$orderId]);
  if ($alreadyProcessed->fetchColumn()) return;

  $reservedForOrder = [];
  if (inventoryReservationSchemaAvailable($db)) {
    $stmt = $db->prepare('SELECT inventory_item_id, reserved_quantity FROM inventory_order_reservations WHERE order_id = ?');
    $stmt->execute([$orderId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $reservedForOrder[(int) $row['inventory_item_id']] = (float) $row['reserved_quantity'];
  }
  $usage = $reservedForOrder ?: inventoryOrderRequirements($db, $orderId);
  $stock = inventoryStockSnapshot($db, array_keys($usage), true);
  foreach ($usage as $itemId => $required) {
    if (!isset($stock[$itemId])) throw new RuntimeException('An inventory item in this product recipe no longer exists.');
    $available = $stock[$itemId]['on_hand'] - $stock[$itemId]['reserved'];
    if ($reservedForOrder) {
      if ($stock[$itemId]['on_hand'] + 0.000001 < $stock[$itemId]['reserved']) {
        throw new RuntimeException('Reserved stock for ' . $stock[$itemId]['name'] . ' is short. Restock it before preparing this order.');
      }
    } elseif ($available + 0.000001 < $required) {
      throw new RuntimeException(sprintf(
        'Not enough %s to prepare this order. Available: %s %s; required: %s %s.',
        $stock[$itemId]['name'], number_format(max(0, $available), 3), $stock[$itemId]['unit'],
        number_format($required, 3), $stock[$itemId]['unit']
      ));
    }
  }

  $updateStock = $db->prepare(
    'UPDATE inventory_items SET quantity_on_hand = quantity_on_hand - ? WHERE id = ? AND quantity_on_hand + 0.000001 >= ?'
  );
  $insertLog = $db->prepare(
    "INSERT INTO inventory_log (inventory_item_id, change_amount, reason, order_id, actor_role, actor_id)
     VALUES (?, ?, 'sale', ?, ?, ?)"
  );
  foreach ($usage as $itemId => $amount) {
    $amount = round($amount, 3);
    if ($amount <= 0) continue;
    $updateStock->execute([$amount, $itemId, $amount]);
    if ($updateStock->rowCount() !== 1) throw new RuntimeException('Inventory changed while processing this order. Please retry.');
    $insertLog->execute([$itemId, -$amount, $orderId, $actorRole, $actorId]);
  }
  $db->prepare('INSERT INTO inventory_order_deductions (order_id, actor_role, actor_id) VALUES (?, ?, ?)')
    ->execute([$orderId, $actorRole, $actorId]);
  if (inventoryReservationSchemaAvailable($db)) {
    $db->prepare('DELETE FROM inventory_order_reservations WHERE order_id = ?')->execute([$orderId]);
  }
}

/** Release a pending stock hold or restore deducted stock on cancellation. */
function restoreInventoryForCancelledOrder(PDO $db, int $orderId, string $actorRole, ?int $actorId): void
{
  if (!$db->inTransaction()) throw new LogicException('Inventory restoration must run inside the order transaction.');
  $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);
  if (!inventoryReservationSchemaAvailable($db)) return;

  $deduction = $db->prepare('SELECT 1 FROM inventory_order_deductions WHERE order_id = ?');
  $deduction->execute([$orderId]);
  if ($deduction->fetchColumn()) {
    $restored = $db->prepare('SELECT 1 FROM inventory_order_restorations WHERE order_id = ?');
    $restored->execute([$orderId]);
    if (!$restored->fetchColumn()) {
      $saleLog = $db->prepare("SELECT inventory_item_id, SUM(change_amount) AS change_amount FROM inventory_log WHERE order_id = ? AND reason = 'sale' GROUP BY inventory_item_id");
      $saleLog->execute([$orderId]);
      $changes = $saleLog->fetchAll(PDO::FETCH_ASSOC);
      if ($changes) inventoryStockSnapshot($db, array_column($changes, 'inventory_item_id'), true);
      $update = $db->prepare('UPDATE inventory_items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?');
      $log = $db->prepare("INSERT INTO inventory_log (inventory_item_id, change_amount, reason, order_id, actor_role, actor_id) VALUES (?, ?, 'restock', ?, ?, ?)");
      foreach ($changes as $row) {
        $amount = round(abs((float) $row['change_amount']), 3);
        if ($amount <= 0) continue;
        $update->execute([$amount, (int) $row['inventory_item_id']]);
        $log->execute([(int) $row['inventory_item_id'], $amount, $orderId, $actorRole, $actorId]);
      }
      $db->prepare('INSERT INTO inventory_order_restorations (order_id, actor_role, actor_id) VALUES (?, ?, ?)')
        ->execute([$orderId, $actorRole, $actorId]);
    }
  }
  $db->prepare('DELETE FROM inventory_order_reservations WHERE order_id = ?')->execute([$orderId]);
}
