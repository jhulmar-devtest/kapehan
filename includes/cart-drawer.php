<?php
// ============================================================
// includes/cart-drawer.php
//
// Shared cart sidebar + toast stack + order-confirmation modal,
// for any logged-in customer page (orders.php, account.php).
// Pairs with assets/css/cart-drawer.css and assets/js/cart-drawer.js.
//
// Requires: APP_URL constant available. Does NOT render the
// guest/signed-out state — only include this on pages that
// already gate access with requireRole(ROLE_STUDENT, ROLE_FACULTY).
// ============================================================
?>
<div class="toast-stack" id="toastStack" aria-live="polite"></div>

<div class="overlay-scrim" id="scrim" onclick="closeCart()"></div>

<aside class="cart-sidebar" id="cartSidebar">
  <div class="cart-sidebar-header">
    <strong>Your Order</strong>
    <button class="modal-close" style="position:static" onclick="closeCart()">&times;</button>
  </div>
  <div class="cart-sidebar-body" id="cartBody"><!-- rendered by JS --></div>
  <div class="cart-sidebar-footer" id="cartFooter">
    <div class="field-label">Pickup Time</div>
    <select class="field-select" id="pickupTime">
      <option value="">Loading available times...</option>
    </select>
    <p id="pickupSlotMessage" class="cart-hint" role="status" style="font-size:12px;color:var(--text-muted);margin:6px 0 0"></p>
    <input class="field-input" id="cartNotes" placeholder="Notes (optional)" style="margin-top:10px">

    <div class="cart-total-row" style="margin-top:16px">
      <span>Total</span><span id="cartTotal">₱0.00</span>
    </div>
    <button class="btn btn-primary" id="proceedPaymentButton" style="width:100%" onclick="proceedToPayment()" disabled>Proceed to payment</button>
  </div>
</aside>

<div class="modal-overlay" id="paymentModal" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="paymentHeading">
    <button class="modal-close" onclick="closePaymentModal()" aria-label="Close">&times;</button>
    <div class="confirm-heading" id="paymentHeading">Pay with GCash</div>
    <p class="confirm-subheading">Send the exact amount below, then paste your GCash reference number.</p>
    <div class="confirm-details">
      <div class="confirm-row"><span>Account name</span><strong id="gcashPaymentName" style="text-align:right;overflow-wrap:anywhere"><?= e(gcashPaymentName() ?: 'Name not configured') ?></strong></div>
      <div class="confirm-row"><span>GCash number</span><strong id="gcashPaymentNumber" style="text-align:right;overflow-wrap:anywhere"><?= e(gcashPaymentNumber() ?: 'Number not configured') ?></strong></div>
      <div class="confirm-row total"><span>Amount</span><span id="paymentAmount"></span></div>
    </div>
    <?php if (gcashPaymentNumber() === '' || gcashPaymentName() === ''): ?>
      <p class="cart-hint" style="color:var(--danger-color,#b42318);font-size:13px">The shop's GCash recipient name and number need to be set by an admin (Admin &rarr; Settings) before customers can pay.</p>
    <?php endif; ?>
    <label class="field-label" for="refNo">GCash reference number</label>
    <input class="field-input" id="refNo" inputmode="numeric" autocomplete="off" placeholder="Paste reference number">
    <div class="confirm-actions" style="margin-top:18px">
      <button class="btn btn-ghost" onclick="closePaymentModal()">Back to cart</button>
      <button class="btn btn-primary" id="submitPaymentButton" onclick="submitOrder()" <?= gcashPaymentNumber() !== '' && gcashPaymentName() !== '' ? '' : 'disabled' ?>>Submit order</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="confirmModal" hidden>
  <div class="modal-box" role="alertdialog" aria-labelledby="confirmHeading">
    <button class="modal-close" onclick="closeConfirmModal()" aria-label="Close">&times;</button>
    <div class="confirm-icon"><i class="fa-solid fa-check"></i></div>
    <div class="confirm-heading" id="confirmHeading">Order placed!</div>
    <div class="confirm-subheading">Show your school ID when claiming.</div>
    <div class="confirm-details">
      <div class="confirm-row"><span>Order number</span><span id="confOrderNo"></span></div>
      <div class="confirm-row"><span>Pickup</span><span id="confPickup"></span></div>
      <div class="confirm-row"><span>Payment</span><span id="confPayment"></span></div>
      <div class="confirm-row total"><span>Total</span><span id="confTotal"></span></div>
    </div>
    <div class="confirm-actions">
      <button class="btn btn-primary" onclick="window.location.href=APP_URL+'/orders.php'">Track My Order</button>
      <button class="btn btn-ghost" onclick="closeConfirmModal()">Continue Browsing</button>
    </div>
  </div>
</div>
