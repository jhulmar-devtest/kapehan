<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

$types = [
  'products' => 'Products',
  'menu_categories' => 'Menu categories',
  'inventory_items' => 'Inventory items',
  'inventory_categories' => 'Inventory categories',
  'drink_sizes' => 'Drink sizes',
  'addons' => 'Add-ons',
  'cashiers' => 'Cashiers',
];
$type = (string) ($_GET['type'] ?? $_POST['type'] ?? 'products');
if (!isset($types[$type])) $type = 'products';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore'])) {
  verifyCsrf();
  $id = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
  if (!$id || $id < 1) {
    flash('global', 'Invalid archive record.', 'error');
    redirect(APP_URL . '/admin/archive.php?type=' . rawurlencode($type));
  }
  try {
    switch ($type) {
      case 'products':
        $stmt = $db->prepare('UPDATE products p JOIN categories c ON c.id = p.category_id SET p.archived_at = NULL WHERE p.id = ? AND p.archived_at IS NOT NULL AND c.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM product_ingredients pi JOIN inventory_items i ON i.id = pi.inventory_item_id WHERE pi.product_id = p.id AND i.archived_at IS NOT NULL)');
        $stmt->execute([$id]);
        break;
      case 'menu_categories':
        $stmt = $db->prepare('UPDATE categories child JOIN categories parent ON parent.id = child.parent_id SET child.archived_at = NULL WHERE child.id = ? AND child.archived_at IS NOT NULL AND parent.archived_at IS NULL');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) {
          $stmt = $db->prepare('UPDATE categories SET archived_at = NULL WHERE id = ? AND parent_id IS NULL AND archived_at IS NOT NULL');
          $stmt->execute([$id]);
        }
        break;
      case 'inventory_items':
        $stmt = $db->prepare('UPDATE inventory_items i LEFT JOIN inventory_categories c ON c.name = i.category SET i.archived_at = NULL WHERE i.id = ? AND i.archived_at IS NOT NULL AND (c.id IS NULL OR c.archived_at IS NULL)');
        $stmt->execute([$id]);
        break;
      case 'inventory_categories':
        $stmt = $db->prepare('UPDATE inventory_categories SET archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL');
        $stmt->execute([$id]);
        break;
      case 'drink_sizes':
        $stmt = $db->prepare('UPDATE size_options SET archived_at = NULL, is_active = 1 WHERE id = ? AND archived_at IS NOT NULL');
        $stmt->execute([$id]);
        break;
      case 'addons':
        $stmt = $db->prepare("UPDATE addons AS a SET archived_at = NULL, status = 'active' WHERE a.id = ? AND a.archived_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM addon_ingredients ai JOIN inventory_items i ON i.id = ai.inventory_item_id WHERE ai.addon_id = a.id AND i.archived_at IS NOT NULL)");
        $stmt->execute([$id]);
        break;
      case 'cashiers':
        $lookup = $db->prepare('SELECT id, username FROM cashiers WHERE id = ? AND deleted_at IS NOT NULL');
        $lookup->execute([$id]);
        $cashier = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$cashier) throw new RuntimeException('Archived cashier was not found.');
        if (str_starts_with((string) $cashier['username'], '__deleted_cashier_')) {
          throw new RuntimeException('This older archived login was anonymized before archive restoration was available and cannot be restored.');
        }
        $duplicate = $db->prepare('SELECT COUNT(*) FROM cashiers WHERE username = ? AND id <> ? AND deleted_at IS NULL');
        $duplicate->execute([$cashier['username'], $id]);
        if ((int) $duplicate->fetchColumn() > 0) throw new RuntimeException('That cashier username is now in use. Rename the active account before restoring this one.');
        $stmt = $db->prepare('UPDATE cashiers SET deleted_at = NULL, is_active = 0 WHERE id = ? AND deleted_at IS NOT NULL');
        $stmt->execute([$id]);
        break;
    }
    if (!$stmt->rowCount()) throw new RuntimeException('This record could not be restored. Restore its parent category or required stock items first.');
    auditLog(ROLE_ADMIN, currentUserId(), 'restore_' . $type, $type, $id);
    flash('global', $type === 'cashiers' ? 'Cashier restored as inactive. Activate the account on the Cashiers page when ready.' : 'Record restored.', 'success');
  } catch (Throwable $e) {
    if (!($e instanceof RuntimeException)) error_log('Archive restore failed: ' . $e->getMessage());
    flash('global', $e->getMessage() ?: 'Could not restore this record.', 'error');
  }
  redirect(APP_URL . '/admin/archive.php?type=' . rawurlencode($type));
}

