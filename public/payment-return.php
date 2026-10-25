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
// and checks again every few seconds.
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

// Progress tracker: Order placed → Payment → Confirmed
$stepStates = match ($state) {
  'paid'    => ['done', 'done', 'done'],
  'waiting' => ['done', 'current', 'todo'],
  'unpaid'  => ['done', 'warn', 'todo'],
  default   => ['done', 'fail', 'todo'],
};
$stepLabels = ['Order placed', 'Payment', 'Confirmed'];
$stepIcons = [
  'done'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
  'warn'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v6M12 17v.01"/></svg>',
  'fail'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17"/></svg>',
  'current' => '<span class="mini-spinner" aria-hidden="true"></span>',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payment &middot; <?= e(APP_NAME) ?></title>
  <?php if ($state === 'waiting'): ?><noscript>
      <meta http-equiv="refresh" content="4;url=<?= e($self) ?>">
    </noscript><?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/../assets/css/variables.css">
  <style>
    :root {
      color-scheme: light;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      padding: 28px 16px;
      display: grid;
      place-items: center;
      background: var(--background-color, #f3f0ec);
      color: var(--text-color, #30251f);
      font-family: 'DM Sans', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      -webkit-font-smoothing: antialiased;
      line-height: 1.5;
    }

    .shell {
      width: min(100%, 520px);
    }

    /* Brand */
    .brand {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 11px;
      margin-bottom: 22px;
    }

    .brand img {
      width: 44px;
      height: 44px;
      object-fit: contain;
      border-radius: 50%;
      background: var(--primary-color, #68452f);
      box-shadow: var(--shadow-primary, none);
    }

    .brand-copy {
      display: grid;
      gap: 1px;
      text-align: left;
    }

    .brand-name {
      font-size: .92rem;
      font-weight: 700;
      letter-spacing: -.01em;
    }

    .brand-sub {
      color: var(--text-muted, #75675d);
      font-size: .66rem;
      letter-spacing: .1em;
      text-transform: uppercase;
    }

    /* Card */
    .card {
      background: var(--surface-color, #fff);
      border: 1px solid var(--border-color, #eadfd4);
      border-radius: var(--radius-xl, 24px);
      padding: clamp(24px, 6vw, 40px);
      box-shadow: var(--shadow-xl, 0 22px 60px rgba(65, 43, 28, .12));
      text-align: center;
    }

    /* Progress tracker */
    .steps {
      display: flex;
      list-style: none;
      margin: 0 0 30px;
      padding: 0;
    }

    .step {
      flex: 1;
      position: relative;
      display: grid;
      justify-items: center;
      gap: 7px;
    }

    .step::before {
      content: "";
      position: absolute;
      top: 15px;
      right: 50%;
      width: 100%;
      height: 2px;
      background: var(--border-color, #eadfd4);
    }

    .step:first-child::before {
      display: none;
    }

    .step.is-done::before,
    .step.is-current::before,
    .step.is-warn::before,
    .step.is-fail::before {
      background: var(--ok, #267344);
    }

    .step-dot {
      position: relative;
      z-index: 1;
      width: 32px;
      height: 32px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      border: 2px solid var(--border-color, #eadfd4);
      background: var(--surface-color, #fff);
      color: var(--text-muted, #75675d);
      font-size: .78rem;
      font-weight: 700;
    }

    .step-dot svg {
      width: 16px;
      height: 16px;
      fill: none;
      stroke: currentColor;
      stroke-width: 3;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .step.is-done .step-dot {
      background: var(--ok, #267344);
      border-color: var(--ok, #267344);
      color: #fff;
    }

    .step.is-current .step-dot {
      border-color: var(--primary-color, #68452f);
      box-shadow: 0 0 0 4px var(--primary-subtle, #f3e8df);
    }

    .step.is-warn .step-dot {
      background: var(--warn-bg, #fff1e5);
      border-color: var(--warn, #a45a24);
      color: var(--warn, #a45a24);
    }

    .step.is-fail .step-dot {
      background: var(--bad, #a43f3f);
      border-color: var(--bad, #a43f3f);
      color: #fff;
    }

    .step-label {
      font-size: .72rem;
      font-weight: 600;
      color: var(--text-muted, #75675d);
    }

    .step.is-done .step-label,
    .step.is-current .step-label {
      color: var(--text-color, #30251f);
    }

    .mini-spinner {
      width: 14px;
      height: 14px;
      border: 2px solid var(--primary-subtle, #eadfd4);
      border-top-color: var(--primary-color, #68452f);
      border-radius: 50%;
      animation: spin .8s linear infinite;
    }

    /* Status hero */
    .status-icon {
      width: 76px;
      height: 76px;
      display: grid;
      place-items: center;
      margin: 0 auto 18px;
      border-radius: 50%;
    }

    .status-icon svg {
      width: 36px;
      height: 36px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2.6;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .status-icon.success {
      background: var(--ok-bg, #e7f4eb);
      color: var(--ok, #267344);
    }

    .status-icon.pending {
      background: var(--primary-subtle, #f8f0df);
      color: var(--primary-color, #93691c);
    }

    .status-icon.warning {
      background: var(--warn-bg, #fff1e5);
      color: var(--warn, #a45a24);
    }

    .status-icon.cancelled {
      background: var(--bad-bg, #fcebec);
      color: var(--bad, #a43f3f);
    }

    .status-icon.success svg path {
      stroke-dasharray: 30;
      stroke-dashoffset: 30;
      animation: draw .5s .15s ease-out forwards;
    }

    .big-spinner {
      width: 32px;
      height: 32px;
      border: 3px solid var(--border-color, #e5d7c4);
      border-top-color: var(--primary-color, #68452f);
      border-radius: 50%;
      animation: spin .8s linear infinite;
    }

    h1 {
      margin: 0;
      font-family: 'Instrument Serif', Georgia, serif;
      font-weight: 400;
      font-size: clamp(1.8rem, 6vw, 2.3rem);
      line-height: 1.1;
      letter-spacing: -.02em;
    }

    .lead {
      margin: 10px auto 0;
      max-width: 380px;
      color: var(--text-secondary, #75675d);
      font-size: .95rem;
      line-height: 1.6;
    }

    /* Order summary */
    .summary {
      margin: 24px 0 0;
      border: 1px solid var(--border-color, #eadfd4);
      border-radius: 14px;
      overflow: hidden;
      text-align: left;
      background: var(--surface-raised, #fcfaf7);
    }

    .summary>div {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 14px;
      padding: 12px 16px;
      border-bottom: 1px solid var(--border-color, #eadfd4);
    }

    .summary>div:last-child {
      border-bottom: 0;
    }

    .summary dt {
      color: var(--text-muted, #75675d);
      font-size: .78rem;
      font-weight: 600;
    }

    .summary dd {
      margin: 0;
      font-size: .92rem;
      font-weight: 700;
      text-align: right;
      overflow-wrap: anywhere;
    }

    .summary .order {
      letter-spacing: .03em;
    }

    .summary .total {
      background: var(--surface-color, #fff);
    }

    .summary .total dd {
      color: var(--primary-color, #68452f);
      font-size: 1.08rem;
    }

    /* Extra info blocks */
    .next-steps {
      margin: 22px 0 0;
      padding: 0;
      list-style: none;
      display: grid;
      gap: 10px;
      text-align: left;
    }

    .next-steps li {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      font-size: .88rem;
      color: var(--text-secondary, #75675d);
      line-height: 1.5;
    }

    .next-steps .num {
      flex: none;
      width: 24px;
      height: 24px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: var(--primary-subtle, #f3e8df);
      color: var(--primary-color, #68452f);
      font-size: .74rem;
      font-weight: 800;
    }

    .next-steps strong {
      color: var(--text-color, #30251f);
    }

    .callout {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      margin: 18px 0 0;
      padding: 12px 14px;
      border-radius: 12px;
      text-align: left;
      font-size: .85rem;
      line-height: 1.55;
    }

    .callout svg {
      flex: none;
      width: 18px;
      height: 18px;
      margin-top: 1px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2.2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .callout.info {
      background: var(--surface-raised, #f6f0e8);
      color: var(--text-secondary, #75675d);
    }

    .callout.warn {
      background: var(--warn-bg, #fff1e5);
      color: var(--warn, #a45a24);
    }

    .callout.bad {
      background: var(--bad-bg, #fcebec);
      color: var(--bad, #a43f3f);
    }

    .callout[hidden] {
      display: none;
    }

    /* Actions */
    .actions {
      display: grid;
      gap: 10px;
      margin-top: 26px;
    }

    .actions form {
      display: contents;
    }

    a.btn,
    button.btn {
      min-height: 48px;
      border: 1px solid transparent;
      border-radius: 12px;
      padding: 11px 18px;
      font: inherit;
      font-size: .92rem;
      font-weight: 700;
      width: 100%;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: transform .16s ease, background .16s ease, box-shadow .16s ease, color .16s ease;
    }

    a.btn:hover,
    button.btn:hover {
      transform: translateY(-1px);
    }

    .primary {
      background: var(--primary-color, #68452f);
      color: var(--text-on-primary, #fff);
      box-shadow: var(--shadow-primary, 0 5px 13px rgba(104, 69, 47, .18));
    }

    .primary:hover {
      background: var(--primary-dark, #4d3223);
    }

    .ghost {
      background: var(--surface-color, #fff);
      border-color: var(--border-color, #eadfd4);
      color: var(--text-color, #30251f);
    }

    .ghost:hover {
      background: var(--surface-raised, #fbf7f2);
    }

    .link-danger {
      background: none;
      color: var(--bad, #a43f3f);
      min-height: 40px;
      font-size: .85rem;
    }

    .link-danger:hover {
      background: var(--bad-bg, #fcebec);
    }

    button:focus-visible,
    a:focus-visible {
      outline: 3px solid var(--primary-color, #d6b58f);
      outline-offset: 3px;
    }

    .footnote {
      margin: 18px 0 0;
      text-align: center;
      color: var(--text-muted, #75675d);
      font-size: .76rem;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    @keyframes draw {
      to {
        stroke-dashoffset: 0;
      }
    }

    @media (min-width:520px) {
      .actions.row {
        grid-template-columns: 1fr 1fr;
      }
    }

    @media (max-width:480px) {
      body {
        padding: 18px 12px;
        place-items: start center;
      }

      .brand {
        margin-bottom: 16px;
      }

      .card {
        border-radius: 20px;
      }

      .step-label {
        font-size: .68rem;
      }
    }

    @media (prefers-reduced-motion:reduce) {

      *,
      *::before,
      *::after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        scroll-behavior: auto !important;
        transition-duration: .01ms !important;
      }

      .status-icon.success svg path {
        stroke-dashoffset: 0;
      }
    }
  </style>
</head>

<body>
  <main class="shell">
    <div class="brand">
      <img src="<?= e(APP_URL) ?>/../assets/images/logo.png" alt="" onerror="this.style.display='none'">
      <div class="brand-copy"><span class="brand-name"><?= e(APP_NAME) ?></span><span class="brand-sub">Online order payment</span></div>
    </div>

    <section class="card" aria-live="polite" data-state="<?= e($state) ?>">
      <ol class="steps" aria-label="Order progress">
        <?php foreach ($stepLabels as $i => $label):
          $st = $stepStates[$i]; ?>
          <li class="step is-<?= $st ?>" <?= $st === 'current' ? ' aria-current="step"' : '' ?>>
            <span class="step-dot"><?= $stepIcons[$st] ?? ($i + 1) ?></span>
            <span class="step-label"><?= e($label) ?></span>
          </li>
        <?php endforeach; ?>
      </ol>

      <?php if ($state === 'paid'): ?>
        <div class="status-icon success" aria-hidden="true"><svg viewBox="0 0 24 24">
            <path d="M5 12.5l4.5 4.5L19 7.5" />
          </svg></div>
        <h1>Payment received</h1>
        <p class="lead">Your GCash payment was confirmed. The cashier can now prepare your order.</p>
        <dl class="summary">
          <div>
            <dt>Order number</dt>
            <dd class="order"><?= e($order['order_number']) ?></dd>
          </div>
          <div>
            <dt>Payment</dt>
            <dd>GCash &middot; Paid</dd>
          </div>
          <div class="total">
            <dt>Amount</dt>
            <dd><?= peso($order['total_amount']) ?></dd>
          </div>
        </dl>
        <ol class="next-steps">
          <li><span class="num">1</span><span><strong>We prepare your order.</strong> You can follow its status under My Orders.</span></li>
          <li><span class="num">2</span><span><strong>Pick it up at your chosen time.</strong> Show your school ID when you claim it.</span></li>
        </ol>
        <div class="actions row">
          <a class="btn primary" href="<?= e(APP_URL) ?>/orders.php">Track my order</a>
          <a class="btn ghost" href="<?= e(APP_URL) ?>/menu.php">Back to menu</a>
        </div>
        <script>
          try {
            sessionStorage.removeItem('shop_cart');
          } catch (e) {}
        </script>

      <?php elseif ($state === 'waiting'): ?>
        <div class="status-icon pending" aria-hidden="true"><span class="big-spinner"></span></div>
        <h1>Confirming your payment</h1>
        <p class="lead">We&rsquo;re waiting for GCash to confirm this transaction. This usually takes just a few seconds.</p>
        <dl class="summary">
          <div>
            <dt>Order number</dt>
            <dd class="order"><?= e($order['order_number']) ?></dd>
          </div>
          <div>
            <dt>Payment</dt>
            <dd>GCash &middot; Pending</dd>
          </div>
          <div class="total">
            <dt>Amount</dt>
            <dd><?= peso($order['total_amount']) ?></dd>
          </div>
        </dl>
        <div class="callout info" role="status">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5M12 8v.01" />
          </svg>
          <span>Please keep this page open. It updates by itself as soon as your payment is confirmed &mdash; no need to refresh.</span>
        </div>
        <div class="callout warn" id="slowNote" hidden>
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
          </svg>
          <span>This is taking longer than usual. You can safely leave this page &mdash; your order will update in My Orders once payment is confirmed.</span>
        </div>
        <div class="actions">
          <a class="btn ghost" href="<?= e(APP_URL) ?>/orders.php">Check my orders</a>
        </div>

      <?php elseif ($state === 'unpaid'): ?>
        <div class="status-icon warning" aria-hidden="true"><svg viewBox="0 0 24 24">
            <path d="M12 7v6M12 17v.01" />
          </svg></div>
        <h1>Payment not completed</h1>
        <p class="lead">This order is still unpaid. Your cart is saved if you want to order again.</p>
        <dl class="summary">
          <div>
            <dt>Order number</dt>
            <dd class="order"><?= e($order['order_number']) ?></dd>
          </div>
          <div>
            <dt>Payment</dt>
            <dd>GCash &middot; Unpaid</dd>
          </div>
          <div class="total">
            <dt>Amount</dt>
            <dd><?= peso($order['total_amount']) ?></dd>
          </div>
        </dl>
        <div class="callout warn">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
          </svg>
          <span>Unpaid orders are released automatically after <?= (int) PAYMONGO_UNPAID_ORDER_MINUTES ?> minutes.</span>
        </div>
        <form method="POST" class="actions" onsubmit="return confirm('Cancel this unpaid order?');">
          <?= csrfField() ?>
          <input type="hidden" name="order" value="<?= e($order['order_number']) ?>">
          <a class="btn primary" href="<?= e(APP_URL) ?>/menu.php">Back to menu</a>
          <button class="btn link-danger" type="submit" name="cancel_unpaid" value="1">Cancel this order</button>
        </form>

      <?php else: ?>
        <div class="status-icon cancelled" aria-hidden="true"><svg viewBox="0 0 24 24">
            <path d="M7 7l10 10M17 7L7 17" />
          </svg></div>
        <h1>Order cancelled</h1>
        <p class="lead">This order was cancelled because payment was not received in time.</p>
        <dl class="summary">
          <div>
            <dt>Order number</dt>
            <dd class="order"><?= e($order['order_number']) ?></dd>
          </div>
          <div>
            <dt>Payment</dt>
            <dd>GCash &middot; Not received</dd>
          </div>
          <div class="total">
            <dt>Amount</dt>
            <dd><?= peso($order['total_amount']) ?></dd>
          </div>
        </dl>
        <div class="callout bad">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5M12 8v.01" />
          </svg>
          <span>If GCash deducted the payment, contact the shop and provide this order number.</span>
        </div>
        <div class="actions">
          <a class="btn primary" href="<?= e(APP_URL) ?>/menu.php">Back to menu</a>
        </div>
      <?php endif; ?>
    </section>

    <p class="footnote">Need help? Show this order number to the cashier.</p>
  </main>

  <?php if ($state === 'waiting'): ?>
    <script>
      // Quietly re-check the order every few seconds; reload once its state changes.
      (function() {
        var self = <?= json_encode($self) ?>;
        var key = 'pay_wait_' + <?= json_encode($order['order_number']) ?>;
        var start = Date.now();
        try {
          start = Number(sessionStorage.getItem(key)) || start;
          sessionStorage.setItem(key, String(start));
        } catch (e) {}
        var slow = document.getElementById('slowNote');
        var timer;

        function tick() {
          var elapsed = Date.now() - start;
          if (slow && elapsed > 45000) slow.hidden = false;
          if (elapsed > 600000) clearInterval(timer); // stop polling after 10 minutes
        }

        function check() {
          tick();
          fetch(self, {
              credentials: 'same-origin',
              cache: 'no-store'
            })
            .then(function(r) {
              return r.text();
            })
            .then(function(html) {
              if (html.indexOf('data-state="waiting"') === -1) {
                clearInterval(timer);
                try {
                  sessionStorage.removeItem(key);
                } catch (e) {}
                location.reload();
              }
            })
            .catch(function() {
              /* try again on the next tick */
            });
        }

        tick();
        timer = setInterval(check, 3000);
      })();
    </script>
  <?php endif; ?>
</body>

</html>