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
$activeSubtab = in_array(($_GET['tab'] ?? ''), ['sizes', 'categories'], true) ? $_GET['tab'] : 'products';

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
  $categoryId = (int) ($_POST['category_id'] ?? 0);
  $parentRaw = $_POST['parent_id'] ?? '';
  $parentId = $parentRaw === '' ? null : (int) $parentRaw;
  if ($categoryName === '') {
    flash('global', 'Enter a category name.', 'error');
    redirect(APP_URL . '/admin/ordering.php?tab=categories');
  }
  if ($categoryId > 0) {
    $exists = $db->prepare('SELECT parent_id FROM categories WHERE id = ?');
    $exists->execute([$categoryId]);
    $existingParent = $exists->fetchColumn();
    if ($existingParent === false) {
      flash('global', 'That category no longer exists. Refresh and try again.', 'error');
      redirect(APP_URL . '/admin/ordering.php?tab=categories');
    }
    $duplicate = $db->prepare('SELECT COUNT(*) FROM categories WHERE name = ? AND parent_id <=> ? AND id <> ?');
    $duplicate->execute([$categoryName, $existingParent, $categoryId]);
    if ((int)$duplicate->fetchColumn() > 0) {
      flash('global', 'A category with that name already exists at this level.', 'error');
      redirect(APP_URL . '/admin/ordering.php?tab=categories');
    }
    try {
      $db->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$categoryName, $categoryId]);
      auditLog(ROLE_ADMIN, currentUserId(), 'rename_category', 'categories', $categoryId);
      flash('global', 'Category renamed. Its products remain assigned to it.', 'success');
    } catch (PDOException $e) {
      flash('global', 'A category with that name already exists.', 'error');
    }
    redirect(APP_URL . '/admin/ordering.php?tab=categories');
  }
  if ($parentId !== null) {
    $parentCheck = $db->prepare('SELECT id FROM categories WHERE id = ? AND parent_id IS NULL');
    $parentCheck->execute([$parentId]);
    if (!$parentCheck->fetchColumn()) {
      flash('global', 'Choose a valid top-level category, or leave it blank to create one.', 'error');
      redirect(APP_URL . '/admin/ordering.php?tab=categories');
    }
  }
  $sortQuery = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM categories WHERE parent_id <=> ?');
  $sortQuery->execute([$parentId]);
  $sortOrder = (int) $sortQuery->fetchColumn() + 1;
  $duplicate = $db->prepare('SELECT COUNT(*) FROM categories WHERE name = ? AND parent_id <=> ?');
  $duplicate->execute([$categoryName, $parentId]);
  if ((int)$duplicate->fetchColumn() > 0) {
    flash('global', 'A category with that name already exists at this level.', 'error');
    redirect(APP_URL . '/admin/ordering.php?tab=categories');
  }
  $db->prepare('INSERT INTO categories (name, parent_id, sort_order) VALUES (?, ?, ?)')->execute([$categoryName, $parentId, $sortOrder]);
  $categoryId = (int) $db->lastInsertId();
  auditLog(ROLE_ADMIN, currentUserId(), 'add_category', 'categories', $categoryId);
  flash('global', 'Category created. You can now add products to it.', 'success');
  redirect(APP_URL . '/admin/ordering.php?tab=categories');
}

// ---- Handle DELETE CATEGORY ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE_CATEGORY') {
  verifyCsrf();
  $catId = (int)($_POST['category_id'] ?? 0);

  if ($catId < 1) {
    flash('global', 'Invalid category selected.', 'error');
    redirect(APP_URL . '/admin/ordering.php?tab=categories');
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
    redirect(APP_URL . '/admin/ordering.php?tab=categories');
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
  redirect(APP_URL . '/admin/ordering.php?tab=categories');
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
$availableProductCount = 0;
$pausedProductCount = 0;
$stockBlockedProductCount = 0;
foreach ($products as $product) {
  if (empty($product['is_available'])) $pausedProductCount++;
  elseif (empty($stockAvailability[(int)$product['id']])) $stockBlockedProductCount++;
  else $availableProductCount++;
}

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
// Only assignable categories = leaf nodes (sub-cats) OR groups with no children
$assignableCats = array_filter($allCats, function ($c) use ($catsByParent) {
  // A category is assignable if it has no children (it's a leaf)
  return empty($catsByParent[$c['id']]);
});
$productCountsByCategory = [];
foreach ($db->query('SELECT category_id, COUNT(*) AS product_count FROM products GROUP BY category_id')->fetchAll(PDO::FETCH_ASSOC) as $countRow) {
  $productCountsByCategory[(int) $countRow['category_id']] = (int) $countRow['product_count'];
}
$totalCategoryProducts = array_sum($productCountsByCategory);

// Base URL for product images
$imgBase = APP_URL . '/../uploads/products/';

// Sizes & Pricing sub-tab data (item 13)
$sizeOptions = $db->query("SELECT * FROM size_options ORDER BY sort_order, id")->fetchAll();
$activeSizeCount = count(array_filter($sizeOptions, static fn($size) => (int)$size['is_active'] === 1));
$nextSizeSortOrder = $sizeOptions ? max(array_map(static fn($size) => (int)$size['sort_order'], $sizeOptions)) + 1 : 1;
layoutHeader('Ordering');
?>

<div class="page-header">
  <div>
    <div class="page-header-title">Menu Management</div>
    <div class="page-header-sub">Manage products, categories, add-ons, and drink sizes.</div>
  </div>
</div>
<?php showFlash('global'); ?>

<style>
  .ordering-section-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    margin: 4px 0 18px
  }

  .ordering-section-head h2 {
    font-size: 1.35rem;
    margin: 0 0 4px
  }

  .ordering-section-head p {
    color: var(--text-muted);
    margin: 0;
    font-size: .9rem
  }

  @media(max-width:760px) {
    .ordering-section-head {
      align-items: flex-start;
      flex-direction: column
    }
  }
