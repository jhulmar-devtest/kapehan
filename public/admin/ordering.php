<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);

$db = Database::getInstance();

// Which sub-tab to show on load. Size saves below redirect back with
// ?tab=sizes specifically so admins adding several sizes in a row don't
// get bounced back to the Products tab after every single save.
$activeSubtab = ($_GET['tab'] ?? '') === 'sizes' ? 'sizes' : 'products';

// If the last save_product submission failed validation, this holds what
// the admin typed so the modal can reopen pre-filled instead of blank.
$oldProductInput = getOldInput();

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
    redirect(APP_URL . '/admin/ordering.php');
  }

  // ---- Handle image upload ----
  $newImageName = null; // null means "no change"

  if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
    $file     = $_FILES['product_image'];
    $maxSize  = UPLOAD_MAX_SIZE; // 2MB defined in constants.php
    $allowed  = ['image/jpeg', 'image/png', 'image/webp'];

    // Validate file size
    if ($file['size'] > $maxSize) {
      flash('global', 'Image is too large. Maximum size is 2MB.', 'error');
      flashOldInput($_POST);
      redirect(APP_URL . '/admin/ordering.php');
    }

    // Validate MIME type using finfo (more reliable than just checking extension)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowed, true) || !getimagesize($file['tmp_name'])) {
      flash('global', 'Invalid image type. Only JPG, PNG, and WEBP are allowed.', 'error');
      flashOldInput($_POST);
      redirect(APP_URL . '/admin/ordering.php');
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
      redirect(APP_URL . '/admin/ordering.php');
    }

    // If editing, delete the OLD image so we don't accumulate unused files
    if ($pid > 0) {
      $stmt = $db->prepare("SELECT image_path FROM products WHERE id=?");
      $stmt->execute([$pid]);
      $old = $stmt->fetchColumn();
      if ($old && file_exists(UPLOAD_DIR . $old)) {
        unlink(UPLOAD_DIR . $old);
      }
    }
  }

  if ($pid > 0) {
    // Edit existing product
    if ($newImageName) {
      // Update with new image
      $db->prepare("UPDATE products SET name=?,category_id=?,price=?,description=?,image_path=?,has_sizes=?,has_sugar=?,spotlight_pinned=?,spotlight_pinned_until=? WHERE id=?")
        ->execute([$name, $catId, $price, $desc, $newImageName, $hasSizes, $hasSugar, $spotlightPinned, $spotlightUntil, $pid]);
    } else {
      // Keep the existing image
      $db->prepare("UPDATE products SET name=?,category_id=?,price=?,description=?,has_sizes=?,has_sugar=?,spotlight_pinned=?,spotlight_pinned_until=? WHERE id=?")
        ->execute([$name, $catId, $price, $desc, $hasSizes, $hasSugar, $spotlightPinned, $spotlightUntil, $pid]);
    }
    auditLog(ROLE_ADMIN, currentUserId(), 'edit_product', 'products', $pid);
    flash('global', 'Product updated.', 'success');
  } else {
    // Add new product
    $db->prepare("INSERT INTO products (name,category_id,price,description,image_path,has_sizes,has_sugar,spotlight_pinned,spotlight_pinned_until) VALUES (?,?,?,?,?,?,?,?,?)")
      ->execute([$name, $catId, $price, $desc, $newImageName, $hasSizes, $hasSugar, $spotlightPinned, $spotlightUntil]);
    $newId = (int)$db->lastInsertId();
    auditLog(ROLE_ADMIN, currentUserId(), 'add_product', 'products', $newId);
    flash('global', 'Product added.', 'success');
  }

  redirect(APP_URL . '/admin/ordering.php');
}

