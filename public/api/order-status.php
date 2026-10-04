<?php
// api/order-status.php
// Polled by orders.php every 20s so a customer's order status
// (pending → preparing → ready → claimed) updates live without a
// manual page refresh. Mirrors the XHR-gated pattern used by
// api/preorder-badge.php for the cashier sidebar.
//
// Returns JSON: { orders: [ { id, status }, ... ] }

require_once __DIR__ . '/../../config/init.php';

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
$stmt = $db->prepare(
  "SELECT id, status FROM orders WHERE {$fkColumn} = ? AND id IN ({$placeholders})"
);
$stmt->execute(array_merge([$userId], $ids));

echo json_encode(['orders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