$queries = [
  'products' => "SELECT p.id, p.name, c.name AS group_name, p.archived_at FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.archived_at IS NOT NULL ORDER BY p.archived_at DESC, p.name",
  'menu_categories' => "SELECT c.id, c.name, parent.name AS group_name, c.archived_at FROM categories c LEFT JOIN categories parent ON parent.id = c.parent_id WHERE c.archived_at IS NOT NULL ORDER BY c.archived_at DESC, c.name",
  'inventory_items' => "SELECT i.id, i.name, i.category AS group_name, i.archived_at FROM inventory_items i WHERE i.archived_at IS NOT NULL ORDER BY i.archived_at DESC, i.name",
  'inventory_categories' => "SELECT c.id, c.name, COUNT(i.id) AS item_count, c.archived_at FROM inventory_categories c LEFT JOIN inventory_items i ON i.category = c.name AND i.archived_at IS NOT NULL WHERE c.archived_at IS NOT NULL GROUP BY c.id, c.name, c.archived_at ORDER BY c.archived_at DESC, c.name",
  'drink_sizes' => "SELECT id, label AS name, price_adjustment, is_active, archived_at FROM size_options WHERE archived_at IS NOT NULL ORDER BY archived_at DESC, sort_order, label",
  'addons' => "SELECT a.id, a.name, a.price, GROUP_CONCAT(DISTINCT i.name ORDER BY i.name SEPARATOR ', ') AS group_name, a.archived_at FROM addons a LEFT JOIN addon_ingredients ai ON ai.addon_id = a.id LEFT JOIN inventory_items i ON i.id = ai.inventory_item_id WHERE a.archived_at IS NOT NULL GROUP BY a.id, a.name, a.price, a.archived_at ORDER BY a.archived_at DESC, a.name",
  'cashiers' => "SELECT id, full_name AS name, username, deleted_at AS archived_at FROM cashiers WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, full_name",
];
$records = $db->query($queries[$type])->fetchAll(PDO::FETCH_ASSOC);
$counts = [];
foreach ($types as $key => $_label) {
  $tableAndColumn = [
    'products' => ['products', 'archived_at'], 'menu_categories' => ['categories', 'archived_at'],
    'inventory_items' => ['inventory_items', 'archived_at'], 'inventory_categories' => ['inventory_categories', 'archived_at'],
    'drink_sizes' => ['size_options', 'archived_at'], 'addons' => ['addons', 'archived_at'], 'cashiers' => ['cashiers', 'deleted_at'],
  ][$key];
  $count = $db->query('SELECT COUNT(*) FROM `' . $tableAndColumn[0] . '` WHERE `' . $tableAndColumn[1] . '` IS NOT NULL')->fetchColumn();
  $counts[$key] = (int) $count;
}

layoutHeader('Archive');
?>
<div class="page-header">
  <div><div class="page-header-title">Archive</div><div class="page-header-sub">Restore products and settings that were removed from active pages.</div></div>
  <div class="page-header-actions"><a href="<?= APP_URL ?>/admin/ordering.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to management</a></div>
</div>
<?php showFlash('global'); ?>
<style>
  .archive-tabs{display:flex;gap:8px;overflow:auto;padding:4px 2px 14px;margin-bottom:4px}
  .archive-tab{flex:none;padding:9px 12px;border:1px solid var(--border-color);border-radius:999px;background:var(--surface-color);color:var(--text-secondary);text-decoration:none;font-size:.83rem;font-weight:700}
  .archive-tab.active{border-color:var(--primary-color);background:var(--primary-subtle);color:var(--primary-color)}
  .archive-count{margin-left:5px;opacity:.72;font-variant-numeric:tabular-nums}
  .archive-table-wrap{overflow-x:auto}
  .archive-empty{text-align:center;padding:44px 20px;color:var(--text-muted)}
  .archive-empty i{display:block;margin-bottom:10px;color:var(--primary-color);font-size:1.8rem}
</style>
<nav class="archive-tabs" aria-label="Archived record types">
  <?php foreach ($types as $key => $label): ?><a class="archive-tab <?= $key === $type ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/archive.php?type=<?= e($key) ?>"><?= e($label) ?><span class="archive-count"><?= $counts[$key] ?></span></a><?php endforeach; ?>
</nav>
<div class="card">
  <div class="card-header"><div class="card-title"><?= e($types[$type]) ?></div><span class="text-muted"><?= count($records) ?> archived</span></div>
  <?php if (!$records): ?>
    <div class="archive-empty"><i class="fa-solid fa-box-open"></i>Nothing in this archive category.</div>
  <?php else: ?>
    <div class="archive-table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Group / details</th><th>Archived</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($records as $record):
        $name = $record['name'] ?? $record['full_name'] ?? 'Unnamed record';
        $detail = $record['group_name'] ?? '';
        if ($type === 'drink_sizes') $detail = 'Price adjustment: ' . peso($record['price_adjustment']);
        elseif ($type === 'addons') $detail = trim(($record['group_name'] ?? '') . ' · ' . peso($record['price']));
        elseif ($type === 'cashiers') $detail = 'Username: ' . ($record['username'] ?? '—');
        elseif ($type === 'inventory_categories') $detail = number_format((int) $record['item_count']) . ' archived stock items';
        $canRestore = !($type === 'cashiers' && str_starts_with((string) ($record['username'] ?? ''), '__deleted_cashier_'));
      ?>
        <tr><td><strong><?= e($name) ?></strong></td><td><?= e($detail ?: '—') ?></td><td><?= e(date('M j, Y g:i A', strtotime($record['archived_at']))) ?></td><td>
          <?php if ($canRestore): ?><form method="POST" onsubmit="return confirm('Restore this record?')"><?= csrfField() ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><button class="btn btn-sm btn-primary" name="restore" value="1" type="submit"><i class="fa-solid fa-rotate-left"></i> Restore</button></form>
          <?php else: ?><span class="text-muted" title="The old login credentials were removed before reversible archiving was added.">Cannot restore</span><?php endif; ?>
        </td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>
<?php layoutFooter(); ?>