// ---- Handle ADD CATEGORY WITH PRODUCTS ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category_with_products'])) {
  verifyCsrf();

  $catName = sanitizeString($_POST['category_name'] ?? '');
  $parentId = null;

  // Handle parent category selection
  $parentSelection = $_POST['parent_id_select'] ?? '';
  $customParentName = sanitizeString($_POST['custom_parent_name'] ?? '');

  if (!empty($parentSelection) && $parentSelection !== 'custom') {
    // User selected an existing category
    $parentId = (int)$parentSelection;
  } elseif ($parentSelection === 'custom' && !empty($customParentName)) {
    // User wants to create a new top-level menu
    // Check if it exists
    $stmt = $db->prepare("SELECT id FROM categories WHERE name = ? AND parent_id IS NULL");
    $stmt->execute([$customParentName]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
      $parentId = $existing;
    } else {
      // Create the custom menu category
      $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM categories WHERE parent_id IS NULL");
      $sort = (int)$stmt->fetchColumn() + 1;
      $db->prepare("INSERT INTO categories (name, parent_id, sort_order) VALUES (?, NULL, ?)")
        ->execute([$customParentName, $sort]);
      $parentId = (int)$db->lastInsertId();
    }
  }

  // Require category name
  if (empty($catName)) {
    flash('global', 'Category Menu name is required.', 'error');
    redirect(APP_URL . '/admin/ordering.php');
  }

  // Get sort order for the new subcategory
  $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM categories WHERE parent_id = ?");
  $stmt->execute([$parentId]);
  $sortOrder = (int)$stmt->fetchColumn() + 1;

  // Insert category (allow empty name)
  $db->prepare("INSERT INTO categories (name, parent_id, sort_order) VALUES (?, ?, ?)")
    ->execute([$catName, $parentId, $sortOrder]);
  $catId = (int)$db->lastInsertId();
  auditLog(ROLE_ADMIN, currentUserId(), 'add_category', 'categories', $catId);

  // Now handle products
  $productNames = $_POST['product_name'] ?? [];
  if (empty(array_filter($productNames))) {
    flash('global', 'Please add at least one product.', 'error');
    redirect(APP_URL . '/admin/ordering.php');
  }
  $productPrices = $_POST['product_price'] ?? [];
  $productDescs = $_POST['product_desc'] ?? [];
  $numProducts = count($productNames);
  for ($i = 0; $i < $numProducts; $i++) {
    $pName = sanitizeString($productNames[$i] ?? '');
    $pPrice = round((float)($productPrices[$i] ?? 0), 2);
    $pDesc = sanitizeString($productDescs[$i] ?? '', 500);
    $pHasSizes = isset($_POST["product_has_sizes_$i"]) ? 1 : 0;
    $pHasSugar = isset($_POST["product_has_sugar_$i"]) ? 1 : 0;

    if (!empty($pName) && $pPrice > 0) {
      // Handle image for this product
      $pImageName = null;
      if (isset($_FILES['product_image']['name'][$i]) && $_FILES['product_image']['error'][$i] === UPLOAD_ERR_OK) {
        $file = [
          'name' => $_FILES['product_image']['name'][$i],
          'type' => $_FILES['product_image']['type'][$i],
          'tmp_name' => $_FILES['product_image']['tmp_name'][$i],
          'error' => $_FILES['product_image']['error'][$i],
          'size' => $_FILES['product_image']['size'][$i]
        ];
        $maxSize = UPLOAD_MAX_SIZE;
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if ($file['size'] > $maxSize) {
          flash('global', 'One of the images is too large (max 2MB).', 'error');
          redirect(APP_URL . '/admin/ordering.php');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, $allowed, true) || !getimagesize($file['tmp_name'])) {
          flash('global', 'One of the product images is invalid.', 'error');
          redirect(APP_URL . '/admin/ordering.php');
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mimeType];
        $pImageName = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $pImageName)) {
          $pImageName = null;
        }
      }

      $db->prepare("INSERT INTO products (name, category_id, price, description, image_path, has_sizes, has_sugar) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$pName, $catId, $pPrice, $pDesc, $pImageName, $pHasSizes, $pHasSugar]);
      $pId = (int)$db->lastInsertId();
      auditLog(ROLE_ADMIN, currentUserId(), 'add_product', 'products', $pId);
    }
  }

  flash('global', 'Category and products added successfully.', 'success');
  redirect(APP_URL . '/admin/ordering.php');
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
    <div class="page-header-sub">Manage what's for sale: products, add-ons, and size pricing</div>
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
    <i class="fa-solid fa-ruler"></i> Sizes &amp; Pricing
  </button>
</div>

<div id="ordering-tab-products" style="<?= $activeSubtab === 'products' ? '' : 'display:none' ?>">
  <div class="page-header">
    <div></div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick="openAddModal()">
        <i class="fa-solid fa-plus"></i> Add Product
      </button>
      <button class="btn btn-secondary" onclick="openCategoryModal()">
        <i class="fa-solid fa-plus"></i> Add Category
      </button>
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
          </tr>
        </thead>
        <tbody>
          <?php if (empty($products)): ?>
            <tr>
              <td colspan="4" style="text-align:center;padding:30px;color:var(--text-muted)">No products found.</td>
            </tr>
            <?php else: foreach ($products as $p): ?>
              <tr onclick="editProduct(<?= htmlspecialchars(json_encode($p)) ?>)"
                style="cursor:pointer">
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
                  </form>
                </td>
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

<!-- ══════════════ Sizes & Pricing sub-tab (item 13, new) ══════════════ -->
<div id="ordering-tab-sizes" style="<?= $activeSubtab === 'sizes' ? '' : 'display:none' ?>">
  <div class="page-header">
    <div>
      <div class="page-header-sub">Editable size upcharges — used by the size picker on every sized product.</div>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick="openSizeModal()">
        <i class="fa-solid fa-plus"></i> Add Size
      </button>
    </div>
  </div>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Label</th>
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
        <div class="form-group"><label class="form-label">Label</label><input type="text" name="label" id="size-form-label" class="form-control" required placeholder="e.g. 16oz"></div>
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

<!-- Hidden delete form — separate from the save form (forms cannot be nested) -->
<form method="POST" id="delete-form" onsubmit="return confirmDelete(document.getElementById('modal-delete-btn').dataset.name)">
  <?= csrfField() ?>
  <input type="hidden" name="_method" value="DELETE">
  <input type="hidden" name="product_id" id="delete-product-id" value="">
