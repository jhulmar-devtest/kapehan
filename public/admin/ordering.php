<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';
requireRole(ROLE_ADMIN);

$db = Database::getInstance();

/** Save recipe amounts and add-on assignments submitted with the product editor. */
function adminSaveProductSetup(PDO $db, int $productId, bool $hasSizes): void
{
  $rawItemIds = $_POST['recipe_item_ids'] ?? [];
  $rawQuantities = $_POST['recipe_quantities'] ?? [];
  $rawAddonIds = $_POST['setup_addon_ids'] ?? [];
  if (!is_array($rawItemIds) || !is_array($rawQuantities) || !is_array($rawAddonIds)) {
    throw new RuntimeException('The stock or add-on setup was not submitted correctly. Please try again.');
  }

  $sizeOptions = $hasSizes
    ? $db->query("SELECT id, label FROM size_options WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC)
    : [];
  $validSizes = array_column($sizeOptions, 'label');
  $validItemIds = array_fill_keys(array_map('intval', $db->query('SELECT id FROM inventory_items')->fetchAll(PDO::FETCH_COLUMN)), true);
  $recipeRows = [];
  $seenItems = [];
  $quantitiesBySize = is_array($rawQuantities) ? $rawQuantities : [];

  foreach (array_values($rawItemIds) as $index => $rawItemId) {
    $itemId = filter_var($rawItemId, FILTER_VALIDATE_INT);
    if (!$itemId || !isset($validItemIds[$itemId])) {
      if (trim((string) $rawItemId) === '') continue;
      throw new RuntimeException('Choose a valid stock item for every recipe row.');
    }
    if (isset($seenItems[$itemId])) throw new RuntimeException('Each stock item can only appear once in a recipe.');
    $seenItems[$itemId] = true;

    $defaultRaw = $quantitiesBySize['default'][$index] ?? '';
    $defaultRaw = is_scalar($defaultRaw) ? trim((string) $defaultRaw) : '';
    $rowValues = [];
    if ($defaultRaw !== '') {
      if (!is_numeric($defaultRaw) || (float) $defaultRaw <= 0 || (float) $defaultRaw > 999999999) {
        throw new RuntimeException('Default recipe amounts must be greater than zero.');
      }
      $rowValues[''] = round((float) $defaultRaw, 3);
    }

    foreach ($sizeOptions as $sizeOption) {
      $label = (string) $sizeOption['label'];
      $key = 'size_' . (int) $sizeOption['id'];
      $raw = $quantitiesBySize[$key][$index] ?? '';
      $raw = is_scalar($raw) ? trim((string) $raw) : '';
      if ($raw === '') continue; // blank means use the default recipe
      if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 999999999) {
        throw new RuntimeException('Size-specific recipe amounts must be zero or greater.');
      }
      $rowValues[(string) $label] = round((float) $raw, 3);
    }

    foreach ($rowValues as $sizeLabel => $amount) {
      $recipeRows[] = [$itemId, $sizeLabel, $amount];
    }
  }

  $addonIds = array_values(array_unique(array_filter(array_map('intval', $rawAddonIds), static fn($id) => $id > 0)));
  if ($addonIds) {
    $marks = implode(',', array_fill(0, count($addonIds), '?'));
    $validAddons = $db->prepare(
      "SELECT DISTINCT a.id FROM addons a JOIN addon_ingredients ai ON ai.addon_id = a.id
        WHERE a.status = 'active' AND a.id IN ($marks)"
    );
    $validAddons->execute($addonIds);
    $validAddonIds = array_map('intval', $validAddons->fetchAll(PDO::FETCH_COLUMN));
    if (count($validAddonIds) !== count($addonIds)) {
      throw new RuntimeException('One of the selected add-ons is no longer available. Refresh and try again.');
    }
  }

  // Replace the default recipe and currently active size overrides. Keep
  // inactive-size recipes so they are still available if that size is restored.
  $db->prepare("DELETE FROM product_ingredients WHERE product_id = ? AND size_label = ''")
    ->execute([$productId]);
  if ($validSizes) {
    $marks = implode(',', array_fill(0, count($validSizes), '?'));
    $db->prepare("DELETE FROM product_ingredients WHERE product_id = ? AND size_label IN ($marks)")
      ->execute(array_merge([$productId], array_values($validSizes)));
  }
  $insertRecipe = $db->prepare(
    'INSERT INTO product_ingredients (product_id, inventory_item_id, size_label, qty_per_unit) VALUES (?, ?, ?, ?)'
  );
  foreach ($recipeRows as [$itemId, $sizeLabel, $amount]) {
    $insertRecipe->execute([$productId, $itemId, $sizeLabel, $amount]);
  }

  $db->prepare('DELETE FROM product_addons WHERE product_id = ?')->execute([$productId]);
  if ($addonIds) {
    $insertAddon = $db->prepare('INSERT INTO product_addons (product_id, addon_id) VALUES (?, ?)');
    foreach ($addonIds as $addonId) $insertAddon->execute([$productId, $addonId]);
  }
}

