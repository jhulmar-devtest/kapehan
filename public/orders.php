<?php
// ============================================================
// public/orders.php
//
// "My Orders" — reached from the profile dropdown on menu.php and
// from the sidebar on account.php. Not in a subfolder, same as
// account.php, since one file serves both students and faculty.
//
// Replaces public/student/orders.php and public/faculty/orders.php,
// merging their feedback-submission and reorder logic into one file.
// ============================================================

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/refund-qr.php';
requireRole(ROLE_STUDENT, ROLE_FACULTY);

$db       = Database::getInstance();
$role     = currentRole();
$userId   = currentUserId();
$isStudent = $role === ROLE_STUDENT;
$fkColumn  = $isStudent ? 'student_id' : 'faculty_id';

// ── AJAX: distinct products in a claimed order (used by Rate modal) ──
if (isset($_GET['get_rate_items'])) {
  header('Content-Type: application/json');
  $oid = (int) $_GET['get_rate_items'];
  $stmt = $db->prepare(
    "SELECT DISTINCT od.product_id, p.name
       FROM order_details od
       JOIN orders o ON o.id = od.order_id
       JOIN products p ON p.id = od.product_id
      WHERE od.order_id = ? AND o.{$fkColumn} = ? AND o.status = 'claimed'
      ORDER BY p.name"
  );
  $stmt->execute([$oid, $userId]);
  echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

// ── AJAX: return order items as JSON (used by Reorder) ──────
if (isset($_GET['get_order_items'])) {
  header('Content-Type: application/json');
  $oid = (int) $_GET['get_order_items'];

  $chk = $db->prepare("SELECT id FROM orders WHERE id = ? AND {$fkColumn} = ?");
  $chk->execute([$oid, $userId]);
  if (!$chk->fetch()) {
    echo json_encode(['items' => []]);
    exit;
  }

  $stmt = $db->prepare(
    "SELECT od.id AS detail_id, od.product_id, od.quantity, od.price_at_time, p.name, p.image_path,
            p.price AS base_price, p.has_sizes, p.has_sugar, p.is_available,
            od.customization_note AS note
     FROM order_details od
     JOIN products p ON od.product_id = p.id
     WHERE od.order_id = ?"
  );
  $stmt->execute([$oid]);
  $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $activeSizes = $db->query('SELECT label, price_adjustment FROM size_options WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
  $sugarOptions = ['Full Sugar', 'Less Sugar', '50% Sugar', 'No Sugar'];

  foreach ($orderItems as &$item) {
    $parts = array_values(array_filter(array_map('trim', explode(' · ', (string) ($item['note'] ?? ''))), static fn($part) => $part !== ''));
    $item['size'] = null;
    $item['sugar'] = null;
    $item['custom_note'] = '';
    $item['addon_ids'] = [];
    $item['addons'] = [];
    $sizeAdjustment = 0.0;

    if ((int) $item['has_sizes'] === 1) {
      foreach ($parts as $index => $part) {
        foreach ($activeSizes as $sizeOption) {
          if ($part === $sizeOption['label']) {
            $item['size'] = $sizeOption['label'];
            $sizeAdjustment = (float) $sizeOption['price_adjustment'];
            unset($parts[$index]);
            break 2;
          }
        }
      }
      if ($item['size'] === null && $activeSizes) {
        $item['size'] = $activeSizes[0]['label'];
        $sizeAdjustment = (float) $activeSizes[0]['price_adjustment'];
      }
    }

    if ((int) $item['has_sugar'] === 1) {
      foreach ($parts as $index => $part) {
        if (in_array($part, $sugarOptions, true)) {
          $item['sugar'] = $part;
          unset($parts[$index]);
          break;
        }
      }
      if ($item['sugar'] === null) $item['sugar'] = $sugarOptions[0];
    }

    try {
      $addonStmt = $db->prepare(
        'SELECT a.id, a.name, a.price
           FROM addons a JOIN product_addons pa ON pa.addon_id = a.id
          WHERE pa.product_id = ? AND a.status = \'active\'
            AND EXISTS (SELECT 1 FROM addon_ingredients ai WHERE ai.addon_id = a.id)
          ORDER BY a.id'
      );
      $addonStmt->execute([(int) $item['product_id']]);
      $availableAddons = $addonStmt->fetchAll(PDO::FETCH_ASSOC);
      $savedAddonStmt = $db->prepare('SELECT addon_id, addon_name_snapshot FROM order_detail_addons WHERE order_detail_id = ? ORDER BY id');
      $savedAddonStmt->execute([(int) $item['detail_id']]);
      $savedAddons = $savedAddonStmt->fetchAll(PDO::FETCH_ASSOC);

      $savedAddonNames = array_column($savedAddons, 'addon_name_snapshot');
    } catch (PDOException $e) {
      // Older installations may not have the add-on snapshot table yet.
      $availableAddons = [];
      $savedAddonNames = [];
    }

    $requestedAddonNames = $savedAddonNames;
    foreach ($parts as $index => $part) {
      if (str_starts_with($part, '+')) {
        $requestedAddonNames = array_merge($requestedAddonNames, preg_split('/\\s*,\\s*\\+/', ltrim($part, '+')) ?: []);
        unset($parts[$index]);
      }
    }
    $addonTotal = 0.0;
    foreach ($availableAddons as $availableAddon) {
      if (in_array($availableAddon['name'], $requestedAddonNames, true)) {
        $item['addon_ids'][] = (int) $availableAddon['id'];
        $item['addons'][] = $availableAddon['name'];
        $addonTotal += (float) $availableAddon['price'];
      }
    }
    $item['unit_price'] = round((float) $item['base_price'] + $sizeAdjustment + $addonTotal, 2);

    foreach ($parts as $part) {
      $item['custom_note'] = trim($item['custom_note'] . ' ' . $part);
    }
    $item['custom_note'] = trim($item['custom_note']);
    unset($item['detail_id'], $item['price_at_time'], $item['base_price'], $item['has_sugar']);
  }
  unset($item);
  echo json_encode(['items' => $orderItems]);
  exit;
}

// ── Handle feedback submission ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
  verifyCsrf();
  $orderId        = (int) ($_POST['order_id'] ?? 0);
  $cashierRating  = (int) ($_POST['rating'] ?? 0);
  $comment        = sanitizeString($_POST['comment'] ?? '', 500);
  $productRatings = is_array($_POST['product_rating'] ?? null) ? $_POST['product_rating'] : [];

  if ($cashierRating < 1 || $cashierRating > 5) {
    flash('global', 'Please select an overall star rating.', 'error');
    redirect(APP_URL . '/orders.php');
  }

  $stmt = $db->prepare(
    "SELECT o.id, o.cashier_id FROM orders o WHERE o.id = ? AND o.{$fkColumn} = ? AND o.status = 'claimed'"
  );
  $stmt->execute([$orderId, $userId]);
  $order = $stmt->fetch();
  if (!$order) {
    flash('global', 'Order not found or not yet claimed.', 'error');
    redirect(APP_URL . '/orders.php');
  }

  $stmt = $db->prepare("SELECT id FROM order_feedback WHERE order_id = ?");
  $stmt->execute([$orderId]);
  if ($stmt->fetch()) {
    flash('global', 'You have already rated this order.', 'error');
    redirect(APP_URL . '/orders.php');
  }

  $stmt = $db->prepare("SELECT od.product_id FROM order_details od WHERE od.order_id = ?");
  $stmt->execute([$orderId]);
  $validProductIds = array_column($stmt->fetchAll(), 'product_id');

  $db->beginTransaction();
  try {
    $db->prepare(
      "INSERT INTO order_feedback (order_id, student_id, faculty_id, cashier_id, rating, comment)
       VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
      $orderId,
      $isStudent ? $userId : null,
      $isStudent ? null : $userId,
      $order['cashier_id'] ?: null,
      $cashierRating,
      $comment ?: null,
    ]);
    $feedbackId = (int) $db->lastInsertId();

    if (!empty($productRatings)) {
      $pstmt = $db->prepare(
        "INSERT INTO product_ratings (feedback_id, order_id, product_id, student_id, faculty_id, rating)
         VALUES (?, ?, ?, ?, ?, ?)"
      );
      $ratedProducts = [];
      foreach ($productRatings as $pid => $prating) {
        $pid = (int) $pid;
        $prating = (int) $prating;
        if (!in_array($pid, $validProductIds, true)) continue;
        if ($prating < 1 || $prating > 5) continue;
        // uq_product_order allows one rating per product per order
        if (isset($ratedProducts[$pid])) continue;
        $ratedProducts[$pid] = true;
        $pstmt->execute([
          $feedbackId, $orderId, $pid,
          $isStudent ? $userId : null,
          $isStudent ? null : $userId,
          $prating,
        ]);
      }
    }

    $db->commit();
    flash('global', 'Thank you for your feedback!', 'success');
  } catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    // 23000 = unique violation: a double-submit raced past the "already rated" check
    if ($e instanceof PDOException && $e->getCode() === '23000' && str_contains($e->getMessage(), 'order_id')) {
      flash('global', 'You have already rated this order.', 'error');
    } else {
      error_log($e->getMessage());
      flash('global', 'Could not save feedback. Please try again.', 'error');
    }
  }
  redirect(APP_URL . '/orders.php');
}

