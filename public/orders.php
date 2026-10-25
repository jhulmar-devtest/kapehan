<?php
// ============================================================
// public/orders.php
//
// "My Orders" — reached from the profile dropdown on menu.php and
// from the sidebar on account.php. Not in a subfolder, same as
// account.php, since one file serves both students and faculty.
//
// Replaces public/student/orders.php and public/faculty/orders.php,
// merging their feedback-submission and reorder logic into one file.
// ============================================================

require_once __DIR__ . '/../config/init.php';
requireRole(ROLE_STUDENT, ROLE_FACULTY);

$db       = Database::getInstance();
$role     = currentRole();
$userId   = currentUserId();
$isStudent = $role === ROLE_STUDENT;
$fkColumn  = $isStudent ? 'student_id' : 'faculty_id';

// ── AJAX: return order items as JSON (used by Reorder) ──────
if (isset($_GET['get_order_items'])) {
  header('Content-Type: application/json');
  $oid = (int) $_GET['get_order_items'];

  $chk = $db->prepare("SELECT id FROM orders WHERE id = ? AND {$fkColumn} = ?");
  $chk->execute([$oid, $userId]);
  if (!$chk->fetch()) {
    echo json_encode(['items' => []]);
    exit;
  }

  $stmt = $db->prepare(
    "SELECT od.id AS detail_id, od.product_id, od.quantity, od.price_at_time, p.name, p.image_path,
            p.price AS base_price, p.has_sizes, p.has_sugar, p.is_available,
            od.customization_note AS note
     FROM order_details od
     JOIN products p ON od.product_id = p.id
     WHERE od.order_id = ?"
  );
  $stmt->execute([$oid]);
  $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $activeSizes = $db->query('SELECT label, price_adjustment FROM size_options WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
  $sugarOptions = ['Full Sugar', 'Less Sugar', '50% Sugar', 'No Sugar'];

  foreach ($orderItems as &$item) {
    $parts = array_values(array_filter(array_map('trim', explode(' · ', (string) ($item['note'] ?? ''))), static fn($part) => $part !== ''));
    $item['size'] = null;
    $item['sugar'] = null;
    $item['custom_note'] = '';
    $item['addon_ids'] = [];
    $item['addons'] = [];
    $sizeAdjustment = 0.0;

    if ((int) $item['has_sizes'] === 1) {
      foreach ($parts as $index => $part) {
        foreach ($activeSizes as $sizeOption) {
          if ($part === $sizeOption['label']) {
            $item['size'] = $sizeOption['label'];
            $sizeAdjustment = (float) $sizeOption['price_adjustment'];
            unset($parts[$index]);
            break 2;
          }
        }
      }
      if ($item['size'] === null && $activeSizes) {
        $item['size'] = $activeSizes[0]['label'];
        $sizeAdjustment = (float) $activeSizes[0]['price_adjustment'];
      }
    }

    if ((int) $item['has_sugar'] === 1) {
      foreach ($parts as $index => $part) {
        if (in_array($part, $sugarOptions, true)) {
          $item['sugar'] = $part;
          unset($parts[$index]);
          break;
        }
      }
      if ($item['sugar'] === null) $item['sugar'] = $sugarOptions[0];
    }

    try {
      $addonStmt = $db->prepare(
        'SELECT a.id, a.name, a.price
           FROM addons a JOIN product_addons pa ON pa.addon_id = a.id
          WHERE pa.product_id = ? AND a.status = \'active\'
            AND EXISTS (SELECT 1 FROM addon_ingredients ai WHERE ai.addon_id = a.id)
          ORDER BY a.id'
      );
      $addonStmt->execute([(int) $item['product_id']]);
      $availableAddons = $addonStmt->fetchAll(PDO::FETCH_ASSOC);
      $savedAddonStmt = $db->prepare('SELECT addon_id, addon_name_snapshot FROM order_detail_addons WHERE order_detail_id = ? ORDER BY id');
      $savedAddonStmt->execute([(int) $item['detail_id']]);
      $savedAddons = $savedAddonStmt->fetchAll(PDO::FETCH_ASSOC);

      $savedAddonNames = array_column($savedAddons, 'addon_name_snapshot');
    } catch (PDOException $e) {
      // Older installations may not have the add-on snapshot table yet.
      $availableAddons = [];
      $savedAddonNames = [];
    }

    $requestedAddonNames = $savedAddonNames;
    foreach ($parts as $index => $part) {
      if (str_starts_with($part, '+')) {
        $requestedAddonNames = array_merge($requestedAddonNames, preg_split('/\\s*,\\s*\\+/', ltrim($part, '+')) ?: []);
        unset($parts[$index]);
      }
    }
    $addonTotal = 0.0;
    foreach ($availableAddons as $availableAddon) {
      if (in_array($availableAddon['name'], $requestedAddonNames, true)) {
        $item['addon_ids'][] = (int) $availableAddon['id'];
        $item['addons'][] = $availableAddon['name'];
        $addonTotal += (float) $availableAddon['price'];
      }
    }
    $item['unit_price'] = round((float) $item['base_price'] + $sizeAdjustment + $addonTotal, 2);

    foreach ($parts as $part) {
      $item['custom_note'] = trim($item['custom_note'] . ' ' . $part);
    }
    $item['custom_note'] = trim($item['custom_note']);
    unset($item['detail_id'], $item['price_at_time'], $item['base_price'], $item['has_sugar']);
  }
  unset($item);
  echo json_encode(['items' => $orderItems]);
  exit;
}

// ── Handle feedback submission ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
  verifyCsrf();
  $orderId        = (int) ($_POST['order_id'] ?? 0);
  $cashierRating  = (int) ($_POST['rating'] ?? 0);
  $comment        = sanitizeString($_POST['comment'] ?? '', 500);
  $productRatings = $_POST['product_rating'] ?? [];

  if ($cashierRating < 1 || $cashierRating > 5) {
    flash('global', 'Please select an overall star rating.', 'error');
    redirect(APP_URL . '/orders.php');
  }

  $stmt = $db->prepare(
    "SELECT o.id, o.cashier_id FROM orders o WHERE o.id = ? AND o.{$fkColumn} = ? AND o.status = 'claimed'"
  );
  $stmt->execute([$orderId, $userId]);
  $order = $stmt->fetch();
  if (!$order) {
    flash('global', 'Order not found or not yet claimed.', 'error');
    redirect(APP_URL . '/orders.php');
  }

  $stmt = $db->prepare("SELECT id FROM order_feedback WHERE order_id = ?");
  $stmt->execute([$orderId]);
  if ($stmt->fetch()) {
    flash('global', 'You have already rated this order.', 'error');
    redirect(APP_URL . '/orders.php');
  }

  $stmt = $db->prepare("SELECT od.product_id FROM order_details od WHERE od.order_id = ?");
  $stmt->execute([$orderId]);
  $validProductIds = array_column($stmt->fetchAll(), 'product_id');

  $db->beginTransaction();
  try {
    $db->prepare(
      "INSERT INTO order_feedback (order_id, student_id, faculty_id, cashier_id, rating, comment)
       VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
      $orderId,
      $isStudent ? $userId : null,
      $isStudent ? null : $userId,
      $order['cashier_id'] ?: null,
      $cashierRating,
      $comment ?: null,
    ]);
    $feedbackId = (int) $db->lastInsertId();

    if (!empty($productRatings)) {
      $pstmt = $db->prepare(
        "INSERT INTO product_ratings (feedback_id, order_id, product_id, student_id, rating) VALUES (?, ?, ?, ?, ?)"
      );
      foreach ($productRatings as $pid => $prating) {
        $pid = (int) $pid;
        $prating = (int) $prating;
        if (!in_array($pid, $validProductIds, true)) continue;
        if ($prating < 1 || $prating > 5) continue;
        $pstmt->execute([$feedbackId, $orderId, $pid, $isStudent ? $userId : null, $prating]);
      }
    }

    $db->commit();
    flash('global', 'Thank you for your feedback!', 'success');
  } catch (\Throwable $e) {
    $db->rollBack();
    error_log($e->getMessage());
    flash('global', 'Could not save feedback. Please try again.', 'error');
  }
  redirect(APP_URL . '/orders.php');
}

