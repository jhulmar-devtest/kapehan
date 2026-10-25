/* ============================================================
   cart-drawer.js
   Shared cart, checkout, toast, and order-confirmation logic.
   Requires globals APP_URL and CSRF_TOKEN to be defined before
   this file is loaded, and the markup from
   includes/cart-drawer.php to be present on the page.
   ============================================================ */

/* ── Small helpers ── */
function escapeHtml(value) {
  return String(value ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      })[c],
  );
}

function formatPeso(amount) {
  return "\u20b1" + Number(amount || 0).toFixed(2);
}

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

let cartLastFocus = null;

function openCart() {
  // Pages that allow anonymous browsing (menu.php) can define
  // window.beforeOpenCart to intercept — e.g. show a sign-in prompt
  // instead of the cart for guests — without duplicating this function.
  if (
    typeof window.beforeOpenCart === "function" &&
    window.beforeOpenCart() === false
  )
    return;
  cartLastFocus = document.activeElement;
  renderCartSidebar();
  document.getElementById("cartSidebar").classList.add("open");
  document.getElementById("scrim").classList.add("open");
  document.getElementById("toastStack")?.classList.add("cart-open");
  document.body.classList.add("cart-locked");
  document
    .querySelector("#cartSidebar .modal-close")
    ?.focus({ preventScroll: true });
}

function closeCart() {
  document.getElementById("cartSidebar").classList.remove("open");
  document.getElementById("scrim").classList.remove("open");
  document.getElementById("toastStack")?.classList.remove("cart-open");
  document.body.classList.remove("cart-locked");
  if (cartLastFocus && typeof cartLastFocus.focus === "function") {
    cartLastFocus.focus({ preventScroll: true });
  }
  cartLastFocus = null;
}

/* ── Footer layout: scrolling options + pinned total/checkout ──
   Works with the existing markup (ids unchanged) by wrapping the
   existing elements, so every page that uses the drawer benefits
   without editing its HTML. */
function organizeCartFooter() {
  const footer = document.getElementById("cartFooter");
  if (!footer || footer.classList.contains("is-organized")) return;
  const button = document.getElementById("proceedPaymentButton");
  const startRow =
    document.getElementById("cartSubtotalRow") ||
    document.getElementById("cartTotal")?.closest(".cart-total-row");
  if (!button || !startRow) return;
  const children = Array.from(footer.children);
  const splitAt = children.indexOf(startRow);
  if (splitAt === -1) return;

  const options = document.createElement("div");
  options.className = "cart-footer-options";
  const summary = document.createElement("div");
  summary.className = "cart-footer-summary";
  children.forEach((el, i) =>
    (i < splitAt ? options : summary).appendChild(el),
  );

  const hint = document.createElement("p");
  hint.id = "checkoutHint";
  hint.className = "cart-checkout-hint";
  hint.setAttribute("role", "status");
  hint.hidden = true;
  summary.insertBefore(hint, button);

  footer.append(options, summary);
  footer.classList.add("is-organized");
}

/* How many units of a product are allowed in total, if its stock is tracked */
function cartStockCap(cart, productId) {
  const caps = cart
    .filter((i) => Number(i.productId) === Number(productId))
    .filter(
      (i) =>
        i.stockMax !== null &&
        i.stockMax !== undefined &&
        Number.isFinite(Number(i.stockMax)),
    )
    .map((i) => Number(i.stockMax));
  return caps.length ? Math.min(...caps) : null;
}

/* Resolve a cart item's thumbnail URL. Prefer the image saved with the item;
   for older cart entries and reorders (which have none), reuse the image
   already shown on the menu card when that page is open. */
function cartItemImageSrc(item) {
  if (item.image) {
    const path = String(item.image)
      .split("/")
      .map(encodeURIComponent)
      .join("/");
    return `${APP_URL}/../uploads/products/${path}`;
  }
  const menuImg = document
    .getElementById("card-" + item.productId)
    ?.querySelector(".menu-card-img img");
  return menuImg ? menuImg.getAttribute("src") : "";
}

