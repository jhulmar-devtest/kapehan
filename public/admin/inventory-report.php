<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/inventory_report.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

$scope = ($_GET['scope'] ?? 'all') === 'low' ? 'low' : 'all';
$search = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$categories = inventoryCategories($db);
$categoryNames = array_column($categories, 'name');
if ($category !== '' && !in_array($category, $categoryNames, true)) $category = '';
$rows = inventoryReportData($db, $scope, $category, $search);
$allRows = inventoryReportData($db);
$lowStockCount = count(array_filter($allRows, fn($item) => $item['is_low']));
$totalStockValue = array_sum(array_column($rows, 'stock_value'));
$filterParams = array_filter(['scope' => $scope, 'category' => $category, 'q' => $search], fn($value) => $value !== '');

layoutHeader('Inventory Report');
?>
<style>
  .inventory-report { display:grid; gap:18px; }
  .report-intro { display:flex; align-items:center; gap:13px; padding:17px 19px; border:1px solid var(--border-color); border-radius:var(--radius-lg); background:linear-gradient(115deg,var(--surface-color),var(--surface-raised)); }
  .report-intro-icon { display:grid; place-items:center; flex:0 0 42px; width:42px; height:42px; border-radius:13px; background:var(--primary-subtle); color:var(--primary-color); }
  .report-intro h2 { margin:0; font-size:15px; }
  .report-intro p { margin:3px 0 0; color:var(--text-muted); font-size:12px; }
  .report-filters { display:flex; flex-wrap:wrap; align-items:end; gap:12px; }
  .report-filters .form-group { min-width:160px; flex:1; margin:0; }
  .report-filters .search-field { min-width:220px; flex:2; }
  .report-summary { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
  .report-stat { padding:15px 17px; border:1px solid var(--border-color); border-radius:var(--radius-md); background:var(--surface-color); }
  .report-stat-label { color:var(--text-muted); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
  .report-stat-value { margin-top:5px; color:var(--text-primary); font-size:20px; font-weight:800; font-variant-numeric:tabular-nums; }
  .report-table-wrap { overflow:auto; }
  .report-table { min-width:840px; }
  .report-table th { white-space:nowrap; }
  .report-table td { font-variant-numeric:tabular-nums; }
  .report-actions { display:flex; align-items:center; justify-content:space-between; gap:12px; }
  .report-actions-right { display:flex; gap:9px; }
  .report-status { font-weight:700; }
  .report-status-low { color:var(--primary-color); }
  .report-status-ok { color:var(--status-ready,#2f9e63); }
  .report-empty { padding:30px 18px; color:var(--text-muted); text-align:center; }
  .report-meta { color:var(--text-muted); font-size:11px; }
  @media(max-width:640px) {
    .report-summary { grid-template-columns:1fr; }
    .report-actions { align-items:stretch; flex-direction:column; }
    .report-actions-right { display:grid; grid-template-columns:1fr 1fr; }
    .report-actions-right .btn { justify-content:center; }
  }
  @media print {
    @page { size:landscape; margin:12mm; }
    body { background:#fff !important; }
    .sidebar,.sidebar-backdrop,.topbar,.page-header-actions,.report-intro,.report-filters,.report-actions-right,.admin-footer { display:none !important; }
    .main-wrapper,.page-content { width:100% !important; max-width:none !important; margin:0 !important; padding:0 !important; }
    .inventory-report { gap:10px; }
    .report-summary { grid-template-columns:repeat(3,1fr); }
    .report-stat,.card { box-shadow:none !important; break-inside:avoid; }
    .report-table-wrap { overflow:visible; }
    .report-table { min-width:0; width:100%; font-size:9pt; }
    .report-table thead { display:table-header-group; }
    .report-table tr { break-inside:avoid; }
  }
</style>

<div class="page-header">
  <div>
    <div class="page-header-title">Inventory Report</div>
    <div class="page-header-sub">Current stock snapshot · Generated <?= e(date('M j, Y · g:i A')) ?></div>
  </div>
  <div class="page-header-actions"><a href="<?= APP_URL ?>/admin/inventory.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to Inventory</a></div>
</div>

<main class="inventory-report">
  <section class="report-intro">
    <span class="report-intro-icon"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i></span>
    <div><h2>Preview before exporting</h2><p>Costs and stock value are included for internal inventory review. Stock value is based on on-hand quantity × current unit cost.</p></div>
  </section>

  <section class="card"><div class="card-body">
    <form method="GET" class="report-filters">
      <div class="form-group search-field"><label class="form-label" for="report-search">Find item</label><input class="form-control" id="report-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Search item, category, or unit"></div>
      <div class="form-group"><label class="form-label" for="report-category">Category</label><select class="form-control" id="report-category" name="category"><option value="">All categories</option><?php foreach ($categories as $row): ?><option value="<?= e($row['name']) ?>" <?= $category === $row['name'] ? 'selected' : '' ?>><?= e($row['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label" for="report-scope">Items to show</label><select class="form-control" id="report-scope" name="scope"><option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All active items</option><option value="low" <?= $scope === 'low' ? 'selected' : '' ?>>Below reorder level</option></select></div>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Update preview</button>
    </form>
  </div></section>

  <section class="report-summary" aria-label="Inventory report summary">
    <div class="report-stat"><div class="report-stat-label">Items in preview</div><div class="report-stat-value"><?= count($rows) ?></div></div>
    <div class="report-stat"><div class="report-stat-label">Low-stock items</div><div class="report-stat-value"><?= $lowStockCount ?></div></div>
    <div class="report-stat"><div class="report-stat-label">Stock value in preview</div><div class="report-stat-value"><?= e(peso($totalStockValue)) ?></div></div>
  </section>

  <section class="card">
    <div class="card-header">
      <div><div class="card-title">Stock on hand</div><div class="report-meta"><?= $scope === 'low' ? 'Below reorder level' : 'All active inventory' ?><?= $category !== '' ? ' · ' . e($category) : '' ?><?= $search !== '' ? ' · Search: “' . e($search) . '”' : '' ?></div></div>
      <span class="report-meta">As of <?= e(date('M j, Y g:i A')) ?></span>
    </div>
    <div class="report-table-wrap">
      <table class="data-table report-table">
        <thead><tr><th>Inventory item</th><th>Category</th><th class="num">On hand</th><th class="num">Reserved</th><th class="num">Available</th><th class="num">Reorder level</th><th>Status</th><th class="num">Cost / unit</th><th class="num">Stock value</th></tr></thead>
        <tbody>
          <?php if (!$rows): ?><tr><td colspan="9" class="report-empty">No inventory items match these filters.</td></tr><?php endif; ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><strong><?= e($row['name']) ?></strong></td><td><?= e($row['category']) ?></td>
              <td class="num"><?= number_format((float) $row['quantity_on_hand'], 3) ?> <?= e($row['unit']) ?></td>
              <td class="num"><?= number_format((float) $row['reserved_quantity'], 3) ?> <?= e($row['unit']) ?></td>
              <td class="num"><?= number_format(max(0, (float) $row['available_quantity']), 3) ?> <?= e($row['unit']) ?></td>
              <td class="num"><?= number_format((float) $row['reorder_level'], 3) ?> <?= e($row['unit']) ?></td>
              <td class="report-status <?= $row['is_low'] ? 'report-status-low' : 'report-status-ok' ?>"><?= $row['is_low'] ? 'Reorder' : 'In stock' ?></td>
              <td class="num"><?= e(peso($row['cost_per_unit'])) ?></td><td class="num"><?= e(peso($row['stock_value'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><tr><th colspan="8" class="num">Total stock value</th><th class="num"><?= e(peso($totalStockValue)) ?></th></tr></tfoot><?php endif; ?>
      </table>
    </div>
    <div class="card-body report-actions">
      <span class="report-meta">Stock value uses on-hand quantity and the current cost per unit.</span>
      <div class="report-actions-right">
        <button type="button" class="btn btn-ghost" onclick="window.print()"><i class="fa-solid fa-print"></i> Print preview</button>
        <a class="btn btn-primary" href="<?= APP_URL ?>/admin/inventory-report-export.php<?= $filterParams ? '?' . e(http_build_query($filterParams)) : '' ?>"><i class="fa-solid fa-file-excel"></i> Export to Excel</a>
      </div>
    </div>
  </section>
</main>

<?php layoutFooter(); ?>
