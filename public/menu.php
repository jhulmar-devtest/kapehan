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
require_once __DIR__ . '/../includes/loyalty.php';
require_once __DIR__ . '/../includes/inventory.php';

$role       = currentRole(); // null for guests
$loggedIn   = isLoggedIn() && in_array($role, [ROLE_STUDENT, ROLE_FACULTY], true);

// ---- Products: flat categories, no parent grouping (items 4 & 5) ----
$db = Database::getInstance();
$loyaltyReady = loyaltyLedgerAvailable($db);
$loyaltyRules = loyaltyRules($db);
$loyaltyProductRewards = $loyaltyReady ? loyaltyProductRewards($db) : [];
$minRewardPoints = $loyaltyProductRewards ? min(array_column($loyaltyProductRewards, 'points_required')) : PHP_INT_MAX;
$loyaltyBalance = $loggedIn ? loyaltyPointsBalance($db, $role, (int) currentUserId()) : 0;
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
   WHERE c.name != 'Add-ons'
   GROUP BY p.id, c.name, c.id, c.sort_order
   ORDER BY c.sort_order, p.name"
)->fetchAll();

$stockAvailability = inventoryProductAvailabilityMap($db, array_column($products, 'id'));
foreach ($products as &$product) {
  $product['is_orderable'] = (bool) $product['is_available'] && ($stockAvailability[(int) $product['id']] ?? true);
}
unset($product);

$spotlightProducts = getSpotlightProducts(5);
$orderableById = [];
foreach ($products as $product) $orderableById[(int) $product['id']] = $product['is_orderable'];
$spotlightProducts = array_values(array_filter($spotlightProducts, static fn($product) => array_key_exists((int) $product['id'], $orderableById)));
foreach ($spotlightProducts as &$spotlightProduct) {
  $spotlightProduct['is_orderable'] = $orderableById[(int) $spotlightProduct['id']] ?? false;
}
unset($spotlightProduct);

$soldArr = array_column($products, 'total_sold');
rsort($soldArr);
$bestSellerThreshold = ($soldArr[0] ?? 0) > 0 ? ($soldArr[min(4, count($soldArr) - 1)] ?? 1) : PHP_INT_MAX;

