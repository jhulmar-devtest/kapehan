/* ============================================================
   cart-drawer.js
   Shared cart, checkout, toast, and order-confirmation logic.
   Requires globals APP_URL and CSRF_TOKEN to be defined before
   this file is loaded, and the markup from
   includes/cart-drawer.php to be present on the page.
   ============================================================ */

/* ── Toasts (replaces browser alert() for all inline feedback) ── */
function showToast(type, message, timeout = 4500) {
  const icons = {
    success: "fa-circle-check",
    error: "fa-circle-xmark",
    warning: "fa-triangle-exclamation",
    info: "fa-circle-info",
  };
  const stack = document.getElementById("toastStack");
  if (!stack) return;
  const el = document.createElement("div");
  el.className = `toast toast-${type}`;
  el.setAttribute("role", "alert");
  el.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i><span></span><button class="toast-close" aria-label="Dismiss"><i class="fa-solid fa-xmark"></i></button>`;
  el.querySelector("span").textContent = message; // textContent — never inject HTML into a toast
  el.querySelector(".toast-close").onclick = () => el.remove();
  stack.appendChild(el);
  if (timeout) setTimeout(() => el.remove(), timeout);
}

/* ── Cart storage (sessionStorage, shared across every page) ── */
function getCart() {
  return JSON.parse(sessionStorage.getItem("shop_cart") || "[]");
}

function saveCart(c) {
  sessionStorage.setItem("shop_cart", JSON.stringify(c));
  renderCartBadge();
}

function renderCartBadge() {
  const cart = getCart();
  const qty = cart.reduce((s, i) => s + i.qty, 0);
  const badge = document.getElementById("cartBadge");
  if (!badge) return;
  badge.style.display = qty > 0 ? "flex" : "none";
  badge.textContent = qty;

  const byProduct = {};
  cart.forEach(
    (i) => (byProduct[i.productId] = (byProduct[i.productId] || 0) + i.qty),
  );
  document.querySelectorAll(".menu-card").forEach((card) => {
    const id = card.id.replace("card-", "");
    const q = byProduct[id] || 0;
    const b = document.getElementById("badge-" + id);
    if (b) b.textContent = q;
    card.classList.toggle("has-items", q > 0);
  });
}

function openCart() {
  // Pages that allow anonymous browsing (menu.php) can define
  // window.beforeOpenCart to intercept — e.g. show a sign-in prompt
  // instead of the cart for guests — without duplicating this function.
  if (
    typeof window.beforeOpenCart === "function" &&
    window.beforeOpenCart() === false
  )
    return;
  renderCartSidebar();
  document.getElementById("cartSidebar").classList.add("open");
  document.getElementById("scrim").classList.add("open");
  document.getElementById("toastStack")?.classList.add("cart-open");
}

function closeCart() {
  document.getElementById("cartSidebar").classList.remove("open");
  document.getElementById("scrim").classList.remove("open");
  document.getElementById("toastStack")?.classList.remove("cart-open");
}

function renderCartSidebar() {
  const cart = getCart();
  const body = document.getElementById("cartBody");
  if (!body) return;
  if (cart.length === 0) {
    body.innerHTML =
      '<div class="cart-empty-state"><i class="fa-solid fa-mug-hot" style="font-size:28px;opacity:.4"></i><p style="margin-top:12px">Your cart is empty.</p></div>';
  } else {
    body.innerHTML = cart
      .map(
        (item) => `
      <div class="cart-line">
        <div style="flex:1">
          <div class="cart-line-name">${item.name}</div>
          <div class="cart-line-meta">${[item.size, item.sugar, ...(item.addons || [])].filter(Boolean).join(" \u00b7 ")}</div>
          <div class="cart-line-qty">
            <button class="qty-btn" onclick="cartChangeQty('${item.lineId}',-1)">\u2212</button>
            <span>${item.qty}</span>
            <button class="qty-btn" onclick="cartChangeQty('${item.lineId}',1)">+</button>
          </div>
          <div class="cart-line-remove" onclick="cartRemove('${item.lineId}')">Remove</div>
        </div>
        <div class="cart-line-price">\u20b1${(item.unit_price * item.qty).toFixed(2)}</div>
      </div>
    `,
      )
      .join("");
  }
  const total = cart.reduce((s, i) => s + i.unit_price * i.qty, 0);
  const totalEl = document.getElementById("cartTotal");
  if (totalEl) totalEl.textContent = "\u20b1" + total.toFixed(2);
  loadPickupSlots();
}

function cartChangeQty(lineId, d) {
  const cart = getCart();
  const item = cart.find((i) => i.lineId === lineId);
  if (item) item.qty = Math.max(1, item.qty + d);
  saveCart(cart);
  renderCartSidebar();
}

function cartRemove(lineId) {
  saveCart(getCart().filter((i) => i.lineId !== lineId));
  renderCartSidebar();
}

let pickupTimeManuallySelected = false;

function todayInManila() {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function updateProceedButton() {
  const timeEl = document.getElementById("pickupTime");
  const button = document.getElementById("proceedPaymentButton");
  if (!timeEl || !button) return;
  button.disabled = !timeEl.value || timeEl.options[timeEl.selectedIndex]?.disabled;
}

function loadPickupSlots() {
  const timeEl = document.getElementById("pickupTime");
  const messageEl = document.getElementById("pickupSlotMessage");
  if (!timeEl) return;
  const previousValue = timeEl.value;
  const previousWasAsap = !pickupTimeManuallySelected ||
    timeEl.options[timeEl.selectedIndex]?.dataset.asap === "true";
  const button = document.getElementById("proceedPaymentButton");
  if (button) button.disabled = true;
  fetch(`${APP_URL}/api/pickup-slots.php?date=${todayInManila()}`)
    .then((r) => r.json())
    .then((slots) => {
      const available = slots.filter((slot) => !slot.full);
      timeEl.innerHTML = slots.map((slot) =>
        `<option value="${slot.value}" data-asap="${slot.asap ? "true" : "false"}" ${slot.full ? "disabled" : ""}>${slot.label}${slot.full ? " (full)" : ""}</option>`,
      ).join("") || '<option value="">No times available</option>';

      if (!available.length) {
        timeEl.value = "";
        if (messageEl) messageEl.textContent = slots.length
          ? "All pickup times are full for today."
          : "Ordering is closed for today.";
      } else if (previousWasAsap) {
        timeEl.value = available[0].value;
        if (messageEl) messageEl.textContent = "Pickup is available today only. ASAP follows the earliest available time.";
      } else if (available.some((slot) => slot.value === previousValue)) {
        timeEl.value = previousValue;
        if (messageEl) messageEl.textContent = "Pickup is available today only.";
      } else {
        timeEl.value = "";
        if (messageEl) messageEl.textContent = previousValue
          ? "Your selected time has passed or filled up. Please choose another."
          : "Pickup is available today only.";
      }
      updateProceedButton();
    })
    .catch(() => {
      timeEl.innerHTML = '<option value="">Could not load times</option>';
      if (messageEl) messageEl.textContent = "Could not load pickup times. Please try again.";
      updateProceedButton();
    });
}

/* ── Reorder: pull a past order's items and push them back into the cart ── */
function reorder(orderId) {
  fetch(`${APP_URL}/orders.php?get_order_items=${orderId}`)
    .then((r) => r.json())
    .then((data) => {
      if (!data.items || data.items.length === 0) {
        showToast("error", "Could not load this order.");
        return;
      }
      const cart = getCart();
      data.items.forEach((item) => {
        cart.push({
          lineId: "l_" + Date.now() + Math.random().toString(16).slice(2),
          productId: item.product_id,
          name: item.name,
          size: null,
          sugar: null,
          addons: [],
          unit_price: parseFloat(item.price_at_time),
          qty: parseInt(item.quantity) || 1,
          note: item.note || "",
        });
      });
      saveCart(cart);
      showToast("success", "Added to your cart!");
      openCart();
    })
    .catch(() => showToast("error", "Network error — please try again."));
}

/* ── Checkout ── */
function proceedToPayment() {
  const cart = getCart();
  if (cart.length === 0) {
    showToast("warning", "Your cart is empty.");
    return;
  }
  const pickupTimeEl = document.getElementById("pickupTime");
  if (!pickupTimeEl?.value || pickupTimeEl.options[pickupTimeEl.selectedIndex]?.disabled) {
    showToast("warning", "Please choose an available pickup time.");
    return;
  }
  const pickupDate = todayInManila();
  const pickupTime = pickupTimeEl.value;
  const pickupTimestamp = new Date(`${pickupDate}T${pickupTime}:00+08:00`).getTime();
  if (pickupTimestamp < Date.now() + 5 * 60 * 1000) {
    loadPickupSlots();
    showToast("warning", "Pickup times must be at least 5 minutes from now. Choose another time.");
    return;
  }
  document.getElementById("paymentAmount").textContent =
    "₱" + cart.reduce((sum, item) => sum + item.unit_price * item.qty, 0).toFixed(2);
  document.getElementById("refNo").value = "";
  document.getElementById("paymentModal").hidden = false;
}

function closePaymentModal() {
  document.getElementById("paymentModal").hidden = true;
}

function submitOrder() {
  const cart = getCart();
  const pickupTimeEl = document.getElementById("pickupTime");
  if (cart.length === 0 || !pickupTimeEl?.value) {
    closePaymentModal();
    showToast("warning", "Your cart or pickup time changed. Please review it and try again.");
    return;
  }
  const refNo = document.getElementById("refNo").value.trim();
  if (!refNo) {
    showToast("warning", "Please enter your GCash reference number.");
    return;
  }
  const pickupDate = todayInManila();
  const pickupLabel =
    pickupTimeEl.options[pickupTimeEl.selectedIndex]?.text ||
    pickupTimeEl.value;
  const placeBtn = document.getElementById("submitPaymentButton");
  const payload = {
    items: cart,
    reference_no: refNo,
    notes: document.getElementById("cartNotes").value.trim(),
    pickup_date: pickupDate,
    pickup_time: pickupTimeEl.value,
  };
  placeBtn.disabled = true;
  placeBtn.textContent = "Submitting…";
  fetch(`${APP_URL}/api/checkout.php`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": CSRF_TOKEN,
    },
    body: JSON.stringify(payload),
  })
    .then((r) => r.json())
    .then((res) => {
      if (res.ok) {
        saveCart([]);
        closePaymentModal();
        closeCart();
        openConfirmModal(res, { pickupLabel, pickupDate });
        if (typeof onOrderPlaced === "function") onOrderPlaced(res);
      } else {
        if (["slot_unavailable", "slot_full", "closed"].includes(res.reason)) {
          closePaymentModal();
          loadPickupSlots();
        }
        showToast("error", res.message || "Something went wrong.");
      }
    })
    .catch(() => {
      showToast("error", "Network error — please try again.");
    })
    .finally(() => {
      placeBtn.disabled = false;
      placeBtn.textContent = "Submit order";
    });
}

function openConfirmModal(res, meta) {
  document.getElementById("confOrderNo").textContent = res.order_number;
  const dateLabel = new Date(meta.pickupDate + "T00:00:00").toLocaleDateString(
    undefined,
    { month: "short", day: "numeric" },
  );
  document.getElementById("confPickup").textContent =
    `${dateLabel}, ${meta.pickupLabel}`;
  document.getElementById("confPayment").textContent = "GCash — pending verification";
  document.getElementById("confTotal").textContent =
    "\u20b1" + Number(res.total).toFixed(2);
  document.getElementById("confirmModal").hidden = false;
}

function closeConfirmModal() {
  document.getElementById("confirmModal").hidden = true;
}

/* ── Wiring: pickup-time refresh + keyboard support for pill-style pickers
   (size/sugar in the customize modal still use .pay-option styling) ── */
document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("pickupTime")?.addEventListener("change", () => {
    pickupTimeManuallySelected = true;
    const messageEl = document.getElementById("pickupSlotMessage");
    if (messageEl) messageEl.textContent = "Pickup is available today only.";
    updateProceedButton();
  });
  // Refresh while the drawer is open. If the selection is still the automatic
  // ASAP choice, it advances as time passes; an explicitly chosen time stays put.
  window.setInterval(() => {
    if (document.getElementById("cartSidebar")?.classList.contains("open")) {
      loadPickupSlots();
    }
  }, 30000);
  renderCartBadge();
});

document.addEventListener("keydown", (e) => {
  if ((e.key === "Enter" || e.key === " ") && e.target.matches(".pay-option")) {
    e.preventDefault();
    e.target.click();
  }
});
