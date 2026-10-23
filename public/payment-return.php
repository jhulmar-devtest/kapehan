<?php
// ============================================================
// public/payment-return.php
//
// Where the customer lands after the PayMongo GCash page.
//   ?order=ORD-...               came back after paying (success_url)
//   ?order=ORD-...&cancelled=1   backed out of the payment page (cancel_url)
//
// The page never trusts the redirect itself. "Paid" is shown only once the
// webhook has marked the payment paid in the database; until then it waits
// and refreshes every few seconds.
// ============================================================

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../includes/paymongo.php';
requireRole(ROLE_STUDENT, ROLE_FACULTY);

$db       = Database::getInstance();
$role     = currentRole();
$userId   = (int) currentUserId();
$fkColumn = $role === ROLE_STUDENT ? 'student_id' : 'faculty_id';

$orderNo = sanitizeString($_GET['order'] ?? $_POST['order'] ?? '', 20);
$backedOut = isset($_GET['cancelled']);

$stmt = $db->prepare(
  "SELECT o.id, o.order_number, o.status, o.total_amount, p.payment_status, p.gateway
     FROM orders o LEFT JOIN payments p ON p.order_id = o.id
    WHERE o.order_number = ? AND o.{$fkColumn} = ? LIMIT 1"
);
$stmt->execute([$orderNo, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order || $order['gateway'] !== 'paymongo') {
  redirect(APP_URL . '/menu.php');
}

// Customer chose to give up on an unpaid order.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_unpaid'])) {
  verifyCsrf();
  try {
    paymongoCancelUnpaidOrder($db, (int) $order['id'], $role, $userId);
    flash('global', 'Your unpaid order was cancelled.', 'warning');
  } catch (Throwable $e) {
    error_log('Cancel unpaid order failed: ' . $e->getMessage());
    flash('global', 'Could not cancel the order. Please try again.', 'error');
  }
  redirect(APP_URL . '/menu.php');
}

