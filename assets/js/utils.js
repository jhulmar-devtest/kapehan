// assets/js/utils.js
// Small reusable JavaScript utilities used across the app.

/**
 * peso(amount)
 * Formats a number as Philippine Peso.
 * Example: peso(1234.5) → "₱1,234.50"
 */
function peso(amount) {
  return (
    "₱" +
    parseFloat(amount).toLocaleString("en-PH", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}

/**
 * showToast(message, type)
 * Shows a small notification that auto-dismisses.
 * type: 'success' | 'error' | 'warning'
 * Creates the toast element if it doesn't exist yet.
 */
function showToast(message, type = "success") {
  let toast = document.getElementById("app-toast");
  if (!toast) {
    toast = document.createElement("div");
    toast.id = "app-toast";
    toast.style.cssText = `
      position:fixed;bottom:24px;right:24px;padding:12px 20px;
      border-radius:var(--radius-sm);font-size:.84rem;display:flex;align-items:center;
      gap:10px;transform:translateY(80px);transition:transform .3s;
      z-index:9999;font-family:'DM Sans',sans-serif;box-shadow:0 4px 20px rgba(0,0,0,.15);
      max-width:320px;
    `;
    document.body.appendChild(toast);
  }

  // Solid-fill colors sourced from the same --status-* tokens that drive
  // every badge, pill, and modal icon elsewhere in the app — this used to
  // be a completely separate hardcoded palette (dark red/brown instead of
  // the app's actual status colors), so "error" here didn't match "error"
  // anywhere else a cashier or admin would see it.
  const root = getComputedStyle(document.documentElement);
  const colors = {
    success: {
      bg: root.getPropertyValue("--status-ready").trim() || "#059669",
      icon: "fa-circle-check",
    },
    error: {
      bg: root.getPropertyValue("--status-cancelled").trim() || "#dc2626",
      icon: "fa-circle-xmark",
    },
    warning: {
      bg: root.getPropertyValue("--status-pending").trim() || "#d97706",
      icon: "fa-triangle-exclamation",
    },
  };
  const style = colors[type] || colors.success;

  toast.style.background = style.bg;
  toast.style.color = "#fff";
  toast.innerHTML = `<i class="fa-solid ${style.icon}" style="color:#fff;flex-shrink:0"></i> ${message}`;
  toast.style.transform = "translateY(0)";

  clearTimeout(toast._timeout);
  toast._timeout = setTimeout(() => {
    toast.style.transform = "translateY(80px)";
  }, 3000);
}

/**
 * confirmDelete(message, formOrCallback)
 * Shows a browser confirm dialog. Returns true if user confirms.
 * Usage: <form onsubmit="return confirmDelete('Delete this item?')">
 */
function confirmDelete(message) {
  return confirm(
    message || "Are you sure you want to delete this? This cannot be undone.",
  );
}

/**
 * fetchJSON(url, options)
 * Wrapper around fetch() that automatically:
 * - Sets Content-Type to application/json
 * - Includes the CSRF token in headers
 * - Returns parsed JSON
 */
async function fetchJSON(url, options = {}) {
  const csrfToken =
    document.querySelector('meta[name="csrf-token"]')?.content || "";

  const defaults = {
    headers: {
      "Content-Type": "application/json",
      "X-Requested-With": "XMLHttpRequest",
      "X-CSRF-TOKEN": csrfToken,
    },
  };

  const merged = {
    ...defaults,
    ...options,
    headers: { ...defaults.headers, ...(options.headers || {}) },
  };

  const response = await fetch(url, merged);
  return response.json();
}

/**
 * initToasts()
 * Auto-dismisses toast notifications after 5 seconds.
 * Call this on page load.
 */
function initToasts() {
  const toasts = document.querySelectorAll(".toast");
  toasts.forEach((toast) => {
    setTimeout(() => {
      toast.classList.add("toast-exit");
      setTimeout(() => toast.remove(), 300);
    }, 5000);
  });
}

// Initialize on DOM ready
document.addEventListener("DOMContentLoaded", initToasts);

/* ============================================================
   MODAL POPUP SYSTEM
   Uses CSS variables from variables.css for consistent styling
   ============================================================ */

/**
 * showModal(options)
 * Shows a custom modal dialog with the given options.
 *
 * @param {Object} options - Modal configuration
 * @param {string} options.title - Modal title
 * @param {string} options.message - Modal body message (HTML supported)
 * @param {string} options.icon - FontAwesome icon class (e.g., 'fa-triangle-exclamation')
 * @param {string} options.iconColor - Icon color variant: 'primary' | 'success' | 'warning' | 'danger' | 'info'
 * @param {string} options.confirmText - Text for confirm button (default: 'Confirm')
 * @param {string} options.cancelText - Text for cancel button (default: 'Cancel')
 * @param {string} options.confirmClass - Button class for confirm (default: 'btn-primary')
 * @param {boolean} options.showCancel - Show cancel button (default: true)
 * @param {boolean} options.danger - Use danger styling (default: false)
 * @param {Function} options.onConfirm - Callback when confirmed
 * @param {Function} options.onCancel - Callback when cancelled
 *
 * @returns {Promise<boolean>} - Resolves true if confirmed, false if cancelled
 */
function showModal(options = {}) {
  const {
    title = "Confirm",
    message = "",
    icon = "fa-circle-question",
    iconColor = "primary",
    confirmText = "Confirm",
    cancelText = "Cancel",
    confirmClass = "btn-primary",
    showCancel = true,
    danger = false,
    onConfirm = null,
    onCancel = null,
  } = options;

  return new Promise((resolve) => {
    // Remove existing modal if any
    const existing = document.getElementById("app-modal");
    if (existing) existing.remove();

    // Map icon colors to CSS variable-based classes
    const iconColors = {
      primary: "color: var(--primary-color)",
      success: "color: var(--status-ready)",
      warning: "color: var(--status-pending)",
      danger: "color: var(--status-cancelled)",
      info: "color: var(--status-preparing)",
    };

    const modal = document.createElement("div");
    modal.id = "app-modal";
    modal.className = "modal-overlay";
    modal.innerHTML = `
      <div class="modal" style="width: 420px; max-width: 95vw;">
        <div class="modal-header" style="padding: var(--space-4) var(--space-5);">
          <div class="modal-title" style="font-size: 0.92rem;">
            <i class="fa-solid ${icon}" style="${iconColors[iconColor] || iconColors.primary}; font-size: 15px;"></i>
            <span>${escapeHtml(title)}</span>
          </div>
          <button type="button" class="modal-close" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <div class="modal-body" style="padding: var(--space-5);">
          <div style="font-size: 0.88rem; line-height: 1.6; color: var(--text-secondary);">
            ${message}
          </div>
        </div>
        <div class="modal-footer" style="padding: var(--space-3) var(--space-5);">
          ${showCancel ? `<button type="button" class="btn btn-ghost modal-cancel">${escapeHtml(cancelText)}</button>` : ""}
          <button type="button" class="btn ${danger ? "btn-danger" : confirmClass} modal-confirm">
            ${danger ? '<i class="fa-solid fa-triangle-exclamation"></i>' : ""}
            ${escapeHtml(confirmText)}
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    // Get elements
    const overlay = modal;
    const closeBtn = modal.querySelector(".modal-close");
    const cancelBtn = modal.querySelector(".modal-cancel");
    const confirmBtn = modal.querySelector(".modal-confirm");
    const modalBox = modal.querySelector(".modal");

    // Close handlers
    const closeModal = (result) => {
      modalBox.style.animation = "modalOut 0.15s ease forwards";
      setTimeout(() => {
        modal.remove();
        resolve(result);
        if (result && onConfirm) onConfirm();
        if (!result && onCancel) onCancel();
      }, 150);
    };

    // Add modalOut animation
    const style = document.createElement("style");
    style.textContent = `
      @keyframes modalOut {
        from { opacity: 1; transform: scale(1) translateY(0); }
        to { opacity: 0; transform: scale(0.96) translateY(10px); }
      }
    `;
    document.head.appendChild(style);

    // Event listeners
    closeBtn?.addEventListener("click", () => closeModal(false));
    cancelBtn?.addEventListener("click", () => closeModal(false));
    confirmBtn?.addEventListener("click", () => closeModal(true));
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) closeModal(false);
    });

    // Escape key closes modal
    const escHandler = (e) => {
      if (e.key === "Escape") {
        closeModal(false);
        document.removeEventListener("keydown", escHandler);
      }
    };
    document.addEventListener("keydown", escHandler);

    // Focus confirm button
    confirmBtn?.focus();
  });
}

/**
 * confirmModal(message, options)
 * Convenience wrapper for confirm-style dialogs
 *
 * @param {string} message - The confirmation message
 * @param {Object} options - Additional options
 * @returns {Promise<boolean>}
 */
function confirmModal(message, options = {}) {
  return showModal({
    title: options.title || "Confirm Action",
    message,
    icon: options.icon || "fa-circle-question",
    iconColor: options.iconColor || "primary",
    confirmText: options.confirmText || "Yes, proceed",
    cancelText: options.cancelText || "Cancel",
    showCancel: true,
    danger: options.danger || false,
    ...options,
  });
}

/**
 * alertModal(message, options)
 * Shows an alert-style modal (single OK button)
 *
 * @param {string} message - The alert message
 * @param {Object} options - Additional options
 * @returns {Promise<boolean>}
 */
function alertModal(message, options = {}) {
  return showModal({
    title: options.title || "Notice",
    message,
    icon: options.icon || "fa-circle-info",
    iconColor: options.iconColor || "info",
    confirmText: options.confirmText || "OK",
    showCancel: false,
    ...options,
  });
}

/**
 * showLockedOrderModal(orderData)
 * Shows a modal informing that an order is locked by another cashier
 *
 * @param {Object} orderData - Order lock info
 * @param {string} orderData.locked_by_name - Name of cashier who locked it
 * @param {string} orderData.order_number - Order number for display
 */
function showLockedOrderModal(orderData) {
  return alertModal(
    `This order is currently being prepared by <strong>${escapeHtml(orderData.locked_by_name || "another cashier")}</strong>. Please wait until they finish or the lock expires.`,
    {
      title: "Order Locked",
      icon: "fa-lock",
      iconColor: "warning",
    },
  );
}

/**
 * escapeHtml(str)
 * Escapes HTML special characters to prevent XSS
 */
function escapeHtml(str) {
  if (typeof str !== "string") return str;
  const div = document.createElement("div");
  div.textContent = str;
  return div.innerHTML;
}
