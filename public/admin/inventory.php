<?php
// ============================================================
// public/admin/inventory.php
//
// Tab 3 of 4 (item 13 / item 12). Tracks raw stock (milk, beans,
// cups, etc.), flags anything under its reorder level, and logs
// every restock/waste adjustment to inventory_log for a paper trail.
//
// Product recipes are managed on admin/recipes.php. Recipe quantities are
// deducted for walk-in sales and when a pre-order enters preparation.
// ============================================================

require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();
$inventoryCategories = inventoryCategories($db);

// ---- Adjust stock (restock / waste / correction) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_item'])) {
  verifyCsrf();
  $id     = (int) ($_POST['item_id'] ?? 0);
  $reason = in_array($_POST['reason'] ?? '', ['restock', 'waste', 'correction'], true) ? $_POST['reason'] : 'correction';
  $amount = round((float) ($_POST['amount'] ?? 0), 3);
  // Supplier only makes sense for a restock — ignored for waste/correction either way.
  $supplier = $reason === 'restock' ? sanitizeString($_POST['supplier'] ?? '', 150) : null;
  if ($supplier === '') $supplier = null;
  // Waste always subtracts, restock always adds, correction uses the sign the admin typed
  $change = $reason === 'waste' ? -abs($amount) : ($reason === 'restock' ? abs($amount) : $amount);

  if ($id && $amount != 0) {
    $db->prepare("UPDATE inventory_items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?")->execute([$change, $id]);
    try {
      $db->prepare(
        "INSERT INTO inventory_log (inventory_item_id, change_amount, reason, supplier, actor_role, actor_id) VALUES (?, ?, ?, ?, ?, ?)"
      )->execute([$id, $change, $reason, $supplier, ROLE_ADMIN, currentUserId()]);
    } catch (\Throwable $e) {
      // supplier column migration not run yet — log without it rather than failing the whole adjustment
      $db->prepare(
        "INSERT INTO inventory_log (inventory_item_id, change_amount, reason, actor_role, actor_id) VALUES (?, ?, ?, ?, ?)"
      )->execute([$id, $change, $reason, ROLE_ADMIN, currentUserId()]);
    }
    auditLog(ROLE_ADMIN, currentUserId(), 'adjust_inventory', 'inventory_items', $id);
    flash('global', 'Stock updated.', 'success');
  }
  redirect(APP_URL . '/admin/inventory.php');
}

// ---- Edit item details (name/unit/reorder level/cost) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
  verifyCsrf();
  $id = (int) ($_POST['item_id'] ?? 0);
  $name = sanitizeString($_POST['name'] ?? '', 120);
  $category = sanitizeString($_POST['category'] ?? '', 80);
  $unit = sanitizeString($_POST['unit'] ?? '', 20);
  $reorder = round((float) ($_POST['reorder_level'] ?? 0), 3);
  $cost = round((float) ($_POST['cost_per_unit'] ?? 0), 2);
  if (!inventoryCategoryExists($db, $category)) {
    flash('global', 'Choose a valid inventory category.', 'error');
    redirect(APP_URL . '/admin/inventory.php');
  }
  if ($id && !empty($name) && !empty($unit)) {
    $isAddon = ($_POST['is_addon'] ?? '') === '1';
    $addonName = sanitizeString($_POST['addon_name'] ?? '', 120);
    $addonPrice = round((float) ($_POST['addon_price'] ?? 0), 2);
    $addonQty = round((float) ($_POST['addon_quantity_per_unit'] ?? 0), 3);

    if ($isAddon && ($addonName === '' || $addonPrice < 0 || $addonQty <= 0)) {
      flash('global', 'Add-on name, a non-negative price, and a usage quantity above zero are required.', 'error');
      redirect(APP_URL . '/admin/inventory.php');
    }

    try {
      $db->beginTransaction();
      $db->prepare("UPDATE inventory_items SET name=?, category=?, unit=?, reorder_level=?, cost_per_unit=? WHERE id=?")
        ->execute([$name, $category, $unit, $reorder, $cost, $id]);

      $existing = $db->prepare("SELECT a.id FROM addons a JOIN addon_ingredients ai ON ai.addon_id = a.id WHERE ai.inventory_item_id = ? ORDER BY a.id LIMIT 1");
      $existing->execute([$id]);
      $addonId = (int) ($existing->fetchColumn() ?: 0);

      if ($isAddon) {
        $duplicate = $db->prepare('SELECT COUNT(*) FROM addons WHERE name = ? AND id <> ?');
        $duplicate->execute([$addonName, $addonId]);
        if ((int) $duplicate->fetchColumn() > 0) {
          $db->rollBack();
          flash('global', 'An add-on with that name already exists. Edit its inventory item instead.', 'error');
          redirect(APP_URL . '/admin/inventory.php');
        }
        if ($addonId) {
          $db->prepare("UPDATE addons SET name=?, price=?, status='active' WHERE id=?")
            ->execute([$addonName, $addonPrice, $addonId]);
          $db->prepare("DELETE FROM addon_ingredients WHERE addon_id=?")->execute([$addonId]);
        } else {
          $db->prepare("INSERT INTO addons (name, price, status) VALUES (?, ?, 'active')")
            ->execute([$addonName, $addonPrice]);
          $addonId = (int) $db->lastInsertId();
        }
        $db->prepare("INSERT INTO addon_ingredients (addon_id, inventory_item_id, qty_per_unit) VALUES (?, ?, ?)")
          ->execute([$addonId, $id, $addonQty]);
      } elseif ($addonId) {
        // Keep the mapping and product assignments so the add-on can be restored later.
        $db->prepare("UPDATE addons SET status='inactive' WHERE id=?")->execute([$addonId]);
      }

      $db->commit();
      auditLog(ROLE_ADMIN, currentUserId(), 'edit_inventory_item', 'inventory_items', $id);
      flash('global', 'Item details updated.', 'success');
    } catch (\Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      error_log('Edit inventory/add-on error: ' . $e->getMessage());
      flash('global', 'Could not update item and add-on settings.', 'error');
    }
  }
  redirect(APP_URL . '/admin/inventory.php');
}