if ($order['payment_status'] === PAY_STATUS_PAID) {
  $state = 'paid';
} elseif ($order['status'] === STATUS_CANCELLED) {
  $state = 'cancelled';
} elseif ($backedOut) {
  $state = 'unpaid';
} else {
  $state = 'waiting';
}
$self = APP_URL . '/payment-return.php?order=' . urlencode($order['order_number']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payment &middot; <?= e(APP_NAME) ?></title>
  <?php if ($state === 'waiting'): ?><meta http-equiv="refresh" content="3;url=<?= e($self) ?>"><?php endif; ?>
  <style>
    :root { color-scheme:light; --ink:#30251f; --muted:#75675d; --coffee:#68452f; --coffee-dark:#4d3223; --cream:#f6f0e8; --line:#eadfd4; }
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; padding:28px 16px; display:grid; place-items:center;
      background:radial-gradient(ellipse at 50% -12%,#fff 0,rgba(255,255,255,.8) 26%,transparent 63%),linear-gradient(145deg,#f2e8dc 0%,#fbf8f3 52%,#f2e9df 100%);
      font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; color:var(--ink); }
    .shell { width:min(100%,540px); }
    .brand { display:flex; align-items:center; justify-content:center; gap:10px; margin-bottom:20px; color:var(--coffee-dark); }
    .brand img { width:42px; height:42px; object-fit:contain; }
    .brand-copy { display:grid; gap:2px; text-align:left; }
    .brand-name { font-family:Georgia,"Times New Roman",serif; font-size:1.08rem; font-weight:700; letter-spacing:.01em; }
    .brand-sub { color:var(--muted); font-size:.68rem; letter-spacing:.12em; text-transform:uppercase; }
    .card { position:relative; overflow:hidden; background:rgba(255,255,255,.96); border:1px solid rgba(104,69,47,.11); border-radius:24px;
      padding:clamp(25px,6vw,42px); box-shadow:0 22px 60px rgba(65,43,28,.12); text-align:center; }
    .card::before { position:absolute; inset:0 0 auto; height:5px; background:linear-gradient(90deg,#9a714e,#d1ad82,#9a714e); content:""; }
    .status-icon { width:72px; height:72px; display:grid; place-items:center; margin:0 auto 19px; border-radius:50%;
      font-size:2rem; font-weight:700; }
    .status-icon.success { background:#e7f4eb; color:#267344; }
    .status-icon.pending { background:#f8f0df; color:#93691c; }
    .status-icon.warning { background:#fff1e5; color:#a45a24; }
    .status-icon.cancelled { background:#fcebec; color:#a43f3f; }
    h1 { margin:0; font-family:Georgia,"Times New Roman",serif; font-size:clamp(1.55rem,5vw,2rem); line-height:1.18; letter-spacing:-.02em; }
    .lead { margin:10px 0 0; color:var(--muted); font-size:.96rem; line-height:1.6; }
    .order-summary { display:flex; align-items:center; justify-content:space-between; gap:16px; margin:24px 0 0; padding:14px 16px;
      border:1px solid var(--line); border-radius:14px; background:#fcfaf7; text-align:left; }
    .order-label { display:block; color:var(--muted); font-size:.7rem; font-weight:700; letter-spacing:.09em; text-transform:uppercase; }
    .order { display:block; margin-top:4px; font-size:.93rem; font-weight:750; letter-spacing:.025em; overflow-wrap:anywhere; }
    .amount { flex:none; font-size:1.02rem; font-weight:750; }
    .notice { margin:17px auto 0; max-width:390px; color:var(--muted); font-size:.88rem; line-height:1.6; }
    .auto-note { display:inline-flex; align-items:center; justify-content:center; gap:8px; margin-top:14px; color:#82643e; font-size:.78rem; }
    .spinner { width:17px; height:17px; border:2px solid #e5d7c4; border-top-color:var(--coffee); border-radius:50%; animation:spin .8s linear infinite; }
    .actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:25px; }
    a.btn, button.btn { min-height:46px; border:1px solid transparent; border-radius:12px; padding:11px 18px; font:inherit; font-size:.9rem; font-weight:700;
      cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; transition:transform .16s ease,background .16s ease,box-shadow .16s ease; }
    a.btn:hover, button.btn:hover { transform:translateY(-1px); }
    .primary { background:var(--coffee); color:#fff; box-shadow:0 5px 13px rgba(104,69,47,.18); }
    .primary:hover { background:var(--coffee-dark); }
    .ghost { background:#fff; border-color:var(--line)!important; color:var(--ink); }
    .ghost:hover { background:#fbf7f2; }
    .actions form { display:contents; }
    button:focus-visible, a:focus-visible { outline:3px solid #d6b58f; outline-offset:3px; }
    @keyframes spin { to { transform:rotate(360deg); } }
    @media(max-width:480px) {
      body { padding:20px 13px; }
      .brand { margin-bottom:15px; }
      .brand img { width:38px; height:38px; }
      .card { border-radius:20px; }
      .order-summary { align-items:flex-start; flex-direction:column; gap:9px; }
      .actions { display:grid; grid-template-columns:1fr; }
      .actions form { display:grid; }
      a.btn, button.btn { width:100%; }
    }
    @media(prefers-reduced-motion:reduce) { *,*::before,*::after { animation-duration:.01ms!important; animation-iteration-count:1!important; scroll-behavior:auto!important; transition-duration:.01ms!important; } }
  </style>
</head>
<body>
  <main class="shell">
    <div class="brand">
      <img src="<?= e(APP_URL) ?>/../assets/images/logo.png" alt="" onerror="this.style.display='none'">
      <div class="brand-copy"><span class="brand-name"><?= e(APP_NAME) ?></span><span class="brand-sub">Online order payment</span></div>
    </div>
    <section class="card" aria-live="polite">
    <?php if ($state === 'paid'): ?>
      <div class="status-icon success" aria-hidden="true">&#10003;</div>
      <h1>Payment received!</h1>
      <p class="lead">Your GCash payment was confirmed. The cashier can now prepare your order.</p>
      <div class="order-summary"><div><span class="order-label">Order number</span><span class="order"><?= e($order['order_number']) ?></span></div><span class="amount"><?= peso($order['total_amount']) ?></span></div>
      <p class="notice">Show your school ID when you come to claim your order.</p>
      <div class="actions">
        <a class="btn primary" href="<?= e(APP_URL) ?>/orders.php">Track my order</a>
        <a class="btn ghost" href="<?= e(APP_URL) ?>/menu.php">Back to menu</a>
      </div>
      <script>try { sessionStorage.removeItem('shop_cart'); } catch (e) {}</script>

    <?php elseif ($state === 'waiting'): ?>
      <div class="status-icon pending" aria-hidden="true"><span class="spinner"></span></div>
      <h1>Confirming your payment</h1>
      <p class="lead">We’re waiting for GCash to confirm this transaction.</p>
      <div class="order-summary"><div><span class="order-label">Order number</span><span class="order"><?= e($order['order_number']) ?></span></div><span class="amount"><?= peso($order['total_amount']) ?></span></div>
      <p class="auto-note"><span class="spinner" aria-hidden="true"></span> This page checks for updates automatically.</p>
      <div class="actions">
        <a class="btn primary" href="<?= e(APP_URL) ?>/orders.php">Check my orders</a>
      </div>

    <?php elseif ($state === 'unpaid'): ?>
      <div class="status-icon warning" aria-hidden="true">!</div>
      <h1>Payment not completed</h1>
      <p class="lead">This order is still unpaid. Your cart is saved if you want to order again.</p>
      <div class="order-summary"><div><span class="order-label">Order number</span><span class="order"><?= e($order['order_number']) ?></span></div><span class="amount"><?= peso($order['total_amount']) ?></span></div>
      <p class="notice">Unpaid orders are released automatically after <?= (int) PAYMONGO_UNPAID_ORDER_MINUTES ?> minutes.</p>
      <form method="POST" class="actions">
        <?= csrfField() ?>
        <input type="hidden" name="order" value="<?= e($order['order_number']) ?>">
        <button class="btn primary" type="submit" name="cancel_unpaid" value="1">Cancel this order</button>
        <a class="btn ghost" href="<?= e(APP_URL) ?>/menu.php">Back to menu</a>
      </form>

    <?php else: ?>
      <div class="status-icon cancelled" aria-hidden="true">&#10005;</div>
      <h1>Order cancelled</h1>
      <p class="lead">This order was cancelled because payment was not received in time.</p>
      <div class="order-summary"><div><span class="order-label">Order number</span><span class="order"><?= e($order['order_number']) ?></span></div><span class="amount"><?= peso($order['total_amount']) ?></span></div>
      <p class="notice">If GCash deducted the payment, contact the shop and provide this order number.</p>
      <div class="actions">
        <a class="btn primary" href="<?= e(APP_URL) ?>/menu.php">Back to menu</a>
      </div>
    <?php endif; ?>
    </section>
  </main>
</body>
</html>
