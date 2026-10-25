<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

// ── Redirect old receipt JSON calls to shared endpoint ─────────────────────
if (isset($_GET['receipt'])) {
  header('Location: ' . APP_URL . '/receipt_view.php?order_id=' . (int)$_GET['receipt']);
  exit;
}

// ── Filters ───────────────────────────────────────────────────
$filterStatus   = $_GET['status']   ?? 'all';
$filterType     = $_GET['type']     ?? 'all';
$filterCashier  = (int)($_GET['cashier'] ?? 0);
$filterPayment  = $_GET['payment']  ?? 'all';
$requestedSort  = $_GET['sort'] ?? 'newest';
$filterSort     = in_array($requestedSort, ['newest', 'oldest'], true) ? $requestedSort : 'newest';
$filterDateFrom = trim((string) ($_GET['date_from'] ?? ''));
$filterDateTo   = trim((string) ($_GET['date_to'] ?? ''));
$dateFilterError = '';
$isValidDate = static function (string $value): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return $date !== false && $date->format('Y-m-d') === $value;
};
if ($filterDateFrom !== '' && !$isValidDate($filterDateFrom)) $filterDateFrom = '';
if ($filterDateTo !== '' && !$isValidDate($filterDateTo)) $filterDateTo = '';
if ($filterDateFrom !== '' && $filterDateTo !== '' && $filterDateFrom > $filterDateTo) {
  $dateFilterError = 'The start date must be on or before the end date.';
  $filterDateFrom = $filterDateTo = '';
}
$today = date('Y-m-d');
$quickDateRanges = [
  'today' => ['label' => 'Today', 'from' => $today, 'to' => $today],
  'yesterday' => ['label' => 'Yesterday', 'from' => date('Y-m-d', strtotime('-1 day')), 'to' => date('Y-m-d', strtotime('-1 day'))],
  'last7' => ['label' => 'Last 7 days', 'from' => date('Y-m-d', strtotime('-6 days')), 'to' => $today],
  'month' => ['label' => 'This month', 'from' => date('Y-m-01'), 'to' => $today],
];
$activeDatePreset = '';
foreach ($quickDateRanges as $key => $range) {
  if ($filterDateFrom === $range['from'] && $filterDateTo === $range['to']) $activeDatePreset = $key;
}
$activeDateLabel = 'All dates';
if ($filterDateFrom !== '' && $filterDateTo !== '') {
  $activeDateLabel = $filterDateFrom === $filterDateTo
    ? date('M j, Y', strtotime($filterDateFrom))
    : date('M j, Y', strtotime($filterDateFrom)) . ' – ' . date('M j, Y', strtotime($filterDateTo));
} elseif ($filterDateFrom !== '') {
  $activeDateLabel = 'From ' . date('M j, Y', strtotime($filterDateFrom));
} elseif ($filterDateTo !== '') {
  $activeDateLabel = 'Through ' . date('M j, Y', strtotime($filterDateTo));
}
$activeFilterCount = (int) ($filterStatus !== 'all')
  + (int) ($filterType !== 'all')
  + (int) ($filterPayment !== 'all')
  + (int) ($filterCashier > 0)
  + (int) ($filterDateFrom !== '' || $filterDateTo !== '')
  + (int) ($filterSort !== 'newest');

$conditions = ['1=1'];
$params     = [];

if ($filterStatus !== 'all' && in_array($filterStatus, ['pending', 'preparing', 'ready', 'claimed', 'cancelled'])) {
  $conditions[] = 'o.status = ?';
  $params[] = $filterStatus;
}
if ($filterType !== 'all' && in_array($filterType, ['walk-in', 'pre-order'])) {
  $conditions[] = 'o.order_type = ?';
  $params[] = $filterType;
}
if ($filterCashier > 0) {
  $conditions[] = 'o.cashier_id = ?';
  $params[] = $filterCashier;
}
if ($filterPayment !== 'all' && in_array($filterPayment, ['cash', 'GCash'])) {
  $conditions[] = 'p.payment_method = ?';
  $params[] = $filterPayment;
}
if ($filterDateFrom !== '') {
  $conditions[] = 'o.created_at >= ?';
  $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
  $conditions[] = 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
  $params[] = $filterDateTo . ' 00:00:00';
}