// ---- Delete item ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
  verifyCsrf();
  $id = (int) ($_POST['item_id'] ?? 0);

  // inventory_log.inventory_item_id is ON DELETE CASCADE — deleting an
  // item here would silently wipe its entire restock/waste history with
  // it. Same principle as products: don't let a routine cleanup action
  // quietly destroy an audit trail. Block it if any log entries exist.
  $stmt = $db->prepare("SELECT COUNT(*) FROM inventory_log WHERE inventory_item_id = ?");
  $stmt->execute([$id]);
  if ((int) $stmt->fetchColumn() > 0) {
    flash('global', 'This item has stock history and cannot be deleted, to keep the audit trail intact. Set its quantity to 0 instead if it is no longer used.', 'error');
    redirect(APP_URL . '/admin/inventory.php');
  }

  $stmt = $db->prepare("SELECT COUNT(*) FROM addon_ingredients ai JOIN addons a ON a.id=ai.addon_id WHERE ai.inventory_item_id = ? AND a.status='active'");
  $stmt->execute([$id]);
  if ((int) $stmt->fetchColumn() > 0) {
    flash('global', 'This item supplies an add-on. Turn off the add-on in Edit Item before deleting its inventory item.', 'error');
    redirect(APP_URL . '/admin/inventory.php');
  }

  $stmt = $db->prepare('SELECT COUNT(*) FROM product_ingredients WHERE inventory_item_id = ?');
  $stmt->execute([$id]);
  if ((int) $stmt->fetchColumn() > 0) {
    flash('global', 'This item is used in one or more product recipes. Remove it from those recipes before deleting it.', 'error');
    redirect(APP_URL . '/admin/inventory.php');
  }

  $db->prepare("DELETE FROM inventory_items WHERE id = ?")->execute([$id]);
  auditLog(ROLE_ADMIN, currentUserId(), 'delete_inventory_item', 'inventory_items', $id);
  flash('global', 'Item deleted.', 'success');
  redirect(APP_URL . '/admin/inventory.php');
}

$items = $db->query("SELECT * FROM inventory_items ORDER BY name")->fetchAll();
$addonMappings = $db->query("SELECT ai.inventory_item_id, ai.qty_per_unit, a.id AS addon_id, a.name AS addon_name, a.price AS addon_price, a.status AS addon_status FROM addon_ingredients ai JOIN addons a ON a.id=ai.addon_id ORDER BY a.id")->fetchAll();
$addonByItem = [];
foreach ($addonMappings as $mapping) {
  $addonByItem[(int) $mapping['inventory_item_id']] = $mapping;
}
$stockSnapshot = inventoryStockSnapshot($db, array_column($items, 'id'));
foreach ($items as &$item) {
  $stock = $stockSnapshot[(int) $item['id']] ?? ['reserved' => 0, 'available' => (float) $item['quantity_on_hand']];
  $item['reserved_quantity'] = $stock['reserved'];
  $item['available_quantity'] = $stock['available'];
  $item['addon'] = $addonByItem[(int) $item['id']] ?? null;
}
unset($item);
$lowStockCount = count(array_filter($items, fn($i) => (float) $i['available_quantity'] <= (float) $i['reorder_level']));

// ---- Recent activity (grouped per day, and per order) ----
$recentLog = $db->query(
  "SELECT l.*, i.name AS item_name, i.unit, o.order_number
   FROM inventory_log l
   JOIN inventory_items i ON l.inventory_item_id = i.id
   LEFT JOIN orders o ON o.id = l.order_id
   ORDER BY l.created_at DESC LIMIT 100"
)->fetchAll();

// Visual metadata per log type. Anything that isn't restock/waste/correction
// (e.g. deductions from sales or pre-orders) falls back to "order".
$activityMeta = [
  'restock'    => ['label' => 'Restock',    'icon' => 'fa-box-open'],
  'waste'      => ['label' => 'Waste',      'icon' => 'fa-trash-can'],
  'correction' => ['label' => 'Correction', 'icon' => 'fa-sliders'],
  'order'      => ['label' => 'Order',      'icon' => 'fa-receipt'],
];

