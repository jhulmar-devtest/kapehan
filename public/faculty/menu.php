<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_FACULTY);
$db = Database::getInstance();

$products = $db->query(
  "SELECT p.*, c.name AS cat_name, c.id AS cat_id, c.parent_id AS cat_parent,
            cp.name AS group_name, cp.id AS group_id,
            COALESCE(ROUND(AVG(pr.rating),1), 0) AS avg_rating,
            COUNT(DISTINCT pr.id) AS rating_count,
            COALESCE(SUM(od.quantity), 0) AS total_sold,
            CASE WHEN EXISTS (
                SELECT 1 FROM product_addons pa
                JOIN addons a ON pa.addon_id = a.id
                WHERE pa.product_id = p.id AND a.status = 'active'
            ) THEN 1 ELSE 0 END AS has_addons
     FROM products p
     JOIN categories c  ON p.category_id = c.id
     LEFT JOIN categories cp ON c.parent_id = cp.id
     LEFT JOIN product_ratings pr ON pr.product_id = p.id
     LEFT JOIN order_details od   ON od.product_id  = p.id
     WHERE c.name != 'Add-ons'
     GROUP BY p.id, c.name, c.id, c.parent_id, cp.name, cp.id, c.sort_order
     ORDER BY c.sort_order, p.name"
)->fetchAll();

$soldArr2 = array_column($products, 'total_sold');
rsort($soldArr2);
$bestSellerThreshold = ($soldArr2[0] ?? 0) > 0 ? ($soldArr2[min(4, count($soldArr2) - 1)] ?? 1) : PHP_INT_MAX;