function cartLineHtml(item, cart) {
  const id = escapeHtml(item.lineId);
  const name = escapeHtml(item.name);
  const cap = cartStockCap(cart, item.productId);
  const productQty = cart
    .filter((i) => Number(i.productId) === Number(item.productId))
    .reduce((sum, i) => sum + Number(i.qty || 0), 0);
  const atMax = cap !== null && productQty >= cap;

  const imgSrc = cartItemImageSrc(item);
  const thumb = imgSrc
    ? `<img src="${escapeHtml(imgSrc)}" alt="" loading="lazy" onerror="this.parentNode.innerHTML='<i class=&quot;fa-solid fa-mug-hot&quot;></i>'">`
    : '<i class="fa-solid fa-mug-hot" aria-hidden="true"></i>';

  const chips = [item.size, item.sugar]
    .filter(Boolean)
    .map((t) => `<span class="cart-chip">${escapeHtml(t)}</span>`)
    .concat(
      (item.addons || []).map(
        (a) => `<span class="cart-chip addon">+ ${escapeHtml(a)}</span>`,
      ),
    )
    .join("");

  return `
    <div class="cart-line">
      <div class="cart-line-thumb">${thumb}</div>
      <div class="cart-line-main">
        <div class="cart-line-top">
          <div class="cart-line-name">${name}</div>
          <button type="button" class="cart-line-remove" aria-label="Remove ${name} from cart" onclick="requestCartRemoval('${id}')"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
        </div>
        ${chips ? `<div class="cart-line-tags">${chips}</div>` : ""}
        ${item.note ? `<div class="cart-line-note">&ldquo;${escapeHtml(item.note)}&rdquo;</div>` : ""}
        <div class="cart-line-bottom">
          <div class="cart-line-qty" role="group" aria-label="Quantity for ${name}">
            <button type="button" class="qty-btn" aria-label="Decrease quantity" ${item.qty <= 1 ? "disabled" : ""} onclick="cartChangeQty('${id}',-1)">\u2212</button>
            <span aria-live="polite">${item.qty}</span>
            <button type="button" class="qty-btn" aria-label="Increase quantity" ${atMax ? "disabled" : ""} onclick="cartChangeQty('${id}',1)">+</button>
          </div>
          <div class="cart-line-pricing">
            <span class="cart-line-price">${formatPeso(item.unit_price * item.qty)}</span>
            ${item.qty > 1 ? `<span class="cart-line-unit">${formatPeso(item.unit_price)} each</span>` : ""}
          </div>
        </div>
        ${atMax ? '<div class="cart-line-warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Maximum available quantity reached</div>' : ""}
      </div>
    </div>`;
}

