<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_CASHIER);
$db = Database::getInstance();

$orderId = (int)($_GET['id'] ?? $_SESSION['last_order_id'] ?? 0);
if (!$orderId) {
  redirect(APP_URL . '/cashier/walkin.php');
}

$stmt = $db->prepare(
  "SELECT o.*, p.payment_method, p.amount_paid, p.change_given, p.reference_number,
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
  redirect(APP_URL . '/cashier/walkin.php');
}

$stmt = $db->prepare(
  "SELECT od.quantity, od.price_at_time, od.subtotal, od.customization_note, pr.name
   FROM order_details od
   JOIN products pr ON od.product_id = pr.id
   WHERE od.order_id = ?"
);
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

ob_start();
include __DIR__ . '/receipt_styles.php';
$receiptStyles = ob_get_clean();

layoutHeader('Receipt', $receiptStyles);
?>

<style>
  /* Thermal print button states */
  #thermal-btn {
    min-width: 100px;
  }

  #thermal-btn.printing {
    opacity: 0.6;
    pointer-events: none;
  }

  #thermal-btn.success {
    background: var(--status-ready-bg);
    color: var(--status-ready);
    border-color: var(--status-ready);
  }

  #thermal-btn.error {
    background: var(--status-cancelled-bg);
    color: var(--status-cancelled);
    border-color: var(--status-cancelled);
  }
</style>

<!-- Screen action bar (hidden when printing) -->
<div class="no-print" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-5)">
  <div>
    <div style="font-size:1.15rem;font-weight:800;color:var(--text-color)">Order Receipt</div>
    <div style="font-size:0.78rem;color:var(--text-muted);margin-top:2px"><?= e($order['order_number']) ?></div>
  </div>
  <div style="display:flex;gap:10px">

    <!-- 🖨️ Thermal printer button -->
    <button id="thermal-btn" onclick="thermalPrint()" class="btn btn-primary">
      <i class="fa-solid fa-print"></i> Print Receipt
    </button>

    <a href="<?= APP_URL ?>/cashier/orders.php" class="btn btn-ghost">
      <i class="fa-solid fa-list"></i> Order History
    </a>
    <a href="<?= APP_URL ?>/cashier/walkin.php" class="btn btn-ghost">
      <i class="fa-solid fa-plus"></i> New Order
    </a>
  </div>
</div>

<!-- Receipt paper — screen display only -->
<div class="rcpt-print-root">
  <?php include __DIR__ . '/receipt_template.php'; ?>
</div>

<?php layoutFooter(); ?>

<script>
  const THERMAL_URL = '<?= APP_URL ?>/cashier/thermal_receipt.php?order_id=<?= $orderId ?>';

  function thermalPrint() {
    const btn = document.getElementById('thermal-btn');
    btn.classList.add('printing');
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Printing…';

    fetch(THERMAL_URL)
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          btn.classList.remove('printing');
          btn.classList.add('success');
          btn.innerHTML = '<i class="fa-solid fa-check"></i> Sent!';
          setTimeout(resetBtn, 3000);
        } else {
          throw new Error(data.error || 'Printer error');
        }
      })
      .catch(err => {
        btn.classList.remove('printing');
        btn.classList.add('error');
        btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Failed';
        btn.title = err.message;
        console.error('[Thermal]', err.message);
        setTimeout(resetBtn, 4000);
      });
  }

  function resetBtn() {
    const btn = document.getElementById('thermal-btn');
    btn.classList.remove('printing', 'success', 'error');
    btn.innerHTML = '<i class="fa-solid fa-print"></i> Print Receipt';
    btn.title = '';
  }
</script>