// Which sub-tab to show on load. Size saves below redirect back with
// ?tab=sizes specifically so admins adding several sizes in a row don't
// get bounced back to the Products tab after every single save.
$activeSubtab = ($_GET['tab'] ?? '') === 'sizes' ? 'sizes' : 'products';

// ---- Handle Size & Pricing CRUD (item 13, new) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['size_action'])) {
  verifyCsrf();
  $action = $_POST['size_action'];

  if ($action === 'delete') {
    $id = (int) ($_POST['size_id'] ?? 0);
    $db->prepare("DELETE FROM size_options WHERE id = ?")->execute([$id]);
    auditLog(ROLE_ADMIN, currentUserId(), 'delete_size', 'size_options', $id);
    flash('global', 'Size option deleted.', 'success');
  } else {
    $label   = sanitizeString($_POST['label'] ?? '', 30);
    $adj     = round((float) ($_POST['price_adjustment'] ?? 0), 2);
    $sort    = (int) ($_POST['sort_order'] ?? 0);
    $active  = isset($_POST['is_active']) ? 1 : 0;

    if (empty($label)) {
      flash('global', 'Size label is required.', 'error');
    } elseif ($action === 'update') {
      $id = (int) ($_POST['size_id'] ?? 0);
      $db->prepare(
        "UPDATE size_options SET label=?, price_adjustment=?, sort_order=?, is_active=? WHERE id=?"
      )->execute([$label, $adj, $sort, $active, $id]);
      auditLog(ROLE_ADMIN, currentUserId(), 'update_size', 'size_options', $id);
      flash('global', 'Size option updated.', 'success');
    } else {
      $db->prepare(
        "INSERT INTO size_options (label, price_adjustment, sort_order, is_active) VALUES (?, ?, ?, ?)"
      )->execute([$label, $adj, $sort, $active]);
      $newId = (int) $db->lastInsertId();
      auditLog(ROLE_ADMIN, currentUserId(), 'create_size', 'size_options', $newId);
      flash('global', 'Size option added.', 'success');
    }
  }
  redirect(APP_URL . '/admin/ordering.php?tab=sizes');
}

// ---- Handle DELETE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
  verifyCsrf();
  $id = (int)$_POST['product_id'];

  // BUG FIX: this used to unconditionally run
  // `DELETE od FROM order_details od WHERE od.product_id = ?` before
  // deleting the product — silently destroying the line items of every
  // PAST order that ever included it, corrupting historical receipts,
  // sales reports, and audit records. Deleting a menu item is a routine
  // admin action (e.g. removing a seasonal drink); it should never be
  // able to reach back and rewrite completed transactions. If a product
  // has real order history, refuse the hard delete and point to the
  // existing "deactivate" toggle instead, which removes it from the
  // menu without touching anything historical.
  $stmt = $db->prepare("SELECT COUNT(*) FROM order_details WHERE product_id = ?");
  $stmt->execute([$id]);
  if ((int) $stmt->fetchColumn() > 0) {
    flash('global', 'This product has order history and cannot be deleted, to keep past receipts and reports intact. Use "Deactivate" instead to remove it from the menu.', 'error');
    redirect(APP_URL . '/admin/ordering.php');
  }

  // Delete the old image file if it exists
  $stmt = $db->prepare("SELECT image_path FROM products WHERE id=?");
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  if ($row && $row['image_path'] && file_exists(UPLOAD_DIR . $row['image_path'])) {
    unlink(UPLOAD_DIR . $row['image_path']);
  }
  $db->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
  auditLog(ROLE_ADMIN, currentUserId(), 'delete_product', 'products', $id);
  flash('global', 'Product deleted.', 'success');
  redirect(APP_URL . '/admin/ordering.php');
}

// ---- Handle TOGGLE availability ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
  verifyCsrf();
  $id = (int)$_POST['toggle_id'];
  $db->prepare("UPDATE products SET is_available = NOT is_available WHERE id=?")->execute([$id]);
  flash('global', 'Product availability updated.', 'success');
  redirect(APP_URL . '/admin/ordering.php');
}