$allCats      = $db->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();
$catsByParent = [];
foreach ($allCats as $cat) {
  if ($cat['parent_id']) $catsByParent[$cat['parent_id']][] = $cat;
}
$catGroups     = array_filter($allCats, fn($c) => $c['parent_id'] === null && $c['name'] !== 'Add-ons' && !empty($catsByParent[$c['id']]));
$catStandalone = array_filter($allCats, fn($c) => $c['parent_id'] === null && $c['name'] !== 'Add-ons' && empty($catsByParent[$c['id']]));
$catLeaves     = array_filter($allCats, fn($c) => $c['parent_id'] !== null && $c['name'] !== 'Add-ons');
$imgBase       = APP_URL . '/../uploads/products/';
layoutHeader('Order Now', '');
?>
<style>
  .menu-wrap {
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
  }

  .menu-controls {
    position: sticky;
    top: var(--header-h);
    z-index: 40;
    background: var(--surface-color);
    border-bottom: 1px solid var(--border-color);
    margin: calc(-1 * var(--space-4)) calc(-1 * var(--space-4)) 0;
    padding: var(--space-4);
    box-shadow: var(--shadow-sm);
    border-radius: var(--radius-sm);
  }

  .menu-nav-row {
    display: flex;
    align-items: center;
    gap: var(--space-4);
  }

  .cat-pills {
    display: flex;
    gap: var(--space-2);
    overflow-x: auto;
    flex-wrap: nowrap;
    scrollbar-width: none;
    flex: 1;
    min-width: 0;
    padding: var(--space-1) 0;
  }

  .cat-pills::-webkit-scrollbar {
    display: none
  }

  .cat-pill {
    height: 36px;
    padding: 0 var(--space-4);
    border-radius: var(--radius-full);
    font-size: 0.82rem;
    font-weight: 600;
    border: 1.5px solid var(--border-color);
    background: var(--surface-color);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all var(--transition-fast);
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    font-family: inherit;
    white-space: nowrap;
    flex-shrink: 0;
  }

  .cat-pill:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: var(--primary-subtle);
  }

  .cat-pill.active {
    background: var(--primary-color);
    color: var(--text-on-primary);
    border-color: var(--primary-color);
    box-shadow: var(--shadow-primary);
  }

  .cat-pill .pill-count {
    font-size: 0.65rem;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.25);
    padding: 2px 7px;
    border-radius: var(--radius-full);
    line-height: 1.2;
  }

  .cat-pill:not(.active) .pill-count {
    background: var(--surface-sunken);
    color: var(--text-muted);
  }

  .cat-pill[data-type="group"] {
    padding-right: var(--space-3);
  }

  .cat-pill[data-type="group"] .pill-chevron {
    font-size: 0.7rem;
    margin-left: 2px;
    transition: transform var(--transition-fast);
  }

  .cat-pill[data-type="group"].active .pill-chevron {
    transform: rotate(180deg);
  }

  .menu-search-wrap {
    position: relative;
    flex-shrink: 0;
    width: 220px;
  }

  .menu-search-wrap i {
    position: absolute;
    left: var(--space-3);
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 0.8rem;
    pointer-events: none;
  }

  .menu-search {
    width: 100%;
    height: 36px;
    padding: 0 var(--space-3) 0 36px;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-full);
    font-size: 0.82rem;
    font-family: inherit;
    color: var(--text-color);
    background: var(--surface-color);
    outline: none;
    transition: all var(--transition-fast);
  }

  .menu-search:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px var(--primary-subtle);
    background: var(--surface-raised);
  }

  .menu-search::placeholder {
    color: var(--text-placeholder);
  }

  .cat-subpills {
    display: none;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-top: var(--space-3);
    padding-top: var(--space-3);
    border-top: 1px dashed var(--border-color);
    animation: slideDown 0.2s ease;
  }

  .cat-subpills.open {
    display: flex;
  }

  @keyframes slideDown {
    from {
      opacity: 0;
      transform: translateY(-8px)
    }

    to {
      opacity: 1;
      transform: translateY(0)
    }
  }

  .cat-subpill-group {
    display: none;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .cat-subpill-group.active {
    display: flex;
  }

  .cat-subpill {
    height: 30px;
    padding: 0 var(--space-3);
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
    border: 1px solid var(--border-color);
    background: var(--surface-raised);
    color: var(--text-muted);
    cursor: pointer;
    transition: all var(--transition-fast);
    font-family: inherit;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
  }

  .cat-subpill:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: var(--primary-subtle);
  }

  .cat-subpill.active {
    background: var(--primary-color);
    color: var(--text-on-primary);
    border-color: var(--primary-color);
  }

  .cat-subpill .pill-count {
    font-size: 0.6rem;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.25);
    padding: 1px 5px;
    border-radius: var(--radius-full);
  }

  .cat-subpill:not(.active) .pill-count {
    background: var(--surface-sunken);
    color: var(--text-muted);
  }

  .menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: var(--space-4);
  }

  .menu-card {
    background: var(--surface-color);
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    overflow: hidden;
    cursor: pointer;
    transition: all var(--transition-fast);
    box-shadow: var(--shadow-xs);
    user-select: none;
    position: relative;
  }

  .menu-card:hover {
    border-color: var(--primary-color);
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
  }

  .menu-card:active {
    transform: translateY(-2px) scale(0.98);
  }

  .menu-card.unavail {
    opacity: 0.45;
    cursor: not-allowed;
    pointer-events: none;
    filter: grayscale(0.6);
  }

  .menu-card.just-added {
    animation: cardPop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
  }

  @keyframes cardPop {
    0% {
      transform: scale(1)
    }

    40% {
      transform: scale(1.05) translateY(-6px)
    }

    100% {
      transform: scale(1) translateY(0)
    }
  }

  .menu-card-img {
    height: 140px;
    background: linear-gradient(135deg, var(--surface-raised) 0%, var(--surface-sunken) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }

  .menu-card-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
  }

  .menu-card:hover .menu-card-img img {
    transform: scale(1.08);
  }

  .menu-card-img-icon {
    font-size: 36px;
    color: var(--border-strong);
  }

  .best-seller-badge {
    position: absolute;
    top: var(--space-2);
    left: var(--space-2);
    z-index: 10;
    background: linear-gradient(135deg, var(--accent-color) 0%, var(--accent-dark) 100%);
    color: var(--text-on-accent);
    font-size: 0.62rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-xs);
    display: flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 8px rgba(240, 180, 41, 0.4);
  }

  .best-seller-badge i {
    font-size: 0.65rem;
    color: #fff;
  }

  .unavail-ribbon {
    position: absolute;
    top: var(--space-3);
    right: -2px;
    background: var(--status-cancelled);
    color: var(--text-on-primary);
    font-size: 0.58rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    padding: 3px 12px 3px 8px;
    text-transform: uppercase;
    border-radius: var(--radius-xs) 0 0 var(--radius-xs);
    box-shadow: var(--shadow-sm);
  }

  .in-cart-badge {
    position: absolute;
    top: var(--space-2);
    right: var(--space-2);
    z-index: 10;
    background: var(--primary-color);
    color: var(--text-on-primary);
    font-size: 0.65rem;
    font-weight: 800;
    min-width: 22px;
    height: 22px;
    border-radius: var(--radius-full);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-primary);
    opacity: 0;
    transform: scale(0.5);
    transition: all 0.2s cubic-bezier(0.34, 1.4, 0.64, 1);
  }

  .menu-card.has-items .in-cart-badge {
    opacity: 1;
    transform: scale(1);
  }

  .menu-card-body {
    padding: var(--space-3);
    padding-bottom: var(--space-4);
  }

  .menu-card-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--text-color);
    line-height: 1.35;
    margin-bottom: var(--space-2);
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  .menu-card-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .menu-card-price {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--primary-color);
    letter-spacing: -0.02em;
  }

  .card-rating-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: var(--accent-subtle);
    border: 1px solid rgba(240, 180, 41, 0.25);
    color: var(--accent-dark);
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: var(--radius-full);
  }

  .card-rating-badge i {
    color: var(--accent-color);
    font-size: 0.65rem;
  }

  .menu-card-add {
    position: absolute;
    bottom: var(--space-3);
    right: var(--space-3);
    width: 32px;
    height: 32px;
    background: var(--primary-color);
    color: var(--text-on-primary);
    border-radius: var(--radius-full);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    box-shadow: var(--shadow-primary);
    transition: all var(--transition-fast);
    opacity: 0;
    transform: scale(0.8);
  }

  .menu-card:hover .menu-card-add {
    opacity: 1;
    transform: scale(1);
  }

  .menu-card-add:hover {
    transform: scale(1.15);
    background: var(--primary-dark);
  }

  .group-section {
    margin-bottom: var(--space-8);
  }

  .group-section-title {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text-color);
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-bottom: var(--space-4);
    padding-bottom: var(--space-3);
    border-bottom: 2px solid var(--primary-color);
  }

  .group-section-title i {
    color: var(--primary-color);
    font-size: 0.95rem;
  }

  .cat-section {
    scroll-margin-top: calc(var(--header-h) + 100px);
    margin-bottom: var(--space-5);
  }

  .cat-section-title {
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-bottom: var(--space-4);
  }

  .cat-section-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border-color);
  }

  .cart-bar {
    position: fixed;
    bottom: var(--space-6);
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    z-index: 90;
    background: var(--text-color);
    color: var(--text-on-primary);
    border-radius: var(--radius-full);
    padding: 0 8px 0 var(--space-5);
    height: 56px;
    display: flex;
    align-items: center;
    gap: var(--space-4);
    box-shadow: var(--shadow-xl);
    min-width: 300px;
    max-width: 500px;
    transition: transform 0.35s cubic-bezier(0.34, 1.2, 0.64, 1), opacity 0.2s;
    opacity: 0;
    pointer-events: none;
  }

  .cart-bar.visible {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
    pointer-events: all;
  }

  .cart-bar-left {
    flex: 1;
    min-width: 0;
  }

  .cart-bar-items {
    font-size: 0.78rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.65);
  }

  .cart-bar-total {
    font-size: 1.1rem;
    font-weight: 800;
    letter-spacing: -0.02em;
  }

  .cart-bar-btn {
    background: var(--primary-color);
    color: var(--text-on-primary);
    height: 42px;
    padding: 0 var(--space-5);
    border-radius: var(--radius-full);
    font-size: 0.85rem;
    font-weight: 700;
    font-family: inherit;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: var(--space-2);
    transition: all var(--transition-fast);
    text-decoration: none;
    white-space: nowrap;
    box-shadow: 0 2px 12px rgba(192, 57, 43, 0.4);
  }

  .cart-bar-btn:hover {
    background: var(--primary-light);
    transform: scale(1.03);
  }

  .no-results {
    grid-column: 1/-1;
    text-align: center;
    padding: var(--space-10) var(--space-4);
    color: var(--text-muted);
  }

  .no-results i {
    font-size: 40px;
    opacity: 0.25;
    display: block;
    margin-bottom: var(--space-4);
    color: var(--border-strong);
  }

  .no-results p {
    font-size: 0.9rem;
  }

  @media(max-width:768px) {
    .menu-nav-row {
      flex-direction: column;
      align-items: stretch
    }

    .menu-search-wrap {
      width: 100%;
      order: -1
    }

    .cat-pills {
      order: 1
    }

    .menu-controls {
      padding: var(--space-3)
    }
  }

  @media(max-width:600px) {
    .menu-grid {
      grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
      gap: var(--space-3)
    }

    .menu-card-img {
      height: 110px
    }

    .cart-bar {
      left: var(--space-4);
      right: var(--space-4);
      transform: translateY(100px);
      min-width: unset;
      border-radius: var(--radius-lg);
      padding: 0 var(--space-4);
      height: 52px
    }

    .cart-bar.visible {
      transform: translateY(0)
    }
  }

  input[type=number]::-webkit-inner-spin-button,
  input[type=number]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0
  }

  input[type=number] {
    -moz-appearance: textfield
  }
