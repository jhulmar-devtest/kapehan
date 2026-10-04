<?php
// ============================================================
// public/admin/sales.php
//
// Tab 1 of 4 (item 13). Replaces dashboard.php + reports.php.
// Shows today's numbers, a 7-day trend, and the latest 5-10
// transactions, with a button out to the full order history
// (admin/orders.php — kept as its own page, just not in the nav
// anymore since it's one click away from here).
// ============================================================

require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

// ── KPI cards ────────────────────────────────────────────────
$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status!='cancelled'");
$stmt->execute();
$todaySales = (float) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE() AND status!='cancelled'");
$stmt->execute();
$todayCount = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE order_type='pre-order' AND status IN ('pending','preparing','ready')");
$stmt->execute();
$activePreorders = (int) $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM refund_requests WHERE status='pending'");
$pendingRefunds = (int) $stmt->fetchColumn();

// ── Period-based Sales Report + Best Sellers (item 1 & 2) ───
// One period selector drives both sections below, so "Monthly" always
// means the same date range in the totals card and the best-sellers list.
$period = $_GET['period'] ?? 'daily';
if (!in_array($period, ['daily', 'monthly', 'quarterly', 'yearly'], true)) $period = 'daily';

$now = new DateTime();
$qStartMonth = ((int) ceil(((int) $now->format('n')) / 3) - 1) * 3 + 1;

switch ($period) {
  case 'monthly':
    $rangeStart  = $now->format('Y-m-01 00:00:00');
    $rangeEnd    = $now->format('Y-m-t 23:59:59');
    $periodLabel = $now->format('F Y');
    break;
  case 'quarterly':
    $qNum        = (int) (($qStartMonth - 1) / 3) + 1;
    $rangeStart  = $now->format('Y') . '-' . str_pad((string)$qStartMonth, 2, '0', STR_PAD_LEFT) . '-01 00:00:00';
    $rangeEnd    = date('Y-m-t 23:59:59', mktime(0, 0, 0, $qStartMonth + 2, 1, (int) $now->format('Y')));
    $periodLabel = "Q{$qNum} " . $now->format('Y');
    break;
  case 'yearly':
    $rangeStart  = $now->format('Y-01-01 00:00:00');
    $rangeEnd    = $now->format('Y-12-31 23:59:59');
    $periodLabel = $now->format('Y');
    break;
  default: // daily
    $rangeStart  = $now->format('Y-m-d 00:00:00');
    $rangeEnd    = $now->format('Y-m-d 23:59:59');
    $periodLabel = $now->format('F j, Y');
}

$stmt = $db->prepare(
  "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
   FROM orders WHERE created_at BETWEEN ? AND ? AND status != 'cancelled'"
);
$stmt->execute([$rangeStart, $rangeEnd]);
$periodRow   = $stmt->fetch();
$periodTotal = (float) $periodRow['total'];
$periodCount = (int) $periodRow['cnt'];
$periodAvg   = $periodCount > 0 ? $periodTotal / $periodCount : 0;