// ── Load orders, filtered by tab ─────────────────────────────
$filter = $_GET['status'] ?? 'all';
$statusGroups = [
  'ongoing'   => ['pending', 'preparing', 'ready'],
  'completed' => ['claimed'],
  'cancelled' => ['cancelled', 'no_show'],
];
$where = '';
$params = [$userId];
if (isset($statusGroups[$filter])) {
  $placeholders = implode(',', array_fill(0, count($statusGroups[$filter]), '?'));
  $where = " AND o.status IN ({$placeholders})";
  $params = array_merge($params, $statusGroups[$filter]);
}

$stmt = $db->prepare(
  "SELECT o.*, p.payment_method, p.payment_status,
          f.id AS feedback_id,
          (SELECT COALESCE(SUM(od.quantity),0) FROM order_details od WHERE od.order_id = o.id) AS item_count
   FROM orders o
   LEFT JOIN payments p ON o.id = p.order_id
   LEFT JOIN order_feedback f ON o.id = f.order_id
   WHERE o.{$fkColumn} = ? {$where}
   ORDER BY
     CASE WHEN o.status IN ('pending', 'preparing', 'ready') THEN 0 ELSE 1 END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready') AND o.pickup_time = 'ASAP' THEN 0 ELSE 1 END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready')
          THEN COALESCE(o.pickup_date, DATE(o.created_at)) ELSE NULL END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready') AND o.pickup_time <> 'ASAP'
          THEN COALESCE(STR_TO_DATE(o.pickup_time, '%H:%i'), TIME(o.created_at))
          WHEN o.status IN ('pending', 'preparing', 'ready') THEN TIME(o.created_at)
          ELSE NULL END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready')
          THEN CASE o.status WHEN 'ready' THEN 0 WHEN 'preparing' THEN 1 ELSE 2 END
          ELSE 0 END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready') THEN o.created_at ELSE NULL END ASC,
     o.created_at DESC"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