</style>

<div class="menu-wrap">
  <div class="page-header" style="margin-bottom:0">
    <div>
      <div class="page-header-title">Order Menu</div>
      <div class="page-header-sub"><?= count($products) ?> items available · Add to cart and checkout</div>
    </div>
    <div class="page-header-actions">
      <a href="<?= APP_URL ?>/faculty/cart.php" class="btn btn-ghost btn-sm" id="cart-top-btn" style="display:none">
        <i class="fa-solid fa-cart-shopping"></i>
        <span id="cart-top-count">0</span> in cart
      </a>
    </div>
  </div>

  <div class="menu-controls">
    <div class="menu-nav-row">
      <div class="cat-pills" id="cat-pills">
        <button class="cat-pill active" data-cat="all" data-type="all">
          All <span class="pill-count" id="pill-count-all"><?= count($products) ?></span>
        </button>
        <?php
        $catCounts = [];
        foreach ($products as $p) $catCounts[$p['cat_id']] = ($catCounts[$p['cat_id']] ?? 0) + 1;
        foreach ($catGroups as $group):
          $groupSubs  = $catsByParent[$group['id']] ?? [];
          $groupCount = array_sum(array_map(fn($s) => $catCounts[$s['id']] ?? 0, $groupSubs));
          if ($groupCount === 0) continue;
        ?>
          <button class="cat-pill" data-cat="group-<?= $group['id'] ?>"
            data-type="group" data-group-id="<?= $group['id'] ?>">
            <?= e($group['name']) ?> <span class="pill-count"><?= $groupCount ?></span>
            <i class="fa-solid fa-chevron-down pill-chevron"></i>
          </button>
        <?php endforeach;
        foreach ($catStandalone as $cat):
          $cnt = $catCounts[$cat['id']] ?? 0;
          if ($cnt === 0) continue;
        ?>
          <button class="cat-pill" data-cat="<?= $cat['id'] ?>"
            data-type="leaf" data-section="cat-<?= $cat['id'] ?>">
            <?= e($cat['name']) ?> <span class="pill-count"><?= $cnt ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <div class="menu-search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="menu-search" id="menu-search" type="search" placeholder="Search menu…" autocomplete="off">
      </div>
    </div>

    <div class="cat-subpills" id="cat-subpills">
      <?php foreach ($catGroups as $group):
        $groupSubs = $catsByParent[$group['id']] ?? [];
      ?>
        <div class="cat-subpill-group" id="subpills-<?= $group['id'] ?>">
          <?php foreach ($groupSubs as $sub):
            $subCount = $catCounts[$sub['id']] ?? 0;
            if ($subCount === 0) continue;
          ?>
            <button class="cat-subpill" data-cat="<?= $sub['id'] ?>"
              data-section="cat-<?= $sub['id'] ?>" data-parent="<?= $group['id'] ?>">
              <?= e($sub['name']) ?> <span class="pill-count"><?= $subCount ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php showFlash('global'); ?>

  <div id="menu-sections">
    <?php
    $renderedCats = [];
    foreach ($catGroups as $group):
      $groupSubs = $catsByParent[$group['id']] ?? [];
      $groupHasProducts = false;
      foreach ($groupSubs as $sub) {
        if (!empty(array_filter($products, fn($p) => $p['cat_id'] == $sub['id']))) {
          $groupHasProducts = true;
          break;
        }
      }
      if (!$groupHasProducts) continue;
    ?>
      <div class="group-section" id="group-<?= $group['id'] ?>">
        <div class="group-section-title">
          <?php if (!empty($group['icon'])): ?><i class="fa-solid <?= e($group['icon']) ?>"></i><?php else: ?><i class="fa-solid fa-utensils"></i><?php endif; ?>
          <?= e($group['name']) ?>
        </div>
        <?php foreach ($groupSubs as $cat):
          $catProducts = array_filter($products, fn($p) => $p['cat_id'] == $cat['id']);
          if (empty($catProducts)) continue;
          $renderedCats[] = $cat['id'];
        ?>
          <div class="cat-section" id="cat-<?= $cat['id'] ?>" data-cat="<?= $cat['id'] ?>">
            <div class="cat-section-title"><?= e($cat['name']) ?></div>
            <div class="menu-grid" data-cat="<?= $cat['id'] ?>">
              <?php foreach ($catProducts as $p): ?>
                <div class="menu-card <?= !$p['is_available'] ? 'unavail' : '' ?>"
                  id="card-<?= $p['id'] ?>" data-cat="<?= $p['cat_id'] ?>"
                  data-name="<?= strtolower(e($p['name'])) ?>" data-id="<?= $p['id'] ?>"
                  onclick="openCustomModal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name'])) ?>, <?= $p['price'] ?>, <?= htmlspecialchars(json_encode($p['image_path'] ?? '')) ?>, <?= (int)$p['has_sizes'] ?>, <?= (int)$p['has_sugar'] ?>, <?= (int)$p['has_addons'] ?>)">
                  <div class="menu-card-img">
                    <?php if (!empty($p['image_path']) && file_exists(UPLOAD_DIR . $p['image_path'])): ?>
                      <img src="<?= $imgBase . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                    <?php else: ?><span class="menu-card-img-icon"><i class="fa-solid fa-mug-hot"></i></span><?php endif; ?>
                    <?php if ($p['total_sold'] >= $bestSellerThreshold && $p['total_sold'] > 0): ?><div class="best-seller-badge"><i class="fa-solid fa-fire"></i> Best Seller</div><?php endif; ?>
                    <?php if (!$p['is_available']): ?><div class="unavail-ribbon">Unavailable</div><?php endif; ?>
                    <div class="in-cart-badge" id="badge-<?= $p['id'] ?>">1</div>
                  </div>
                  <div class="menu-card-body">
                    <div class="menu-card-name"><?= e($p['name']) ?></div>
                    <div class="menu-card-meta">
                      <div class="menu-card-price"><?= peso($p['price']) ?></div>
                      <?php if ($p['avg_rating'] > 0): ?><span class="card-rating-badge"><i class="fa-solid fa-star"></i> <?= $p['avg_rating'] ?></span><?php endif; ?>
                    </div>
                  </div>
                  <?php if ($p['is_available']): ?><div class="menu-card-add"><i class="fa-solid fa-plus"></i></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach;

    foreach ($catStandalone as $cat):
      $catProducts = array_filter($products, fn($p) => $p['cat_id'] == $cat['id']);
      if (empty($catProducts)) continue;
    ?>
      <div class="cat-section" id="cat-<?= $cat['id'] ?>" data-cat="<?= $cat['id'] ?>">
        <div class="cat-section-title"><?= e($cat['name']) ?></div>
        <div class="menu-grid" data-cat="<?= $cat['id'] ?>">
          <?php foreach ($catProducts as $p): ?>
            <div class="menu-card <?= !$p['is_available'] ? 'unavail' : '' ?>"
              id="card-<?= $p['id'] ?>" data-cat="<?= $p['cat_id'] ?>"
              data-name="<?= strtolower(e($p['name'])) ?>" data-id="<?= $p['id'] ?>"
              onclick="openCustomModal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name'])) ?>, <?= $p['price'] ?>, <?= htmlspecialchars(json_encode($p['image_path'] ?? '')) ?>, <?= (int)$p['has_sizes'] ?>, <?= (int)$p['has_sugar'] ?>, <?= (int)$p['has_addons'] ?>)">
              <div class="menu-card-img">
                <?php if (!empty($p['image_path']) && file_exists(UPLOAD_DIR . $p['image_path'])): ?>
                  <img src="<?= $imgBase . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                <?php else: ?><span class="menu-card-img-icon"><i class="fa-solid fa-mug-hot"></i></span><?php endif; ?>
                <?php if ($p['total_sold'] >= $bestSellerThreshold && $p['total_sold'] > 0): ?><div class="best-seller-badge"><i class="fa-solid fa-fire"></i> Best Seller</div><?php endif; ?>
                <?php if (!$p['is_available']): ?><div class="unavail-ribbon">Unavailable</div><?php endif; ?>
                <div class="in-cart-badge" id="badge-<?= $p['id'] ?>">1</div>
              </div>
              <div class="menu-card-body">
                <div class="menu-card-name"><?= e($p['name']) ?></div>
                <div class="menu-card-meta">
                  <div class="menu-card-price"><?= peso($p['price']) ?></div>
                  <?php if ($p['avg_rating'] > 0): ?><span class="card-rating-badge"><i class="fa-solid fa-star"></i> <?= $p['avg_rating'] ?></span><?php endif; ?>
                </div>
              </div>
              <?php if ($p['is_available']): ?><div class="menu-card-add"><i class="fa-solid fa-plus"></i></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div id="no-results" style="display:none" class="no-results">
      <i class="fa-solid fa-magnifying-glass"></i>
      <p>No items match "<span id="no-results-term"></span>"</p>
    </div>
  </div>
