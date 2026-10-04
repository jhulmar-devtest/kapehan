<?php

/**
 * receipt_view.php — Shared receipt view endpoint
 *
 * Used by: admin/orders.php (modal), cashier/orders.php (modal)
 * Returns: Standalone HTML with receipt styles, or JSON if requested via XHR
 *
 * Parameters:
 *   order_id (required) — the order ID to display
 *   format (optional) — 'html' (default), 'print' (standalone for popup print)
 */
require_once __DIR__ . '/../config/init.php';

// SECURITY FIX: this file had no access control at all — anyone, logged
// in or not, could view any order's full receipt (customer name, items,
// payment reference number, amount) by guessing/iterating order_id in
// the URL. Both real callers (admin/orders.php and cashier/orders.php)
// are staff-only pages, so gate this the same way.
requireRole(ROLE_ADMIN, ROLE_CASHIER);

$db = Database::getInstance();

$orderId = (int)($_GET['order_id'] ?? 0);
$format  = $_GET['format'] ?? 'html';

if (!$orderId) {
  http_response_code(400);
  echo 'Order ID required';
  exit;
}

// Fetch order data (same query used by both admin and cashier)
$stmt = $db->prepare(
  "SELECT o.*, p.payment_method, p.amount_paid, p.change_given,
          p.reference_number,
          c.full_name AS cashier_name,
          COALESCE(s.full_name, f.full_name, 'Walk-in') AS customer_name
   FROM orders o
   LEFT JOIN payments p ON o.id = p.order_id
   LEFT JOIN cashiers c ON o.cashier_id = c.id
   LEFT JOIN students s ON o.student_id = s.id
   LEFT JOIN faculty  f ON o.faculty_id = f.id
   WHERE o.id = ?"
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
  http_response_code(404);
  echo 'Order not found';
  exit;
}

// Fetch line items
$stmt = $db->prepare(
  "SELECT od.quantity, od.price_at_time, od.subtotal,
          od.customization_note, pr.name
   FROM order_details od
   JOIN products pr ON od.product_id = pr.id
   WHERE od.order_id = ?"
);
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

// Pull in receipt styles
ob_start();
include __DIR__ . '/cashier/receipt_styles.php';
$receiptStyles = ob_get_clean();

// If XHR request, return JSON with HTML payload
if (
  !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
  ob_start();
  include __DIR__ . '/cashier/receipt_template.php';
  $receiptHtml = ob_get_clean();

  header('Content-Type: application/json');
  echo json_encode([
    'success' => true,
    'order'   => $order,
    'items'   => $items,
    'html'    => $receiptStyles . $receiptHtml
  ]);
  exit;
}

// For print format: standalone popup window
if ($format === 'print') {
?>
  <!DOCTYPE html>
  <html>

  <head>
    <meta charset="UTF-8">
    <title>Receipt #<?= e($order['order_number']) ?></title>
    <?= $receiptStyles ?>
    <style>
      body {
        margin: 0;
        padding: 20px;
        background: #f5f5f5;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: 100vh;
      }

      .rcpt-paper {
        background: #fff;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
      }

      @media print {
        body {
          background: #fff;
          padding: 0;
        }

        .rcpt-paper {
          box-shadow: none;
        }
      }
    </style>
  </head>

  <body>
    <div class="rcpt-print-root">
      <?php include __DIR__ . '/cashier/receipt_template.php'; ?>
    </div>
    <script>
      // Auto-print when loaded, but allow manual print too
      window.addEventListener('load', () => {
        setTimeout(() => window.print(), 200);
      });
    </script>
  </body>

  </html>
<?php
  exit;
}

// Default: HTML only (for modal injection)
echo $receiptStyles;
include __DIR__ . '/cashier/receipt_template.php';