// Chart granularity changes with the period: hours within a day, days
// within a month, months within a quarter/year — always the natural
// breakdown for that span rather than one fixed bucket size.
switch ($period) {
  case 'monthly':
    $daysInMonth = (int) $now->format('t');
    $buckets = array_fill(1, $daysInMonth, 0.0);
    $stmt = $db->prepare("SELECT DAY(created_at) k, SUM(total_amount) t FROM orders WHERE created_at BETWEEN ? AND ? AND status!='cancelled' GROUP BY k");
    $stmt->execute([$rangeStart, $rangeEnd]);
    foreach ($stmt->fetchAll() as $r) $buckets[(int) $r['k']] = (float) $r['t'];
    $reportChartLabels = array_keys($buckets);
    $reportChartData   = array_values($buckets);
    break;
  case 'quarterly':
    $months  = range($qStartMonth, $qStartMonth + 2);
    $buckets = array_fill_keys($months, 0.0);
    $stmt = $db->prepare("SELECT MONTH(created_at) k, SUM(total_amount) t FROM orders WHERE created_at BETWEEN ? AND ? AND status!='cancelled' GROUP BY k");
    $stmt->execute([$rangeStart, $rangeEnd]);
    foreach ($stmt->fetchAll() as $r) $buckets[(int) $r['k']] = (float) $r['t'];
    $reportChartLabels = array_map(fn($m) => date('F', mktime(0, 0, 0, $m, 1)), $months);
    $reportChartData   = array_values($buckets);
    break;
  case 'yearly':
    $buckets = array_fill(1, 12, 0.0);
    $stmt = $db->prepare("SELECT MONTH(created_at) k, SUM(total_amount) t FROM orders WHERE created_at BETWEEN ? AND ? AND status!='cancelled' GROUP BY k");
    $stmt->execute([$rangeStart, $rangeEnd]);
    foreach ($stmt->fetchAll() as $r) $buckets[(int) $r['k']] = (float) $r['t'];
    $reportChartLabels = array_map(fn($m) => date('M', mktime(0, 0, 0, $m, 1)), range(1, 12));
    $reportChartData   = array_values($buckets);
    break;
  default: // daily
    $buckets = array_fill(0, 24, 0.0);
    $stmt = $db->prepare("SELECT HOUR(created_at) k, SUM(total_amount) t FROM orders WHERE created_at BETWEEN ? AND ? AND status!='cancelled' GROUP BY k");
    $stmt->execute([$rangeStart, $rangeEnd]);
    foreach ($stmt->fetchAll() as $r) $buckets[(int) $r['k']] = (float) $r['t'];
    $reportChartLabels = array_map(fn($h) => date('ga', mktime($h, 0, 0)), range(0, 23));
    $reportChartData   = array_values($buckets);
}

// Best Sellers for the same period/range, ranked by quantity actually sold.
$stmt = $db->prepare(
  "SELECT p.id, p.name, p.image_path,
          SUM(od.quantity) AS qty_sold,
          SUM(od.subtotal) AS revenue
   FROM order_details od
   JOIN orders o   ON o.id = od.order_id
   JOIN products p ON p.id = od.product_id
   WHERE o.created_at BETWEEN ? AND ? AND o.status != 'cancelled'
   GROUP BY p.id
   ORDER BY qty_sold DESC, revenue DESC
   LIMIT 5"
);
$stmt->execute([$rangeStart, $rangeEnd]);
$bestSellers = $stmt->fetchAll();

