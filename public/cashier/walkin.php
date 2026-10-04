<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_CASHIER);
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_order'])) {
  verifyCsrf();
  $items        = json_decode($_POST['cart_items'] ?? '[]', true);
  $payMethod    = in_array($_POST['pay_method'] ?? '', ['cash', 'GCash']) ? $_POST['pay_method'] : 'cash';
  $cash         = $payMethod === 'cash' ? round((float)($_POST['cash_received'] ?? 0), 2) : 0;
  $gcashRef     = $payMethod === 'GCash' ? sanitizeString($_POST['gcash_reference'] ?? '', 50) : null;
  $denomsRaw    = json_decode($_POST['denominations'] ?? '{}', true) ?: [];
  $discountType = sanitizeString($_POST['discount_type'] ?? 'none');
  if (!in_array($discountType, ['none', 'pwd', 'senior'])) $discountType = 'none';

  // Validate GCash reference
  if ($payMethod === 'GCash' && empty($gcashRef)) {
    flash('global', 'GCash reference number is required.', 'error');
    redirect(APP_URL . '/cashier/walkin.php');
  }

  $validDenoms = [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.50, 0.25];
  $denoms = [];
  foreach ($validDenoms as $d) {
    $key = (string)$d;
    $qty = max(0, (int)($denomsRaw[$key] ?? 0));
    if ($qty > 0) $denoms[$key] = $qty;
  }

  if (empty($items)) {
    flash('global', 'Cart is empty.', 'error');
    redirect(APP_URL . '/cashier/walkin.php');
  }

  $subtotal = 0;
  $details  = [];
  $allOk    = true;
  foreach ($items as $item) {
    $stmt = $db->prepare("SELECT id,name,price FROM products WHERE id=? AND is_available=1");
    $stmt->execute([(int)$item['product_id']]);
    $prod = $stmt->fetch();
    if (!$prod) {
      flash('global', 'A selected product is no longer available.', 'error');
      $allOk = false;
      break;
    }
    $qty   = max(1, (int)$item['qty']);
    $price = isset($item['price']) && $item['price'] > 0 ? round((float)$item['price'], 2) : (float)$prod['price'];
    $sub   = $price * $qty;
    $subtotal += $sub;
    $note  = sanitizeString($item['note'] ?? '', 300);
    $details[] = ['product_id' => $prod['id'], 'qty' => $qty, 'price' => $price, 'sub' => $sub, 'note' => $note];
  }

  // VAT is already included in price (VAT-inclusive), so VAT = subtotal * 12/112
  $vatAmount      = round($subtotal * 12 / 112, 2);
  $discountAmount = 0;
  if ($discountType !== 'none') {
    // PWD/Senior: 20% discount on VAT-exclusive base price
    $vatExclusive   = round($subtotal - $vatAmount, 2);
    $discountAmount = round($vatExclusive * 0.20, 2);
  }
  $total = round($subtotal - $discountAmount, 2);

  // For GCash, amount paid = exact total, change = 0
  if ($payMethod === 'GCash') {
    $cash   = $total;
    $change = 0;
  } else {
    if ($allOk && $cash < $total) {
      flash('global', 'Cash received is less than the total.', 'error');
      $allOk = false;
    }
    $change = $allOk ? round($cash - $total, 2) : 0;
  }

  if ($allOk) {
    $db->beginTransaction();
    try {
      // See api/checkout.php for why this retries: generateOrderNumber()
      // is count-then-insert, which can collide under concurrent cashiers
      // ringing up orders at the same moment — exactly the situation a
      // busy till hits most often. Retrying regenerates a fresh number
      // instead of failing an order the cashier already collected cash for.
      $maxAttempts = 5;
      for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $orderNo = generateOrderNumber();
        try {
          $db->prepare("INSERT INTO orders (order_number,order_type,status,cashier_id,total_amount) VALUES (?,?,?,?,?)")
            ->execute([$orderNo, ORDER_WALKIN, STATUS_CLAIMED, currentUserId(), $total]);
          break; // success
        } catch (\PDOException $e) {
          if ($e->getCode() === '23000' && $attempt < $maxAttempts) {
            continue; // order_number collision — regenerate and retry
          }
          throw $e;
        }
      }
      $orderId = (int)$db->lastInsertId();
      $stmt = $db->prepare("INSERT INTO order_details (order_id,product_id,quantity,price_at_time,subtotal,customization_note) VALUES (?,?,?,?,?,?)");
      foreach ($details as $d) {
        $stmt->execute([$orderId, $d['product_id'], $d['qty'], $d['price'], $d['sub'], $d['note']]);
      }
      $db->prepare("INSERT INTO payments (order_id,payment_method,amount_paid,change_given,reference_number,payment_status,paid_at) VALUES (?,?,?,?,?,?,NOW())")
        ->execute([$orderId, $payMethod, $cash, $change, $gcashRef, PAY_STATUS_PAID]);
      $paymentId = (int)$db->lastInsertId();

      // Denominations only apply to cash payments
      if ($payMethod === 'cash' && !empty($denoms)) {
        $dstmt = $db->prepare("INSERT INTO payment_denominations (payment_id,denomination,quantity) VALUES (?,?,?)");
        foreach ($denoms as $d => $qty) $dstmt->execute([$paymentId, (float)$d, $qty]);
      }

      $db->commit();
      auditLog(ROLE_CASHIER, currentUserId(), 'create_walkin_order', 'orders', $orderId);
      $_SESSION['last_order_id'] = $orderId;

      if ($payMethod === 'GCash') {
        flash('global', "Order {$orderNo} processed via GCash! Ref: {$gcashRef}", 'success');
      } else {
        flash('global', "Order {$orderNo} processed! Change: " . peso($change), 'success');
      }
      redirect(APP_URL . '/cashier/receipt.php');
    } catch (\Throwable $e) {
      $db->rollBack();
      error_log($e->getMessage());
      flash('global', 'Order failed. Please try again.', 'error');
      redirect(APP_URL . '/cashier/walkin.php');
    }
  } else {
    redirect(APP_URL . '/cashier/walkin.php');
  }
}

// Fetch all products, explicitly selecting parent_id and attaching total_sold via the summary view
$allProducts = $db->query(
  "SELECT p.*, c.name AS cat_name, c.id AS cat_id,
           c.parent_id AS cat_parent, cp.id AS group_id,
           COALESCE(prs.total_sold, 0) AS total_sold,
           CASE WHEN EXISTS (
               SELECT 1 FROM product_addons pa 
               JOIN addons a ON pa.addon_id = a.id 
               WHERE pa.product_id = p.id AND a.status = 'active'
           ) THEN 1 ELSE 0 END AS has_addons
   FROM products p
   JOIN categories c  ON p.category_id = c.id
   LEFT JOIN categories cp ON c.parent_id = cp.id
   LEFT JOIN product_rating_summary prs ON prs.product_id = p.id
   WHERE c.name != 'Add-ons'
   ORDER BY c.sort_order, p.name"
)->fetchAll();

// Threshold logic: Calculate top-5 best sellers 
$soldCounts = array_column($allProducts, 'total_sold');
rsort($soldCounts);
$bestSellerThreshold = $soldCounts[min(4, count($soldCounts) - 1)] ?? 0;

$allCats = $db->query("SELECT * FROM categories WHERE name != 'Add-ons' ORDER BY sort_order")->fetchAll();
$catsByParent = [];
foreach ($allCats as $cat) {
  if ($cat['parent_id']) $catsByParent[$cat['parent_id']][] = $cat;
}
$catGroups     = array_filter($allCats, fn($c) => $c['parent_id'] === null && !empty($catsByParent[$c['id']]));
$catStandalone = array_filter($allCats, fn($c) => $c['parent_id'] === null && empty($catsByParent[$c['id']]));

