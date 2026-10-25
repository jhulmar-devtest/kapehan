<?php

/**
 * receipt_template.php — Shared receipt component
 *
 * Expected variables in scope when included:
 *   $order  — associative array with keys:
 *               order_number, order_type, customer_name, cashier_name,
 *               created_at, total_amount, payment_method,
 *               amount_paid, change_given, reference_number
 *   $items  — array of rows with keys:
 *               name, quantity, subtotal, customization_note
 *
 * Output: pure HTML for the receipt paper (no wrapper divs, no buttons).
 * The caller is responsible for the surrounding page/modal chrome.
 */

$order = $order ?? [];
$items = $items ?? [];
?>
<div class="rcpt-paper">

  <!-- Shop header -->
  <div class="rcpt-header">
    <img class="rcpt-logo" src="<?= e(APP_URL . '/../assets/images/logo.png') ?>" alt="<?= e(APP_NAME) ?> logo">
    <div class="rcpt-shop-name"><?= e(APP_NAME) ?></div>
    <div class="rcpt-tagline"><?= e(APP_TAGLINE) ?></div>
  </div>

  <hr class="rcpt-rule">

  <!-- Order meta -->
  <div class="rcpt-meta">
    <div><span class="rcpt-label">Order No.:</span> <?= e($order['order_number']) ?></div>
    <div><span class="rcpt-label">Type:</span> <?= ucfirst(e($order['order_type'])) ?></div>
    <div><span class="rcpt-label">Customer:</span> <?= e($order['customer_name'] ?? 'Walk-in') ?></div>
    <div><span class="rcpt-label">Cashier:</span> <?= e($order['cashier_name'] ?? '—') ?></div>
    <div><span class="rcpt-label">Date:</span> <?= date('M j, Y  g:i A', strtotime($order['created_at'])) ?></div>
  </div>

  <hr class="rcpt-rule">

  <!-- Line items -->
  <div class="rcpt-items">
    <?php foreach ($items as $item): ?>
      <div class="rcpt-item">
        <span class="rcpt-item-name"><?= e($item['name']) ?></span>
        <span class="rcpt-item-qty">×<?= (int)$item['quantity'] ?></span>
        <span class="rcpt-item-sub"><?= peso($item['subtotal']) ?></span>
      </div>
      <?php if (!empty($item['customization_note'])): ?>
        <div class="rcpt-item-note"><?= e($item['customization_note']) ?></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <hr class="rcpt-rule">

  <!-- Totals -->
  <div class="rcpt-totals">
    <div class="rcpt-total-grand">
      <span>TOTAL</span><span><?= peso($order['total_amount']) ?></span>
    </div>

    <?php foreach (($loyaltyEntries ?? []) as $loyaltyEntry): ?>
      <?php if ($loyaltyEntry['entry_type'] === 'redeem'): ?>
        <div class="rcpt-total-row"><span class="rcpt-total-lbl">Reward drink</span><span class="rcpt-total-val"><?= e($loyaltyEntry['note'] ?? 'Drink reward') ?></span></div>
        <div class="rcpt-total-row"><span class="rcpt-total-lbl">Points redeemed</span><span class="rcpt-total-val"><?= number_format(abs((int)$loyaltyEntry['points_delta'])) ?> pts (<?= peso($loyaltyEntry['amount_value']) ?> off)</span></div>
      <?php elseif ($loyaltyEntry['entry_type'] === 'earn'): ?>
        <div class="rcpt-total-row"><span class="rcpt-total-lbl">Points earned</span><span class="rcpt-total-val">+<?= number_format((int)$loyaltyEntry['points_delta']) ?> pts</span></div>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if (strtolower($order['payment_method'] ?? '') === 'cash'): ?>
      <div class="rcpt-total-row">
        <span class="rcpt-total-lbl">Cash Received</span>
        <span class="rcpt-total-val"><?= peso($order['amount_paid'] ?? 0) ?></span>
      </div>
      <div class="rcpt-total-row">
        <span class="rcpt-total-lbl">Change</span>
        <span class="rcpt-total-val"><?= peso($order['change_given'] ?? 0) ?></span>
      </div>
    <?php else: ?>
      <div class="rcpt-total-row">
        <span class="rcpt-total-lbl">Ref. No.</span>
        <span class="rcpt-total-val"><?= e($order['reference_number'] ?? '—') ?></span>
      </div>
    <?php endif; ?>

    <div class="rcpt-total-row">
      <span class="rcpt-total-lbl">Payment</span>
      <span class="rcpt-total-val"><?= ucfirst(e($order['payment_method'] ?? '')) ?></span>
    </div>
  </div>

  <hr class="rcpt-rule">

  <!-- Footer -->
  <div class="rcpt-footer">
    <div>Thank you for your purchase!</div>
    <div>EARIST Cavite Campus — GMA, Cavite</div>
  </div>

</div>
