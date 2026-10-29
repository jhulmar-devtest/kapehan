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
    WHERE p.archived_at IS NULL AND c.archived_at IS NULL
    ORDER BY c.sort_order, c.name, p.name'
)->fetchAll(PDO::FETCH_ASSOC);
$inventoryItems = $db->query('SELECT id, name, unit, quantity_on_hand FROM inventory_items WHERE archived_at IS NULL ORDER BY name')
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
$configuredIngredientCount = count($selectedRecipe);

layoutHeader('Recipes');
?>
<style>
  .recipe-help { margin-bottom:18px; border-radius:var(--radius-md); line-height:1.6; }
  .recipe-hero { margin-bottom:18px; border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; background:linear-gradient(120deg,var(--surface-color),var(--surface-raised)); box-shadow:var(--shadow-xs); }
  .recipe-hero-top { display:flex; align-items:center; gap:14px; padding:18px 20px 14px; }
  .recipe-hero-icon { display:grid; place-items:center; flex:0 0 42px; width:42px; height:42px; border-radius:13px; background:var(--primary-subtle); color:var(--primary-color); font-size:17px; }
  .recipe-hero h2 { margin:0; font-size:15px; }
  .recipe-hero p { margin:4px 0 0; color:var(--text-muted); font-size:12px; }

  .recipe-selectors {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
    align-items: end;
    padding: 0 20px 19px;
  }
  .recipe-product-search { grid-column:1 / -1; max-width:520px; }
  .recipe-product-search .form-hint { display:block; margin-top:5px; }

  .recipe-editor-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; width:100%; }
  .recipe-editor-name { display:flex; align-items:center; gap:9px; min-width:0; }
  .recipe-editor-name i { color:var(--primary-color); }
  .recipe-count { display:inline-flex; align-items:center; gap:6px; flex:0 0 auto; padding:6px 10px; border-radius:999px; background:var(--primary-subtle); color:var(--primary-color); font-size:11px; font-weight:700; }
  .recipe-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
  .recipe-search { max-width:300px; position:relative; flex:1; }
  .recipe-search i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none; }
  .recipe-search input { padding-left:35px; }
  .recipe-table-wrap { border:1px solid var(--border-color); border-radius:var(--radius-md); }
  .recipe-table-wrap .data-table { margin:0; }
  .recipe-table-wrap .data-table th { white-space:nowrap; }
  .recipe-table-wrap .data-table tr[hidden] { display:none !important; }
  .recipe-stock-value { font-variant-numeric:tabular-nums; white-space:nowrap; }
  .recipe-unit { color:var(--text-muted); font-size:11px; }
  .recipe-qty { font-variant-numeric:tabular-nums; }
  .recipe-empty-filter { display:none; padding:20px; text-align:center; color:var(--text-muted); }
  .recipe-editor-note { display:flex; align-items:flex-start; gap:9px; margin-bottom:16px; padding:11px 13px; border-radius:var(--radius-sm); background:var(--surface-raised); color:var(--text-secondary); font-size:12px; line-height:1.5; }
  .recipe-editor-note i { margin-top:2px; color:var(--primary-color); }
  .recipe-save-row { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:16px; }

  .recipe-table-wrap {
    overflow-x: auto;
  }

  .recipe-qty {
    width: 150px;
    min-width: 120px;
  }

  .recipe-size-hint {
    color: var(--text-muted);
    font-size: .78rem;
    white-space: nowrap;
  }

  .recipe-empty {
    text-align: center;
    padding: 28px 16px;
    color: var(--text-muted);
  }

  @media (max-width: 640px) {
    .recipe-selectors {
      grid-template-columns: 1fr;
    }

    .recipe-qty {
      width: 126px;
    }
    .recipe-hero-top { padding:16px; }
    .recipe-selectors { padding:0 16px 16px; }
    .recipe-toolbar,.recipe-save-row { align-items:stretch; flex-direction:column; }
    .recipe-search { max-width:none; }
    .recipe-save-row .btn { width:100%; }
    .recipe-editor-head { flex-direction:column; }
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
    Products without a recipe remain orderable and do not use stock tracking. Recipe-linked products become unavailable when none of their active sizes can be made with available stock. Online pre-orders hold their ingredients until preparation or cancellation; restocking can make an unavailable product orderable again.
  </div>
</div>

<section class="recipe-hero" aria-labelledby="recipe-setup-heading">
  <div class="recipe-hero-top">
    <span class="recipe-hero-icon"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
    <div><h2 id="recipe-setup-heading">Choose what to configure</h2><p>Select a product, then choose its default recipe or a size-specific override.</p></div>
  </div>
  <form method="GET" class="recipe-selectors">
      <div class="form-group recipe-product-search" style="margin:0">
        <label class="form-label" for="recipe-product-search">Find a product</label>
        <input class="form-control" type="search" id="recipe-product-search" placeholder="Search by product or category" autocomplete="off" <?= !$products ? 'disabled' : '' ?>>
        <small class="form-hint" id="recipe-product-match-count" aria-live="polite"><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?> available</small>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="recipe-product">Product</label>
        <select class="form-control" name="product_id" id="recipe-product" onchange="this.form.submit()" <?= !$products ? 'disabled' : '' ?>>
          <?php foreach ($products as $product): ?>
            <option value="<?= (int) $product['id'] ?>" data-search="<?= e(mb_strtolower($product['name'] . ' ' . $product['category_name'])) ?>" <?= (int) $product['id'] === $selectedProductId ? 'selected' : '' ?>><?= e($product['name']) ?> · <?= e($product['category_name']) ?></option>
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
</section>

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
      <div class="recipe-editor-head">
        <div><div class="card-title recipe-editor-name"><i class="fa-solid fa-flask"></i> <?= e($selectedProduct['name']) ?> <span aria-hidden="true">·</span> <?= $selectedSize !== '' ? e($selectedSize) . ' recipe' : 'Default recipe' ?></div><div class="text-muted" style="font-size:12px;margin-top:5px"><?= e($selectedProduct['category_name']) ?><?= !empty($selectedProduct['has_sizes']) ? ' · size-aware product' : ' · one recipe for all servings' ?></div></div>
        <span class="recipe-count"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i> <?= $configuredIngredientCount ?> <?= $selectedSize !== '' ? 'override' : 'ingredient' ?><?= $configuredIngredientCount === 1 ? '' : 's' ?> configured</span>
      </div>
    </div>
    <div class="card-body">
      <?php if ($selectedSize !== ''): ?>
        <div class="recipe-editor-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Blank uses the default amount. Enter a quantity to override it, or <strong>0</strong> to exclude the ingredient for this size.</span></div>
      <?php else: ?>
        <div class="recipe-editor-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>This is the base recipe. For size-aware products, size overrides can replace individual ingredient quantities.</span></div>
      <?php endif; ?>

      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="save_recipe" value="1">
        <input type="hidden" name="product_id" value="<?= $selectedProductId ?>">
        <input type="hidden" name="size_label" value="<?= e($selectedSize) ?>">
        <div class="recipe-toolbar">
          <label class="recipe-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input id="recipe-item-search" type="search" class="form-control" placeholder="Find an ingredient" autocomplete="off"></label>
          <span class="text-muted" style="font-size:11px">Amounts use each item’s stock unit</span>
        </div>
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
                <tr data-recipe-item="<?= e(mb_strtolower($item['name'])) ?>">
                  <td><strong><?= e($item['name']) ?></strong>
                    <div class="recipe-unit">Stock unit: <?= e($item['unit']) ?></div>
                  </td>
                  <td class="recipe-stock-value"><?= number_format((float) $item['quantity_on_hand'], 3) ?> <span class="recipe-unit"><?= e($item['unit']) ?></span></td>
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
        <div class="recipe-empty-filter" id="recipe-no-match">No inventory items match that search.</div>
        <div class="recipe-save-row"><span class="text-muted" style="font-size:11px">Changes apply to future stock deductions.</span>
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Recipe</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<script>
  (() => {
    const productSearch = document.getElementById('recipe-product-search');
    const productSelect = document.getElementById('recipe-product');
    const productCount = document.getElementById('recipe-product-match-count');
    if (productSearch && productSelect && productCount) {
      const productOptions = Array.from(productSelect.options);
      productSearch.addEventListener('input', () => {
        const query = productSearch.value.trim().toLocaleLowerCase();
        let matches = 0;
        productOptions.forEach(option => {
          const match = !query || (option.dataset.search || option.textContent.toLocaleLowerCase()).includes(query);
          option.hidden = !match;
          if (match) matches++;
        });
        productCount.textContent = `${matches} product${matches === 1 ? '' : 's'} found`;
      });
    }

    const input = document.getElementById('recipe-item-search');
    const rows = Array.from(document.querySelectorAll('[data-recipe-item]'));
    const empty = document.getElementById('recipe-no-match');
    if (!input || !rows.length || !empty) return;
    input.addEventListener('input', () => {
      const query = input.value.trim().toLocaleLowerCase();
      let visible = 0;
      rows.forEach(row => {
        const match = row.dataset.recipeItem.includes(query);
        row.hidden = !match;
        if (match) visible++;
      });
      empty.style.display = visible ? 'none' : 'block';
    });
  })();
</script>

<?php layoutFooter(); ?>
