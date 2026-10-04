<?php

/**
 * Order Lock API
 * Handles locking/unlocking pre-orders for cashiers
 */

// Show errors for debugging (turn off in production)
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start output buffering BEFORE any includes
ob_start();

try {
  require_once __DIR__ . '/../../config/constants.php';
  require_once __DIR__ . '/../../config/database.php';
  require_once __DIR__ . '/../../config/session.php';
  require_once __DIR__ . '/../../includes/functions.php';
  require_once __DIR__ . '/../../includes/csrf.php';
} catch (Throwable $e) {
  while (ob_get_level() > 0) {
    ob_end_clean();
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['success' => false, 'error' => 'include_error', 'message' => $e->getMessage()]);
  exit;
}

// Clear any output from includes
while (ob_get_level() > 1) {
  ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

// Debug: Log request info
error_log("API order-lock.php called - Action: " . ($_GET['action'] ?? 'none') . " Method: " . $_SERVER['REQUEST_METHOD']);

/**
 * Helper: Return JSON response and exit
 */
function jsonOut(array $data, int $code = 200): void {
  // Clear any buffered output without turning off buffering
  while (ob_get_level() > 0) {
    ob_end_clean();
  }
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

// Check if user is logged in as cashier
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== ROLE_CASHIER) {
  jsonOut(['success' => false, 'error' => 'unauthorized', 'message' => 'You must be logged in as a cashier'], 401);
}

$db = Database::getInstance();
$currentUser = (int) $_SESSION['user_id'];

/**
 * Helper: Check if order is locked by another cashier
 * Returns: ['locked' => bool, 'by' => cashier_id|null, 'name' => cashier_name|null, 'expired' => bool]
 */
function checkOrderLock(PDO $db, int $orderId): array {
  $order = $db->prepare(
    "SELECT o.locked_by, o.locked_at, o.lock_expire_at, o.status, c.full_name AS locked_by_name
     FROM orders o
     LEFT JOIN cashiers c ON o.locked_by = c.id
     WHERE o.id = ?"
  );
  $order->execute([$orderId]);
  $row = $order->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    return ['locked' => false, 'by' => null, 'name' => null, 'expired' => false, 'error' => 'Order not found'];
  }

  $isExpired = $row['lock_expire_at'] && strtotime($row['lock_expire_at']) < time();
  $isProtectedStatus = in_array($row['status'], ['preparing', 'ready'], true);

  // Don't auto-clear lock if order is in a protected status
  if ($isExpired && $row['locked_by'] && !$isProtectedStatus) {
    $db->prepare("UPDATE orders SET locked_by = NULL, locked_at = NULL, lock_expire_at = NULL WHERE id = ?")
      ->execute([$orderId]);
    return ['locked' => false, 'by' => null, 'name' => null, 'expired' => true];
  }

  return [
    'locked' => !empty($row['locked_by']),
    'by' => (int) $row['locked_by'],
    'name' => $row['locked_by_name'],
    'expired' => false
  ];
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Handle sendBeacon requests (no Content-Type header, need to parse raw body)
if ($method === 'POST' && empty($_POST)) {
  $rawInput = file_get_contents('php://input');
  parse_str($rawInput, $_POST);
}

// CSRF verification for POST requests
if ($method === 'POST') {
  $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    jsonOut(['success' => false, 'error' => 'Invalid CSRF token'], 403);
  }
}