$where = implode(' AND ', $conditions);

$stmt = $db->prepare(
  "SELECT o.*,
          COALESCE(s.full_name, f.full_name, 'Walk-in') AS customer,
          COALESCE(c.full_name, '—')        AS cashier_name,
          p.payment_method, p.payment_status
   FROM orders o
   LEFT JOIN students s ON o.student_id  = s.id
   LEFT JOIN faculty  f ON o.faculty_id  = f.id
   LEFT JOIN cashiers c ON o.cashier_id  = c.id
   LEFT JOIN payments p ON o.id          = p.order_id
   WHERE $where
   ORDER BY o.created_at " . ($filterSort === 'oldest' ? 'ASC' : 'DESC') . ", o.id " . ($filterSort === 'oldest' ? 'ASC' : 'DESC') . "
   LIMIT 300"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$allCashiers = $db->query(
  "SELECT DISTINCT c.id, c.full_name FROM cashiers c
   JOIN orders o ON o.cashier_id = c.id
   ORDER BY c.full_name"
)->fetchAll();

layoutHeader('All Orders');
?>
<style>
  .orders-row {
    cursor: pointer;
    transition: background var(--transition-fast);
  }

  .orders-heading-icon {
    width: 44px;
    height: 44px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    background: var(--primary-subtle);
    color: var(--primary-color);
    font-size: 18px;
    flex: none
  }

  .orders-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px
  }

  .orders-filter-triggerbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 18px;
    margin-bottom: 16px
  }

  .orders-filter-summary {
    display: grid;
    gap: 3px;
    min-width: 0
  }

  .orders-filter-summary strong {
    font-size: .86rem;
    color: var(--text-color)
  }

  .orders-filter-summary span {
    font-size: .78rem;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis
  }

  .orders-filter-trigger {
    white-space: nowrap
  }

  .orders-filter-count {
    display: inline-grid;
    place-items: center;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    margin-left: 4px;
    border-radius: 999px;
    background: var(--primary-dark);
    color: var(--text-on-primary);
    font-size: .7rem;
    font-weight: 800
  }

  .orders-filter-overlay {
    position: fixed;
    inset: 0;
    z-index: 400;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(24, 18, 14, .56);
    backdrop-filter: blur(3px)
  }

  .orders-filter-overlay.open {
    display: flex
  }

  .orders-filter-modal {
    width: min(100%, 680px);
    max-height: min(90vh, 820px);
    overflow: auto;
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    animation: orders-filter-in .18s ease-out
  }

  @keyframes orders-filter-in {
    from {
      opacity: 0;
      transform: translateY(8px) scale(.99)
    }

    to {
      opacity: 1;
      transform: translateY(0) scale(1)
    }
  }

  .orders-filter-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 20px 22px;
    border-bottom: 1px solid var(--border-color)
  }

  .orders-filter-modal-title {
    display: flex;
    align-items: center;
    gap: 11px;
    font-weight: 800;
    font-size: 1.08rem
  }

  .orders-filter-modal-title i {
    color: var(--primary-color)
  }

  .orders-filter-modal-sub {
    margin-top: 4px;
    color: var(--text-muted);
    font-size: .8rem
  }

  .orders-filter-close {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border: 1px solid var(--border-color);
    border-radius: 50%;
    background: var(--surface-raised);
    color: var(--text-muted);
    cursor: pointer
  }

  .orders-filter-form {
    padding: 20px 22px
  }

  .orders-filter-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px
  }

  .orders-filter-field {
    display: grid;
    gap: 6px;
    min-width: 0
  }

  .orders-filter-field label,
  .orders-filter-legend {
    font-size: .72rem;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .045em
  }

  .orders-filter-field .form-control {
    width: 100%
  }

  .orders-date-fieldset {
    grid-column: 1/-1;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 14px;
    margin: 0;
    min-width: 0
  }

  .orders-date-fieldset legend {
    padding: 0 6px
  }

  .orders-date-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 6px
  }

  .orders-date-presets {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 13px
  }

  .orders-date-presets-label {
    width: 100%;
    font-size: .72rem;
    color: var(--text-muted);
    font-weight: 700;
    margin-bottom: 2px
  }

  .orders-date-preset {
    border: 1px solid var(--border-color);
    border-radius: var(--radius-full);
    background: var(--surface-color);
    color: var(--text-secondary);
    padding: 6px 11px;
    font-size: .76rem;
    font-weight: 700;
    cursor: pointer;
    transition: all var(--transition-fast)
  }

  .orders-date-preset:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: var(--primary-subtle)
  }

  .orders-date-preset.active {
    border-color: var(--primary-color);
    background: var(--primary-subtle);
    color: var(--primary-dark)
  }

  .orders-filter-error {
    grid-column: 1/-1;
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    background: var(--color-error-bg);
    color: var(--color-error);
    font-size: .82rem
  }

  .orders-filter-modal-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 15px 22px;
    border-top: 1px solid var(--border-color);
    background: var(--surface-raised)
  }

  .orders-filter-modal-actions {
    display: flex;
    gap: 8px;
    align-items: center
  }

  .orders-results-card {
    overflow: hidden
  }

  .orders-results-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 18px;
    border-bottom: 1px solid var(--border-color);
    flex-wrap: wrap
  }

  .orders-search {
    position: relative;
    flex: 1;
    max-width: 390px;
    min-width: min(100%, 240px)
  }

  .orders-search i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none
  }

  .orders-search input {
    padding-left: 38px
  }

  .orders-result-count {
    font-size: .82rem;
    color: var(--text-muted);
    font-weight: 600
  }

  .orders-table-wrap {
    overflow-x: auto
  }

  .orders-table {
    min-width: 900px
  }

  .orders-table th {
    white-space: nowrap
  }

  .orders-row:hover td {
    background: var(--primary-subtle) !important
  }

  .orders-row:focus-visible {
    outline: 2px solid var(--primary-color);
    outline-offset: -2px
  }

  .orders-table-order {
    font-weight: 800;
    color: var(--primary-color);
    white-space: nowrap
  }

  .orders-customer {
    font-weight: 650;
    color: var(--text-color)
  }

  .orders-cashier {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--text-secondary);
    font-size: .82rem;
    white-space: nowrap
  }

  .orders-cashier-icon {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--surface-raised);
    display: inline-grid;
    place-items: center;
    color: var(--secondary-color);
    font-size: 11px
  }

  .orders-total {
    font-weight: 800;
    white-space: nowrap;
    color: var(--text-color)
  }

  .orders-payment {
    display: grid;
    justify-items: start;
    gap: 4px;
    white-space: nowrap
  }

  .orders-payment-method {
    font-size: .82rem;
    font-weight: 650;
    color: var(--text-secondary)
  }

  .orders-date {
    white-space: nowrap
  }

  .orders-date time {
    display: block;
    font-size: .75rem;
    color: var(--text-muted);
    margin-top: 2px
  }

  .orders-empty-search td {
    text-align: center;
    padding: 28px;
    color: var(--text-muted)
  }

  @media(max-width:760px) {
    .orders-filter-triggerbar {
      padding: 12px
    }

    .orders-filter-summary span {
      max-width: 55vw
    }

    .orders-filter-modal-head,
    .orders-filter-form {
      padding: 17px
    }

    .orders-filter-modal-foot {
      padding: 13px 17px
    }
  }

  @media(max-width:480px) {

    .orders-filter-grid,
    .orders-date-grid {
      grid-template-columns: 1fr
    }

    .orders-date-fieldset,
    .orders-filter-error {
      grid-column: auto
    }

    .orders-filter-modal-foot {
      align-items: stretch;
      flex-direction: column-reverse
    }

    .orders-filter-modal-actions {
      display: grid;
      grid-template-columns: 1fr 1fr
    }

    .orders-filter-modal-actions .btn {
      justify-content: center
    }

    .orders-filter-triggerbar {
      align-items: flex-start
    }

    .orders-filter-trigger {
      padding: 9px 11px
    }

    .orders-results-toolbar {
      padding: 12px
    }

    .orders-search {
      max-width: none;
      width: 100%
    }
  }

  /* ── Receipt Modal (same as cashier side) ──────────────────── */
  .receipt-overlay {
    position: fixed;
    inset: 0;
    z-index: 300;
    background: rgba(0, 0, 0, 0.55);
    backdrop-filter: blur(3px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-5);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
  }

  .receipt-overlay.open {
    opacity: 1;
    pointer-events: all;
  }

  .receipt-modal {
    background: var(--surface-color);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    width: 100%;
    max-width: 400px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transform: scale(0.95) translateY(10px);
    transition: transform 0.22s cubic-bezier(0.34, 1.1, 0.64, 1);
  }

  .receipt-overlay.open .receipt-modal {
    transform: scale(1) translateY(0);
  }

  .receipt-modal-head {
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
  }

  .receipt-modal-head-title {
    font-size: 0.90rem;
    font-weight: 800;
    color: var(--text-color);
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .receipt-modal-head-title i {
    color: var(--primary-color);
  }

  .receipt-modal-head-actions {
    display: flex;
    gap: var(--space-2);
    align-items: center;
  }

  .receipt-close {
    width: 28px;
    height: 28px;
    border-radius: var(--radius-full);
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 12px;
    transition: all var(--transition-fast);
  }

  .receipt-close:hover {
    background: var(--status-cancelled-bg);
    color: var(--status-cancelled);
  }

  .receipt-body {
    flex: 1;
    overflow-y: auto;
    padding: var(--space-5);
  }

  /* Modal body for shared receipt template */
  .receipt-body-content {
    display: flex;
    justify-content: center;
  }

  .receipt-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-8);
    color: var(--text-muted);
    font-size: 0.84rem;
    gap: var(--space-3);
  }