$imgBase = APP_URL . '/../uploads/products/';
layoutHeader('Walk-in POS', '');
?>
<style>
  input::-webkit-outer-spin-button,
  input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
  }

  input[type=number] {
    -moz-appearance: textfield;
  }

  :root {
    --pos-cart-w: 380px;
  }

  @media (max-width: 1180px) {
    :root {
      --pos-cart-w: 320px;
    }
  }

  @media (max-width: 900px) {
    :root {
      --pos-cart-w: 290px;
    }
  }

  .pos-wrap {
    margin-right: calc(var(--pos-cart-w) + var(--space-5));
    padding-bottom: var(--space-6);
  }

  .pos-menu {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .filter-bar-wrap {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex-shrink: 0;
    margin-bottom: 8px;
  }

  .cat-pills {
    display: flex;
    align-items: center;
    gap: 6px;
    overflow-x: auto;
    scrollbar-width: none;
    flex-wrap: nowrap;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 2px;
  }

  .cat-pills::-webkit-scrollbar {
    display: none;
  }

  .cat-pill {
    height: 32px;
    padding: 0 16px;
    border-radius: var(--radius-full);
    font-size: 0.78rem;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    border: 1.5px solid var(--border-color);
    background: var(--surface-color);
    color: var(--text-muted);
    white-space: nowrap;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 7px;
  }

  .cat-pill:focus-visible,
  .sub-pill:focus-visible {
    outline: 2px solid var(--primary-color);
    outline-offset: 2px;
  }

  .cat-pill:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
  }

  .cat-pill.active {
    background: var(--primary-color);
    color: var(--text-on-primary);
    border-color: var(--primary-color);
    box-shadow: var(--shadow-primary);
  }

  .filter-sub {
    padding: 6px 0 0;
    border-top: 1px dashed var(--border-color);
    display: none;
    margin-top: 2px;
  }

  .filter-sub.open {
    display: flex;
  }

  .sub-pill {
    height: 28px;
    padding: 0 14px;
    font-size: 0.72rem;
    background: var(--surface-raised);
    border-color: transparent;
  }

  .pos-search-wrap {
    position: relative;
    flex: 1;
  }

  .pos-search-wrap i {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 13px;
    pointer-events: none;
  }

  .pos-search {
    width: 100%;
    height: 36px;
    padding: 0 12px 0 34px;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 0.83rem;
    font-family: inherit;
    color: var(--text-color);
    background: var(--surface-color);
    outline: none;
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
  }

  .pos-search:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px var(--primary-subtle);
  }

  .pos-cart {
    position: fixed;
    top: var(--header-h);
    right: 0;
    width: var(--pos-cart-w);
    height: calc(100vh - var(--header-h));
    background: var(--surface-color);
    border-left: 1.5px solid var(--border-color);
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    z-index: 90;
  }

  @media (max-width: 768px) {
    :root {
      --pos-cart-w: 100vw;
    }

    .pos-wrap {
      margin-right: 0;
      padding-bottom: 80px;
    }

    .pos-cart {
      position: fixed;
      top: auto;
      bottom: 0;
      left: 0;
      right: 0;
      width: 100%;
      height: auto;
      max-height: 75vh;
      border-left: none;
      border-top: 2px solid var(--border-color);
      border-radius: var(--radius-lg) var(--radius-lg) 0 0;
      box-shadow: 0 -8px 32px rgba(24, 18, 14, 0.12);
      transform: translateY(calc(100% - 60px));
      transition: transform var(--transition-slow);
    }

    .pos-cart.cart-open {
      transform: translateY(0);
    }

    .pos-cart-handle {
      display: flex;
      justify-content: center;
      padding: 12px 0 8px;
      flex-shrink: 0;
      cursor: pointer;
    }

    .pos-cart-handle::before {
      content: '';
      display: block;
      width: 40px;
      height: 5px;
      background: var(--border-strong);
      border-radius: 3px;
    }

    .pos-cart-fab {
      display: flex;
      position: fixed;
      bottom: 16px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 95;
      background: var(--text-color);
      color: var(--text-on-primary);
      border: none;
      border-radius: var(--radius-full);
      padding: 0 20px;
      height: 50px;
      gap: var(--space-3);
      font-size: 0.88rem;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      box-shadow: var(--shadow-xl);
      align-items: center;
      white-space: nowrap;
      transition: background var(--transition-fast), box-shadow var(--transition-fast);
    }

    .pos-cart-fab:hover {
      background: var(--primary-color);
    }

    .pos-cart-fab.hidden {
      display: none;
    }
  }

  @media (min-width: 769px) {

    .pos-cart-handle,
    .pos-cart-fab {
      display: none;
    }
  }

  .pos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(135px, 1fr));
    gap: var(--space-3);
  }

  @media (min-width: 540px) {
    .pos-grid {
      grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
  }

  .pos-card {
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

  .pos-card:hover {
    border-color: var(--primary-color);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
  }

  .pos-card:active {
    transform: scale(0.97);
    box-shadow: var(--shadow-xs);
  }

  .pos-card:focus-visible {
    outline: 2px solid var(--primary-color);
    outline-offset: 2px;
  }

  .pos-card-unavail-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 5;
  }

  .pos-card-unavail-badge {
    background: var(--status-cancelled);
    color: var(--text-on-primary);
    font-size: 0.58rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: var(--radius-full);
    text-transform: uppercase;
    letter-spacing: 0.06em;
  }

  .pos-card.in-cart {
    border-color: var(--primary-color);
    background: var(--primary-subtle);
  }

  .pos-card.in-cart::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: calc(var(--radius-md) - 1px);
    border: 1.5px solid var(--primary-color);
    pointer-events: none;
  }

  .pos-card-img {
    position: relative;
    width: 100%;
    padding-bottom: 100%;
    background: var(--surface-raised);
    overflow: hidden;
  }

  .pos-card-img>* {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .pos-card-img img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
  }

  .pos-card:hover .pos-card-img img {
    transform: scale(1.05);
  }

  .pos-card-img-icon {
    font-size: 26px;
    color: var(--border-strong);
  }

  .pos-best-seller {
    position: absolute;
    top: 6px;
    left: 6px;
    right: auto;
    bottom: auto;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    font-size: 0.55rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    padding: 3px 6px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    gap: 3px;
    box-shadow: 0 2px 6px rgba(217, 119, 6, 0.4);
    z-index: 10;
  }

  .pos-qty-badge {
    position: absolute;
    top: 6px;
    right: 6px;
    background: var(--primary-color);
    color: var(--text-on-primary);
    font-size: 0.68rem;
    font-weight: 800;
    width: 22px;
    height: 22px;
    border-radius: var(--radius-full);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-primary);
    opacity: 0;
    transform: scale(0.6);
    transition: opacity 0.15s, transform 0.2s cubic-bezier(0.34, 1.4, 0.64, 1);
    z-index: 10;
  }

  .pos-card.in-cart .pos-qty-badge {
    opacity: 1;
    transform: scale(1);
  }

  .pos-card-body {
    padding: var(--space-2) var(--space-3) var(--space-3);
  }

  .pos-card-name {
    font-size: 0.80rem;
    font-weight: 600;
    color: var(--text-color);
    line-height: 1.3;
    margin-bottom: 3px;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  .pos-card-price {
    font-size: 0.90rem;
    font-weight: 800;
    color: var(--primary-color);
  }

  .pos-no-results {
    grid-column: 1/-1;
    text-align: center;
    padding: var(--space-8);
    color: var(--text-muted);
    font-size: 0.84rem;
  }

  .pos-cart-head {
    padding: 16px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    background: var(--surface-color);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    z-index: 2;
  }

  .pos-cart-title {
    font-size: 0.9rem;
    font-weight: 800;
    color: var(--text-color);
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .pos-cart-title i {
    color: var(--primary-color);
  }

  .pos-item-chip {
    font-size: 0.65rem;
    font-weight: 800;
    background: var(--primary-subtle);
    color: var(--primary-color);
    padding: 3px 10px;
    border-radius: var(--radius-full);
  }

  .pos-cart-clear-btn {
    width: 30px;
    height: 30px;
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 13px;
    transition: all var(--transition-fast);
  }

  .pos-cart-clear-btn:hover {
    background: var(--status-cancelled-bg);
    color: var(--status-cancelled);
    border-color: var(--status-cancelled-border);
    transform: scale(1.05);
  }

  .pos-cart-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    scrollbar-width: thin;
    scrollbar-color: var(--border-color) transparent;
    background: var(--surface-raised);
  }

  .pos-cart-body::-webkit-scrollbar {
    width: 4px;
  }

  .pos-cart-body::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 4px;
  }

  .pos-cart-items {
    flex-shrink: 0;
    padding: 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .pos-cart-empty {
    padding: var(--space-8) var(--space-6);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-3);
    color: var(--text-muted);
    height: 100%;
  }

  .pos-cart-empty i {
    font-size: 40px;
    opacity: 0.15;
    margin-bottom: 10px;
  }

  .pos-cart-empty span {
    font-size: 0.9rem;
    font-weight: 600;
  }

  .pos-cart-footer {
    padding: 16px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: var(--surface-color);
    box-shadow: 0 -1px 3px rgba(0, 0, 0, 0.02);
    z-index: 2;
  }

  .pos-process-btn {
    background: var(--primary-color);
    color: var(--text-on-primary);
    border: none;
    border-radius: var(--radius-sm);
    width: 100%;
    padding: 12px 16px;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    transition: background var(--transition-fast), transform var(--transition-fast);
    box-shadow: var(--shadow-sm);
  }

  .pos-process-btn:hover:not(:disabled) {
    background: var(--primary-dark);
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
  }

  .pos-process-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    filter: grayscale(100%);
  }

  .pos-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-xs);
    transition: transform var(--transition-fast), box-shadow var(--transition-fast);
    position: relative;
  }

  .pos-item:hover {
    box-shadow: var(--shadow-sm);
    border-color: rgba(192, 57, 43, 0.3);
  }

  .pos-item-thumb {
    width: 54px;
    height: 54px;
    min-width: 54px;
    min-height: 54px;
    flex-shrink: 0;
    align-self: stretch;
    border-radius: var(--radius-sm);
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    overflow: hidden;
    position: relative;
  }

  .pos-item-thumb img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100% !important;
    object-fit: cover;
    display: block;
  }

  .pos-item-thumb-icon {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .pos-item-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .pos-item-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--text-color);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .pos-item-note {
    font-size: 0.7rem;
    color: var(--text-muted);
    font-style: italic;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .pos-item-unit-price {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--primary-color);
  }

  .pos-item-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    flex-shrink: 0;
  }

  .pos-item-sub {
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--text-color);
  }

  .pos-item-stepper {
    display: flex;
    align-items: center;
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-full);
    padding: 2px;
    overflow: hidden;
    width: max-content;
  }

  .pos-qty-btn {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: transparent;
    border: none;
    color: var(--text-secondary);
    font-size: 14px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all var(--transition-fast);
  }

  .pos-qty-btn:hover {
    background: var(--text-color);
    color: var(--surface-color);
  }

  .pos-qty-btn.minus:hover {
    background: var(--status-cancelled);
    color: #fff;
  }

  .pos-qty-input {
    width: 32px;
    text-align: center;
    background: transparent;
    border: none;
    font-size: 0.85rem;
    font-weight: 800;
    color: var(--text-color);
    outline: none;
  }

  .pos-item-remove {
    position: absolute;
    top: -8px;
    right: -8px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    color: var(--text-muted);
    font-size: 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast);
    box-shadow: var(--shadow-xs);
    opacity: 0;
  }

  .pos-item:hover .pos-item-remove {
    opacity: 1;
  }

  .pos-item-remove:hover {
    background: var(--status-cancelled);
    color: #fff;
    border-color: var(--status-cancelled);
  }

  .pos-totals {
    background: var(--surface-color);
    padding: 16px;
    flex-shrink: 0;
    box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.03);
    z-index: 2;
  }

  .pos-totals-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 0;
    font-size: 0.8rem;
    color: var(--text-muted);
  }

  .pos-totals-line .lbl {
    font-weight: 600;
  }

  .pos-totals-line .val {
    font-weight: 700;
    color: var(--text-color);
  }

  .pos-totals-line .val.discount {
    color: var(--status-ready);
    background: rgba(5, 150, 105, 0.1);
    padding: 2px 6px;
    border-radius: 4px;
  }

  .pos-totals-line.total-final {
    padding: 12px 0 8px;
    border-top: 2px dashed var(--border-color);
    margin-top: 8px;
    align-items: flex-end;
  }

  .pos-totals-line.total-final .lbl {
    font-size: 0.85rem;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 4px;
  }

  .pos-totals-line.total-final .val {
    font-size: 2rem;
    font-weight: 900;
    color: var(--primary-color);
    line-height: 1;
  }

  .discount-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-top: 1px solid var(--border-color);
    margin-top: 12px;
  }

  .discount-label {
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
  }

  .discount-pills {
    display: flex;
    gap: 6px;
    flex: 1;
  }

  .discount-pill {
    flex: 1;
    height: 32px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 700;
    border: 1px solid var(--border-color);
    background: var(--surface-raised);
    color: var(--text-muted);
    cursor: pointer;
    transition: all var(--transition-fast);
  }

  .discount-pill.active {
    background: var(--text-color);
    color: var(--surface-color);
    border-color: var(--text-color);
    box-shadow: var(--shadow-sm);
  }

  .discount-pill.active.pwd {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
  }

  .discount-pill.active.senior {
    background: #059669;
    border-color: #059669;
    color: #fff;
  }

  /* ── Payment modal ── */
  .cash-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 300;
    background: rgba(0, 0, 0, 0.65);
    backdrop-filter: blur(5px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-5);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
  }

  .cash-modal-overlay.open {
    opacity: 1;
    pointer-events: all;
  }

  .cash-modal {
    background: var(--surface-color);
    border-radius: var(--radius-lg);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
    width: 100%;
    max-width: 420px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transform: scale(0.95) translateY(20px);
    transition: transform 0.3s cubic-bezier(0.34, 1.1, 0.64, 1);
  }

  .cash-modal-overlay.open .cash-modal {
    transform: scale(1) translateY(0);
  }

  .cash-modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--surface-color);
  }

  .cash-modal-title {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text-color);
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .cash-modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--surface-raised);
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 14px;
    transition: all var(--transition-fast);
  }

  .cash-modal-close:hover {
    background: var(--status-cancelled-bg);
    color: var(--status-cancelled);
    transform: rotate(90deg);
  }

  .cash-modal-due {
    padding: 20px 24px 0;
  }

  .cash-modal-due-inner {
    background: var(--primary-subtle);
    border: 1px solid rgba(192, 57, 43, 0.2);
    border-radius: var(--radius-md);
    padding: 14px 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .cash-modal-due-lbl {
    font-size: 0.8rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
  }

  .cash-modal-due-val {
    font-size: 1.8rem;
    font-weight: 900;
    color: var(--primary-color);
  }

  /* Payment method tabs */
  .pay-tabs {
    display: flex;
    gap: 8px;
    padding: 16px 24px 0;
  }

  .pay-tab {
    flex: 1;
    height: 38px;
    border-radius: var(--radius-sm);
    font-size: 0.8rem;
    font-weight: 700;
    font-family: inherit;
    border: 1.5px solid var(--border-color);
    background: var(--surface-raised);
    color: var(--text-muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all var(--transition-fast);
  }

  .pay-tab:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
  }

  .pay-tab.active-cash {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: var(--text-on-primary);
    box-shadow: var(--shadow-primary);
  }

  .pay-tab.active-gcash {
    background: #0052CC;
    border-color: #0052CC;
    color: #fff;
    box-shadow: 0 2px 8px rgba(0, 82, 204, 0.35);
  }

  .cash-modal-body {
    padding: 20px 24px;
    overflow-y: auto;
  }

  .cash-modal-footer {
    padding: 16px 24px 20px;
    border-top: 1px solid var(--border-color);
    background: var(--surface-raised);
  }

  .cash-modal-confirm-btn {
    width: 100%;
    height: 52px;
    background: var(--primary-color);
    color: var(--text-on-primary);
    border: none;
    border-radius: var(--radius-md);
    font-size: 1rem;
    font-weight: 800;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: var(--shadow-md);
    transition: all var(--transition-fast);
  }

  .cash-modal-confirm-btn:hover:not(:disabled) {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
  }

  .cash-modal-confirm-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    filter: grayscale(100%);
  }

  .cash-modal-confirm-btn.gcash-btn {
    background: #0052CC;
    box-shadow: 0 2px 8px rgba(0, 82, 204, 0.35);
  }

  .cash-modal-confirm-btn.gcash-btn:hover:not(:disabled) {
    background: #003d99;
  }

  /* Cash panel */
  .cash-input-wrap {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .cash-input-field {
    width: 100%;
    height: 56px;
    border: 2px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 1.6rem;
    font-weight: 800;
    font-family: inherit;
    color: var(--text-color);
    background: var(--surface-color);
    padding: 0 16px;
    outline: none;
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    text-align: right;
  }

  .cash-input-field:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px var(--primary-subtle);
  }

  .cash-quick-btns {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
  }

  .cash-quick-btn {
    height: 38px;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    background: var(--surface-raised);
    font-size: 0.82rem;
    font-weight: 700;
    font-family: inherit;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all var(--transition-fast);
  }

  .cash-quick-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: var(--primary-subtle);
  }

  .cash-change-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-radius: var(--radius-md);
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    transition: all var(--transition-fast);
  }

  .cash-change-row.ok-bg {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.3);
  }

  .cash-change-row.short-bg {
    background: rgba(192, 57, 43, 0.08);
    border-color: rgba(192, 57, 43, 0.3);
  }

  .cash-change-lbl {
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--text-muted);
  }

  .cash-change-val {
    font-size: 1.3rem;
    font-weight: 900;
    color: var(--text-color);
  }

  .cash-change-val.ok {
    color: var(--status-ready);
  }

  .cash-change-val.short {
    color: var(--status-cancelled);
  }

  /* GCash panel */
  .gcash-panel {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .gcash-ref-label {
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--text-muted);
    margin-bottom: 6px;
  }

  .gcash-ref-input {
    width: 100%;
    height: 52px;
    border: 2px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 1.2rem;
    font-weight: 800;
    font-family: inherit;
    letter-spacing: 0.08em;
    color: var(--text-color);
    background: var(--surface-color);
    padding: 0 16px;
    outline: none;
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
  }

  .gcash-ref-input:focus {
    border-color: #0052CC;
    box-shadow: 0 0 0 3px rgba(0, 82, 204, 0.12);
  }

  .gcash-hint {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 12px 14px;
    font-size: 0.77rem;
    color: var(--text-muted);
    line-height: 1.5;
  }

  .gcash-hint i {
    color: #0052CC;
    font-size: 15px;
    flex-shrink: 0;
    margin-top: 1px;
  }