// ── Request cancellation of a pre-order before preparation ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_order_cancellation'])) {
  verifyCsrf();
  $orderId = (int) ($_POST['order_id'] ?? 0);
  $reason = sanitizeString($_POST['cancel_reason'] ?? '', 500);
  if ($reason === '') {
    flash('global', 'Tell us why you need to cancel this order.', 'error');
    redirect(APP_URL . '/orders.php');
  }

  $newQrFilename = null;
  $oldQrFilename = null;
  $requestCommitted = false;
  $db->beginTransaction();
  try {
    $stmt = $db->prepare(
      "SELECT o.id, o.order_number, o.order_type, o.status, p.payment_method, p.gateway
         FROM orders o LEFT JOIN payments p ON p.order_id = o.id
        WHERE o.id = ? AND o.{$fkColumn} = ? FOR UPDATE"
    );
    $stmt->execute([$orderId, $userId]);
    $orderToCancel = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$orderToCancel || $orderToCancel['order_type'] !== ORDER_PREORDER || $orderToCancel['status'] !== STATUS_PENDING) {
      throw new RuntimeException('Only pre-orders that are still pending can request cancellation. Please contact the shop if preparation has started.');
    }

    $isManualGcash = strtolower(trim((string) ($orderToCancel['payment_method'] ?? ''))) === 'gcash'
      && ($orderToCancel['gateway'] ?? '') !== 'paymongo';
    $refundGcashName = null;
    $refundGcashNumber = null;
    if ($isManualGcash) {
      $refundGcashName = sanitizeString($_POST['refund_gcash_name'] ?? '', 100);
      $refundGcashNumber = trim((string) ($_POST['refund_gcash_number'] ?? ''));
      if ($refundGcashName === '') throw new RuntimeException('Enter the name on the GCash account that should receive the refund.');
      if (!preg_match('/\A09[0-9]{9}\z/', $refundGcashNumber)) {
        throw new RuntimeException('Enter a valid 11-digit GCash number starting with 09.');
      }
      $newQrFilename = storeRefundQrUpload($_FILES['refund_qr'] ?? null);
    }

    $existing = $db->prepare('SELECT id, status, refund_qr_filename FROM order_cancellation_requests WHERE order_id = ? FOR UPDATE');
    $existing->execute([$orderId]);
    $request = $existing->fetch(PDO::FETCH_ASSOC);
    if ($request && $request['status'] !== 'rejected') {
      throw new RuntimeException('A cancellation request is already being handled for this order.');
    }
    $oldQrFilename = $request['refund_qr_filename'] ?? null;

    if ($request) {
      $db->prepare(
        "UPDATE order_cancellation_requests
            SET requester_role = ?, requester_id = ?, reason = ?, status = 'pending', refund_reference = NULL,
                refund_gcash_name = ?, refund_gcash_number = ?, refund_qr_filename = ?,
                handled_by_cashier_id = NULL, handled_by_admin_id = NULL, handled_at = NULL
          WHERE id = ?"
      )->execute([$role, $userId, $reason, $refundGcashName, $refundGcashNumber, $newQrFilename, $request['id']]);
    } else {
      $db->prepare(
        "INSERT INTO order_cancellation_requests
           (order_id, requester_role, requester_id, reason, refund_gcash_name, refund_gcash_number, refund_qr_filename)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
      )->execute([$orderId, $role, $userId, $reason, $refundGcashName, $refundGcashNumber, $newQrFilename]);
    }
    $db->commit();
    $requestCommitted = true;
    if ($oldQrFilename && $oldQrFilename !== $newQrFilename) deleteRefundQrFile($oldQrFilename);
    auditLog($role, $userId, 'request_order_cancellation', 'orders', $orderId);
    flash('global', 'Cancellation request sent. If a refund is due, the shop can use the GCash details you provided.', 'success');
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if (!$requestCommitted && $newQrFilename) deleteRefundQrFile($newQrFilename);
    flash('global', $e instanceof RuntimeException ? $e->getMessage() : 'Could not send the cancellation request. Please try again.', 'error');
  }
  redirect(APP_URL . '/orders.php');
}

// ── Load orders, filtered by tab ─────────────────────────────
$filter = $_GET['status'] ?? 'all';
$statusGroups = [
  'ongoing'   => ['pending', 'preparing', 'ready'],
  'completed' => ['claimed'],
  'cancelled' => ['cancelled', 'no_show'],
];
$where = '';
$params = [$userId];
if (isset($statusGroups[$filter])) {
  $placeholders = implode(',', array_fill(0, count($statusGroups[$filter]), '?'));
  $where = " AND o.status IN ({$placeholders})";
  $params = array_merge($params, $statusGroups[$filter]);
}