</div>

<!-- CUSTOMISATION MODAL -->
<style>
  .cm-backdrop {
    position: fixed;
    inset: 0;
    z-index: 200;
    background: rgba(0, 0, 0, 0.55);
    backdrop-filter: blur(3px);
    display: flex;
    align-items: flex-end;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s
  }

  .cm-backdrop.open {
    opacity: 1;
    pointer-events: all
  }

  .cm-sheet {
    background: var(--surface-color);
    width: 100%;
    max-width: 540px;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    box-shadow: var(--shadow-xl);
    transform: translateY(100%);
    transition: transform 0.3s cubic-bezier(0.34, 1.1, 0.64, 1);
    display: flex;
    flex-direction: column;
    max-height: 92vh;
    overflow: hidden
  }

  .cm-backdrop.open .cm-sheet {
    transform: translateY(0)
  }

  .cm-handle {
    width: 36px;
    height: 4px;
    border-radius: 2px;
    background: var(--border-strong);
    margin: var(--space-3) auto 0;
    flex-shrink: 0
  }

  .cm-header {
    display: flex;
    align-items: flex-start;
    gap: var(--space-4);
    padding: var(--space-4) var(--space-5) var(--space-3);
    flex-shrink: 0
  }

  .cm-thumb {
    width: 72px;
    height: 72px;
    border-radius: var(--radius-md);
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    overflow: hidden;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center
  }

  .cm-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover
  }

  .cm-thumb-icon {
    font-size: 26px;
    color: var(--border-strong)
  }

  .cm-title {
    flex: 1;
    min-width: 0
  }

  .cm-product-name {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--text-color);
    line-height: 1.3;
    margin-bottom: 3px
  }

  .cm-base-price {
    font-size: 0.78rem;
    color: var(--text-muted)
  }

  .cm-close {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-full);
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 14px;
    flex-shrink: 0;
    transition: all var(--transition-fast)
  }

  .cm-close:hover {
    background: var(--status-cancelled-bg);
    color: var(--status-cancelled)
  }

  .cm-body {
    flex: 1;
    overflow-y: auto;
    padding: 0 var(--space-5) var(--space-4);
    scrollbar-width: thin
  }

  .cm-label {
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.11em;
    color: var(--text-muted);
    margin: var(--space-4) 0 var(--space-2)
  }

  .cm-size-group {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-2)
  }

  .cm-size-btn {
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: var(--space-3) var(--space-2);
    text-align: center;
    cursor: pointer;
    background: var(--surface-color);
    transition: all var(--transition-fast);
    font-family: inherit
  }

  .cm-size-btn:hover {
    border-color: var(--primary-color);
    background: var(--primary-subtle)
  }

  .cm-size-btn.active {
    border-color: var(--primary-color);
    background: var(--primary-subtle);
    box-shadow: 0 0 0 2px var(--primary-subtle)
  }

  .cm-size-name {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--text-color);
    display: block
  }

  .cm-size-adj {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 2px;
    display: block
  }

  .cm-size-btn.active .cm-size-name,
  .cm-size-btn.active .cm-size-adj {
    color: var(--primary-color)
  }

  .cm-sugar-group {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap
  }

  .cm-sugar-btn {
    height: 34px;
    padding: 0 var(--space-4);
    border-radius: var(--radius-full);
    border: 1.5px solid var(--border-color);
    background: var(--surface-color);
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    transition: all var(--transition-fast);
    font-family: inherit;
    white-space: nowrap
  }

  .cm-sugar-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color)
  }

  .cm-sugar-btn.active {
    background: var(--primary-color);
    color: var(--text-on-primary);
    border-color: var(--primary-color)
  }

  .cm-addon-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-2)
  }

  .cm-addon-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3);
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all var(--transition-fast);
    background: var(--surface-color)
  }

  .cm-addon-row:hover {
    border-color: var(--primary-color);
    background: var(--primary-subtle)
  }

  .cm-addon-row input[type=checkbox] {
    display: none
  }

  .cm-addon-check {
    width: 22px;
    height: 22px;
    border-radius: var(--radius-xs);
    border: 2px solid var(--border-color);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast);
    font-size: 11px;
    color: transparent
  }

  .cm-addon-row:has(input:checked) {
    border-color: var(--primary-color);
    background: var(--primary-subtle)
  }

  .cm-addon-row:has(input:checked) .cm-addon-check {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: var(--text-on-primary)
  }

  .cm-addon-name {
    flex: 1;
    font-size: 0.84rem;
    font-weight: 600;
    color: var(--text-color)
  }

  .cm-addon-price {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--primary-color)
  }

  .cm-no-addons {
    font-size: 0.78rem;
    color: var(--text-muted);
    font-style: italic;
    padding: var(--space-3) 0
  }

  .cm-notes-input {
    width: 100%;
    padding: var(--space-3);
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 0.84rem;
    font-family: inherit;
    color: var(--text-color);
    background: var(--surface-color);
    outline: none;
    resize: none;
    transition: border-color var(--transition-fast)
  }

  .cm-notes-input:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px var(--primary-subtle)
  }

  .cm-notes-input::placeholder {
    color: var(--text-placeholder)
  }

  .cm-divider {
    height: 1px;
    background: var(--border-color);
    margin: var(--space-4) 0 0
  }

  .cm-footer {
    padding: var(--space-4) var(--space-5) var(--space-5);
    border-top: 1px solid var(--border-color);
    background: var(--surface-color);
    flex-shrink: 0
  }

  .cm-footer-row {
    display: flex;
    align-items: center;
    gap: var(--space-3)
  }

  .cm-qty-control {
    display: flex;
    align-items: center;
    gap: 2px;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 2px;
    background: var(--surface-raised);
    flex-shrink: 0
  }

  .cm-qty-btn {
    width: 34px;
    height: 34px;
    border-radius: calc(var(--radius-sm) - 2px);
    background: transparent;
    border: none;
    font-size: 16px;
    font-weight: 700;
    font-family: inherit;
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast)
  }

  .cm-qty-btn:hover {
    background: var(--primary-color);
    color: var(--text-on-primary)
  }

  .cm-qty-num {
    width: 36px;
    text-align: center;
    font-size: 0.92rem;
    font-weight: 700;
    color: var(--text-color);
    border: none;
    background: transparent;
    font-family: inherit;
    outline: none
  }

  .cm-add-btn {
    flex: 1;
    height: 48px;
    background: var(--primary-color);
    color: var(--text-on-primary);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.90rem;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-3);
    box-shadow: var(--shadow-primary);
    transition: all var(--transition-fast)
  }

  .cm-add-btn:hover {
    background: var(--primary-dark);
    transform: translateY(-1px)
  }

  .cm-price-stack {
    display: flex;
    flex-direction: column;
    align-items: flex-end
  }

  .cm-unit-price {
    font-size: 0.68rem;
    color: rgba(255, 255, 255, 0.70);
    line-height: 1
  }

  .cm-total-price {
    font-size: 1.00rem;
    font-weight: 800;
    line-height: 1.2
  }