// ── Revenue trend (last 7 days) ─────────────────────────────
$stmt = $db->prepare("SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS t
  FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status != 'cancelled'
  GROUP BY DATE(created_at) ORDER BY d");
$stmt->execute();
$salesRaw = $stmt->fetchAll();
$salesByDate = [];
for ($i = 6; $i >= 0; $i--) {
  $salesByDate[date('Y-m-d', strtotime("-{$i} days"))] = 0;
}
foreach ($salesRaw as $r) $salesByDate[$r['d']] = (float) $r['t'];
$chartLabels = array_map(fn($d) => date('D', strtotime($d)), array_keys($salesByDate));
$chartData   = array_values($salesByDate);

// ── Latest transactions (5-10, per item 13) ─────────────────
$txLimit = 10;
$stmt = $db->prepare(
  "SELECT o.order_number, o.order_type, o.status, o.total_amount, o.created_at,
          p.payment_method, p.payment_status,
          COALESCE(s.full_name, f.full_name, 'Walk-in') AS customer
   FROM orders o
   LEFT JOIN payments p ON o.id = p.order_id
   LEFT JOIN students s ON o.student_id = s.id
   LEFT JOIN faculty  f ON o.faculty_id = f.id
   ORDER BY o.created_at DESC
   LIMIT {$txLimit}"
);
$stmt->execute();
$recentOrders = $stmt->fetchAll();

layoutHeader('Sales', '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>');
?>
<style>
  .chart-card {
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 20px;
    margin-bottom: 22px;
  }

  .tx-card {
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 20px;
  }

  .tx-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
  }

  /* ── Period Sales Report + Best Sellers (item 1 & 2) ── */
  .report-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-bottom: 16px;
  }

  .report-title {
    font-weight: 700;
    font-size: 1rem;
  }

  .report-sub {
    font-size: 0.78rem;
    color: var(--text-muted);
    margin-top: 2px;
  }

  .report-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 20px;
    margin-bottom: 22px;
  }

  @media (max-width: 1000px) {
    .report-grid {
      grid-template-columns: 1fr;
    }
  }

  .mini-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 16px;
  }

  .mini-stat {
    background: var(--surface-raised);
    border-radius: var(--radius-md);
    padding: 12px 14px;
  }

  .mini-stat-label {
    font-size: 0.72rem;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 4px;
  }

  .mini-stat-value {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--text-color);
  }

  @media (max-width: 560px) {
    .mini-stats {
      grid-template-columns: 1fr;
    }
  }

  .best-seller-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
  }

  .best-seller-row:last-child {
    border-bottom: none;
  }

  .best-seller-rank {
    flex: 0 0 auto;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--primary-subtle);
    color: var(--primary-color);
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .best-seller-name {
    font-weight: 600;
    font-size: 13.5px;
  }

  .best-seller-meta {
    font-size: 12px;
    color: var(--text-muted);
  }

  .best-seller-revenue {
    margin-left: auto;
    font-weight: 700;
    color: var(--primary-color);
    font-size: 13.5px;
    white-space: nowrap;
  }
</style>

