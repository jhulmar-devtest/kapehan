<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();
$sizeLabels = inventorySizeLabels($db);

$products = $db->query(
  'SELECT p.id, p.name, p.has_sizes, c.name AS category_name
     FROM products p
     JOIN categories c ON c.id = p.category_id
    ORDER BY c.sort_order, c.name, p.name'
)->fetchAll(PDO::FETCH_ASSOC);
$inventoryItems = $db->query('SELECT id, name, unit, quantity_on_hand FROM inventory_items ORDER BY name')
  ->fetchAll(PDO::FETCH_ASSOC);

$selectedProductId = (int) ($_POST['product_id'] ?? $_GET['product_id'] ?? ($products[0]['id'] ?? 0));
$selectedProduct = null;
foreach ($products as $product) {
  if ((int) $product['id'] === $selectedProductId) {
    $selectedProduct = $product;
    break;
  }
}
if (!$selectedProduct && $products) {
  $selectedProduct = $products[0];
  $selectedProductId = (int) $selectedProduct['id'];
}

$requestedSize = trim((string) ($_POST['size_label'] ?? $_GET['size_label'] ?? ''));
$validSizes = !empty($selectedProduct['has_sizes']) ? $sizeLabels : [];
$invalidRequestedSize = $requestedSize !== '' && !in_array($requestedSize, $validSizes, true);
$selectedSize = in_array($requestedSize, $validSizes, true) ? $requestedSize : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recipe'])) {
  verifyCsrf();
  if ($invalidRequestedSize) {
    flash('global', 'That size is not available for this product.', 'error');
    redirect(APP_URL . '/admin/recipes.php?' . http_build_query(['product_id' => $selectedProductId]));
  }
  if (!$selectedProduct || !$inventoryItems) {
    flash('global', 'Add a product and at least one inventory item before creating a recipe.', 'error');
    redirect(APP_URL . '/admin/recipes.php');
  }

  $quantities = $_POST['quantities'] ?? [];
  if (!is_array($quantities)) $quantities = [];
  $validItemIds = array_fill_keys(array_map(fn($item) => (int) $item['id'], $inventoryItems), true);
  $recipeRows = [];
  $invalidQuantity = false;
  foreach ($quantities as $rawItemId => $rawAmount) {
    $itemId = (int) $rawItemId;
    if (!isset($validItemIds[$itemId]) || !is_scalar($rawAmount)) continue;
    $rawAmount = trim((string) $rawAmount);
    if ($rawAmount === '') continue;
    if (!is_numeric($rawAmount) || (float) $rawAmount < 0 || (float) $rawAmount > 999999999) {
      $invalidQuantity = true;
      break;
    }
    $amount = round((float) $rawAmount, 3);
    if ($selectedSize === '' && $amount <= 0) continue;
    $recipeRows[] = [$itemId, $amount];
  }

  if ($invalidQuantity) {
    flash('global', 'Recipe quantities must be zero or a positive amount with up to three decimal places.', 'error');
    redirect(APP_URL . '/admin/recipes.php?' . http_build_query(['product_id' => $selectedProductId, 'size_label' => $selectedSize]));
  }

  try {
    $db->beginTransaction();
    $db->prepare('DELETE FROM product_ingredients WHERE product_id = ? AND size_label = ?')
      ->execute([$selectedProductId, $selectedSize]);
    $insertRecipe = $db->prepare(
      'INSERT INTO product_ingredients (product_id, inventory_item_id, size_label, qty_per_unit)
       VALUES (?, ?, ?, ?)'
    );
    foreach ($recipeRows as [$itemId, $amount]) {
      $insertRecipe->execute([$selectedProductId, $itemId, $selectedSize, $amount]);
    }
    auditLog(ROLE_ADMIN, currentUserId(), 'update_product_recipe', 'products', $selectedProductId);
    $db->commit();
    flash('global', 'Product recipe saved.', 'success');
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Save product recipe failed: ' . $e->getMessage());
    flash('global', 'Could not save this recipe. Please try again.', 'error');
  }
  redirect(APP_URL . '/admin/recipes.php?' . http_build_query(['product_id' => $selectedProductId, 'size_label' => $selectedSize]));
}

