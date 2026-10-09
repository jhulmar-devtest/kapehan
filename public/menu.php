<?php
// ============================================================
// public/menu.php
//
// THE HOMEPAGE. Works for guests, students, and faculty alike:
//   - Guest: can browse everything, but tapping a product or the
//     cart opens the sign-in/register modal instead of adding to cart.
//   - Student/Faculty: full ordering — add to cart, open the cart
//     sidebar, pick a pickup date/time, and check out — all without
//     ever leaving this page.
//
// Replaces: landing.php, the old guest-only menu.php, student/menu.php,
// student/cart.php, faculty/menu.php, faculty/cart.php, and the
// role-select step of login.php/register.php.
// ============================================================

require_once __DIR__ . '/../config/init.php';

$role       = currentRole(); // null for guests
$loggedIn   = isLoggedIn() && in_array($role, [ROLE_STUDENT, ROLE_FACULTY], true);

// ---- Products: flat categories, no parent grouping (items 4 & 5) ----
$db = Database::getInstance();
$products = $db->query(
  "SELECT p.*, c.name AS cat_name, c.id AS cat_id,
          COALESCE(ROUND(AVG(pr.rating),1), 0) AS avg_rating,
          COUNT(DISTINCT pr.id) AS rating_count,
          COALESCE(SUM(od.quantity), 0) AS total_sold,
          CASE WHEN EXISTS (
              SELECT 1 FROM product_addons pa
              JOIN addons a ON pa.addon_id = a.id
              WHERE pa.product_id = p.id AND a.status = 'active'
          ) THEN 1 ELSE 0 END AS has_addons
   FROM products p
   JOIN categories c ON p.category_id = c.id
   LEFT JOIN product_ratings pr ON pr.product_id = p.id
   LEFT JOIN order_details od   ON od.product_id  = p.id
   WHERE c.name != 'Add-ons' AND p.is_available = 1
   GROUP BY p.id, c.name, c.id, c.sort_order
   ORDER BY c.sort_order, p.name"
)->fetchAll();

$spotlightProducts = getSpotlightProducts(5);

$soldArr = array_column($products, 'total_sold');
rsort($soldArr);
$bestSellerThreshold = ($soldArr[0] ?? 0) > 0 ? ($soldArr[min(4, count($soldArr) - 1)] ?? 1) : PHP_INT_MAX;

// Flat, leaf-only categories that actually have products — no parent rows rendered (item 5)
$categories = $db->query(
  "SELECT c.* FROM categories c
    WHERE c.name != 'Add-ons'
      AND EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.is_available = 1)
    ORDER BY c.sort_order, c.name"
)->fetchAll();