function statusGroupOf(string $status): string
{
  if (in_array($status, ['pending', 'preparing', 'ready'], true)) return 'ongoing';
  if ($status === 'claimed') return 'completed';
  return 'cancelled';
}

// Renders the Pending → Preparing → Ready → Claimed progress strip for
// an ongoing order. Returns '' for cancelled/no_show orders — a linear
// timeline doesn't make sense once an order has fallen off that path.
function renderOrderTimeline(string $status): string
{
  $steps = ['pending' => 'Pending', 'preparing' => 'Preparing', 'ready' => 'Ready', 'claimed' => 'Claimed'];
  $order = array_keys($steps);
  $currentIdx = array_search($status, $order, true);
  if ($currentIdx === false) return '';

  $html = '<div class="order-timeline">';
  $i = 0;
  foreach ($steps as $key => $label) {
    $isClaimed = $status === 'claimed';
    $state = $isClaimed || $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'current' : '');
    $icon  = $isClaimed || $i < $currentIdx ? 'fa-check' : ($i === $currentIdx ? 'fa-mug-hot' : '');
    $html .= '<div class="order-timeline-step ' . $state . '">'
      . '<div class="track"></div>'
      . '<div class="dot">' . ($icon ? '<i class="fa-solid ' . $icon . '"></i>' : ($i + 1)) . '</div>'
      . '<div class="label">' . e($label) . '</div>'
      . '</div>';
    $i++;
  }
  $html .= '</div>';
  return $html;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrfToken() ?>">
  <title>My Orders — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/variables.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/account-shell.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/account-shell.css') ?>">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/cart-drawer.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/cart-drawer.css') ?>">
  <style>
    /* ── Page-specific styles ── */
    .order-tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }

    .order-tab {
      padding: 9px 18px;
      border-radius: var(--radius-full);
      font-size: 13.5px;
      font-weight: 700;
      color: var(--text-secondary);
      border: 1px solid var(--border-color);
      background: var(--surface-color);
      cursor: pointer;
      transition: background var(--transition-fast), border-color var(--transition-fast), color var(--transition-fast);
    }

    .order-tab:focus-visible {
      outline: 2px solid var(--primary-color);
      outline-offset: 2px;
    }

    .order-tab.active {
      background: var(--primary-color);
      border-color: var(--primary-color);
      color: var(--text-on-primary);
    }

    .order-row {
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding: 20px 0;
      border-bottom: 1px solid var(--border-color);
    }

    .order-row-top {
      display: flex;
      gap: 16px;
    }

    .order-row:last-child {
      border-bottom: none;
    }

    .order-icon {
      width: 52px;
      height: 52px;
      border-radius: var(--radius-md);
      background: var(--primary-subtle);
      color: var(--primary-color);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      flex-shrink: 0;
    }

    .order-main {
      flex: 1;
      min-width: 0;
    }

    .order-title {
      font-weight: 700;
      font-size: 15px;
    }

    .order-meta {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 3px;
    }

    .order-date {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .order-side {
      text-align: right;
      flex-shrink: 0;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 8px;
    }

    .order-total {
      font-weight: 800;
      font-size: 15.5px;
    }

    .order-actions {
      display: flex;
      gap: 8px;
    }

    .btn-sm {
      padding: 7px 14px;
      font-size: 12.5px;
      border-radius: var(--radius-sm);
    }

    .btn-outline {
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      color: var(--text-color);
    }

    .btn-outline:hover {
      border-color: var(--primary-color);
      color: var(--primary-color);
    }

    .empty-state {
      text-align: center;
      padding: 70px 20px;
      color: var(--text-muted);
    }

    .empty-state i {
      font-size: 34px;
      opacity: .35;
      margin-bottom: 14px;
    }

    /* Rate modal */
    .star-row {
      display: flex;
      gap: 6px;
      font-size: 26px;
      color: var(--border-color);
      cursor: pointer;
      margin: 10px 0 18px;
    }

    .star-row i.active {
      color: #f5a623;
    }

    .modal-box textarea {
      width: 100%;
      padding: 11px 13px;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      font: inherit;
      resize: vertical;
      min-height: 70px;
    }

    @media (max-width: 600px) {
      .order-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        margin-right: -4px;
        padding: 0 4px 5px 0;
        scrollbar-width: none;
      }

      .order-tabs::-webkit-scrollbar {
        display: none;
      }

      .order-tab {
        flex: 0 0 auto;
        padding: 9px 14px;
        font-size: .82rem;
      }

      .order-row {
        gap: 10px;
        padding: 16px 0;
      }

      .order-row-top {
        display: grid;
        grid-template-columns: 42px minmax(0, 1fr);
        gap: 10px;
      }

      .order-icon {
        width: 42px;
        height: 42px;
        font-size: 17px;
      }

      .order-title {
        font-size: .94rem;
        overflow-wrap: anywhere;
      }

      .order-meta,
      .order-date {
        font-size: .8rem;
        line-height: 1.45;
      }

      .order-side {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 8px 10px;
        text-align: left;
      }

      .order-total {
        font-size: 1rem;
      }

      .order-side>.badge {
        justify-self: end;
      }

      .order-actions {
        grid-column: 1 / -1;
        display: flex;
        flex-wrap: wrap;
      }

      .order-actions .btn {
        min-height: 40px;
      }

      .empty-state {
        padding: 44px 12px;
      }

      .star-row {
        min-height: 44px;
        align-items: center;
      }
    }
  </style>
