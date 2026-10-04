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
}

function closeCart() {
  document.getElementById("cartSidebar").classList.remove("open");
  document.getElementById("scrim").classList.remove("open");
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

function loadPickupSlots() {
  const dateEl = document.getElementById("pickupDate");
  const timeEl = document.getElementById("pickupTime");
  if (!dateEl || !timeEl) return;
  fetch(`${APP_URL}/api/pickup-slots.php?date=${dateEl.value}`)
    .then((r) => r.json())
    .then((slots) => {
      timeEl.innerHTML =
        slots
          .map(
            (s) =>
              `<option value="${s.value}" ${s.full ? "disabled" : ""}>${s.label}${s.full ? " (full)" : ""}</option>`,
          )
          .join("") || '<option value="">No slots available</option>';
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
function placeOrder() {
  const cart = getCart();
  if (cart.length === 0) {
    showToast("warning", "Your cart is empty.");
    return;
  }
  const pickupDateEl = document.getElementById("pickupDate");
  const pickupTimeEl = document.getElementById("pickupTime");
  if (!pickupDateEl.value || !pickupTimeEl.value) {
    showToast("warning", "Please choose a pickup date and time.");
    return;
  }
  const refNo = document.getElementById("refNo").value.trim();
  if (!refNo) {
    showToast("warning", "Please enter your GCash reference number.");
    return;
  }
  const pickupLabel =
    pickupTimeEl.options[pickupTimeEl.selectedIndex]?.text ||
    pickupTimeEl.value;
  const placeBtn = document.querySelector("#cartFooter .btn-primary");
  const payload = {
    items: cart,
    reference_no: refNo,
    notes: document.getElementById("cartNotes").value.trim(),
    pickup_date: pickupDateEl.value,
    pickup_time: pickupTimeEl.value,
  };
  placeBtn.disabled = true;
  placeBtn.textContent = "Placing order…";
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
        closeCart();
        openConfirmModal(res, { pickupLabel, pickupDate: payload.pickup_date });
        if (typeof onOrderPlaced === "function") onOrderPlaced(res);
      } else {
        showToast("error", res.message || "Something went wrong.");
      }
    })
    .catch(() => {
      showToast("error", "Network error — please try again.");
    })
    .finally(() => {
      placeBtn.disabled = false;
      placeBtn.textContent = "Place Order";
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
  document.getElementById("confPayment").textContent = "GCash";
  document.getElementById("confTotal").textContent =
    "\u20b1" + Number(res.total).toFixed(2);
  document.getElementById("confirmModal").hidden = false;
}

function closeConfirmModal() {
  document.getElementById("confirmModal").hidden = true;
}

/* ── Wiring: date change + keyboard support for pill-style pickers
   (size/sugar in the customize modal still use .pay-option styling) ── */
document.addEventListener("DOMContentLoaded", () => {
  document
    .getElementById("pickupDate")
    ?.addEventListener("change", loadPickupSlots);
  renderCartBadge();
});

document.addEventListener("keydown", (e) => {
  if ((e.key === "Enter" || e.key === " ") && e.target.matches(".pay-option")) {
    e.preventDefault();
    e.target.click();
  }
});
