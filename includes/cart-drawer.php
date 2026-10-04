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
    <div class="field-label">Pickup Date</div>
    <input type="date" class="field-input" id="pickupDate" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
    <div class="field-label">Pickup Time</div>
    <select class="field-select" id="pickupTime">
      <option value="">Select date first...</option>
    </select>

    <div class="field-label">GCash Reference Number</div>
    <input class="field-input" id="refNo" placeholder="e.g. 1234567890123" style="margin-top:2px">
    <div class="cart-hint" style="font-size:12px;color:var(--text-muted);margin-top:6px">
      Pre-orders are paid via GCash only. Send payment first, then enter the reference number here.
    </div>
    <input class="field-input" id="cartNotes" placeholder="Notes (optional)" style="margin-top:10px">

    <div class="cart-total-row" style="margin-top:16px">
      <span>Total</span><span id="cartTotal">₱0.00</span>
    </div>
    <button class="btn btn-primary" style="width:100%" onclick="placeOrder()">Place Order</button>
  </div>
</aside>

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