function renderCartSidebar(refreshSlots = true) {
  const cart = getCart();
  const body = document.getElementById("cartBody");
  if (!body) return;

  const itemCount = cart.reduce((s, i) => s + Number(i.qty || 0), 0);
  document
    .getElementById("cartSidebar")
    ?.classList.toggle("is-empty", cart.length === 0);
  const headerCount = document.getElementById("cartHeaderCount");
  if (headerCount) {
    headerCount.hidden = itemCount === 0;
    headerCount.textContent = `${itemCount} item${itemCount === 1 ? "" : "s"}`;
  }

  if (cart.length === 0) {
    body.innerHTML = `
      <div class="cart-empty-state">
        <div class="cart-empty-icon"><i class="fa-solid fa-mug-hot" aria-hidden="true"></i></div>
        <div class="cart-empty-title">Your cart is empty</div>
        <p class="cart-empty-text">Pick a drink or snack from the menu and it will show up here.</p>
        <button type="button" class="btn btn-primary" onclick="closeCart()">Browse the menu</button>
      </div>`;
  } else {
    body.innerHTML = cart.map((item) => cartLineHtml(item, cart)).join("");
  }

  const subtotal = cart.reduce((s, i) => s + i.unit_price * i.qty, 0);
  updateLoyaltyRewardChoices(cart);
  const redemption = getCartLoyaltyRedemption(cart);
  const total = subtotal - redemption.discount;

  const subtotalRow = document.getElementById("cartSubtotalRow");
  if (subtotalRow?.firstElementChild) {
    subtotalRow.firstElementChild.textContent = `Subtotal \u00b7 ${itemCount} item${itemCount === 1 ? "" : "s"}`;
  }
  const subtotalEl = document.getElementById("cartSubtotal");
  if (subtotalEl) subtotalEl.textContent = formatPeso(subtotal);
  const discountRow = document.getElementById("cartLoyaltyDiscountRow");
  if (discountRow) {
    discountRow.hidden = redemption.discount <= 0;
    if (discountRow.firstElementChild) discountRow.firstElementChild.textContent = redemption.productName ? `Reward · ${redemption.productName}` : "Drink reward";
  }
  const discountEl = document.getElementById("cartLoyaltyDiscount");
  if (discountEl)
    discountEl.textContent = "\u2212" + formatPeso(redemption.discount);
  const totalEl = document.getElementById("cartTotal");
  if (totalEl) totalEl.textContent = formatPeso(total);

  if (refreshSlots) loadPickupSlots();
  else updateProceedButton();
}

function updateLoyaltyRewardChoices(cart) {
  const select = document.getElementById("loyaltyRewardProduct");
  const checkbox = document.getElementById("redeemLoyaltyCheckbox");
  if (!select || !checkbox) return;
  const balance = Number(window.LOYALTY_BALANCE || 0);
  const previous = select.value;
  const choices = (window.LOYALTY_PRODUCT_REWARDS || []).filter(
    (reward) => cart.some((item) =>
      Number(item.productId ?? item.product_id) === Number(reward.product_id) &&
      Number(item.unit_price ?? item.price ?? 0) >= Number(reward.discount_amount)
    ) && balance >= Number(reward.points_required),
  );
  select.replaceChildren();
  const placeholder = document.createElement("option");
  placeholder.value = "";
  placeholder.textContent = choices.length ? "Choose a reward drink" : "Add a reward drink with enough points";
  select.appendChild(placeholder);
  choices.forEach((reward) => {
    const option = document.createElement("option");
    option.value = String(reward.product_id);
    option.textContent = `${reward.name} · ${reward.points_required} pts for ${formatPeso(reward.discount_amount)} off`;
    select.appendChild(option);
  });
  if (choices.some((reward) => String(reward.product_id) === previous)) select.value = previous;
  select.disabled = choices.length === 0;
  checkbox.disabled = choices.length === 0;
  if (!choices.some((reward) => String(reward.product_id) === select.value)) {
    select.value = "";
    checkbox.checked = false;
  }
  const hint = document.getElementById("loyaltyCartHint");
  if (hint) {
    hint.textContent = choices.length
      ? `${balance} points available · choose one drink reward`
      : `${balance} points available · add a configured reward drink to redeem`;
  }
}

function getCartLoyaltyRedemption(cart = getCart()) {
  const checkbox = document.getElementById("redeemLoyaltyCheckbox");
  const productId = Number(document.getElementById("loyaltyRewardProduct")?.value || 0);
  if (!checkbox?.checked || !productId) return { points: 0, discount: 0, productId: 0 };
  const reward = (window.LOYALTY_PRODUCT_REWARDS || []).find(
    (item) => Number(item.product_id) === productId,
  );
  const inCart = cart.some((item) => Number(item.productId ?? item.product_id) === productId && Number(item.unit_price ?? item.price ?? 0) >= Number(reward?.discount_amount || 0));
  if (!reward || !inCart || Number(window.LOYALTY_BALANCE || 0) < Number(reward.points_required)) {
    return { points: 0, discount: 0, productId: 0 };
  }
  return {
    points: Number(reward.points_required),
    discount: Number(reward.discount_amount),
    productId,
    productName: reward.name,
  };
}

