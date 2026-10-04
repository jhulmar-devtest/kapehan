<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_STUDENT);
$db  = Database::getInstance();
$uid = currentUserId();
$oid = (int)($_GET['order'] ?? 0);

$stmt = $db->prepare("SELECT o.*,p.payment_status FROM orders o LEFT JOIN payments p ON o.id=p.order_id WHERE o.id=? AND o.student_id=?");
$stmt->execute([$oid, $uid]);
$order = $stmt->fetch();
if (!$order || $order['status'] !== STATUS_CANCELLED) {
  flash('global', 'Invalid refund request.', 'error');
  redirect(APP_URL . '/orders.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();
  $reason = sanitizeString($_POST['reason'] ?? '', 500);
  if (empty($reason)) {
    flash('global', 'Please provide a reason.', 'error');
    redirect(APP_URL . '/student/refund.php?order=' . $oid);
  }
  // Check not already requested
  $chk = $db->prepare("SELECT id FROM refund_requests WHERE order_id=?");
  $chk->execute([$oid]);
  if ($chk->fetch()) {
    flash('global', 'A refund request already exists for this order.', 'warning');
    redirect(APP_URL . '/orders.php');
  }
  $db->prepare("INSERT INTO refund_requests (order_id,student_id,reason) VALUES (?,?,?)")->execute([$oid, $uid, $reason]);
  flash('global', 'Refund request submitted. The admin will review it.', 'success');
  redirect(APP_URL . '/orders.php');
}

layoutHeader('Request Refund');
?>
<div style="max-width:500px">
  <h2 class="mb-4" style="font-size:1.2rem">Request Refund</h2>
  <?php showFlash('global'); ?>
  <div class="card">
    <div class="card-body">
      <div class="alert alert-info mb-4"><i class="fa-solid fa-circle-info"></i>
        <div>Refund for order <strong><?= e($order['order_number']) ?></strong> — <?= peso($order['total_amount']) ?>. The admin will review and approve or reject your request.</div>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <div class="form-group">
          <label class="form-label">Reason for Refund <span style="color:var(--status-cancelled)">*</span></label>
          <textarea name="reason" class="form-control" rows="4" required placeholder="Explain why you are requesting a refund…"></textarea>
        </div>
        <div style="display:flex;gap:10px">
          <a href="<?= APP_URL ?>/orders.php" class="btn btn-ghost">Cancel</a>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php layoutFooter(); ?>