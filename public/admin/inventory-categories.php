<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';
requireRole(ROLE_ADMIN);

$db = Database::getInstance();
$otherCategory = 'Other';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();
  if (isset($_POST['add_category'])) {
    $name = trim(sanitizeString($_POST['name'] ?? '', 80));
    if ($name === '') {
      flash('global', 'Enter a category name.', 'error');
    } else {
      try {
        $nextOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM inventory_categories')->fetchColumn();
        $db->prepare('INSERT INTO inventory_categories (name, sort_order) VALUES (?, ?)')->execute([$name, $nextOrder]);
        auditLog(ROLE_ADMIN, currentUserId(), 'create_inventory_category', 'inventory_categories', (int) $db->lastInsertId());
        flash('global', 'Category added.', 'success');
      } catch (PDOException $e) {
        flash('global', 'That category already exists or could not be added.', 'error');
      }
    }
  } elseif (isset($_POST['rename_category'])) {
    $id = (int) ($_POST['category_id'] ?? 0);
    $name = trim(sanitizeString($_POST['name'] ?? '', 80));
    $stmt = $db->prepare('SELECT name FROM inventory_categories WHERE id = ?');
    $stmt->execute([$id]);
    $oldName = $stmt->fetchColumn();
    if (!$oldName) {
      flash('global', 'Category not found.', 'error');
    } elseif ($oldName === $otherCategory) {
      flash('global', 'The Other category is reserved for uncategorized stock.', 'error');
    } elseif ($name === '') {
      flash('global', 'Enter a category name.', 'error');
    } else {
      try {
        $db->beginTransaction();
        $db->prepare('UPDATE inventory_categories SET name = ? WHERE id = ?')->execute([$name, $id]);
        $db->prepare('UPDATE inventory_items SET category = ? WHERE category = ?')->execute([$name, $oldName]);
        $db->commit();
        auditLog(ROLE_ADMIN, currentUserId(), 'rename_inventory_category', 'inventory_categories', $id);
        flash('global', 'Category renamed. Items in that category were updated too.', 'success');
      } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('global', 'That category name already exists or could not be saved.', 'error');
      }
    }
  } elseif (isset($_POST['delete_category'])) {
    $id = (int) ($_POST['category_id'] ?? 0);
    $stmt = $db->prepare('SELECT name FROM inventory_categories WHERE id = ?');
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();
    if (!$name) {
      flash('global', 'Category not found.', 'error');
    } elseif ($name === $otherCategory) {
      flash('global', 'The Other category is reserved and cannot be deleted.', 'error');
    } else {
      $usage = $db->prepare('SELECT COUNT(*) FROM inventory_items WHERE category = ?');
      $usage->execute([$name]);
      if ((int) $usage->fetchColumn() > 0) {
        flash('global', 'Move all items out of this category before deleting it.', 'error');
      } else {
        $db->prepare('DELETE FROM inventory_categories WHERE id = ?')->execute([$id]);
        auditLog(ROLE_ADMIN, currentUserId(), 'delete_inventory_category', 'inventory_categories', $id);
        flash('global', 'Category deleted.', 'success');
      }
    }
  }
  redirect(APP_URL . '/admin/inventory-categories.php');
}

$categories = $db->query(
  'SELECT c.id, c.name, c.sort_order, COUNT(i.id) AS item_count
   FROM inventory_categories c LEFT JOIN inventory_items i
     ON i.category COLLATE utf8mb4_unicode_ci = c.name
   GROUP BY c.id, c.name, c.sort_order ORDER BY c.sort_order, c.name'
)->fetchAll(PDO::FETCH_ASSOC);

layoutHeader('Inventory Categories');
?>
<div class="page-header">
  <div>
    <div class="page-header-title">Inventory Categories</div>
    <div class="page-header-sub">Create and maintain the groups used to organize stock items.</div>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/admin/inventory.php" class="btn btn-ghost"><i class="fa-solid fa-boxes-stacked"></i> Back to Inventory</a>
  </div>
</div>
<?php showFlash('global'); ?>

<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <div class="card-title">Add a Category</div>
  </div>
  <div class="card-body">
    <form method="POST" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
      <?= csrfField() ?>
      <input type="hidden" name="add_category" value="1">
      <div class="form-group" style="margin:0;flex:1;min-width:220px">
        <label class="form-label" for="new-category-name">Category name</label>
        <input id="new-category-name" name="name" class="form-control" maxlength="80" required>
      </div>
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus"></i> Add Category</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title">Categories</div>
  </div>
  <div style="overflow-x:auto">
    <table class="data-table">
      <thead>
        <tr>
          <th>Category</th>
          <th>Stock items</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$categories): ?>
          <tr>
            <td colspan="3" style="text-align:center;padding:24px;color:var(--text-muted)">No categories yet.</td>
          </tr>
          <?php else: foreach ($categories as $category): ?>
            <tr>
              <td>
                <?php if ($category['name'] === $otherCategory): ?>
                  <strong><?= e($category['name']) ?></strong> <span class="text-muted">(reserved)</span>
                <?php else: ?>
                  <form method="POST" style="display:flex;gap:8px;align-items:center;max-width:520px">
                    <?= csrfField() ?>
                    <input type="hidden" name="rename_category" value="1">
                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                    <input name="name" class="form-control" maxlength="80" value="<?= e($category['name']) ?>" required aria-label="Category name">
                    <button class="btn btn-sm btn-outline" type="submit" title="Save category name"><i class="fa-solid fa-check"></i> Save</button>
                  </form>
                <?php endif; ?>
              </td>
              <td><?= (int) $category['item_count'] ?></td>
              <td>
                <?php if ($category['name'] !== $otherCategory): ?>
                  <form method="POST" onsubmit="return confirm('Delete this category?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="delete_category" value="1">
                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit" <?= (int) $category['item_count'] > 0 ? 'disabled title="Move its items to another category first"' : '' ?>><i class="fa-solid fa-trash"></i> Delete</button>
                  </form>
                <?php else: ?><span class="text-muted">Required fallback</span><?php endif; ?>
              </td>
            </tr>
        <?php endforeach;
        endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layoutFooter(); ?>