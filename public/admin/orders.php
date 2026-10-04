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
   ORDER BY o.created_at DESC
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

  .orders-row:hover {
    background: var(--primary-subtle) !important;
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
  <div>
    <div class="page-header-title">All Orders</div>
    <div class="page-header-sub">
      <?= count($orders) ?> orders shown · Click any row to view receipt
    </div>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/admin/sales.php" class="btn btn-ghost btn-sm">
      <i class="fa-solid fa-chart-bar"></i> Reports
    </a>
  </div>
</div>

<?php showFlash('global'); ?>

<!-- Filters -->
<div style="display:flex;gap:var(--space-3);flex-wrap:wrap;margin-bottom:var(--space-4);align-items:center">
  <!-- Status tabs -->
  <div class="tab-bar" style="flex:1;min-width:0">
    <?php foreach (['all' => 'All', 'pending' => 'Pending', 'preparing' => 'Preparing', 'ready' => 'Ready', 'claimed' => 'Claimed', 'cancelled' => 'Cancelled'] as $s => $l): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['status' => $s])) ?>"
        class="tab-btn <?= $filterStatus === $s ? 'active' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <!-- Type + cashier filters -->
  <div style="display:flex;gap:var(--space-2);flex-shrink:0">
    <select class="form-control filter-select"
      onchange="applyFilter('type',this.value)">
      <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
      <option value="walk-in" <?= $filterType === 'walk-in' ? 'selected' : '' ?>>Walk-in</option>
      <option value="pre-order" <?= $filterType === 'pre-order' ? 'selected' : '' ?>>Pre-order</option>
    </select>
    <select class="form-control filter-select"
      onchange="applyFilter('payment',this.value)">
      <option value="all" <?= $filterPayment === 'all' ? 'selected' : '' ?>>All Payments</option>
      <option value="cash" <?= $filterPayment === 'cash' ? 'selected' : '' ?>>Cash</option>
      <option value="GCash" <?= $filterPayment === 'GCash' ? 'selected' : '' ?>>GCash</option>
    </select>
    <select class="form-control filter-select"
      onchange="applyFilter('cashier',this.value)">
      <option value="0" <?= $filterCashier === 0 ? 'selected' : '' ?>>All Cashiers</option>
      <?php foreach ($allCashiers as $csh): ?>
        <option value="<?= $csh['id'] ?>" <?= $filterCashier === $csh['id'] ? 'selected' : '' ?>>
          <?= e($csh['full_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php if ($filterStatus !== 'all' || $filterType !== 'all' || $filterCashier > 0 || $filterPayment !== 'all'): ?>
      <a href="?" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div style="overflow-x:auto">
    <table class="data-table">
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
                <i class="fa-solid fa-receipt"></i>
                <h3>No orders found</h3>
                <p>Try adjusting the filters above.</p>
              </div>
            </td>
          </tr>
          <?php else: foreach ($orders as $o): ?>
            <tr class="orders-row" onclick="openReceipt(<?= $o['id'] ?>)" title="Click to view receipt">
              <td><strong><?= e($o['order_number']) ?></strong></td>
              <td><?= e($o['customer']) ?></td>
              <td>
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.80rem">
                  <?php if ($o['cashier_name'] !== '—'): ?>
                    <span style="width:20px;height:20px;border-radius:var(--radius-full);background:var(--primary-subtle);display:inline-flex;align-items:center;justify-content:center;color:var(--primary-color);font-size:10px;flex-shrink:0">
                      <i class="fa-solid fa-cash-register"></i>
                    </span>
                  <?php endif; ?>
                  <?= e($o['cashier_name']) ?>
                </span>
              </td>
              <td><span class="badge badge-<?= $o['order_type'] === 'walk-in' ? 'walkin' : 'preorder' ?>"><?= e($o['order_type']) ?></span></td>
              <td class="num"><?= peso($o['total_amount']) ?></td>
              <td><?= e($o['payment_method'] ?? '—') ?></td>
              <td><span class="badge badge-<?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
              <td class="text-muted"><?= date('M j, Y g:i A', strtotime($o['created_at'])) ?></td>
            </tr>
        <?php endforeach;
        endif; ?>
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

  function applyFilter(key, val) {
    const url = new URL(window.location.href);
    url.searchParams.set(key, val);
    window.location.href = url.toString();
  }

  document.getElementById('receipt-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeReceipt();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeReceipt();
  });
</script>

<?php layoutFooter(); ?>