// Cashier pickup reminders are loaded by the shared cashier layout so they
// continue working while the cashier moves between pages.
(function () {
  const endpoint = document.body.dataset.cashierPickupAlertEndpoint || "";
  if (!endpoint) return;

  const dateKey = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
  const sentKey = `kapehanPickupAlertsSent:${dateKey}`;
  const activeAlertsKey = `kapehanPickupActiveAlerts:${dateKey}`;
  const lastSoundKey = `kapehanPickupLastSound:${dateKey}`;
  const soundRepeatMs = 20000;
  let enabled = true;
  let audioContext = null;
  let polling = false;
  let activeAlerts = readActiveAlerts();

  function getAudioContext() {
    if (audioContext) return audioContext;
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (AudioContextClass) audioContext = new AudioContextClass();
    return audioContext;
  }

  function playSound(kind) {
    if (!enabled || !audioContext || audioContext.state !== "running") return false;
    const notes = kind === "near"
      ? [{ offset: 0, frequency: 560 }, { offset: 0.2, frequency: 680 }]
      : [{ offset: 0, frequency: 660 }, { offset: 0.24, frequency: 880 }, { offset: 0.48, frequency: 660 }];
    const startAt = audioContext.currentTime;
    notes.forEach((note) => {
      const oscillator = audioContext.createOscillator();
      const gain = audioContext.createGain();
      const start = startAt + note.offset;
      const duration = kind === "near" ? 0.14 : 0.18;
      oscillator.type = "sine";
      oscillator.frequency.setValueAtTime(note.frequency, start);
      gain.gain.setValueAtTime(0.0001, start);
      gain.gain.exponentialRampToValueAtTime(0.12, start + 0.025);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
      oscillator.connect(gain);
      gain.connect(audioContext.destination);
      oscillator.start(start);
      oscillator.stop(start + duration + 0.01);
    });
    return true;
  }

  function maybePlayReminderSound() {
    if (!enabled || !activeAlerts.length || !audioContext || audioContext.state !== "running") return;
    let lastSoundAt = window._cashierPickupLastSoundAt || 0;
    try {
      lastSoundAt = Number(localStorage.getItem(lastSoundKey)) || lastSoundAt;
    } catch (error) {
      // Continue using this page's timestamp if browser storage is unavailable.
    }
    if (Date.now() - lastSoundAt < soundRepeatMs) return;

    const kind = activeAlerts.some((alert) => alert.kind === "due") ? "due" : "near";
    if (!playSound(kind)) return;
    const now = Date.now();
    window._cashierPickupLastSoundAt = now;
    try { localStorage.setItem(lastSoundKey, String(now)); } catch (error) {}
  }

  function readSentAlerts() {
    try {
      const value = JSON.parse(localStorage.getItem(sentKey) || "[]");
      return new Set(Array.isArray(value) ? value.map(String) : []);
    } catch (error) {
      return new Set();
    }
  }

  function saveSentAlerts(sent) {
    try {
      localStorage.setItem(sentKey, JSON.stringify([...sent]));
    } catch (error) {
      // Reminders still work for this page if browser storage is unavailable.
    }
  }

  function readActiveAlerts() {
    try {
      const value = JSON.parse(localStorage.getItem(activeAlertsKey) || "[]");
      return Array.isArray(value) ? value.filter((alert) => alert && alert.id && (alert.kind === "near" || alert.kind === "due")) : [];
    } catch (error) {
      return [];
    }
  }

  function saveActiveAlerts() {
    try {
      localStorage.setItem(activeAlertsKey, JSON.stringify(activeAlerts));
    } catch (error) {
      // The alert still stays visible for the current page.
    }
  }

  function renderPersistentAlerts() {
    let region = document.getElementById("cashierPickupAlerts");
    if (!region) {
      region = document.createElement("section");
      region.id = "cashierPickupAlerts";
      region.className = "cashier-pickup-alerts";
      region.setAttribute("aria-label", "Pickup reminders");
      region.setAttribute("aria-live", "assertive");
      document.body.appendChild(region);
    }

    region.replaceChildren();
    const groups = ["due", "near"].map((kind) => ({
      kind,
      orders: activeAlerts.filter((alert) => alert.kind === kind),
    })).filter((group) => group.orders.length);

    groups.forEach(({ kind, orders }) => {
      const card = document.createElement("article");
      card.className = `cashier-pickup-alert${kind === "due" ? " is-due" : ""}`;
      const icon = document.createElement("i");
      icon.className = `cashier-pickup-alert-icon fa-solid ${kind === "due" ? "fa-bell" : "fa-clock"}`;
      icon.setAttribute("aria-hidden", "true");

      const copy = document.createElement("div");
      copy.className = "cashier-pickup-alert-copy";
      const title = document.createElement("strong");
      title.textContent = kind === "due" ? "Pickup time reached" : "Pickup in 5 minutes";
      const orderNumbers = orders.slice(0, 4).map((order) => `#${order.orderNumber}`).join(", ");
      const extraCount = orders.length > 4 ? ` and ${orders.length - 4} more` : "";
      const detail = document.createElement("div");
      detail.textContent = `${orderNumbers}${extraCount}. Check the pre-order queue.`;
      const actions = document.createElement("div");
      actions.className = "cashier-pickup-alert-actions";
      const link = document.createElement("a");
      link.className = "cashier-pickup-alert-link";
      link.href = endpoint.replace(/\/api\/cashier-pickup-alerts\.php(?:\?.*)?$/, "/cashier/preorders.php");
      link.textContent = "View pre-orders";
      const dismiss = document.createElement("button");
      dismiss.type = "button";
      dismiss.className = "cashier-pickup-alert-dismiss";
      dismiss.setAttribute("aria-label", "Acknowledge pickup reminder and stop its sound");
      dismiss.textContent = "Acknowledge";
      dismiss.addEventListener("click", () => {
        activeAlerts = activeAlerts.filter((alert) => alert.kind !== kind);
        saveActiveAlerts();
        if (!activeAlerts.length) {
          try { localStorage.removeItem(lastSoundKey); } catch (error) {}
        }
        renderPersistentAlerts();
      });
      actions.append(link, dismiss);
      copy.append(title, detail, actions);
      card.append(icon, copy);
      region.appendChild(card);
    });
  }

  function getPickupKind(order, serverNow) {
    if (order.pickup_time === "ASAP") return "due";
    if (!/^\d{1,2}:\d{2}/.test(order.pickup_time || "") || !order.pickup_date) return "";
    const time = order.pickup_time.slice(0, 5);
    const pickupAt = new Date(`${order.pickup_date}T${time}:00+08:00`);
    const untilPickup = pickupAt.getTime() - serverNow.getTime();
    if (untilPickup <= 0) return "due";
    if (untilPickup <= 5 * 60 * 1000) return "near";
    return "";
  }

  function alertMessage(kind, orders) {
    const numbers = orders.slice(0, 3).map((order) => `#${order.order_number}`).join(", ");
    const remaining = orders.length > 3 ? ` and ${orders.length - 3} more` : "";
    return kind === "near"
      ? `Pickup in 5 minutes: ${numbers}${remaining}. Check the pre-order queue.`
      : `Pickup time reached: ${numbers}${remaining}. Check the pre-order queue.`;
  }

  function sendVisualAlert(kind, orders) {
    const known = new Set(activeAlerts.map((alert) => `${alert.kind}:${alert.id}`));
    orders.forEach((order) => {
      const id = String(order.id);
      if (kind === "due") {
        activeAlerts = activeAlerts.filter((alert) => !(alert.id === id && alert.kind === "near"));
      }
      if (!known.has(`${kind}:${id}`)) {
        activeAlerts.push({ id, orderNumber: String(order.order_number), kind });
      }
    });
    saveActiveAlerts();
    renderPersistentAlerts();

    if (document.visibilityState !== "visible" && "Notification" in window && Notification.permission === "granted") {
      try {
        const notification = new Notification(
          kind === "near" ? "Pickup in 5 minutes" : "Pickup time reached",
          {
            body: alertMessage(kind, orders),
            tag: `kapehan-pickup-${kind}-${orders.map((order) => order.id).join("-")}`,
            renotify: false,
          },
        );
        notification.onclick = () => window.focus();
        return true;
      } catch (error) {
        return true;
      }
    }
    return true;
  }

  async function enableAudioFromGesture() {
    const context = getAudioContext();
    if (!context) return;
    try {
      if (context.state !== "running") await context.resume();
      maybePlayReminderSound();
    } catch (error) {
      // Browser audio policies may require the next cashier interaction.
    }
  }

  async function pollOrders() {
    if (polling || !endpoint) return;
    polling = true;
    try {
      const response = await fetch(endpoint, {
        cache: "no-store",
        credentials: "same-origin",
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      if (!response.ok) return;
      const result = await response.json();
      if (!result.success || !Array.isArray(result.orders)) return;

      const activeOrderIds = new Set(result.orders.map((order) => String(order.id)));
      activeAlerts = activeAlerts.filter((alert) => activeOrderIds.has(String(alert.id)));
      saveActiveAlerts();
      renderPersistentAlerts();
      if (!activeAlerts.length) {
        try { localStorage.removeItem(lastSoundKey); } catch (error) {}
      }

      const serverNow = new Date(result.server_now || Date.now());
      const dueOrders = { near: [], due: [] };
      result.orders.forEach((order) => {
        const kind = getPickupKind(order, serverNow);
        if (kind) dueOrders[kind].push(order);
      });

      const sent = readSentAlerts();
      ["near", "due"].forEach((kind) => {
        const orders = dueOrders[kind];
        if (!orders.length) return;
        const noticeKey = `${kind}:notice`;
        const needingNotice = orders.filter((order) => !sent.has(`${order.id}:${noticeKey}`));

        if (needingNotice.length && sendVisualAlert(kind, needingNotice)) {
          needingNotice.forEach((order) => sent.add(`${order.id}:${noticeKey}`));
        }
      });
      saveSentAlerts(sent);
      maybePlayReminderSound();
    } catch (error) {
      // A missed poll should never interrupt the cashier's current task.
    } finally {
      polling = false;
    }
  }

  renderPersistentAlerts();

  pollOrders();
  document.addEventListener("pointerdown", enableAudioFromGesture, { passive: true });
  document.addEventListener("keydown", enableAudioFromGesture);
  document.addEventListener("visibilitychange", pollOrders);
  window.addEventListener("focus", pollOrders);
  window.setInterval(pollOrders, 5000);
  window.setInterval(maybePlayReminderSound, 2000);
})();