$stmt = $db->prepare(
  "SELECT o.*, p.payment_method, p.payment_status, p.gateway,
          cr.status AS cancellation_request_status, cr.refund_reference,
          f.id AS feedback_id,
          (SELECT COALESCE(SUM(od.quantity),0) FROM order_details od WHERE od.order_id = o.id) AS item_count
   FROM orders o
   LEFT JOIN payments p ON o.id = p.order_id
   LEFT JOIN order_cancellation_requests cr ON cr.order_id = o.id
   LEFT JOIN order_feedback f ON o.id = f.order_id
   WHERE o.{$fkColumn} = ? {$where}
   ORDER BY
     CASE WHEN o.status IN ('pending', 'preparing', 'ready') THEN 0 ELSE 1 END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready') AND o.pickup_time = 'ASAP' THEN 0 ELSE 1 END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready')
          THEN COALESCE(o.pickup_date, DATE(o.created_at)) ELSE NULL END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready') AND o.pickup_time <> 'ASAP'
          THEN COALESCE(STR_TO_DATE(o.pickup_time, '%H:%i'), TIME(o.created_at))
          WHEN o.status IN ('pending', 'preparing', 'ready') THEN TIME(o.created_at)
          ELSE NULL END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready')
          THEN CASE o.status WHEN 'ready' THEN 0 WHEN 'preparing' THEN 1 ELSE 2 END
          ELSE 0 END ASC,
     CASE WHEN o.status IN ('pending', 'preparing', 'ready') THEN o.created_at ELSE NULL END ASC,
     o.created_at DESC"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// An online order still showing "unpaid" may simply be waiting on a webhook
// that never arrived. Ask PayMongo (throttled, newest few only) so a customer
// who really paid sees it here.
$reconcileBudget = 3;
foreach ($orders as &$orderRow) {
  if ($reconcileBudget > 0 && ($orderRow['gateway'] ?? '') === 'paymongo'
      && $orderRow['payment_status'] === PAY_STATUS_PENDING && $orderRow['status'] === STATUS_PENDING) {
    $reconcileBudget--;
    if (paymongoReconcileThrottled($db, (int) $orderRow['id'], 15) === 'paid') {
      $orderRow['payment_status'] = PAY_STATUS_PAID;
    }
  }
}
unset($orderRow);

function statusGroupOf(string $status): string
{
  if (in_array($status, ['pending', 'preparing', 'ready'], true)) return 'ongoing';
  if ($status === 'claimed') return 'completed';
  return 'cancelled';
}