</form>

<!-- ===================== ADD / EDIT MODAL ===================== -->
<!--
  IMPORTANT: The form uses enctype="multipart/form-data"
  This is required whenever a form uploads a file.
  Without it, PHP never receives the uploaded file.
-->
<div class="modal-overlay hidden" id="product-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modal-title">
        <i class="fa-solid fa-plus"></i>Add Product
      </div>
      <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form method="POST" id="product-form" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="save_product" value="1">
      <input type="hidden" name="product_id" id="f-product-id" value="0">

      <div class="modal-body">

        <div class="form-group">
          <label class="form-label">Product Name <span style="color:var(--status-cancelled)">*</span></label>
          <input type="text" name="name" id="f-name" class="form-control" required placeholder="e.g. Café Latte">
        </div>

        <div class="form-group">
          <label class="form-label">Category <span style="color:var(--status-cancelled)">*</span></label>
          <select name="category_id" id="f-category" class="form-control" required>
            <?php foreach ($catGroups as $group): ?>
              <?php if (!empty($catsByParent[$group['id']])): ?>
                <optgroup label="── <?= e($group['name']) ?>">
                  <?php foreach ($catsByParent[$group['id']] as $sub): ?>
                    <option value="<?= $sub['id'] ?>"><?= e($sub['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php else: ?>
                <option value="<?= $group['id'] ?>"><?= e($group['name']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Price (₱) <span style="color:var(--status-cancelled)">*</span></label>
          <input type="number" name="price" id="f-price" class="form-control"
            required min="0.5" step="0.50" placeholder="0.00">
        </div>

        <div class="form-group">
          <label class="form-label">Description (optional)</label>
          <textarea name="description" id="f-desc" class="form-control" rows="2"
            placeholder="Brief description…"></textarea>
        </div>

        <!-- Image upload with live preview -->
        <div class="form-group">
          <label class="form-label">Product Image (optional — JPG, PNG, WEBP, max 2MB)</label>

          <!-- Current image preview (shown when editing a product that already has an image) -->
          <div id="current-img-wrap" style="display:none;margin-bottom:10px">
            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px">Current image:</div>
            <img id="current-img" src="" alt="Current"
              style="width:80px;height:80px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border-color)">
          </div>

          <!-- New image preview (shown after the user picks a file) -->
          <div id="new-img-wrap" style="display:none;margin-bottom:10px">
            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px">New image preview:</div>
            <img id="new-img-preview" src="" alt="Preview"
              style="width:80px;height:80px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border-color)">
          </div>

          <input type="file" name="product_image" id="f-image"
            class="form-control" accept="image/jpeg,image/png,image/webp"
            onchange="previewImage(this)">
          <div style="font-size:.74rem;color:var(--text-muted);margin-top:5px">
            Leave blank to keep the existing image when editing.
          </div>
        </div>

        <!-- Customization options -->
        <div class="form-group">
          <label class="form-label">Customization Options</label>
          <div style="display:flex;flex-direction:column;gap:var(--space-2);margin-top:4px">
            <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;font-size:0.84rem">
              <input type="checkbox" name="has_sizes" id="f-has-sizes" value="1"
                style="width:16px;height:16px;accent-color:var(--primary-color);cursor:pointer">
              <span>
                <strong>Sizes</strong>
                <span style="color:var(--text-muted);font-weight:400"> — 16oz / 22oz (+₱<?= SIZE_22OZ_UPCHARGE ?>)</span>
              </span>
            </label>
            <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;font-size:0.84rem">
              <input type="checkbox" name="has_sugar" id="f-has-sugar" value="1"
                style="width:16px;height:16px;accent-color:var(--primary-color);cursor:pointer">
              <span>
                <strong>Sugar level</strong>
                <span style="color:var(--text-muted);font-weight:400"> — Full / Less / 50% / No Sugar</span>
              </span>
            </label>
          </div>
          <!-- Product Add-ons Manager -->
          <div style="margin-top:var(--space-3)">
            <button type="button" class="btn btn-sm btn-outline" id="manage-addons-btn" onclick="openManageAddonsModal()" style="display:none">
              <i class="fa-solid fa-sliders"></i> Manage Add-ons
            </button>
            <div id="addons-count-display" style="font-size:0.78rem;color:var(--text-muted);margin-top:6px;display:none">
              <span id="addons-count">0</span> add-on(s) assigned to this product
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Homepage Spotlight</label>
          <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;font-size:0.84rem;margin-top:4px">
            <input type="checkbox" name="spotlight_pinned" id="f-spotlight-pinned" value="1"
              style="width:16px;height:16px;accent-color:var(--primary-color);cursor:pointer"
              onchange="document.getElementById('spotlight-until-wrap').style.display = this.checked ? 'block' : 'none'">
            <span>
              <strong>Pin to Spotlight</strong>
              <span style="color:var(--text-muted);font-weight:400"> — forces this into the homepage "Today's Picks" carousel</span>
            </span>
          </label>
          <div id="spotlight-until-wrap" style="display:none;margin-top:8px">
            <label class="form-label" style="font-weight:400;font-size:0.8rem">Auto-unpin on (optional)</label>
            <input type="date" name="spotlight_pinned_until" id="f-spotlight-until" class="form-control">
            <div style="font-size:.74rem;color:var(--text-muted);margin-top:5px">
              Leave blank to keep it pinned until you uncheck the box yourself.
            </div>
          </div>
        </div>

      </div><!-- /modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn btn-danger" id="modal-delete-btn"
          style="display:none;margin-right:auto"
          onclick="submitDelete()">
          <i class="fa-solid fa-trash"></i> Delete
        </button>
        <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <i class="fa-solid fa-floppy-disk"></i> Save Product
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== MANAGE ADD-ONS MODAL ===================== -->
<div class="modal-overlay hidden" id="manage-addons-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">
        <i class="fa-solid fa-sliders"></i> Manage Add-ons for Product
      </div>
      <button class="modal-close" onclick="closeManageAddonsModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="modal-body">
      <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:1rem">
        Select which add-ons are available for this product. All add-ons are optional — customers must manually select them.
      </p>

      <div id="manage-addons-list" style="display:flex;flex-direction:column;gap:var(--space-2);max-height:400px;overflow-y:auto">
        <!-- Add-ons checkboxes will be loaded here -->
        <div style="text-align:center;padding:2rem;color:var(--text-muted)">
          <i class="fa-solid fa-circle-notch fa-spin" style="font-size:1.5rem"></i>
          <div style="margin-top:0.5rem;font-size:0.85rem">Loading add-ons...</div>
        </div>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeManageAddonsModal()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="saveProductAddons()">
        <i class="fa-solid fa-floppy-disk"></i> Save Add-ons
      </button>
    </div>
  </div>
</div>

<!-- ===================== ADD ADD-ON MODAL ===================== -->
<div class="modal-overlay hidden" id="add-addon-modal" style="z-index:1100">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">
        <i class="fa-solid fa-plus"></i> Add New Add-on
      </div>
      <button class="modal-close" onclick="closeAddAddonModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="modal-body">
      <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1rem">
        Adding several? This stays open after each save so you can keep going — click Done when you're finished.
      </p>
      <div class="form-group">
        <label class="form-label">Add-on Name <span style="color:var(--status-cancelled)">*</span></label>
        <input type="text" id="new-addon-name" class="form-control" placeholder="e.g. Extra Shot, Cheese, Pearl">
      </div>

      <div class="form-group">
        <label class="form-label">Price (₱) <span style="color:var(--status-cancelled)">*</span></label>
        <input type="number" id="new-addon-price" class="form-control" min="0" step="0.50" placeholder="0.00"
          onkeydown="if(event.key==='Enter'){event.preventDefault();saveNewAddon();}">
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeAddAddonModal()">Done</button>
      <button type="button" class="btn btn-primary" onclick="saveNewAddon()">
        <i class="fa-solid fa-floppy-disk"></i> Save &amp; Add Another
      </button>
    </div>
  </div>
</div>

<!-- ===================== ADD-ONS LIST MODAL ===================== -->
<div class="modal-overlay hidden" id="addons-list-modal">
  <div class="modal" style="max-width:800px;width:95vw;">
    <div class="modal-header">
      <div class="modal-title">
        <i class="fa-solid fa-layer-group"></i> Manage Add-ons
      </div>
      <button class="modal-close" onclick="closeAddonsListModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="modal-body">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <p style="font-size:0.85rem;color:var(--text-muted);margin:0">
          Create and manage add-ons that can be assigned to products.
        </p>
        <button type="button" class="btn btn-sm btn-primary" onclick="openAddAddonModal()">
          <i class="fa-solid fa-plus"></i> Add Add-on
        </button>
      </div>

      <div id="addons-list-table" style="overflow-x:auto">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Price</th>
              <th>Status</th>
              <th style="width:100px">Actions</th>
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

<!-- ===================== ADD CATEGORY WITH PRODUCTS MODAL ===================== -->
<div class="modal-overlay hidden" id="category-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">
        <i class="fa-solid fa-plus"></i> Add Category with Products
      </div>
      <button class="modal-close" onclick="closeCategoryModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form method="POST" id="category-form" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="save_category_with_products" value="1">

      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="parent_id_select" id="menu-select" class="form-control" onchange="updateMenuSelection()">
            <option value="">-- Create Category --</option>
            <?php foreach ($catGroups as $group): ?>
              <option value="<?= $group['id'] ?>"><?= e($group['name']) ?></option>
            <?php endforeach; ?>
            <option value="custom">+ New Category</option>
          </select>
          <input type="text" name="custom_parent_name" id="custom-parent-input" class="form-control"
            placeholder="Enter new menu category name..." style="display:none;margin-top:8px">
          <input type="hidden" name="parent_id_hidden" id="parent-id-hidden" value="">
        </div>

        <div class="form-group">
          <label class="form-label">Sub Category <span style="color:var(--status-cancelled)">*</span></label>
          <input type="text" name="category_name" class="form-control" placeholder="e.g. Coffee, Breakfast, Pasta">
        </div>

        <hr style="margin:20px 0;border:none;border-top:1px solid var(--border-color)">

        <div style="margin-bottom:15px">
          <strong>Add Products to this Category</strong>
          <button type="button" class="btn btn-sm btn-outline" onclick="addProductField()" style="margin-left:10px">
            <i class="fa-solid fa-plus"></i> Add Product
          </button>
        </div>

        <div id="products-container">
          <!-- Product fields will be added here -->
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCategoryModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <i class="fa-solid fa-floppy-disk"></i> Save Category & Products
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  const imgBase = '<?= $imgBase ?>';

  // HTML escape helper function
  function e(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function openAddModal() {
    // Reset form for a fresh Add
    document.getElementById('f-product-id').value = '0';
    document.getElementById('f-name').value = '';
    document.getElementById('f-price').value = '';
    document.getElementById('f-desc').value = '';
    document.getElementById('f-image').value = '';
    document.getElementById('f-category').selectedIndex = 0;
    document.getElementById('current-img-wrap').style.display = 'none';
    document.getElementById('new-img-wrap').style.display = 'none';
    document.getElementById('modal-delete-btn').style.display = 'none';
    document.getElementById('f-has-sizes').checked = false;
    document.getElementById('f-has-sugar').checked = false;
    document.getElementById('f-spotlight-pinned').checked = false;
    document.getElementById('f-spotlight-until').value = '';
    document.getElementById('spotlight-until-wrap').style.display = 'none';
    document.getElementById('manage-addons-btn').style.display = 'none';
    document.getElementById('addons-count-display').style.display = 'none';
    document.getElementById('modal-title').innerHTML =
      '<i class="fa-solid fa-plus"></i> Add Product';
    document.getElementById('product-modal').classList.remove('hidden');
  }

  function closeModal() {
    document.getElementById('product-modal').classList.add('hidden');
  }

  let currentEditProductId = null;
  let currentEditProductHasAddons = false;

  function editProduct(p) {
    currentEditProductId = p.id;
    currentEditProductHasAddons = !!parseInt(p.has_addons);

    document.getElementById('f-product-id').value = p.id;
    document.getElementById('f-name').value = p.name;
    document.getElementById('f-category').value = p.category_id;
    document.getElementById('f-price').value = p.price;
    document.getElementById('f-desc').value = p.description || '';
    document.getElementById('f-image').value = ''; // clear file input
    document.getElementById('new-img-wrap').style.display = 'none';

    // Show the existing image if there is one
    if (p.image_path) {
      document.getElementById('current-img').src = imgBase + p.image_path;
      document.getElementById('current-img-wrap').style.display = 'block';
    } else {
      document.getElementById('current-img-wrap').style.display = 'none';
    }

    // Wire delete button to this product
    document.getElementById('delete-product-id').value = p.id;
    document.getElementById('modal-delete-btn').style.display = '';
    document.getElementById('modal-delete-btn').dataset.name = p.name;

    // Customization flags
    document.getElementById('f-has-sizes').checked = !!parseInt(p.has_sizes);
    document.getElementById('f-has-sugar').checked = !!parseInt(p.has_sugar);

    // Spotlight pin (p.spotlight_pinned/_until may be undefined if the
    // migration hasn't been run yet — default to unpinned in that case)
    const isPinned = !!parseInt(p.spotlight_pinned || 0);
    document.getElementById('f-spotlight-pinned').checked = isPinned;
    document.getElementById('spotlight-until-wrap').style.display = isPinned ? 'block' : 'none';
    document.getElementById('f-spotlight-until').value = p.spotlight_pinned_until
      ? p.spotlight_pinned_until.substring(0, 10)
      : '';

    // Show manage add-ons button if product exists
    const manageBtn = document.getElementById('manage-addons-btn');
    const addonsCountDisplay = document.getElementById('addons-count-display');
    if (p.id > 0) {
      manageBtn.style.display = 'inline-flex';
      addonsCountDisplay.style.display = 'block';
      // Load current add-ons count
      loadProductAddonsCount(p.id);
    } else {
      manageBtn.style.display = 'none';
      addonsCountDisplay.style.display = 'none';
    }

    document.getElementById('modal-title').innerHTML =
      '<i class="fa-solid fa-pen"></i> Edit Product';
    document.getElementById('product-modal').classList.remove('hidden');
  }

  function loadProductAddonsCount(productId) {
    fetch(`<?= APP_URL ?>/api/get_addons.php?product_id=${productId}`)
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          const checkedCount = data.addons.filter(a => a.is_checked).length;
          document.getElementById('addons-count').textContent = checkedCount;
        }
      })
      .catch(err => console.error('Failed to load add-ons count:', err));
  }

  function submitDelete() {
    const name = document.getElementById('modal-delete-btn').dataset.name || '';
    if (!confirmDelete(name)) return;
    document.getElementById('delete-form').submit();
  }

  function confirmDelete(name) {
    const label = name ? `Delete "${name}"?` : 'Delete this product?';
    return confirm(label + ' This cannot be undone.');
  }

  function handleToggle(el) {
    if (!confirm("Change product availability?")) {
      el.checked = !el.checked;
      return;
    }
    el.disabled = true;
    el.form.submit();
  }

  // Show a preview of the newly chosen image before saving
  function previewImage(input) {
    const wrap = document.getElementById('new-img-wrap');
    const preview = document.getElementById('new-img-preview');
    if (input.files && input.files[0]) {
      const reader = new FileReader();
      reader.onload = e => {
        preview.src = e.target.result;
        wrap.style.display = 'block';
      };
      reader.readAsDataURL(input.files[0]);
    } else {
      wrap.style.display = 'none';
    }
  }

  // Close modal when clicking outside it
  document.getElementById('product-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
  });

  // ===================== ADD-ON MANAGEMENT =====================

  function openManageAddonsModal() {
    if (!currentEditProductId) return;

    document.getElementById('manage-addons-modal').classList.remove('hidden');
    loadManageAddonsList(currentEditProductId);
  }

  function closeManageAddonsModal() {
    document.getElementById('manage-addons-modal').classList.add('hidden');
  }

  function loadManageAddonsList(productId) {
    const container = document.getElementById('manage-addons-list');
    container.innerHTML = `
      <div style="text-align:center;padding:2rem;color:var(--text-muted)">
        <i class="fa-solid fa-circle-notch fa-spin" style="font-size:1.5rem"></i>
        <div style="margin-top:0.5rem;font-size:0.85rem">Loading add-ons...</div>
      </div>
    `;

    fetch(`<?= APP_URL ?>/api/get_addons.php?product_id=${productId}`)
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--status-cancelled)">Failed to load add-ons</div>';
          return;
        }

        if (data.addons.length === 0) {
          container.innerHTML = `
            <div style="text-align:center;padding:2rem;color:var(--text-muted)">
              <i class="fa-solid fa-inbox" style="font-size:2rem;opacity:0.3;margin-bottom:0.5rem"></i>
              <div style="font-size:0.85rem">No add-ons available yet.</div>
              <button type="button" class="btn btn-sm btn-primary" style="margin-top:0.5rem" onclick="closeManageAddonsModal();openAddAddonModal()">
                <i class="fa-solid fa-plus"></i> Create First Add-on
              </button>
            </div>
          `;
          return;
        }

        let html = '';
        data.addons.forEach(addon => {
          html += `
            <label style="display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3);border:1.5px solid var(--border-color);border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition-fast);${addon.is_checked ? 'border-color:var(--primary-color);background:var(--primary-subtle)' : 'background:var(--surface-color)'}"
              onmouseover="this.style.borderColor='var(--primary-color)'"
              onmouseout="this.style.borderColor='${addon.is_checked ? 'var(--primary-color)' : 'var(--border-color)'}'">
              <input type="checkbox" class="addon-cb" data-addon-id="${addon.id}" value="1" ${addon.is_checked ? 'checked' : ''}
                style="width:18px;height:18px;accent-color:var(--primary-color);cursor:pointer">
              <span style="flex:1;font-size:0.9rem;font-weight:500">${e(addon.name)}</span>
              <span style="font-size:0.85rem;font-weight:700;color:var(--primary-color)">+₱${parseFloat(addon.price).toFixed(2)}</span>
            </label>
          `;
        });
        container.innerHTML = html;
      })
      .catch(err => {
        console.error('Failed to load add-ons:', err);
        container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--status-cancelled)">Failed to load add-ons</div>';
      });
  }

  function saveProductAddons() {
    const productId = currentEditProductId;
    const addonIds = [];
    document.querySelectorAll('.addon-cb:checked').forEach(cb => {
      addonIds.push(cb.dataset.addonId);
    });

    const formData = new FormData();
    formData.append('product_id', productId);
    formData.append('addon_ids', JSON.stringify(addonIds));
    formData.append('csrf_token', '<?= csrfToken() ?>');

    fetch('<?= APP_URL ?>/api/save_product_addons.php', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          document.getElementById('addons-count').textContent = addonIds.length;
          closeManageAddonsModal();
          showToast('Add-ons updated successfully');
        } else {
          showToast('Failed to save: ' + (data.error || 'Unknown error'), 'error');
        }
      })
      .catch(err => {
        console.error('Save failed:', err);
        showToast('Failed to save add-ons', 'error');
      });
  }

  function openAddAddonModal() {
    document.getElementById('new-addon-name').value = '';
    document.getElementById('new-addon-price').value = '';
    document.getElementById('add-addon-modal').classList.remove('hidden');
  }

  function closeAddAddonModal() {
    document.getElementById('add-addon-modal').classList.add('hidden');
  }

  document.getElementById('add-addon-modal').addEventListener('click', function(e) {
    if (e.target === this) closeAddAddonModal();
  });

  function saveNewAddon() {
    const name = document.getElementById('new-addon-name').value.trim();
    const price = parseFloat(document.getElementById('new-addon-price').value) || 0;

    if (!name) {
      showToast('Please enter an add-on name', 'warning');
      return;
    }

    const formData = new FormData();
    formData.append('name', name);
    formData.append('price', price);
    formData.append('csrf_token', '<?= csrfToken() ?>');

    fetch('<?= APP_URL ?>/api/save_addon.php', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          showToast('Add-on "' + name + '" added — add another or close when done');
          // Stay open and reset for the next one, instead of closing —
          // adding several add-ons in a row (Extra Shot, Pearl, Cheese...)
          // shouldn't mean reopening this modal every single time.
          document.getElementById('new-addon-name').value = '';
          document.getElementById('new-addon-price').value = '';
          document.getElementById('new-addon-name').focus();
          // If manage addons modal is open, refresh it
          if (!document.getElementById('manage-addons-modal').classList.contains('hidden')) {
            loadManageAddonsList(currentEditProductId);
          }
          // If addons list modal is open, refresh it
          if (!document.getElementById('addons-list-modal').classList.contains('hidden')) {
            loadAddonsList();
          }
        } else {
          showToast('Failed to save: ' + (data.error || 'Unknown error'), 'error');
        }
      })
      .catch(err => {
        console.error('Save failed:', err);
        showToast('Failed to save add-on', 'error');
      });
  }

  function openAddonsListModal() {
    document.getElementById('addons-list-modal').classList.remove('hidden');
    loadAddonsList();
  }

  function closeAddonsListModal() {
    document.getElementById('addons-list-modal').classList.add('hidden');
  }

  document.getElementById('addons-list-modal').addEventListener('click', function(e) {
    if (e.target === this) closeAddonsListModal();
  });

  function loadAddonsList() {
    const tbody = document.getElementById('addons-list-body');
    tbody.innerHTML = `
      <tr>
        <td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted)">
          <i class="fa-solid fa-circle-notch fa-spin" style="font-size:1.5rem"></i>
          <div style="margin-top:0.5rem;font-size:0.85rem">Loading...</div>
        </td>
      </tr>
    `;

    fetch('<?= APP_URL ?>/api/get_addons.php')
      .then(r => r.json())
      .then(data => {
        if (!data.success || data.addons.length === 0) {
          tbody.innerHTML = `
            <tr>
              <td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted)">
                <i class="fa-solid fa-inbox" style="font-size:2rem;opacity:0.3;margin-bottom:0.5rem"></i>
                <div style="font-size:0.85rem">No add-ons yet. Click "Add Add-on" to create one.</div>
              </td>
            </tr>
          `;
          return;
        }

        let html = '';
        data.addons.forEach(addon => {
          html += `
            <tr>
              <td><strong>${e(addon.name)}</strong></td>
              <td>₱${parseFloat(addon.price).toFixed(2)}</td>
              <td><span style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;background:rgba(5,150,105,0.1);color:var(--status-ready);padding:2px 8px;border-radius:var(--radius-full)">${addon.status}</span></td>
              <td>
                <button type="button" class="btn btn-sm btn-ghost" onclick="toggleAddonStatus(${addon.id}, '${addon.status}')" title="${addon.status === 'active' ? 'Deactivate' : 'Activate'}">
                  <i class="fa-solid fa-${addon.status === 'active' ? 'pause' : 'play'}"></i>
                </button>
              </td>
            </tr>
          `;
        });
        tbody.innerHTML = html;
      })
      .catch(err => {
        console.error('Failed to load add-ons:', err);
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:1rem;color:var(--status-cancelled)">Failed to load add-ons</td></tr>';
      });
  }

  function toggleAddonStatus(addonId, currentStatus) {
    const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
    const formData = new FormData();
    formData.append('addon_id', addonId);
    formData.append('status', newStatus);
    formData.append('csrf_token', '<?= csrfToken() ?>');

    fetch('<?= APP_URL ?>/api/toggle_addon_status.php', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          showToast('Add-on ' + (newStatus === 'active' ? 'activated' : 'deactivated'));
          loadAddonsList(); // refresh the list
        } else {
          showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
        }
      })
      .catch(err => {
        console.error('Toggle failed:', err);
        showToast('Failed to update status', 'error');
      });
  }
