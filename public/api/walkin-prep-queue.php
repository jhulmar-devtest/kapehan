<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_CASHIER);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
$db = Database::getInstance();

function walkinPrepQueue(PDO $db): array
{
  $rows = $db->query(
    "SELECT o.id, o.order_number, o.total_amount, o.created_at,
            TIMESTAMPDIFF(SECOND, o.created_at, NOW()) AS elapsed_seconds,
            GROUP_CONCAT(
              CONCAT(od.quantity, '× ', p.name,
                IF(od.customization_note IS NOT NULL AND od.customization_note != '', CONCAT(' (', od.customization_note, ')'), ''))
              ORDER BY od.id SEPARATOR ' · '
            ) AS items
       FROM orders o
       JOIN order_details od ON od.order_id = o.id
       JOIN products p ON p.id = od.product_id
      WHERE o.order_type = 'walk-in'
        AND o.status = 'claimed'
        AND o.walkin_prep_status = 'preparing'
      GROUP BY o.id, o.order_number, o.total_amount, o.created_at
      ORDER BY o.created_at ASC, o.id ASC"
  )->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as &$row) {
    $row['id'] = (int) $row['id'];
    $row['total_amount'] = (float) $row['total_amount'];
    $row['elapsed_seconds'] = max(0, (int) $row['elapsed_seconds']);
  }
  unset($row);
  return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  try {
    $orders = walkinPrepQueue($db);
    echo json_encode(['ok' => true, 'orders' => $orders]);
  } catch (Throwable $e) {
    error_log('Could not read walk-in preparation queue: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not load walk-in preparation tickets.']);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Use GET to view or POST to complete a preparation ticket.']);
  exit;
}

verifyCsrfAjax();
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$orderId = max(0, (int) ($data['order_id'] ?? 0));
if ($orderId < 1) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'Choose a valid walk-in ticket.']);
  exit;
}

try {
  $db->beginTransaction();
  lockAsapPreparationWorkload($db, date('Y-m-d'));
  $stmt = $db->prepare("SELECT id, walkin_prep_status FROM orders WHERE id = ? AND order_type = 'walk-in' AND status = 'claimed' FOR UPDATE");
  $stmt->execute([$orderId]);
  $order = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$order || $order['walkin_prep_status'] !== 'preparing') {
    throw new RuntimeException('This ticket is no longer in the preparation queue.');
  }
  $update = $db->prepare("UPDATE orders SET walkin_prep_status = 'completed', walkin_completed_at = NOW() WHERE id = ? AND walkin_prep_status = 'preparing'");
  $update->execute([$orderId]);
  if ($update->rowCount() !== 1) throw new RuntimeException('This ticket is no longer in the preparation queue.');
  $db->commit();
  auditLog(ROLE_CASHIER, currentUserId(), 'complete_walkin_preparation', 'orders', $orderId);
  echo json_encode(['ok' => true, 'message' => 'Walk-in ticket completed.']);
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  if ($e instanceof RuntimeException) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
  } else {
    error_log('Could not complete walk-in preparation: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not complete this ticket. Please try again.']);
  }
}