// ---- Handle ADD / EDIT ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
  verifyCsrf();

  $pid      = (int)($_POST['product_id'] ?? 0);
  $productSetupUrl = APP_URL . '/admin/product-setup.php' . ($pid > 0 ? '?id=' . $pid : '');
  $name     = sanitizeString($_POST['name'] ?? '');
  $catId    = (int)($_POST['category_id'] ?? 0);
  $price    = round((float)($_POST['price'] ?? 0), 2);
  $desc     = sanitizeString($_POST['description'] ?? '', 500);
  $hasSizes  = isset($_POST['has_sizes'])  ? 1 : 0;
  $hasSugar  = isset($_POST['has_sugar'])  ? 1 : 0;

  $spotlightPinned = isset($_POST['spotlight_pinned']) ? 1 : 0;
  $spotlightUntilRaw = trim($_POST['spotlight_pinned_until'] ?? '');
  // Empty = pinned indefinitely (until unpinned by hand). Otherwise expect
  // a <input type="date"> value and store it as end-of-day. Unpinned
  // products never carry a stale expiry value.
  $spotlightUntil = ($spotlightPinned && $spotlightUntilRaw !== '')
    ? $spotlightUntilRaw . ' 23:59:59'
    : null;

  if (empty($name) || $catId < 1 || $price <= 0) {
    flash('global', 'Please fill in all required fields.', 'error');
    flashOldInput($_POST);
    redirect($productSetupUrl);
  }

  // ---- Handle image upload ----
  $newImageName = null; // null means "no change"
  $oldImageToDelete = null;

  if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
    $file     = $_FILES['product_image'];
    $maxSize  = UPLOAD_MAX_SIZE; // 2MB defined in constants.php
    $allowed  = ['image/jpeg', 'image/png', 'image/webp'];

    // Validate file size
    if ($file['size'] > $maxSize) {
      flash('global', 'Image is too large. Maximum size is 2MB.', 'error');
      flashOldInput($_POST);
      redirect($productSetupUrl);
    }

    // Validate MIME type using finfo (more reliable than just checking extension)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowed, true) || !getimagesize($file['tmp_name'])) {
      flash('global', 'Invalid image type. Only JPG, PNG, and WEBP are allowed.', 'error');
      flashOldInput($_POST);
      redirect($productSetupUrl);
    }

    // Generate a safe random filename — never use the original filename
    $ext          = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mimeType];
    $newImageName = bin2hex(random_bytes(16)) . '.' . $ext;

    // Make sure the uploads directory exists
    if (!is_dir(UPLOAD_DIR)) {
      mkdir(UPLOAD_DIR, 0755, true);
    }

    // Move the uploaded file from the temp folder to our uploads folder
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $newImageName)) {
      flash('global', 'Failed to save image. Please try again.', 'error');
      flashOldInput($_POST);
      redirect($productSetupUrl);
    }

    // If editing, delete the OLD image so we don't accumulate unused files
    if ($pid > 0) {
      $stmt = $db->prepare("SELECT image_path FROM products WHERE id=?");
      $stmt->execute([$pid]);
      $oldImageToDelete = $stmt->fetchColumn() ?: null;
    }
  }

  try {
    $db->beginTransaction();
    if ($pid > 0) {
      // Edit existing product
      if ($newImageName) {
        $db->prepare("UPDATE products SET name=?,category_id=?,price=?,description=?,image_path=?,has_sizes=?,has_sugar=?,spotlight_pinned=?,spotlight_pinned_until=? WHERE id=?")
          ->execute([$name, $catId, $price, $desc, $newImageName, $hasSizes, $hasSugar, $spotlightPinned, $spotlightUntil, $pid]);
      } else {
        // Keep the existing image
        $db->prepare("UPDATE products SET name=?,category_id=?,price=?,description=?,has_sizes=?,has_sugar=?,spotlight_pinned=?,spotlight_pinned_until=? WHERE id=?")
          ->execute([$name, $catId, $price, $desc, $hasSizes, $hasSugar, $spotlightPinned, $spotlightUntil, $pid]);
      }
      $savedProductId = $pid;
      $action = 'edit_product';
      $successMessage = 'Product and setup saved.';
    } else {
      // Add product
      $db->prepare("INSERT INTO products (name,category_id,price,description,image_path,has_sizes,has_sugar,spotlight_pinned,spotlight_pinned_until) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$name, $catId, $price, $desc, $newImageName, $hasSizes, $hasSugar, $spotlightPinned, $spotlightUntil]);
      $savedProductId = (int) $db->lastInsertId();
      $action = 'add_product';
      $successMessage = 'Product and setup saved.';
    }

    if (isset($_POST['integrated_setup'])) {
      adminSaveProductSetup($db, $savedProductId, (bool) $hasSizes);
    }
    auditLog(ROLE_ADMIN, currentUserId(), $action, 'products', $savedProductId);
    $db->commit();
    if ($newImageName && $oldImageToDelete && file_exists(UPLOAD_DIR . $oldImageToDelete)) {
      unlink(UPLOAD_DIR . $oldImageToDelete);
    }
    flash('global', $successMessage, 'success');
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($newImageName && file_exists(UPLOAD_DIR . $newImageName)) unlink(UPLOAD_DIR . $newImageName);
    error_log('Save product and setup failed: ' . $e->getMessage());
    flash('global', $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the product setup. Please try again.', 'error');
    flashOldInput($_POST);
    redirect($productSetupUrl);
  }

  redirect(APP_URL . '/admin/ordering.php');
}

// Create a category by itself. Products are always added through the full setup page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
  verifyCsrf();
  $categoryName = sanitizeString($_POST['category_name'] ?? '', 100);
  $parentRaw = $_POST['parent_id'] ?? '';
  $parentId = $parentRaw === '' ? null : (int) $parentRaw;
  if ($categoryName === '') {
    flash('global', 'Enter a category name.', 'error');
    redirect(APP_URL . '/admin/ordering.php');
  }
  if ($parentId !== null) {
    $parentCheck = $db->prepare('SELECT id FROM categories WHERE id = ? AND parent_id IS NULL');
    $parentCheck->execute([$parentId]);
    if (!$parentCheck->fetchColumn()) {
      flash('global', 'Choose a valid top-level category, or leave it blank to create one.', 'error');
      redirect(APP_URL . '/admin/ordering.php');
    }
  }
  $sortQuery = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM categories WHERE parent_id <=> ?');
  $sortQuery->execute([$parentId]);
  $sortOrder = (int) $sortQuery->fetchColumn() + 1;
  $db->prepare('INSERT INTO categories (name, parent_id, sort_order) VALUES (?, ?, ?)')->execute([$categoryName, $parentId, $sortOrder]);
  $categoryId = (int) $db->lastInsertId();
  auditLog(ROLE_ADMIN, currentUserId(), 'add_category', 'categories', $categoryId);
  flash('global', 'Category created. You can now add products to it.', 'success');
  redirect(APP_URL . '/admin/product-setup.php?category_id=' . $categoryId);
}