</style>

<!-- ══════════════ Sub-tabs (item 13) ══════════════ -->
<div class="tab-bar mb-4" id="orderingSubTabs">
  <button type="button" class="tab-btn <?= $activeSubtab === 'products' ? 'active' : '' ?>" data-subtab="products" onclick="switchOrderingTab('products')">
    <i class="fa-solid fa-mug-hot"></i> Products
  </button>
  <button type="button" class="tab-btn <?= $activeSubtab === 'categories' ? 'active' : '' ?>" data-subtab="categories" onclick="switchOrderingTab('categories')">
    <i class="fa-solid fa-folder-tree"></i> Categories
  </button>
  <button type="button" class="tab-btn <?= $activeSubtab === 'sizes' ? 'active' : '' ?>" data-subtab="sizes" onclick="switchOrderingTab('sizes')">
    <i class="fa-solid fa-ruler"></i> Drink Sizes &amp; Pricing
  </button>
  <button type="button" class="tab-btn" onclick="openAddonsListModal()">
    <i class="fa-solid fa-layer-group"></i> Add-on Catalog
  </button>
</div>

<div id="ordering-tab-products" style="<?= $activeSubtab === 'products' ? '' : 'display:none' ?>">
  <style>
    .ordering-summary-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 18px
    }

    .ordering-summary {
      padding: 16px 18px;
      display: flex;
      align-items: center;
      gap: 13px
    }

    .ordering-summary-icon {
      width: 40px;
      height: 40px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      background: var(--surface-raised);
      color: var(--primary-color);
      flex: none
    }

    .ordering-summary>div>span {
      display: block;
      font-size: .78rem;
      color: var(--text-muted)
    }

    .ordering-summary strong {
      display: block;
      font-size: 1.35rem;
      line-height: 1.2;
      margin-top: 2px
    }

    .product-toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 14px;
      padding: 15px 18px;
      border-bottom: 1px solid var(--border-color)
    }

    .product-search {
      position: relative;
      width: min(100%, 340px)
    }

    .product-search i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      pointer-events: none
    }

    .product-search input {
      padding-left: 36px
    }

    .product-result-count {
      color: var(--text-muted);
      font-size: .82rem;
      white-space: nowrap
    }

    .product-filter-bar {
      display: flex;
      align-items: center;
      gap: 8px;
      overflow-x: auto;
      padding: 0 0 12px;
      margin-bottom: 16px
    }

    .product-filter-label {
      font-size: .8rem;
      font-weight: 700;
      color: var(--text-muted);
      white-space: nowrap;
      margin-right: 3px
    }

    .product-filter-bar .tab-btn {
      white-space: nowrap
    }

    .product-cell {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 210px
    }

    .product-thumb {
      width: 44px;
      height: 44px;
      object-fit: cover;
      border-radius: 10px;
      border: 1px solid var(--border-color);
      flex: none
    }

    .product-thumb-placeholder {
      display: grid;
      place-items: center;
      background: var(--surface-raised);
      color: var(--text-muted)
    }

    .product-description {
      color: var(--text-muted);
      font-size: .78rem;
      margin-top: 3px;
      max-width: 370px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap
    }

    .product-status {
      display: flex;
      align-items: center;
      gap: 8px
    }

    .product-status .switch {
      flex: none
    }

    .product-status-copy {
      display: grid;
      gap: 3px
    }

    .product-status-note {
      font-size: .72rem;
      color: var(--text-muted)
    }

    .ordering-empty-search td {
      text-align: center;
      padding: 28px;
      color: var(--text-muted)
    }

    @media(max-width:760px) {
      .ordering-summary-grid {
        grid-template-columns: 1fr
      }

      .product-toolbar {
        align-items: flex-start;
        flex-direction: column
      }

      .product-search {
        width: 100%
      }
    }
  </style>
  <div class="ordering-section-head">
    <div>
      <h2>Products</h2>
      <p>Manage availability, pricing, and product setup.</p>
    </div>
    <div class="page-header-actions">
      <?php if (!empty($assignableCats)): ?>
        <a class="btn btn-primary" href="<?= e(APP_URL) ?>/admin/product-setup.php"><i class="fa-solid fa-plus"></i> Add Product</a>
      <?php else: ?>
        <button class="btn btn-primary" onclick="switchOrderingTab('categories');openCategoryModal()"><i class="fa-solid fa-folder-plus"></i> Create your first category</button>
      <?php endif; ?>
      <?php if (!empty($assignableCats)): ?><button class="btn btn-secondary" onclick="switchOrderingTab('categories');openCategoryModal()"><i class="fa-solid fa-folder-plus"></i> Add Category</button><?php endif; ?>
    </div>
  </div>

  <div class="ordering-summary-grid">
    <div class="card ordering-summary"><span class="ordering-summary-icon"><i class="fa-solid fa-mug-hot"></i></span>
      <div><span>Products in view</span><strong><?= count($products) ?></strong></div>
    </div>
    <div class="card ordering-summary"><span class="ordering-summary-icon" style="color:var(--color-success)"><i class="fa-solid fa-circle-check"></i></span>
      <div><span>Available to order</span><strong><?= $availableProductCount ?></strong></div>
    </div>
    <div class="card ordering-summary"><span class="ordering-summary-icon" style="color:var(--status-cancelled)"><i class="fa-solid fa-circle-pause"></i></span>
      <div><span>Paused / low stock</span><strong><?= $pausedProductCount + $stockBlockedProductCount ?></strong></div>
    </div>
  </div>

  <!-- Category Filter Tabs -->
  <div class="product-filter-bar">
    <span class="product-filter-label"><i class="fa-solid fa-filter"></i> Category</span>
    <a href="?cat=0" class="tab-btn <?= $catFilter === 0 ? 'active' : '' ?>">All categories</a>
    <?php foreach ($catGroups as $group): ?>
      <?php if (!empty($catsByParent[$group['id']])): ?>
        <a href="?cat=<?= $group['id'] ?>" class="tab-btn <?= $catFilter === (int)$group['id'] ? 'active' : '' ?>" title="Show products in <?= e($group['name']) ?> and its subcategories"><?= e($group['name']) ?> and subcategories</a>
        <?php foreach ($catsByParent[$group['id']] as $sub): ?>
          <a href="?cat=<?= $sub['id'] ?>" class="tab-btn <?= $catFilter === (int)$sub['id'] ? 'active' : '' ?>"><?= e($sub['name']) ?></a>
        <?php endforeach; ?>
      <?php else: ?>
        <a href="?cat=<?= $group['id'] ?>" class="tab-btn <?= $catFilter === (int)$group['id'] ? 'active' : '' ?>"><?= e($group['name']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <div class="card mb-4">
    <div class="product-toolbar">
      <label class="product-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" class="form-control" id="product-search" aria-label="Search products" placeholder="Search product name or description" autocomplete="off"></label>
      <span class="product-result-count" id="product-result-count"><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?> shown</span>
    </div>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Category</th>
            <th>Price</th>
            <th>Order status</th>
            <th>Setup</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($products)): ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:30px;color:var(--text-muted)">No products are in this view yet. Add a product to get started.</td>
            </tr>
            <?php else: foreach ($products as $p): ?>
              <tr>
                <?php $productSearchText = strtolower($p['name'] . ' ' . ($p['description'] ?? '') . ' ' . $p['cat_name']); ?>
                <td>
                  <div class="product-cell" data-product-search="<?= e($productSearchText) ?>">
                    <?php if ($p['image_path'] && file_exists(UPLOAD_DIR . $p['image_path'])): ?>
                      <img src="<?= $imgBase . e($p['image_path']) ?>" class="product-thumb" alt="">
                    <?php else: ?>
                      <div class="product-thumb product-thumb-placeholder" aria-hidden="true">
                        <i class="fa-solid fa-mug-hot"></i>
                      </div>
                    <?php endif; ?>
                    <div>
                      <strong><?= e($p['name']) ?></strong>
                      <?php if (!empty($p['spotlight_pinned'])): ?>
                        <i class="fa-solid fa-star" title="Pinned to Spotlight" style="color:var(--accent-color);font-size:11px;margin-left:4px"></i>
                      <?php endif; ?>
                      <?php if ($p['description']): ?>
                        <div class="product-description" title="<?= e($p['description']) ?>"><?= e($p['description']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td><?= e($p['cat_name']) ?></td>
                <td><?= peso($p['price']) ?></td>
                <td onclick="event.stopPropagation()">
                  <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="toggle_id" value="<?= $p['id'] ?>">
                    <div class="product-status"><label class="switch" title="Toggle product availability">
                        <input type="checkbox" aria-label="Toggle availability for <?= e($p['name']) ?>" onchange="handleToggle(this)" <?= $p['is_available'] ? 'checked' : '' ?>>
                        <span class="switch-slider"></span>
                      </label>
                      <div class="product-status-copy">
                        <?php if ($p['is_available'] && !($stockAvailability[(int) $p['id']] ?? true)): ?>
                          <span class="badge badge-cancelled">Low stock</span><span class="product-status-note">Recipe stock is short</span>
                        <?php elseif (!$p['is_available']): ?>
                          <span class="badge badge-cancelled">Paused</span>
                        <?php else: ?>
                          <span class="badge badge-ready">Available</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </form>
                </td>
                <td><a class="btn btn-ghost btn-sm" href="<?= e(APP_URL) ?>/admin/product-setup.php?id=<?= (int) $p['id'] ?>"><i class="fa-solid fa-pen"></i> Edit setup</a></td>
              </tr>
          <?php endforeach;
          endif; ?>
          <tr class="ordering-empty-search" id="product-search-empty" hidden>
            <td colspan="5"><i class="fa-solid fa-magnifying-glass" style="font-size:1.2rem;display:block;margin-bottom:7px"></i>No products match your search.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /#ordering-tab-products -->

<div id="ordering-tab-categories" style="<?= $activeSubtab === 'categories' ? '' : 'display:none' ?>">
  <style>
    .category-summary-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 18px
    }

    .category-summary {
      padding: 17px 19px;
      display: grid;
      gap: 4px
    }

    .category-summary strong {
      font-size: 1.65rem;
      line-height: 1.1;
      color: var(--text-color)
    }

    .category-summary small {
      color: var(--text-muted)
    }

    .category-manager-list {
      display: grid;
      gap: 14px
    }

    .category-group-card {
      overflow: hidden
    }

    .category-group-header {
      padding: 17px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px
    }

    .category-group-identity,
    .category-sub-identity {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0
    }

    .category-icon {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      background: var(--surface-raised);
      color: var(--primary-color);
      font-size: 17px;
      flex: none
    }

    .category-group-identity h2 {
      font-size: 1rem;
      margin: 0 0 3px
    }

    .category-group-identity p {
      font-size: .82rem;
      color: var(--text-muted);
      margin: 0
    }

    .category-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 7px;
      flex-wrap: wrap
    }

    .category-actions form {
      margin: 0
    }

    .category-sub-list {
      border-top: 1px solid var(--border-color);
      padding: 4px 20px 4px 74px
    }

    .category-sub-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 12px 0;
      border-bottom: 1px solid var(--border-color)
    }

    .category-sub-row:last-child {
      border-bottom: 0
    }

    .category-sub-identity>i {
      color: var(--color-success);
      width: 18px;
      text-align: center
    }

    .category-sub-identity div {
      display: grid;
      gap: 2px
    }

    .category-sub-identity span {
      font-size: .78rem;
      color: var(--text-muted)
    }

    .category-group-empty {
      border-top: 1px solid var(--border-color);
      padding: 13px 20px 5px 74px;
      color: var(--text-muted);
      font-size: .84rem
    }

    .category-group-footer {
      padding: 0 20px 14px 74px
    }

    .category-empty-state {
      text-align: center;
      padding: 44px 24px
    }

    .category-empty-state>i {
      font-size: 2rem;
      color: var(--primary-color);
      margin-bottom: 8px
    }

    .category-empty-state h2 {
      font-size: 1.1rem;
      margin: 0 0 7px
    }

    .category-empty-state p {
      color: var(--text-muted);
      margin: 0 auto 16px;
      max-width: 470px
    }

    @media(max-width:760px) {
      .category-summary-grid {
        grid-template-columns: 1fr
      }

      .category-group-header,
      .category-sub-row {
        align-items: flex-start;
        flex-direction: column
      }

      .category-actions {
        justify-content: flex-start
      }

      .category-sub-list,
      .category-group-empty,
      .category-group-footer {
        padding-left: 20px
      }
    }
  </style>
  <div class="ordering-section-head">
    <div>
      <h2>Menu categories</h2>
      <p>Organize products into menu groups and subcategories. Renaming a category keeps its products assigned.</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" type="button" onclick="openCategoryModal()"><i class="fa-solid fa-folder-plus"></i> Add Category</button>
    </div>
  </div>
  <div class="category-summary-grid">
    <div class="card category-summary"><span class="text-muted">Categories</span><strong><?= count($allCats) ?></strong><small>Groups and subcategories</small></div>
    <div class="card category-summary"><span class="text-muted">Top-level groups</span><strong><?= count($catGroups) ?></strong><small>Shown as main menu sections</small></div>
    <div class="card category-summary"><span class="text-muted">Products assigned</span><strong><?= number_format($totalCategoryProducts) ?></strong><small>Across all categories</small></div>
  </div>

  <?php if (!$allCats): ?>
    <div class="card category-empty-state">
      <i class="fa-solid fa-folder-open"></i>
      <h2>No menu categories yet</h2>
      <p>Create a top-level category such as Coffee or Other Drinks. You can add subcategories after that.</p>
      <button class="btn btn-primary" type="button" onclick="openCategoryModal()"><i class="fa-solid fa-plus"></i> Create first category</button>
    </div>
  <?php else: ?>
    <div class="category-manager-list">
      <?php foreach ($catGroups as $group):
        $groupChildren = $catsByParent[(int)$group['id']] ?? [];
        $groupProductCount = $productCountsByCategory[(int)$group['id']] ?? 0;
        foreach ($groupChildren as $sub) $groupProductCount += $productCountsByCategory[(int)$sub['id']] ?? 0;
        $groupData = ['id' => (int)$group['id'], 'name' => $group['name']];
      ?>
        <section class="card category-group-card">
          <header class="category-group-header">
            <div class="category-group-identity">
              <span class="category-icon"><i class="fa-solid fa-folder"></i></span>
              <div>
                <h2><?= e($group['name']) ?></h2>
                <p><?= count($groupChildren) ?> subcategor<?= count($groupChildren) === 1 ? 'y' : 'ies' ?> · <?= number_format($groupProductCount) ?> product<?= $groupProductCount === 1 ? '' : 's' ?></p>
              </div>
            </div>
            <div class="category-actions">
              <button class="btn btn-sm btn-outline" type="button" onclick='openCategoryModal(null, <?= json_encode($groupData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="fa-solid fa-pen"></i> Rename</button>
              <button class="btn btn-sm btn-outline" type="button" onclick="openCategoryModal(<?= (int)$group['id'] ?>)"><i class="fa-solid fa-folder-plus"></i> Add subcategory</button>
              <form method="POST" onsubmit="return confirmCategoryDelete(this, <?= (int)$groupProductCount ?>)">
                <?= csrfField() ?><input type="hidden" name="_method" value="DELETE_CATEGORY"><input type="hidden" name="category_id" value="<?= (int)$group['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" <?= $groupChildren ? 'disabled title="Remove its subcategories before deleting this group"' : ($groupProductCount > 0 ? 'title="Deleting this category also deletes its products if they have no order history"' : '') ?>><i class="fa-solid fa-trash"></i> Delete</button>
              </form>
            </div>
          </header>
          <?php if ($groupChildren): ?>
            <div class="category-sub-list">
              <?php foreach ($groupChildren as $sub):
                $subCount = $productCountsByCategory[(int)$sub['id']] ?? 0;
                $subData = ['id' => (int)$sub['id'], 'name' => $sub['name']];
              ?>
                <div class="category-sub-row">
                  <div class="category-sub-identity"><i class="fa-solid fa-folder-open"></i>
                    <div><strong><?= e($sub['name']) ?></strong><span><?= number_format($subCount) ?> product<?= $subCount === 1 ? '' : 's' ?></span></div>
                  </div>
                  <div class="category-actions">
                    <a class="btn btn-sm btn-ghost" href="<?= e(APP_URL) ?>/admin/product-setup.php?category_id=<?= (int)$sub['id'] ?>"><i class="fa-solid fa-plus"></i> Add product</a>
                    <button class="btn btn-sm btn-outline" type="button" onclick='openCategoryModal(null, <?= json_encode($subData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="fa-solid fa-pen"></i> Rename</button>
                    <form method="POST" onsubmit="return confirmCategoryDelete(this, <?= (int)$subCount ?>)">
                      <?= csrfField() ?><input type="hidden" name="_method" value="DELETE_CATEGORY"><input type="hidden" name="category_id" value="<?= (int)$sub['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger" <?= $subCount > 0 ? 'title="Deleting this category also deletes its products if they have no order history"' : '' ?>><i class="fa-solid fa-trash"></i></button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="category-group-empty">No subcategories yet. Products can be assigned directly to this group, or add a subcategory.</div>
            <div class="category-group-footer"><a class="btn btn-sm btn-ghost" href="<?= e(APP_URL) ?>/admin/product-setup.php?category_id=<?= (int)$group['id'] ?>"><i class="fa-solid fa-plus"></i> Add product to this group</a></div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Drink size presets used by products that offer customer size choices. -->