$defaultRecipe = [];
$selectedRecipe = [];
if ($selectedProduct) {
  $stmt = $db->prepare('SELECT inventory_item_id, qty_per_unit FROM product_ingredients WHERE product_id = ? AND size_label = ?');
  $stmt->execute([$selectedProductId, '']);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $defaultRecipe[(int) $row['inventory_item_id']] = (float) $row['qty_per_unit'];

  if ($selectedSize !== '') {
    $stmt->execute([$selectedProductId, $selectedSize]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $selectedRecipe[(int) $row['inventory_item_id']] = (float) $row['qty_per_unit'];
  } else {
    $selectedRecipe = $defaultRecipe;
  }
}

layoutHeader('Recipes');
?>
<style>
  .recipe-help { margin-bottom: 18px; }
  .recipe-selectors { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; align-items: end; }
  .recipe-table-wrap { overflow-x: auto; }
  .recipe-qty { width: 150px; min-width: 120px; }
  .recipe-size-hint { color: var(--text-muted); font-size: .78rem; white-space: nowrap; }
  .recipe-empty { text-align: center; padding: 28px 16px; color: var(--text-muted); }
  @media (max-width: 640px) {
    .recipe-selectors { grid-template-columns: 1fr; }
    .recipe-qty { width: 126px; }
  }
</style>

<div class="page-header">
  <div>
    <div class="page-header-title">Product Recipes</div>
    <div class="page-header-sub">Link each menu item to the stock it consumes.</div>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/admin/inventory.php" class="btn btn-ghost"><i class="fa-solid fa-boxes-stacked"></i> Inventory</a>
    <a href="<?= APP_URL ?>/admin/ordering.php" class="btn btn-ghost"><i class="fa-solid fa-mug-hot"></i> Products</a>
  </div>
</div>
<?php showFlash('global'); ?>

<div class="alert alert-info recipe-help">
  <i class="fa-solid fa-circle-info"></i>
  <div>
    Enter how much of each inventory item goes into <strong>one serving</strong>, using the same unit as the inventory record.
    For example, if milk is stocked in liters, enter <strong>0.180</strong> for 180 ml. Fractions are supported to three decimal places.
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="recipe-selectors">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="recipe-product">Product</label>
        <select class="form-control" name="product_id" id="recipe-product" onchange="this.form.submit()" <?= !$products ? 'disabled' : '' ?>>
          <?php foreach ($products as $product): ?>
            <option value="<?= (int) $product['id'] ?>" <?= (int) $product['id'] === $selectedProductId ? 'selected' : '' ?>><?= e($product['name']) ?> · <?= e($product['category_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (!empty($selectedProduct['has_sizes'])): ?>
        <div class="form-group" style="margin:0">
          <label class="form-label" for="recipe-size">Recipe size</label>
          <select class="form-control" name="size_label" id="recipe-size" onchange="this.form.submit()">
            <option value="" <?= $selectedSize === '' ? 'selected' : '' ?>>Default recipe · all sizes</option>
            <?php foreach ($sizeLabels as $label): ?>
              <option value="<?= e($label) ?>" <?= $selectedSize === $label ? 'selected' : '' ?>><?= e($label) ?> override</option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if (!$products): ?>
  <div class="card recipe-empty">Add a product in Ordering before defining its recipe.</div>
<?php elseif (!$inventoryItems): ?>
  <div class="card recipe-empty">
    <p>Add inventory items before defining a recipe.</p>
    <a href="<?= APP_URL ?>/admin/inventory.php" class="btn btn-primary mt-3">Open Inventory</a>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-flask"></i> <?= e($selectedProduct['name']) ?> · <?= $selectedSize !== '' ? e($selectedSize) . ' recipe' : 'Default recipe' ?></div>
    </div>
    <div class="card-body">
      <?php if ($selectedSize !== ''): ?>
        <div class="alert alert-info mb-4" style="font-size:.82rem">
          Leave a quantity blank to use the default recipe. Enter a different amount to override it; enter 0 to exclude that ingredient for this size.
        </div>
      <?php else: ?>
        <p class="text-muted mb-4">This recipe is the default for this product. Size-specific values can override individual ingredients.</p>
      <?php endif; ?>

      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="save_recipe" value="1">
        <input type="hidden" name="product_id" value="<?= $selectedProductId ?>">
        <input type="hidden" name="size_label" value="<?= e($selectedSize) ?>">
        <div class="recipe-table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Inventory item</th>
                <th>On hand</th>
                <?php if ($selectedSize !== ''): ?><th>Default</th><?php endif; ?>
                <th>Used per serving (<?= $selectedSize !== '' ? e($selectedSize) : 'default' ?>)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inventoryItems as $item):
                $itemId = (int) $item['id'];
                $hasVariantAmount = array_key_exists($itemId, $selectedRecipe) && $selectedSize !== '';
                $inputValue = $selectedSize === ''
                  ? ($selectedRecipe[$itemId] ?? '')
                  : ($hasVariantAmount ? $selectedRecipe[$itemId] : '');
              ?>
                <tr>
                  <td><strong><?= e($item['name']) ?></strong><div class="text-muted"><?= e($item['unit']) ?> per stock unit</div></td>
                  <td><?= number_format((float) $item['quantity_on_hand'], 3) ?> <?= e($item['unit']) ?></td>
                  <?php if ($selectedSize !== ''): ?>
                    <td class="recipe-size-hint">
                      <?= array_key_exists($itemId, $defaultRecipe) ? number_format($defaultRecipe[$itemId], 3) . ' ' . e($item['unit']) : '—' ?>
                    </td>
                  <?php endif; ?>
                  <td>
                    <input class="form-control recipe-qty" type="number" min="<?= $selectedSize !== '' ? '0' : '0.001' ?>" step="0.001"
                      name="quantities[<?= $itemId ?>]" value="<?= $inputValue === '' ? '' : number_format((float) $inputValue, 3, '.', '') ?>"
                      placeholder="<?= $selectedSize !== '' && array_key_exists($itemId, $defaultRecipe) ? 'Uses default' : 'Not used' ?>"
                      aria-label="<?= e($item['name']) ?> amount per serving">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:var(--space-4)">
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Recipe</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php layoutFooter(); ?>
