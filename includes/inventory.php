<?php

/** Inventory recipes, availability checks, reservations, and deductions. */

function inventoryCategories(PDO $db): array
{
  return $db->query('SELECT id, name, sort_order FROM inventory_categories ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
}

function inventoryCategoryExists(PDO $db, string $category): bool
{
  $stmt = $db->prepare('SELECT 1 FROM inventory_categories WHERE name=? LIMIT 1');
  $stmt->execute([$category]);
  return (bool)$stmt->fetchColumn();
}

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

function inventoryAddonSchemaAvailable(PDO $db): bool
{
  static $available = null;
  if ($available === null) {
    try {
      $available = (bool) $db->query("SHOW TABLES LIKE 'addon_ingredients'")->fetchColumn();
    } catch (Throwable $e) {
      $available = false;
    }
  }
  return $available;
}

function inventoryOrderAddonSnapshotSchemaAvailable(PDO $db): bool
{
  static $available = null;
  if ($available === null) {
    try {
      $available = (bool) $db->query("SHOW TABLES LIKE 'order_detail_addons'")->fetchColumn()
        && (bool) $db->query("SHOW TABLES LIKE 'order_detail_addon_ingredients'")->fetchColumn();
    } catch (Throwable $e) {
      $available = false;
    }
  }
  return $available;
}

/** Availability for optional add-ons; unlinked add-ons remain untracked. */
function inventoryAddonAvailabilityMap(PDO $db, array $addonIds): array
{
  $addonIds = array_values(array_unique(array_filter(array_map('intval', $addonIds))));
  if (!$addonIds || !inventoryAddonSchemaAvailable($db)) return array_fill_keys($addonIds, true);
  $stmt = $db->prepare(
    'SELECT addon_id, inventory_item_id, qty_per_unit FROM addon_ingredients
      WHERE addon_id IN (' . implode(',', array_fill(0, count($addonIds), '?')) . ')'
  );
  $stmt->execute($addonIds);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $availability = array_fill_keys($addonIds, false);
  if (!$rows) return $availability;
  $stock = inventoryStockSnapshot($db, array_column($rows, 'inventory_item_id'));
  $hasRecipe = [];
  foreach ($rows as $row) {
    $addonId = (int) $row['addon_id'];
    $itemId = (int) $row['inventory_item_id'];
    if (!isset($availability[$addonId])) continue;
    if (!isset($hasRecipe[$addonId])) $availability[$addonId] = true;
    $hasRecipe[$addonId] = true;
    if (!isset($stock[$itemId]) || $stock[$itemId]['available'] + 0.000001 < (float) $row['qty_per_unit']) {
      $availability[$addonId] = false;
    }
  }
  return $availability;
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

/** Estimate how many units of a selected product configuration remain makeable. */
function inventoryProductStockEstimate(PDO $db, int $productId, string $sizeLabel = '', array $addonIds = []): array
{
  $productStmt = $db->prepare('SELECT has_sizes FROM products WHERE id = ? AND is_available = 1 LIMIT 1');
  $productStmt->execute([$productId]);
  $product = $productStmt->fetch(PDO::FETCH_ASSOC);
  if (!$product) throw new RuntimeException('This product is no longer available.');

  $sizeLabel = trim($sizeLabel);
  if (!(bool) $product['has_sizes']) $sizeLabel = '';
  elseif ($sizeLabel !== '' && !in_array($sizeLabel, inventorySizeLabels($db), true)) {
    throw new RuntimeException('The selected size is no longer available.');
  }

  $usage = inventoryRecipeForProduct($db, $productId, $sizeLabel);
  $trackedStmt = $db->prepare('SELECT 1 FROM product_ingredients WHERE product_id = ? LIMIT 1');
  $trackedStmt->execute([$productId]);
  $tracked = (bool) $trackedStmt->fetchColumn();

  $addonIds = array_values(array_unique(array_filter(
    array_map('intval', array_filter($addonIds, 'is_scalar')),
    static fn($id) => $id > 0
  )));
  if ($addonIds) {
    if (!inventoryAddonSchemaAvailable($db)) {
      throw new RuntimeException('Add-on inventory tracking is not set up yet.');
    }
    $allowedStmt = $db->prepare(
      "SELECT a.id FROM addons a JOIN product_addons pa ON pa.addon_id = a.id
        WHERE pa.product_id = ? AND a.status = 'active'
          AND a.id IN (" . implode(',', array_fill(0, count($addonIds), '?')) . ')'
    );
    $allowedStmt->execute(array_merge([$productId], $addonIds));
    $allowed = array_map('intval', $allowedStmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($allowed) !== count($addonIds)) throw new RuntimeException('An add-on is no longer available for this product.');
    foreach ($addonIds as $addonId) {
      $addonRecipe = $db->prepare('SELECT inventory_item_id, qty_per_unit FROM addon_ingredients WHERE addon_id = ?');
      $addonRecipe->execute([$addonId]);
      $rows = $addonRecipe->fetchAll(PDO::FETCH_ASSOC);
      if (!$rows) throw new RuntimeException('Inventory use has not been configured for a selected add-on.');
      foreach ($rows as $row) {
        $itemId = (int) $row['inventory_item_id'];
        $usage[$itemId] = ($usage[$itemId] ?? 0.0) + (float) $row['qty_per_unit'];
      }
      $tracked = true;
    }
  }

  if (!$usage) {
    if ($tracked) throw new RuntimeException('This product size does not have a complete inventory recipe.');
    return ['tracked' => false, 'max_quantity' => null, 'low_stock' => false];
  }

  $stock = inventoryStockSnapshot($db, array_keys($usage));
  $maxQuantity = 999;
  $lowStock = false;
  foreach ($usage as $itemId => $perUnit) {
    if ($perUnit <= 0 || !isset($stock[$itemId])) {
      $maxQuantity = 0;
      $lowStock = true;
      continue;
    }
    $available = max(0, (float) $stock[$itemId]['available']);
    $maxQuantity = min($maxQuantity, (int) floor(($available + 0.000001) / $perUnit));
    if ($available <= (float) ($stock[$itemId]['reorder_level'] ?? 0)) $lowStock = true;
  }

  return ['tracked' => true, 'max_quantity' => max(0, $maxQuantity), 'low_stock' => $lowStock];
}

/** Aggregate recipe use for order-detail rows, honoring size labels in notes. */
function inventoryRequirementsForDetails(PDO $db, array $details): array
{
  $sizeLabels = inventorySizeLabels($db);
  $recipeCache = [];
  $trackedProductCache = [];
  $addonCache = [];
  $addonRecipeCache = [];
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

    $hasAddonSnapshot = false;
    $detailId = (int) ($detail['id'] ?? 0);
    if ($detailId > 0 && inventoryOrderAddonSnapshotSchemaAvailable($db)) {
      $snapshotStmt = $db->prepare(
        'SELECT i.inventory_item_id, i.qty_per_addon, a.quantity_per_product
           FROM order_detail_addons a
           JOIN order_detail_addon_ingredients i ON i.order_detail_addon_id = a.id
          WHERE a.order_detail_id = ?'
      );
      $snapshotStmt->execute([$detailId]);
      $snapshots = $snapshotStmt->fetchAll(PDO::FETCH_ASSOC);
      if ($snapshots) {
        $hasAddonSnapshot = true;
        foreach ($snapshots as $snapshot) {
          $itemId = (int) $snapshot['inventory_item_id'];
          $usage[$itemId] = ($usage[$itemId] ?? 0.0)
            + ((float) $snapshot['qty_per_addon'] * (float) $snapshot['quantity_per_product'] * $quantity);
        }
      }
    }

    if (!$hasAddonSnapshot && $note !== '') {
      $addonSegment = '';
      foreach (preg_split('/\s(?:·|-|–)\s/u', $note) as $part) {
        if (preg_match('/^\s*\+/u', $part)) {
          $addonSegment = trim($part);
          break;
        }
      }
      if ($addonSegment !== '') {
        if (!inventoryAddonSchemaAvailable($db)) {
          throw new RuntimeException('Add-on inventory tracking is not set up yet. Apply the add-on inventory migration, then try again.');
        }
        if (!array_key_exists($productId, $addonCache)) {
          $choices = $db->prepare(
            "SELECT a.id, a.name FROM addons a JOIN product_addons pa ON pa.addon_id = a.id
              WHERE pa.product_id = ? AND a.status = 'active'"
          );
          $choices->execute([$productId]);
          $addonCache[$productId] = [];
          foreach ($choices->fetchAll(PDO::FETCH_ASSOC) as $choice) {
            $addonCache[$productId][(string) $choice['name']] = (int) $choice['id'];
          }
        }
        preg_match_all('/(?:^|,\s*)\+([^,]+)/u', $addonSegment, $matches);
        foreach ($matches[1] ?? [] as $rawName) {
          $addonName = trim($rawName);
          if ($addonName === '' || !isset($addonCache[$productId][$addonName])) continue;
          $addonId = $addonCache[$productId][$addonName];
          if (!array_key_exists($addonId, $addonRecipeCache)) {
            $addonStmt = $db->prepare('SELECT inventory_item_id, qty_per_unit FROM addon_ingredients WHERE addon_id = ?');
            $addonStmt->execute([$addonId]);
            $addonRecipeCache[$addonId] = $addonStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$addonRecipeCache[$addonId]) {
              throw new RuntimeException('Inventory use has not been set up for the ' . $addonName . ' add-on. Please contact the administrator.');
            }
          }
          foreach ($addonRecipeCache[$addonId] as $addonIngredient) {
            $itemId = (int) $addonIngredient['inventory_item_id'];
            $usage[$itemId] = ($usage[$itemId] ?? 0.0) + ((float) $addonIngredient['qty_per_unit'] * $quantity);
          }
        }
      }
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
  $sql = 'SELECT id, name, unit, quantity_on_hand, reorder_level FROM inventory_items' . $where . ' ORDER BY id';
  if ($forUpdate) $sql .= ' FOR UPDATE';
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  $items = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $id = (int) $row['id'];
    $items[$id] = [
      'id' => $id,
      'name' => $row['name'],
      'unit' => $row['unit'],
      'on_hand' => (float) $row['quantity_on_hand'],
      'reserved' => 0.0,
      'reorder_level' => (float) $row['reorder_level'],
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
      if ($fits) {
        $canMake = true;
        break;
      }
    }
    $availability[$pid] = $canMake;
  }
  return $availability;
}

function inventoryOrderRequirements(PDO $db, int $orderId): array
{
  $stmt = $db->prepare('SELECT id, product_id, quantity, customization_note FROM order_details WHERE order_id = ?');
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
        $stock[$itemId]['name'],
        number_format(max(0, $available), 3),
        $stock[$itemId]['unit'],
        number_format($required, 3),
        $stock[$itemId]['unit']
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
  $usage = inventoryOrderRequirements($db, $orderId);
  $stock = inventoryStockSnapshot($db, array_keys($usage), true);
  foreach ($usage as $itemId => $required) {
    if (!isset($stock[$itemId])) throw new RuntimeException('An inventory item in this product recipe no longer exists.');
    $available = $stock[$itemId]['on_hand'] - $stock[$itemId]['reserved'];
    if ($reservedForOrder) {
      $thisOrderReserved = $reservedForOrder[$itemId] ?? 0.0;
      if ($thisOrderReserved + $available + 0.000001 < $required) {
        throw new RuntimeException('Not enough ' . $stock[$itemId]['name'] . ' to prepare this order. Restock it before preparing this order.');
      }
    } elseif ($available + 0.000001 < $required) {
      throw new RuntimeException(sprintf(
        'Not enough %s to prepare this order. Available: %s %s; required: %s %s.',
        $stock[$itemId]['name'],
        number_format(max(0, $available), 3),
        $stock[$itemId]['unit'],
        number_format($required, 3),
        $stock[$itemId]['unit']
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