// Route by action
switch ($action) {

  // ============================================================
  // TEST ENDPOINT (for debugging)
  // GET /api/order-lock.php?action=test
  // ============================================================
  case 'test':
    jsonOut([
      'success' => true,
      'message' => 'API is working',
      'user_id' => $currentUser,
      'time' => date('c')
    ]);
    break;

  // ============================================================
  // CHECK LOCK STATUS
  // GET /api/order-lock.php?action=check&order_id=123
  // ============================================================
  case 'check':
    if ($method !== 'GET') jsonOut(['error' => 'Method not allowed'], 405);

    $orderId = (int) ($_GET['order_id'] ?? 0);
    if ($orderId <= 0) jsonOut(['error' => 'Invalid order ID'], 400);

    $lock = checkOrderLock($db, $orderId);
    jsonOut([
      'success' => true,
      'order_id' => $orderId,
      'locked' => $lock['locked'],
      'locked_by' => $lock['by'],
      'locked_by_name' => $lock['name'],
      'lock_expired' => $lock['expired']
    ]);
    break;

  // ============================================================
  // LOCK ORDER
  // POST /api/order-lock.php?action=lock
  // Body: order_id=123
  // ============================================================
  case 'lock':
    if ($method !== 'POST') jsonOut(['error' => 'Method not allowed'], 405);

    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($orderId <= 0) jsonOut(['error' => 'Invalid order ID'], 400);

    // Fetch order status directly to enforce protected status check
    $orderRow = $db->prepare("SELECT status, locked_by, lock_expire_at FROM orders WHERE id = ?");
    $orderRow->execute([$orderId]);
    $orderInfo = $orderRow->fetch(PDO::FETCH_ASSOC);

    // Block lock acquisition if order is preparing/ready and owned by someone else
    if (
      $orderInfo &&
      in_array($orderInfo['status'], ['preparing', 'ready'], true) &&
      !empty($orderInfo['locked_by']) &&
      (int)$orderInfo['locked_by'] !== $currentUser
    ) {
      $lockedByName = $db->prepare("SELECT full_name FROM cashiers WHERE id = ?");
      $lockedByName->execute([$orderInfo['locked_by']]);
      $name = $lockedByName->fetchColumn();
      jsonOut([
        'success'        => false,
        'error'          => 'order_locked',
        'message'        => "This order is being handled by {$name}",
        'locked_by_name' => $name
      ], 423);
    }

    // Normal lock check for pending orders
    $lock = checkOrderLock($db, $orderId);
    if ($lock['locked'] && $lock['by'] !== $currentUser) {
      jsonOut([
        'success'        => false,
        'error'          => 'order_locked',
        'message'        => "This order is being prepared by {$lock['name']}",
        'locked_by_name' => $lock['name']
      ], 423);
    }

    // Only allow locking pending orders (preparing/ready lock is set by the status update, not here)
    $stmt = $db->prepare(
      "UPDATE orders
     SET locked_by = ?, locked_at = NOW(), lock_expire_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
     WHERE id = ? AND status IN ('pending', 'preparing')"
    );
    $stmt->execute([$currentUser, $orderId]);

    if ($stmt->rowCount() > 0) {
      auditLog(ROLE_CASHIER, $currentUser, 'locked', 'orders', $orderId);
      jsonOut(['success' => true, 'message' => 'Order locked successfully', 'lock_expire_at' => date('c', strtotime('+15 minutes'))]);
    } else {
      jsonOut(['success' => false, 'error' => 'Could not lock order'], 400);
    }
    break;

  // ============================================================
  // UNLOCK ORDER
  // POST /api/order-lock.php?action=unlock
  // Body: order_id=123
  // ============================================================
  case 'unlock':
    if ($method !== 'POST') jsonOut(['error' => 'Method not allowed'], 405);

    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($orderId <= 0) jsonOut(['error' => 'Invalid order ID'], 400);

    // Only allow unlocking if you're the one who locked it (or it's expired)
    $lock = checkOrderLock($db, $orderId);

    if ($lock['locked'] && $lock['by'] !== $currentUser && !$lock['expired']) {
      jsonOut([
        'success' => false,
        'error' => 'not_your_lock',
        'message' => 'You cannot unlock an order locked by another cashier'
      ], 403);
    }

    // Unlock the order
    $stmt = $db->prepare(
      "UPDATE orders
       SET locked_by = NULL, locked_at = NULL, lock_expire_at = NULL
       WHERE id = ?"
    );
    $stmt->execute([$orderId]);

    auditLog(ROLE_CASHIER, $currentUser, 'unlocked', 'orders', $orderId);
    jsonOut(['success' => true, 'message' => 'Order unlocked']);
    break;

  // ============================================================
  // REFRESH LOCK (extend expiry)
  // POST /api/order-lock.php?action=refresh
  // Body: order_id=123
  // ============================================================
  case 'refresh':
    if ($method !== 'POST') jsonOut(['error' => 'Method not allowed'], 405);

    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($orderId <= 0) jsonOut(['error' => 'Invalid order ID'], 400);

    // Only allow refreshing if you're the one who locked it
    $lock = checkOrderLock($db, $orderId);

    if (!$lock['locked'] || $lock['by'] !== $currentUser) {
      jsonOut([
        'success' => false,
        'error' => 'not_your_lock',
        'message' => 'You can only refresh your own locks'
      ], 403);
    }

    // Extend the lock by another 15 minutes
    $stmt = $db->prepare(
      "UPDATE orders
       SET lock_expire_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
       WHERE id = ? AND locked_by = ?"
    );
    $stmt->execute([$orderId, $currentUser]);

    jsonOut([
      'success' => true,
      'message' => 'Lock extended',
      'lock_expire_at' => date('c', strtotime('+15 minutes'))
    ]);
    break;

  // ============================================================
  // GET ALL LOCKED ORDERS (for polling)
  // GET /api/order-lock.php?action=list
  // ============================================================
  case 'list':
    if ($method !== 'GET') jsonOut(['error' => 'Method not allowed'], 405);

    // Get all locked pre-orders with status pending/preparing/ready
    // Orders in "preparing" status stay locked to the preparing cashier
    $stmt = $db->query(
      "SELECT o.id, o.order_number, o.status, o.locked_by, o.lock_expire_at,
              c.full_name AS locked_by_name
       FROM orders o
       LEFT JOIN cashiers c ON o.locked_by = c.id
       WHERE o.order_type = 'pre-order'
         AND o.status IN ('pending', 'preparing', 'ready')
         AND o.locked_by IS NOT NULL
         AND (o.lock_expire_at > NOW() OR o.status IN ('preparing', 'ready'))"
    );
    $locks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Clear expired locks only for orders NOT in "preparing" status
    $db->query(
      "UPDATE orders
       SET locked_by = NULL, locked_at = NULL, lock_expire_at = NULL
       WHERE lock_expire_at IS NOT NULL
         AND lock_expire_at < NOW()
         AND status NOT IN ('preparing', 'ready')"
    );

    jsonOut([
      'success' => true,
      'locks' => $locks,
      'current_cashier_id' => $currentUser
    ]);
    break;

  // ============================================================
  // UNKNOWN ACTION
  // ============================================================
  default:
    jsonOut(['error' => 'Unknown action'], 400);
}
