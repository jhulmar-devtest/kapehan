<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';
requireRole(ROLE_ADMIN);

$db = Database::getInstance();
$productId = (int) ($_GET['id'] ?? 0);
$defaultCategoryId = (int) ($_GET['category_id'] ?? 0);
$product = null;
if ($productId > 0) {
  $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
  $stmt->execute([$productId]);
  $product = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$product) {
    flash('global', 'That product could not be found.', 'error');
    redirect(APP_URL . '/admin/ordering.php');
  }
}

$old = getOldInput();
$old = is_array($old) ? $old : [];
$editing = $product !== null;
$field = static fn(string $key, $fallback = '') => array_key_exists($key, $old) ? $old[$key] : $fallback;
$categoryRows = $db->query('SELECT id, name, parent_id FROM categories ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$categoryChildren = [];
$categoryNames = [];
foreach ($categoryRows as $categoryRow) {
  $categoryNames[(int) $categoryRow['id']] = $categoryRow['name'];
  if ($categoryRow['parent_id'] !== null) $categoryChildren[(int) $categoryRow['parent_id']][] = $categoryRow;
}
$assignableCategories = array_values(array_filter($categoryRows, static fn($category) => empty($categoryChildren[(int) $category['id']])));
$categoryLabels = [];
foreach ($assignableCategories as $category) {
  $categoryLabels[(int) $category['id']] = $category['parent_id'] === null
    ? $category['name']
    : ($categoryNames[(int) $category['parent_id']] ?? '') . ' / ' . $category['name'];
}

$sizeOptions = $db->query('SELECT id, label FROM size_options WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
$inventoryItems = $db->query('SELECT id, name, unit FROM inventory_items ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$addonOptions = $db->query(
  "SELECT a.id, a.name, a.price,
          GROUP_CONCAT(CONCAT(i.name, ' · ', FORMAT(ai.qty_per_unit, 3), ' ', i.unit) ORDER BY i.name SEPARATOR ', ') AS stock_summary
     FROM addons a
     JOIN addon_ingredients ai ON ai.addon_id = a.id
     JOIN inventory_items i ON i.id = ai.inventory_item_id
    WHERE a.status = 'active'
    GROUP BY a.id, a.name, a.price
    ORDER BY a.name"
)->fetchAll(PDO::FETCH_ASSOC);

$recipeState = [];
if (!empty($old['recipe_submitted'])) {
  $oldItemIds = is_array($old['recipe_item_ids'] ?? null) ? $old['recipe_item_ids'] : [];
  $oldQuantities = is_array($old['recipe_quantities'] ?? null) ? $old['recipe_quantities'] : [];
  foreach (array_values($oldItemIds) as $index => $itemId) {
    $quantities = [];
    foreach ($oldQuantities as $sizeKey => $values) {
      if (is_array($values) && isset($values[$index]) && $values[$index] !== '') $quantities[$sizeKey] = $values[$index];
    }
    $recipeState[] = ['itemId' => (string) $itemId, 'quantities' => $quantities];
  }
} elseif ($productId > 0) {
  $stmt = $db->prepare('SELECT inventory_item_id, size_label, qty_per_unit FROM product_ingredients WHERE product_id = ? ORDER BY inventory_item_id, size_label');
  $stmt->execute([$productId]);
  $byItem = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $recipe) {
    $itemId = (string) $recipe['inventory_item_id'];
    $byItem[$itemId] ??= ['itemId' => $itemId, 'quantities' => []];
    if ($recipe['size_label'] === '') $key = 'default';
    else {
      $size = array_values(array_filter($sizeOptions, static fn($option) => $option['label'] === $recipe['size_label']));
      if (!$size) continue;
      $key = 'size_' . (int) $size[0]['id'];
    }
    $byItem[$itemId]['quantities'][$key] = (float) $recipe['qty_per_unit'];
  }
  $recipeState = array_values($byItem);
}

$selectedAddonIds = isset($old['setup_addon_ids']) && is_array($old['setup_addon_ids'])
  ? array_map('intval', $old['setup_addon_ids'])
  : [];
if (empty($old['setup_addons_submitted']) && $productId > 0) {
  $stmt = $db->prepare('SELECT addon_id FROM product_addons WHERE product_id = ?');
  $stmt->execute([$productId]);
  $selectedAddonIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$hasSizes = (bool) $field('has_sizes', $product['has_sizes'] ?? 0);
$hasSugar = (bool) $field('has_sugar', $product['has_sugar'] ?? 0);
$isPinned = (bool) $field('spotlight_pinned', $product['spotlight_pinned'] ?? 0);
$imageBase = APP_URL . '/../uploads/products/';
$postTarget = APP_URL . '/admin/ordering.php';
layoutHeader('Ordering');
?>
<style>
  .setup-page {
    max-width: 1120px;
    margin: 0 auto;
    padding-bottom: 100px;
  }

  .setup-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 20px;
  }

  .setup-heading h1 {
    margin: 0 0 5px;
    font-size: 1.55rem;
  }

  .setup-heading p {
    max-width: 680px;
    margin: 0;
    color: var(--text-muted);
    line-height: 1.5;
  }

  .setup-steps {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 18px 0 22px;
  }

  .setup-steps a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 13px;
    border: 1px solid var(--border-color);
    border-radius: 999px;
    color: var(--text-color);
    text-decoration: none;
    background: var(--surface-color);
    font-size: .82rem;
    font-weight: 650;
  }

  .setup-steps a:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
  }

  .setup-steps span {
    display: grid;
    place-items: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--primary-subtle);
    color: var(--primary-color);
    font-size: .72rem;
  }

  .setup-card {
    margin-bottom: 16px;
    scroll-margin-top: 24px;
  }

  .setup-card .card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
  }

  .setup-card .card-title {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .setup-card .card-title i {
    color: var(--primary-color);
  }

  .optional-tag {
    padding: 3px 8px;
    border-radius: 999px;
    background: var(--surface-raised);
    color: var(--text-muted);
    font-size: .68rem;
    font-weight: 650;
  }

  .setup-card-intro {
    margin: 5px 0 0;
    color: var(--text-muted);
    font-size: .82rem;
    line-height: 1.5;
  }

  .setup-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 18px;
  }

  .setup-grid .span-two {
    grid-column: 1/-1;
  }

  .setup-choice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 8px 0 14px;
    cursor: pointer;
  }

  .setup-choice input {
    margin-top: 3px;
    accent-color: var(--primary-color);
  }

  .setup-choice span {
    display: grid;
    gap: 3px;
  }

  .setup-choice small,
  .setup-help {
    color: var(--text-muted);
    font-size: .8rem;
    line-height: 1.5;
  }

  .setup-help {
    margin: 0 0 14px;
  }

  .setup-inline-link {
    color: var(--primary-color);
    font-size: .82rem;
    font-weight: 650;
  }

  .setup-recipe-table-wrap {
    overflow-x: auto;
    margin: 0 -4px 12px;
  }

  .setup-recipe-table {
    width: 100%;
    min-width: 620px;
    border-collapse: collapse;
    font-size: .82rem;
  }

  .setup-recipe-table th,
  .setup-recipe-table td {
    padding: 8px 6px;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
  }

  .setup-recipe-table th {
    color: var(--text-muted);
    font-weight: 650;
    white-space: nowrap;
  }

  .setup-recipe-table .form-control {
    min-width: 86px;
  }

  .setup-recipe-table .recipe-item-select {
    min-width: 190px;
  }

  .recipe-empty {
    padding: 18px;
    border: 1px dashed var(--border-color);
    border-radius: 12px;
    color: var(--text-muted);
    text-align: center;
  }

  .quick-stock-create {
    margin-top: 14px;
    border: 1px dashed var(--border-color);
    border-radius: 12px;
  }

  .quick-stock-create summary {
    padding: 12px 14px;
    color: var(--primary-color);
    font-weight: 700;
    cursor: pointer;
  }

  .quick-stock-body {
    padding: 14px;
    border-top: 1px solid var(--border-color);
  }

  .quick-stock-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 14px;
  }

  .quick-stock-grid .form-group {
    margin-bottom: 12px;
  }

  .quick-addon-fields {
    display: none;
    grid-column: 1/-1;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 14px;
  }

  .quick-stock-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-top: 4px;
  }

  .addon-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .addon-choice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    cursor: pointer;
  }

  .addon-choice:has(input:checked) {
    border-color: var(--primary-color);
    background: var(--primary-subtle);
  }

  .addon-choice input {
    margin-top: 4px;
    accent-color: var(--primary-color);
  }

  .addon-choice span {
    display: grid;
    gap: 4px;
  }

  .addon-choice small {
    color: var(--text-muted);
    line-height: 1.45;
  }

  .image-preview {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 10px;
    color: var(--text-muted);
    font-size: .8rem;
  }

  .image-preview img {
    width: 72px;
    height: 72px;
    border-radius: 12px;
    object-fit: cover;
    border: 1px solid var(--border-color);
  }

  .setup-savebar {
    position: sticky;
    bottom: 0;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin: 18px -12px 0;
    padding: 14px 18px;
    border: 1px solid var(--border-color);
    border-radius: 14px 14px 0 0;
    background: color-mix(in srgb, var(--surface-color) 94%, transparent);
    box-shadow: 0 -8px 22px rgba(30, 20, 12, .08);
    backdrop-filter: blur(10px);
  }

  .setup-savebar-note {
    color: var(--text-muted);
    font-size: .8rem;
  }

  .setup-savebar-actions {
    display: flex;
    gap: 9px;
  }

  @media(max-width:700px) {

    .setup-grid,
    .addon-grid {
      grid-template-columns: 1fr;
    }

    .quick-stock-grid,
    .quick-addon-fields {
      grid-template-columns: 1fr;
    }

    .setup-heading {
      display: block;
    }

    .setup-heading .btn {
      margin-top: 14px;
    }
  }

  @media(max-width:520px) {
    .setup-steps {
      display: grid;
      grid-template-columns: 1fr;
    }

    .setup-savebar {
      margin: 14px -8px 0;
      padding: 12px;
      align-items: stretch;
      flex-direction: column;
    }

    .setup-savebar-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
    }

    .setup-savebar-actions .btn {
      justify-content: center;
    }

    .quick-stock-actions {
      align-items: stretch;
      flex-direction: column;
    }
  }