function cartChangeQty(lineId, d) {
  const cart = getCart();
  const item = cart.find((i) => i.lineId === lineId);
  if (!item) return;
  const productItems = cart.filter(
    (i) => Number(i.productId) === Number(item.productId),
  );
  const stockCaps = productItems
    .filter(
      (i) =>
        i.stockMax !== null &&
        i.stockMax !== undefined &&
        Number.isFinite(Number(i.stockMax)),
    )
    .map((i) => Number(i.stockMax));
  const nextProductQty =
    productItems.reduce((sum, i) => sum + Number(i.qty || 0), 0) + d;
  if (d > 0 && stockCaps.length && nextProductQty > Math.min(...stockCaps)) {
    showToast("warning", `Estimated stock limit reached for ${item.name}.`);
    return;
  }
  item.qty = Math.max(1, item.qty + d);
  saveCart(cart);
  renderCartSidebar(false);
}

function cartRemove(lineId) {
  saveCart(getCart().filter((i) => i.lineId !== lineId));
  renderCartSidebar(false);
}

function requestCartRemoval(lineId) {
  const item = getCart().find((line) => line.lineId === lineId);
  if (!item) return;
  openActionConfirmation({
    title: "Remove this item?",
    message: `Remove ${item.name} from your cart?`,
    confirmLabel: "Remove item",
    onConfirm: () => cartRemove(lineId),
  });
}

let actionConfirmationCallback = null;

function openActionConfirmation({ title, message, confirmLabel, onConfirm }) {
  const modal = document.getElementById("actionConfirmModal");
  if (!modal) {
    onConfirm?.();
    return;
  }
  document.getElementById("actionConfirmHeading").textContent = title;
  document.getElementById("actionConfirmMessage").textContent = message;
  document.getElementById("actionConfirmButton").textContent = confirmLabel;
  actionConfirmationCallback = onConfirm;
  modal.hidden = false;
  document.getElementById("actionConfirmButton").focus();
}

function closeActionConfirmation() {
  const modal = document.getElementById("actionConfirmModal");
  if (modal) modal.hidden = true;
  actionConfirmationCallback = null;
}

function confirmPendingAction() {
  const callback = actionConfirmationCallback;
  closeActionConfirmation();
  callback?.();
}

let pickupTimeManuallySelected = false;
let pickupSlotsLoading = false;