// ---- Handle DELETE CATEGORY ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE_CATEGORY') {
  verifyCsrf();
  $catId = (int)($_POST['category_id'] ?? 0);

  if ($catId < 1) {
    flash('global', 'Invalid category selected.', 'error');
    redirect(APP_URL . '/admin/ordering.php');
  }

  $categoryIds = [$catId];
  $queue = [$catId];
  while (!empty($queue)) {
    $current = array_shift($queue);
    $stmt = $db->prepare("SELECT id FROM categories WHERE parent_id = ?");
    $stmt->execute([$current]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($children as $childId) {
      $categoryIds[] = $childId;
      $queue[] = $childId;
    }
  }

  $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

  // BUG FIX: same issue as the single-product delete above, at larger
  // scale — this used to run a JOIN DELETE against order_details for
  // every product in the category (and all its subcategories) before
  // deleting them, wiping the historical line items of every past order
  // that ever included any of those products. Refuse the whole deletion
  // if any product in this category tree has order history, rather than
  // silently corrupting some past orders and not others.
  $stmt = $db->prepare(
    "SELECT COUNT(*) FROM order_details od
     JOIN products p ON od.product_id = p.id
     WHERE p.category_id IN ($placeholders)"
  );
  $stmt->execute($categoryIds);
  if ((int) $stmt->fetchColumn() > 0) {
    flash('global', 'This category contains products with order history and cannot be deleted, to keep past receipts and reports intact. Deactivate the products or category instead.', 'error');
    redirect(APP_URL . '/admin/ordering.php');
  }

  $stmt = $db->prepare("SELECT image_path FROM products WHERE category_id IN ($placeholders)");
  $stmt->execute($categoryIds);
  $images = $stmt->fetchAll(PDO::FETCH_COLUMN);

  foreach ($images as $img) {
    if ($img && file_exists(UPLOAD_DIR . $img)) {
      unlink(UPLOAD_DIR . $img);
    }
  }

  // Now delete products (safe — we just confirmed none have order history)
  $db->prepare("DELETE FROM products WHERE category_id IN ($placeholders)")->execute($categoryIds);

  // Finally delete categories
  $db->prepare("DELETE FROM categories WHERE id IN ($placeholders)")->execute($categoryIds);

  auditLog(ROLE_ADMIN, currentUserId(), 'delete_category', 'categories', $catId);
  flash('global', 'Category and its contents deleted.', 'success');
  redirect(APP_URL . '/admin/ordering.php');
}

// ---- Load products ----
$catFilter = (int)($_GET['cat'] ?? 0);
$params    = [];
$where     = '';

if ($catFilter > 0) {
  // Check if this is a parent category or a child category
  $stmt = $db->prepare("SELECT parent_id FROM categories WHERE id = ?");
  $stmt->execute([$catFilter]);
  $category = $stmt->fetch();

  if ($category && $category['parent_id'] === null) {
    // This is a parent category - get products from all its children
    $stmt = $db->prepare("SELECT id FROM categories WHERE parent_id = ?");
    $stmt->execute([$catFilter]);
    $childIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $childIds[] = $catFilter; // Include the parent itself in case it has direct products
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $where = "WHERE p.category_id IN ($placeholders)";
    $params = $childIds;
  } else {
    // This is a child category - get only its products
    $where = 'WHERE p.category_id = ?';
    $params[] = $catFilter;
  }
}

$stmt = $db->prepare("SELECT p.*, c.name AS cat_name FROM products p JOIN categories c ON p.category_id=c.id $where ORDER BY c.sort_order, p.name");
$stmt->execute($params);
$products = $stmt->fetchAll();
$stockAvailability = inventoryProductAvailabilityMap($db, array_column($products, 'id'));