<div class="stats-grid">
  <div class="stat-card stat-red">
    <div class="stat-icon red"><i class="fa-solid fa-peso-sign"></i></div>
    <div class="stat-content">
      <div class="stat-label">Today's Sales</div>
      <div class="stat-value"><?= peso($todaySales) ?></div>
    </div>
  </div>
  <div class="stat-card stat-brown">
    <div class="stat-icon brown"><i class="fa-solid fa-receipt"></i></div>
    <div class="stat-content">
      <div class="stat-label">Today's Orders</div>
      <div class="stat-value"><?= $todayCount ?></div>
    </div>
  </div>
  <div class="stat-card <?= $activePreorders > 0 ? 'stat-gold' : '' ?>">
    <div class="stat-icon <?= $activePreorders > 0 ? 'gold' : 'brown' ?>"><i class="fa-solid fa-clock"></i></div>
    <div class="stat-content">
      <div class="stat-label">Active Pre-orders</div>
      <div class="stat-value"><?= $activePreorders ?></div>
    </div>
  </div>
  <div class="stat-card <?= $pendingRefunds > 0 ? 'stat-red' : '' ?>">
    <div class="stat-icon <?= $pendingRefunds > 0 ? 'red' : 'brown' ?>"><i class="fa-solid fa-rotate-left"></i></div>
    <div class="stat-content">
      <div class="stat-label">Pending Refunds</div>
      <div class="stat-value"><?= $pendingRefunds ?></div>
      <?php if ($pendingRefunds > 0): ?>
        <div class="stat-sub"><a href="<?= APP_URL ?>/admin/orders.php" style="color:var(--primary-color);font-weight:600">Review now &rarr;</a></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="report-head">
  <div>
    <div class="report-title">Sales Report</div>
    <div class="report-sub"><?= e($periodLabel) ?></div>
  </div>
  <div class="tab-bar">
    <?php foreach (['daily' => 'Daily', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'] as $p => $l): ?>
      <a href="?period=<?= $p ?>" class="tab-btn <?= $period === $p ? 'active' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="report-grid">
  <div class="chart-card" style="margin-bottom:0">
    <div class="mini-stats">
      <div class="mini-stat">
        <div class="mini-stat-label">Total Sales</div>
        <div class="mini-stat-value"><?= peso($periodTotal) ?></div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-label">Orders</div>
        <div class="mini-stat-value"><?= $periodCount ?></div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-label">Avg. Order</div>
        <div class="mini-stat-value"><?= peso($periodAvg) ?></div>
      </div>
    </div>
    <canvas id="reportChart" height="110"></canvas>
  </div>

  <div class="chart-card" style="margin-bottom:0">
    <div style="font-weight:700;margin-bottom:4px">Best Sellers</div>
    <div class="report-sub" style="margin-bottom:12px">Ranked by quantity sold &middot; <?= e($periodLabel) ?></div>
    <?php if (empty($bestSellers)): ?>
      <p style="color:var(--text-muted);font-size:13.5px;padding:20px 0;text-align:center">No sales in this period yet.</p>
      <?php else: foreach ($bestSellers as $i => $bs): ?>
        <div class="best-seller-row">
          <div class="best-seller-rank"><?= $i + 1 ?></div>
          <div>
            <div class="best-seller-name"><?= e($bs['name']) ?></div>
            <div class="best-seller-meta"><?= (int) $bs['qty_sold'] ?> sold</div>
          </div>
          <div class="best-seller-revenue"><?= peso($bs['revenue']) ?></div>
        </div>
    <?php endforeach;
    endif; ?>
  </div>
</div>

<div class="chart-card">
  <div style="font-weight:700;margin-bottom:12px">Last 7 Days</div>
  <canvas id="revenueChart" height="80"></canvas>
</div>

<div class="tx-card">
  <div class="tx-card-head">
    <div style="font-weight:700">Latest Transactions</div>
    <a href="<?= APP_URL ?>/admin/orders.php" class="btn btn-outline btn-sm">
      <i class="fa-solid fa-list-check"></i> View All Orders
    </a>
  </div>
  <div style="overflow-x:auto">
    <table class="data-table">
      <thead>
        <tr>
          <th>Order #</th>
          <th>Customer</th>
          <th>Type</th>
          <th>Payment</th>
          <th>Status</th>
          <th>Total</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recentOrders)): ?>
          <tr>
            <td colspan="7" style="text-align:center;padding:26px;color:var(--text-muted)">No transactions yet.</td>
          </tr>
          <?php else: foreach ($recentOrders as $o): ?>
            <tr>
              <td><code><?= e($o['order_number']) ?></code></td>
              <td><?= e($o['customer']) ?></td>
              <td><?= e(ucfirst(str_replace('-', ' ', $o['order_type']))) ?></td>
              <td><?= e($o['payment_method'] ?? '—') ?></td>
              <td><span class="badge badge-<?= e($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span></td>
              <td><?= peso($o['total_amount']) ?></td>
              <td><?= date('M j, g:i A', strtotime($o['created_at'])) ?></td>
            </tr>
        <?php endforeach;
        endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  // Read the real brand color from CSS so the chart always matches the
  // current theme instead of a hardcoded value that can drift from it
  // (this one had — the site's --primary-color is a red, #c0392b, but
  // this chart was still drawing a leftover brown from an earlier palette).
  const brand = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#c0392b';
  const brandSubtle = getComputedStyle(document.documentElement).getPropertyValue('--primary-subtle').trim() || 'rgba(192, 57, 43, 0.08)';

  new Chart(document.getElementById('reportChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($reportChartLabels) ?>,
      datasets: [{
        label: 'Sales',
        data: <?= json_encode($reportChartData) ?>,
        backgroundColor: brand,
        borderRadius: 4,
        maxBarThickness: 36,
      }],
    },
    options: {
      plugins: {
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          beginAtZero: true
        }
      },
    },
  });

  new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($chartLabels) ?>,
      datasets: [{
        label: 'Revenue',
        data: <?= json_encode($chartData) ?>,
        borderColor: brand,
        backgroundColor: brandSubtle,
        tension: 0.35,
        fill: true,
      }],
    },
    options: {
      plugins: {
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          beginAtZero: true
        }
      },
    },
  });
</script>
<?php layoutFooter(); ?>