<?php
// api/order-status.php
// Polled by orders.php every 20s so a customer's order status
// (pending → preparing → ready → claimed) updates live without a
// manual page refresh. Mirrors the XHR-gated pattern used by
// api/preorder-badge.php for the cashier sidebar.
//
// Returns JSON: { orders: [ { id, status, awaiting_payment }, ... ] }
// awaiting_payment is true while an online (PayMongo) order is still unpaid.

require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/paymongo.php';

header('Content-Type: application/json');

if (
  !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
  !in_array(currentRole(), [ROLE_STUDENT, ROLE_FACULTY], true)
) {
  echo json_encode(['orders' => []]);
  exit;
}

$role     = currentRole();
$userId   = currentUserId();
$fkColumn = $role === ROLE_STUDENT ? 'student_id' : 'faculty_id';

$ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
if (empty($ids)) {
  echo json_encode(['orders' => []]);
  exit;
}

$db = Database::getInstance();
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT o.id, o.status,
               (p.gateway = 'paymongo' AND p.payment_status = 'pending' AND o.status = 'pending') AS awaiting_payment
          FROM orders o LEFT JOIN payments p ON p.order_id = o.id
         WHERE o.{$fkColumn} = ? AND o.id IN ({$placeholders})";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge([$userId], $ids));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// A paid online order whose webhook never arrived would sit on "unpaid"
// forever; ask PayMongo (throttled per order, newest few only).
$budget = 3;
foreach ($rows as &$row) {
  if ($budget > 0 && (int) $row['awaiting_payment'] === 1) {
    $budget--;
    if (paymongoReconcileThrottled($db, (int) $row['id'], 15) === 'paid') {
      $row['awaiting_payment'] = 0;
    }
  }
  $row['awaiting_payment'] = (int) $row['awaiting_payment'] === 1;
}
unset($row);

echo json_encode(['orders' => $rows]);