// Load category tree: parents first, then children grouped under them
$allCats = $db->query(
  "SELECT * FROM categories ORDER BY sort_order"
)->fetchAll();
// Separate into groups and sub-categories
$catGroups = array_filter($allCats, fn($c) => $c['parent_id'] === null);
$catSubs   = array_filter($allCats, fn($c) => $c['parent_id'] !== null);
// Index sub-cats by parent_id
$catsByParent = [];
foreach ($catSubs as $sub) {
  $catsByParent[$sub['parent_id']][] = $sub;
}
// Flat list for backward compat (used in table)
$categories = $allCats;
// Only assignable categories = leaf nodes (sub-cats) OR groups with no children
$assignableCats = array_filter($allCats, function ($c) use ($catsByParent) {
  // A category is assignable if it has no children (it's a leaf)
  return empty($catsByParent[$c['id']]);
});

// Base URL for product images
$imgBase = APP_URL . '/../uploads/products/';

// Sizes & Pricing sub-tab data (item 13)
$sizeOptions = $db->query("SELECT * FROM size_options ORDER BY sort_order, id")->fetchAll();
layoutHeader('Ordering');
?>

<div class="page-header">
  <div>
    <div class="page-header-title">Ordering</div>
    <div class="page-header-sub">Manage menu products, categories, drink sizes, and stock-aware recipes.</div>
  </div>
</div>
<?php showFlash('global'); ?>

<!-- ══════════════ Sub-tabs (item 13) ══════════════ -->
<div class="tab-bar mb-4" id="orderingSubTabs">
  <button type="button" class="tab-btn <?= $activeSubtab === 'products' ? 'active' : '' ?>" data-subtab="products" onclick="switchOrderingTab('products')">
    <i class="fa-solid fa-mug-hot"></i> Products
  </button>
  <button type="button" class="tab-btn" onclick="openAddonsListModal()">
    <i class="fa-solid fa-layer-group"></i> Add-ons
  </button>
  <button type="button" class="tab-btn <?= $activeSubtab === 'sizes' ? 'active' : '' ?>" data-subtab="sizes" onclick="switchOrderingTab('sizes')">
    <i class="fa-solid fa-ruler"></i> Drink Sizes &amp; Pricing
  </button>
</div>