</style>

<div class="cm-backdrop" id="custom-modal">
  <div class="cm-sheet">
    <div class="cm-handle"></div>
    <div class="cm-header">
      <div class="cm-thumb">
        <img id="cm-img" src="" alt="" style="display:none">
        <span id="cm-icon" class="cm-thumb-icon"><i class="fa-solid fa-mug-hot"></i></span>
      </div>
      <div class="cm-title">
        <div class="cm-product-name" id="cm-name"></div>
        <div class="cm-base-price">Base price: <span id="cm-base"></span></div>
      </div>
      <button class="cm-close" onclick="closeCustomModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="cm-body">
      <div id="cm-section-size">
        <div class="cm-label">Size</div>
        <div class="cm-size-group">
          <?php foreach ([['16oz', 0], ['22oz', SIZE_22OZ_UPCHARGE]] as [$sz, $adj]): ?>
            <button class="cm-size-btn" onclick="document.querySelectorAll('.cm-size-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');recalcModal();">
              <span class="cm-size-name"><?= $sz ?></span>
              <span class="cm-size-adj"><?= $adj > 0 ? '+₱' . $adj : ($adj < 0 ? '−₱' . abs($adj) : 'Base') ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="cm-section-sugar">
        <div class="cm-label">Sugar Level</div>
        <div class="cm-sugar-group">
          <?php foreach (['Full Sugar', 'Less Sugar', '50% Sugar', 'No Sugar'] as $sl): ?>
            <button class="cm-sugar-btn" onclick="document.querySelectorAll('.cm-sugar-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');"><?= $sl ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="cm-section-addons">
        <div class="cm-label">Add-ons</div>
        <div id="cm-addons-container">
          <div class="cm-no-addons">No add-ons available.</div>
        </div>
      </div>
      <div class="cm-label">Special Instructions</div>
      <textarea id="cm-notes" class="cm-notes-input" rows="2" placeholder="e.g. extra hot, less ice, no whip…"></textarea>
      <div class="cm-divider"></div>
    </div>
    <div class="cm-footer">
      <div class="cm-footer-row">
        <div class="cm-qty-control">
          <button class="cm-qty-btn" onclick="const i=document.getElementById('cm-qty');i.value=Math.max(1,parseInt(i.value||1)-1);recalcModal();">−</button>
          <input type="number" id="cm-qty" class="cm-qty-num" value="1" min="1" max="99" oninput="recalcModal()" onclick="this.select()">
          <button class="cm-qty-btn" onclick="const i=document.getElementById('cm-qty');i.value=Math.min(99,parseInt(i.value||1)+1);recalcModal();">+</button>
        </div>
        <button class="cm-add-btn" onclick="confirmAddToCart()">
          <i class="fa-solid fa-cart-plus"></i>
          Add to Cart
          <div class="cm-price-stack">
            <span class="cm-unit-price">per item <span id="cm-unit-price"></span></span>
            <span class="cm-total-price" id="cm-total"></span>
          </div>
        </button>
      </div>
    </div>
  </div>