</style>

<?php showFlash('global'); ?>

<form id="order-form" method="POST" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="process_order" value="1">
  <input type="hidden" name="cart_items" id="cart-data">
  <input type="hidden" name="denominations" id="denom-data">
  <input type="hidden" name="cash_received" id="cash-data">
  <input type="hidden" name="discount_type" id="discount-type-data">
  <input type="hidden" name="pay_method" id="pay-method-data">
  <input type="hidden" name="gcash_reference" id="gcash-ref-data">
</form>

<div class="pos-wrap">

  <div class="pos-menu">
    <div class="pos-menu-head">
      <div class="pos-search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="pos-search" id="pos-search" type="search" placeholder="Search products…" autocomplete="off">
      </div>
    </div>

    <div class="filter-bar-wrap" id="c">
      <div class="cat-pills" id="tier-1">
        <button class="cat-pill active" data-type="all" data-id="all">
          All <span class="cat-pill-count"><?= count($allProducts) ?></span>
        </button>
        <?php foreach ($catGroups as $group): ?>
          <button class="cat-pill" data-type="group" data-id="<?= $group['id'] ?>">
            <?php if (!empty($group['icon'])): ?><i class="fa-solid <?= e($group['icon']) ?>"></i><?php endif; ?>
            <?= e($group['name']) ?> <i class="fa-solid fa-chevron-down" style="font-size:10px; opacity:0.6; margin-left:2px"></i>
          </button>
        <?php endforeach; ?>
        <?php foreach ($catStandalone as $cat): ?>
          <button class="cat-pill" data-type="cat" data-id="<?= $cat['id'] ?>">
            <?= e($cat['name']) ?>
          </button>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($catsByParent)): ?>
        <div class="filter-sub cat-pills" id="tier-2">
          <?php foreach ($catsByParent as $parentId => $children): ?>
            <?php foreach ($children as $subCat): ?>
              <button class="cat-pill sub-pill" data-parent="<?= $parentId ?>" data-id="<?= $subCat['id'] ?>" style="display:none;">
                <?= e($subCat['name']) ?>
              </button>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="pos-grid" id="pos-grid">
      <?php foreach ($allProducts as $p): ?>
        <div class="pos-card <?= !$p['is_available'] ? 'unavail' : '' ?>"
          id="pcard-<?= $p['id'] ?>"
          data-id="<?= $p['id'] ?>"
          data-cat="<?= $p['cat_id'] ?>"
          data-parent="<?= $p['cat_parent'] ?>"
          data-name="<?= strtolower(e($p['name'])) ?>"
          data-avail="<?= $p['is_available'] ? '1' : '0' ?>"
          <?php if ($p['is_available']):
            $hasOptions = $p['has_sizes'] || $p['has_sugar'] || $p['has_addons'];
            $onclick = $hasOptions
              ? "openPosModal({$p['id']}, " . htmlspecialchars(json_encode($p['name'])) . ", {$p['price']}, " . htmlspecialchars(json_encode($p['image_path'] ?? '')) . ", " . (int)$p['has_sizes'] . ", " . (int)$p['has_sugar'] . ", " . (int)$p['has_addons'] . ")"
              : "quickAddToCart({$p['id']}, " . htmlspecialchars(json_encode($p['name'])) . ", {$p['price']})";
          ?>
          role="button"
          tabindex="0"
          aria-label="<?= $hasOptions ? 'Customize ' . e($p['name']) : 'Add ' . e($p['name']) ?>, <?= peso($p['price']) ?>"
          onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
          onclick="<?= $onclick ?>"
          <?php endif; ?>>

          <div class="pos-card-img">
            <?php if (!empty($p['image_path']) && file_exists(UPLOAD_DIR . $p['image_path'])): ?>
              <img src="<?= $imgBase . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
            <?php else: ?>
              <span class="pos-card-img-icon"><i class="fa-solid fa-mug-hot"></i></span>
            <?php endif; ?>

            <?php if ($p['total_sold'] >= $bestSellerThreshold && $p['total_sold'] > 0): ?>
              <div class="pos-best-seller"><i class="fa-solid fa-fire"></i> Best Seller</div>
            <?php endif; ?>

            <?php if (!$p['is_available']): ?>
              <div class="pos-card-unavail-overlay">
                <span class="pos-card-unavail-badge">Unavailable</span>
              </div>
            <?php endif; ?>
            <div class="pos-qty-badge" id="pbadge-<?= $p['id'] ?>">0</div>
          </div>

          <div class="pos-card-body">
            <div class="pos-card-name"><?= e($p['name']) ?></div>
            <div class="pos-card-price"><?= peso($p['price']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <div id="pos-no-results" class="pos-no-results" style="display:none">
        <i class="fa-solid fa-magnifying-glass" style="font-size:24px;opacity:0.2;display:block;margin-bottom:8px"></i>
        No products found
      </div>
    </div>
  </div>

  <div class="pos-cart" id="pos-cart">
    <div class="pos-cart-handle" onclick="toggleMobileCart()"></div>
    <div class="pos-cart-head">
      <div class="pos-cart-title"><i class="fa-solid fa-receipt"></i> Current Order</div>
      <div style="display:flex;align-items:center;gap:var(--space-2)">
        <span class="pos-item-chip" id="pos-item-chip">Empty</span>
        <button class="pos-cart-clear-btn" onclick="clearCart()" title="Clear order"><i class="fa-solid fa-trash"></i></button>
      </div>
    </div>

    <div class="pos-cart-body">
      <div class="pos-cart-items" id="pos-cart-items">
        <div class="pos-cart-empty" id="pos-empty-msg">
          <i class="fa-solid fa-mug-hot"></i>
          <span>Tap products to add</span>
        </div>
      </div>
      <div class="pos-totals" id="pos-totals-block" style="display:none">
        <div class="pos-totals-line">
          <span class="lbl">Subtotal</span>
          <span class="val" id="tot-subtotal">₱0.00</span>
        </div>
        <div class="pos-totals-line">
          <span class="lbl">VAT (12%, incl.)</span>
          <span class="val" id="tot-vat">₱0.00</span>
        </div>
        <div class="pos-totals-line" id="tot-discount-row" style="display:none">
          <span class="lbl" id="tot-discount-lbl">Discount (20%)</span>
          <span class="val discount" id="tot-discount">−₱0.00</span>
        </div>
        <div class="pos-totals-line total-final">
          <span class="lbl">Total</span>
          <span class="val" id="pos-total">₱0.00</span>
        </div>
      </div>
      <div class="discount-row">
        <span class="discount-label">Discount</span>
        <div class="discount-pills">
          <button class="discount-pill active" data-dtype="none" onclick="setDiscount('none')">None</button>
          <button class="discount-pill pwd" data-dtype="pwd" onclick="setDiscount('pwd')">PWD</button>
          <button class="discount-pill senior" data-dtype="senior" onclick="setDiscount('senior')">Senior</button>
        </div>
      </div>
    </div>

    <div class="pos-cart-footer">
      <button class="pos-process-btn" id="pos-process-btn" disabled onclick="openCashModal()">
        <i class="fa-solid fa-money-bill-wave"></i> Enter Payment &amp; Process
      </button>
    </div>
  </div>
</div>

<button class="pos-cart-fab hidden" id="pos-cart-fab" onclick="toggleMobileCart()">
  <i class="fa-solid fa-receipt"></i>
  <span id="fab-label">Cart</span>
  <span id="fab-chip" style="background:var(--primary-color);color:#fff;font-size:0.62rem;font-weight:800;padding:2px 7px;border-radius:var(--radius-full);margin-left:4px"></span>
</button>

<!-- Payment Modal -->
<div class="cash-modal-overlay" id="cash-modal-overlay">
  <div class="cash-modal">
    <div class="cash-modal-header">
      <div class="cash-modal-title"><i class="fa-solid fa-coins"></i> Payment</div>
      <button class="cash-modal-close" onclick="closeCashModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="cash-modal-due">
      <div class="cash-modal-due-inner">
        <span class="cash-modal-due-lbl">Amount Due</span>
        <span class="cash-modal-due-val" id="cash-modal-due-val">₱0.00</span>
      </div>
    </div>

    <!-- Payment method tabs -->
    <div class="pay-tabs">
      <button class="pay-tab active-cash" id="pay-tab-cash" onclick="switchPayTab('cash')">
        <i class="fa-solid fa-money-bill-wave"></i> Cash
      </button>
      <button class="pay-tab" id="pay-tab-gcash" onclick="switchPayTab('GCash')">
        <i class="fa-solid fa-mobile-screen-button"></i> GCash
      </button>
    </div>

    <!-- Cash panel -->
    <div class="cash-modal-body" id="pay-panel-cash">
      <div class="cash-input-wrap">
        <input
          type="number"
          id="cash-input"
          class="cash-input-field"
          placeholder="0.00"
          min="0"
          step="0.01"
          autocomplete="off"
          oninput="calcChange()"
          onclick="this.select()">
        <div class="cash-quick-btns" id="cash-quick-btns"></div>
        <div class="cash-change-row" id="cash-change-row">
          <span class="cash-change-lbl">Change</span>
          <span class="cash-change-val" id="pos-change-amount">—</span>
        </div>
      </div>
    </div>

    <!-- GCash panel -->
    <div class="cash-modal-body" id="pay-panel-gcash" style="display:none">
      <div class="gcash-panel">
        <div>
          <div class="gcash-ref-label">GCash Reference Number</div>
          <input
            type="text"
            id="gcash-ref-input"
            class="gcash-ref-input"
            placeholder="e.g. 1234567890"
            maxlength="50"
            autocomplete="off"
            oninput="onGcashRefInput()"
            onclick="this.select()">
        </div>
        <div class="gcash-hint">
          <i class="fa-solid fa-circle-info"></i>
          <span>Ask the customer to show their GCash transaction screenshot and type the reference number shown.</span>
        </div>
      </div>
    </div>

    <div class="cash-modal-footer">
      <button type="button" class="cash-modal-confirm-btn" id="cash-confirm-btn" disabled onclick="submitOrder()">
        <i class="fa-solid fa-check-circle"></i> <span id="confirm-btn-label">Process Order</span>
      </button>
    </div>
  </div>
</div>

<script>
  const posProductImages = <?php
                            $images = [];
                            foreach ($allProducts as $p) {
                              if (!empty($p['image_path']) && file_exists(UPLOAD_DIR . $p['image_path'])) {
                                $images[$p['id']] = $imgBase . e($p['image_path']);
                              }
                            }
                            echo json_encode($images, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                            ?>;
</script>

<script>
  function e(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  let cart = {};
  let _discountType = 'none';
  let _payMethod = 'cash'; // 'cash' or 'GCash'

  function getSubtotal() {
    return Object.values(cart).reduce((s, i) => s + i.price * i.qty, 0);
  }

  function getFinancials(subtotal) {
    const vat = subtotal * 12 / 112;
    const vatExcl = subtotal - vat;
    const discount = _discountType !== 'none' ? vatExcl * 0.20 : 0;
    const total = subtotal - discount;
    return {
      subtotal,
      vat,
      discount,
      total
    };
  }

  function setDiscount(type) {
    _discountType = type;
    document.querySelectorAll('.discount-pill').forEach(p => p.classList.toggle('active', p.dataset.dtype === type));
    renderCart();
    if (_payMethod === 'cash') calcChange();
    else onGcashRefInput();
  }

  // ── Payment tab switching ──────────────────────────────────────────────────
  function switchPayTab(method) {
    _payMethod = method;

    const tabCash = document.getElementById('pay-tab-cash');
    const tabGcash = document.getElementById('pay-tab-gcash');
    const panelCash = document.getElementById('pay-panel-cash');
    const panelGcash = document.getElementById('pay-panel-gcash');
    const confirmBtn = document.getElementById('cash-confirm-btn');
    const confirmLbl = document.getElementById('confirm-btn-label');

    if (method === 'cash') {
      tabCash.className = 'pay-tab active-cash';
      tabGcash.className = 'pay-tab';
      panelCash.style.display = '';
      panelGcash.style.display = 'none';
      confirmBtn.classList.remove('gcash-btn');
      calcChange();
      setTimeout(() => document.getElementById('cash-input').focus(), 80);
    } else {
      tabCash.className = 'pay-tab';
      tabGcash.className = 'pay-tab active-gcash';
      panelCash.style.display = 'none';
      panelGcash.style.display = '';
      confirmBtn.classList.add('gcash-btn');
      onGcashRefInput();
      setTimeout(() => document.getElementById('gcash-ref-input').focus(), 80);
    }
  }

  // ── GCash reference validation ─────────────────────────────────────────────
  function onGcashRefInput() {
    const ref = document.getElementById('gcash-ref-input').value.trim();
    const confirmBtn = document.getElementById('cash-confirm-btn');
    const confirmLbl = document.getElementById('confirm-btn-label');
    const valid = ref.length >= 6;
    confirmBtn.disabled = !valid;
    confirmLbl.textContent = valid ?
      'Confirm GCash Payment' :
      'Enter Reference Number';
  }

  // ── Render cart ────────────────────────────────────────────────────────────
  function renderCart() {
    const keys = Object.keys(cart);
    const el = document.getElementById('pos-cart-items');

    const qtyById = {};
    Object.values(cart).forEach(item => {
      qtyById[item.productId] = (qtyById[item.productId] || 0) + item.qty;
    });
    document.querySelectorAll('.pos-card').forEach(card => {
      const qty = qtyById[card.dataset.id] || 0;
      const badge = document.getElementById('pbadge-' + card.dataset.id);
      if (badge) badge.textContent = qty;
      card.classList.toggle('in-cart', qty > 0);
    });

    const chip = document.getElementById('pos-item-chip');
    chip.textContent = keys.length > 0 ? keys.length + (keys.length === 1 ? ' item' : ' items') : 'Empty';

    if (keys.length === 0) {
      el.innerHTML = '<div class="pos-cart-empty"><i class="fa-solid fa-mug-hot"></i><span>Tap products to add</span></div>';
      document.getElementById('pos-totals-block').style.display = 'none';
      document.getElementById('pos-total').textContent = '₱0.00';
      document.getElementById('pos-process-btn').disabled = true;
      return;
    }

    let html = '';
    keys.forEach(key => {
      const item = cart[key];
      const sub = item.price * item.qty;
      const posImg = posProductImages[item.productId];
      const thumbHtml = posImg ?
        '<img src="' + posImg + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">' :
        '<span class="pos-item-thumb-icon"><i class="fa-solid fa-mug-hot"></i></span>';
      const noteHtml = item.note ? '<div class="pos-item-note">' + item.note + '</div>' : '';

      html +=
        '<div class="pos-item" id="posrow-' + key + '">' +
        '<div class="pos-item-thumb">' + thumbHtml + '</div>' +
        '<div class="pos-item-main">' +
        '<div class="pos-item-name">' + item.name + '</div>' + noteHtml +
        '<div class="pos-item-unit-price">₱' + item.price.toFixed(2) + ' each</div>' +
        '<div class="pos-item-stepper">' +
        '<button class="pos-qty-btn minus" onclick="updateQty(\'' + key + '\',-1)">−</button>' +
        '<input class="pos-qty-input" type="number" min="1" max="999" value="' + item.qty + '" ' +
        'onchange="setQty(\'' + key + '\',this.value)" onblur="setQty(\'' + key + '\',this.value)" onclick="this.select()">' +
        '<button class="pos-qty-btn" onclick="updateQty(\'' + key + '\',1)">+</button>' +
        '</div>' +
        '</div>' +
        '<div class="pos-item-right">' +
        '<div class="pos-item-sub">₱' + sub.toFixed(2) + '</div>' +
        '<button class="pos-item-remove" onclick="removeItem(\'' + key + '\')" title="Remove"><i class="fa-solid fa-xmark"></i></button>' +
        '</div>' +
        '</div>';
    });
    el.innerHTML = html;

    const fin = getFinancials(getSubtotal());
    document.getElementById('pos-totals-block').style.display = '';
    document.getElementById('tot-subtotal').textContent = '₱' + fin.subtotal.toFixed(2);
    document.getElementById('tot-vat').textContent = '₱' + fin.vat.toFixed(2);

    const discRow = document.getElementById('tot-discount-row');
    if (fin.discount > 0) {
      discRow.style.display = '';
      document.getElementById('tot-discount-lbl').textContent = _discountType === 'pwd' ? 'PWD Discount (20%)' : 'Senior Discount (20%)';
      document.getElementById('tot-discount').textContent = '−₱' + fin.discount.toFixed(2);
    } else {
      discRow.style.display = 'none';
    }
    document.getElementById('pos-total').textContent = '₱' + fin.total.toFixed(2);
    document.getElementById('pos-process-btn').disabled = false;

    const dueEl = document.getElementById('cash-modal-due-val');
    if (dueEl) dueEl.textContent = '₱' + fin.total.toFixed(2);

    calcChange();
    updateCartFab();
  }

  function updateQty(key, delta) {
    if (!cart[key]) return;
    cart[key].qty = Math.max(0, cart[key].qty + delta);
    if (cart[key].qty === 0) delete cart[key];
    renderCart();
  }

  function setQty(key, val) {
    const qty = parseInt(val, 10);
    if (isNaN(qty) || qty <= 0) delete cart[key];
    else if (cart[key]) cart[key].qty = qty;
    renderCart();
  }

  function removeItem(key) {
    delete cart[key];
    renderCart();
  }

  function clearCart() {
    cart = {};
    document.getElementById('cash-input').value = '';
    document.getElementById('gcash-ref-input').value = '';
    calcChange();
    renderCart();
  }

  // ── Cash change calculator ─────────────────────────────────────────────────
  function calcChange() {
    const fin = getFinancials(getSubtotal());
    const orderTotal = fin.total;
    const cashInput = document.getElementById('cash-input');
    const cash = parseFloat(cashInput.value) || 0;
    const changeEl = document.getElementById('pos-change-amount');
    const changeRow = document.getElementById('cash-change-row');
    const confirmBtn = document.getElementById('cash-confirm-btn');
    const confirmLbl = document.getElementById('confirm-btn-label');

    if (_payMethod !== 'cash') return;

    if (cash > 0 && Object.keys(cart).length > 0) {
      const diff = Math.round((cash - orderTotal) * 100) / 100;
      if (diff >= 0) {
        changeEl.textContent = '₱' + diff.toFixed(2);
        changeEl.className = 'cash-change-val ok';
        changeRow.className = 'cash-change-row ok-bg';
        confirmBtn.disabled = false;
        confirmBtn.classList.remove('gcash-btn');
        confirmLbl.textContent = 'Process Order — Change ₱' + diff.toFixed(2);
      } else {
        changeEl.textContent = '−₱' + Math.abs(diff).toFixed(2);
        changeEl.className = 'cash-change-val short';
        changeRow.className = 'cash-change-row short-bg';
        confirmBtn.disabled = true;
        confirmLbl.textContent = 'Short ₱' + Math.abs(diff).toFixed(2);
      }
    } else {
      changeEl.textContent = '—';
      changeEl.className = 'cash-change-val';
      changeRow.className = 'cash-change-row';
      confirmBtn.disabled = true;
      confirmLbl.textContent = 'Enter Cash Amount';
    }

    const processEl = document.getElementById('pos-process-btn');
    if (processEl) processEl.disabled = Object.keys(cart).length === 0;
  }

  // ── Open / close payment modal ─────────────────────────────────────────────
  function openCashModal() {
    if (Object.keys(cart).length === 0) return;
    const fin = getFinancials(getSubtotal());
    const total = fin.total;
    document.getElementById('cash-modal-due-val').textContent = '₱' + total.toFixed(2);

    // Reset both panels
    document.getElementById('cash-input').value = '';
    document.getElementById('gcash-ref-input').value = '';

    // Default to cash tab
    switchPayTab('cash');

    // Build quick-amount buttons
    const bills = [20, 50, 100, 200, 500, 1000];
    const quickAmounts = [Math.ceil(total)];
    bills.forEach(b => {
      if (b > total && quickAmounts.length < 4) quickAmounts.push(b);
    });
    let next = Math.ceil(total / 100) * 100;
    while (quickAmounts.length < 4) {
      if (!quickAmounts.includes(next)) quickAmounts.push(next);
      next += 100;
    }
    document.getElementById('cash-quick-btns').innerHTML = quickAmounts.slice(0, 4).map(amt =>
      `<button type="button" class="cash-quick-btn" onclick="document.getElementById('cash-input').value='${amt}';calcChange();">₱${amt.toFixed(0)}</button>`
    ).join('');

    calcChange();
    document.getElementById('cash-modal-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('cash-input').focus(), 100);
  }

  function closeCashModal() {
    document.getElementById('cash-modal-overlay').classList.remove('open');
    document.body.style.overflow = '';
  }

  document.getElementById('cash-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeCashModal();
  });

  // ── Submit order ───────────────────────────────────────────────────────────
  function submitOrder() {
    if (Object.keys(cart).length === 0) return;

    const fin = getFinancials(getSubtotal());
    const items = Object.values(cart).map(item => ({
      product_id: item.productId,
      qty: item.qty,
      price: item.price,
      note: item.note || ''
    }));

    if (_payMethod === 'cash') {
      const cash = parseFloat(document.getElementById('cash-input').value) || 0;
      if (cash < fin.total) return;
      document.getElementById('cash-data').value = cash;
      document.getElementById('denom-data').value = JSON.stringify({});
    } else {
      const ref = document.getElementById('gcash-ref-input').value.trim();
      if (!ref) return;
      document.getElementById('cash-data').value = fin.total; // backend uses this for GCash
      document.getElementById('denom-data').value = JSON.stringify({});
      document.getElementById('gcash-ref-data').value = ref;
    }

    document.getElementById('cart-data').value = JSON.stringify(items);
    document.getElementById('pay-method-data').value = _payMethod;
    document.getElementById('discount-type-data').value = _discountType;

    closeCashModal();
    document.getElementById('order-form').submit();
  }

  // ── Mobile cart toggle ─────────────────────────────────────────────────────
  function toggleMobileCart() {
    const cart = document.getElementById('pos-cart');
    const fab = document.getElementById('pos-cart-fab');
    const isOpen = cart.classList.toggle('cart-open');
    if (fab) fab.querySelector('span:first-of-type').textContent = isOpen ? 'Close' : 'Cart';
  }

  function updateCartFab() {
    const fab = document.getElementById('pos-cart-fab');
    if (!fab) return;
    const count = Object.keys(cart).length;
    if (window.innerWidth <= 768) {
      fab.classList.toggle('hidden', false);
      const chip = document.getElementById('fab-chip');
      if (chip) chip.textContent = count > 0 ? count : '';
    } else {
      fab.classList.add('hidden');
    }
  }
  window.addEventListener('resize', updateCartFab);

  // ── Category filter ────────────────────────────────────────────────────────
  let activeType = '';
  let activeId = '';
  let searchQuery = '';

  document.getElementById('tier-1').addEventListener('click', e => {
    const pill = e.target.closest('.cat-pill');
    if (!pill) return;

    document.querySelectorAll('#tier-1 .cat-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    activeType = pill.dataset.type;
    activeId = pill.dataset.id;

    const subPills = document.querySelectorAll('.sub-pill');
    const subContainer = document.getElementById('tier-2');
    subPills.forEach(sp => sp.classList.remove('active'));

    if (activeType === 'group') {
      let hasChildren = false;
      subPills.forEach(sp => {
        if (sp.dataset.parent === activeId) {
          sp.style.display = 'inline-flex';
          hasChildren = true;
        } else sp.style.display = 'none';
      });
      subContainer.classList.toggle('open', hasChildren);
    } else {
      subContainer.classList.remove('open');
      subPills.forEach(sp => sp.style.display = 'none');
    }
    applyFilter();
  });

  document.getElementById('tier-2').addEventListener('click', e => {
    const pill = e.target.closest('.sub-pill');
    if (!pill) return;
    document.querySelectorAll('.sub-pill').forEach(p => p.classList.remove('active'));

    if (activeId === pill.dataset.id && activeType === 'subcat') {
      activeType = 'group';
      activeId = pill.dataset.parent;
    } else {
      pill.classList.add('active');
      activeType = 'subcat';
      activeId = pill.dataset.id;
    }
    applyFilter();
  });

  document.getElementById('pos-search').addEventListener('input', e => {
    searchQuery = e.target.value.trim().toLowerCase();
    applyFilter();
  });

  activeType = 'all';
  activeId = 'all';
  applyFilter();

  function applyFilter() {
    let visible = 0;
    document.querySelectorAll('#pos-grid .pos-card').forEach(card => {
      let catMatch = false;
      if (activeType === 'all') catMatch = true;
      else if (activeType === 'group') catMatch = card.dataset.parent === activeId;
      else if (activeType === 'cat' || activeType === 'subcat') catMatch = card.dataset.cat === activeId;

      const show = catMatch && (searchQuery === '' || card.dataset.name.includes(searchQuery));
      card.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    document.getElementById('pos-no-results').style.display = visible === 0 ? '' : 'none';
  }
</script>

<style>
  .pm-overlay {
    position: fixed;
    inset: 0;
    z-index: 400;
    background: rgba(0, 0, 0, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-5);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
  }

  .pm-overlay.open {
    opacity: 1;
    pointer-events: all;
  }

  .pm-dialog {
    background: var(--surface-color);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    width: 100%;
    max-width: 460px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    transform: scale(0.94) translateY(12px);
    transition: transform 0.22s cubic-bezier(0.34, 1.1, 0.64, 1);
    overflow: hidden;
  }

  .pm-overlay.open .pm-dialog {
    transform: scale(1) translateY(0);
  }

  .pm-header {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
  }

  .pm-thumb {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-sm);
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    overflow: hidden;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .pm-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .pm-thumb-icon {
    font-size: 19px;
    color: var(--border-strong);
  }

  .pm-header-text {
    flex: 1;
    min-width: 0;
  }

  .pm-product-name {
    font-size: 0.94rem;
    font-weight: 800;
    color: var(--text-color);
  }

  .pm-base-price {
    font-size: 0.73rem;
    color: var(--text-muted);
    margin-top: 2px;
  }

  .pm-close-btn {
    width: 28px;
    height: 28px;
    border-radius: var(--radius-full);
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 12px;
    transition: all var(--transition-fast);
    flex-shrink: 0;
  }

  .pm-close-btn:hover {
    background: var(--status-cancelled-bg);
    color: var(--status-cancelled);
  }

  .pm-body {
    flex: 1;
    overflow-y: auto;
    padding: var(--space-4) var(--space-5);
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .pm-section-label {
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.11em;
    color: var(--text-muted);
    margin-bottom: var(--space-2);
  }

  .pm-size-group {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-2);
  }

  .pm-size-btn {
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: var(--space-2);
    text-align: center;
    cursor: pointer;
    background: var(--surface-color);
    transition: all var(--transition-fast);
    font-family: inherit;
  }

  .pm-size-btn:hover,
  .pm-size-btn.active {
    border-color: var(--primary-color);
    background: var(--primary-subtle);
  }

  .pm-size-name {
    font-size: 0.80rem;
    font-weight: 700;
    color: var(--text-color);
    display: block;
  }

  .pm-size-adj {
    font-size: 0.68rem;
    color: var(--text-muted);
    display: block;
    margin-top: 1px;
  }

  .pm-size-btn.active .pm-size-name,
  .pm-size-btn.active .pm-size-adj {
    color: var(--primary-color);
  }

  .pm-sugar-group {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .pm-sugar-btn {
    height: 30px;
    padding: 0 12px;
    border-radius: var(--radius-full);
    border: 1.5px solid var(--border-color);
    background: var(--surface-color);
    font-size: 0.76rem;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    transition: all var(--transition-fast);
    font-family: inherit;
  }

  .pm-sugar-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
  }

  .pm-sugar-btn.active {
    background: var(--primary-color);
    color: var(--text-on-primary);
    border-color: var(--primary-color);
  }

  .pm-addon-grid {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .pm-addon-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-2) var(--space-3);
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-xs);
    cursor: pointer;
    transition: all var(--transition-fast);
  }

  .pm-addon-row:hover,
  .pm-addon-row:has(input:checked) {
    border-color: var(--primary-color);
    background: var(--primary-subtle);
  }

  .pm-addon-row input {
    display: none;
  }

  .pm-addon-check {
    width: 18px;
    height: 18px;
    border-radius: 4px;
    border: 2px solid var(--border-color);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    color: transparent;
    transition: all var(--transition-fast);
  }

  .pm-addon-row:has(input:checked) .pm-addon-check {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: var(--text-on-primary);
  }

  .pm-addon-name {
    flex: 1;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text-color);
  }

  .pm-addon-price {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--primary-color);
  }

  .pm-no-addons {
    font-size: 0.76rem;
    color: var(--text-muted);
    font-style: italic;
  }

  .pm-notes {
    width: 100%;
    padding: var(--space-2) var(--space-3);
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 0.82rem;
    font-family: inherit;
    color: var(--text-color);
    background: var(--surface-color);
    outline: none;
    resize: none;
    transition: border-color var(--transition-fast);
  }

  .pm-notes:focus {
    border-color: var(--primary-color);
  }

  .pm-notes::placeholder {
    color: var(--text-placeholder);
  }

  .pm-footer {
    padding: var(--space-3) var(--space-5) var(--space-4);
    border-top: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: var(--space-3);
    flex-shrink: 0;
  }

  .pm-qty-ctrl {
    display: flex;
    align-items: center;
    gap: 2px;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 2px;
    background: var(--surface-raised);
    flex-shrink: 0;
  }

  .pm-qty-btn {
    width: 30px;
    height: 30px;
    border: none;
    border-radius: calc(var(--radius-sm) - 2px);
    background: transparent;
    font-size: 15px;
    font-weight: 700;
    font-family: inherit;
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast);
  }

  .pm-qty-btn:hover {
    background: var(--primary-color);
    color: var(--text-on-primary);
  }

  .pm-qty-num {
    width: 34px;
    text-align: center;
    border: none;
    background: transparent;
    font-size: 0.88rem;
    font-weight: 700;
    font-family: inherit;
    color: var(--text-color);
    outline: none;
  }

  .pm-add-btn {
    flex: 1;
    height: 42px;
    background: var(--primary-color);
    color: var(--text-on-primary);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.88rem;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-3);
    box-shadow: var(--shadow-primary);
    transition: all var(--transition-fast);
  }

  .pm-add-btn:hover {
    background: var(--primary-dark);
  }

  .pm-price-col {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    line-height: 1.2;
  }

  .pm-unit-lbl {
    font-size: 0.62rem;
    opacity: 0.75;
  }

  .pm-total-lbl {
    font-size: 0.92rem;
    font-weight: 800;
  }