<div id="ordering-tab-sizes" style="<?= $activeSubtab === 'sizes' ? '' : 'display:none' ?>">
  <style>
    .size-summary-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 18px
    }

    .size-summary {
      padding: 16px 18px;
      display: flex;
      align-items: center;
      gap: 12px
    }

    .size-summary-icon {
      width: 40px;
      height: 40px;
      display: grid;
      place-items: center;
      border-radius: 12px;
      background: var(--surface-raised);
      color: var(--primary-color);
      flex: none
    }

    .size-summary>div>span {
      display: block;
      color: var(--text-muted);
      font-size: .78rem
    }

    .size-summary strong {
      display: block;
      font-size: 1.35rem;
      line-height: 1.2;
      margin-top: 2px
    }

    .size-help {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 13px 16px;
      border-radius: var(--radius-md);
      background: var(--surface-raised);
      color: var(--text-muted);
      font-size: .83rem;
      margin-bottom: 16px
    }

    .size-help i {
      color: var(--primary-color);
      margin-top: 2px
    }

    .size-actions {
      display: flex;
      align-items: center;
      gap: 7px;
      white-space: nowrap
    }

    @media(max-width:760px) {
      .size-summary-grid {
        grid-template-columns: 1fr
      }
    }
  </style>
  <div class="ordering-section-head">
    <div>
      <h2>Drink size options</h2>
      <p>Customers choose these menu sizes. Stock units such as L, kg, and pcs are set separately in Inventory. Price adjustments apply to every product that offers sizes.</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick="openSizeModal()">
        <i class="fa-solid fa-plus"></i> Add Drink Size
      </button>
    </div>
  </div>
  <div class="size-summary-grid">
    <div class="card size-summary"><span class="size-summary-icon"><i class="fa-solid fa-ruler-combined"></i></span>
      <div><span>Size options</span><strong><?= count($sizeOptions) ?></strong></div>
    </div>
    <div class="card size-summary"><span class="size-summary-icon" style="color:var(--color-success)"><i class="fa-solid fa-circle-check"></i></span>
      <div><span>Shown to customers</span><strong><?= $activeSizeCount ?></strong></div>
    </div>
    <div class="card size-summary"><span class="size-summary-icon" style="color:var(--text-muted)"><i class="fa-solid fa-eye-slash"></i></span>
      <div><span>Hidden</span><strong><?= count($sizeOptions) - $activeSizeCount ?></strong></div>
    </div>
  </div>
  <div class="size-help"><i class="fa-solid fa-circle-info"></i><span>Price adjustments are added to a product’s base price for each selected size. Inactive sizes stay saved but are hidden from customers.</span></div>
  <div class="card">
    <div class="card-header">
      <div class="card-title">Drink sizes</div>
      <div class="text-muted" style="font-size:.82rem">Sort order controls the order shown during checkout.</div>
    </div>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Drink Size</th>
            <th>Price adjustment</th>
            <th>Sort Order</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sizeOptions)): ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:36px;color:var(--text-muted)"><i class="fa-solid fa-ruler-combined" style="font-size:1.6rem;display:block;margin-bottom:8px;color:var(--primary-color)"></i>No sizes yet. Add the choices customers can select, such as 16oz or 22oz.</td>
            </tr>
            <?php else: foreach ($sizeOptions as $s): ?>
              <tr>
                <td><strong><?= e($s['label']) ?></strong></td>
                <td><strong><?= $s['price_adjustment'] > 0 ? '+' . peso($s['price_adjustment']) : peso($s['price_adjustment']) ?></strong></td>
                <td><?= (int) $s['sort_order'] ?></td>
                <td><span class="badge badge-<?= $s['is_active'] ? 'paid' : 'cancelled' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td>
                  <div class="size-actions">
                    <button type="button" class="btn btn-sm btn-outline" onclick='openSizeModal(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="fa-solid fa-pen"></i> Edit</button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this size option? Products using this size will no longer offer it.')">
                      <?= csrfField() ?>
                      <input type="hidden" name="size_action" value="delete">
                      <input type="hidden" name="size_id" value="<?= $s['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger" aria-label="Delete <?= e($s['label']) ?>"><i class="fa-solid fa-trash"></i></button>
                    </form>
                  </div>
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
        <div class="form-group"><label class="form-label" for="size-form-adj">Price adjustment (₱)</label><input type="number" name="price_adjustment" id="size-form-adj" class="form-control" step="0.50" value="0" required><small class="text-muted">Added to the product’s base price when this size is chosen.</small></div>
        <div class="form-group"><label class="form-label" for="size-form-sort">Display order</label><input type="number" name="sort_order" id="size-form-sort" class="form-control" value="0"><small class="text-muted">Lower numbers appear first in checkout.</small></div>
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
      <style>
        .addons-catalog-note {
          display: flex;
          align-items: flex-start;
          gap: 9px;
          padding: 12px 14px;
          background: var(--surface-raised);
          border-radius: var(--radius-md);
          color: var(--text-muted);
          font-size: .82rem;
          margin-bottom: 14px
        }

        .addons-catalog-note i {
          color: var(--primary-color);
          margin-top: 2px
        }

        .addons-catalog-toolbar {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 12px;
          margin-bottom: 12px
        }

        .addons-catalog-search {
          position: relative;
          flex: 1;
          max-width: 360px
        }

        .addons-catalog-search i {
          position: absolute;
          left: 12px;
          top: 50%;
          transform: translateY(-50%);
          color: var(--text-muted)
        }

        .addons-catalog-search input {
          padding-left: 36px
        }

        @media(max-width:600px) {
          .addons-catalog-toolbar {
            align-items: stretch;
            flex-direction: column
          }

          .addons-catalog-search {
            max-width: none
          }
        }
      </style>
      <div class="addons-catalog-note"><i class="fa-solid fa-circle-info"></i><span>Add-ons use shared stock managed in <a href="<?= APP_URL ?>/admin/inventory.php" style="font-weight:700">Inventory</a>. Open a product’s setup to choose which add-ons it offers.</span></div>
      <div class="addons-catalog-toolbar">
        <label class="addons-catalog-search"><i class="fa-solid fa-magnifying-glass"></i><input id="addons-list-search" type="search" class="form-control" aria-label="Search add-ons" placeholder="Search add-ons or stock items" autocomplete="off"></label>
        <span class="text-muted" id="addons-list-count" style="font-size:.82rem">Loading add-ons…</span>
      </div>

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
      <input type="hidden" name="category_id" id="category-id" value="">

      <div class="modal-body">
        <p class="text-muted" id="category-form-help" style="font-size:.85rem;line-height:1.5;margin-top:0">Create a menu group or place it under an existing top-level category. Add products after the category is saved.</p>
        <div class="form-group" id="category-parent-row"><label class="form-label" for="category-parent">Parent category <span class="text-muted">(optional)</span></label><select name="parent_id" id="category-parent" class="form-control">
            <option value="">No parent — create a top-level category</option><?php foreach ($catGroups as $group): ?><option value="<?= (int) $group['id'] ?>">Under <?= e($group['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label" for="category-name">Category name <span style="color:var(--status-cancelled)">*</span></label><input type="text" name="category_name" id="category-name" class="form-control" maxlength="100" required placeholder="e.g. Coffee, Iced Drinks"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCategoryModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="category-submit"><i class="fa-solid fa-floppy-disk"></i> Create category</button>
      </div>
    </form>
  </div>
</div>

<script>
  function e(value) {
    const node = document.createElement('span');
    node.textContent = value ?? '';
    return node.innerHTML;
  }
  const productSearch = document.getElementById('product-search');
  if (productSearch) {
    const productCells = Array.from(document.querySelectorAll('.product-cell[data-product-search]'));
    const resultCount = document.getElementById('product-result-count');
    const emptySearch = document.getElementById('product-search-empty');
    productSearch.addEventListener('input', () => {
      const query = productSearch.value.trim().toLowerCase();
      let visible = 0;
      productCells.forEach(cell => {
        const matches = cell.dataset.productSearch.includes(query);
        cell.closest('tr').hidden = !matches;
        if (matches) visible++;
      });
      if (emptySearch) emptySearch.hidden = query === '' || visible > 0;
      if (resultCount) resultCount.textContent = query ?
        `${visible} of ${productCells.length} products match` :
        `${productCells.length} product${productCells.length === 1 ? '' : 's'} shown`;
    });
  }

  function handleToggle(input) {
    if (!confirm('Change product availability?')) {
      input.checked = !input.checked;
      return;
    }
    input.disabled = true;
    input.form.submit();
  }

  function openAddonsListModal() {
    document.getElementById('addons-list-modal').classList.remove('hidden');
    loadAddonsList();
  }

  function closeAddonsListModal() {
    document.getElementById('addons-list-modal').classList.add('hidden');
  }
  document.getElementById('addons-list-modal').addEventListener('click', event => {
    if (event.target === event.currentTarget) closeAddonsListModal();
  });

  function loadAddonsList() {
    const body = document.getElementById('addons-list-body');
    const search = document.getElementById('addons-list-search');
    if (search) search.value = '';
    body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading…</td></tr>';
    fetch('<?= APP_URL ?>/api/get_addons.php').then(response => response.json()).then(data => {
      const count = document.getElementById('addons-list-count');
      if (!data.success || !data.addons.length) {
        if (count) count.textContent = '0 add-ons';
        body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fa-solid fa-layer-group" style="display:block;font-size:1.4rem;margin-bottom:8px;color:var(--primary-color)"></i>No stocked add-ons yet. Add an add-on from Inventory.</td></tr>';
        return;
      }
      if (count) count.textContent = `${data.addons.length} add-on${data.addons.length===1?'':'s'}`;
      body.innerHTML = data.addons.map(addon => {
        const active = addon.status === 'active';
        const searchText = [addon.name, addon.inventory_name || ''].join(' ').toLowerCase();
        return `<tr data-addon-search="${e(searchText)}"><td><strong>${e(addon.name)}</strong></td><td>₱${Number(addon.price).toFixed(2)}</td><td>${e(addon.inventory_name||'—')} <span class="text-muted">(${Number(addon.qty_per_unit||0).toFixed(3)} ${e(addon.inventory_unit||'')})</span></td><td><span class="badge ${active?'badge-ready':'badge-cancelled'}">${e(addon.status)}</span></td></tr>`;
      }).join('');
      applyAddonSearch();
    }).catch(() => {
      document.getElementById('addons-list-count').textContent = 'Could not load';
      body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:1rem;color:var(--status-cancelled)">Could not load add-ons. Try again.</td></tr>';
    });
  }

  function applyAddonSearch() {
    const search = document.getElementById('addons-list-search');
    const body = document.getElementById('addons-list-body');
    if (!search || !body) return;
    const query = search.value.trim().toLowerCase();
    const rows = Array.from(body.querySelectorAll('tr[data-addon-search]'));
    let visible = 0;
    rows.forEach(row => {
      row.hidden = !row.dataset.addonSearch.includes(query);
      if (!row.hidden) visible++;
    });
    const count = document.getElementById('addons-list-count');
    if (count) count.textContent = query ? `${visible} of ${rows.length} add-ons match` : `${rows.length} add-on${rows.length===1?'':'s'}`;
    let empty = body.querySelector('[data-addon-empty]');
    if (!empty) {
      empty = document.createElement('tr');
      empty.dataset.addonEmpty = '1';
      empty.innerHTML = '<td colspan="4" style="text-align:center;padding:1.5rem;color:var(--text-muted)">No add-ons match this search.</td>';
      body.appendChild(empty);
    }
    empty.hidden = query === '' || visible > 0;
  }
  document.getElementById('addons-list-search').addEventListener('input', applyAddonSearch);
</script>

<script>
  function openCategoryModal(parentId = '', category = null) {
    const form = document.getElementById('category-form');
    form.reset();
    const isEditing = !!category;
    document.getElementById('category-id').value = isEditing ? category.id : '';
    document.getElementById('category-parent-row').style.display = isEditing ? 'none' : '';
    document.getElementById('category-modal').querySelector('.modal-title').innerHTML = isEditing ?
      '<i class="fa-solid fa-pen"></i> Rename Menu Category' :
      '<i class="fa-solid fa-folder-plus"></i> Add Menu Category';
    document.getElementById('category-form-help').textContent = isEditing ?
      'Renaming keeps every product assigned to this category.' :
      'Create a menu group or place it under an existing top-level category.';
    document.getElementById('category-submit').innerHTML = isEditing ?
      '<i class="fa-solid fa-floppy-disk"></i> Save changes' :
      '<i class="fa-solid fa-floppy-disk"></i> Create category';
    document.getElementById('category-parent').value = parentId || '';
    if (isEditing) document.getElementById('category-name').value = category.name;
    document.getElementById('category-modal').classList.remove('hidden');
    document.getElementById('category-name').focus();
  }

  function confirmCategoryDelete(form, productCount) {
    const products = productCount === 1 ? '1 product' : `${productCount} products`;
    const impact = productCount > 0 ? ` This also deletes its ${products} if they have no sales history.` : '';
    return confirm(`Delete this category?${impact} Categories with products in past orders cannot be deleted.`);
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
    document.getElementById('ordering-tab-categories').style.display = tab === 'categories' ? '' : 'none';
    document.getElementById('ordering-tab-sizes').style.display = tab === 'sizes' ? '' : 'none';
    document.querySelectorAll('#orderingSubTabs .tab-btn[data-subtab]').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.subtab === tab);
    });
    const url = new URL(window.location.href);
    if (tab === 'products') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
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
      document.getElementById('size-form-sort').value = <?= (int)$nextSizeSortOrder ?>;
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