function todayInManila() {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

/* What (if anything) is stopping the customer from continuing? */
function getCheckoutBlocker() {
  if (getCart().length === 0) return "Add an item to continue.";
  const timeEl = document.getElementById("pickupTime");
  if (!timeEl?.value || timeEl.options[timeEl.selectedIndex]?.disabled) {
    return "Choose a pickup time to continue.";
  }
  const deliveryEl = document.getElementById("deliveryLocation");
  if (deliveryEl && !deliveryEl.value.trim()) {
    return "Enter your delivery location to continue.";
  }
  return "";
}

function updateProceedButton() {
  const button = document.getElementById("proceedPaymentButton");
  if (!button) return;
  const blocker = getCheckoutBlocker();
  button.disabled = pickupSlotsLoading || !!blocker;
  const hint = document.getElementById("checkoutHint");
  if (hint) {
    const show = !pickupSlotsLoading && !!blocker;
    hint.hidden = !show;
    hint.innerHTML = show
      ? `<i class="fa-solid fa-circle-info" aria-hidden="true"></i><span></span>`
      : "";
    if (show) hint.querySelector("span").textContent = blocker;
  }
}

function loadPickupSlots() {
  const timeEl = document.getElementById("pickupTime");
  const messageEl = document.getElementById("pickupSlotMessage");
  if (!timeEl) return;
  const previousValue = timeEl.value;
  const previousWasAsap =
    !pickupTimeManuallySelected ||
    timeEl.options[timeEl.selectedIndex]?.dataset.asap === "true";
  pickupSlotsLoading = true;
  updateProceedButton();
  fetch(`${APP_URL}/api/pickup-slots.php?date=${todayInManila()}`)
    .then((r) => r.json())
    .then((slots) => {
      const available = slots.filter((slot) => !slot.full);
      timeEl.innerHTML =
        slots
          .map(
            (slot) =>
              `<option value="${slot.value}" data-asap="${slot.asap ? "true" : "false"}" ${slot.full ? "disabled" : ""}>${slot.label}${slot.full ? " (full)" : ""}</option>`,
          )
          .join("") || '<option value="">No times available</option>';

      if (!available.length) {
        timeEl.value = "";
        if (messageEl)
          messageEl.textContent = slots.length
            ? "All pickup times are full for today."
            : "Ordering is closed for today.";
      } else if (previousWasAsap) {
        timeEl.value = available[0].value;
        if (messageEl)
          messageEl.textContent =
            "Pickup is available today only. ASAP follows the earliest available time.";
      } else if (available.some((slot) => slot.value === previousValue)) {
        timeEl.value = previousValue;
        if (messageEl)
          messageEl.textContent = "Pickup is available today only.";
      } else {
        timeEl.value = "";
        if (messageEl)
          messageEl.textContent = previousValue
            ? "Your selected time has passed or filled up. Please choose another."
            : "Pickup is available today only.";
      }
      pickupSlotsLoading = false;
      updateProceedButton();
    })
    .catch(() => {
      timeEl.innerHTML = '<option value="">Could not load times</option>';
      if (messageEl)
        messageEl.textContent =
          "Could not load pickup times. Please try again.";
      pickupSlotsLoading = false;
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
      let skipped = 0;
      data.items.forEach((item) => {
        if (
          !Number(item.is_available) ||
          (Number(item.has_sizes) && !item.size)
        ) {
          skipped++;
          return;
        }
        cart.push({
          lineId: "l_" + Date.now() + Math.random().toString(16).slice(2),
          productId: item.product_id,
          name: item.name,
          image: item.image_path || null,
          size: item.size || null,
          sugar: item.sugar || null,
          addons: item.addons || [],
          addon_ids: item.addon_ids || [],
          unit_price: parseFloat(item.unit_price),
          qty: parseInt(item.quantity) || 1,
          note: item.custom_note || "",
        });
      });
      if (!data.items.length || data.items.length === skipped) {
        showToast(
          "error",
          "The products in this order are no longer available to reorder.",
        );
        return;
      }
      saveCart(cart);
      showToast(
        skipped ? "warning" : "success",
        skipped
          ? `Available items added. ${skipped} unavailable item${skipped === 1 ? " was" : "s were"} skipped.`
          : "Added to your cart with the original options and current prices!",
      );
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
  const deliveryLocationEl = document.getElementById("deliveryLocation");
  if (deliveryLocationEl && !deliveryLocationEl.value.trim()) {
    showToast("warning", "Please enter where your order should be delivered.");
    deliveryLocationEl.focus();
    return;
  }
  const pickupTimeEl = document.getElementById("pickupTime");
  if (
    !pickupTimeEl?.value ||
    pickupTimeEl.options[pickupTimeEl.selectedIndex]?.disabled
  ) {
    showToast("warning", "Please choose an available pickup time.");
    return;
  }
  const pickupDate = todayInManila();
  const pickupTime = pickupTimeEl.value;
  const pickupTimestamp = new Date(
    `${pickupDate}T${pickupTime}:00+08:00`,
  ).getTime();
  if (pickupTimestamp < Date.now() + 5 * 60 * 1000) {
    loadPickupSlots();
    showToast(
      "warning",
      "Pickup times must be at least 5 minutes from now. Choose another time.",
    );
    return;
  }
  const subtotal = cart.reduce(
    (sum, item) => sum + item.unit_price * item.qty,
    0,
  );
  const redemption = getCartLoyaltyRedemption(cart);
  document.getElementById("paymentAmount").textContent =
    "₱" + (subtotal - redemption.discount).toFixed(2);
  const loyaltyRow = document.getElementById("paymentLoyaltyRow");
  if (loyaltyRow) loyaltyRow.hidden = redemption.discount <= 0;
  const loyaltyDiscount = document.getElementById("paymentLoyaltyDiscount");
  if (loyaltyDiscount)
    loyaltyDiscount.textContent = `−₱${redemption.discount.toFixed(2)} (${redemption.points} points for ${redemption.productName})`;
  document.getElementById("refNo").value = "";
  if (window.PAYMONGO_ENABLED) setupGatewayPaymentModal();
  document.getElementById("paymentModal").hidden = false;
}

// Online GCash: hide the manual "send money + paste reference" parts.
function setupGatewayPaymentModal() {
  const modal = document.getElementById("paymentModal");
  ["gcashPaymentName", "gcashPaymentNumber"].forEach((id) => {
    const row = document.getElementById(id)?.closest(".confirm-row");
    if (row) row.style.display = "none";
  });
  const refInput = document.getElementById("refNo");
  if (refInput) refInput.style.display = "none";
  const refLabel = modal.querySelector('label[for="refNo"]');
  if (refLabel) refLabel.style.display = "none";
  const hint = modal.querySelector("p.cart-hint");
  if (hint) hint.style.display = "none";
  const sub = modal.querySelector(".confirm-subheading");
  if (sub)
    sub.textContent =
      "You'll be taken to GCash's secure payment page to pay for this order.";
  const btn = document.getElementById("submitPaymentButton");
  if (btn) {
    btn.disabled = false;
    btn.textContent = "Pay with GCash";
  }
}

function closePaymentModal() {
  document.getElementById("paymentModal").hidden = true;
}

function submitOrder() {
  const cart = getCart();
  const pickupTimeEl = document.getElementById("pickupTime");
  if (cart.length === 0 || !pickupTimeEl?.value) {
    closePaymentModal();
    showToast(
      "warning",
      "Your cart or pickup time changed. Please review it and try again.",
    );
    return;
  }
  // Online GCash (PayMongo) needs no reference number; the manual flow does.
  const useGateway = !!window.PAYMONGO_ENABLED;
  const refNo = useGateway ? "" : document.getElementById("refNo").value.trim();
  if (!useGateway) {
    if (!refNo) {
      showToast("warning", "Please enter your GCash reference number.");
      return;
    }
    if (refNo.replace(/\D/g, "").length !== (window.GCASH_REF_DIGITS || 13)) {
      showToast(
        "warning",
        `Please enter the ${window.GCASH_REF_DIGITS || 13}-digit GCash reference number from your receipt.`,
      );
      return;
    }
  }
  const pickupDate = todayInManila();
  const pickupLabel =
    pickupTimeEl.options[pickupTimeEl.selectedIndex]?.text ||
    pickupTimeEl.value;
  const placeBtn = document.getElementById("submitPaymentButton");
  const payload = {
    items: cart,
    reference_no: refNo,
    pay_via: useGateway ? "paymongo" : "manual",
    notes: document.getElementById("cartNotes").value.trim(),
    delivery_location:
      document.getElementById("deliveryLocation")?.value.trim() || "",
    pickup_date: pickupDate,
    pickup_time: pickupTimeEl.value,
    redeem_points: !!document.getElementById("redeemLoyaltyCheckbox")?.checked,
    redeem_product_id: getCartLoyaltyRedemption(cart).productId,
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
      if (res.ok && res.redirect_url) {
        // Online GCash: hand the customer over to PayMongo's payment page.
        window.location.href = res.redirect_url;
        return;
      }
      if (res.ok) {
        saveCart([]);
        const redeemCheckbox = document.getElementById("redeemLoyaltyCheckbox");
        if (redeemCheckbox) redeemCheckbox.checked = false;
        const rewardSelect = document.getElementById("loyaltyRewardProduct");
        if (rewardSelect) rewardSelect.value = "";
        if (Number.isFinite(Number(res.loyalty_balance)))
          window.LOYALTY_BALANCE = Number(res.loyalty_balance);
        const loyaltyHint = document.getElementById("loyaltyCartHint");
        if (loyaltyHint)
          loyaltyHint.textContent = `${window.LOYALTY_BALANCE || 0} points available · choose a reward drink in your cart`;
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
      placeBtn.textContent = window.PAYMONGO_ENABLED
        ? "Pay with GCash"
        : "Submit order";
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
  document.getElementById("confPayment").textContent =
    "GCash — pending verification";
  document.getElementById("confTotal").textContent =
    "\u20b1" + Number(res.total).toFixed(2);
  const redeemedRow = document.getElementById("confPointsRedeemedRow");
  if (redeemedRow) redeemedRow.hidden = !(Number(res.points_redeemed) > 0);
  const redeemedEl = document.getElementById("confPointsRedeemed");
  if (redeemedEl)
    redeemedEl.textContent = `${res.points_redeemed} points (₱${Number(res.loyalty_discount || 0).toFixed(2)} off)`;
  const earnedRow = document.getElementById("confPointsEarnedRow");
  if (earnedRow)
    earnedRow.hidden = !(Number(res.points_expected_after_pickup) > 0);
  const earnedEl = document.getElementById("confPointsEarned");
  if (earnedEl)
    earnedEl.textContent = `${res.points_expected_after_pickup} points`;
  document.getElementById("confirmModal").hidden = false;
}

function closeConfirmModal() {
  document.getElementById("confirmModal").hidden = true;
}

/* ── Wiring: pickup-time refresh + keyboard support for pill-style pickers
   (size/sugar in the customize modal still use .pay-option styling) ── */
document.addEventListener("DOMContentLoaded", () => {
  organizeCartFooter();
  const sidebar = document.getElementById("cartSidebar");
  if (sidebar) {
    sidebar.setAttribute("role", "dialog");
    sidebar.setAttribute("aria-modal", "true");
    sidebar.setAttribute("aria-label", "Your order");
  }

  document.addEventListener("click", (event) => {
    const link = event.target.closest('a[href$="/logout.php"]');
    if (
      !link ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    )
      return;
    event.preventDefault();
    openActionConfirmation({
      title: "Sign out?",
      message: "Are you sure you want to sign out?",
      confirmLabel: "Sign out",
      onConfirm: () => window.location.assign(link.href),
    });
  });
  document
    .getElementById("actionConfirmModal")
    ?.addEventListener("click", (event) => {
      if (event.target.id === "actionConfirmModal") closeActionConfirmation();
    });
  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    if (!document.getElementById("actionConfirmModal")?.hidden) {
      closeActionConfirmation();
      return;
    }
    // Close the drawer with Escape, but only if no other dialog is on top of it
    if (
      document.getElementById("cartSidebar")?.classList.contains("open") &&
      !document.querySelector(".modal-overlay:not([hidden])")
    ) {
      closeCart();
    }
  });
  document
    .getElementById("actionConfirmButton")
    ?.addEventListener("click", confirmPendingAction);
  document
    .getElementById("actionCancelButton")
    ?.addEventListener("click", closeActionConfirmation);
  document.getElementById("pickupTime")?.addEventListener("change", () => {
    pickupTimeManuallySelected = true;
    const messageEl = document.getElementById("pickupSlotMessage");
    if (messageEl) messageEl.textContent = "Pickup is available today only.";
    updateProceedButton();
  });
  document
    .getElementById("deliveryLocation")
    ?.addEventListener("input", updateProceedButton);
  document
    .getElementById("redeemLoyaltyCheckbox")
    ?.addEventListener("change", () => renderCartSidebar(false));
  document
    .getElementById("loyaltyRewardProduct")
    ?.addEventListener("change", () => renderCartSidebar(false));
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