// Renders the Pending → Preparing → Ready → Claimed progress strip for
// an ongoing order. Returns '' for cancelled/no_show orders — a linear
// timeline doesn't make sense once an order has fallen off that path.
function renderOrderTimeline(string $status): string
{
  $steps = ['pending' => 'Pending', 'preparing' => 'Preparing', 'ready' => 'Ready', 'claimed' => 'Claimed'];
  $order = array_keys($steps);
  $currentIdx = array_search($status, $order, true);
  if ($currentIdx === false) return '';

  $html = '<div class="order-timeline">';
  $i = 0;
  foreach ($steps as $key => $label) {
    $isClaimed = $status === 'claimed';
    $state = $isClaimed || $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'current' : '');
    $icon  = $isClaimed || $i < $currentIdx ? 'fa-check' : ($i === $currentIdx ? 'fa-mug-hot' : '');
    $html .= '<div class="order-timeline-step ' . $state . '">'
      . '<div class="track"></div>'
      . '<div class="dot">' . ($icon ? '<i class="fa-solid ' . $icon . '"></i>' : ($i + 1)) . '</div>'
      . '<div class="label">' . e($label) . '</div>'
      . '</div>';
    $i++;
  }
  $html .= '</div>';
  return $html;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrfToken() ?>">
  <title>My Orders — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/variables.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/account-shell.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/account-shell.css') ?>">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/cart-drawer.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/cart-drawer.css') ?>">
  <style>
    /* ── Page-specific styles ── */
    .order-tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }

    .order-tab {
      padding: 9px 18px;
      border-radius: var(--radius-full);
      font-size: 13.5px;
      font-weight: 700;
      color: var(--text-secondary);
      border: 1px solid var(--border-color);
      background: var(--surface-color);
      cursor: pointer;
      transition: background var(--transition-fast), border-color var(--transition-fast), color var(--transition-fast);
    }

    .order-tab:focus-visible {
      outline: 2px solid var(--primary-color);
      outline-offset: 2px;
    }

    .order-tab.active {
      background: var(--primary-color);
      border-color: var(--primary-color);
      color: var(--text-on-primary);
    }

    .order-row {
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding: 20px 0;
      border-bottom: 1px solid var(--border-color);
    }

    .order-row-top {
      display: flex;
      gap: 16px;
    }

    .order-row:last-child {
      border-bottom: none;
    }

    .order-icon {
      width: 52px;
      height: 52px;
      border-radius: var(--radius-md);
      background: var(--primary-subtle);
      color: var(--primary-color);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      flex-shrink: 0;
    }

    .order-main {
      flex: 1;
      min-width: 0;
    }

    .order-title {
      font-weight: 700;
      font-size: 15px;
    }

    .order-meta {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 3px;
    }

    .order-date {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .order-side {
      text-align: right;
      flex-shrink: 0;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 8px;
    }

    .order-total {
      font-weight: 800;
      font-size: 15.5px;
    }

    .order-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .order-cancel-trigger {
      display: inline-flex;
      align-items: center;
      gap: 7px;
    }

    .order-note {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      margin-top: 8px;
      padding: 8px 10px;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      background: var(--surface-raised, var(--surface-color));
      color: var(--text-secondary);
      font-size: 12px;
      line-height: 1.4;
    }

    .order-note i {
      margin-top: 2px;
      flex: 0 0 auto;
    }

    .order-note.warning {
      border-color: rgba(190, 115, 20, .28);
      background: rgba(255, 184, 77, .1);
      color: #875313;
    }

    .order-note.success {
      border-color: rgba(36, 125, 70, .25);
      background: rgba(55, 155, 91, .08);
      color: #23653d;
    }

    .cancel-modal-copy {
      margin: 12px 0 0;
      color: var(--text-muted);
      font-size: 13px;
      line-height: 1.5;
    }

    .cancel-request-modal {
      width: min(100%, 520px);
      max-height: min(90vh, 760px);
      padding: 24px;
      overflow-y: auto;
      overscroll-behavior: contain;
    }

    .cancel-request-heading {
      display: flex;
      align-items: center;
      gap: 12px;
      padding-right: 26px;
    }

    .cancel-request-icon {
      display: grid;
      place-items: center;
      width: 42px;
      height: 42px;
      flex: 0 0 auto;
      color: var(--primary-color);
      background: var(--primary-subtle);
      border-radius: 12px;
      font-size: 17px;
    }

    .cancel-request-title {
      margin: 0;
      color: var(--text-color);
      font-size: 18px;
      font-weight: 800;
      line-height: 1.25;
    }

    .cancel-modal-order {
      margin-top: 4px;
    }

    .cancel-request-form {
      display: grid;
      gap: 14px;
      margin-top: 16px;
    }

    .cancel-request-form .field-label {
      display: block;
      margin: 0 0 6px;
      color: var(--text-secondary);
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0;
      text-transform: none;
    }

    .cancel-request-form .form-control {
      box-sizing: border-box;
      width: 100%;
      height: 46px;
      min-width: 0;
      padding: 0 12px;
      color: var(--text-color);
      background: var(--surface-color);
      border: 1.5px solid var(--border-color);
      border-radius: var(--radius-sm);
      font-size: 0.9rem;
      transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    }

    .cancel-request-form .form-control::placeholder {
      color: var(--text-placeholder);
    }

    .cancel-request-form .form-control:hover {
      border-color: var(--border-strong);
    }

    .cancel-request-form .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px var(--primary-subtle);
      outline: none;
    }

    .cancel-input-wrap {
      position: relative;
    }

    .cancel-input-wrap > i {
      position: absolute;
      top: 50%;
      left: 12px;
      z-index: 1;
      color: var(--text-muted);
      font-size: 13px;
      pointer-events: none;
      transform: translateY(-50%);
    }

    .cancel-input-wrap .form-control {
      padding-left: 36px;
    }

    #cancelRequestReason {
      height: auto !important;
      min-height: 96px;
      padding: 11px 12px !important;
      line-height: 1.5;
      resize: vertical;
    }

    .cancel-refund-fields {
      display: grid;
      gap: 10px;
      margin-top: 0;
      padding: 14px;
      background: var(--surface-raised);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
    }

    .cancel-refund-fields[hidden] {
      display: none;
    }

    .cancel-refund-fields-title {
      display: flex;
      align-items: center;
      gap: 7px;
      color: var(--text-color);
      font-size: 13px;
      font-weight: 800;
    }

    .cancel-refund-fields > p {
      margin: -5px 0 2px;
      color: var(--text-muted);
      font-size: 12px;
      line-height: 1.45;
    }

    .cancel-refund-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .cancel-refund-field {
      min-width: 0;
    }

    .cancel-refund-upload {
      padding-top: 10px;
      border-top: 1px solid var(--border-color);
    }

    .cancel-refund-help {
      display: block;
      margin-top: 6px;
      color: var(--text-muted);
      font-size: 11px;
      line-height: 1.4;
    }

    .cancel-refund-file-picker {
      position: relative;
      display: flex;
      align-items: center;
      gap: 10px;
      min-height: 58px;
      padding: 9px 11px;
      overflow: hidden;
      color: var(--text-secondary);
      background: var(--surface-color);
      border: 1.5px dashed var(--border-strong);
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: border-color var(--transition-fast), background var(--transition-fast), box-shadow var(--transition-fast);
    }

    .cancel-refund-file-picker:hover {
      background: var(--surface-raised);
      border-color: var(--primary-color);
    }

    .cancel-refund-file-picker:focus-within {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px var(--primary-subtle);
    }

    .cancel-refund-file-icon {
      display: grid;
      place-items: center;
      width: 36px;
      height: 36px;
      flex: 0 0 auto;
      color: var(--primary-color);
      background: var(--primary-subtle);
      border-radius: 10px;
    }

    .cancel-refund-file-copy {
      display: grid;
      min-width: 0;
      gap: 2px;
      font-size: 0.78rem;
      line-height: 1.35;
    }

    .cancel-refund-file-copy strong,
    .cancel-refund-file-copy small {
      overflow-wrap: anywhere;
    }

    .cancel-refund-file-copy small {
      color: var(--text-muted);
      font-size: 0.68rem;
    }

    .cancel-refund-file-browse {
      margin-left: auto;
      padding: 6px 9px;
      color: var(--primary-color);
      background: var(--primary-subtle);
      border-radius: 7px;
      font-size: 0.7rem;
      font-weight: 700;
      white-space: nowrap;
    }

    .cancel-refund-file {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: pointer;
    }

    .cancel-request-actions {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr);
      gap: 10px;
      padding-top: 14px;
      border-top: 1px solid var(--border-color);
    }

    .cancel-request-actions .btn {
      width: 100%;
      min-height: 44px;
      white-space: normal;
    }

    @media (max-width: 520px) {
      .cancel-request-modal {
        padding: 18px 16px;
      }

      .cancel-refund-grid {
        grid-template-columns: minmax(0, 1fr);
      }

      .cancel-request-actions {
        grid-template-columns: minmax(0, 1fr);
      }
    }

    .cancel-modal-order {
      display: inline-block;
      padding: 5px 9px;
      border-radius: var(--radius-full);
      background: var(--primary-subtle);
      color: var(--primary-color);
      font-size: 12px;
      font-weight: 800;
    }

    .btn-sm {
      padding: 7px 14px;
      font-size: 12.5px;
      border-radius: var(--radius-sm);
    }

    .btn-outline {
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      color: var(--text-color);
    }

    .btn-outline:hover {
      border-color: var(--primary-color);
      color: var(--primary-color);
    }

    .empty-state {
      text-align: center;
      padding: 70px 20px;
      color: var(--text-muted);
    }

    .empty-state i {
      font-size: 34px;
      opacity: .35;
      margin-bottom: 14px;
    }

    /* Rate modal */
    .star-row {
      display: flex;
      gap: 6px;
      font-size: 26px;
      color: var(--border-color);
      cursor: pointer;
      margin: 10px 0 18px;
    }

    .star-row i.active {
      color: #f5a623;
    }

    .rate-error {
      color: var(--danger-color, #c0392b);
      font-size: 13px;
      margin: -8px 0 12px;
    }

    .rate-items {
      border-top: 1px solid var(--border-color);
      padding-top: 12px;
      margin-bottom: 14px;
    }

    .rate-items-title {
      font-size: 13px;
      font-weight: 700;
      margin-bottom: 6px;
    }

    .rate-items-title span {
      font-weight: 400;
      color: var(--text-muted);
    }

    .rate-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 5px 0;
      font-size: 13px;
    }

    .rate-item-name {
      flex: 1;
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .rate-item-stars {
      display: flex;
      gap: 3px;
      font-size: 17px;
      color: var(--border-color);
      cursor: pointer;
    }

    .rate-item-stars i.active {
      color: #f5a623;
    }

    .modal-box textarea {
      width: 100%;
      padding: 11px 13px;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm);
      font: inherit;
      resize: vertical;
      min-height: 70px;
    }

    @media (max-width: 600px) {
      .order-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        margin-right: -4px;
        padding: 0 4px 5px 0;
        scrollbar-width: none;
      }

      .order-tabs::-webkit-scrollbar {
        display: none;
      }

      .order-tab {
        flex: 0 0 auto;
        padding: 9px 14px;
        font-size: .82rem;
      }

      .order-row {
        gap: 10px;
        padding: 16px 0;
      }

      .order-row-top {
        display: grid;
        grid-template-columns: 42px minmax(0, 1fr);
        gap: 10px;
      }

      .order-icon {
        width: 42px;
        height: 42px;
        font-size: 17px;
      }

      .order-title {
        font-size: .94rem;
        overflow-wrap: anywhere;
      }

      .order-meta,
      .order-date {
        font-size: .8rem;
        line-height: 1.45;
      }

      .order-side {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 8px 10px;
        text-align: left;
      }

      .order-total {
        font-size: 1rem;
      }

      .order-side>.badge {
        justify-self: end;
      }

      .order-actions {
        grid-column: 1 / -1;
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-start;
      }

      .order-actions .btn {
        flex: 1 1 auto;
        min-height: 40px;
      }

      .empty-state {
        padding: 44px 12px;
      }

      .star-row {
        min-height: 44px;
        align-items: center;
      }
    }
  </style>
