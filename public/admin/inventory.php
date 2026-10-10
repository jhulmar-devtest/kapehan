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
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

// ---- Add new item ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
  verifyCsrf();
  $name  = sanitizeString($_POST['name'] ?? '', 120);
  $unit  = sanitizeString($_POST['unit'] ?? '', 20);
  $qty   = round((float) ($_POST['quantity_on_hand'] ?? 0), 3);
  $reorder = round((float) ($_POST['reorder_level'] ?? 0), 3);
  $cost  = round((float) ($_POST['cost_per_unit'] ?? 0), 2);

  if (empty($name) || empty($unit)) {
    flash('global', 'Name and unit are required.', 'error');
  } else {
    $db->prepare(
      "INSERT INTO inventory_items (name, unit, quantity_on_hand, reorder_level, cost_per_unit) VALUES (?, ?, ?, ?, ?)"
    )->execute([$name, $unit, $qty, $reorder, $cost]);
    $newId = (int) $db->lastInsertId();
    if ($qty > 0) {
      $db->prepare(
        "INSERT INTO inventory_log (inventory_item_id, change_amount, reason, actor_role, actor_id) VALUES (?, ?, 'restock', ?, ?)"
      )->execute([$newId, $qty, ROLE_ADMIN, currentUserId()]);
    }
    auditLog(ROLE_ADMIN, currentUserId(), 'create_inventory_item', 'inventory_items', $newId);
    flash('global', 'Inventory item added.', 'success');
  }
  redirect(APP_URL . '/admin/inventory.php');
}

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
  $unit = sanitizeString($_POST['unit'] ?? '', 20);
  $reorder = round((float) ($_POST['reorder_level'] ?? 0), 3);
  $cost = round((float) ($_POST['cost_per_unit'] ?? 0), 2);
  if ($id && !empty($name) && !empty($unit)) {
    $db->prepare(
      "UPDATE inventory_items SET name=?, unit=?, reorder_level=?, cost_per_unit=? WHERE id=?"
    )->execute([$name, $unit, $reorder, $cost, $id]);
    flash('global', 'Item details updated.', 'success');
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
$lowStockCount = count(array_filter($items, fn($i) => (float) $i['quantity_on_hand'] <= (float) $i['reorder_level']));

$recentLog = $db->query(
  "SELECT l.*, i.name AS item_name, i.unit, o.order_number
   FROM inventory_log l
   JOIN inventory_items i ON l.inventory_item_id = i.id
   LEFT JOIN orders o ON o.id = l.order_id
   ORDER BY l.created_at DESC LIMIT 12"
)->fetchAll();

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

  .log-item {
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
    font-size: 13.5px;
  }

  .log-item:last-child {
    border-bottom: none;
  }

  .log-change-pos {
    color: #1e7a3d;
    font-weight: 700;
  }

  .log-change-neg {
    color: #b3261e;
    font-weight: 700;
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
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>On Hand</th>
            <th>Reorder Level</th>
            <th>Cost/Unit</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:30px;color:var(--text-muted)">No inventory items yet.</td>
            </tr>
            <?php else: foreach ($items as $it):
              $low = (float) $it['quantity_on_hand'] <= (float) $it['reorder_level'];
            ?>
              <tr style="<?= $low ? 'background:#fdecea' : '' ?>">
                <td>
                  <button type="button" class="inv-item-link" onclick='openDetailsModal(<?= json_encode($it) ?>, <?= json_encode($restockByItem[$it['id']] ?? []) ?>)'>
                    <?= e($it['name']) ?>
                  </button>
                </td>
                <td><?= number_format((float) $it['quantity_on_hand'], 3) ?> <?= e($it['unit']) ?>
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
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title">Recent Activity</div>
    </div>
    <div class="card-body">
      <?php if (empty($recentLog)): ?>
        <p style="color:var(--text-muted);font-size:13.5px">No stock changes logged yet.</p>
        <?php else: foreach ($recentLog as $log): ?>
          <div class="log-item">
            <strong><?= e($log['item_name']) ?></strong>
            <span class="<?= $log['change_amount'] >= 0 ? 'log-change-pos' : 'log-change-neg' ?>">
              <?= $log['change_amount'] >= 0 ? '+' : '' ?><?= number_format((float) $log['change_amount'], 3) ?> <?= e($log['unit']) ?>
            </span>
            <div style="color:var(--text-muted)"><?= e(ucfirst($log['reason'])) ?><?= !empty($log['order_number']) ? ' · Order ' . e($log['order_number']) : '' ?> &middot; <?= date('M j, g:i A', strtotime($log['created_at'])) ?></div>
          </div>
      <?php endforeach;
      endif; ?>
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
      <div class="form-group"><label class="form-label">Unit</label><input type="text" id="new-item-unit" class="form-control" required placeholder="g, kg, ml, L, pcs"></div>
      <div class="form-group"><label class="form-label">Starting Quantity</label><input type="number" step="0.001" id="new-item-qty" class="form-control" value="0"></div>
      <div class="form-group"><label class="form-label">Reorder Level</label><input type="number" step="0.001" id="new-item-reorder" class="form-control" value="0"></div>
      <div class="form-group">
        <label class="form-label">Cost per Unit (₱)</label>
        <input type="number" step="0.01" id="new-item-cost" class="form-control" value="0"
          onkeydown="if(event.key==='Enter'){event.preventDefault();saveNewInventoryItem();}">
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
        <div class="form-group"><label class="form-label">Unit</label><input type="text" name="unit" id="edit-item-unit" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Reorder Level</label><input type="number" step="0.001" name="reorder_level" id="edit-item-reorder" class="form-control"></div>
        <div class="form-group"><label class="form-label">Cost per Unit (₱)</label><input type="number" step="0.01" name="cost_per_unit" id="edit-item-cost" class="form-control"></div>
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

  function openAddItemModal() {
    inventoryItemsAddedThisSession = false;
    ['new-item-name', 'new-item-unit'].forEach(id => document.getElementById(id).value = '');
    ['new-item-qty', 'new-item-reorder', 'new-item-cost'].forEach(id => document.getElementById(id).value = '0');
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
    formData.append('unit', unit);
    formData.append('quantity_on_hand', document.getElementById('new-item-qty').value || 0);
    formData.append('reorder_level', document.getElementById('new-item-reorder').value || 0);
    formData.append('cost_per_unit', document.getElementById('new-item-cost').value || 0);
    formData.append('csrf_token', '<?= csrfToken() ?>');

    fetch('<?= APP_URL ?>/api/save_inventory_item.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          inventoryItemsAddedThisSession = true;
          showToast('"' + name + '" added — add another or click Done');
          ['new-item-name', 'new-item-unit'].forEach(id => document.getElementById(id).value = '');
          ['new-item-qty', 'new-item-reorder', 'new-item-cost'].forEach(id => document.getElementById(id).value = '0');
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
    document.getElementById('edit-item-unit').value = item.unit;
    document.getElementById('edit-item-reorder').value = item.reorder_level;
    document.getElementById('edit-item-cost').value = item.cost_per_unit;
    document.getElementById('edit-item-modal').classList.remove('hidden');
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