</style>

<div class="pm-overlay" id="pos-modal">
  <div class="pm-dialog">
    <div class="pm-header">
      <div class="pm-thumb">
        <img id="pm-img" src="" alt="" style="display:none">
        <span id="pm-icon" class="pm-thumb-icon"><i class="fa-solid fa-mug-hot"></i></span>
      </div>
      <div class="pm-header-text">
        <div class="pm-product-name" id="pm-name"></div>
        <div class="pm-base-price">Base: <span id="pm-base"></span></div>
      </div>
      <button class="pm-close-btn" onclick="closePosModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="pm-body">
      <div id="pm-section-size">
        <div class="pm-section-label">Size</div>
        <div class="pm-size-group">
          <?php foreach ([['16oz', 0], ['22oz', SIZE_22OZ_UPCHARGE]] as [$sz, $adj]): ?>
            <button class="pm-size-btn" onclick="document.querySelectorAll('.pm-size-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');posRecalc();">
              <span class="pm-size-name"><?= $sz ?></span>
              <span class="pm-size-adj"><?= $adj > 0 ? '+₱' . $adj : ($adj < 0 ? '−₱' . abs($adj) : 'Base') ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="pm-section-sugar">
        <div class="pm-section-label">Sugar Level</div>
        <div class="pm-sugar-group">
          <?php foreach (['Full Sugar', 'Less Sugar', '50% Sugar', 'No Sugar'] as $sl): ?>
            <button class="pm-sugar-btn" onclick="document.querySelectorAll('.pm-sugar-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');"><?= $sl ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="pm-section-addons">
        <div class="pm-section-label">Add-ons</div>
        <div id="pm-addons-container">
          <div class="pm-no-addons">No add-ons available.</div>
        </div>
      </div>
      <div>
        <div class="pm-section-label">Special Instructions</div>
        <textarea id="pm-notes" class="pm-notes" rows="2" placeholder="extra hot, no whip, etc."></textarea>
      </div>
    </div>
    <div class="pm-footer">
      <div class="pm-qty-ctrl">
        <button class="pm-qty-btn" onclick="const i=document.getElementById('pm-qty');i.value=Math.max(1,parseInt(i.value||1)-1);posRecalc();">−</button>
        <input type="number" id="pm-qty" class="pm-qty-num" value="1" min="1" max="99" oninput="posRecalc()" onclick="this.select()">
        <button class="pm-qty-btn" onclick="const i=document.getElementById('pm-qty');i.value=Math.min(99,parseInt(i.value||1)+1);posRecalc();">+</button>
      </div>
      <button class="pm-add-btn" onclick="posAddToCart()">
        <i class="fa-solid fa-cart-plus"></i> Add to Order
        <div class="pm-price-col">
          <span class="pm-unit-lbl">each <span id="pm-unit"></span></span>
          <span class="pm-total-lbl" id="pm-total"></span>
        </div>
      </button>
    </div>
  </div>