</head>

<body>

  <header class="site-header">
    <div class="container header-inner">
      <a href="<?= APP_URL ?>/menu.php" class="logo">
        <img src="<?= APP_URL ?>/../assets/images/logo.png" alt="<?= APP_NAME ?>" onerror="this.style.display='none'">
        <span>
          <span class="logo-name"><?= e(APP_NAME) ?></span>
          <span class="logo-sub">EARIST Cavite Campus</span>
        </span>
      </a>
      <form class="header-search" role="search" action="<?= APP_URL ?>/menu.php" method="GET">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" name="q" aria-label="Search the menu" placeholder="Search the menu...">
      </form>
      <div class="header-actions">
        <div class="user-menu">
          <button class="user-menu-btn" onclick="toggleUserMenu()" aria-label="Account menu for <?= e($_SESSION['full_name'] ?? 'User') ?>" aria-haspopup="true" aria-controls="userMenuDropdown">
            <i class="fa-solid fa-circle-user" style="font-size:20px;flex:none"></i>
            <span class="user-menu-name"><?= e($_SESSION['full_name'] ?? 'Account') ?></span>
          </button>
          <div class="user-menu-dropdown" id="userMenuDropdown">
            <a href="<?= APP_URL ?>/account.php" class="user-menu-item"><i class="fa-solid fa-user"></i> My Profile</a>
            <a href="<?= APP_URL ?>/orders.php" class="user-menu-item active"><i class="fa-solid fa-box"></i> My Orders</a>
            <hr style="border:0;border-top:1px solid var(--border-color);margin:4px 0">
            <a href="<?= APP_URL ?>/logout.php" class="user-menu-item"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>
          </div>
        </div>
        <button class="cart-btn" onclick="openCart()" aria-label="Cart">
          <i class="fa-solid fa-bag-shopping"></i>
          <span class="cart-badge" id="cartBadge" style="display:none">0</span>
        </button>
      </div>
    </div>

    <nav class="account-subnav">
      <div class="container account-subnav-inner">
        <a href="<?= APP_URL ?>/menu.php" class="back-btn" aria-label="Back to shop"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="account-subnav-title">Account Management</div>
      </div>
    </nav>
  </header>

  <main class="container">
    <?php showFlashAsToast('global'); ?>

    <div class="account-layout">
      <aside class="account-sidebar">
        <a href="<?= APP_URL ?>/account.php" class="account-nav-item"><i class="fa-solid fa-user"></i> My Profile</a>
        <div class="account-nav-item active"><i class="fa-solid fa-box"></i> My Orders</div>
        <a href="<?= APP_URL ?>/logout.php" class="account-nav-item logout"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>
      </aside>

      <div class="account-content">
        <div class="account-content-title">My Orders</div>
        <div class="account-content-sub">Track your order history effortlessly</div>

        <div class="order-tabs">
          <a href="?status=all" class="order-tab <?= $filter === 'all' ? 'active' : '' ?>">All</a>
          <a href="?status=ongoing" class="order-tab <?= $filter === 'ongoing' ? 'active' : '' ?>">Ongoing</a>
          <a href="?status=completed" class="order-tab <?= $filter === 'completed' ? 'active' : '' ?>">Completed</a>
          <a href="?status=cancelled" class="order-tab <?= $filter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
        </div>

        <?php if (empty($orders)): ?>
          <div class="empty-state">
            <i class="fa-solid fa-mug-hot"></i>
            <p>No orders here yet.</p>
          </div>
          <?php else: foreach ($orders as $o):
            $group = statusGroupOf($o['status']);
            $awaitingPayment = ($o['gateway'] ?? '') === 'paymongo'
              && $o['payment_status'] === PAY_STATUS_PENDING
              && $o['status'] === STATUS_PENDING;
            $payUrl = APP_URL . '/payment-return.php?order=' . urlencode($o['order_number']);
          ?>
            <div class="order-row" data-order-id="<?= $o['id'] ?>" data-order-number="<?= e($o['order_number']) ?>" data-status="<?= e($o['status']) ?>" data-cancellation-status="<?= e($o['cancellation_request_status'] ?? '') ?>" data-payment-gateway="<?= e($o['gateway'] ?? '') ?>" data-manual-gcash-refund="<?= strtolower(trim((string) ($o['payment_method'] ?? ''))) === 'gcash' && ($o['gateway'] ?? '') !== 'paymongo' ? '1' : '0' ?>">
              <div class="order-row-top">
                <div class="order-icon"><i class="fa-solid fa-mug-hot"></i></div>
                <div class="order-main">
                  <div class="order-title"><?= e($o['order_number']) ?></div>
                  <div class="order-meta">Pickup &middot; <?= (int) $o['item_count'] ?> item<?= (int) $o['item_count'] !== 1 ? 's' : '' ?></div>
                  <?php if ($awaitingPayment): ?>
                    <div class="order-date" data-pay-note style="color:var(--warn, #a45a24);font-weight:600">
                      Awaiting payment &middot; released after <?= (int) PAYMONGO_UNPAID_ORDER_MINUTES ?> min if unpaid
                    </div>
                  <?php endif; ?>
                  <?php if (($o['cancellation_request_status'] ?? '') === 'pending'): ?>
                    <div class="order-note warning"><i class="fa-solid fa-hourglass-half"></i><span>Cancellation request sent. The cashier will check the payment before cancelling.</span></div>
                  <?php elseif (($o['cancellation_request_status'] ?? '') === 'refund_required'): ?>
                    <div class="order-note warning"><i class="fa-solid fa-rotate-left"></i><span>Cancellation is awaiting refund processing by the shop.</span></div>
                  <?php elseif (($o['cancellation_request_status'] ?? '') === 'rejected'): ?>
                    <div class="order-note"><i class="fa-solid fa-circle-info"></i><span>Cancellation request declined. You can contact the shop if you need help.</span></div>
                  <?php elseif (($o['cancellation_request_status'] ?? '') === 'cancelled_unpaid'): ?>
                    <div class="order-note"><i class="fa-solid fa-circle-info"></i><span>Cancelled after the cashier confirmed no payment was received.</span></div>
                  <?php elseif (($o['cancellation_request_status'] ?? '') === 'refunded'): ?>
                    <div class="order-note success"><i class="fa-solid fa-circle-check"></i><span>Refund recorded<?= !empty($o['refund_reference']) ? ' · Ref ' . e($o['refund_reference']) : '' ?></span></div>
                  <?php endif; ?>
                  <div class="order-date">
                    <?php if (!empty($o['pickup_date'])): ?>
                      <?= date('j M Y', strtotime($o['pickup_date'])) ?><?= !empty($o['pickup_time']) ? ', ' . ($o['pickup_time'] === 'ASAP' ? 'ASAP' : date('g:i A', strtotime($o['pickup_time']))) : '' ?>
                    <?php else: ?>
                      <?= date('j M Y, g:i A', strtotime($o['created_at'])) ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="order-side">
                  <div class="order-total"><?= peso($o['total_amount']) ?></div>
                  <span class="badge badge-<?= e($o['status']) ?>" data-status-badge><?= e(ucfirst(str_replace('_', ' ', $o['status']))) ?></span>
                  <div class="order-actions" data-order-actions>
                    <?php if ($o['status'] === 'ready'): ?>
                      <button class="btn btn-sm btn-primary" data-qr-btn onclick="openQrModal(<?= $o['id'] ?>, '<?= e($o['order_number']) ?>')">
                        <i class="fa-solid fa-qrcode"></i> Show QR to Claim
                      </button>
                    <?php endif; ?>
                    <?php if ($awaitingPayment): ?>
                      <a class="btn btn-sm btn-primary" data-pay-actions href="<?= e($payUrl) ?>&amp;resume=1">
                        <i class="fa-solid fa-mobile-screen-button"></i> Complete Payment
                      </a>
                      <a class="btn btn-sm btn-outline" data-pay-actions href="<?= e($payUrl) ?>&amp;cancelled=1">Cancel</a>
                    <?php endif; ?>
                    <?php if ($o['status'] === STATUS_PENDING && in_array($o['order_type'], [ORDER_PREORDER, 'pre-order'], true) && !$awaitingPayment && !in_array(($o['cancellation_request_status'] ?? ''), ['pending', 'refund_required', 'cancelled_unpaid', 'refunded'], true)): ?>
                      <button type="button" class="btn btn-sm btn-outline order-cancel-trigger" data-cancel-action onclick="openCancelRequestModal(<?= (int) $o['id'] ?>, '<?= e($o['order_number']) ?>')">
                        <i class="fa-solid fa-ban"></i> Request cancellation
                      </button>
                    <?php endif; ?>
                    <?php if ($group === 'completed' && empty($o['feedback_id'])): ?>
                      <button class="btn btn-sm btn-outline" data-rate-btn onclick="openRateModal(<?= $o['id'] ?>)">Rate Order</button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-primary" onclick="reorder(<?= $o['id'] ?>)">Reorder</button>
                  </div>
                </div>
              </div>
              <?php if ($group !== 'cancelled'): ?>
                <div data-timeline><?= renderOrderTimeline($o['status']) ?></div>
              <?php endif; ?>
            </div>
        <?php endforeach;
        endif; ?>
      </div>
    </div>
  </main>

  <div class="modal-overlay" id="cancelRequestModal" hidden>
    <div class="modal-box cancel-request-modal" role="dialog" aria-modal="true" aria-labelledby="cancelRequestTitle">
      <button type="button" class="modal-close" onclick="closeCancelRequestModal()" aria-label="Close">&times;</button>
      <div class="cancel-request-heading">
        <div class="cancel-request-icon"><i class="fa-solid fa-ban"></i></div>
        <div>
          <h2 class="cancel-request-title" id="cancelRequestTitle">Request cancellation</h2>
          <div class="cancel-modal-order" id="cancelRequestOrder"></div>
        </div>
      </div>
      <p class="cancel-modal-copy" id="cancelRequestCopy">The cashier will review your request before preparation. Refunds are not automatic.</p>
      <form method="POST" id="cancelRequestForm" class="cancel-request-form" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="request_order_cancellation" value="1">
        <input type="hidden" name="order_id" id="cancelRequestOrderId">
        <div class="cancel-refund-field">
          <label class="field-label" for="cancelRequestReason">Reason for cancellation</label>
          <textarea class="form-control" id="cancelRequestReason" name="cancel_reason" maxlength="500" required rows="3" placeholder="Tell the shop why you need to cancel"></textarea>
        </div>
        <section class="cancel-refund-fields" id="cancelRefundFields" hidden>
          <div class="cancel-refund-fields-title"><i class="fa-solid fa-wallet"></i> GCash refund destination</div>
          <p>Enter the account that sent the payment. The shop will confirm the payment before sending any refund.</p>
          <div class="cancel-refund-grid">
            <div class="cancel-refund-field">
              <label class="field-label" for="cancelRefundGcashName">Name on GCash account</label>
              <div class="cancel-input-wrap"><i class="fa-regular fa-user" aria-hidden="true"></i><input class="form-control" id="cancelRefundGcashName" name="refund_gcash_name" type="text" maxlength="100" autocomplete="name" placeholder="Account holder's name"></div>
            </div>
            <div class="cancel-refund-field">
              <label class="field-label" for="cancelRefundGcashNumber">GCash number</label>
              <div class="cancel-input-wrap"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i><input class="form-control" id="cancelRefundGcashNumber" name="refund_gcash_number" type="tel" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" autocomplete="tel-national" placeholder="09XXXXXXXXX"></div>
            </div>
          </div>
          <div class="cancel-refund-upload">
            <label class="field-label" for="cancelRefundQr">GCash QR code <span class="text-muted" style="font-weight:400;text-transform:none">Optional</span></label>
            <label class="cancel-refund-file-picker" for="cancelRefundQr">
              <span class="cancel-refund-file-icon"><i class="fa-solid fa-qrcode" aria-hidden="true"></i></span>
              <span class="cancel-refund-file-copy"><strong id="cancelRefundQrName" aria-live="polite">Choose a QR image</strong><small>JPG, PNG, or WebP · up to 2 MB</small></span>
              <span class="cancel-refund-file-browse">Browse</span>
              <input class="cancel-refund-file" id="cancelRefundQr" name="refund_qr" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Choose an optional GCash QR code image">
            </label>
            <small class="cancel-refund-help">The QR code is visible only to authorized admins.</small>
          </div>
        </section>
        <div class="cancel-request-actions">
          <button type="button" class="btn btn-ghost" onclick="closeCancelRequestModal()">Keep order</button>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Send request</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Rate Order modal -->
  <div class="modal-overlay" id="rateModal" hidden>
    <div class="modal-box">
      <button class="modal-close" onclick="document.getElementById('rateModal').hidden=true">&times;</button>
      <div style="font-size:18px;font-weight:800;margin-bottom:4px">Rate Your Order</div>
      <p style="font-size:13px;color:var(--text-muted)">How was your experience?</p>
      <form method="POST" id="rateForm">
        <?= csrfField() ?>
        <input type="hidden" name="submit_feedback" value="1">
        <input type="hidden" name="order_id" id="rate-order-id">
        <div class="star-row" id="starRow">
          <i class="fa-solid fa-star" data-v="1" onclick="setStar(1)"></i>
          <i class="fa-solid fa-star" data-v="2" onclick="setStar(2)"></i>
          <i class="fa-solid fa-star" data-v="3" onclick="setStar(3)"></i>
          <i class="fa-solid fa-star" data-v="4" onclick="setStar(4)"></i>
          <i class="fa-solid fa-star" data-v="5" onclick="setStar(5)"></i>
        </div>
        <input type="hidden" name="rating" id="rate-rating" value="0">
        <div class="rate-error" id="rateError" hidden>Please tap a star to rate your order.</div>
        <div id="rateItems" class="rate-items" hidden>
          <div class="rate-items-title">Rate your items <span>(optional)</span></div>
          <div id="rateItemsList"></div>
        </div>
        <textarea name="comment" id="rate-comment" maxlength="500" placeholder="Anything you'd like to share? (optional)"></textarea>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px">Submit Feedback</button>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="qrModal" hidden>
    <div class="modal-box" style="text-align:center;max-width:340px">
      <button class="modal-close" onclick="closeQrModal()">&times;</button>
      <div style="font-size:18px;font-weight:800;margin-bottom:2px">Show this to the cashier</div>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px" id="qrOrderNum"></p>
      <div id="qrCanvasBox" style="display:flex;align-items:center;justify-content:center;min-height:220px">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;color:var(--text-muted)"></i>
      </div>
      <p style="font-size:12px;color:var(--text-muted);margin-top:14px" id="qrExpiryNote">
        This code is valid for 10 minutes.
      </p>
      <button class="btn btn-ghost" style="width:100%;margin-top:8px" id="qrRefreshBtn" onclick="refreshQr()" hidden>
        <i class="fa-solid fa-rotate"></i> Code expired — Tap to refresh
      </button>
    </div>
  </div>

  <?php require __DIR__ . '/../includes/cart-drawer.php'; ?>

  <script src="<?= APP_URL ?>/../assets/js/qrcode.min.js"></script>
  <script>
    const APP_URL = '<?= APP_URL ?>';
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

    function toggleUserMenu() {
      document.getElementById('userMenuDropdown').classList.toggle('open');
    }

    function openCancelRequestModal(orderId, orderNumber) {
      const orderRow = document.querySelector(`.order-row[data-order-id="${orderId}"]`);
      const refundFields = document.getElementById('cancelRefundFields');
      const needsRefundDetails = orderRow?.dataset.manualGcashRefund === '1';
      const isPayMongo = orderRow?.dataset.paymentGateway === 'paymongo';
      refundFields.hidden = !needsRefundDetails;
      document.getElementById('cancelRequestCopy').textContent = needsRefundDetails
        ? 'The cashier will review your request before preparation. If a refund is due for this manual GCash payment, the shop will use the details below. Refunds are not automatic.'
        : isPayMongo
          ? 'The cashier will review your request before preparation. Any eligible refund will be handled through PayMongo; no GCash destination details are needed here.'
          : 'The cashier will review your request before preparation. If a refund is due, the shop will contact you about the next steps.';
      document.getElementById('cancelRefundGcashName').required = needsRefundDetails;
      document.getElementById('cancelRefundGcashNumber').required = needsRefundDetails;
      document.getElementById('cancelRefundGcashName').value = '';
      document.getElementById('cancelRefundGcashNumber').value = '';
      document.getElementById('cancelRefundQr').value = '';
      document.getElementById('cancelRefundQrName').textContent = 'Choose a QR image';
      document.getElementById('cancelRequestOrderId').value = orderId;
      document.getElementById('cancelRequestOrder').textContent = `Order ${orderNumber}`;
      document.getElementById('cancelRequestReason').value = '';
      document.getElementById('cancelRequestModal').hidden = false;
      document.getElementById('cancelRequestReason').focus();
    }

    document.getElementById('cancelRefundGcashNumber').addEventListener('input', event => {
      event.target.value = event.target.value.replace(/\D/g, '').slice(0, 11);
    });

    document.getElementById('cancelRefundQr').addEventListener('change', event => {
      const file = event.target.files?.[0];
      document.getElementById('cancelRefundQrName').textContent = file?.name || 'Choose a QR image';
    });

    function closeCancelRequestModal() {
      document.getElementById('cancelRequestModal').hidden = true;
    }

    document.getElementById('cancelRequestModal').addEventListener('click', event => {
      if (event.target.id === 'cancelRequestModal') closeCancelRequestModal();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !document.getElementById('cancelRequestModal').hidden) closeCancelRequestModal();
    });

    window.addEventListener('click', e => {
      if (!e.target.closest('.user-menu')) document.getElementById('userMenuDropdown')?.classList.remove('open');
    });

    /* ── Show QR modal (pickup claim) ──
       Mirrors the 10-minute expiry enforced server-side in
       api/qr-claim.php — the countdown here is just so the student/
       faculty isn't caught off guard by a code that silently stopped
       working; refreshQr() gets a freshly-signed one from the same
       endpoint used to render it the first time. */
    let qrCountdownTimer = null;
    let qrCurrentOrderId = null;

    function openQrModal(orderId, orderNumber) {
      qrCurrentOrderId = orderId;
      document.getElementById('qrOrderNum').textContent = orderNumber;
      document.getElementById('qrModal').hidden = false;
      loadQr(orderId);
    }

    function closeQrModal() {
      document.getElementById('qrModal').hidden = true;
      clearInterval(qrCountdownTimer);
    }

    function refreshQr() {
      if (qrCurrentOrderId) loadQr(qrCurrentOrderId);
    }

    function loadQr(orderId) {
      clearInterval(qrCountdownTimer);
      document.getElementById('qrRefreshBtn').hidden = true;
      const box = document.getElementById('qrCanvasBox');
      box.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="font-size:28px;color:var(--text-muted)"></i>';

      fetch(`${APP_URL}/api/qr-generate.php?order_id=${orderId}`)
        .then(r => r.json())
        .then(data => {
          if (!data.success) {
            box.innerHTML = `<p style="color:var(--status-cancelled);font-size:13px;padding:0 10px">${data.message || 'Could not generate a QR code for this order right now.'}</p>`;
            return;
          }
          box.innerHTML = '';
          new QRCode(box, {
            text: data.qr_data,
            width: 200,
            height: 200
          });
          startQrCountdown(600); // seconds — matches the server-side expiry window
        })
        .catch(() => {
          box.innerHTML = '<p style="color:var(--status-cancelled);font-size:13px">Network error — try again.</p>';
        });
    }

    function startQrCountdown(seconds) {
      let remaining = seconds;
      const note = document.getElementById('qrExpiryNote');
      const refreshBtn = document.getElementById('qrRefreshBtn');
      const tick = () => {
        if (remaining <= 0) {
          clearInterval(qrCountdownTimer);
          note.textContent = 'This code has expired.';
          refreshBtn.hidden = false;
          return;
        }
        const m = Math.floor(remaining / 60);
        const s = String(remaining % 60).padStart(2, '0');
        note.textContent = `Valid for ${m}:${s} more`;
        remaining--;
      };
      tick();
      qrCountdownTimer = setInterval(tick, 1000);
    }

    /* ── Rate Order modal ── */
    function openRateModal(orderId) {
      document.getElementById('rate-order-id').value = orderId;
      document.getElementById('rate-rating').value = 0;
      document.getElementById('rate-comment').value = '';
      document.getElementById('rateError').hidden = true;
      document.getElementById('rateItems').hidden = true;
      document.getElementById('rateItemsList').innerHTML = '';
      document.querySelectorAll('#starRow i').forEach(s => s.classList.remove('active'));
      document.getElementById('rateModal').hidden = false;
      loadRateItems(orderId);
    }

    function setStar(v) {
      document.getElementById('rate-rating').value = v;
      document.getElementById('rateError').hidden = true;
      document.querySelectorAll('#starRow i').forEach(s => s.classList.toggle('active', parseInt(s.dataset.v) <= v));
    }

    /* Per-item stars are optional — if this request fails the customer
       can still submit the overall rating. */
    function loadRateItems(orderId) {
      fetch(`${APP_URL}/orders.php?get_rate_items=${orderId}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
          // Ignore a late response if the modal has moved on to another order
          if (document.getElementById('rate-order-id').value !== String(orderId)) return;
          const items = data.items || [];
          if (!items.length) return;
          const list = document.getElementById('rateItemsList');
          items.forEach(item => {
            const row = document.createElement('div');
            row.className = 'rate-item';

            const name = document.createElement('div');
            name.className = 'rate-item-name';
            name.textContent = item.name;

            const stars = document.createElement('div');
            stars.className = 'rate-item-stars';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `product_rating[${item.product_id}]`;
            input.value = 0;
            input.disabled = true; // not submitted until the customer picks a rating

            for (let v = 1; v <= 5; v++) {
              const star = document.createElement('i');
              star.className = 'fa-solid fa-star';
              star.dataset.v = v;
              star.addEventListener('click', () => {
                input.value = v;
                input.disabled = false;
                stars.querySelectorAll('i').forEach(s => s.classList.toggle('active', parseInt(s.dataset.v) <= v));
              });
              stars.appendChild(star);
            }

            row.append(name, stars, input);
            list.appendChild(row);
          });
          document.getElementById('rateItems').hidden = false;
        })
        .catch(() => { /* item ratings are optional */ });
    }

    document.getElementById('rateForm').addEventListener('submit', e => {
      if (parseInt(document.getElementById('rate-rating').value, 10) < 1) {
        e.preventDefault();
        document.getElementById('rateError').hidden = false;
      }
    });

    /* ── Live order-status polling ──
       Ongoing orders (pending/preparing/ready) are re-checked every
       20s so status and available actions stay current without a reload. */
    const STEP_ORDER = ['pending', 'preparing', 'ready', 'claimed'];
    const STEP_LABELS = {
      pending: 'Pending',
      preparing: 'Preparing',
      ready: 'Ready',
      claimed: 'Claimed'
    };

    function renderTimelineHTML(status) {
      const idx = STEP_ORDER.indexOf(status);
      if (idx === -1) return '';
      return '<div class="order-timeline">' + STEP_ORDER.map((key, i) => {
        const isClaimed = status === 'claimed';
        const state = isClaimed || i < idx ? 'done' : (i === idx ? 'current' : '');
        const icon = isClaimed || i < idx ? '<i class="fa-solid fa-check"></i>' : (i === idx ? '<i class="fa-solid fa-mug-hot"></i>' : (i + 1));
        return `<div class="order-timeline-step ${state}"><div class="track"></div><div class="dot">${icon}</div><div class="label">${STEP_LABELS[key]}</div></div>`;
      }).join('') + '</div>';
    }

    document.querySelectorAll('.order-row').forEach(r => {
      if (r.querySelector('[data-pay-actions]')) r.dataset.hadPayButtons = '1';
    });

    function pollOrderStatuses() {
      const rows = Array.from(document.querySelectorAll('.order-row[data-status]')).filter(r => ['pending', 'preparing', 'ready'].includes(r.dataset.status));
      if (rows.length === 0) return;
      const ids = rows.map(r => r.dataset.orderId).join(',');

      fetch(`${APP_URL}/api/order-status.php?ids=${ids}`, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(r => r.json())
        .then(data => {
          (data.orders || []).forEach(o => {
            const row = document.querySelector(`.order-row[data-order-id="${o.id}"]`);
            if (!row) return;

            // Payment settled (or the order moved on): the pay/cancel buttons no longer apply.
            if (o.awaiting_payment === false) {
              row.querySelectorAll('[data-pay-actions], [data-pay-note]').forEach(el => el.remove());
              if (!row.dataset.paidNotified && row.dataset.hadPayButtons === '1') {
                row.dataset.paidNotified = '1';
                if (o.status !== 'cancelled' && o.status !== 'no_show') {
                  showToast('success', `Payment received for ${row.dataset.orderNumber}.`, 6000);
                }
              }
              if (o.status === 'pending' && !['pending', 'refund_required', 'cancelled_unpaid', 'refunded'].includes(row.dataset.cancellationStatus)) {
                const actions = row.querySelector('[data-order-actions]');
                if (actions && !actions.querySelector('[data-cancel-action]')) {
                  const cancelButton = document.createElement('button');
                  cancelButton.type = 'button';
                  cancelButton.className = 'btn btn-sm btn-outline order-cancel-trigger';
                  cancelButton.setAttribute('data-cancel-action', '');
                  cancelButton.innerHTML = '<i class="fa-solid fa-ban"></i> Request cancellation';
                  cancelButton.onclick = () => openCancelRequestModal(o.id, row.dataset.orderNumber);
                  actions.appendChild(cancelButton);
                }
              }
            }
            if (o.status !== 'pending') {
              row.querySelectorAll('[data-cancel-action]').forEach(el => el.remove());
            }
            if (row.dataset.status === o.status) return;

            const wasStatus = row.dataset.status;
            row.dataset.status = o.status;

            const badge = row.querySelector('[data-status-badge]');
            if (badge) {
              badge.className = 'badge badge-' + o.status;
              badge.textContent = o.status.charAt(0).toUpperCase() + o.status.replace('_', ' ').slice(1);
            }

            const timelineHost = row.querySelector('[data-timeline]');
            if (timelineHost) timelineHost.innerHTML = renderTimelineHTML(o.status);

            if (o.status === 'ready' && wasStatus !== 'ready') {
              showToast('success', `Order ${row.dataset.orderNumber} is ready for pickup!`, 8000);
              const actions = row.querySelector('[data-order-actions]');
              if (actions && !actions.querySelector('[data-qr-btn]')) {
                const btn = document.createElement('button');
                btn.className = 'btn btn-sm btn-primary';
                btn.setAttribute('data-qr-btn', '');
                btn.innerHTML = '<i class="fa-solid fa-qrcode"></i> Show QR to Claim';
                btn.onclick = () => openQrModal(o.id, row.dataset.orderNumber);
                actions.prepend(btn);
              }
            } else if (o.status === 'claimed') {
              const actions = row.querySelector('[data-order-actions]');
              actions?.querySelectorAll('[data-qr-btn]').forEach(btn => btn.remove());

              if (qrCurrentOrderId === Number(o.id)) closeQrModal();

              if (actions && !o.has_feedback && !actions.querySelector('[data-rate-btn]')) {
                const btn = document.createElement('button');
                btn.className = 'btn btn-sm btn-outline';
                btn.setAttribute('data-rate-btn', '');
                btn.textContent = 'Rate Order';
                btn.onclick = () => openRateModal(o.id);
                actions.insertBefore(btn, actions.querySelector('button[onclick^="reorder"]') || null);
              }
              showToast('success', `Order ${row.dataset.orderNumber} has been claimed.`, 6000);
            } else if (o.status === 'cancelled' || o.status === 'no_show') {
              showToast('warning', `Order ${row.dataset.orderNumber} was cancelled.`, 8000);
            }
          });
        })
        .catch(() => {}); // silent — this is a background refresh, not a user-initiated action
    }
    setInterval(pollOrderStatuses, 20000);
  </script>
  <script src="<?= APP_URL ?>/../assets/js/cart-drawer.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/cart-drawer.js') ?>"></script>
</body>

</html>
