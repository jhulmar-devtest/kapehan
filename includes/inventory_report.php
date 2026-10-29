<?php

function inventoryReportData(PDO $db, string $scope = 'all', string $category = '', string $search = ''): array
{
  $items = $db->query(
    "SELECT id, name, category, unit, quantity_on_hand, reorder_level, cost_per_unit
       FROM inventory_items
      WHERE archived_at IS NULL
      ORDER BY COALESCE(category, 'Other'), name"
  )->fetchAll(PDO::FETCH_ASSOC);
  $stock = inventoryStockSnapshot($db, array_column($items, 'id'));
  $rows = [];
  $search = mb_strtolower(trim($search));
  $category = trim($category);

  foreach ($items as $item) {
    $itemId = (int) $item['id'];
    $snapshot = $stock[$itemId] ?? [
      'reserved' => 0.0,
      'available' => (float) $item['quantity_on_hand'],
    ];
    $item['reserved_quantity'] = (float) $snapshot['reserved'];
    $item['available_quantity'] = (float) $snapshot['available'];
    $item['category'] = $item['category'] ?: 'Other';
    $item['stock_value'] = (float) $item['quantity_on_hand'] * (float) $item['cost_per_unit'];
    $item['is_low'] = $item['available_quantity'] <= (float) $item['reorder_level'];

    if ($scope === 'low' && !$item['is_low']) continue;
    if ($category !== '' && $item['category'] !== $category) continue;
    if ($search !== '' && !str_contains(mb_strtolower($item['name'] . ' ' . $item['category'] . ' ' . $item['unit']), $search)) continue;
    $rows[] = $item;
  }

  return $rows;
}

function inventoryReportXmlEscape(string $value): string
{
  return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