</div>

<script>
  const POS_SIZES = [{
    label: '16oz',
    adj: 0
  }, {
    label: '22oz',
    adj: +<?= SIZE_22OZ_UPCHARGE ?>
  }];
  const POS_SUGAR = ['Full Sugar', 'Less Sugar', '50% Sugar', 'No Sugar'];

  let _pos = {
    id: null,
    name: null,
    basePrice: 0,
    imgPath: null,
    hasSizes: false,
    hasSugar: false,
    hasAddons: false,
    addons: []
  };

  function openPosModal(id, name, basePrice, imgPath, hasSizes, hasSugar, hasAddons) {
    _pos = {
      id,
      name,
      basePrice: parseFloat(basePrice),
      imgPath,
      hasSizes: !!hasSizes,
      hasSugar: !!hasSugar,
      hasAddons: !!hasAddons,
      addons: []
    };

    document.getElementById('pm-section-size').style.display = hasSizes ? '' : 'none';
    document.getElementById('pm-section-sugar').style.display = hasSugar ? '' : 'none';
    document.getElementById('pm-section-addons').style.display = hasAddons ? '' : 'none';

    document.getElementById('pm-name').textContent = name;
    document.getElementById('pm-base').textContent = '₱' + parseFloat(basePrice).toFixed(2);

    const imgEl = document.getElementById('pm-img');
    const iconEl = document.getElementById('pm-icon');
    if (imgPath) {
      imgEl.src = '<?php echo $imgBase; ?>' + imgPath;
      imgEl.style.display = '';
      iconEl.style.display = 'none';
    } else {
      imgEl.style.display = 'none';
      iconEl.style.display = '';
    }

    document.querySelectorAll('.pm-size-btn').forEach((b, i) => b.classList.toggle('active', i === 0));
    document.querySelectorAll('.pm-sugar-btn').forEach((b, i) => b.classList.toggle('active', i === 0));
    document.getElementById('pm-notes').value = '';
    document.getElementById('pm-qty').value = 1;

    document.getElementById('pm-addons-container').innerHTML = `
      <div style="text-align:center;padding:1rem;color:var(--text-muted)">
        <i class="fa-solid fa-circle-notch fa-spin" style="font-size:1.2rem"></i>
        <div style="margin-top:0.5rem;font-size:0.8rem">Loading...</div>
      </div>`;

    if (hasAddons) {
      fetch(`<?= APP_URL ?>/api/get_product_addons.php?product_id=${id}`)
        .then(r => r.json())
        .then(data => {
          if (data.success && data.addons.length > 0) {
            _pos.addons = data.addons;
            renderAddonsList(data.addons);
          } else {
            document.getElementById('pm-section-addons').style.display = 'none';
          }
        })
        .catch(() => {
          document.getElementById('pm-section-addons').style.display = 'none';
        });
    }

    posRecalc();
    document.getElementById('pos-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function renderAddonsList(addons) {
    const container = document.getElementById('pm-addons-container');
    if (!addons.length) {
      container.innerHTML = '<div class="pm-no-addons">No add-ons available for this product.</div>';
      return;
    }
    let html = '<div class="pm-addon-grid">';
    addons.forEach(addon => {
      html += `<label class="pm-addon-row">
        <input type="checkbox" class="pm-addon-cb" data-name="${e(addon.name)}" data-price="${addon.price}" onchange="posRecalc()">
        <div class="pm-addon-check"><i class="fa-solid fa-check"></i></div>
        <span class="pm-addon-name">${e(addon.name)}</span>
        <span class="pm-addon-price">+₱${parseFloat(addon.price).toFixed(2)}</span>
      </label>`;
    });
    html += '</div>';
    container.innerHTML = html;
  }

  function closePosModal() {
    document.getElementById('pos-modal').classList.remove('open');
    document.body.style.overflow = '';
  }
  document.getElementById('pos-modal').addEventListener('click', function(e) {
    if (e.target === this) closePosModal();
  });

  /* ── Speed path: a product with no size/sugar/add-on options needs no
     modal at all — one tap adds it straight to the cart. This matters
     for a till: most of what a cashier rings up (bottled drinks,
     pastries, snacks) has nothing to configure, so forcing every item
     through the customize modal costs real time over a shift. ── */
  function quickAddToCart(id, name, price) {
    const key = id + '|plain';
    if (!cart[key]) cart[key] = {
      productId: id,
      name,
      price: parseFloat(price),
      note: '',
      qty: 0
    };
    cart[key].qty += 1;

    const card = document.getElementById('pcard-' + id);
    if (card) {
      card.style.transition = 'transform 0.12s';
      card.style.transform = 'scale(0.94)';
      setTimeout(() => {
        card.style.transform = '';
      }, 120);
    }
    renderCart();
  }

  function posRecalc() {
    const sizeIdx = [...document.querySelectorAll('.pm-size-btn')].findIndex(b => b.classList.contains('active'));
    const sizeAdj = _pos.hasSizes ? (POS_SIZES[sizeIdx]?.adj ?? 0) : 0;
    let addonTotal = 0;
    if (_pos.hasAddons && _pos.addons.length > 0)
      document.querySelectorAll('.pm-addon-cb:checked').forEach(cb => addonTotal += parseFloat(cb.dataset.price));
    const unitPrice = _pos.basePrice + sizeAdj + addonTotal;
    const qty = Math.max(1, parseInt(document.getElementById('pm-qty').value) || 1);
    document.getElementById('pm-unit').textContent = '₱' + unitPrice.toFixed(2);
    document.getElementById('pm-total').textContent = '₱' + (unitPrice * qty).toFixed(2);
  }

  function posAddToCart() {
    const sizeIdx = [...document.querySelectorAll('.pm-size-btn')].findIndex(b => b.classList.contains('active'));
    const sugarIdx = [...document.querySelectorAll('.pm-sugar-btn')].findIndex(b => b.classList.contains('active'));
    const size = _pos.hasSizes ? (POS_SIZES[sizeIdx]?.label ?? 'Medium') : null;
    const sizeAdj = _pos.hasSizes ? (POS_SIZES[sizeIdx]?.adj ?? 0) : 0;
    const sugar = _pos.hasSugar ? (POS_SUGAR[sugarIdx] ?? 'Full Sugar') : null;
    const qty = Math.max(1, parseInt(document.getElementById('pm-qty').value) || 1);
    const notes = document.getElementById('pm-notes').value.trim();

    const chosenAddons = [];
    let addonTotal = 0;
    if (_pos.hasAddons && _pos.addons.length > 0) {
      document.querySelectorAll('.pm-addon-cb:checked').forEach(cb => {
        chosenAddons.push(cb.dataset.name);
        addonTotal += parseFloat(cb.dataset.price);
      });
    }

    const finalPrice = _pos.basePrice + sizeAdj + addonTotal;
    const keyParts = [_pos.id];
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
    const note = noteParts.join(' · ');

    if (!cart[key]) cart[key] = {
      productId: _pos.id,
      name: _pos.name,
      price: finalPrice,
      note,
      qty: 0
    };
    cart[key].qty += qty;

    const card = document.getElementById('pcard-' + _pos.id);
    if (card) {
      card.style.transition = 'transform 0.12s';
      card.style.transform = 'scale(0.94)';
      setTimeout(() => {
        card.style.transform = '';
      }, 120);
    }

    renderCart();
    closePosModal();
  }
</script>

<?php layoutFooter(); ?>