// Size options — editable via admin, falls back to the old constant if table is empty
$sizeOptions = [];
try {
  $sizeOptions = $db->query("SELECT * FROM size_options WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
} catch (\Throwable $e) { /* migration not run yet */
}
if (empty($sizeOptions)) {
  $sizeOptions = [
    ['label' => '16oz', 'price_adjustment' => 0],
    ['label' => '22oz', 'price_adjustment' => defined('SIZE_22OZ_UPCHARGE') ? SIZE_22OZ_UPCHARGE : 20],
  ];
}

$imgBase = APP_URL . '/../uploads/products/';

// Auto-open the auth modal if we were redirected back here (login/register error, or ?auth=)
$autoAuthTab = $_GET['auth'] ?? '';
if (!in_array($autoAuthTab, ['login', 'register'], true)) $autoAuthTab = '';
$registerOld = $_SESSION['register_old'] ?? [];
unset($_SESSION['register_old']);

$storeOpen  = getSetting('store_open_time', '07:00');
$storeClose = getSetting('store_close_time', '20:00');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrfToken() ?>">
  <title><?= APP_NAME ?> — EARIST Cavite Campus</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/variables.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/cart-drawer.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/cart-drawer.css') ?>">
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html {
      font-size: 17px;
      scroll-behavior: smooth;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    body {
      font-family: 'DM Sans', system-ui, sans-serif;
      background: var(--background-color);
      color: var(--text-color);
      -webkit-font-smoothing: antialiased;
    }

    /* ── Shared container width — this is what keeps header/nav/content aligned (item 9) ── */
    .container {
      max-width: 1200px;
      margin: 0 auto;
      padding-inline: 24px;
    }

    /* ── Header ── */
    .site-header {
      position: sticky;
      top: 0;
      z-index: 100;
      background: rgba(243, 240, 236, .92);
      backdrop-filter: blur(14px);
      border-bottom: 1px solid var(--border-color);
    }

    .header-inner {
      display: flex;
      align-items: center;
      gap: 20px;
      height: 82px;
    }

    .logo img {
      height: 46px;
      display: block;
    }

    .header-search {
      display: flex;
      align-items: center;
      gap: 10px;
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-full);
      padding: 10px 18px;
      width: 360px;
      font-size: 1.05rem;
      transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    }

    .header-search:focus-within {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px var(--primary-subtle);
    }

    .header-search input {
      border: none;
      outline: none;
      background: none;
      font: inherit;
      flex: 1;
      color: var(--text-color);
    }

    .header-search i {
      color: var(--text-muted);
    }

    .header-actions {
      margin-left: auto;
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .user-menu {
      position: relative;
      display: inline-block;
    }

    .user-menu-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 48px;
      width: 48px;
      border-radius: 50%;
      border: 1px solid var(--border-color);
      background: var(--surface-color);
      cursor: pointer;
      font: inherit;
      font-size: 22px;
      color: var(--text-secondary);
      transition: background .15s ease, border-color .15s ease, color .15s ease;
    }

    .user-menu-btn:hover {
      background: var(--surface-raised);
      border-color: var(--primary-color);
      color: var(--primary-color);
    }

    .user-menu-btn:focus-visible,
    .cart-btn:focus-visible {
      outline: 2px solid var(--primary-color);
      outline-offset: 2px;
    }

    .user-menu-dropdown {
      position: absolute;
      top: 100%;
      right: 0;
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-md);
      min-width: 180px;
      display: none;
      flex-direction: column;
      padding: 8px 0;
      z-index: 1000;
    }

    .user-menu-dropdown.open {
      display: flex;
    }

    .user-menu-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 11px 18px;
      font-size: 15px;
      font-weight: 500;
      color: var(--text-color);
      transition: background .15s ease;
    }

    .user-menu-item i {
      width: 18px;
      text-align: center;
      color: var(--text-secondary);
    }

    .user-menu-item:hover i {
      color: var(--text-color);
    }

    .user-menu-item:hover {
      background: var(--surface-raised);
    }

    .btn {
      font: inherit;
      font-size: 1.02rem;
      cursor: pointer;
      border: none;
      border-radius: var(--radius-sm);
      padding: 11px 20px;
      font-weight: 600;
      transition: background var(--transition-fast), box-shadow var(--transition-fast), opacity var(--transition-fast);
    }

    .btn:focus-visible {
      outline: 2px solid var(--primary-color);
      outline-offset: 2px;
    }

    .btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .btn-primary {
      background: var(--primary-color);
      color: var(--text-on-primary);
    }

    .btn-primary:hover {
      background: var(--primary-dark);
    }

    .btn-ghost {
      background: none;
      border: 1px solid var(--border-color);
      color: var(--text-color);
    }

    .btn-ghost:hover {
      background: var(--surface-raised);
    }

    .cart-btn {
      position: relative;
      width: 48px;
      height: 48px;
      border-radius: var(--radius-md);
      border: 1px solid var(--border-color);
      background: var(--surface-color);
      cursor: pointer;
      font-size: 19px;
      color: var(--text-color);
    }

    .cart-badge {
      position: absolute;
      top: -6px;
      right: -6px;
      background: var(--primary-color);
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      border-radius: var(--radius-full);
      min-width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 4px;
    }

    /* ── Category nav (flat + horizontally scrollable — items 5 & 9) ── */
    .category-nav {
      border-top: 1px solid var(--border-color);
    }

    .category-scroll {
      display: flex;
      gap: 8px;
      overflow-x: auto;
      scrollbar-width: none;
      padding-block: 12px;
    }

    .category-scroll::-webkit-scrollbar {
      display: none;
    }

    .cat-pill {
      flex: 0 0 auto;
      white-space: nowrap;
      padding: 10px 20px;
      border-radius: var(--radius-full);
      border: 1px solid var(--border-color);
      background: var(--surface-color);
      cursor: pointer;
      font: inherit;
      font-weight: 600;
      font-size: 14.5px;
      color: var(--text-secondary);
      transition: background var(--transition-fast), border-color var(--transition-fast),
        color var(--transition-fast), box-shadow var(--transition-fast);
    }

    .cat-pill:hover {
      border-color: var(--primary-color);
      color: var(--primary-color);
    }

    .cat-pill.active {
      background: var(--primary-color);
      border-color: var(--primary-color);
      color: var(--text-on-primary);
      box-shadow: var(--shadow-primary);
    }

    .cat-pill.active:hover {
      color: var(--text-on-primary);
    }

    .cat-pill:focus-visible,
    .pay-option:focus-visible,
    .tab-btn:focus-visible,
    .qty-btn:focus-visible,
    .menu-card:focus-visible {
      outline: 2px solid var(--primary-color);
      outline-offset: 2px;
    }

    /* ── Spotlight (hybrid pinned + auto-picked highlight strip) ── */
    .spotlight-section {
      margin-bottom: 24px;
      padding: 22px 22px 26px;
      border-radius: var(--radius-xl);
      background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-light) 100%);
      position: relative;
      overflow: hidden;
    }

    .spotlight-section::before {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at 88% -10%, var(--accent-subtle) 0%, transparent 55%);
      pointer-events: none;
    }

    .spotlight-heading {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 16px;
      position: relative;
    }

    .spotlight-heading h2 {
      font-family: 'Instrument Serif', serif;
      font-size: 29px;
      font-weight: 400;
      letter-spacing: -0.01em;
      color: #fff;
    }

    .spotlight-heading p {
      font-size: 14px;
      color: rgba(255, 255, 255, 0.72);
    }

    .spotlight-scroll {
      display: flex;
      gap: 14px;
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      scrollbar-width: none;
      padding-bottom: 2px;
      position: relative;
    }

    .spotlight-scroll::-webkit-scrollbar {
      display: none;
    }

    .spotlight-card {
      flex: 0 0 auto;
      scroll-snap-align: start;
      width: 240px;
      background: var(--surface-color);
      border-radius: var(--radius-lg);
      overflow: hidden;
      cursor: pointer;
      position: relative;
      transition: transform var(--transition-fast), box-shadow var(--transition-fast);
    }

    .spotlight-card:hover,
    .spotlight-card:focus-visible {
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg);
      outline: none;
    }

    .spotlight-card-img {
      height: 184px;
      background: var(--surface-raised);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
    }

    .spotlight-card-img img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .spotlight-card-img-icon {
      font-size: 36px;
      color: var(--text-placeholder);
    }

    .spotlight-rank {
      position: absolute;
      top: 10px;
      left: 10px;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: rgba(24, 18, 14, 0.55);
      backdrop-filter: blur(4px);
      color: #fff;
      font-size: 13px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .spotlight-card-body {
      padding: 13px 15px 15px;
    }

    .spotlight-card-name {
      font-weight: 600;
      font-size: 15.5px;
      margin-bottom: 6px;
    }

    .spotlight-card-meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .spotlight-card-price {
      font-weight: 700;
      color: var(--primary-color);
      font-size: 15px;
    }

    @media (max-width: 640px) {
      .spotlight-section {
        margin-bottom: 16px;
        border-radius: var(--radius-lg);
        padding: 18px 16px 20px;
      }
      .spotlight-card {
        width: 182px;
      }
      .spotlight-card-img {
        height: 142px;
      }
    }

    /* ── Main content ── */
    main.container {
      padding-block: 28px 120px;
    }

    .cat-section {
      margin-bottom: 44px;
    }

    .cat-section-title {
      display: flex;
      align-items: center;
      gap: 12px;
      font-family: 'Instrument Serif', serif;
      font-weight: 400;
      font-size: 30px;
      letter-spacing: -0.01em;
      margin-bottom: 18px;
    }

    .cat-section-title::before {
      content: '';
      flex: 0 0 auto;
      width: 28px;
      height: 2px;
      border-radius: var(--radius-xs);
      background: var(--primary-color);
    }

    .menu-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(228px, 1fr));
      gap: 22px;
    }

    .menu-card {
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      overflow: hidden;
      cursor: pointer;
      position: relative;
      transition: transform var(--transition-base), box-shadow var(--transition-base), border-color var(--transition-base);
    }

    .menu-card:hover,
    .menu-card:focus-visible {
      transform: translateY(-5px);
      box-shadow: var(--shadow-md);
      border-color: var(--border-strong);
    }

    .menu-card-img {
      height: 190px;
      background: var(--surface-raised);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
    }

    .menu-card-img img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform var(--transition-slow);
    }

    .menu-card:hover .menu-card-img img {
      transform: scale(1.07);
    }

    .menu-card-img-icon {
      font-size: 38px;
      color: var(--text-placeholder);
    }

    .best-seller-badge {
      position: absolute;
      top: 10px;
      left: 10px;
      background: var(--accent-color);
      color: var(--text-on-accent);
      font-size: 12px;
      font-weight: 700;
      padding: 5px 11px;
      border-radius: var(--radius-full);
      box-shadow: var(--shadow-xs);
    }

    .in-cart-badge {
      position: absolute;
      top: 8px;
      right: 8px;
      background: var(--primary-color);
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      min-width: 22px;
      height: 22px;
      border-radius: var(--radius-full);
      display: none;
      align-items: center;
      justify-content: center;
    }

    .menu-card.has-items .in-cart-badge {
      display: flex;
    }

    .menu-card-body {
      padding: 15px 17px 17px;
    }

    .menu-card-name {
      font-weight: 600;
      font-size: 16.5px;
      line-height: 1.35;
      margin-bottom: 7px;
    }

    .menu-card-meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .menu-card-price {
      font-weight: 700;
      color: var(--primary-color);
      font-size: 16px;
    }

    .card-rating-badge {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      font-size: 13px;
      font-weight: 600;
      color: var(--text-muted);
    }

    .card-rating-badge i {
      color: var(--accent-dark);
    }

    .menu-card-add {
      position: absolute;
      bottom: 12px;
      right: 12px;
      width: 34px;
      height: 34px;
      border-radius: var(--radius-full);
      background: var(--primary-color);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
    }

    .no-results {
      display: none;
      text-align: center;
      padding: 60px 0;
      color: var(--text-muted);
    }

    /* ── Footer — the staff-login link lives here, deliberately understated (item 10) ── */
    .site-footer {
      border-top: 1px solid var(--border-color);
      padding-block: 22px;
    }

    .footer-inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      font-size: 13.5px;
      color: var(--text-muted);
    }

    .footer-staff-link {
      color: var(--text-muted);
      text-decoration: underline;
      text-underline-offset: 2px;
    }

    .footer-staff-link:hover {
      color: var(--text-secondary);
    }

    .auth-tabs {
      display: flex;
      gap: 6px;
      background: var(--surface-raised);
      border-radius: var(--radius-md);
      padding: 4px;
      margin-bottom: 20px;
    }

    .tab-btn {
      flex: 1;
      padding: 9px;
      border: none;
      background: none;
      border-radius: var(--radius-sm);
      font: inherit;
      font-weight: 700;
      cursor: pointer;
      color: var(--text-muted);
    }

    .tab-btn.active {
      background: var(--surface-color);
      color: var(--text-color);
      box-shadow: var(--shadow-xs);
    }

    .auth-panel label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      margin: 12px 0 5px;
      color: var(--text-secondary);
    }

    .auth-panel input,
    .auth-panel select {
      width: 100%;
      padding: 11px 13px;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      font: inherit;
    }

    .auth-panel .btn-primary {
      width: 100%;
      margin-top: 18px;
      padding: 12px;
    }

    .link-muted {
      display: block;
      text-align: center;
      margin-top: 12px;
      font-size: 13px;
      color: var(--text-muted);
    }

    .checkbox-row {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      margin-top: 14px;
    }

    .checkbox-row input {
      width: auto;
    }

    .checkbox-row label {
      margin: 0;
      font-weight: 400;
    }


    @media (max-width: 768px) {
      .container {
        padding-inline: 16px;
      }

      .header-inner {
        height: auto;
        flex-wrap: wrap;
        gap: 10px;
        padding-block: 10px;
      }

      .logo {
        order: 1;
      }

      .header-actions {
        order: 2;
        gap: 8px;
      }

      .header-search {
        display: flex;
        order: 3;
        width: 100%;
        flex: 1 0 100%;
        min-width: 0;
        padding: 10px 14px;
        font-size: .95rem;
      }

      .category-scroll {
        padding-block: 8px;
      }

      .cat-pill {
        padding: 9px 14px;
        font-size: .82rem;
      }

      main.container {
        padding-block: 18px 88px;
      }

      .cat-section {
        margin-bottom: 30px;
      }

      .cat-section-title {
        font-size: 1.65rem;
        margin-bottom: 14px;
      }

      .menu-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
      }

      .menu-card-img {
        height: 132px;
      }

      .menu-card-body {
        padding: 12px 42px 13px 12px;
      }

      .menu-card-name {
        font-size: .88rem;
        line-height: 1.3;
      }

      .menu-card-price {
        font-size: .9rem;
      }

      .menu-card-add {
        width: 30px;
        height: 30px;
        right: 8px;
        bottom: 8px;
      }
    }

    @media (max-width: 380px) {
      .container {
        padding-inline: 12px;
      }

      .menu-grid {
        gap: 9px;
      }

      .menu-card-img {
        height: 112px;
      }

      .menu-card-body {
        padding-left: 10px;
        padding-top: 10px;
      }

      .menu-card-name {
        font-size: .82rem;
      }
    }
  </style>