</script>

<script>
  let productIndex = 0;

  function updateMenuSelection() {
    const select = document.getElementById('menu-select');
    const customInput = document.getElementById('custom-parent-input');
    if (select.value === 'custom') {
      customInput.style.display = 'block';
      customInput.focus();
    } else {
      customInput.style.display = 'none';
      customInput.value = '';
    }
  }

  function openCategoryModal() {
    document.getElementById('category-form').reset();
    document.getElementById('products-container').innerHTML = '';
    productIndex = 0;
    document.getElementById('menu-select').value = '';
    document.getElementById('custom-parent-input').style.display = 'none';
    document.getElementById('custom-parent-input').value = '';
    document.getElementById('category-modal').classList.remove('hidden');
  }

  function closeCategoryModal() {
    document.getElementById('category-modal').classList.add('hidden');
  }

  function addProductField() {
    const container = document.getElementById('products-container');
    const div = document.createElement('div');
    div.className = 'product-field';
    div.style.border = '1px solid var(--border-color)';
    div.style.borderRadius = 'var(--radius-sm)';
    div.style.padding = '15px';
    div.style.marginBottom = '15px';
    div.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <strong>Product ${productIndex + 1}</strong>
        <button type="button" class="btn btn-sm btn-danger" onclick="removeProductField(this)">
          <i class="fa-solid fa-trash"></i> Remove
        </button>
      </div>
      <div class="form-group">
        <label class="form-label">Product Name <span style="color:var(--status-cancelled)">*</span></label>
        <input type="text" name="product_name[]" class="form-control" placeholder="e.g. Café Latte">
      </div>
      <div class="form-group">
        <label class="form-label">Price (₱) <span style="color:var(--status-cancelled)">*</span></label>
        <input type="number" name="product_price[]" class="form-control" min="0.5" step="0.50" placeholder="0.00">
      </div>
      <div class="form-group">
        <label class="form-label">Description (optional)</label>
        <textarea name="product_desc[]" class="form-control" rows="2" placeholder="Brief description…"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Product Image (optional — JPG, PNG, WEBP, max 2MB)</label>
        <input type="file" name="product_image[]" class="form-control" accept="image/jpeg,image/png,image/webp">
      </div>
      <div class="form-group">
        <label class="form-label">Customization Options</label>
        <div style="display:flex;flex-direction:column;gap:var(--space-2);margin-top:4px">
          <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;font-size:0.84rem">
            <input type="checkbox" class="size-checkbox" name="product_has_sizes_${productIndex}" value="1" style="width:16px;height:16px;accent-color:var(--primary-color);cursor:pointer" onchange="toggleCustomizationDetails(this, ${productIndex})">
            <span><strong>Sizes</strong> <span style="color:var(--text-muted);font-weight:400"> — 16oz / 22oz (+₱<?= SIZE_22OZ_UPCHARGE ?>)</span></span>
          </label>
          <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;font-size:0.84rem">
            <input type="checkbox" class="sugar-checkbox" name="product_has_sugar_${productIndex}" value="1" style="width:16px;height:16px;accent-color:var(--primary-color);cursor:pointer" onchange="toggleCustomizationDetails(this, ${productIndex})">
            <span><strong>Sugar level</strong> <span style="color:var(--text-muted);font-weight:400"> — Full / Less / 50% / No Sugar</span></span>
          </label>
        </div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:6px">
          <i class="fa-solid fa-info-circle"></i> Add-ons can be managed after saving the product
        </div>
      </div>
    `;
    container.appendChild(div);
    productIndex++;
  }

  function toggleCustomizationDetails(checkbox, index) {
    // Visual feedback - checked items show details, unchecked items hide them
    // The descriptions are shown inline with the checkboxes
    if (checkbox.checked) {
      checkbox.parentElement.style.opacity = '1';
      checkbox.parentElement.style.fontWeight = '500';
    } else {
      checkbox.parentElement.style.opacity = '0.7';
      checkbox.parentElement.style.fontWeight = '400';
    }
  }

  function removeProductField(btn) {
    btn.closest('.product-field').remove();
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

<?php if ($oldProductInput): ?>
<script>
  // A save_product submission just failed validation — reopen the modal
  // with what the admin already typed instead of making them start over.
  // The one thing that can't be restored is the chosen image file (the
  // browser won't let a page pre-fill a file input); everything else is.
  document.addEventListener('DOMContentLoaded', function() {
    const old = <?= json_encode($oldProductInput) ?>;
    if (parseInt(old.product_id) > 0) {
      editProduct({
        id: old.product_id,
        name: old.name || '',
        category_id: old.category_id || '',
        price: old.price || '',
        description: old.description || '',
        image_path: '',
        has_sizes: old.has_sizes ? 1 : 0,
        has_sugar: old.has_sugar ? 1 : 0,
        spotlight_pinned: old.spotlight_pinned ? 1 : 0,
        spotlight_pinned_until: old.spotlight_pinned_until || '',
        has_addons: 0,
      });
    } else {
      openAddModal();
      document.getElementById('f-name').value = old.name || '';
      document.getElementById('f-category').value = old.category_id || '';
      document.getElementById('f-price').value = old.price || '';
      document.getElementById('f-desc').value = old.description || '';
      document.getElementById('f-has-sizes').checked = !!old.has_sizes;
      document.getElementById('f-has-sugar').checked = !!old.has_sugar;
      document.getElementById('f-spotlight-pinned').checked = !!old.spotlight_pinned;
      document.getElementById('spotlight-until-wrap').style.display = old.spotlight_pinned ? 'block' : 'none';
      document.getElementById('f-spotlight-until').value = old.spotlight_pinned_until ? old.spotlight_pinned_until.substring(0, 10) : '';
    }
  });
</script>
<?php endif; ?>

<?php layoutFooter(); ?>