</head>

<body>

  <header class="site-header">
    <div class="container header-inner">
      <a href="<?= APP_URL ?>/menu.php" class="logo">
        <img src="<?= APP_URL ?>/../assets/images/logo.png" alt="<?= APP_NAME ?>" onerror="this.style.display='none'">
        <span>
          <span class="logo-name"><?= e(APP_NAME) ?></span>
          <span class="logo-sub">EARIST Cavite Campus</span>
        </span>
      </a>
      <form class="header-search" role="search" action="<?= APP_URL ?>/menu.php" method="GET">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" name="q" aria-label="Search the menu" placeholder="Search the menu...">
      </form>
      <div class="header-actions">
        <div class="user-menu">
          <button class="user-menu-btn" onclick="toggleUserMenu()" aria-label="Account menu for <?= e($_SESSION['full_name'] ?? 'User') ?>" aria-haspopup="true" aria-controls="userMenuDropdown">
            <i class="fa-solid fa-circle-user" style="font-size:20px;flex:none"></i>
            <span class="user-menu-name"><?= e($_SESSION['full_name'] ?? 'Account') ?></span>
          </button>
          <div class="user-menu-dropdown" id="userMenuDropdown">
            <a href="<?= APP_URL ?>/account.php" class="user-menu-item"><i class="fa-solid fa-user"></i> My Profile</a>
            <a href="<?= APP_URL ?>/orders.php" class="user-menu-item active"><i class="fa-solid fa-box"></i> My Orders</a>
            <hr style="border:0;border-top:1px solid var(--border-color);margin:4px 0">
            <a href="<?= APP_URL ?>/logout.php" class="user-menu-item"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>
          </div>
        </div>
        <button class="cart-btn" onclick="openCart()" aria-label="Cart">
          <i class="fa-solid fa-bag-shopping"></i>
          <span class="cart-badge" id="cartBadge" style="display:none">0</span>
        </button>
      </div>
    </div>

    <nav class="account-subnav">
      <div class="container account-subnav-inner">
        <a href="<?= APP_URL ?>/menu.php" class="back-btn" aria-label="Back to shop"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="account-subnav-title">Account Management</div>
      </div>
    </nav>
  </header>

  <main class="container">
    <?php showFlashAsToast('global'); ?>

    <div class="account-layout">
      <aside class="account-sidebar">
        <a href="<?= APP_URL ?>/account.php" class="account-nav-item"><i class="fa-solid fa-user"></i> My Profile</a>
        <div class="account-nav-item active"><i class="fa-solid fa-box"></i> My Orders</div>
        <a href="<?= APP_URL ?>/logout.php" class="account-nav-item logout"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>
      </aside>

      <div class="account-content">
        <div class="account-content-title">My Orders</div>
        <div class="account-content-sub">Track your order history effortlessly</div>

        <div class="order-tabs">
          <a href="?status=all" class="order-tab <?= $filter === 'all' ? 'active' : '' ?>">All</a>
          <a href="?status=ongoing" class="order-tab <?= $filter === 'ongoing' ? 'active' : '' ?>">Ongoing</a>
          <a href="?status=completed" class="order-tab <?= $filter === 'completed' ? 'active' : '' ?>">Completed</a>
          <a href="?status=cancelled" class="order-tab <?= $filter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
        </div>

        <?php if (empty($orders)): ?>
          <div class="empty-state">
            <i class="fa-solid fa-mug-hot"></i>
            <p>No orders here yet.</p>
          </div>
          <?php else: foreach ($orders as $o):
            $group = statusGroupOf($o['status']);
          ?>
            <div class="order-row" data-order-id="<?= $o['id'] ?>" data-order-number="<?= e($o['order_number']) ?>" data-status="<?= e($o['status']) ?>">
              <div class="order-row-top">
                <div class="order-icon"><i class="fa-solid fa-mug-hot"></i></div>
                <div class="order-main">
                  <div class="order-title"><?= e($o['order_number']) ?></div>
                  <div class="order-meta">Pickup &middot; <?= (int) $o['item_count'] ?> item<?= (int) $o['item_count'] !== 1 ? 's' : '' ?></div>
                  <div class="order-date">
                    <?php if (!empty($o['pickup_date'])): ?>
                      <?= date('j M Y', strtotime($o['pickup_date'])) ?><?= !empty($o['pickup_time']) ? ', ' . ($o['pickup_time'] === 'ASAP' ? 'ASAP' : date('g:i A', strtotime($o['pickup_time']))) : '' ?>
                    <?php else: ?>
                      <?= date('j M Y, g:i A', strtotime($o['created_at'])) ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="order-side">
                  <div class="order-total"><?= peso($o['total_amount']) ?></div>
                  <span class="badge badge-<?= e($o['status']) ?>" data-status-badge><?= e(ucfirst(str_replace('_', ' ', $o['status']))) ?></span>
                  <div class="order-actions" data-order-actions>
                    <?php if ($o['status'] === 'ready'): ?>
                      <button class="btn btn-sm btn-primary" data-qr-btn onclick="openQrModal(<?= $o['id'] ?>, '<?= e($o['order_number']) ?>')">
                        <i class="fa-solid fa-qrcode"></i> Show QR to Claim
                      </button>
                    <?php endif; ?>
                    <?php if ($group === 'completed' && empty($o['feedback_id'])): ?>
                      <button class="btn btn-sm btn-outline" onclick="openRateModal(<?= $o['id'] ?>)">Rate Order</button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-primary" onclick="reorder(<?= $o['id'] ?>)">Reorder</button>
                  </div>
                </div>
              </div>
              <?php if ($group !== 'cancelled'): ?>
                <div data-timeline><?= renderOrderTimeline($o['status']) ?></div>
              <?php endif; ?>
            </div>
        <?php endforeach;
        endif; ?>
      </div>
    </div>
  </main>

  <!-- Rate Order modal -->
  <div class="modal-overlay" id="rateModal" hidden>
    <div class="modal-box">
      <button class="modal-close" onclick="document.getElementById('rateModal').hidden=true">&times;</button>
      <div style="font-size:18px;font-weight:800;margin-bottom:4px">Rate Your Order</div>
      <p style="font-size:13px;color:var(--text-muted)">How was your experience?</p>
      <form method="POST" id="rateForm">
        <?= csrfField() ?>
        <input type="hidden" name="submit_feedback" value="1">
        <input type="hidden" name="order_id" id="rate-order-id">
        <div class="star-row" id="starRow">
          <i class="fa-solid fa-star" data-v="1" onclick="setStar(1)"></i>
          <i class="fa-solid fa-star" data-v="2" onclick="setStar(2)"></i>
          <i class="fa-solid fa-star" data-v="3" onclick="setStar(3)"></i>
          <i class="fa-solid fa-star" data-v="4" onclick="setStar(4)"></i>
          <i class="fa-solid fa-star" data-v="5" onclick="setStar(5)"></i>
        </div>
        <input type="hidden" name="rating" id="rate-rating" value="0">
        <textarea name="comment" placeholder="Anything you'd like to share? (optional)"></textarea>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px">Submit Feedback</button>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="qrModal" hidden>
    <div class="modal-box" style="text-align:center;max-width:340px">
      <button class="modal-close" onclick="closeQrModal()">&times;</button>
      <div style="font-size:18px;font-weight:800;margin-bottom:2px">Show this to the cashier</div>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px" id="qrOrderNum"></p>
      <div id="qrCanvasBox" style="display:flex;align-items:center;justify-content:center;min-height:220px">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;color:var(--text-muted)"></i>
      </div>
      <p style="font-size:12px;color:var(--text-muted);margin-top:14px" id="qrExpiryNote">
        This code is valid for 10 minutes.
      </p>
      <button class="btn btn-ghost" style="width:100%;margin-top:8px" id="qrRefreshBtn" onclick="refreshQr()" hidden>
        <i class="fa-solid fa-rotate"></i> Code expired — Tap to refresh
      </button>
    </div>
  </div>

  <?php require __DIR__ . '/../includes/cart-drawer.php'; ?>

  <script src="<?= APP_URL ?>/../assets/js/qrcode.min.js"></script>
  <script>
    const APP_URL = '<?= APP_URL ?>';
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

    function toggleUserMenu() {
      document.getElementById('userMenuDropdown').classList.toggle('open');
    }
    window.addEventListener('click', e => {
      if (!e.target.closest('.user-menu')) document.getElementById('userMenuDropdown')?.classList.remove('open');
    });

    /* ── Show QR modal (pickup claim) ──
       Mirrors the 10-minute expiry enforced server-side in
       api/qr-claim.php — the countdown here is just so the student/
       faculty isn't caught off guard by a code that silently stopped
       working; refreshQr() gets a freshly-signed one from the same
       endpoint used to render it the first time. */
    let qrCountdownTimer = null;
    let qrCurrentOrderId = null;

    function openQrModal(orderId, orderNumber) {
      qrCurrentOrderId = orderId;
      document.getElementById('qrOrderNum').textContent = orderNumber;
      document.getElementById('qrModal').hidden = false;
      loadQr(orderId);
    }

    function closeQrModal() {
      document.getElementById('qrModal').hidden = true;
      clearInterval(qrCountdownTimer);
    }

    function refreshQr() {
      if (qrCurrentOrderId) loadQr(qrCurrentOrderId);
    }

    function loadQr(orderId) {
      clearInterval(qrCountdownTimer);
      document.getElementById('qrRefreshBtn').hidden = true;
      const box = document.getElementById('qrCanvasBox');
      box.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="font-size:28px;color:var(--text-muted)"></i>';

      fetch(`${APP_URL}/api/qr-generate.php?order_id=${orderId}`)
        .then(r => r.json())
        .then(data => {
          if (!data.success) {
            box.innerHTML = `<p style="color:var(--status-cancelled);font-size:13px;padding:0 10px">${data.message || 'Could not generate a QR code for this order right now.'}</p>`;
            return;
          }
          box.innerHTML = '';
          new QRCode(box, {
            text: data.qr_data,
            width: 200,
            height: 200
          });
          startQrCountdown(600); // seconds — matches the server-side expiry window
        })
        .catch(() => {
          box.innerHTML = '<p style="color:var(--status-cancelled);font-size:13px">Network error — try again.</p>';
        });
    }

    function startQrCountdown(seconds) {
      let remaining = seconds;
      const note = document.getElementById('qrExpiryNote');
      const refreshBtn = document.getElementById('qrRefreshBtn');
      const tick = () => {
        if (remaining <= 0) {
          clearInterval(qrCountdownTimer);
          note.textContent = 'This code has expired.';
          refreshBtn.hidden = false;
          return;
        }
        const m = Math.floor(remaining / 60);
        const s = String(remaining % 60).padStart(2, '0');
        note.textContent = `Valid for ${m}:${s} more`;
        remaining--;
      };
      tick();
      qrCountdownTimer = setInterval(tick, 1000);
    }

    /* ── Rate Order modal ── */
    function openRateModal(orderId) {
      document.getElementById('rate-order-id').value = orderId;
      document.getElementById('rate-rating').value = 0;
      document.querySelectorAll('#starRow i').forEach(s => s.classList.remove('active'));
      document.getElementById('rateModal').hidden = false;
    }

    function setStar(v) {
      document.getElementById('rate-rating').value = v;
      document.querySelectorAll('#starRow i').forEach(s => s.classList.toggle('active', parseInt(s.dataset.v) <= v));
    }

    /* ── Live order-status polling (item: "what happens next") ──
       Ongoing orders (pending/preparing/ready) are re-checked every
       20s so a customer sees "Ready for pickup" the moment the
       cashier updates it, without needing to refresh the page. */
    const STEP_ORDER = ['pending', 'preparing', 'ready', 'claimed'];
    const STEP_LABELS = {
      pending: 'Pending',
      preparing: 'Preparing',
      ready: 'Ready',
      claimed: 'Claimed'
    };

    function renderTimelineHTML(status) {
      const idx = STEP_ORDER.indexOf(status);
      if (idx === -1) return '';
      return '<div class="order-timeline">' + STEP_ORDER.map((key, i) => {
        const isClaimed = status === 'claimed';
        const state = isClaimed || i < idx ? 'done' : (i === idx ? 'current' : '');
        const icon = isClaimed || i < idx ? '<i class="fa-solid fa-check"></i>' : (i === idx ? '<i class="fa-solid fa-mug-hot"></i>' : (i + 1));
        return `<div class="order-timeline-step ${state}"><div class="track"></div><div class="dot">${icon}</div><div class="label">${STEP_LABELS[key]}</div></div>`;
      }).join('') + '</div>';
    }

    function pollOrderStatuses() {
      const rows = Array.from(document.querySelectorAll('.order-row[data-status]')).filter(r => ['pending', 'preparing', 'ready'].includes(r.dataset.status));
      if (rows.length === 0) return;
      const ids = rows.map(r => r.dataset.orderId).join(',');

      fetch(`${APP_URL}/api/order-status.php?ids=${ids}`, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(r => r.json())
        .then(data => {
          (data.orders || []).forEach(o => {
            const row = document.querySelector(`.order-row[data-order-id="${o.id}"]`);
            if (!row || row.dataset.status === o.status) return;

            const wasStatus = row.dataset.status;
            row.dataset.status = o.status;

            const badge = row.querySelector('[data-status-badge]');
            if (badge) {
              badge.className = 'badge badge-' + o.status;
              badge.textContent = o.status.charAt(0).toUpperCase() + o.status.replace('_', ' ').slice(1);
            }

            const timelineHost = row.querySelector('[data-timeline]');
            if (timelineHost) timelineHost.innerHTML = renderTimelineHTML(o.status);

            if (o.status === 'ready' && wasStatus !== 'ready') {
              showToast('success', `Order ${row.dataset.orderNumber} is ready for pickup!`, 8000);
              const actions = row.querySelector('[data-order-actions]');
              if (actions && !actions.querySelector('[data-qr-btn]')) {
                const btn = document.createElement('button');
                btn.className = 'btn btn-sm btn-primary';
                btn.setAttribute('data-qr-btn', '');
                btn.innerHTML = '<i class="fa-solid fa-qrcode"></i> Show QR to Claim';
                btn.onclick = () => openQrModal(o.id, row.dataset.orderNumber);
                actions.prepend(btn);
              }
            } else if (o.status === 'cancelled' || o.status === 'no_show') {
              showToast('warning', `Order ${row.dataset.orderNumber} was cancelled.`, 8000);
            }
          });
        })
        .catch(() => {}); // silent — this is a background refresh, not a user-initiated action
    }
    setInterval(pollOrderStatuses, 20000);
  </script>
  <script src="<?= APP_URL ?>/../assets/js/cart-drawer.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/cart-drawer.js') ?>"></script>
</body>

</html>