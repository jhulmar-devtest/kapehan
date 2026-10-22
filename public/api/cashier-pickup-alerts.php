<?php
// Returns today's active pre-orders for the cashier's shared pickup alert.
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (currentRole() !== ROLE_CASHIER) {
  jsonResponse(['success' => false, 'message' => 'Cashier access required.'], 403);
}

try {
  $db = Database::getInstance();
  $orders = $db->query(
    "SELECT id, order_number, status,
            DATE_FORMAT(pickup_date, '%Y-%m-%d') AS pickup_date,
            CASE WHEN pickup_time = 'ASAP' THEN 'ASAP' ELSE pickup_time END AS pickup_time
     FROM orders
     WHERE order_type = 'pre-order'
       AND status IN ('pending', 'preparing', 'ready')
       AND pickup_date = CURDATE()
     ORDER BY CASE WHEN pickup_time = 'ASAP' THEN 0 ELSE 1 END,
              STR_TO_DATE(pickup_time, '%H:%i') ASC,
              created_at ASC"
  )->fetchAll(PDO::FETCH_ASSOC);

  jsonResponse(['success' => true, 'server_now' => date('c'), 'orders' => $orders]);
} catch (Throwable $error) {
  error_log('Cashier pickup alert poll failed: ' . $error->getMessage());
  jsonResponse(['success' => false, 'message' => 'Pickup alerts are temporarily unavailable.'], 500);
}