</style>

<div class="setup-page">
  <div class="setup-heading">
    <div>
      <a href="<?= e(APP_URL) ?>/admin/ordering.php" class="text-muted" style="display:inline-block;margin-bottom:10px;text-decoration:none"><i class="fa-solid fa-arrow-left"></i> Back to products</a>
      <h1><?= $editing ? 'Edit product' : 'Add a product' ?></h1>
      <p>Set the product details, connect the stock it uses, and choose which add-ons customers can request.</p>
    </div>
    <?php if ($editing): ?>
      <form method="POST" action="<?= e(APP_URL) ?>/admin/ordering.php" onsubmit="return confirm('Delete this product? This cannot be undone.');">
        <?= csrfField() ?><input type="hidden" name="_method" value="DELETE"><input type="hidden" name="product_id" value="<?= $productId ?>">
        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete product</button>
      </form>
    <?php endif; ?>
  </div>
  <?php showFlash('global'); ?>

  <nav class="setup-steps" aria-label="Product setup sections">
    <a href="#product-details"><span>1</span> Product details</a>
    <a href="#product-recipe"><span>2</span> Stock recipe</a>
    <a href="#product-addons"><span>3</span> Add-ons</a>
  </nav>

  <form method="POST" action="<?= e($postTarget) ?>" id="product-setup-form" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="save_product" value="1">
    <input type="hidden" name="integrated_setup" value="1">
    <input type="hidden" name="return_to_setup_page" value="1">
    <input type="hidden" name="recipe_submitted" value="1">
    <input type="hidden" name="setup_addons_submitted" value="1">
    <input type="hidden" name="product_id" value="<?= $productId ?>">

    <section class="card setup-card" id="product-details">
      <div class="card-header">
        <div>
          <div class="card-title"><i class="fa-solid fa-mug-hot"></i> Product details</div>
          <p class="setup-card-intro">Start with the name, menu category, price, and customer choices.</p>
        </div>
      </div>
      <div class="card-body">
        <?php if (!$assignableCategories): ?>
          <div class="alert alert-warning"><i class="fa-solid fa-circle-info"></i>
            <div>Create a menu category before adding products. <a class="setup-inline-link" href="<?= e(APP_URL) ?>/admin/ordering.php">Go to Ordering to add a category</a>.</div>
          </div>
        <?php endif; ?>
        <div class="setup-grid">
          <div class="form-group"><label class="form-label" for="f-name">Product name <span class="required">*</span></label><input class="form-control" id="f-name" name="name" required maxlength="120" value="<?= e((string) $field('name', $product['name'] ?? '')) ?>" placeholder="e.g. Café Latte"></div>
          <div class="form-group"><label class="form-label" for="f-category">Menu category <span class="required">*</span></label><select class="form-control" id="f-category" name="category_id" required <?= !$assignableCategories ? 'disabled' : '' ?>>
              <option value="">Choose a category</option><?php foreach ($assignableCategories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (string) $field('category_id', $product['category_id'] ?? $defaultCategoryId) === (string) $category['id'] ? 'selected' : '' ?>><?= e($categoryLabels[(int) $category['id']]) ?></option><?php endforeach; ?>
            </select></div>
          <div class="form-group"><label class="form-label" for="f-price">Base price (₱) <span class="required">*</span></label><input class="form-control" id="f-price" name="price" type="number" min="0.5" step="0.50" required value="<?= e((string) $field('price', $product['price'] ?? '')) ?>" placeholder="0.00"><small class="text-muted">Drink size price adjustments are managed separately under Drink Sizes.</small></div>
          <div class="form-group"><label class="form-label" for="f-image">Product photo (optional)</label><input class="form-control" id="f-image" name="product_image" type="file" accept="image/jpeg,image/png,image/webp"><small class="text-muted">JPG, PNG, or WEBP up to 2 MB. Leave blank to keep the current photo.</small>
            <?php if (!empty($product['image_path'])): ?><div class="image-preview"><img id="current-image" src="<?= e($imageBase . $product['image_path']) ?>" alt="Current product photo"><span>Current photo</span></div><?php endif; ?><div class="image-preview" id="new-image-wrap" hidden><img id="new-image-preview" alt="New photo preview"><span>New photo preview</span></div>
          </div>
          <div class="form-group span-two"><label class="form-label" for="f-description">Description (optional)</label><textarea class="form-control" id="f-description" name="description" rows="3" maxlength="500" placeholder="A short description for the menu"><?= e((string) $field('description', $product['description'] ?? '')) ?></textarea></div>
        </div>
        <div class="setup-grid">
          <div>
            <label class="setup-choice"><input type="checkbox" name="has_sizes" id="f-has-sizes" value="1" <?= $hasSizes ? 'checked' : '' ?>><span><strong>Offer drink sizes</strong><small>Customers can choose among your active drink sizes. Stock units such as ml, g, or pcs are configured in the recipe.</small></span></label>
            <label class="setup-choice"><input type="checkbox" name="has_sugar" value="1" <?= $hasSugar ? 'checked' : '' ?>><span><strong>Allow sugar-level choices</strong><small>Customers can choose full, less, half, or no sugar.</small></span></label>
          </div>
          <div class="setup-size-note"><strong>Active drink sizes:</strong> <?= $sizeOptions ? e(implode(', ', array_column($sizeOptions, 'label'))) : 'none configured' ?><br><a class="setup-inline-link" href="<?= e(APP_URL) ?>/admin/ordering.php?tab=sizes">Manage drink sizes and price adjustments</a></div>
        </div>
      </div>
    </section>

    <section class="card setup-card" id="product-recipe">
      <div class="card-header">
        <div>
          <div class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Stock recipe <span class="optional-tag">Optional</span></div>
          <p class="setup-card-intro">Link ingredients to deduct stock when this product is sold. Leave this section empty if stock is not tracked yet.</p>
        </div>
      </div>
      <div class="card-body">
        <p class="setup-help">Enter the stock amount used for one serving. For sized drinks, enter the base amount and add an override only when a size uses a different amount. A value of 0 means that size does not use the ingredient.</p>
        <div id="recipe-grid"></div>
        <button type="button" class="btn btn-outline btn-sm" onclick="addRecipeRow()"><i class="fa-solid fa-plus"></i> Add ingredient</button>
        <details class="quick-stock-create">
          <summary><i class="fa-solid fa-circle-plus"></i> Need a new stock item? Add it here</summary>
          <div class="quick-stock-body">
            <div class="quick-stock-grid">
              <div class="form-group"><label class="form-label" for="quick-stock-name">Stock item name</label><input class="form-control" id="quick-stock-name" maxlength="120" placeholder="e.g. Fresh milk"></div>
              <div class="form-group"><label class="form-label" for="quick-stock-unit">Stock unit</label><input class="form-control" id="quick-stock-unit" maxlength="20" placeholder="L, ml, kg, g, pcs"></div>
              <div class="form-group"><label class="form-label" for="quick-stock-quantity">Starting quantity</label><input class="form-control" id="quick-stock-quantity" type="number" min="0" step="0.001" value="0"></div>
              <div class="form-group"><label class="form-label" for="quick-stock-reorder">Low-stock alert level</label><input class="form-control" id="quick-stock-reorder" type="number" min="0" step="0.001" value="0"></div>
              <div class="form-group"><label class="form-label" for="quick-stock-cost">Cost per stock unit (₱)</label><input class="form-control" id="quick-stock-cost" type="number" min="0" step="0.01" value="0"></div>
              <label class="setup-choice"><input id="quick-stock-is-addon" type="checkbox" onchange="toggleQuickAddonFields()"><span><strong>Also make this a customer add-on</strong><small>The add-on will use the same inventory stock.</small></span></label>
              <div class="quick-addon-fields" id="quick-addon-fields">
                <div class="form-group"><label class="form-label" for="quick-addon-name">Add-on name</label><input class="form-control" id="quick-addon-name" maxlength="120" placeholder="e.g. Extra nata"></div>
                <div class="form-group"><label class="form-label" for="quick-addon-price">Customer price (₱)</label><input class="form-control" id="quick-addon-price" type="number" min="0" step="0.50" value="0"></div>
                <div class="form-group"><label class="form-label" for="quick-addon-quantity">Stock used per add-on (<span id="quick-addon-unit">unit</span>)</label><input class="form-control" id="quick-addon-quantity" type="number" min="0.001" step="0.001" value="0.010"></div>
              </div>
            </div>
            <div class="quick-stock-actions"><small class="text-muted">A new stock item is saved immediately so it can be shared by other products. You can set its recipe amount after saving.</small><button type="button" class="btn btn-primary btn-sm" id="quick-stock-save" onclick="saveQuickStockItem()">Save stock item</button></div>
          </div>
        </details>
      </div>
    </section>

    <section class="card setup-card" id="product-addons">
      <div class="card-header">
        <div>
          <div class="card-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Customer add-ons <span class="optional-tag">Optional</span></div>
          <p class="setup-card-intro">Choose only the add-ons that make sense for this product. Prices and stock portions are managed once in Inventory.</p>
        </div><a class="setup-inline-link" href="<?= e(APP_URL) ?>/admin/inventory.php">Manage inventory</a>
      </div>
      <div class="card-body">
        <div id="addon-choices"></div>
      </div>
    </section>

    <section class="card setup-card" id="product-display">
      <div class="card-header">
        <div>
          <div class="card-title"><i class="fa-solid fa-star"></i> Homepage spotlight <span class="optional-tag">Optional</span></div>
          <p class="setup-card-intro">Pin this product to the homepage “Today’s Picks” section.</p>
        </div>
      </div>
      <div class="card-body">
        <label class="setup-choice"><input type="checkbox" name="spotlight_pinned" id="spotlight-pinned" value="1" <?= $isPinned ? 'checked' : '' ?> onchange="document.getElementById('spotlight-until-wrap').hidden=!this.checked"><span><strong>Pin to Spotlight</strong><small>Leave the date blank to keep it pinned until you remove it.</small></span></label>
        <div class="form-group" id="spotlight-until-wrap" <?= $isPinned ? '' : 'hidden' ?>><label class="form-label" for="spotlight-until">Automatically unpin on (optional)</label><input class="form-control" id="spotlight-until" type="date" name="spotlight_pinned_until" value="<?= e(substr((string) $field('spotlight_pinned_until', $product['spotlight_pinned_until'] ?? ''), 0, 10)) ?>"></div>
      </div>
    </section>

    <div class="setup-savebar"><span class="setup-savebar-note">Your product, recipe, and add-on choices save together.</span>
      <div class="setup-savebar-actions"><a class="btn btn-ghost" href="<?= e(APP_URL) ?>/admin/ordering.php">Cancel</a><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?= $editing ? 'Save product' : 'Create product' ?></button></div>
    </div>
  </form>
</div>

<script>
  const INVENTORY = <?= json_encode($inventoryItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const ADDONS = <?= json_encode($addonOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const SIZES = <?= json_encode($sizeOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const RECIPE_START = <?= json_encode($recipeState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const ADDON_START = <?= json_encode(array_values($selectedAddonIds), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const HAS_SIZES = <?= $hasSizes ? 'true' : 'false' ?>;
  let recipeRows = RECIPE_START;
  let addonSelection = ADDON_START;

  function htmlEscape(value) {
    const node = document.createElement('span');
    node.textContent = value ?? '';
    return node.innerHTML;
  }

  function collectRecipeRows() {
    return [...document.querySelectorAll('#recipe-grid tbody tr')].map(row => {
      const quantities = {};
      row.querySelectorAll('[data-size-key]').forEach(input => {
        if (input.value !== '') quantities[input.dataset.sizeKey] = input.value;
      });
      return {
        itemId: row.querySelector('.recipe-item').value,
        quantities
      };
    });
  }

  function recipeRowHtml(row) {
    let html = '<tr><td><select class="form-control recipe-item" name="recipe_item_ids[]" required><option value="">Choose stock item</option>';
    INVENTORY.forEach(item => {
      html += `<option value="${Number(item.id)}" ${String(row.itemId)===String(item.id)?'selected':''}>${htmlEscape(item.name)} (${htmlEscape(item.unit)})</option>`;
    });
    html += '</select></td>';
    html += `<td><input class="form-control" type="number" min="0.001" step="0.001" name="recipe_quantities[default][]" data-size-key="default" value="${htmlEscape(row.quantities?.default??'')}" placeholder="e.g. 0.180"></td>`;
    if (document.getElementById('f-has-sizes').checked) SIZES.forEach(size => {
      const key = `size_${Number(size.id)}`;
      html += `<td><input class="form-control" type="number" min="0" step="0.001" name="recipe_quantities[${key}][]" data-size-key="${key}" value="${htmlEscape(row.quantities?.[key]??'')}" placeholder="Use default"></td>`;
    });
    return html + `<td><button type="button" class="btn btn-ghost btn-sm" aria-label="Remove ingredient" onclick="removeRecipeRow(this)"><i class="fa-solid fa-trash"></i></button></td></tr>`;
  }

  function renderRecipeRows() {
    const target = document.getElementById('recipe-grid');
    if (!INVENTORY.length) {
      target.innerHTML = '<div class="recipe-empty">No stock items yet. Add one below or create it from Inventory.</div>';
      return;
    }
    let html = '<div class="setup-recipe-table-wrap"><table class="setup-recipe-table"><thead><tr><th>Stock item</th><th>Amount per serving</th>';
    if (document.getElementById('f-has-sizes').checked) SIZES.forEach(size => {
      html += `<th>${htmlEscape(size.label)} override</th>`;
    });
    html += '<th></th></tr></thead><tbody>' + recipeRows.map(recipeRowHtml).join('') + '</tbody></table></div>';
    target.innerHTML = html;
  }

  function addRecipeRow() {
    recipeRows = collectRecipeRows();
    recipeRows.push({
      itemId: '',
      quantities: {}
    });
    renderRecipeRows();
  }

  function removeRecipeRow(button) {
    button.closest('tr').remove();
    recipeRows = collectRecipeRows();
  }

  function renderAddons() {
    const target = document.getElementById('addon-choices');
    if (!ADDONS.length) {
      target.innerHTML = '<div class="recipe-empty">No stocked add-ons are available yet. Create one from Inventory or in the stock recipe section above.</div>';
      return;
    }
    const selected = new Set(addonSelection.map(Number));
    target.innerHTML = '<div class="addon-grid">' + ADDONS.map(addon => `<label class="addon-choice"><input type="checkbox" name="setup_addon_ids[]" value="${Number(addon.id)}" ${selected.has(Number(addon.id))?'checked':''}><span><strong>${htmlEscape(addon.name)} · ₱${Number(addon.price).toFixed(2)}</strong><small>Stock use: ${htmlEscape(addon.stock_summary||'linked inventory item')}</small></span></label>`).join('') + '</div>';
  }
  document.getElementById('f-has-sizes').addEventListener('change', () => {
    recipeRows = collectRecipeRows();
    renderRecipeRows();
  });

  function toggleQuickAddonFields() {
    document.getElementById('quick-addon-fields').style.display = document.getElementById('quick-stock-is-addon').checked ? 'grid' : 'none';
  }
  document.getElementById('quick-stock-unit').addEventListener('input', function() {
    document.getElementById('quick-addon-unit').textContent = this.value.trim() || 'unit';
  });

  function saveQuickStockItem() {
    const name = document.getElementById('quick-stock-name').value.trim(),
      unit = document.getElementById('quick-stock-unit').value.trim();
    const isAddon = document.getElementById('quick-stock-is-addon').checked,
      addonName = document.getElementById('quick-addon-name').value.trim();
    if (!name || !unit) {
      showToast('Enter a stock item name and unit.', 'warning');
      return;
    }
    if (INVENTORY.some(item => item.name.trim().toLowerCase() === name.toLowerCase() && item.unit.trim().toLowerCase() === unit.toLowerCase())) {
      showToast('That stock item already exists. Select it in the recipe and restock it from Inventory.', 'warning');
      return;
    }
    if (isAddon && (!addonName || Number(document.getElementById('quick-addon-quantity').value) <= 0)) {
      showToast('Enter the add-on name and stock portion.', 'warning');
      return;
    }
    const button = document.getElementById('quick-stock-save');
    button.disabled = true;
    button.textContent = 'Saving…';
    const data = new FormData();
    data.append('name', name);
    data.append('unit', unit);
    data.append('quantity_on_hand', document.getElementById('quick-stock-quantity').value || '0');
    data.append('reorder_level', document.getElementById('quick-stock-reorder').value || '0');
    data.append('cost_per_unit', document.getElementById('quick-stock-cost').value || '0');
    data.append('is_addon', isAddon ? '1' : '0');
    data.append('addon_name', addonName);
    data.append('addon_price', document.getElementById('quick-addon-price').value || '0');
    data.append('addon_quantity_per_unit', document.getElementById('quick-addon-quantity').value || '0');
    data.append('csrf_token', '<?= csrfToken() ?>');
    fetch('<?= e(APP_URL) ?>/api/save_inventory_item.php', {
      method: 'POST',
      body: data
    }).then(response => response.json()).then(result => {
      if (!result.success) throw new Error(result.error || 'Could not save the stock item.');
      INVENTORY.push({
        id: Number(result.item.id),
        name: result.item.name,
        unit: result.item.unit
      });
      INVENTORY.sort((a, b) => a.name.localeCompare(b.name));
      recipeRows = collectRecipeRows();
      recipeRows.push({
        itemId: String(result.item.id),
        quantities: {}
      });
      renderRecipeRows();
      if (result.addon) {
        ADDONS.push(result.addon);
        ADDONS.sort((a, b) => a.name.localeCompare(b.name));
        addonSelection = [...document.querySelectorAll('#addon-choices input:checked')].map(input => Number(input.value));
        addonSelection.push(Number(result.addon.id));
        renderAddons();
      }
      ['quick-stock-name', 'quick-stock-unit'].forEach(id => document.getElementById(id).value = '');
      ['quick-stock-quantity', 'quick-stock-reorder', 'quick-stock-cost', 'quick-addon-price'].forEach(id => document.getElementById(id).value = '0');
      document.getElementById('quick-stock-is-addon').checked = false;
      document.getElementById('quick-addon-name').value = '';
      document.getElementById('quick-addon-quantity').value = '0.010';
      toggleQuickAddonFields();
      showToast(`${name} added. Enter its amount per serving in the recipe.`, 'success');
    }).catch(error => showToast(error.message || 'Could not save the stock item.', 'error')).finally(() => {
      button.disabled = false;
      button.textContent = 'Save stock item';
    });
  }
  document.getElementById('f-image').addEventListener('change', function() {
    const file = this.files?.[0],
      wrap = document.getElementById('new-image-wrap');
    if (!file) {
      wrap.hidden = true;
      return;
    }
    document.getElementById('new-image-preview').src = URL.createObjectURL(file);
    wrap.hidden = false;
  });
  renderRecipeRows();
  renderAddons();
</script>
<?php layoutFooter(); ?>