function activityDayLabel(string $datetime): string
{
  $day = date('Y-m-d', strtotime($datetime));
  if ($day === date('Y-m-d')) return 'Today';
  if ($day === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
  return date('D, M j', strtotime($datetime));
}

function logQtyHtml(array $log): string
{
  $change = (float) $log['change_amount'];
  $cls = $change >= 0 ? 'log-change-pos' : 'log-change-neg';
  return '<div class="log-qty ' . $cls . '">' . ($change >= 0 ? '+' : '−') . number_format(abs($change), 3)
    . ' <span style="font-weight:500;font-size:11.5px">' . e($log['unit']) . '</span></div>';
}

// day label => list of entries (a single row, or one group per order)
$logGroups = [];
$orderSlots = [];
foreach ($recentLog as $log) {
  $day = activityDayLabel($log['created_at']);
  $orderId = (int) ($log['order_id'] ?? 0);

  if ($orderId) {
    $key = $day . '|' . $orderId; // an order spanning two days shows under each day
    if (!isset($orderSlots[$key])) {
      $logGroups[$day][] = [
        'kind'         => 'order',
        'order_number' => $log['order_number'] ?: ('#' . $orderId),
        'time'         => $log['created_at'],
        'lines'        => [],
      ];
      $orderSlots[$key] = array_key_last($logGroups[$day]);
    }
    $logGroups[$day][$orderSlots[$key]]['lines'][] = $log;
  } else {
    $logGroups[$day][] = ['kind' => 'single', 'log' => $log];
  }
}

// Restock history per item, for the "View Details" popup (item 4). Grouped
// in PHP from one query rather than one query per item on the page.
try {
  $restockRows = $db->query(
    "SELECT inventory_item_id, change_amount, supplier, created_at
     FROM inventory_log WHERE reason = 'restock' ORDER BY created_at DESC"
  )->fetchAll();
} catch (\Throwable $e) {
  // supplier column migration not run yet — same history, just without supplier names
  $restockRows = $db->query(
    "SELECT inventory_item_id, change_amount, NULL AS supplier, created_at
     FROM inventory_log WHERE reason = 'restock' ORDER BY created_at DESC"
  )->fetchAll();
}
$restockByItem = [];
foreach ($restockRows as $r) {
  $restockByItem[(int) $r['inventory_item_id']][] = [
    'date'     => date('M j, Y', strtotime($r['created_at'])),
    'supplier' => $r['supplier'] ?: '—',
    'qty'      => number_format((float) $r['change_amount'], 3),
  ];
}

layoutHeader('Inventory');
?>
<style>
  .low-stock-banner {
    background: var(--color-error-bg);
    color: var(--color-error);
    border: 1px solid var(--color-error-border);
    border-radius: var(--radius-md);
    padding: 14px 18px;
    margin-bottom: 18px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .inv-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    align-items: start;
  }

  @media (max-width:1000px) {
    .inv-grid {
      grid-template-columns: 1fr;
    }
  }

  /* ---- Recent Activity ---- */
  .activity-card .card-body {
    padding: 0;
  }

  .activity-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
  }

  .activity-chip {
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-muted);
    font: inherit;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 999px;
    cursor: pointer;
    transition: all var(--transition-fast);
  }

  .activity-chip:hover {
    color: var(--text-color);
    border-color: var(--text-muted);
  }

  .activity-chip.active {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: #fff;
  }

  .activity-scroll {
    max-height: 560px;
    overflow-y: auto;
  }

  .activity-day {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--surface-raised);
    padding: 6px 16px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-color);
  }

  [data-log-day][hidden] {
    display: none;
  }

  .log-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
  }

  .log-row[hidden] {
    display: none;
  }

  .log-icon {
    flex: 0 0 34px;
    height: 34px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    font-size: 14px;
  }

  .log-type-restock .log-icon {
    background: #e6f4ea;
    color: #1e7a3d;
  }

  .log-type-waste .log-icon {
    background: #fdecea;
    color: #b3261e;
  }

  .log-type-correction .log-icon {
    background: #fff4e0;
    color: #b26a00;
  }

  .log-type-order .log-icon {
    background: #e8f0fe;
    color: #1a56c4;
  }

  .log-main {
    flex: 1;
    min-width: 0;
  }

  .log-name {
    font-weight: 600;
    font-size: 13.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .log-meta {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 2px;
  }

  .log-qty {
    flex: 0 0 auto;
    text-align: right;
    font-weight: 700;
    font-size: 13.5px;
    font-variant-numeric: tabular-nums;
  }

  .log-change-pos {
    color: #1e7a3d;
  }

  .log-change-neg {
    color: #b3261e;
  }

  /* Order groups */
  .log-order {
    border-bottom: 1px solid var(--border-color);
  }

  .log-order[hidden] {
    display: none;
  }

  .log-order>summary {
    list-style: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
  }

  .log-order>summary::-webkit-details-marker {
    display: none;
  }

  .log-order>summary:hover {
    background: var(--surface-raised);
  }

  .log-chevron {
    color: var(--text-muted);
    font-size: 11px;
    transition: transform var(--transition-fast);
  }

  .log-order[open] .log-chevron {
    transform: rotate(90deg);
  }

  .log-order-lines {
    padding: 0 16px 10px 62px;
  }

  .log-line {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 12px;
    padding: 6px 0;
    font-size: 13px;
    border-top: 1px dashed var(--border-color);
  }

  .log-line:first-child {
    border-top: none;
  }

  .log-line-name {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .activity-empty {
    padding: 28px 16px;
    text-align: center;
    color: var(--text-muted);
    font-size: 13.5px;
  }

  .activity-empty[hidden] {
    display: none;
  }

  .activity-more {
    padding: 12px 16px;
    border-top: 1px solid var(--border-color);
    text-align: center;
  }

  .inv-item-link {
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    font-weight: 600;
    color: var(--text-color);
    cursor: pointer;
    text-align: left;
    text-decoration: underline;
    text-decoration-color: transparent;
    transition: text-decoration-color var(--transition-fast), color var(--transition-fast);
  }

  .inv-item-link:hover {
    color: var(--primary-color);
    text-decoration-color: var(--primary-color);
  }

  .details-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 18px;
  }

  .details-summary-item {
    background: var(--surface-raised);
    border-radius: var(--radius-md);
    padding: 10px 12px;
  }

  .details-summary-label {
    font-size: 0.7rem;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 3px;
  }

  .details-summary-value {
    font-weight: 700;
    font-size: 0.95rem;
  }

  @media (max-width: 480px) {
    .details-summary {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="page-header">
  <div>
    <div class="page-header-title">Inventory</div>
    <div class="page-header-sub"><?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?> tracked</div>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/admin/inventory-categories.php" class="btn btn-ghost"><i class="fa-solid fa-tags"></i> Manage Categories</a>
    <a href="<?= APP_URL ?>/admin/recipes.php" class="btn btn-ghost"><i class="fa-solid fa-flask"></i> Product Recipes</a>
    <button class="btn btn-primary" onclick="openAddItemModal()"><i class="fa-solid fa-plus"></i> Add Item</button>
  </div>
</div>
<?php showFlash('global'); ?>

<?php if ($lowStockCount > 0): ?>
  <div class="low-stock-banner">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <?= $lowStockCount ?> item<?= $lowStockCount !== 1 ? 's are' : ' is' ?> at or below its reorder level.
  </div>
<?php endif; ?>

<div class="inv-grid">
  <div class="card">
    <div style="padding:var(--space-4);border-bottom:1px solid var(--border-color);display:flex;align-items:end;gap:var(--space-3);flex-wrap:wrap">
      <div style="flex:1;min-width:min(100%,240px)">
        <label class="form-label" for="inventory-search">Search stock items</label>
        <div style="position:relative">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted)"></i>
          <input class="form-control" id="inventory-search" type="search" placeholder="Search name, category, or unit…" style="padding-left:36px" autocomplete="off">
        </div>
      </div>
      <div style="min-width:190px">
        <label class="form-label" for="inventory-category-filter">Category</label>
        <select class="form-control" id="inventory-category-filter">
          <option value="">All categories</option>
          <?php foreach ($inventoryCategories as $category): ?>
            <option value="<?= e(mb_strtolower($category['name'])) ?>"><?= e($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <span id="inventory-result-count" class="text-muted" aria-live="polite" style="font-size:.82rem;padding-bottom:9px"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
    </div>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Category</th>
            <th>On Hand</th>
            <th>Reorder Level</th>
            <th>Cost/Unit</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted)">No inventory items yet.</td>
            </tr>
            <?php else: foreach ($items as $it):
              $low = (float) $it['available_quantity'] <= (float) $it['reorder_level'];
            ?>
              <tr data-inventory-row data-inventory-name="<?= e(mb_strtolower($it['name'] . ' ' . ($it['category'] ?? 'Other') . ' ' . $it['unit'])) ?>" data-inventory-category="<?= e(mb_strtolower($it['category'] ?? 'Other')) ?>" style="<?= $low ? 'background:#fdecea' : '' ?>">
                <td>
                  <button type="button" class="inv-item-link" onclick='openDetailsModal(<?= json_encode($it) ?>, <?= json_encode($restockByItem[$it['id']] ?? []) ?>)'>
                    <?= e($it['name']) ?>
                  </button>
                  <?php if (!empty($it['addon'])): ?><span class="badge <?= $it['addon']['addon_status'] === 'active' ? 'badge-ready' : 'badge-cancelled' ?>" style="margin-left:6px"><?= $it['addon']['addon_status'] === 'active' ? 'Add-on' : 'Add-on paused' ?>: <?= e($it['addon']['addon_name']) ?></span><?php endif; ?>
                </td>
                <td><span class="badge badge-ready"><?= e($it['category'] ?? 'Other') ?></span></td>
                <td>
                  <?= number_format((float) $it['quantity_on_hand'], 3) ?> <?= e($it['unit']) ?> on hand
                  <div class="text-muted" style="font-size:11px;margin-top:3px">
                    <?= number_format(max(0, (float) $it['available_quantity']), 3) ?> <?= e($it['unit']) ?> available for new orders
                    <?php if ((float) $it['reserved_quantity'] > 0): ?> · <?= number_format((float) $it['reserved_quantity'], 3) ?> <?= e($it['unit']) ?> held for pre-orders<?php endif; ?>
                  </div>
                  <?php if ($low): ?><span class="badge badge-cancelled" style="margin-left:6px">Low</span><?php endif; ?>
                </td>
                <td><?= number_format((float) $it['reorder_level'], 3) ?> <?= e($it['unit']) ?></td>
                <td><?= peso($it['cost_per_unit']) ?></td>
                <td style="white-space:nowrap">
                  <button class="btn btn-sm btn-outline" title="View Details" onclick='openDetailsModal(<?= json_encode($it) ?>, <?= json_encode($restockByItem[$it['id']] ?? []) ?>)'><i class="fa-solid fa-eye"></i></button>
                  <button class="btn btn-sm btn-outline" title="Adjust Stock" onclick='openAdjustModal(<?= json_encode($it) ?>)'><i class="fa-solid fa-arrows-rotate"></i> Adjust</button>
                  <button class="btn btn-sm btn-outline" title="Edit Item" onclick='openEditItemModal(<?= json_encode($it) ?>)'><i class="fa-solid fa-pen"></i></button>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this inventory item? This does not affect past orders.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="delete_item" value="1">
                    <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger" title="Delete Item"><i class="fa-solid fa-trash"></i></button>
                  </form>
                </td>
              </tr>
          <?php endforeach;
          endif; ?>
          <?php if (!empty($items)): ?>
            <tr id="inventory-search-empty" hidden>
              <td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted)">No stock items match your search and category filters.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="card activity-card">
    <div class="card-header">
      <div class="card-title">Recent Activity</div>
    </div>

    <div class="activity-filters" role="group" aria-label="Filter activity">
      <button type="button" class="activity-chip active" data-log-filter="all">All</button>
      <button type="button" class="activity-chip" data-log-filter="restock">Restock</button>
      <button type="button" class="activity-chip" data-log-filter="waste">Waste</button>
      <button type="button" class="activity-chip" data-log-filter="correction">Correction</button>
      <button type="button" class="activity-chip" data-log-filter="order">Orders</button>
    </div>

    <div class="card-body">
      <?php if (empty($recentLog)): ?>
        <div class="activity-empty">No stock changes logged yet.</div>
      <?php else: ?>
        <div class="activity-scroll">
          <?php foreach ($logGroups as $dayLabel => $entries): ?>
            <div data-log-day>
              <div class="activity-day"><?= e($dayLabel) ?></div>
              <?php foreach ($entries as $entry): ?>
                <?php if ($entry['kind'] === 'order'):
                  $lines = $entry['lines'];
                  $count = count($lines);
                ?>
                  <details class="log-order log-type-order" data-log-entry data-log-type="order"
                    title="<?= e(date('M j, Y g:i A', strtotime($entry['time']))) ?>">
                    <summary>
                      <div class="log-icon"><i class="fa-solid fa-receipt"></i></div>
                      <div class="log-main">
                        <div class="log-name">Order <?= e($entry['order_number']) ?></div>
                        <div class="log-meta"><?= $count ?> ingredient<?= $count !== 1 ? 's' : '' ?> deducted · <?= date('g:i A', strtotime($entry['time'])) ?></div>
                      </div>
                      <i class="fa-solid fa-chevron-right log-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="log-order-lines">
                      <?php foreach ($lines as $line): ?>
                        <div class="log-line">
                          <span class="log-line-name"><?= e($line['item_name']) ?></span>
                          <?= logQtyHtml($line) ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </details>

                <?php else:
                  $log = $entry['log'];
                  $type = in_array($log['reason'], ['restock', 'waste', 'correction'], true) ? $log['reason'] : 'order';
                  $meta = $activityMeta[$type];
                  $detail = $meta['label'];
                  if ($type === 'restock' && !empty($log['supplier'])) $detail .= ' · ' . $log['supplier'];
                  if ($type === 'order') $detail = ucfirst((string) $log['reason']);
                ?>
                  <div class="log-row log-type-<?= $type ?>" data-log-entry data-log-type="<?= $type ?>"
                    title="<?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?>">
                    <div class="log-icon"><i class="fa-solid <?= $meta['icon'] ?>"></i></div>
                    <div class="log-main">
                      <div class="log-name"><?= e($log['item_name']) ?></div>
                      <div class="log-meta"><?= e($detail) ?> · <?= date('g:i A', strtotime($log['created_at'])) ?></div>
                    </div>
                    <?= logQtyHtml($log) ?>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <div class="activity-empty" id="activity-filter-empty" hidden>No activity of this type yet.</div>
        </div>
        <div class="activity-more" id="activity-more-wrap" hidden>
          <button type="button" class="btn btn-sm btn-ghost" id="activity-more-btn"></button>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Add Item modal -->
<div class="modal-overlay hidden" id="add-item-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Add Inventory Item</div>
      <button class="modal-close" onclick="closeAddItemModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1rem">
        Adding several ingredients? This stays open after each save — click Done when you're finished and the list will refresh.
      </p>
      <div class="form-group"><label class="form-label">Name</label><input type="text" id="new-item-name" class="form-control" required placeholder="e.g. Fresh Milk"></div>
      <div class="form-group"><label class="form-label" for="new-item-category">Category</label><select id="new-item-category" class="form-control"><?php foreach ($inventoryCategories as $categoryOption): ?><option value="<?= e($categoryOption['name']) ?>"><?= e($categoryOption['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">Stock unit</label><input type="text" id="new-item-unit" class="form-control" required placeholder="g, kg, ml, L, pcs"><small class="text-muted">How this item is counted in stock. Drink sizes are set on the Ordering page.</small></div>
      <div class="form-group"><label class="form-label">Starting Quantity</label><input type="number" step="0.001" id="new-item-qty" class="form-control" value="0"></div>
      <div class="form-group"><label class="form-label">Reorder Level</label><input type="number" step="0.001" id="new-item-reorder" class="form-control" value="0"></div>
      <div class="form-group">
        <label class="form-label">Cost per Unit (₱)</label>
        <input type="number" step="0.01" id="new-item-cost" class="form-control" value="0"
          onkeydown="if(event.key==='Enter'){event.preventDefault();saveNewInventoryItem();}">
      </div>
      <hr style="border:0;border-top:1px solid var(--border-color);margin:1rem 0">
      <label style="display:flex;align-items:center;gap:0.6rem;font-weight:600;cursor:pointer">
        <input type="checkbox" id="new-item-is-addon" onchange="toggleAddonFields('new')"> Also offer as a customer add-on
      </label>
      <div id="new-addon-fields" style="display:none;margin-top:1rem">
        <p class="text-muted" style="font-size:.8rem;margin-bottom:.75rem">The selected amount is deducted from this same inventory item whenever a customer orders the add-on.</p>
        <div class="form-group"><label class="form-label">Add-on Name</label><input type="text" id="new-addon-name" class="form-control" placeholder="e.g. Extra shot"></div>
        <div class="form-group"><label class="form-label">Customer Price (₱)</label><input type="number" min="0" step="0.50" id="new-addon-price" class="form-control" value="0"></div>
        <div class="form-group"><label class="form-label">Inventory used per add-on</label><input type="number" min="0.001" step="0.001" id="new-addon-qty" class="form-control" value="0.010"><small class="text-muted">Measured in the inventory unit above.</small></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeAddItemModal()">Done</button>
      <button type="button" class="btn btn-primary" onclick="saveNewInventoryItem()">
        <i class="fa-solid fa-floppy-disk"></i> Save &amp; Add Another
      </button>
    </div>
  </div>
</div>

<!-- Adjust Stock modal -->
<div class="modal-overlay hidden" id="adjust-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="adjust-modal-title">Adjust Stock</div>
      <button class="modal-close" onclick="closeAdjustModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="adjust_item" value="1">
      <input type="hidden" name="item_id" id="adjust-item-id">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Reason</label>
          <select name="reason" id="adjust-reason" class="form-control" onchange="document.getElementById('adjust-supplier-wrap').style.display = this.value === 'restock' ? 'block' : 'none'">
            <option value="restock">Restock (add)</option>
            <option value="waste">Waste / Spoilage (subtract)</option>
            <option value="correction">Correction (+/-)</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Quantity</label><input type="number" step="0.001" name="amount" class="form-control" required></div>
        <div class="form-group" id="adjust-supplier-wrap">
          <label class="form-label">Supplier <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
          <input type="text" name="supplier" class="form-control" placeholder="e.g. Supplier A">
        </div>
        <p style="font-size:12.5px;color:var(--text-muted)">Restock and Waste always use a positive number — the direction is applied automatically. Correction respects the sign you type.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeAdjustModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Item modal -->
<div class="modal-overlay hidden" id="edit-item-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Edit Item</div>
      <button class="modal-close" onclick="closeEditItemModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="edit_item" value="1">
      <input type="hidden" name="item_id" id="edit-item-id">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Name</label><input type="text" name="name" id="edit-item-name" class="form-control" required></div>
        <div class="form-group"><label class="form-label" for="edit-item-category">Category</label><select name="category" id="edit-item-category" class="form-control"><?php foreach ($inventoryCategories as $categoryOption): ?><option value="<?= e($categoryOption['name']) ?>"><?= e($categoryOption['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Stock unit</label><input type="text" name="unit" id="edit-item-unit" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Reorder Level</label><input type="number" step="0.001" name="reorder_level" id="edit-item-reorder" class="form-control"></div>
        <div class="form-group"><label class="form-label">Cost per Unit (₱)</label><input type="number" step="0.01" name="cost_per_unit" id="edit-item-cost" class="form-control"></div>
        <hr style="border:0;border-top:1px solid var(--border-color);margin:1rem 0">
        <label style="display:flex;align-items:center;gap:0.6rem;font-weight:600;cursor:pointer">
          <input type="checkbox" name="is_addon" id="edit-item-is-addon" value="1" onchange="toggleAddonFields('edit')"> Also offer as a customer add-on
        </label>
        <div id="edit-addon-fields" style="display:none;margin-top:1rem">
          <p class="text-muted" style="font-size:.8rem;margin-bottom:.75rem">The selected amount is deducted from this same inventory item whenever a customer orders the add-on. Turning this off hides the add-on but keeps its stock and product assignments.</p>
          <div class="form-group"><label class="form-label">Add-on Name</label><input type="text" name="addon_name" id="edit-addon-name" class="form-control"></div>
          <div class="form-group"><label class="form-label">Customer Price (₱)</label><input type="number" min="0" step="0.50" name="addon_price" id="edit-addon-price" class="form-control" value="0"></div>
          <div class="form-group"><label class="form-label">Inventory used per add-on (<span id="edit-addon-unit"></span>)</label><input type="number" min="0.001" step="0.001" name="addon_quantity_per_unit" id="edit-addon-qty" class="form-control" value="0.010"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeEditItemModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Item Details modal (item 4) -->
<div class="modal-overlay hidden" id="details-modal">
  <div class="modal" style="width:760px">
    <div class="modal-header">
      <div class="modal-title" id="details-modal-title">Item Details</div>
      <button class="modal-close" onclick="closeDetailsModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="details-summary">
        <div class="details-summary-item">
          <div class="details-summary-label">Current Stock</div>
          <div class="details-summary-value" id="details-stock">—</div>
        </div>
        <div class="details-summary-item">
          <div class="details-summary-label">Reorder Level</div>
          <div class="details-summary-value" id="details-reorder">—</div>
        </div>
        <div class="details-summary-item">
          <div class="details-summary-label">Cost / Unit</div>
          <div class="details-summary-value" id="details-cost">—</div>
        </div>
      </div>
      <div style="font-weight:700;margin-bottom:10px">Restock History</div>
      <div style="overflow-x:auto">
        <table class="data-table" id="details-restock-table">
          <thead>
            <tr>
              <th>Restock Date</th>
              <th>Supplier</th>
              <th class="num">Qty Restocked</th>
            </tr>
          </thead>
          <tbody id="details-restock-body"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeDetailsModal()">Close</button>
    </div>
  </div>
</div>

<script>
  let inventoryItemsAddedThisSession = false;

  const inventorySearch = document.getElementById('inventory-search');
  const inventoryCategoryFilter = document.getElementById('inventory-category-filter');
  const inventoryRows = Array.from(document.querySelectorAll('[data-inventory-row]'));
  const inventorySearchEmpty = document.getElementById('inventory-search-empty');
  const inventoryResultCount = document.getElementById('inventory-result-count');

  function filterInventoryRows() {
    const query = inventorySearch.value.trim().toLocaleLowerCase();
    const category = inventoryCategoryFilter.value;
    let visible = 0;
    inventoryRows.forEach(row => {
      const matches = row.dataset.inventoryName.includes(query) &&
        (!category || row.dataset.inventoryCategory === category);
      row.hidden = !matches;
      if (matches) visible++;
    });
    if (inventorySearchEmpty) inventorySearchEmpty.hidden = query === '' && category === '' || visible > 0;
    if (inventoryResultCount) inventoryResultCount.textContent = query || category ?
      `${visible} of ${inventoryRows.length} items match` :
      `${inventoryRows.length} item${inventoryRows.length === 1 ? '' : 's'}`;
  }

  inventorySearch.addEventListener('input', filterInventoryRows);
  inventoryCategoryFilter.addEventListener('change', filterInventoryRows);

  // ---- Recent Activity: type filter + "Show more" paging ----
  const logRows = Array.from(document.querySelectorAll('[data-log-entry]'));
  const logDays = Array.from(document.querySelectorAll('[data-log-day]'));
  const logMoreWrap = document.getElementById('activity-more-wrap');
  const logMoreBtn = document.getElementById('activity-more-btn');
  const logFilterEmpty = document.getElementById('activity-filter-empty');
  const LOG_PAGE_SIZE = 12;
  let logType = 'all';
  let logLimit = LOG_PAGE_SIZE;

  function renderActivity() {
    let matched = 0,
      shown = 0;
    logRows.forEach(row => {
      const ok = logType === 'all' || row.dataset.logType === logType;
      if (ok) matched++;
      const visible = ok && shown < logLimit;
      if (visible) shown++;
      row.hidden = !visible;
    });
    logDays.forEach(day => {
      day.hidden = !day.querySelector('[data-log-entry]:not([hidden])');
    });
    if (logFilterEmpty) logFilterEmpty.hidden = matched > 0;
    if (logMoreWrap) {
      logMoreWrap.hidden = matched <= shown;
      logMoreBtn.textContent = `Show more (${matched - shown})`;
    }
  }

  document.querySelectorAll('[data-log-filter]').forEach(btn => {
    btn.addEventListener('click', () => {
      logType = btn.dataset.logFilter;
      logLimit = LOG_PAGE_SIZE;
      document.querySelectorAll('[data-log-filter]').forEach(b => b.classList.toggle('active', b === btn));
      renderActivity();
    });
  });
  if (logMoreBtn) logMoreBtn.addEventListener('click', () => {
    logLimit += LOG_PAGE_SIZE;
    renderActivity();
  });
  if (logRows.length) renderActivity();

  // ---- Modals ----
  function openAddItemModal() {
    inventoryItemsAddedThisSession = false;
    ['new-item-name', 'new-item-unit'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('new-item-category').value = <?= json_encode((string)($inventoryCategories[0]['name'] ?? '')) ?>;
    ['new-item-qty', 'new-item-reorder', 'new-item-cost'].forEach(id => document.getElementById(id).value = '0');
    document.getElementById('new-item-is-addon').checked = false;
    document.getElementById('new-addon-name').value = '';
    document.getElementById('new-addon-price').value = '0';
    document.getElementById('new-addon-qty').value = '0.010';
    toggleAddonFields('new');
    document.getElementById('add-item-modal').classList.remove('hidden');
  }

  function closeAddItemModal() {
    document.getElementById('add-item-modal').classList.add('hidden');
    // At least one item was saved while this was open — reload so the
    // table (and the Adjust/Edit/Delete buttons' embedded item data)
    // reflects what was just added.
    if (inventoryItemsAddedThisSession) location.reload();
  }

  function saveNewInventoryItem() {
    const name = document.getElementById('new-item-name').value.trim();
    const unit = document.getElementById('new-item-unit').value.trim();

    if (!name || !unit) {
      showToast('Name and unit are required', 'warning');
      return;
    }

    const formData = new FormData();
    formData.append('name', name);
    formData.append('category', document.getElementById('new-item-category').value);
    formData.append('unit', unit);
    formData.append('quantity_on_hand', document.getElementById('new-item-qty').value || 0);
    formData.append('reorder_level', document.getElementById('new-item-reorder').value || 0);
    formData.append('cost_per_unit', document.getElementById('new-item-cost').value || 0);
    formData.append('is_addon', document.getElementById('new-item-is-addon').checked ? '1' : '0');
    formData.append('addon_name', document.getElementById('new-addon-name').value.trim());
    formData.append('addon_price', document.getElementById('new-addon-price').value || 0);
    formData.append('addon_quantity_per_unit', document.getElementById('new-addon-qty').value || 0);
    formData.append('csrf_token', '<?= csrfToken() ?>');

    fetch('<?= APP_URL ?>/api/save_inventory_item.php', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          inventoryItemsAddedThisSession = true;
          showToast('"' + name + '" added — add another or click Done');
          ['new-item-name', 'new-item-unit'].forEach(id => document.getElementById(id).value = '');
          document.getElementById('new-item-category').value = <?= json_encode((string)($inventoryCategories[0]['name'] ?? '')) ?>;
          ['new-item-qty', 'new-item-reorder', 'new-item-cost'].forEach(id => document.getElementById(id).value = '0');
          document.getElementById('new-item-is-addon').checked = false;
          document.getElementById('new-addon-name').value = '';
          document.getElementById('new-addon-price').value = '0';
          document.getElementById('new-addon-qty').value = '0.010';
          toggleAddonFields('new');
          document.getElementById('new-item-name').focus();
        } else {
          showToast('Failed to save: ' + (data.error || 'Unknown error'), 'error');
        }
      })
      .catch(err => {
        console.error('Save failed:', err);
        showToast('Failed to save item', 'error');
      });
  }

  function openDetailsModal(item, restockHistory) {
    document.getElementById('details-modal-title').textContent = item.name;
    document.getElementById('details-stock').textContent = parseFloat(item.quantity_on_hand).toFixed(3) + ' ' + item.unit;
    document.getElementById('details-reorder').textContent = parseFloat(item.reorder_level).toFixed(3) + ' ' + item.unit;
    document.getElementById('details-cost').textContent = '₱' + parseFloat(item.cost_per_unit).toFixed(2);

    const body = document.getElementById('details-restock-body');
    body.innerHTML = '';
    if (!restockHistory || restockHistory.length === 0) {
      body.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:20px;color:var(--text-muted)">No restock records yet.</td></tr>';
    } else {
      restockHistory.forEach(r => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${r.date}</td><td>${r.supplier}</td><td class="num">+${r.qty} ${item.unit}</td>`;
        body.appendChild(tr);
      });
    }
    document.getElementById('details-modal').classList.remove('hidden');
  }

  function closeDetailsModal() {
    document.getElementById('details-modal').classList.add('hidden');
  }

  function openAdjustModal(item) {
    document.getElementById('adjust-modal-title').textContent = 'Adjust: ' + item.name;
    document.getElementById('adjust-item-id').value = item.id;
    document.getElementById('adjust-reason').value = 'restock';
    document.getElementById('adjust-supplier-wrap').style.display = 'block';
    document.querySelector('#adjust-modal input[name="supplier"]').value = '';
    document.querySelector('#adjust-modal input[name="amount"]').value = '';
    document.getElementById('adjust-modal').classList.remove('hidden');
  }

  function closeAdjustModal() {
    document.getElementById('adjust-modal').classList.add('hidden');
  }

  function openEditItemModal(item) {
    document.getElementById('edit-item-id').value = item.id;
    document.getElementById('edit-item-name').value = item.name;
    document.getElementById('edit-item-category').value = item.category || <?= json_encode((string)($inventoryCategories[0]['name'] ?? '')) ?>;
    document.getElementById('edit-item-unit').value = item.unit;
    document.getElementById('edit-item-reorder').value = item.reorder_level;
    document.getElementById('edit-item-cost').value = item.cost_per_unit;
    const addon = item.addon || null;
    document.getElementById('edit-item-is-addon').checked = !!addon && addon.addon_status === 'active';
    document.getElementById('edit-addon-name').value = addon ? addon.addon_name : item.name;
    document.getElementById('edit-addon-price').value = addon ? addon.addon_price : '0';
    document.getElementById('edit-addon-qty').value = addon ? addon.qty_per_unit : '0.010';
    document.getElementById('edit-addon-unit').textContent = item.unit;
    toggleAddonFields('edit');
    document.getElementById('edit-item-modal').classList.remove('hidden');
  }

  function toggleAddonFields(prefix) {
    const checked = document.getElementById(prefix + '-item-is-addon').checked;
    document.getElementById(prefix + '-addon-fields').style.display = checked ? 'block' : 'none';
  }

  function closeEditItemModal() {
    document.getElementById('edit-item-modal').classList.add('hidden');
  }

  ['add-item-modal', 'adjust-modal', 'edit-item-modal', 'details-modal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
      if (e.target === this) this.classList.add('hidden');
    });
  });
</script>

<?php layoutFooter(); ?>