</style>

<div class="page-header">
  <div class="orders-title-wrap">
    <span class="orders-heading-icon"><i class="fa-solid fa-receipt"></i></span>
    <div>
      <div class="page-header-title">All Orders</div>
      <div class="page-header-sub">Review sales, narrow the list, and open an order receipt.</div>
    </div>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/admin/sales.php" class="btn btn-ghost btn-sm">
      <i class="fa-solid fa-chart-bar"></i> Reports
    </a>
  </div>
</div>

<?php showFlash('global'); ?>

<div class="card orders-filter-triggerbar">
  <div class="orders-filter-summary">
    <strong>Order filters and sorting</strong>
    <span><?= $activeFilterCount ?> active filter<?= $activeFilterCount === 1 ? '' : 's' ?> · <?= e($activeDateLabel) ?> · <?= $filterSort === 'oldest' ? 'Oldest first' : 'Newest first' ?></span>
  </div>
  <button type="button" class="btn btn-primary orders-filter-trigger" onclick="openOrdersFilter()" aria-haspopup="dialog" aria-controls="orders-filter-overlay">
    <i class="fa-solid fa-sliders"></i> Filter &amp; Sort
    <?php if ($activeFilterCount > 0): ?><span class="orders-filter-count"><?= $activeFilterCount ?></span><?php endif; ?>
  </button>