<div id="ordering-tab-products" style="<?= $activeSubtab === 'products' ? '' : 'display:none' ?>">
  <div class="page-header">
    <div></div>
    <div class="page-header-actions">
      <?php if (empty($assignableCats)): ?>
        <button class="btn btn-primary" onclick="openCategoryModal()"><i class="fa-solid fa-folder-plus"></i> Create your first category</button>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= e(APP_URL) ?>/admin/product-setup.php"><i class="fa-solid fa-plus"></i> Add Product</a>
      <?php endif; ?>
      <?php if (!empty($assignableCats)): ?><button class="btn btn-secondary" onclick="openCategoryModal()"><i class="fa-solid fa-folder-plus"></i> Add Category</button><?php endif; ?>
    </div>
  </div>

  <style>
    .category-delete-card {
      margin-bottom: 1.5rem;
    }

    .category-delete-card .card-header {
      gap: var(--space-4);
    }

    .category-delete-table th,
    .category-delete-table td {
      padding: 1rem 0.5rem;
      vertical-align: middle;
      border-bottom: 1px solid var(--border-color);
    }

    .category-delete-table th {
      background: var(--surface-raised);
      font-weight: 600;
      color: var(--text-color);
    }

    .category-delete-table tbody tr:hover {
      background: var(--surface-raised);
    }

    .category-parent-row td {
      font-size: 0.95rem;
      border-bottom: 2px solid var(--border-color);
    }

    .category-child-row {
      background: var(--surface-raised);
    }

    .category-child-row:hover {
      background: var(--surface-raised);
    }

    .category-delete-table .btn-danger {
      min-width: 80px;
    }
  </style>

  <!-- Category Filter Tabs -->
  <div class="tab-bar mb-4">
    <a href="?cat=0" class="tab-btn <?= $catFilter === 0 ? 'active' : '' ?>">All</a>
    <?php foreach ($catGroups as $group): ?>
      <?php if (!empty($catsByParent[$group['id']])): ?>
        <a href="?cat=<?= $group['id'] ?>" class="tab-btn tab-parent" style="font-weight:600; color:var(--primary-color); <?= $catFilter === $group['id'] ? 'background:var(--primary-color); color:var(--text-on-primary);' : '' ?>"><?= e($group['name']) ?></a>
        <?php foreach ($catsByParent[$group['id']] as $sub): ?>
          <a href="?cat=<?= $sub['id'] ?>" class="tab-btn <?= $catFilter === $sub['id'] ? 'active' : '' ?>"><?= e($sub['name']) ?></a>
        <?php endforeach; ?>
      <?php else: ?>
        <a href="?cat=<?= $group['id'] ?>" class="tab-btn tab-parent" style="font-weight:600; color:var(--primary-color); <?= $catFilter === $group['id'] ? 'background:var(--primary-color); color:var(--text-on-primary);' : '' ?>"><?= e($group['name']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <div class="card mb-4">
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Category</th>
            <th>Price</th>
            <th>Available</th>
            <th>Setup</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($products)): ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:30px;color:var(--text-muted)">No products found. Add your first product to get started.</td>
            </tr>
            <?php else: foreach ($products as $p): ?>
              <tr>
                <td style="display:flex;align-items:center;gap:12px">
                  <!-- Product thumbnail -->
                  <?php if ($p['image_path'] && file_exists(UPLOAD_DIR . $p['image_path'])): ?>
                    <img src="<?= $imgBase . e($p['image_path']) ?>"
                      style="width:44px;height:44px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border-color);flex-shrink:0"
                      alt="<?= e($p['name']) ?>">
                  <?php else: ?>
                    <div style="width:44px;height:44px;background:var(--surface-raised);border-radius:var(--radius-sm);border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;color:var(--text-muted);flex-shrink:0">
                      <i class="fa-solid fa-mug-hot"></i>
                    </div>
                  <?php endif; ?>
                  <div>
                    <strong><?= e($p['name']) ?></strong>
                    <?php if (!empty($p['spotlight_pinned'])): ?>
                      <i class="fa-solid fa-star" title="Pinned to Spotlight" style="color:var(--accent-color);font-size:11px;margin-left:4px"></i>
                    <?php endif; ?>
                    <?php if ($p['description']): ?>
                      <div class="text-muted"><?= e($p['description']) ?></div>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?= e($p['cat_name']) ?></td>
                <td><?= peso($p['price']) ?></td>
                <td onclick="event.stopPropagation()">
                  <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="toggle_id" value="<?= $p['id'] ?>">
                    <label class="switch">
                      <input type="checkbox" onchange="handleToggle(this)" <?= $p['is_available'] ? 'checked' : '' ?>>
                      <span class="switch-slider"></span>
                    </label>
                    <?php if ($p['is_available'] && !($stockAvailability[(int) $p['id']] ?? true)): ?>
                      <div class="text-muted" style="font-size:11px;margin-top:4px">Automatically unavailable · recipe stock is short</div>
                    <?php elseif (!$p['is_available']): ?>
                      <div class="text-muted" style="font-size:11px;margin-top:4px">Manually paused</div>
                    <?php endif; ?>
                  </form>
                </td>
                <td><a class="btn btn-ghost btn-sm" href="<?= e(APP_URL) ?>/admin/product-setup.php?id=<?= (int) $p['id'] ?>"><i class="fa-solid fa-pen"></i> Edit setup</a></td>
              </tr>
          <?php endforeach;
          endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mb-4 category-delete-card">
    <div style="overflow-x:auto">
      <table class="data-table category-delete-table">
        <thead>
          <tr>
            <th style="width: 60%; padding-left: 1rem;">Category</th>
            <th style="width: 40%; text-align: right; padding-right: 1rem;">Products</th>
            <th style="width: 120px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allCats)): ?>
            <tr>
              <td colspan="3" style="text-align:center;padding:30px;color:var(--text-muted)">
                <i class="fa-solid fa-folder-open" style="font-size: 2rem; opacity: 0.5; display: block; margin-bottom: 0.5rem;"></i>
                No categories found
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($catGroups as $group): ?>
              <?php
              // Count products in this group and its subcategories
              $groupProductCount = 0;
              $groupChildren = $catsByParent[$group['id']] ?? [];
              $allGroupCatIds = [$group['id']];
              foreach ($groupChildren as $sub) {
                $allGroupCatIds[] = $sub['id'];
              }
              if (!empty($allGroupCatIds)) {
                $placeholders = implode(',', array_fill(0, count($allGroupCatIds), '?'));
                $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id IN ($placeholders)");
                $stmt->execute($allGroupCatIds);
                $groupProductCount = $stmt->fetchColumn();
              }
              ?>
              <tr class="category-parent-row">
                <td style="padding-left: 1rem;">
                  <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fa-solid fa-folder" style="color: var(--primary-color); font-size: 1.1rem;"></i>
                    <div>
                      <strong><?= e($group['name']) ?></strong>
                      <?php if (!empty($groupChildren)): ?>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                          <?= count($groupChildren) ?> submenu<?= count($groupChildren) !== 1 ? 's' : '' ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td style="text-align: right; padding-right: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                  <?= number_format($groupProductCount) ?> product<?= $groupProductCount !== 1 ? 's' : '' ?>
                </td>
                <td style="text-align: right; padding-right: 1rem;">
                  <?php if (!empty($groupChildren)): ?>
                    <span class="text-muted" style="font-size: 0.8rem;">Contains submenus</span>
                  <?php else: ?>
                    <form method="POST" onsubmit="return confirm('Delete <?= e($group['name']) ?> category and its <?= number_format($groupProductCount) ?> product<?= $groupProductCount !== 1 ? 's' : '' ?>? This cannot be undone.')" style="display:inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="_method" value="DELETE_CATEGORY">
                      <input type="hidden" name="category_id" value="<?= $group['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fa-solid fa-trash"></i> Delete
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php if (!empty($groupChildren)): ?>
                <?php foreach ($groupChildren as $sub): ?>
                  <?php
                  // Count products in this subcategory
                  $subProductCount = 0;
                  $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
                  $stmt->execute([$sub['id']]);
                  $subProductCount = $stmt->fetchColumn();
                  ?>
                  <tr class="category-child-row">
                    <td style="padding-left: 3rem;">
                      <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-folder-open" style="color: var(--color-success); font-size: 1rem;"></i>
                        <span><?= e($sub['name']) ?></span>
                      </div>
                    </td>
                    <td style="text-align: right; padding-right: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                      <?= number_format($subProductCount) ?> product<?= $subProductCount !== 1 ? 's' : '' ?>
                    </td>
                    <td style="text-align: right; padding-right: 1rem;">
                      <form method="POST" onsubmit="return confirm('Delete <?= e($sub['name']) ?> category and its <?= number_format($subProductCount) ?> product<?= $subProductCount !== 1 ? 's' : '' ?>? This cannot be undone.')" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="_method" value="DELETE_CATEGORY">
                        <input type="hidden" name="category_id" value="<?= $sub['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">
                          <i class="fa-solid fa-trash"></i> Delete
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div><!-- /#ordering-tab-products -->

