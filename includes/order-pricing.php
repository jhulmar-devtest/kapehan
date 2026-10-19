<?php

/** Rebuild an order line from current catalog data instead of trusting browser prices. */
function priceOrderLine(PDO $db, array $item): array
{
  $productId = (int) ($item['product_id'] ?? $item['productId'] ?? 0);
  $stmt = $db->prepare('SELECT id, name, price, has_sizes, is_available FROM products WHERE id = ?');
  $stmt->execute([$productId]);
  $product = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$product || !(int) $product['is_available']) {
    throw new RuntimeException('A selected product is no longer available. Please refresh the menu.');
  }

  $qty = (int) ($item['qty'] ?? 1);
  if ($qty < 1 || $qty > 99) throw new RuntimeException('Product quantity must be between 1 and 99.');

  $size = trim((string) ($item['size'] ?? ''));
  $sizeAdjustment = 0.0;
  if ((int) $product['has_sizes']) {
    if ($size === '') throw new RuntimeException('Choose a size for ' . $product['name'] . '.');
    $sizeStmt = $db->prepare('SELECT price_adjustment FROM size_options WHERE label = ? AND is_active = 1');
    $sizeStmt->execute([$size]);
    $adjustment = $sizeStmt->fetchColumn();
    if ($adjustment === false) throw new RuntimeException('That size is no longer available for ' . $product['name'] . '.');
    $sizeAdjustment = (float) $adjustment;
  } else {
    $size = '';
  }

  $addonIds = $item['addon_ids'] ?? [];
  if (!is_array($addonIds)) $addonIds = [];
  // Accept old saved carts during rollout, but resolve names only against the
  // product's currently assigned add-ons and still price them from the DB.
  if (!$addonIds && !empty($item['addons']) && is_array($item['addons'])) {
    $addonIds = array_map('strval', $item['addons']);
  }
  $addonIds = array_values(array_unique(array_filter(array_map('strval', $addonIds), static fn($v) => $v !== '')));
  $addons = [];
  if ($addonIds) {
    $choices = $db->prepare(
      "SELECT a.id, a.name, a.price
         FROM addons a JOIN product_addons pa ON pa.addon_id = a.id
        WHERE pa.product_id = ? AND a.status = 'active'"
    );
    $choices->execute([$productId]);
    $allowed = [];
    foreach ($choices->fetchAll(PDO::FETCH_ASSOC) as $choice) {
      $allowed[(string) $choice['id']] = $choice;
      $allowed[(string) $choice['name']] = $choice;
    }
    foreach ($addonIds as $addonKey) {
      if (!isset($allowed[$addonKey])) throw new RuntimeException('An add-on is no longer available for ' . $product['name'] . '.');
      $choice = $allowed[$addonKey];
      $recipe = $db->prepare(
        'SELECT ai.inventory_item_id, ai.qty_per_unit, i.name
           FROM addon_ingredients ai JOIN inventory_items i ON i.id = ai.inventory_item_id
          WHERE ai.addon_id = ?'
      );
      $recipe->execute([(int) $choice['id']]);
      $ingredients = $recipe->fetchAll(PDO::FETCH_ASSOC);
      if (!$ingredients) throw new RuntimeException('Inventory use is not configured for the ' . $choice['name'] . ' add-on.');
      $addons[] = [
        'id' => (int) $choice['id'],
        'name' => $choice['name'],
        'price' => (float) $choice['price'],
        'ingredients' => array_map(static fn($row) => [
          'inventory_item_id' => (int) $row['inventory_item_id'],
          'inventory_item_name' => $row['name'],
          'qty_per_addon' => (float) $row['qty_per_unit'],
        ], $ingredients),
      ];
    }
  }

  $price = round((float) $product['price'] + $sizeAdjustment + array_sum(array_column($addons, 'price')), 2);
  // Client prices are comparison-only: they let us stop checkout when the
  // catalog changed while the cart was open, but never determine the charge.
  $displayedPrice = $item['displayed_price'] ?? $item['unit_price'] ?? $item['price'] ?? null;
  if ($displayedPrice !== null && is_numeric($displayedPrice) && abs(round((float) $displayedPrice, 2) - $price) > 0.009) {
    throw new RuntimeException('A menu price or option changed while your cart was open. Please refresh your cart and review the updated total.');
  }
  $notes = [];
  if ($size !== '') $notes[] = $size;
  $sugar = trim((string) ($item['sugar'] ?? ''));
  if ($sugar !== '') $notes[] = sanitizeString($sugar, 30);
  if ($addons) $notes[] = '+' . implode(', +', array_column($addons, 'name'));
  $customNote = trim((string) ($item['custom_note'] ?? $item['customNote'] ?? $item['note'] ?? ''));
  if ($customNote !== '') $notes[] = sanitizeString($customNote, 180);

  return [
    'product_id' => (int) $product['id'],
    'qty' => $qty,
    'price' => $price,
    'sub' => round($price * $qty, 2),
    'note' => sanitizeString(implode(' · ', $notes), 300),
    'addons' => $addons,
  ];
}

/** Save add-on name, price and recipe snapshots against the newly inserted line. */
function saveOrderLineAddonSnapshots(PDO $db, int $detailId, array $addons): void
{
  if (!$addons) return;
  $addOnStmt = $db->prepare(
    'INSERT INTO order_detail_addons (order_detail_id, addon_id, addon_name_snapshot, price_at_time, quantity_per_product)
     VALUES (?, ?, ?, ?, 1)'
  );
  $ingredientStmt = $db->prepare(
    'INSERT INTO order_detail_addon_ingredients (order_detail_addon_id, inventory_item_id, inventory_item_name_snapshot, qty_per_addon)
     VALUES (?, ?, ?, ?)'
  );
  foreach ($addons as $addon) {
    $addOnStmt->execute([$detailId, $addon['id'], $addon['name'], $addon['price']]);
    $addonLineId = (int) $db->lastInsertId();
    foreach ($addon['ingredients'] as $ingredient) {
      $ingredientStmt->execute([$addonLineId, $ingredient['inventory_item_id'], $ingredient['inventory_item_name'], $ingredient['qty_per_addon']]);
    }
  }
}
