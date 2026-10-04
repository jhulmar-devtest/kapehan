<?php

/**
 * receipt_styles.php — Canonical receipt CSS
 *
 * Included by both receipt.php (screen full-page) and orders.php (modal).
 * All rules use the .rcpt-* namespace to avoid collisions.
 *
 * Screen: receipt renders at 380px, card-like.
 * Print:  @media print targets an 80mm thermal roll (216px ≈ 80mm at 72dpi).
 *         For 58mm rolls set --rcpt-print-w to 158px.
 */
?>
<style id="rcpt-styles">
  /* ═══════════════════════════════════════════════════
   RECEIPT — screen
   ═══════════════════════════════════════════════════ */
  :root {
    --rcpt-width: 380px;
    /* screen card width          */
    --rcpt-pad: 24px;
    /* inner padding              */
    --rcpt-font: 'DM Sans', system-ui, sans-serif;
    --rcpt-mono: 'Courier New', Courier, monospace;
    --rcpt-print-w: 216px;
    /* 80mm @ 72 dpi              */
  }

  .rcpt-paper {
    width: 100%;
    max-width: var(--rcpt-width);
    margin: 0 auto;
    background: var(--surface-color, #fff);
    border: 1px solid var(--border-color, #e0dbd4);
    border-radius: 10px;
    padding: var(--rcpt-pad);
    box-shadow: 0 4px 16px rgba(24, 18, 14, .10);
    font-family: var(--rcpt-font);
    color: var(--text-color, #18120e);
    box-sizing: border-box;
  }

  /* ── Header ─────────────────────────────────────── */
  .rcpt-header {
    text-align: center;
    margin-bottom: 14px;
  }

  .rcpt-icon {
    font-size: 26px;
    color: var(--primary-color, #c0392b);
    margin-bottom: 6px;
    line-height: 1;
  }

  .rcpt-shop-name {
    font-family: var(--rcpt-font);
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--primary-color, #c0392b);
    letter-spacing: -0.01em;
    line-height: 1.2;
  }

  .rcpt-tagline {
    font-family: var(--rcpt-font);
    font-size: 0.68rem;
    color: var(--text-muted, #978e84);
    font-style: italic;
    margin-top: 3px;
  }

  /* ── Divider ─────────────────────────────────────── */
  .rcpt-rule {
    border: none;
    border-top: 1px dashed var(--border-color, #e0dbd4);
    margin: 10px 0;
  }

  /* ── Meta ────────────────────────────────────────── */
  .rcpt-meta {
    font-size: 0.76rem;
    color: var(--text-secondary, #54483e);
    line-height: 1.65;
  }

  .rcpt-label {
    font-weight: 700;
    color: var(--text-color, #18120e);
  }

  /* ── Line items ──────────────────────────────────── */
  .rcpt-items {
    margin: 2px 0;
  }

  .rcpt-item {
    display: flex;
    align-items: baseline;
    gap: 5px;
    padding: 2px 0;
    font-size: 0.80rem;
  }

  .rcpt-item-name {
    flex: 1;
    color: var(--text-color, #18120e);
    font-weight: 500;
  }

  .rcpt-item-qty {
    font-size: 0.72rem;
    color: var(--text-muted, #978e84);
    flex-shrink: 0;
    min-width: 26px;
    text-align: center;
  }

  .rcpt-item-sub {
    font-weight: 600;
    flex-shrink: 0;
    text-align: right;
    min-width: 72px;
    font-size: 0.80rem;
  }

  .rcpt-item-note {
    font-size: 0.68rem;
    color: var(--text-muted, #978e84);
    padding-left: 10px;
    margin-top: -1px;
    margin-bottom: 2px;
    font-style: italic;
  }

  /* ── Totals ──────────────────────────────────────── */
  .rcpt-totals {
    margin: 2px 0;
  }

  .rcpt-total-grand {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 8px 0 5px;
    border-top: 1.5px solid var(--border-color, #e0dbd4);
    margin-top: 4px;
    font-size: 1.00rem;
    font-weight: 800;
    color: var(--text-color, #18120e);
    letter-spacing: -0.01em;
  }

  .rcpt-total-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 2px 0;
    font-size: 0.80rem;
  }

  .rcpt-total-lbl {
    color: var(--text-secondary, #54483e);
  }

  .rcpt-total-val {
    font-weight: 600;
    color: var(--text-color, #18120e);
  }

  /* ── Footer ──────────────────────────────────────── */
  .rcpt-footer {
    text-align: center;
    font-size: 0.68rem;
    color: var(--text-muted, #978e84);
    line-height: 1.6;
    margin-top: 4px;
  }


  /* ═══════════════════════════════════════════════════
   RECEIPT — @media print  (thermal 80mm / 58mm)
   ═══════════════════════════════════════════════════ */
  @media print {

    /* Hide the page chrome (nav, header, footer, etc.) */
    body>header,
    body>nav,
    body>footer,
    body>.no-print,
    body>.page-header,
    body>.sidebar,
    body>.sidebar-backdrop,
    .receipt-overlay,
    .receipt-modal,
    .topbar {
      display: none !important;
    }

    /* Show the wrapper containers so the receipt inside is visible */
    .main-wrapper,
    .page-content {
      display: block !important;
      margin: 0 !important;
      padding: 0 !important;
      width: 100% !important;
    }

    /* Hide siblings of .rcpt-print-root inside .page-content */
    .page-content>*:not(.rcpt-print-root):not(script):not(style) {
      display: none !important;
    }

    /* Hide flash messages and alerts */
    .alert,
    [style*="padding:12px 28px"] {
      display: none !important;
    }

    /* Hide no-print elements anywhere */
    .no-print {
      display: none !important;
    }

    /* Reset page */
    @page {
      size: 80mm auto;
      /* thermal roll width; height auto-expands */
      margin: 3mm 2mm;
    }

    html,
    body {
      width: 80mm;
      background: #fff !important;
      font-size: 9pt;
    }

    /* Paper resets for print */
    .rcpt-paper {
      width: 100% !important;
      max-width: 100% !important;
      border: none !important;
      box-shadow: none !important;
      border-radius: 0 !important;
      padding: 0 !important;
      margin: 0 !important;
      font-family: 'Courier New', Courier, monospace !important;
    }

    /* Header */
    .rcpt-icon {
      font-size: 18pt !important;
    }

    .rcpt-shop-name {
      font-size: 11pt !important;
      font-weight: 900 !important;
    }

    .rcpt-tagline {
      font-size: 7pt !important;
    }

    /* Divider */
    .rcpt-rule {
      border-top: 1px dashed #000 !important;
      margin: 6px 0 !important;
    }

    /* Meta */
    .rcpt-meta {
      font-size: 7.5pt !important;
      line-height: 1.5 !important;
    }

    .rcpt-label {
      font-weight: 700 !important;
    }

    /* Items */
    .rcpt-item {
      font-size: 8pt !important;
      padding: 1px 0 !important;
    }

    .rcpt-item-qty {
      font-size: 7pt !important;
    }

    .rcpt-item-sub {
      font-size: 8pt !important;
      min-width: 54px !important;
    }

    .rcpt-item-note {
      font-size: 6.5pt !important;
    }

    /* Totals */
    .rcpt-total-grand {
      font-size: 10pt !important;
      border-top: 1.5px solid #000 !important;
      padding: 5px 0 3px !important;
    }

    .rcpt-total-row {
      font-size: 8pt !important;
    }

    .rcpt-total-lbl,
    .rcpt-total-val {
      color: #000 !important;
    }

    /* Footer */
    .rcpt-footer {
      font-size: 7pt !important;
    }
  }
</style>