</head>

<body>

  <div class="toast-stack" id="toastStack" aria-live="polite"></div>

  <div class="modal-overlay" id="actionConfirmModal" hidden>
    <div class="modal-box" role="alertdialog" aria-modal="true" aria-labelledby="actionConfirmHeading" aria-describedby="actionConfirmMessage">
      <button class="modal-close" type="button" onclick="closeActionConfirmation()" aria-label="Close">&times;</button>
      <div class="confirm-heading" id="actionConfirmHeading">Confirm action</div>
      <p class="confirm-subheading" id="actionConfirmMessage"></p>
      <div class="confirm-actions">
        <button class="btn btn-ghost" type="button" id="actionCancelButton">Cancel</button>
        <button class="btn btn-danger" type="button" id="actionConfirmButton">Confirm</button>
      </div>
    </div>
  </div>

  <header class="site-header">
    <div class="container header-inner">
      <a href="<?= APP_URL ?>/menu.php" class="logo">
        <img src="<?= APP_URL ?>/../assets/images/logo.png" alt="<?= APP_NAME ?>" onerror="this.style.display='none'">
      </a>

      <form class="header-search" role="search" action="<?= APP_URL ?>/menu.php" method="GET">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" id="menuSearch" name="q" aria-label="Search the menu" placeholder="Search the menu..." value="<?= e($_GET['q'] ?? '') ?>">
      </form>

      <div class="header-actions">
        <?php if ($loggedIn): ?>
          <div class="user-menu">
            <button class="user-menu-btn" onclick="toggleUserMenu()">
              <i class="fa-solid fa-circle-user" style="font-size: 22px;"></i>
            </button>
            <div class="user-menu-dropdown" id="userMenuDropdown">
              <a href="<?= APP_URL ?>/account.php" class="user-menu-item"><i class="fa-solid fa-user" style="color: var(--primary-color);"></i> My Profile</a>
              <a href="<?= APP_URL ?>/orders.php" class="user-menu-item"><i class="fa-solid fa-box" style="color: var(--primary-color);"></i> My Orders</a>
              <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 4px 0;">
              <a href="<?= APP_URL ?>/logout.php" class="user-menu-item"><i class="fa-solid fa-right-from-bracket" style="color: var(--primary-color);"></i> Sign Out</a>
            </div>
          </div>
        <?php else: ?>
          <button class="btn btn-primary" onclick="openAuthModal('login')">Sign In</button>
        <?php endif; ?>

        <button class="cart-btn" onclick="openCart()" aria-label="Cart">
          <i class="fa-solid fa-bag-shopping"></i>
          <span class="cart-badge" id="cartBadge" style="display:none">0</span>
        </button>
      </div>
    </div>

    <nav class="category-nav">
      <div class="container category-scroll" id="catScroll">
        <button class="cat-pill active" data-cat="all">All</button>
        <?php foreach ($categories as $cat): ?>
          <button class="cat-pill" data-cat="<?= $cat['id'] ?>"><?= e($cat['name']) ?></button>
        <?php endforeach; ?>
      </div>
    </nav>
  </header>

  <main class="container">

    <?php if (!empty($spotlightProducts)): ?>
      <div class="spotlight-section">
        <div class="spotlight-heading">
          <div>
            <h2>Today's Picks</h2>
            <p>Handpicked and crowd favorites, updated daily</p>
          </div>
        </div>
        <div class="spotlight-scroll" id="spotlightScroll">
          <?php foreach ($spotlightProducts as $i => $p): ?>
            <div class="spotlight-card"
              role="button"
              tabindex="0"
              aria-label="Add <?= e($p['name']) ?>, <?= peso($p['price']) ?>"
              onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
              onclick="onCardClick(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name'])) ?>, <?= (float)$p['price'] ?>, <?= htmlspecialchars(json_encode($p['image_path'] ?? '')) ?>, <?= (int)$p['has_sizes'] ?>, <?= (int)$p['has_sugar'] ?>, <?= (int)$p['has_addons'] ?>)">
              <div class="spotlight-card-img">
                <span class="spotlight-rank"><?= $i + 1 ?></span>
                <?php if (!empty($p['image_path']) && file_exists(UPLOAD_DIR . $p['image_path'])): ?>
                  <img src="<?= $imgBase . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                <?php else: ?>
                  <span class="spotlight-card-img-icon"><i class="fa-solid fa-mug-hot"></i></span>
                <?php endif; ?>
              </div>
              <div class="spotlight-card-body">
                <div class="spotlight-card-name"><?= e($p['name']) ?></div>
                <div class="spotlight-card-meta">
                  <div class="spotlight-card-price"><?= peso($p['price']) ?></div>
                  <?php if ($p['avg_rating'] > 0): ?>
                    <span class="card-rating-badge"><i class="fa-solid fa-star"></i> <?= $p['avg_rating'] ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div id="menuSections">
      <?php foreach ($categories as $cat):
        $catProducts = array_values(array_filter($products, fn($p) => $p['cat_id'] == $cat['id']));
        if (empty($catProducts)) continue;
      ?>
        <div class="cat-section" id="cat-<?= $cat['id'] ?>" data-cat="<?= $cat['id'] ?>">
          <div class="cat-section-title"><?= e($cat['name']) ?></div>
          <div class="menu-grid">
            <?php foreach ($catProducts as $p): ?>
              <div class="menu-card"
                id="card-<?= $p['id'] ?>"
                data-cat="<?= $p['cat_id'] ?>"
                data-name="<?= strtolower(e($p['name'])) ?>"
                role="button"
                tabindex="0"
                aria-label="Add <?= e($p['name']) ?>, <?= peso($p['price']) ?>"
                onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                onclick="onCardClick(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name'])) ?>, <?= (float)$p['price'] ?>, <?= htmlspecialchars(json_encode($p['image_path'] ?? '')) ?>, <?= (int)$p['has_sizes'] ?>, <?= (int)$p['has_sugar'] ?>, <?= (int)$p['has_addons'] ?>)">
                <div class="menu-card-img">
                  <?php if (!empty($p['image_path']) && file_exists(UPLOAD_DIR . $p['image_path'])): ?>
                    <img src="<?= $imgBase . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                  <?php else: ?>
                    <span class="menu-card-img-icon"><i class="fa-solid fa-mug-hot"></i></span>
                  <?php endif; ?>
                  <?php if ($p['total_sold'] >= $bestSellerThreshold && $p['total_sold'] > 0): ?>
                    <div class="best-seller-badge"><i class="fa-solid fa-fire"></i> Best Seller</div>
                  <?php endif; ?>
                  <div class="in-cart-badge" id="badge-<?= $p['id'] ?>">1</div>
                </div>
                <div class="menu-card-body">
                  <div class="menu-card-name"><?= e($p['name']) ?></div>
                  <div class="menu-card-meta">
                    <div class="menu-card-price"><?= peso($p['price']) ?></div>
                    <?php if ($p['avg_rating'] > 0): ?>
                      <span class="card-rating-badge"><i class="fa-solid fa-star"></i> <?= $p['avg_rating'] ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="menu-card-add"><i class="fa-solid fa-plus"></i></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="no-results" id="noResults">
        <i class="fa-solid fa-magnifying-glass" style="font-size:28px"></i>
        <p style="margin-top:10px">No items match your search.</p>
      </div>
    </div>
  </main>

  <footer class="site-footer">
    <div class="container footer-inner">
      <span>&copy; <?= date('Y') ?> <?= APP_NAME ?> &mdash; EARIST Cavite Campus</span>
      <a href="<?= APP_URL ?>/staff-login.php" class="footer-staff-link">Staff Login</a>
    </div>
  </footer>

  <div class="overlay-scrim" id="scrim" onclick="closeCart()"></div>

  <!-- ══════════════ CART SIDEBAR (item 7 & 8) ══════════════ -->
  <aside class="cart-sidebar" id="cartSidebar">
    <div class="cart-sidebar-header">
      <strong>Your Order</strong>
      <button class="modal-close" style="position:static" onclick="closeCart()">&times;</button>
    </div>

    <?php if (!$loggedIn): ?>
      <div class="cart-empty-state">
        <i class="fa-solid fa-bag-shopping" style="font-size:33px;opacity:.4"></i>
        <p style="margin-top:14px">Sign in to start an order.</p>
        <button class="btn btn-primary" style="margin-top:14px" onclick="closeCart();openAuthModal('login')">Order Now</button>
      </div>
    <?php else: ?>
      <div class="cart-sidebar-body" id="cartBody"><!-- rendered by JS --></div>
      <div class="cart-sidebar-footer" id="cartFooter">
        <div class="field-label">Pickup Time</div>
        <select class="field-select" id="pickupTime">
          <option value="">Loading available times...</option>
        </select>
        <p id="pickupSlotMessage" class="cart-hint" role="status" style="font-size:12px;color:var(--text-muted);margin:6px 0 0"></p>
        <input class="field-input" id="cartNotes" placeholder="Notes (optional)" style="margin-top:10px">

        <div class="cart-total-row" style="margin-top:16px">
          <span>Total</span><span id="cartTotal">\u20b10.00</span>
        </div>
        <button class="btn btn-primary" id="proceedPaymentButton" style="width:100%" onclick="proceedToPayment()" disabled>Proceed to payment</button>
        <p style="font-size:11.5px;color:var(--text-muted);margin-top:8px;text-align:center">Store hours: <?= e($storeOpen) ?> - <?= e($storeClose) ?></p>
      </div>
    <?php endif; ?>
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

  <!-- ══════════════ AUTH MODAL (item 2 & 6) ══════════════ -->
  <div class="modal-overlay" id="authModal" hidden>
    <div class="modal-box">
      <button class="modal-close" onclick="closeAuthModal()">&times;</button>
      <?php showFlash('global'); ?>
      <div class="auth-tabs">
        <button class="tab-btn active" data-tab="login" onclick="switchAuthTab('login')">Sign In</button>
        <button class="tab-btn" data-tab="register" onclick="switchAuthTab('register')">Register</button>
      </div>

      <form id="loginPanel" class="auth-panel" method="POST" action="<?= APP_URL ?>/login.php">
        <?= csrfField() ?>
        <label>Student / Faculty ID or Email</label>
        <input type="text" name="identifier" required>
        <label>Password</label>
        <input type="password" name="password" required>
        <button type="submit" class="btn btn-primary">Sign In</button>
        <a href="<?= APP_URL ?>/forgot-password.php" class="link-muted">Forgot password?</a>
      </form>

      <form id="registerPanel" class="auth-panel" hidden method="POST" action="<?= APP_URL ?>/register.php">
        <?= csrfField() ?>
        <label>I am a</label>
        <select name="account_type" id="regAccountType">
          <option value="student" <?= ($registerOld['account_type'] ?? '') === 'student' ? 'selected' : '' ?>>Student</option>
          <option value="faculty" <?= ($registerOld['account_type'] ?? '') === 'faculty' ? 'selected' : '' ?>>Faculty</option>
        </select>
        <label>Full Name</label>
        <input type="text" name="full_name" value="<?= e($registerOld['full_name'] ?? '') ?>" required>
        <label id="idLabel">Student ID Number</label>
        <input type="text" name="id_no" value="<?= e($registerOld['id_no'] ?? '') ?>" placeholder="e.g. 2316-00001C" required>
        <div id="courseField">
          <label>Course</label>
          <input type="text" name="course" value="<?= e($registerOld['course'] ?? '') ?>">
        </div>
        <label>Email</label>
        <input type="email" name="email" value="<?= e($registerOld['email'] ?? '') ?>" required>
        <label>Password</label>
        <input type="password" name="password" required>
        <label>Confirm Password</label>
        <input type="password" name="confirm_password" required>
        <div class="checkbox-row">
          <input type="checkbox" name="id_declaration" id="idDecl" required>
          <label for="idDecl">I confirm this ID belongs to me.</label>
        </div>
        <button type="submit" class="btn btn-primary">Create Account</button>
      </form>
    </div>
  </div>

  <!-- ══════════════ CUSTOMIZE MODAL (existing behavior, adapted) ══════════════ -->
  <div class="modal-overlay" id="customModal" hidden>
    <div class="modal-box wide">
      <button class="modal-close" onclick="closeCustomModal()">&times;</button>
      <div id="cmImg" style="height:168px;border-radius:14px;background:var(--surface-raised);display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:14px"></div>
      <h3 id="cmName" style="font-size:21px;margin-bottom:6px"></h3>
      <div id="cmPrice" style="color:var(--primary-color);font-weight:700;margin-bottom:16px"></div>

      <div id="cmSizeBlock" hidden>
        <div class="field-label">Size</div>
        <div class="pay-options" id="cmSizes"></div>
      </div>
      <div id="cmSugarBlock" hidden>
        <div class="field-label">Sugar Level</div>
        <div class="pay-options" id="cmSugars"></div>
      </div>
      <div id="cmAddonBlock" hidden>
        <div class="field-label">Add-ons</div>
        <div id="cmAddons"></div>
      </div>

      <div class="field-label">Note</div>
      <input class="field-input" id="cmNote" placeholder="e.g. no ice, extra hot">

      <div style="display:flex;align-items:center;gap:14px;margin-top:18px">
        <div style="display:flex;align-items:center;gap:10px">
          <button class="qty-btn" onclick="cmChangeQty(-1)">−</button>
          <span id="cmQty" style="min-width:20px;text-align:center;font-weight:700">1</span>
          <button class="qty-btn" onclick="cmChangeQty(1)">+</button>
        </div>
        <button class="btn btn-primary" style="flex:1" onclick="confirmAddToCart()">Add to Cart — <span id="cmTotal"></span></button>
      </div>
    </div>
  </div>

  <!-- ══════════════ ORDER CONFIRMATION MODAL ══════════════ -->
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

  <script>
    const APP_URL = '<?= APP_URL ?>';
    const IS_LOGGED_IN = <?= $loggedIn ? 'true' : 'false' ?>;
    if (!IS_LOGGED_IN) {
      sessionStorage.removeItem('shop_cart');
    }
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
    const SIZES = <?= json_encode(array_map(fn($s) => ['label' => $s['label'], 'adj' => (float)$s['price_adjustment']], $sizeOptions)) ?>;
    const SUGAR_LEVELS = ['Full Sugar', 'Less Sugar', '50% Sugar', 'No Sugar'];
    const AUTO_AUTH_TAB = <?= json_encode($autoAuthTab) ?>;
    const HAS_REGISTER_ERROR = <?= !empty($registerOld) ? 'true' : 'false' ?>;
    const HAS_GLOBAL_FLASH = <?= isset($_SESSION['flash']['global']) ? 'true' : 'false' ?>;

    /* ── Category filter + search ── */
    document.querySelectorAll('.cat-pill').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.cat-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('menuSearch').value = '';
        filterSearch();
      });
    });

    function filterSearch() {
      const term = document.getElementById('menuSearch').value.trim().toLowerCase();
      const selectedCategory = document.querySelector('.cat-pill.active')?.dataset.cat || 'all';
      let anyVisible = false;
      document.querySelectorAll('.menu-card').forEach(card => {
        const categoryMatch = selectedCategory === 'all' || card.dataset.cat === selectedCategory;
        const match = term ? card.dataset.name.includes(term) : categoryMatch;
        card.style.display = match ? '' : 'none';
        if (match) anyVisible = true;
      });
      document.querySelectorAll('.cat-section').forEach(section => {
        const sectionMatches = [...section.querySelectorAll('.menu-card')].some(card => card.style.display !== 'none');
        section.style.display = sectionMatches ? '' : 'none';
      });
      const spotlight = document.querySelector('.spotlight-section');
      if (spotlight) spotlight.style.display = term ? 'none' : '';
      document.getElementById('noResults').style.display = anyVisible ? 'none' : 'block';
    }
    document.getElementById('menuSearch').addEventListener('input', filterSearch);
    if (document.getElementById('menuSearch').value.trim()) filterSearch();

    /* ── Cart, checkout, and toast logic now lives in assets/js/cart-drawer.js
       (shared with orders.php / account.php) — loaded at the end of this
       page. It provides getCart/saveCart/renderCartBadge/openCart/closeCart/
       renderCartSidebar/cartChangeQty/cartRemove/loadPickupSlots/showToast/
       placeOrder/openConfirmModal/closeConfirmModal. ── */

    function onCardClick(id, name, price, imgPath, hasSizes, hasSugar, hasAddons) {
      if (!IS_LOGGED_IN) {
        openAuthModal('login');
        return;
      }
      openCustomModal(id, name, price, imgPath, hasSizes, hasSugar, hasAddons);
    }

    /* ── Customize modal ── */
    let _cm = {
      id: null,
      name: null,
      base: 0,
      hasSizes: false,
      hasSugar: false,
      hasAddons: false,
      addons: [],
      sizeIdx: 0,
      sugarIdx: 0,
      selectedAddons: [],
      qty: 1
    };

    function openCustomModal(id, name, base, imgPath, hasSizes, hasSugar, hasAddons) {
      _cm = {
        id,
        name,
        base,
        hasSizes: !!hasSizes,
        hasSugar: !!hasSugar,
        hasAddons: !!hasAddons,
        addons: [],
        sizeIdx: 0,
        sugarIdx: 0,
        selectedAddons: [],
        qty: 1
      };
      document.getElementById('cmName').textContent = name;
      const imgBox = document.getElementById('cmImg');
      imgBox.innerHTML = imgPath ? `<img src="${APP_URL}/../uploads/products/${imgPath}" style="width:100%;height:100%;object-fit:cover">` : '<i class="fa-solid fa-mug-hot" style="font-size:35px;color:var(--text-placeholder)"></i>';

      document.getElementById('cmSizeBlock').hidden = !hasSizes;
      if (hasSizes) {
        document.getElementById('cmSizes').setAttribute('role', 'radiogroup');
        document.getElementById('cmSizes').innerHTML = SIZES.map((s, i) =>
          `<div class="pay-option ${i===0?'active':''}" data-i="${i}" role="radio" aria-checked="${i===0}" tabindex="0" onclick="cmPick('size',${i})">${s.label}${s.adj?' +\u20b1'+s.adj:''}</div>`
        ).join('');
      }
      document.getElementById('cmSugarBlock').hidden = !hasSugar;
      if (hasSugar) {
        document.getElementById('cmSugars').setAttribute('role', 'radiogroup');
        document.getElementById('cmSugars').innerHTML = SUGAR_LEVELS.map((s, i) =>
          `<div class="pay-option ${i===0?'active':''}" data-i="${i}" role="radio" aria-checked="${i===0}" tabindex="0" onclick="cmPick('sugar',${i})">${s}</div>`
        ).join('');
      }
      document.getElementById('cmAddonBlock').hidden = !hasAddons;
      if (hasAddons) {
        fetch(`${APP_URL}/api/get_product_addons.php?product_id=${id}`)
          .then(r => r.json()).then(data => {
            const list = data.addons || [];
            _cm.addons = list;
            document.getElementById('cmAddons').innerHTML = list.map(a =>
              `<label style="display:flex;align-items:center;gap:8px;padding:6px 0"><input type="checkbox" onchange="cmToggleAddon(${a.id})"> ${a.name} (+\u20b1${a.price})</label>`
            ).join('');
          });
      }
      document.getElementById('cmNote').value = '';
      document.getElementById('cmQty').textContent = '1';
      cmUpdateTotal();
      document.getElementById('customModal').hidden = false;
    }

    function closeCustomModal() {
      document.getElementById('customModal').hidden = true;
    }

    function cmPick(kind, idx) {
      const key = kind === 'size' ? 'sizeIdx' : 'sugarIdx';
      _cm[key] = idx;
      document.getElementById(kind === 'size' ? 'cmSizes' : 'cmSugars').querySelectorAll('.pay-option').forEach((el, i) => {
        el.classList.toggle('active', i === idx);
        el.setAttribute('aria-checked', i === idx ? 'true' : 'false');
      });
      cmUpdateTotal();
    }

    function cmToggleAddon(id) {
      const i = _cm.selectedAddons.indexOf(id);
      if (i === -1) _cm.selectedAddons.push(id);
      else _cm.selectedAddons.splice(i, 1);
      cmUpdateTotal();
    }

    function cmChangeQty(d) {
      _cm.qty = Math.max(1, _cm.qty + d);
      document.getElementById('cmQty').textContent = _cm.qty;
      cmUpdateTotal();
    }

    function cmUnitPrice() {
      let price = _cm.base;
      if (_cm.hasSizes) price += SIZES[_cm.sizeIdx].adj;
      _cm.selectedAddons.forEach(id => {
        const a = _cm.addons.find(x => x.id === id);
        if (a) price += parseFloat(a.price);
      });
      return price;
    }

    function cmUpdateTotal() {
      document.getElementById('cmPrice').textContent = '\u20b1' + cmUnitPrice().toFixed(2) + ' base';
      document.getElementById('cmTotal').textContent = '\u20b1' + (cmUnitPrice() * _cm.qty).toFixed(2);
    }

    function confirmAddToCart() {
      const cart = getCart();
      const addonNames = _cm.selectedAddons.map(id => _cm.addons.find(a => a.id === id)?.name).filter(Boolean);
      cart.push({
        lineId: 'l_' + Date.now() + Math.random().toString(16).slice(2),
        productId: _cm.id,
        name: _cm.name,
        size: _cm.hasSizes ? SIZES[_cm.sizeIdx].label : null,
        sugar: _cm.hasSugar ? SUGAR_LEVELS[_cm.sugarIdx] : null,
        addons: addonNames,
        unit_price: cmUnitPrice(),
        qty: _cm.qty,
        note: document.getElementById('cmNote').value.trim(),
      });
      saveCart(cart);
      closeCustomModal();
      openCart();
    }

    /* ── Cart sidebar / checkout / toasts now live in assets/js/cart-drawer.js
       (shared with orders.php / account.php). This page's only special
       case is that browsing is allowed while signed out, so intercept
       openCart() via the shared hook and prompt sign-in instead. ── */
    window.beforeOpenCart = function() {
      if (!IS_LOGGED_IN) {
        openAuthModal('login');
        return false;
      }
    };

    /* ── Auth modal (item 2 & 6) ── */
    function openAuthModal(tab) {
      document.getElementById('authModal').hidden = false;
      switchAuthTab(tab);
    }

    function closeAuthModal() {
      document.getElementById('authModal').hidden = true;
    }

    function switchAuthTab(tab) {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
      document.getElementById('loginPanel').hidden = tab !== 'login';
      document.getElementById('registerPanel').hidden = tab !== 'register';
    }
    document.getElementById('regAccountType')?.addEventListener('change', e => {
      const isFaculty = e.target.value === 'faculty';
      document.getElementById('idLabel').textContent = isFaculty ? 'Faculty ID Number' : 'Student ID Number';
      document.getElementById('courseField').style.display = isFaculty ? 'none' : '';
    });

    if (AUTO_AUTH_TAB || HAS_REGISTER_ERROR || HAS_GLOBAL_FLASH) {
      openAuthModal(HAS_REGISTER_ERROR ? 'register' : (AUTO_AUTH_TAB || 'login'));
    }

    function toggleUserMenu() {
      document.getElementById('userMenuDropdown').classList.toggle('open');
    }

    window.addEventListener('click', function(e) {
      if (!e.target.closest('.user-menu')) {
        document.getElementById('userMenuDropdown')?.classList.remove('open');
      }
    });
  </script>
  <script src="<?= APP_URL ?>/../assets/js/cart-drawer.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/cart-drawer.js') ?>"></script>
</body>

</html>