<!-- Drink size presets used by products that offer customer size choices. -->
<div id="ordering-tab-sizes" style="<?= $activeSubtab === 'sizes' ? '' : 'display:none' ?>">
  <div class="page-header">
    <div>
      <div class="page-header-title">Drink size options</div>
      <div class="page-header-sub">Customers choose these menu sizes. Stock units such as L, kg, and pcs are set separately in Inventory. Price adjustments apply to every product that offers sizes.</div>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick="openSizeModal()">
        <i class="fa-solid fa-plus"></i> Add Drink Size
      </button>
    </div>
  </div>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Drink Size</th>
            <th>Price Adjustment</th>
            <th>Sort Order</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sizeOptions)): ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:30px;color:var(--text-muted)">No sizes yet. Add one to get started.</td>
            </tr>
            <?php else: foreach ($sizeOptions as $s): ?>
              <tr>
                <td><strong><?= e($s['label']) ?></strong></td>
                <td><?= $s['price_adjustment'] > 0 ? '+' . peso($s['price_adjustment']) : peso(0) ?></td>
                <td><?= (int) $s['sort_order'] ?></td>
                <td><span class="badge badge-<?= $s['is_active'] ? 'paid' : 'cancelled' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td>
                  <button class="btn btn-sm btn-outline" onclick='openSizeModal(<?= json_encode($s) ?>)'><i class="fa-solid fa-pen"></i> Edit</button>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this size option?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="size_action" value="delete">
                    <input type="hidden" name="size_id" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
                  </form>
                </td>
              </tr>
          <?php endforeach;
          endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Size modal -->
<div class="modal-overlay hidden" id="size-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="size-modal-title">Add Size</div>
      <button class="modal-close" onclick="closeSizeModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="size-form">
      <?= csrfField() ?>
      <input type="hidden" name="size_action" id="size-form-action" value="create">
      <input type="hidden" name="size_id" id="size-form-id" value="">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Drink size label</label><input type="text" name="label" id="size-form-label" class="form-control" required placeholder="e.g. 16oz"></div>
        <div class="form-group"><label class="form-label">Price Adjustment (₱)</label><input type="number" name="price_adjustment" id="size-form-adj" class="form-control" step="0.50" value="0" required></div>
        <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" id="size-form-sort" class="form-control" value="0"></div>
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="is_active" id="size-form-active" value="1" checked style="width:auto"> Active (shown to customers)
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeSizeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Size</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== ADD-ONS LIST MODAL ===================== -->
<div class="modal-overlay hidden" id="addons-list-modal">
  <div class="modal" style="max-width:800px;width:95vw;">
    <div class="modal-header">
      <div class="modal-title">
        <i class="fa-solid fa-layer-group"></i> Add-on catalog
      </div>
      <button class="modal-close" onclick="closeAddonsListModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="modal-body">
      <p style="font-size:0.85rem;color:var(--text-muted);margin:0 0 1rem">
        Add-ons use shared stock from <a href="<?= APP_URL ?>/admin/inventory.php" style="font-weight:600">Inventory</a>. Open a product and choose compatible add-ons in its setup section.
      </p>

      <div id="addons-list-table" style="overflow-x:auto">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Price</th>
              <th>Stock item / portion</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="addons-list-body">
            <!-- Add-ons will be loaded here -->
          </tbody>
        </table>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeAddonsListModal()">Close</button>
    </div>
  </div>
