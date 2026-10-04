<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_FACULTY);

$db  = Database::getInstance();
$uid = currentUserId();

// Get faculty info
$stmt = $db->prepare("SELECT * FROM faculty WHERE id = ?");
$stmt->execute([$uid]);
$faculty = $stmt->fetch();

// Active orders (faculty orders stored with faculty reference)
// For now, faculty can view their active orders similar to students
$activeOrders = $db->prepare("
  SELECT * FROM orders 
  WHERE faculty_id = ?
  AND status IN ('pending','preparing','ready')
  ORDER BY created_at DESC
");
$activeOrders->execute([$uid]);
$active = $activeOrders->fetchAll();

$stmt = $db->prepare("
  SELECT COUNT(*) FROM orders 
  WHERE faculty_id = ?
  AND status != 'cancelled'
");
$stmt->execute([$uid]);
$totalOrders = (int)$stmt->fetchColumn();

$stmt = $db->prepare("
SELECT COALESCE(SUM(amount_paid),0)
FROM payments p
JOIN orders o ON o.id = p.order_id
WHERE o.faculty_id = ?
AND p.payment_status = 'paid'
");
$stmt->execute([$uid]);
$totalSpent = (float)$stmt->fetchColumn();

$stmt = $db->prepare("
  SELECT COUNT(*) FROM orders 
  WHERE faculty_id = ?
  AND DATE(created_at) = CURDATE()
");
$stmt->execute([$uid]);
$todayOrders = (int)$stmt->fetchColumn();

// Status step map
$stepMap = [
  STATUS_PENDING   => 1,
  STATUS_PREPARING => 2,
  STATUS_READY     => 3,
  STATUS_CLAIMED   => 4
];  

$steps = [
  ['icon' => 'fa-check',        'label' => 'Confirmed'],
  ['icon' => 'fa-peso-sign',    'label' => 'Paid'],
  ['icon' => 'fa-fire',         'label' => 'Preparing'],
  ['icon' => 'fa-bell',         'label' => 'Ready'],
  ['icon' => 'fa-bag-shopping', 'label' => 'Claimed'],
];

layoutHeader('Dashboard');
?>
<?php showFlash('global'); ?>

<div class="page-header">
  <div>
    <div class="page-header-title">Faculty Dashboard</div>
    <div class="page-header-sub"><?= date('l, F j') ?> · <?= e($faculty['full_name'] ?? '') ?> · <?= count($active) > 0 ? count($active) . ' order' . (count($active) !== 1 ? 's' : '') . ' active' : 'No active orders' ?></div>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/faculty/menu.php" class="btn btn-primary">
      <i class="fa-solid fa-utensils"></i> Order Now
    </a>
  </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
  <div class="stat-card stat-red">
    <div class="stat-icon red"><i class="fa-solid fa-clock"></i></div>
    <div class="stat-content">
      <div class="stat-label">Active Orders</div>
      <div class="stat-value"><?= count($active) ?></div>
    </div>
  </div>
  <div class="stat-card stat-brown">
    <div class="stat-icon brown"><i class="fa-solid fa-box-archive"></i></div>
    <div class="stat-content">
      <div class="stat-label">Total Orders</div>
      <div class="stat-value"><?= $totalOrders ?></div>
    </div>
  </div>
  <div class="stat-card stat-gold">
    <div class="stat-icon gold"><i class="fa-solid fa-peso-sign"></i></div>
    <div class="stat-content">
      <div class="stat-label">Total Spent</div>
      <div class="stat-value"><?= peso($totalSpent) ?></div>
    </div>
  </div>
</div>

<?php if (!empty($active)): ?>
  <div class="section-label">Active Orders</div>
  <?php foreach ($active as $ao):
    $step = $stepMap[$ao['status']] ?? 0;
    $pct  = round(($step / (count($steps) - 1)) * 100);
  ?>
    <div class="card mb-4">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> <?= e($ao['order_number']) ?></div>
        <div style="display:flex;align-items:center;gap:var(--space-3)">
          <span class="text-muted" style="font-size:0.78rem"><?= date('M j, g:i A', strtotime($ao['created_at'])) ?></span>
          <span class="badge badge-<?= e($ao['status']) ?>"><?= e($ao['status']) ?></span>
        </div>
      </div>
      <div class="card-body">
        <!-- Stepper -->
        <div class="stepper" style="margin:var(--space-4) 0 var(--space-5)">
          <div class="stepper-line"></div>
          <div class="stepper-line-fill" style="width:<?= min($pct, 90) ?>%"></div>
          <?php foreach ($steps as $i => $s):
            $isDone   = $i < $step;
            $isActive = $i === $step;
          ?>
            <div class="step <?= $isDone ? 'done' : ($isActive ? 'active' : '') ?>">
              <div class="step-dot">
                <i class="fa-solid <?= $isDone ? 'fa-check' : $s['icon'] ?>"></i>
              </div>
              <div class="step-label"><?= $s['label'] ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($ao['status'] === STATUS_READY): ?>
          <div class="alert alert-success" style="margin-bottom:0">
            <i class="fa-solid fa-circle-check"></i>
            <div><strong>Your order is ready!</strong> Go to the counter and present your <strong>faculty ID</strong> to claim it.</div>
          </div>
        <?php elseif ($ao['status'] === STATUS_PREPARING): ?>
          <div class="alert alert-info" style="margin-bottom:0">
            <i class="fa-solid fa-fire"></i>
            <div>Your order is being prepared. It'll be ready soon!</div>
          </div>
        <?php elseif ($ao['status'] === STATUS_PENDING): ?>
          <div class="alert alert-warning" style="margin-bottom:0">
            <i class="fa-solid fa-clock"></i>
            <div>Your order has been confirmed and will be prepared shortly.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php else: ?>
  <div class="card">
    <div class="empty-state">
      <i class="fa-solid fa-mug-hot"></i>
      <h3>No active orders</h3>
      <p>Place a pre-order to skip the queue!</p>
      <a href="<?= APP_URL ?>/faculty/menu.php" class="btn btn-primary mt-3">
        <i class="fa-solid fa-utensils"></i> Browse Menu
      </a>
    </div>
  </div>
<?php endif; ?>

<?php layoutFooter(); ?>