</div>

<div class="orders-filter-overlay <?= $dateFilterError !== '' ? 'open' : '' ?>" id="orders-filter-overlay" <?= $dateFilterError !== '' ? '' : 'hidden' ?>>
  <section class="orders-filter-modal" role="dialog" aria-modal="true" aria-labelledby="orders-filter-title">
    <div class="orders-filter-modal-head">
      <div>
        <div class="orders-filter-modal-title" id="orders-filter-title"><i class="fa-solid fa-sliders"></i> Filter &amp; sort orders</div>
        <div class="orders-filter-modal-sub">Narrow the order list by status, payment, cashier, or order date.</div>
      </div>
      <button type="button" class="orders-filter-close" onclick="closeOrdersFilter()" aria-label="Close filters"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="GET" class="orders-filter-form" id="orders-filter-form">
      <div class="orders-filter-grid">
        <div class="orders-filter-field">
          <label for="orders-status-filter">Order status</label>
          <select class="form-control" id="orders-status-filter" name="status">
            <?php foreach (['all' => 'All statuses', 'pending' => 'Pending', 'preparing' => 'Preparing', 'ready' => 'Ready', 'claimed' => 'Claimed', 'cancelled' => 'Cancelled'] as $s => $l): ?>
              <option value="<?= e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="orders-filter-field">
          <label for="orders-type-filter">Order type</label>
          <select class="form-control" id="orders-type-filter" name="type">
            <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All types</option>
            <option value="walk-in" <?= $filterType === 'walk-in' ? 'selected' : '' ?>>Walk-in</option>
            <option value="pre-order" <?= $filterType === 'pre-order' ? 'selected' : '' ?>>Pre-order</option>
          </select>
        </div>
        <div class="orders-filter-field">
          <label for="orders-payment-filter">Payment method</label>
          <select class="form-control" id="orders-payment-filter" name="payment">
            <option value="all" <?= $filterPayment === 'all' ? 'selected' : '' ?>>All payment methods</option>
            <option value="cash" <?= $filterPayment === 'cash' ? 'selected' : '' ?>>Cash</option>
            <option value="GCash" <?= $filterPayment === 'GCash' ? 'selected' : '' ?>>GCash</option>
          </select>
        </div>
        <div class="orders-filter-field">
          <label for="orders-cashier-filter">Cashier</label>
          <select class="form-control" id="orders-cashier-filter" name="cashier">
            <option value="0" <?= $filterCashier === 0 ? 'selected' : '' ?>>All cashiers</option>
            <?php foreach ($allCashiers as $csh): ?>
              <option value="<?= (int) $csh['id'] ?>" <?= $filterCashier === (int) $csh['id'] ? 'selected' : '' ?>><?= e($csh['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <fieldset class="orders-date-fieldset">
          <legend class="orders-filter-legend">Specific order dates</legend>
          <div class="orders-date-presets" role="group" aria-label="Quick date ranges">
            <span class="orders-date-presets-label">Quick ranges</span>
            <?php foreach ($quickDateRanges as $key => $range): ?>
              <button type="button" class="orders-date-preset <?= $activeDatePreset === $key ? 'active' : '' ?>" data-date-from="<?= e($range['from']) ?>" data-date-to="<?= e($range['to']) ?>" aria-pressed="<?= $activeDatePreset === $key ? 'true' : 'false' ?>"><?= e($range['label']) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="orders-date-grid">
            <div class="orders-filter-field">
              <label for="orders-date-from">From date</label>
              <input class="form-control" type="date" id="orders-date-from" name="date_from" value="<?= e($filterDateFrom) ?>">
            </div>
            <div class="orders-filter-field">
              <label for="orders-date-to">To date</label>
              <input class="form-control" type="date" id="orders-date-to" name="date_to" value="<?= e($filterDateTo) ?>">
            </div>
          </div>
          <?php if ($dateFilterError !== ''): ?><div class="orders-filter-error" role="alert"><?= e($dateFilterError) ?></div><?php endif; ?>
        </fieldset>
        <div class="orders-filter-field">
          <label for="orders-sort-filter">Sort by order date</label>
          <select class="form-control" id="orders-sort-filter" name="sort">
            <option value="newest" <?= $filterSort === 'newest' ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= $filterSort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
          </select>
        </div>
      </div>
    </form>
    <div class="orders-filter-modal-foot">
      <a href="?" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Reset filters</a>
      <div class="orders-filter-modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeOrdersFilter()">Cancel</button>
        <button type="submit" form="orders-filter-form" class="btn btn-primary"><i class="fa-solid fa-check"></i> Apply filters</button>
      </div>
    </div>
  </section>
</div>

<div class="card orders-results-card">
  <div class="orders-results-toolbar">
    <label class="orders-search" for="orders-search">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input class="form-control" type="search" id="orders-search" placeholder="Search order number, customer, or cashier" autocomplete="off">
    </label>
    <span class="orders-result-count" id="orders-result-count" aria-live="polite"><?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?> shown<?= count($orders) >= 300 ? ' · first 300' : '' ?></span>
  </div>
  <div class="orders-table-wrap">
    <table class="data-table orders-table">
      <thead>
        <tr>
          <th>Order No.</th>
          <th>Customer</th>
          <th>Cashier</th>
          <th>Type</th>
          <th class="num">Total</th>
          <th>Payment</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($orders)): ?>
          <tr>
            <td colspan="8">
              <div class="empty-state">
                <i class="fa-solid fa-inbox"></i>
                <h3>No matching orders</h3>
                <p>Try another status, date range, or filter.</p>
              </div>
            </td>
          </tr>
          <?php else: foreach ($orders as $o): ?>
            <tr class="orders-row" data-order-id="<?= (int) $o['id'] ?>" data-order-search="<?= e(mb_strtolower($o['order_number'] . ' ' . $o['customer'] . ' ' . $o['cashier_name'])) ?>" tabindex="0" aria-label="View receipt for order <?= e($o['order_number']) ?>" onclick="openReceipt(<?= $o['id'] ?>)" title="Click to view receipt">
              <td><span class="orders-table-order"><?= e($o['order_number']) ?></span></td>
              <td><span class="orders-customer"><?= e($o['customer']) ?></span></td>
              <td>
                <span class="orders-cashier">
                  <?php if ($o['cashier_name'] !== '—'): ?>
                    <span class="orders-cashier-icon">
                      <i class="fa-solid fa-cash-register"></i>
                    </span>
                  <?php endif; ?>
                  <?= e($o['cashier_name']) ?>
                </span>
              </td>
              <td><span class="badge badge-<?= $o['order_type'] === 'walk-in' ? 'walkin' : 'preorder' ?>"><?= e($o['order_type']) ?></span></td>
              <td class="num orders-total"><?= peso($o['total_amount']) ?></td>
              <?php $paymentBadge = match ($o['payment_status'] ?? '') {
                'paid' => 'paid',
                'refunded' => 'refunded',
                'pending' => 'pending',
                'failed' => 'cancelled',
                default => ''
              }; ?>
              <td>
                <div class="orders-payment"><span class="orders-payment-method"><?= e($o['payment_method'] ?? '—') ?></span><?php if ($paymentBadge !== ''): ?><span class="badge badge-<?= $paymentBadge ?>"><?= e(ucfirst($o['payment_status'])) ?></span><?php endif; ?></div>
              </td>
              <td><span class="badge badge-<?= e($o['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $o['status']))) ?></span></td>
              <td class="orders-date"><strong><?= date('M j, Y', strtotime($o['created_at'])) ?></strong><time datetime="<?= e(date(DATE_ATOM, strtotime($o['created_at']))) ?>"><?= date('g:i A', strtotime($o['created_at'])) ?></time></td>
            </tr>
        <?php endforeach;
        endif; ?>
        <?php if (!empty($orders)): ?><tr id="orders-search-empty" class="orders-empty-search" hidden>
            <td colspan="8"><i class="fa-solid fa-magnifying-glass" style="display:block;font-size:1.2rem;margin-bottom:8px"></i>No orders match your search.</td>
          </tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Receipt Modal ──────────────────────────────────────────── -->
<div class="receipt-overlay" id="receipt-overlay">
  <div class="receipt-modal">
    <div class="receipt-modal-head">
      <div class="receipt-modal-head-title">
        <i class="fa-solid fa-receipt"></i> Receipt
      </div>
      <div class="receipt-modal-head-actions">
        <button class="btn btn-ghost btn-sm" onclick="printReceipt()">
          <i class="fa-solid fa-print"></i> Print
        </button>
        <button class="receipt-close" onclick="closeReceipt()">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    </div>
    <div class="receipt-body" id="receipt-body">
      <div class="receipt-body-content">
        <div class="receipt-loading">
          <i class="fa-solid fa-circle-notch fa-spin"></i> Loading…
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const RECEIPT_URL = '<?= APP_URL ?>/receipt_view.php?order_id=';

  function openReceipt(orderId) {
    currentOrderId = orderId;
    document.getElementById('receipt-body').innerHTML =
      '<div class="receipt-loading"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading…</div>';
    document.getElementById('receipt-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch(RECEIPT_URL + orderId, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          throw new Error(data.error || 'Failed to load');
        }
        document.getElementById('receipt-body').innerHTML =
          '<div class="receipt-body-content">' + data.html + '</div>';
      })
      .catch(err => {
        document.getElementById('receipt-body').innerHTML =
          '<div class="receipt-loading" style="color:var(--status-cancelled)">' +
          '<i class="fa-solid fa-circle-exclamation"></i> ' + (err.message || 'Failed to load receipt.') + '</div>';
      });
  }

  function closeReceipt() {
    document.getElementById('receipt-overlay').classList.remove('open');
    document.body.style.overflow = '';
    currentOrderId = null;
  }

  // Current order ID for print reference
  let currentOrderId = null;

  function printReceipt() {
    if (!currentOrderId) return;
    const printUrl = `<?= APP_URL ?>/receipt_view.php?order_id=${currentOrderId}&format=print`;
    const win = window.open(printUrl, '_blank', 'width=420,height=680');
    win?.focus();
  }

  const ordersSearch = document.getElementById('orders-search');
  const orderRows = Array.from(document.querySelectorAll('.orders-row'));
  const ordersSearchEmpty = document.getElementById('orders-search-empty');
  const ordersResultCount = document.getElementById('orders-result-count');
  const ordersFilterOverlay = document.getElementById('orders-filter-overlay');
  const ordersFilterTrigger = document.querySelector('.orders-filter-trigger');
  const orderDateFrom = document.getElementById('orders-date-from');
  const orderDateTo = document.getElementById('orders-date-to');

  function openOrdersFilter() {
    ordersFilterOverlay.hidden = false;
    ordersFilterOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('orders-status-filter').focus();
  }

  function closeOrdersFilter() {
    ordersFilterOverlay.classList.remove('open');
    ordersFilterOverlay.hidden = true;
    document.body.style.overflow = '';
    ordersFilterTrigger.focus();
  }

  ordersFilterOverlay.addEventListener('click', event => {
    if (event.target === ordersFilterOverlay) closeOrdersFilter();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !ordersFilterOverlay.hidden) closeOrdersFilter();
  });

  document.querySelectorAll('.orders-date-preset').forEach(button => {
    button.addEventListener('click', () => {
      orderDateFrom.value = button.dataset.dateFrom;
      orderDateTo.value = button.dataset.dateTo;
      document.querySelectorAll('.orders-date-preset').forEach(preset => {
        const active = preset === button;
        preset.classList.toggle('active', active);
        preset.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    });
  });
  [orderDateFrom, orderDateTo].forEach(input => input.addEventListener('input', () => {
    document.querySelectorAll('.orders-date-preset').forEach(button => {
      button.classList.remove('active');
      button.setAttribute('aria-pressed', 'false');
    });
  }));

  function filterOrderRows() {
    const query = ordersSearch.value.trim().toLocaleLowerCase();
    let visible = 0;
    orderRows.forEach(row => {
      const matches = row.dataset.orderSearch.includes(query);
      row.hidden = !matches;
      if (matches) visible++;
    });
    if (ordersSearchEmpty) ordersSearchEmpty.hidden = query === '' || visible > 0;
    if (ordersResultCount) {
      const suffix = orderRows.length >= 300 ? ' · first 300' : '';
      ordersResultCount.textContent = query ?
        `${visible} of ${orderRows.length} orders match${suffix}` :
        `${orderRows.length} order${orderRows.length === 1 ? '' : 's'} shown${suffix}`;
    }
  }

  ordersSearch?.addEventListener('input', filterOrderRows);
  orderRows.forEach(row => row.addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openReceipt(Number(row.dataset.orderId));
    }
  }));

  document.getElementById('receipt-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeReceipt();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeReceipt();
  });
</script>

<?php layoutFooter(); ?>