</div>

<div class="cart-bar" id="cart-bar">
  <div class="cart-bar-left">
    <div class="cart-bar-items" id="cart-bar-items">0 items</div>
    <div class="cart-bar-total" id="cart-bar-total">₱0.00</div>
  </div>
  <a href="<?= APP_URL ?>/faculty/cart.php" class="cart-bar-btn">
    <i class="fa-solid fa-cart-shopping"></i> View Cart
  </a>
</div>

<script>
  function e(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  /* Faculty cart key */
  function getCart() {
    return JSON.parse(sessionStorage.getItem('faculty_cart') || '{}');
  }

  function saveCart(c) {
    sessionStorage.setItem('faculty_cart', JSON.stringify(c));
  }

  function updateUI(cart, changedProductId) {
    const totalQty = Object.values(cart).reduce((s, i) => s + i.qty, 0);
    const totalPrice = Object.values(cart).reduce((s, i) => s + i.price * i.qty, 0);
    const bar = document.getElementById('cart-bar');
    document.getElementById('cart-bar-items').textContent = totalQty + (totalQty === 1 ? ' item' : ' items');
    document.getElementById('cart-bar-total').textContent = '\u20b1' + totalPrice.toFixed(2);
    if (totalQty > 0) bar.classList.add('visible');
    else bar.classList.remove('visible');
    const topBtn = document.getElementById('cart-top-btn');
    const topCount = document.getElementById('cart-top-count');
    if (totalQty > 0) {
      topBtn.style.display = '';
      topCount.textContent = totalQty;
    } else {
      topBtn.style.display = 'none';
    }
    if (changedProductId != null) {
      const totalForProduct = Object.values(cart).filter(v => v.productId == changedProductId).reduce((s, v) => s + v.qty, 0);
      const badge = document.getElementById('badge-' + changedProductId);
      const card = document.getElementById('card-' + changedProductId);
      if (badge) badge.textContent = totalForProduct;
      if (card) {
        card.classList.toggle('has-items', totalForProduct > 0);
        card.classList.remove('just-added');
        void card.offsetWidth;
        card.classList.add('just-added');
      }
    }
  }

  (function initCart() {
    const cart = getCart();
    const qtyById = {};
    Object.values(cart).forEach(item => {
      qtyById[item.productId] = (qtyById[item.productId] || 0) + item.qty;
    });
    Object.entries(qtyById).forEach(([id, qty]) => {
      const badge = document.getElementById('badge-' + id);
      const card = document.getElementById('card-' + id);
      if (badge) badge.textContent = qty;
      if (card && qty > 0) card.classList.add('has-items');
    });
    updateUI(cart, null);
  })();

  const SIZES = [{
    label: '16oz',
    adj: 0
  }, {
    label: '22oz',
    adj: +<?= SIZE_22OZ_UPCHARGE ?>
  }];
  const SUGAR_LEVELS = ['Full Sugar', 'Less Sugar', '50% Sugar', 'No Sugar'];

  let _modal = {
    id: null,
    name: null,
    basePrice: 0,
    imgPath: null,
    hasSizes: false,
    hasSugar: false,
    hasAddons: false,
    addons: []
  };

  function openCustomModal(id, name, basePrice, imgPath, hasSizes, hasSugar, hasAddons) {
    _modal = {
      id,
      name,
      basePrice: parseFloat(basePrice),
      imgPath,
      hasSizes: !!hasSizes,
      hasSugar: !!hasSugar,
      hasAddons: !!hasAddons,
      addons: []
    };
    document.getElementById('cm-name').textContent = name;
    document.getElementById('cm-base').textContent = '\u20b1' + parseFloat(basePrice).toFixed(2);
    const imgEl = document.getElementById('cm-img');
    const iconEl = document.getElementById('cm-icon');
    if (imgPath) {
      imgEl.src = '<?php echo $imgBase; ?>' + imgPath;
      imgEl.style.display = '';
      iconEl.style.display = 'none';
    } else {
      imgEl.style.display = 'none';
      iconEl.style.display = '';
    }
    document.getElementById('cm-section-size').style.display = hasSizes ? '' : 'none';
    document.getElementById('cm-section-sugar').style.display = hasSugar ? '' : 'none';
    document.getElementById('cm-section-addons').style.display = hasAddons ? '' : 'none';
    document.querySelectorAll('.cm-size-btn').forEach((b, i) => b.classList.toggle('active', i === 0));
    document.querySelectorAll('.cm-sugar-btn').forEach((b, i) => b.classList.toggle('active', i === 0));
    document.getElementById('cm-notes').value = '';
    document.getElementById('cm-qty').value = 1;

    // Clear and load add-ons dynamically
    document.getElementById('cm-addons-container').innerHTML = `
      <div style="text-align:center;padding:1rem;color:var(--text-muted)">
        <i class="fa-solid fa-circle-notch fa-spin" style="font-size:1.2rem"></i>
        <div style="margin-top:0.5rem;font-size:0.8rem">Loading...</div>
      </div>
    `;

    if (hasAddons) {
      fetch(`<?= APP_URL ?>/api/get_product_addons.php?product_id=${id}`)
        .then(r => r.json())
        .then(data => {
          if (data.success && data.addons.length > 0) {
            _modal.addons = data.addons;
            renderModalAddons(data.addons);
          } else {
            document.getElementById('cm-addons-container').innerHTML = '<div class="cm-no-addons">No add-ons available for this product.</div>';
          }
        })
        .catch(err => {
          console.error('Failed to load add-ons:', err);
          document.getElementById('cm-addons-container').innerHTML = '<div class="cm-no-addons">Failed to load add-ons.</div>';
        });
    }

    recalcModal();
    document.getElementById('custom-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeCustomModal() {
    document.getElementById('custom-modal').classList.remove('open');
    document.body.style.overflow = '';
  }

  function renderModalAddons(addons) {
    const container = document.getElementById('cm-addons-container');
    if (!addons || addons.length === 0) {
      container.innerHTML = '<div class="cm-no-addons">No add-ons available for this product.</div>';
      return;
    }

    let html = '<div class="cm-addon-list">';
    addons.forEach(addon => {
      html += `
        <label class="cm-addon-row">
          <input type="checkbox" class="cm-addon-cb" data-name="${e(addon.name)}" data-price="${addon.price}" onchange="recalcModal()">
          <div class="cm-addon-check"><i class="fa-solid fa-check"></i></div>
          <span class="cm-addon-name">${e(addon.name)}</span>
          <span class="cm-addon-price">+\u20b1${parseFloat(addon.price).toFixed(2)}</span>
        </label>
      `;
    });
    html += '</div>';
    container.innerHTML = html;
  }

  function recalcModal() {
    const sizeIdx = [...document.querySelectorAll('.cm-size-btn')].findIndex(b => b.classList.contains('active'));
    const sizeAdj = _modal.hasSizes ? (SIZES[sizeIdx]?.adj ?? 0) : 0;
    let addonTotal = 0;
    if (_modal.hasAddons && _modal.addons.length > 0) {
      document.querySelectorAll('.cm-addon-cb:checked').forEach(cb => {
        addonTotal += parseFloat(cb.dataset.price);
      });
    }
    const unitPrice = _modal.basePrice + sizeAdj + addonTotal;
    const qty = Math.max(1, parseInt(document.getElementById('cm-qty').value) || 1);
    document.getElementById('cm-unit-price').textContent = '\u20b1' + unitPrice.toFixed(2);
    document.getElementById('cm-total').textContent = '\u20b1' + (unitPrice * qty).toFixed(2);
  }

  function confirmAddToCart() {
    const sizeIdx = [...document.querySelectorAll('.cm-size-btn')].findIndex(b => b.classList.contains('active'));
    const sugarIdx = [...document.querySelectorAll('.cm-sugar-btn')].findIndex(b => b.classList.contains('active'));
    const size = _modal.hasSizes ? (SIZES[sizeIdx]?.label ?? 'Medium') : null;
    const sizeAdj = _modal.hasSizes ? (SIZES[sizeIdx]?.adj ?? 0) : 0;
    const sugar = _modal.hasSugar ? (SUGAR_LEVELS[sugarIdx] ?? 'Full Sugar') : null;
    const qty = Math.max(1, parseInt(document.getElementById('cm-qty').value) || 1);
    const notes = document.getElementById('cm-notes').value.trim();
    const chosenAddons = [];
    let addonTotal = 0;
    if (_modal.hasAddons && _modal.addons.length > 0) {
      document.querySelectorAll('.cm-addon-cb:checked').forEach(cb => {
        chosenAddons.push(cb.dataset.name);
        addonTotal += parseFloat(cb.dataset.price);
      });
    }
    const finalPrice = _modal.basePrice + sizeAdj + addonTotal;
    const keyParts = [_modal.id];
    if (size) keyParts.push(size);
    if (sugar) keyParts.push(sugar);
    if (chosenAddons.length) keyParts.push(chosenAddons.join(','));
    if (!size && !sugar && !chosenAddons.length) keyParts.push('plain');
    const key = keyParts.join('|');
    const noteParts = [];
    if (size) noteParts.push(size);
    if (sugar) noteParts.push(sugar);
    if (chosenAddons.length) noteParts.push(chosenAddons.map(a => '+' + a).join(', '));
    if (notes) noteParts.push('"' + notes + '"');
    const note = noteParts.join(' \u00b7 ');
    const cart = getCart();
    if (!cart[key]) cart[key] = {
      productId: _modal.id,
      name: _modal.name,
      price: finalPrice,
      note,
      qty: 0
    };
    cart[key].qty += qty;
    saveCart(cart);
    updateUI(cart, _modal.id);
    showToast(_modal.name + ' \u00d7' + qty + ' added');
    closeCustomModal();
  }

  document.getElementById('cat-pills').addEventListener('click', e => {
    const pill = e.target.closest('.cat-pill');
    if (!pill) return;
    const type = pill.dataset.type;
    const groupId = pill.dataset.groupId;
    document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.cat-subpill').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.cat-subpill-group').forEach(g => g.classList.remove('active'));
    pill.classList.add('active');
    const subpillsBar = document.getElementById('cat-subpills');
    if (type === 'group' && groupId) {
      subpillsBar.classList.add('open');
      const subgrp = document.getElementById('subpills-' + groupId);
      if (subgrp) {
        subgrp.classList.add('active');
        const first = subgrp.querySelector('.cat-subpill');
        if (first) first.classList.add('active');
      }
    } else {
      subpillsBar.classList.remove('open');
    }
    filterMenu(_getMenuCat(), document.getElementById('menu-search').value.trim());
    if (type === 'leaf' && pill.dataset.section) {
      const sec = document.getElementById(pill.dataset.section);
      if (sec) sec.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
  });

  document.getElementById('cat-subpills').addEventListener('click', e => {
    const btn = e.target.closest('.cat-subpill');
    if (!btn) return;
    const parentGrp = btn.closest('.cat-subpill-group');
    parentGrp.querySelectorAll('.cat-subpill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterMenu(_getMenuCat(), document.getElementById('menu-search').value.trim());
    if (btn.dataset.section) {
      const sec = document.getElementById(btn.dataset.section);
      if (sec) sec.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
  });

  document.getElementById('menu-search').addEventListener('input', function() {
    filterMenu(_getMenuCat(), this.value.trim());
  });

  function _getMenuCat() {
    const activeSub = document.querySelector('.cat-subpill.active');
    if (activeSub) return activeSub.dataset.cat;
    const activePill = document.querySelector('.cat-pill.active');
    if (!activePill) return 'all';
    const type = activePill.dataset.type;
    if (type === 'all' || type === 'group') return 'group:' + (activePill.dataset.groupId || '');
    return activePill.dataset.cat;
  }

  function filterMenu(cat, query) {
    const q = query.toLowerCase();
    let visibleTotal = 0;
    let groupCatIds = null;
    if (cat.startsWith('group:')) {
      const gid = cat.split(':')[1];
      if (gid) groupCatIds = new Set([...document.querySelectorAll('#subpills-' + gid + ' .cat-subpill')].map(b => b.dataset.cat));
    }
    document.querySelectorAll('.cat-section').forEach(section => {
      let catMatch;
      if (cat === 'group:' || !cat) catMatch = true;
      else if (groupCatIds) catMatch = groupCatIds.has(section.dataset.cat);
      else catMatch = section.dataset.cat === cat;
      let sectionVisible = 0;
      section.querySelectorAll('.menu-card').forEach(card => {
        const show = catMatch && (q === '' || card.dataset.name.includes(q));
        card.style.display = show ? '' : 'none';
        if (show) {
          sectionVisible++;
          visibleTotal++;
        }
      });
      section.style.display = sectionVisible > 0 ? '' : 'none';
    });
    document.querySelectorAll('.group-section').forEach(group => {
      const anyVisible = [...group.querySelectorAll('.cat-section')].some(s => s.style.display !== 'none');
      group.style.display = anyVisible ? '' : 'none';
    });
    document.getElementById('no-results').style.display = visibleTotal === 0 ? '' : 'none';
    document.getElementById('no-results-term').textContent = query;
  }

  document.getElementById('custom-modal').addEventListener('click', function(e) {
    if (e.target === this) closeCustomModal();
  });
</script>
<?php layoutFooter(); ?>