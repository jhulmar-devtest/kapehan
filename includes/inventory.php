<?php
/**
 * Inventory recipe helpers.
 *
 * Product recipes are quantities consumed for one serving. Variant rows
 * override the matching default ingredient for that size; ingredients with
 * no variant row inherit the default recipe.
 */

function inventorySizeLabels(PDO $db): array
{
  try {
    $labels = $db->query("SELECT label FROM size_options ORDER BY sort_order, label")
      ->fetchAll(PDO::FETCH_COLUMN);
    if ($labels) return array_values(array_unique(array_map('strval', $labels)));
  } catch (Throwable $e) {
    // Older installations may not have size_options yet.
  }
  return ['16oz', '22oz'];
}

/**
 * Deduct the configured recipes for an order exactly once.
 *
 * Call this inside the transaction that finalizes the sale/preparation. The
 * order lock and inventory_order_deductions row make retries idempotent.
 */
function deductInventoryForOrder(PDO $db, int $orderId, string $actorRole, ?int $actorId): void
{
  if (!$db->inTransaction()) {
    throw new LogicException('Inventory deductions must run inside the order transaction.');
  }

  $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);
  $alreadyProcessed = $db->prepare('SELECT 1 FROM inventory_order_deductions WHERE order_id = ?');
  $alreadyProcessed->execute([$orderId]);
  if ($alreadyProcessed->fetchColumn()) return;

  $detailsStmt = $db->prepare(
    'SELECT od.product_id, od.quantity, od.customization_note
       FROM order_details od
      WHERE od.order_id = ?'
  );
  $detailsStmt->execute([$orderId]);
  $details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
  $sizeLabels = inventorySizeLabels($db);
  $recipeCache = [];
  $usageByItem = [];

  foreach ($details as $detail) {
    $productId = (int) $detail['product_id'];
    $sizeLabel = '';
    $note = trim((string) ($detail['customization_note'] ?? ''));
    if ($note !== '') {
      $leadingPart = trim((preg_split('/\s(?:·|-|–)\s/u', $note, 2)[0] ?? ''));
      if (in_array($leadingPart, $sizeLabels, true)) $sizeLabel = $leadingPart;
    }

    $cacheKey = $productId . '|' . $sizeLabel;
    if (!array_key_exists($cacheKey, $recipeCache)) {
      $recipeStmt = $db->prepare(
        'SELECT inventory_item_id, qty_per_unit
           FROM product_ingredients
          WHERE product_id = ? AND size_label = ?'
      );
      $recipeStmt->execute([$productId, $sizeLabel]);
      $overrides = [];
      foreach ($recipeStmt->fetchAll(PDO::FETCH_ASSOC) as $recipeRow) {
        $overrides[(int) $recipeRow['inventory_item_id']] = (float) $recipeRow['qty_per_unit'];
      }

      $recipeStmt->execute([$productId, '']);
      $recipe = [];
      foreach ($recipeStmt->fetchAll(PDO::FETCH_ASSOC) as $recipeRow) {
        $itemId = (int) $recipeRow['inventory_item_id'];
        $recipe[$itemId] = (float) $recipeRow['qty_per_unit'];
      }
      foreach ($overrides as $itemId => $amount) $recipe[$itemId] = $amount;
      $recipeCache[$cacheKey] = $recipe;
    }

    foreach ($recipeCache[$cacheKey] as $itemId => $amountPerServing) {
      $usageByItem[$itemId] = ($usageByItem[$itemId] ?? 0.0)
        + ($amountPerServing * (int) $detail['quantity']);
    }
  }

  $updateStock = $db->prepare(
    'UPDATE inventory_items SET quantity_on_hand = quantity_on_hand - ? WHERE id = ?'
  );
  $insertLog = $db->prepare(
    "INSERT INTO inventory_log (inventory_item_id, change_amount, reason, order_id, actor_role, actor_id)
     VALUES (?, ?, 'sale', ?, ?, ?)"
  );
  foreach ($usageByItem as $itemId => $amount) {
    $amount = round($amount, 3);
    if ($amount <= 0) continue;
    $updateStock->execute([$amount, $itemId]);
    if ($updateStock->rowCount() !== 1) {
      throw new RuntimeException('An inventory item in this product recipe no longer exists.');
    }
    $insertLog->execute([$itemId, -$amount, $orderId, $actorRole, $actorId]);
  }

  $db->prepare(
    'INSERT INTO inventory_order_deductions (order_id, actor_role, actor_id) VALUES (?, ?, ?)'
  )->execute([$orderId, $actorRole, $actorId]);
}