</div>

<!-- ===================== ADD CATEGORY MODAL ===================== -->
<div class="modal-overlay hidden" id="category-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">
        <i class="fa-solid fa-folder-plus"></i> Add Menu Category
      </div>
      <button class="modal-close" onclick="closeCategoryModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form method="POST" id="category-form">
      <?= csrfField() ?>
      <input type="hidden" name="save_category" value="1">

      <div class="modal-body">
        <p class="text-muted" style="font-size:.85rem;line-height:1.5;margin-top:0">Create one category first. Then add each product from the product setup page so its recipe, sizes, and add-ons are configured consistently.</p>
        <div class="form-group"><label class="form-label" for="category-parent">Parent category <span class="text-muted">(optional)</span></label><select name="parent_id" id="category-parent" class="form-control"><option value="">No parent — create a top-level category</option><?php foreach ($catGroups as $group): ?><option value="<?= (int) $group['id'] ?>">Under <?= e($group['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label" for="category-name">Category name <span style="color:var(--status-cancelled)">*</span></label><input type="text" name="category_name" id="category-name" class="form-control" maxlength="100" required placeholder="e.g. Coffee, Iced Drinks"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCategoryModal()">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Create category</button>
      </div>
    </form>
  </div>
</div>

<script>
  function e(value) { const node=document.createElement('span'); node.textContent=value??''; return node.innerHTML; }
  function handleToggle(input) {
    if (!confirm('Change product availability?')) { input.checked=!input.checked; return; }
    input.disabled=true; input.form.submit();
  }
  function openAddonsListModal() { document.getElementById('addons-list-modal').classList.remove('hidden'); loadAddonsList(); }
  function closeAddonsListModal() { document.getElementById('addons-list-modal').classList.add('hidden'); }
  document.getElementById('addons-list-modal').addEventListener('click',event=>{if(event.target===event.currentTarget)closeAddonsListModal();});
  function loadAddonsList() {
    const body=document.getElementById('addons-list-body');
    body.innerHTML='<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading…</td></tr>';
    fetch('<?= APP_URL ?>/api/get_addons.php').then(response=>response.json()).then(data=>{
      if(!data.success||!data.addons.length){body.innerHTML='<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted)">No stocked add-ons yet. Add them from Inventory.</td></tr>';return;}
      body.innerHTML=data.addons.map(addon=>`<tr><td><strong>${e(addon.name)}</strong></td><td>₱${Number(addon.price).toFixed(2)}</td><td>${e(addon.inventory_name||'—')} <span class="text-muted">(${Number(addon.qty_per_unit||0).toFixed(3)} ${e(addon.inventory_unit||'')})</span></td><td>${e(addon.status)}</td></tr>`).join('');
    }).catch(()=>{body.innerHTML='<tr><td colspan="4" style="text-align:center;padding:1rem;color:var(--status-cancelled)">Could not load add-ons. Try again.</td></tr>';});
  }
</script>

<script>
  function openCategoryModal() {
    document.getElementById('category-form').reset();
    document.getElementById('category-modal').classList.remove('hidden');
    document.getElementById('category-name').focus();
  }

  function closeCategoryModal() {
    document.getElementById('category-modal').classList.add('hidden');
  }

  // Close category modal when clicking outside
  document.getElementById('category-modal').addEventListener('click', function(e) {
    if (e.target === this) closeCategoryModal();
  });

  /* ══════════════ Ordering sub-tabs (item 13, new) ══════════════ */
  function switchOrderingTab(tab) {
    document.getElementById('ordering-tab-products').style.display = tab === 'products' ? '' : 'none';
    document.getElementById('ordering-tab-sizes').style.display = tab === 'sizes' ? '' : 'none';
    document.querySelectorAll('#orderingSubTabs .tab-btn[data-subtab]').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.subtab === tab);
    });
  }

  function openSizeModal(size) {
    const form = document.getElementById('size-form');
    if (size) {
      document.getElementById('size-modal-title').textContent = 'Edit Size';
      document.getElementById('size-form-action').value = 'update';
      document.getElementById('size-form-id').value = size.id;
      document.getElementById('size-form-label').value = size.label;
      document.getElementById('size-form-adj').value = size.price_adjustment;
      document.getElementById('size-form-sort').value = size.sort_order;
      document.getElementById('size-form-active').checked = !!parseInt(size.is_active);
    } else {
      document.getElementById('size-modal-title').textContent = 'Add Size';
      document.getElementById('size-form-action').value = 'create';
      form.reset();
      document.getElementById('size-form-id').value = '';
      document.getElementById('size-form-active').checked = true;
    }
    document.getElementById('size-modal').classList.remove('hidden');
  }

  function closeSizeModal() {
    document.getElementById('size-modal').classList.add('hidden');
  }
  document.getElementById('size-modal').addEventListener('click', function(e) {
    if (e.target === this) closeSizeModal();
  });
</script>



<?php layoutFooter(); ?>
