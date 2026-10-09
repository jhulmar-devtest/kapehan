<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/cash-drawer.php';
requireRole(ROLE_CASHIER);
$db = Database::getInstance();
$cid = currentUserId();
$openDrawer = getOpenCashDrawer($db);

$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(created_at)=CURDATE() AND cashier_id=? AND status!='cancelled'");
$stmt->execute([$cid]);
$myToday = (float)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE() AND cashier_id=? AND status!='cancelled'");
$stmt->execute([$cid]);
$myCount = (int)$stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM orders WHERE order_type='pre-order' AND status='pending'");
$pending = (int)$stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM orders WHERE order_type='pre-order' AND status='ready'");
$ready = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT o.order_number,o.total_amount,o.created_at FROM orders o WHERE o.cashier_id=? AND DATE(o.created_at)=CURDATE() AND o.status!='cancelled' ORDER BY o.created_at DESC LIMIT 5");
$stmt->execute([$cid]);
$recent = $stmt->fetchAll();

layoutHeader('Dashboard');
?>
<div class="page-header">
  <div>
    <div class="page-header-title">Good <?= date('G') < 12 ? 'morning' : (date('G') < 17 ? 'afternoon' : 'evening') ?>!</div>
    <div class="page-header-sub"><?= date('l, F j, Y') ?> · Your shift summary</div>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/cashier/walkin.php" class="btn btn-primary">
      <i class="fa-solid fa-plus"></i> New Walk-in Order
    </a>
  </div>
</div>

<div style="display:flex;align-items:center;gap:7px;color:var(--text-muted);font-size:.76rem;margin:-8px 0 16px">
  <i class="fa-solid fa-arrows-rotate" style="color:var(--status-ready)" aria-hidden="true"></i>
  <span>Updated <?= date('g:i:s A') ?> · refreshes every 30 seconds while this tab is open</span>
</div>

<div class="stats-grid">
  <div class="stat-card stat-red">
    <div class="stat-icon red"><i class="fa-solid fa-peso-sign"></i></div>
    <div class="stat-content">
      <div class="stat-label">My Revenue Today</div>
      <div class="stat-value"><?= peso($myToday) ?></div>
      <div class="stat-sub"><?= $myCount ?> transaction<?= $myCount !== 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div class="stat-card stat-gold">
    <div class="stat-icon gold"><i class="fa-solid fa-clock"></i></div>
    <div class="stat-content">
      <div class="stat-label">Pending Pre-orders</div>
      <div class="stat-value"><?= $pending ?></div>
      <div class="stat-sub"><?= $pending > 0 ? '<a href="' . APP_URL . '/cashier/preorders.php" style="color:var(--primary-color);font-weight:600">Start preparing →</a>' : 'Queue is clear' ?></div>
    </div>
  </div>
  <div class="stat-card <?= $ready > 0 ? 'stat-green' : '' ?>">
    <div class="stat-icon <?= $ready > 0 ? 'green' : 'brown' ?>"><i class="fa-solid fa-bell"></i></div>
    <div class="stat-content">
      <div class="stat-label">Ready to Claim</div>
      <div class="stat-value"><?= $ready ?></div>
      <div class="stat-sub"><?= $ready > 0 ? '<a href="' . APP_URL . '/cashier/preorders.php" style="color:var(--status-ready);font-weight:600">Verify IDs →</a>' : 'None waiting' ?></div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-5)">
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-bolt"></i> Quick Actions</div>
    </div>
    <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
      <a href="<?= APP_URL ?>/cashier/walkin.php" class="btn btn-primary btn-lg w-full" style="flex-direction:column;height:72px;gap:6px">
        <i class="fa-solid fa-store" style="font-size:18px"></i>
        <span>Walk-in POS</span>
      </a>
      <a href="<?= APP_URL ?>/cashier/preorders.php" class="btn btn-ghost btn-lg w-full" style="flex-direction:column;height:72px;gap:6px;position:relative">
        <i class="fa-solid fa-clock" style="font-size:18px"></i>
        <span>Pre-orders</span>
        <?php if ($pending + $ready > 0): ?>
          <span style="position:absolute;top:8px;right:8px;background:var(--primary-color);color:var(--text-on-primary);font-size:0.60rem;font-weight:700;padding:2px 7px;border-radius:var(--radius-full)"><?= $pending + $ready ?></span>
        <?php endif; ?>
      </a>
      <a href="<?= APP_URL ?>/cashier/cash-drawer.php" class="btn btn-ghost btn-lg w-full" style="flex-direction:column;height:72px;gap:6px">
        <i class="fa-solid fa-cash-register" style="font-size:18px"></i>
        <span><?= $openDrawer ? 'View Cash Drawer' : 'Open Cash Drawer' ?></span>
      </a>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Transactions</div>
      <a href="<?= APP_URL ?>/cashier/receipt.php" class="btn btn-ghost btn-sm">Last Receipt</a>
    </div>
    <?php if (empty($recent)): ?>
      <div class="empty-state" style="padding:var(--space-5)"><i class="fa-solid fa-receipt"></i>
        <p>No transactions yet today</p>
      </div>
    <?php else: ?>
      <table class="data-table">
        <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><strong><?= e($r['order_number']) ?></strong></td>
              <td class="num"><?= peso($r['total_amount']) ?></td>
              <td class="text-muted"><?= date('g:i A', strtotime($r['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="alert alert-info mt-4" style="margin-bottom:0">
  <i class="fa-solid fa-circle-info"></i>
  <div>Always verify the student's <strong>physical school ID</strong> before marking any pre-order as <strong>Claimed</strong>.</div>
</div>
<script>
  (() => {
    const refreshDelay = 30000;
    const scrollKey = `cashier-dashboard-scroll:${location.pathname}${location.search}`;

    try {
      const savedScrollY = sessionStorage.getItem(scrollKey);
      if (savedScrollY !== null) {
        sessionStorage.removeItem(scrollKey);
        requestAnimationFrame(() => window.scrollTo(0, Number(savedScrollY) || 0));
      }
    } catch (_) {
      // Storage can be unavailable in restricted browser contexts; refresh still works.
    }

    const scheduleRefresh = () => window.setTimeout(() => {
      if (document.visibilityState !== 'visible') {
        scheduleRefresh();
        return;
      }

      try {
        sessionStorage.setItem(scrollKey, String(window.scrollY));
      } catch (_) {
        // Do not block the data refresh if storage is unavailable.
      }
      window.location.reload();
    }, refreshDelay);

    scheduleRefresh();
  })();
</script>
<?php layoutFooter(); ?>