// Flat, leaf-only categories that actually have products — no parent rows rendered (item 5)
$productCategoryIds = array_values(array_unique(array_map(static fn($product) => (int) $product['cat_id'], $products)));
if ($productCategoryIds) {
  $categoryStmt = $db->prepare(
    "SELECT c.* FROM categories c WHERE c.name != 'Add-ons' AND c.id IN (" . implode(',', array_fill(0, count($productCategoryIds), '?')) . ') ORDER BY c.sort_order, c.name'
  );
  $categoryStmt->execute($productCategoryIds);
  $categories = $categoryStmt->fetchAll();
} else {
  $categories = [];
}

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
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/password-toggle.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/password-toggle.css') ?>">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/cart-drawer.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/cart-drawer.css') ?>">
  <script src="<?= APP_URL ?>/../assets/js/password-toggle.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/password-toggle.js') ?>" defer></script>
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
      line-height: 1.5;
    }

    /* ── Shared container width — this is what keeps header/nav/content aligned (item 9) ── */
    .container {
      max-width: 1344px;
      margin: 0 auto;
      padding-inline: 52px;
    }

    /* ── Header ── */
    .site-header {
      position: sticky;
      top: 0;
      z-index: 100;
      background: rgba(243, 240, 236, .94);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border-bottom: 1px solid var(--border-color);
    }

    .header-inner {
      display: flex;
      align-items: center;
      gap: 20px;
      height: 78px;
    }

    .logo {
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 0 0 auto;
    }

    .logo img {
      width: 44px;
      height: 44px;
      display: block;
      object-fit: contain;
      border-radius: 50%;
      background: var(--primary-color);
      box-shadow: var(--shadow-primary);
    }

    .logo-name {
      display: block;
      font-size: .86rem;
      font-weight: 700;
      line-height: 1.2;
      letter-spacing: -.01em;
    }

    .logo-sub {
      display: block;
      color: var(--text-muted);
      font-size: .64rem;
      letter-spacing: .04em;
      margin-top: 2px;
    }

    .header-search {
      display: flex;
      align-items: center;
      gap: 10px;
      background: rgba(255, 255, 255, .78);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-full);
      padding: 11px 18px;
      width: min(390px, 38vw);
      font-size: .9rem;
      transition: border-color var(--transition-fast), box-shadow var(--transition-fast), background var(--transition-fast);
    }

    .header-search:focus-within {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px var(--primary-subtle);
      background: var(--surface-color);
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
      gap: 9px;
      height: 44px;
      max-width: min(230px, 34vw);
      padding: 0 13px;
      border-radius: 999px;
      border: 1px solid var(--border-color);
      background: rgba(255, 255, 255, .78);
      cursor: pointer;
      font: inherit;
      font-size: 22px;
      color: var(--text-secondary);
      transition: background .15s ease, border-color .15s ease, color .15s ease;
    }

    .user-menu-name {
      max-width: 165px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-size: .86rem;
      font-weight: 700;
      color: var(--text-color);
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
      width: 46px;
      height: 46px;
      border-radius: var(--radius-full);
      border: 1px solid var(--border-color);
      background: rgba(255, 255, 255, .78);
      cursor: pointer;
      font-size: 19px;
      color: var(--text-color);
      transition: background var(--transition-fast), border-color var(--transition-fast), transform var(--transition-fast);
    }

    .cart-btn:hover {
      background: var(--surface-color);
      border-color: var(--primary-color);
      transform: translateY(-1px);
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
      background: rgba(243, 240, 236, .72);
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
      padding: 9px 18px;
      border-radius: var(--radius-full);
      border: 1px solid var(--border-color);
      background: var(--surface-color);
      cursor: pointer;
      font: inherit;
      font-weight: 600;
      font-size: .82rem;
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
      margin-bottom: 42px;
      padding: 28px 28px 30px;
      border-radius: var(--radius-xl);
      border: 1px solid var(--border-color);
      background: linear-gradient(135deg, var(--surface-color) 0%, var(--surface-raised) 64%, var(--accent-subtle) 100%);
      position: relative;
      overflow: hidden;
      box-shadow: var(--shadow-sm);
    }

    .spotlight-section::before {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at 96% -30%, var(--primary-subtle) 0%, transparent 44%);
      pointer-events: none;
    }

    .spotlight-heading {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 20px;
      position: relative;
    }

    .spotlight-heading h2 {
      font-family: 'Instrument Serif', serif;
      font-size: clamp(1.8rem, 3vw, 2.35rem);
      font-weight: 400;
      letter-spacing: -0.02em;
      color: var(--text-color);
    }

    .spotlight-heading p {
      font-size: .86rem;
      color: var(--text-secondary);
      margin-top: 3px;
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
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      overflow: hidden;
      cursor: pointer;
      position: relative;
      transition: transform var(--transition-base), box-shadow var(--transition-base), border-color var(--transition-base);
    }

    .spotlight-card.unavailable,
    .menu-card.unavailable {
      cursor: default;
      opacity: .76;
    }

    .spotlight-card.unavailable:hover,
    .menu-card.unavailable:hover {
      transform: none;
      box-shadow: none;
    }

    .unavailable-badge {
      position: absolute;
      left: 10px;
      bottom: 10px;
      z-index: 2;
      padding: 6px 11px;
      border-radius: var(--radius-full);
      background: #342a26;
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      line-height: 1;
      letter-spacing: .02em;
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
      padding-block: 44px 120px;
    }

    .menu-intro {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 32px;
      padding: 22px 0 42px;
    }

    .menu-intro-copy {
      max-width: 760px;
    }

    .menu-eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      color: var(--primary-color);
      font-size: .7rem;
      font-weight: 700;
      letter-spacing: .12em;
      text-transform: uppercase;
      margin-bottom: 12px;
    }

    .menu-eyebrow::before {
      content: '';
      width: 20px;
      height: 1.5px;
      border-radius: var(--radius-xs);
      background: var(--primary-color);
    }

    .menu-intro h1 {
      font-family: 'Instrument Serif', serif;
      font-size: clamp(2.25rem, 4.5vw, 3.7rem);
      font-weight: 400;
      letter-spacing: -.03em;
      line-height: 1.05;
      margin-bottom: 12px;
    }

    .menu-intro h1 em {
      color: var(--primary-color);
      font-weight: 400;
    }

    .menu-intro p {
      max-width: 650px;
      color: var(--text-secondary);
      font-size: .96rem;
      line-height: 1.7;
      font-weight: 300;
    }

    .menu-hours-note {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      flex: 0 0 auto;
      padding: 11px 15px;
      border-radius: var(--radius-full);
      border: 1px solid var(--border-color);
      background: rgba(255, 255, 255, .64);
      color: var(--text-secondary);
      font-size: .76rem;
      font-weight: 600;
      white-space: nowrap;
    }

    .menu-hours-note i {
      color: var(--primary-color);
    }

    .cat-section {
      margin-bottom: 56px;
    }

    .cat-section-title {
      display: flex;
      align-items: center;
      gap: 12px;
      font-family: 'Instrument Serif', serif;
      font-weight: 400;
      font-size: clamp(1.75rem, 2.8vw, 2.1rem);
      letter-spacing: -0.01em;
      margin-bottom: 20px;
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
      gap: 20px;
      align-items: stretch;
    }

    .menu-card {
      display: flex;
      flex-direction: column;
      min-width: 0;
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      overflow: hidden;
      cursor: pointer;
      position: relative;
      transition: transform var(--transition-base), box-shadow var(--transition-base), border-color var(--transition-base);
      box-shadow: var(--shadow-xs);
    }

    .menu-card:hover,
    .menu-card:focus-visible {
      transform: translateY(-3px);
      box-shadow: var(--shadow-md);
      border-color: rgba(192, 57, 43, .3);
      outline: none;
    }

    .menu-card:active {
      transform: scale(.99);
    }

    .menu-card.unavailable {
      cursor: default;
      opacity: .78;
    }

    .menu-card.unavailable:hover,
    .menu-card.unavailable:focus-visible,
    .menu-card.unavailable:active {
      transform: none;
      box-shadow: var(--shadow-xs);
      border-color: var(--border-color);
    }

    .menu-card-img {
      height: clamp(168px, 18vw, 218px);
      background: linear-gradient(145deg, #f6f0e8, #ebe3d8);
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
      transition: transform 420ms cubic-bezier(.2, .7, .2, 1);
    }

    .menu-card:not(.unavailable):hover .menu-card-img img {
      transform: scale(1.045);
    }

    .menu-card-img-icon {
      width: 54px;
      height: 54px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: rgba(255, 255, 255, .62);
      color: var(--secondary-light);
      font-size: 24px;
      box-shadow: var(--shadow-xs);
    }

    .best-seller-badge {
      position: absolute;
      top: 10px;
      left: 10px;
      background: var(--accent-color);
      color: var(--text-on-accent);
      font-size: 11px;
      font-weight: 700;
      padding: 6px 10px;
      border-radius: var(--radius-full);
      box-shadow: var(--shadow-xs);
      letter-spacing: .01em;
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
      flex: 1;
      min-height: 104px;
      padding: 15px 62px 16px 16px;
    }

    .menu-card-name {
      display: -webkit-box;
      min-height: 2.6em;
      overflow: hidden;
      -webkit-box-orient: vertical;
      -webkit-line-clamp: 2;
      line-clamp: 2;
      font-weight: 650;
      font-size: .96rem;
      line-height: 1.3;
      margin-bottom: 9px;
      letter-spacing: -.01em;
    }

    .menu-card-meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
      min-height: 23px;
    }

    .menu-card-price {
      font-weight: 700;
      color: var(--primary-color);
      font-size: 1rem;
      letter-spacing: -.01em;
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
      bottom: 15px;
      right: 15px;
      width: 38px;
      height: 38px;
      border-radius: var(--radius-full);
      background: var(--primary-color);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
      box-shadow: var(--shadow-primary);
      border: 2px solid var(--surface-color);
      transition: transform var(--transition-fast), background var(--transition-fast), box-shadow var(--transition-fast);
    }

    .menu-card:not(.unavailable):hover .menu-card-add {
      background: var(--primary-dark);
      transform: scale(1.06);
      box-shadow: 0 5px 16px rgba(192, 57, 43, .34);
    }

    .menu-card.unavailable .menu-card-add {
      display: none;
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
      padding-block: 26px;
      background: rgba(250, 248, 245, .7);
    }

    .footer-inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      font-size: 13.5px;
      color: var(--text-muted);
      min-height: 34px;
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

    .auth-modal-box {
      max-width: 470px;
      max-height: min(90vh, 820px);
      padding: 28px;
      border: 1px solid rgba(224, 219, 212, .9);
      box-shadow: var(--shadow-xl);
    }

    .product-modal {
      max-width: 520px;
      padding: 20px;
      border: 1px solid rgba(224, 219, 212, .9);
      box-shadow: var(--shadow-xl);
    }

    .product-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }

    .product-modal-kicker {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--text-secondary);
      font-size: .76rem;
      font-weight: 700;
    }

    .product-modal-kicker i {
      color: var(--primary-color);
    }

    .product-modal .product-modal-close {
      position: static;
      flex: 0 0 auto;
      display: grid;
      place-items: center;
      width: 38px;
      height: 38px;
      border: 1px solid var(--border-color);
      border-radius: 50%;
      background: var(--surface-raised);
      color: var(--text-secondary);
      font-size: 21px;
      line-height: 1;
      transition: background var(--transition-fast), border-color var(--transition-fast), color var(--transition-fast), transform var(--transition-fast);
    }

    .product-modal .product-modal-close:hover {
      transform: rotate(90deg);
      border-color: var(--primary-color);
      background: var(--primary-subtle);
      color: var(--primary-color);
    }

    .product-modal .product-modal-close:focus-visible {
      outline: 2px solid var(--primary-color);
      outline-offset: 2px;
    }

    @media (max-width: 600px) {
      .product-modal {
        padding: 16px;
      }
    }

    .auth-heading {
      display: grid;
      grid-template-columns: 48px minmax(0, 1fr);
      align-items: center;
      gap: 13px;
      padding: 2px 28px 20px 0;
    }

    .auth-brand-mark {
      width: 46px;
      height: 46px;
      display: grid;
      place-items: center;
      overflow: hidden;
      border: 1px solid var(--border-color);
      border-radius: 50%;
      background: var(--surface-color);
      box-shadow: var(--shadow-sm);
    }

    .auth-brand-mark img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .auth-eyebrow {
      color: var(--primary-color);
      font-size: .64rem;
      font-weight: 800;
      letter-spacing: .11em;
      line-height: 1.3;
      text-transform: uppercase;
    }

    .auth-heading h2 {
      margin-top: 2px;
      font-family: 'Instrument Serif', serif;
      font-size: 1.8rem;
      font-weight: 400;
      letter-spacing: -.02em;
      line-height: 1.05;
    }

    .auth-heading p {
      margin-top: 4px;
      color: var(--text-muted);
      font-size: .75rem;
      line-height: 1.4;
    }

    .tab-btn {
      flex: 1;
      min-height: 40px;
      padding: 9px 12px;
      border: none;
      background: none;
      border-radius: var(--radius-sm);
      font: inherit;
      font-size: .85rem;
      font-weight: 700;
      cursor: pointer;
      color: var(--text-muted);
      transition: color var(--transition-fast), background var(--transition-fast), box-shadow var(--transition-fast);
    }

    .tab-btn.active {
      background: var(--surface-color);
      color: var(--text-color);
      box-shadow: var(--shadow-xs);
    }

    .auth-panel label {
      display: block;
      font-size: .76rem;
      font-weight: 700;
      margin: 13px 0 6px;
      color: var(--text-secondary);
    }

    .auth-panel input,
    .auth-panel select {
      width: 100%;
      min-height: 46px;
      padding: 10px 13px;
      border: 1px solid var(--border-color);
      border-radius: 10px;
      background: var(--surface-color);
      color: var(--text-color);
      font: inherit;
      font-size: 16px;
      transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    }

    .auth-panel input:focus,
    .auth-panel select:focus {
      border-color: var(--primary-color);
      outline: none;
      box-shadow: 0 0 0 3px var(--primary-subtle);
    }

    .auth-panel input::placeholder {
      color: var(--text-placeholder);
    }

    .auth-panel .btn-primary {
      width: 100%;
      min-height: 48px;
      margin-top: 20px;
      padding: 12px 16px;
      border-radius: 10px;
      font-size: .92rem;
      box-shadow: var(--shadow-primary);
    }

    .link-muted {
      display: block;
      text-align: center;
      margin-top: 15px;
      font-size: .78rem;
      font-weight: 600;
      color: var(--text-secondary);
      text-decoration: underline;
      text-underline-offset: 3px;
    }

    .checkbox-row {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      margin-top: 16px;
      padding: 11px 12px;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      background: var(--surface-raised);
      align-items: center;
    }

    .checkbox-row input {
      width: auto;
    }

    .checkbox-row label {
      margin: 0;
      font-weight: 400;
      font-size: .75rem;
      line-height: 1.45;
    }

    .checkbox-row input {
      flex: 0 0 auto;
      width: 17px;
      height: 17px;
      margin-top: 1px;
      accent-color: var(--primary-color);
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
        min-width: 0;
      }

      .logo-name {
        font-size: .8rem;
      }

      .logo {
        order: 1;
      }

      .header-actions {
        order: 2;
        gap: 8px;
      }

      .user-menu-name {
        display: none;
      }

      .user-menu-btn {
        width: 44px;
        min-width: 44px;
        padding: 0;
        border-radius: 50%;
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

      .menu-intro {
        align-items: flex-start;
        flex-direction: column;
        gap: 16px;
        padding: 12px 0 28px;
      }

      .menu-intro h1 {
        font-size: clamp(2.2rem, 9vw, 3rem);
      }

      .menu-intro p {
        font-size: .9rem;
      }

      .menu-hours-note {
        font-size: .72rem;
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
        height: clamp(112px, 30vw, 156px);
      }

      .menu-card-body {
        min-height: 96px;
        padding: 12px 45px 12px 12px;
      }

      .menu-card-name {
        font-size: .88rem;
        line-height: 1.3;
      }

      .menu-card-price {
        font-size: .9rem;
      }

      .menu-card-add {
        width: 32px;
        height: 32px;
        right: 8px;
        bottom: 8px;
      }

      .auth-modal-box {
        max-height: 88vh;
        padding: 22px 18px;
      }

      .auth-heading {
        grid-template-columns: 42px minmax(0, 1fr);
        gap: 10px;
        padding-bottom: 16px;
      }

      .auth-brand-mark {
        width: 40px;
        height: 40px;
      }

      .auth-heading h2 {
        font-size: 1.6rem;
      }

      .auth-tabs {
        margin-bottom: 15px;
      }

      .auth-panel label {
        margin-top: 10px;
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
      <a href="<?= APP_URL ?>/landing.php" class="logo">
        <img src="<?= APP_URL ?>/../assets/images/logo.png" alt="<?= APP_NAME ?>" onerror="this.style.display='none'">
        <span>
          <span class="logo-name"><?= APP_NAME ?></span>
          <span class="logo-sub">EARIST Cavite Campus</span>
        </span>
      </a>

      <form class="header-search" role="search" action="<?= APP_URL ?>/menu.php" method="GET">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" id="menuSearch" name="q" aria-label="Search the menu" placeholder="Search the menu..." value="<?= e($_GET['q'] ?? '') ?>">
      </form>

      <div class="header-actions">
        <?php if ($loggedIn): ?>
          <div class="user-menu">
            <button class="user-menu-btn" onclick="toggleUserMenu()" aria-label="Account menu for <?= e($_SESSION['full_name'] ?? 'User') ?>" aria-haspopup="true" aria-controls="userMenuDropdown">
              <i class="fa-solid fa-circle-user" style="font-size: 22px; flex:none"></i>
              <span class="user-menu-name"><?= e($_SESSION['full_name'] ?? 'Account') ?></span>
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

    <section class="menu-intro" aria-labelledby="menuTitle">
      <div class="menu-intro-copy">
        <div class="menu-eyebrow">EARIST Cavite Campus · Made for your day</div>
        <h1 id="menuTitle">Find your next <em>campus favorite.</em></h1>
        <p>Browse today’s drinks and snacks, customize your order, and choose an available pickup time. We’ll keep you updated as it’s prepared.</p>
      </div>
      <div class="menu-hours-note"><i class="fa-regular fa-clock"></i> Today’s shop hours <?= e(date('g:i A', strtotime($storeOpen))) ?>–<?= e(date('g:i A', strtotime($storeClose))) ?></div>
    </section>

    <?php if (!empty($spotlightProducts)): ?>
      <div class="spotlight-section">
        <div class="spotlight-heading">
          <div>
            <h2>Today's Picks</h2>
            <p>Handpicked favorites and popular orders</p>
          </div>
        </div>
        <div class="spotlight-scroll" id="spotlightScroll">
          <?php foreach ($spotlightProducts as $i => $p): ?>
            <div class="spotlight-card<?= empty($p['is_orderable']) ? ' unavailable' : '' ?>"
              role="button"
              tabindex="<?= empty($p['is_orderable']) ? '-1' : '0' ?>"
              aria-disabled="<?= empty($p['is_orderable']) ? 'true' : 'false' ?>"
              aria-label="<?= e($p['name']) ?><?= empty($p['is_orderable']) ? ', unavailable' : ', ' . e(peso($p['price'])) ?>"
              onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
              onclick="if(<?= empty($p['is_orderable']) ? 'false' : 'true' ?>) onCardClick(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name'])) ?>, <?= (float)$p['price'] ?>, <?= htmlspecialchars(json_encode($p['image_path'] ?? '')) ?>, <?= (int)$p['has_sizes'] ?>, <?= (int)$p['has_sugar'] ?>, <?= (int)$p['has_addons'] ?>)">
              <div class="spotlight-card-img">
                <span class="spotlight-rank"><?= $i + 1 ?></span>
                <?php if (!empty($p['image_path']) && file_exists(UPLOAD_DIR . $p['image_path'])): ?>
                  <img src="<?= $imgBase . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                <?php else: ?>
                  <span class="spotlight-card-img-icon"><i class="fa-solid fa-mug-hot"></i></span>
                <?php endif; ?>
                <?php if (empty($p['is_orderable'])): ?><span class="unavailable-badge">Unavailable</span><?php endif; ?>
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
              <div class="menu-card<?= empty($p['is_orderable']) ? ' unavailable' : '' ?>"
                id="card-<?= $p['id'] ?>"
                data-cat="<?= $p['cat_id'] ?>"
                data-name="<?= strtolower(e($p['name'])) ?>"
                aria-disabled="<?= empty($p['is_orderable']) ? 'true' : 'false' ?>"
                role="button"
                tabindex="<?= empty($p['is_orderable']) ? '-1' : '0' ?>"
                aria-label="<?= e($p['name']) ?><?= empty($p['is_orderable']) ? ', unavailable' : ', ' . e(peso($p['price'])) ?>"
                onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                onclick="if(<?= empty($p['is_orderable']) ? 'false' : 'true' ?>) onCardClick(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name'])) ?>, <?= (float)$p['price'] ?>, <?= htmlspecialchars(json_encode($p['image_path'] ?? '')) ?>, <?= (int)$p['has_sizes'] ?>, <?= (int)$p['has_sugar'] ?>, <?= (int)$p['has_addons'] ?>)">
                <div class="menu-card-img">
                  <?php if (!empty($p['image_path']) && file_exists(UPLOAD_DIR . $p['image_path'])): ?>
                    <img src="<?= $imgBase . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                  <?php else: ?>
                    <span class="menu-card-img-icon"><i class="fa-solid fa-mug-hot"></i></span>
                  <?php endif; ?>
                  <?php if (empty($p['is_orderable'])): ?><span class="unavailable-badge">Unavailable</span><?php endif; ?>
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
        <?php if ($role === ROLE_FACULTY): ?>
          <label class="field-label" for="deliveryLocation" style="display:block;margin-top:10px">Delivery location <span aria-hidden="true">*</span></label>
          <input class="field-input" id="deliveryLocation" maxlength="255" placeholder="Building, office, or room" required>
        <?php endif; ?>

        <div class="loyalty-cart-option">
          <label><input type="checkbox" id="redeemLoyaltyCheckbox" <?= $loyaltyReady && $loyaltyProductRewards && $loyaltyBalance >= $minRewardPoints ? '' : 'disabled' ?>> Redeem points</label>
          <span id="loyaltyCartHint"><?= $loyaltyReady ? number_format($loyaltyBalance) . ' points available · choose a reward drink in your cart' : 'Points will be available after the loyalty database migration is applied.' ?></span>
        </div>
        <label for="loyaltyRewardProduct" class="cart-hint" style="display:block;margin-top:8px">Reward drink in this order</label>
        <select id="loyaltyRewardProduct" class="field-select" disabled><option value="">Add an eligible reward drink to your cart</option></select>
        <div class="cart-total-row loyalty-subtotal-row" id="cartSubtotalRow" style="margin-top:12px">
          <span>Subtotal</span><span id="cartSubtotal">₱0.00</span>
        </div>
        <div class="cart-total-row loyalty-discount-row" id="cartLoyaltyDiscountRow" hidden>
          <span>Loyalty discount</span><span id="cartLoyaltyDiscount">−₱0.00</span>
        </div>

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
        <div class="confirm-row" id="paymentLoyaltyRow" hidden><span>Points discount</span><span id="paymentLoyaltyDiscount"></span></div>
      </div>
      <?php if (gcashPaymentNumber() === '' || gcashPaymentName() === ''): ?>
        <p class="cart-hint" style="color:var(--danger-color,#b42318);font-size:13px">The shop's GCash recipient name and number need to be set by an admin (Admin &rarr; Settings) before customers can pay.</p>
      <?php endif; ?>
      <label class="field-label" for="refNo">GCash reference number</label>
      <input class="field-input" id="refNo" inputmode="numeric" autocomplete="off" placeholder="<?= (int) GCASH_REF_DIGITS ?>-digit reference number" maxlength="20">
      <div class="confirm-actions" style="margin-top:18px">
        <button class="btn btn-ghost" onclick="closePaymentModal()">Back to cart</button>
        <button class="btn btn-primary" id="submitPaymentButton" onclick="submitOrder()" <?= gcashPaymentNumber() !== '' && gcashPaymentName() !== '' ? '' : 'disabled' ?>>Submit order</button>
      </div>
    </div>
  </div>

  <!-- ══════════════ AUTH MODAL (item 2 & 6) ══════════════ -->
  <div class="modal-overlay" id="authModal" hidden>
    <div class="modal-box auth-modal-box" role="dialog" aria-modal="true" aria-labelledby="authModalTitle" aria-describedby="authModalIntro">
      <button class="modal-close" onclick="closeAuthModal()" aria-label="Close sign in or registration">&times;</button>
      <?php showFlash('global'); ?>
      <div class="auth-heading">
        <div class="auth-brand-mark"><img src="<?= APP_URL ?>/../assets/images/logo.png" alt="Kapehan ni Amang logo"></div>
        <div>
          <div class="auth-eyebrow">Kapehan ni Amang</div>
          <h2 id="authModalTitle">Welcome back</h2>
          <p id="authModalIntro">Sign in to order your campus favorites.</p>
        </div>
      </div>
      <div class="auth-tabs">
        <button class="tab-btn active" data-tab="login" onclick="switchAuthTab('login')">Sign In</button>
        <button class="tab-btn" data-tab="register" onclick="switchAuthTab('register')">Register</button>
      </div>

      <form id="loginPanel" class="auth-panel" method="POST" action="<?= APP_URL ?>/login.php">
        <?= csrfField() ?>
        <label for="loginIdentifier">Student / Faculty ID or email</label>
        <input id="loginIdentifier" type="text" name="identifier" autocomplete="username" placeholder="Enter your ID or email" required>
        <label for="loginPassword">Password</label>
        <input id="loginPassword" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
        <button type="submit" class="btn btn-primary">Sign in</button>
        <a href="<?= APP_URL ?>/forgot-password.php" class="link-muted">Forgot password?</a>
        <a href="<?= APP_URL ?>/report-id-conflict.php" class="link-muted">Having trouble because your ID is already in use?</a>
      </form>

      <form id="registerPanel" class="auth-panel" hidden method="POST" action="<?= APP_URL ?>/register.php">
        <?= csrfField() ?>
        <label for="regFullName">Full name</label>
        <input id="regFullName" type="text" name="full_name" value="<?= e($registerOld['full_name'] ?? '') ?>" autocomplete="name" placeholder="Enter your full name" required>
        <label id="idLabel" for="regIdNumber">Student / Faculty ID number</label>
        <input id="regIdNumber" type="text" name="id_no" value="<?= e($registerOld['id_no'] ?? '') ?>" placeholder="e.g. 2316-00001C or 2023-0001" required>
        <a href="<?= APP_URL ?>/report-id-conflict.php" class="link-muted">Can’t register because this ID is already used? Report an ID conflict.</a>
        <label for="regEmail">Email</label>
        <input id="regEmail" type="email" name="email" value="<?= e($registerOld['email'] ?? '') ?>" autocomplete="email" placeholder="you@example.com" required>
        <label for="regPassword">Password</label>
        <input id="regPassword" type="password" name="password" autocomplete="new-password" placeholder="8+ chars, upper/lowercase, number, symbol" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[\p{P}\p{S}]).{8,}" title="Use at least 8 characters with a lowercase letter, uppercase letter, number, and special character." required>
        <label for="regConfirmPassword">Confirm password</label>
        <input id="regConfirmPassword" type="password" name="confirm_password" autocomplete="new-password" placeholder="Enter your password again" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[\p{P}\p{S}]).{8,}" title="Use at least 8 characters with a lowercase letter, uppercase letter, number, and special character." required>
        <div class="checkbox-row">
          <input type="checkbox" name="id_declaration" id="idDecl" required>
          <label for="idDecl">I confirm this ID belongs to me.</label>
        </div>
        <div class="checkbox-row" style="align-items:flex-start">
          <input type="checkbox" name="privacy_notice_accepted" id="privacyNoticeAccepted" required>
          <label for="privacyNoticeAccepted">I understand that Kapehan ni Amang uses my name, school ID, and email to verify my account, process and update me about orders, manage loyalty points, and resolve account access problems. Authorized staff can access this information for these purposes.</label>
        </div>
        <button type="submit" class="btn btn-primary">Create Account</button>
      </form>
    </div>
  </div>

  <!-- ══════════════ CUSTOMIZE MODAL (existing behavior, adapted) ══════════════ -->
  <div class="modal-overlay" id="customModal" hidden>
    <div class="modal-box wide product-modal" role="dialog" aria-modal="true" aria-labelledby="cmName">
      <div class="product-modal-head">
        <span class="product-modal-kicker"><i class="fa-solid fa-mug-hot" aria-hidden="true"></i> Customize your drink</span>
        <button type="button" class="modal-close product-modal-close" onclick="closeCustomModal()" aria-label="Close product options">&times;</button>
      </div>
      <div id="cmImg" style="height:168px;border-radius:14px;background:var(--surface-raised);display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:14px"></div>
      <h3 id="cmName" style="font-size:21px;margin-bottom:6px"></h3>
      <div id="cmPrice" style="color:var(--primary-color);font-weight:700;margin-bottom:16px"></div>
      <div id="cmStockInfo" role="status" aria-live="polite" hidden style="padding:10px 12px;margin-bottom:14px;border-radius:10px;font-size:13px;font-weight:600"></div>

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
          <button class="qty-btn" id="cmQtyMinus" onclick="cmChangeQty(-1)">−</button>
          <span id="cmQty" style="min-width:20px;text-align:center;font-weight:700">1</span>
          <button class="qty-btn" id="cmQtyPlus" onclick="cmChangeQty(1)">+</button>
        </div>
        <button class="btn btn-primary" id="cmAddButton" style="flex:1" onclick="confirmAddToCart()">Add to Cart — <span id="cmTotal"></span></button>
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
        <div class="confirm-row" id="confPointsRedeemedRow" hidden><span>Points redeemed</span><span id="confPointsRedeemed"></span></div>
        <div class="confirm-row" id="confPointsEarnedRow" hidden><span>Points after pickup</span><span id="confPointsEarned"></span></div>
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
    window.LOYALTY_BALANCE = <?= (int) $loyaltyBalance ?>;
    window.GCASH_REF_DIGITS = <?= (int) GCASH_REF_DIGITS ?>;
    window.PAYMONGO_ENABLED = <?= paymongoEnabled() ? 'true' : 'false' ?>;
    window.LOYALTY_PRODUCT_REWARDS = <?= json_encode($loyaltyProductRewards, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.LOYALTY_READY = <?= $loyaltyReady ? 'true' : 'false' ?>;
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
    let _cmStockRequest = 0;
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
      qty: 1,
      stockEstimate: null,
      stockLoading: false,
      stockRequest: 0,
      maxToAdd: null
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
        qty: 1,
        stockEstimate: null,
        stockLoading: true,
        stockRequest: 0,
        maxToAdd: null
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
            if (Number(_cm.id) !== Number(id)) return;
            const list = data.addons || [];
            _cm.addons = list;
            document.getElementById('cmAddons').innerHTML = list.map(a =>
              `<label style="display:flex;align-items:center;gap:8px;padding:6px 0"><input type="checkbox" onchange="cmToggleAddon(${a.id})"> ${a.name} (+\u20b1${a.price})</label>`
            ).join('');
            cmUpdateStockEstimate();
          });
      }
      document.getElementById('cmNote').value = '';
      document.getElementById('cmQty').textContent = '1';
      cmUpdateTotal();
      document.getElementById('customModal').hidden = false;
      cmUpdateStockEstimate();
    }

    function closeCustomModal() {
      document.getElementById('customModal').hidden = true;
    }

    document.getElementById('customModal').addEventListener('click', function(event) {
      if (event.target === this) closeCustomModal();
    });

    function cmPick(kind, idx) {
      const key = kind === 'size' ? 'sizeIdx' : 'sugarIdx';
      _cm[key] = idx;
      document.getElementById(kind === 'size' ? 'cmSizes' : 'cmSugars').querySelectorAll('.pay-option').forEach((el, i) => {
        el.classList.toggle('active', i === idx);
        el.setAttribute('aria-checked', i === idx ? 'true' : 'false');
      });
      cmUpdateTotal();
      if (kind === 'size') cmUpdateStockEstimate();
    }

    function cmToggleAddon(id) {
      const i = _cm.selectedAddons.indexOf(id);
      if (i === -1) _cm.selectedAddons.push(id);
      else _cm.selectedAddons.splice(i, 1);
      cmUpdateTotal();
      cmUpdateStockEstimate();
    }

    function cmChangeQty(d) {
      const max = _cm.maxToAdd === null ? 99 : Math.min(99, _cm.maxToAdd);
      _cm.qty = Math.max(1, Math.min(max || 1, _cm.qty + d));
      document.getElementById('cmQty').textContent = _cm.qty;
      cmUpdateTotal();
    }

    async function cmUpdateStockEstimate() {
      const box = document.getElementById('cmStockInfo');
      const addButton = document.getElementById('cmAddButton');
      if (!_cm.id || !box || !addButton) return;
      const requestId = ++_cmStockRequest;
      _cm.stockRequest = requestId;
      _cm.stockLoading = true;
      _cm.stockEstimate = null;
      _cm.maxToAdd = null;
      box.hidden = false;
      box.style.background = 'var(--surface-raised)';
      box.style.color = 'var(--text-muted)';
      box.textContent = 'Checking available quantity…';
      addButton.disabled = true;
      const params = new URLSearchParams({
        product_id: _cm.id
      });
      if (_cm.hasSizes) params.set('size_label', SIZES[_cm.sizeIdx].label);
      _cm.selectedAddons.forEach(id => params.append('addon_ids[]', id));
      try {
        const response = await fetch(`${APP_URL}/api/product-stock-estimate.php?${params.toString()}`);
        const result = await response.json();
        if (requestId !== _cmStockRequest) return;
        if (!response.ok || !result.success) throw new Error(result.error || 'Stock estimate unavailable.');
        _cm.stockLoading = false;
        _cm.stockEstimate = result;
        if (!result.tracked) {
          box.hidden = true;
          _cm.maxToAdd = null;
        } else {
          const cart = getCart();
          const currentQty = cart.filter(item => Number(item.productId) === Number(_cm.id)).reduce((sum, item) => sum + Number(item.qty || 0), 0);
          _cm.maxToAdd = Math.max(0, Number(result.max_quantity || 0) - currentQty);
          cart.filter(item => Number(item.productId) === Number(_cm.id)).forEach(item => {
            item.stockMax = Number(result.max_quantity || 0);
          });
          if (cart.length) saveCart(cart);
          box.hidden = false;
          if (result.max_quantity < 1) {
            box.style.background = 'var(--status-cancelled-bg, #fce8e6)';
            box.style.color = 'var(--status-cancelled, #b42318)';
            box.textContent = 'Unavailable — estimated quantity: 0';
          } else if (result.low_stock) {
            box.style.background = 'var(--status-warning-bg, #fff4d6)';
            box.style.color = 'var(--status-warning, #8a5a00)';
            box.textContent = `Low stock — estimated quantity available: ${result.max_quantity}${currentQty ? ` total; ${_cm.maxToAdd} more can be added` : ''}`;
          } else {
            box.style.background = 'var(--surface-raised)';
            box.style.color = 'var(--text-muted)';
            box.textContent = `Estimated quantity available: ${result.max_quantity}${currentQty ? ` total; ${_cm.maxToAdd} more can be added` : ''}`;
          }
        }
        _cm.qty = Math.max(1, Math.min(_cm.qty, _cm.maxToAdd === null ? 99 : Math.max(1, _cm.maxToAdd)));
        document.getElementById('cmQty').textContent = _cm.qty;
        cmUpdateTotal();
      } catch (error) {
        if (requestId !== _cmStockRequest) return;
        _cm.stockLoading = false;
        _cm.maxToAdd = 0;
        box.hidden = false;
        box.style.background = 'var(--status-cancelled-bg, #fce8e6)';
        box.style.color = 'var(--status-cancelled, #b42318)';
        box.textContent = error.message;
        cmUpdateTotal();
      }
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
      const max = _cm.maxToAdd;
      document.getElementById('cmQtyPlus').disabled = _cm.stockLoading || (max !== null && _cm.qty >= max);
      document.getElementById('cmQtyMinus').disabled = _cm.qty <= 1;
      document.getElementById('cmAddButton').disabled = _cm.stockLoading || max === 0 || (max !== null && _cm.qty > max);
    }

    function confirmAddToCart() {
      if (_cm.stockLoading || _cm.maxToAdd === 0 || (_cm.maxToAdd !== null && _cm.qty > _cm.maxToAdd)) {
        showToast('warning', 'The selected product quantity exceeds the estimated stock available.');
        return;
      }
      const cart = getCart();
      const addonNames = _cm.selectedAddons.map(id => _cm.addons.find(a => a.id === id)?.name).filter(Boolean);
      cart.push({
        lineId: 'l_' + Date.now() + Math.random().toString(16).slice(2),
        productId: _cm.id,
        name: _cm.name,
        size: _cm.hasSizes ? SIZES[_cm.sizeIdx].label : null,
        sugar: _cm.hasSugar ? SUGAR_LEVELS[_cm.sugarIdx] : null,
        addons: addonNames,
        addon_ids: [..._cm.selectedAddons],
        unit_price: cmUnitPrice(),
        qty: _cm.qty,
        stockMax: _cm.stockEstimate?.tracked ? Number(_cm.stockEstimate.max_quantity) : null,
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
      document.getElementById('authModalTitle').textContent = tab === 'register' ? 'Create your account' : 'Welcome back';
      document.getElementById('authModalIntro').textContent = tab === 'register' ?
        'Join the campus coffee community and order ahead.' :
        'Sign in to order your campus favorites.';
    }

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
