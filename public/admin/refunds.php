<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/loyalty.php';
require_once __DIR__ . '/../../includes/refund-qr.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_refund'])) {
  verifyCsrf();
  $requestId = (int) ($_POST['request_id'] ?? 0);
  $refundReference = trim((string) ($_POST['refund_reference'] ?? ''));
  if ($refundReference === '' || mb_strlen($refundReference) > 100) {
    flash('global', 'Enter the refund transaction reference (up to 100 characters).', 'error');
    redirect(APP_URL . '/admin/refunds.php');
  }

  try {
    $db->beginTransaction();
    $stmt = $db->prepare(
      "SELECT cr.id, cr.order_id, cr.status AS request_status, o.status AS order_status,
              o.order_number, p.payment_method, p.gateway, p.payment_status, cr.refund_qr_filename
         FROM order_cancellation_requests cr
         JOIN orders o ON o.id = cr.order_id
         LEFT JOIN payments p ON p.order_id = o.id
        WHERE cr.id = ? FOR UPDATE"
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['request_status'] !== 'refund_required') {
      throw new RuntimeException('This refund is no longer awaiting admin review.');
    }
    if ($row['payment_status'] !== PAY_STATUS_PAID) {
      throw new RuntimeException('The payment is not recorded as received, so it cannot be marked refunded.');
    }
    $isManualGcash = strtolower((string) $row['payment_method']) === 'gcash'
      && ($row['gateway'] ?? '') !== 'paymongo';
    if ($isManualGcash) {
      $referenceError = gcashRefInputError($refundReference);
      if ($referenceError !== null) throw new RuntimeException('Refund GCash ' . lcfirst($referenceError));
    }

    $db->prepare('UPDATE payments SET payment_status = ?, updated_at = NOW() WHERE order_id = ? AND payment_status = ?')
      ->execute([PAY_STATUS_REFUNDED, $row['order_id'], PAY_STATUS_PAID]);
    if ($row['order_status'] !== STATUS_CANCELLED) {
      if ($row['order_status'] !== STATUS_PENDING) {
        throw new RuntimeException('This order has already started preparation. Contact the customer and resolve it manually before recording the refund.');
      }
      $db->prepare("UPDATE orders SET status = ?, cashier_id = NULL, locked_by = NULL, locked_at = NULL, lock_expire_at = NULL WHERE id = ?")
        ->execute([STATUS_CANCELLED, $row['order_id']]);
      loyaltyReverseOrder($db, (int) $row['order_id'], ROLE_ADMIN, currentUserId());
      restoreInventoryForCancelledOrder($db, (int) $row['order_id'], ROLE_ADMIN, currentUserId());
    }
    $db->prepare(
      "UPDATE order_cancellation_requests
          SET status = 'refunded', refund_reference = ?, refund_qr_filename = NULL,
              handled_by_admin_id = ?, handled_at = NOW()
        WHERE id = ?"
    )->execute([$refundReference, currentUserId(), $requestId]);
    $db->commit();
    deleteRefundQrFile($row['refund_qr_filename'] ?? null);
    auditLog(ROLE_ADMIN, currentUserId(), 'record_order_refund', 'orders', (int) $row['order_id']);
    flash('global', 'Refund recorded and the cancellation workflow is complete.', 'success');
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('global', $e instanceof RuntimeException ? $e->getMessage() : 'Could not record the refund. Please try again.', 'error');
  }
  redirect(APP_URL . '/admin/refunds.php');
}

$requests = $db->query(
  "SELECT cr.id, cr.order_id, cr.requester_role, cr.reason, cr.status, cr.created_at,
          o.order_number, o.status AS order_status, o.total_amount, o.pickup_date, o.pickup_time,
          p.payment_method, p.payment_status, p.reference_number, p.gateway,
          cr.refund_gcash_name, cr.refund_gcash_number, cr.refund_qr_filename,
          COALESCE(s.full_name, f.full_name, c.full_name, 'Staff') AS customer_name
     FROM order_cancellation_requests cr
     JOIN orders o ON o.id = cr.order_id
     LEFT JOIN payments p ON p.order_id = o.id
     LEFT JOIN students s ON o.student_id = s.id
     LEFT JOIN faculty f ON o.faculty_id = f.id
     LEFT JOIN cashiers c ON cr.requester_role = 'cashier' AND cr.requester_id = c.id
    WHERE cr.status = 'refund_required'
    ORDER BY cr.created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

layoutHeader('Refunds');
?>
<style>
  .refund-page {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 350px), 1fr));
    align-items: start;
    justify-items: start;
    gap: 16px;
  }

  .refund-intro {
    display: flex;
    align-items: flex-start;
    gap: 14px;
  }

  .refund-intro-icon {
    display: grid;
    place-items: center;
    width: 46px;
    height: 46px;
    flex: 0 0 auto;
    color: var(--status-pending);
    background: var(--status-pending-bg);
    border: 1px solid var(--status-pending-border);
    border-radius: 14px;
    font-size: 18px;
  }

  .refund-card {
    width: 100%;
    max-width: 440px;
    padding: clamp(16px, 3vw, 24px);
    overflow: hidden;
    border-top: 3px solid var(--status-pending);
  }

  .refund-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    flex-wrap: wrap;
  }

  .refund-order-number {
    font-size: 1.08rem;
    line-height: 1.3;
    overflow-wrap: anywhere;
  }

  .refund-customer-line {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
    margin-top: 6px;
    color: var(--text-muted);
    font-size: 0.82rem;
  }

  .refund-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 10px;
    color: var(--status-pending);
    background: var(--status-pending-bg);
    border: 1px solid var(--status-pending-border);
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 700;
    white-space: nowrap;
  }

  .refund-details {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin-top: 14px;
  }

  .refund-detail {
    min-width: 0;
    padding: 10px;
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm, 10px);
  }

  .refund-detail-label {
    display: block;
    margin-bottom: 5px;
    color: var(--text-muted);
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.045em;
  }

  .refund-detail-value {
    display: block;
    color: var(--text-color);
    font-size: 0.88rem;
    font-weight: 650;
    line-height: 1.4;
    overflow-wrap: anywhere;
  }

  .refund-detail-amount .refund-detail-value {
    color: var(--primary-color);
    font-size: 1.08rem;
  }

  .refund-reason {
    margin-top: 14px;
    padding: 13px 15px;
    background: var(--status-pending-bg);
    border-left: 3px solid var(--status-pending);
    border-radius: 0 var(--radius-sm, 10px) var(--radius-sm, 10px) 0;
  }

  .refund-reason-label {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 5px;
    color: var(--status-pending);
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .refund-reason-text {
    color: var(--text-color);
    font-size: 0.88rem;
    line-height: 1.5;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
  }

  .refund-destination {
    margin-top: 12px;
    padding: 12px;
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm, 10px);
  }

  .refund-destination-title {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 9px;
    color: var(--text-color);
    font-size: 0.8rem;
    font-weight: 800;
  }

  .refund-destination-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }

  .refund-destination-value {
    display: block;
    color: var(--text-color);
    font-size: 0.85rem;
    font-weight: 700;
    overflow-wrap: anywhere;
  }

  .refund-destination-qr {
    display: block;
    width: 148px;
    height: 148px;
    margin-top: 10px;
    padding: 6px;
    object-fit: contain;
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm, 10px);
  }

  .refund-destination-missing {
    color: var(--status-pending);
    font-size: 0.78rem;
    line-height: 1.45;
  }

  .refund-instruction {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 12px;
    padding: 10px 12px;
    color: var(--text-secondary);
    background: var(--color-info-bg, #eef6fb);
    border: 1px solid var(--color-info-border, #c9e1f0);
    border-radius: var(--radius-sm, 10px);
    font-size: 0.8rem;
    line-height: 1.5;
  }

  .refund-instruction > i {
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--color-info, #28749b);
  }

  .refund-form {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border-color);
  }

  .refund-reference-field {
    min-width: 0;
    margin: 0;
  }

  .refund-action-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: stretch;
    gap: 8px;
    width: 100%;
  }

  .refund-action-row .form-control {
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
  }

  .refund-reference-field .form-control {
    min-height: 44px;
    font-family: monospace;
    letter-spacing: 0.025em;
  }

  .refund-reference-hint {
    display: block;
    margin-top: 6px;
    font-size: 0.74rem;
    line-height: 1.45;
  }

  .refund-submit {
    min-height: 44px;
    padding-inline: 12px;
    max-width: 100%;
    white-space: nowrap;
  }

  .refund-empty {
    padding: clamp(28px, 6vw, 52px) 20px;
    text-align: center;
  }

  .refund-empty-icon {
    display: grid;
    place-items: center;
    width: 58px;
    height: 58px;
    margin: 0 auto 14px;
    color: var(--color-success, #28845a);
    background: var(--color-success-bg, #eaf6ef);
    border-radius: 50%;
    font-size: 23px;
  }

  .refund-empty h3 {
    margin: 0;
  }

  .refund-empty p {
    max-width: 440px;
    margin: 7px auto 0;
    color: var(--text-muted);
    line-height: 1.5;
  }

  @media (max-width: 560px) {
    .refund-intro-icon {
      width: 40px;
      height: 40px;
      border-radius: 12px;
    }

    .refund-detail {
      padding: 10px;
    }
  }
</style>
<div class="page-header">
  <div class="refund-intro">
    <div class="refund-intro-icon"><i class="fa-solid fa-arrow-rotate-left"></i></div>
    <div>
      <div class="text-muted" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:3px">Order management</div>
    <div class="page-header-title">Refund review</div>
    <div class="page-header-sub">Complete the refund in the payment account first, then record its transaction reference here.</div>
    </div>
  </div>
  <div class="page-header-actions"><a href="<?= APP_URL ?>/admin/sales.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Sales</a></div>
</div>
<?php showFlash('global'); ?>

<?php if (!$requests): ?>
  <div class="card refund-empty">
    <div class="refund-empty-icon"><i class="fa-solid fa-check"></i></div>
    <h3>All caught up</h3>
    <p>There are no refunds waiting for review. Orders needing a refund will appear here after cashier review.</p>
  </div>
<?php else: ?>
  <div class="refund-page">
    <?php foreach ($requests as $request): ?>
      <?php $manualGcashRefund = strtolower((string) ($request['payment_method'] ?? '')) === 'gcash' && ($request['gateway'] ?? '') !== 'paymongo'; ?>
      <section class="card refund-card">
        <div class="refund-card-header">
          <div>
            <div class="refund-order-number"><strong><?= e($request['order_number']) ?></strong></div>
            <div class="refund-customer-line">
              <i class="fa-regular fa-user"></i><span><?= e($request['customer_name']) ?></span>
              <span aria-hidden="true">·</span><span><?= e(ucfirst($request['requester_role'])) ?> request</span>
              <span aria-hidden="true">·</span><time datetime="<?= e(date(DATE_ATOM, strtotime($request['created_at']))) ?>"><?= e(date('M j, Y · g:i A', strtotime($request['created_at']))) ?></time>
            </div>
          </div>
          <span class="refund-status-pill"><i class="fa-solid fa-hourglass-half"></i> Refund required</span>
        </div>

        <div class="refund-details">
          <div class="refund-detail refund-detail-amount"><span class="refund-detail-label">Amount to return</span><strong class="refund-detail-value"><?= peso($request['total_amount']) ?></strong></div>
          <div class="refund-detail"><span class="refund-detail-label">Payment method</span><strong class="refund-detail-value"><?= e($request['payment_method'] ?? '—') ?><?= ($request['gateway'] ?? '') === 'paymongo' ? ' · PayMongo' : ' · Manual' ?></strong></div>
          <div class="refund-detail"><span class="refund-detail-label">Original payment reference</span><strong class="refund-detail-value"><?= e($request['reference_number'] ?: (($request['gateway'] ?? '') === 'paymongo' ? 'PayMongo payment' : 'Not provided')) ?></strong></div>
          <div class="refund-detail"><span class="refund-detail-label">Order status</span><strong class="refund-detail-value"><?= e(ucfirst($request['order_status'])) ?></strong></div>
        </div>

        <div class="refund-reason">
          <div class="refund-reason-label"><i class="fa-solid fa-comment-dots"></i> Cancellation reason</div>
          <div class="refund-reason-text"><?= e($request['reason']) ?></div>
        </div>

        <?php if ($manualGcashRefund): ?>
          <div class="refund-destination">
            <div class="refund-destination-title"><i class="fa-solid fa-mobile-screen-button"></i> GCash refund destination</div>
            <?php if (!empty($request['refund_gcash_name']) && !empty($request['refund_gcash_number'])): ?>
              <div class="refund-destination-grid">
                <div><span class="refund-detail-label">Account name</span><strong class="refund-destination-value"><?= e($request['refund_gcash_name']) ?></strong></div>
                <div><span class="refund-detail-label">GCash number</span><strong class="refund-destination-value"><?= e($request['refund_gcash_number']) ?></strong></div>
              </div>
              <?php if (!empty($request['refund_qr_filename'])): ?>
                <img class="refund-destination-qr" src="<?= APP_URL ?>/admin/refund-qr.php?id=<?= (int) $request['id'] ?>" alt="Customer's GCash QR code" loading="lazy">
              <?php endif; ?>
            <?php else: ?>
              <div class="refund-destination-missing">The customer did not provide refund destination details. Contact them before sending money.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="refund-instruction" role="note">
          <i class="fa-solid fa-circle-info"></i>
          <div><strong>Refund the customer first.</strong> Send the money through the payment account shown above. Then enter the reference from that refund transaction here. Recording it will mark the order as refunded in this system.</div>
        </div>

        <form method="POST" class="refund-form">
          <?= csrfField() ?><input type="hidden" name="complete_refund" value="1"><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
          <div class="form-group refund-reference-field">
            <label class="form-label" for="refund-reference-<?= (int) $request['id'] ?>"><?= $manualGcashRefund ? 'GCash refund reference' : 'Refund transaction reference' ?></label>
            <div class="refund-action-row">
              <input class="form-control" id="refund-reference-<?= (int) $request['id'] ?>" name="refund_reference" type="text" required autocomplete="off" <?= $manualGcashRefund ? 'inputmode="numeric" pattern="[0-9]{' . (int) GCASH_REF_DIGITS . '}" maxlength="' . (int) GCASH_REF_DIGITS . '"' : 'maxlength="100"' ?> placeholder="<?= $manualGcashRefund ? (int) GCASH_REF_DIGITS . '-digit GCash reference' : 'Enter the refund reference or ID' ?>" aria-describedby="refund-reference-hint-<?= (int) $request['id'] ?>">
              <button class="btn btn-primary refund-submit" type="submit"><i class="fa-solid fa-check"></i> Record refund</button>
            </div>
            <small class="text-muted refund-reference-hint" id="refund-reference-hint-<?= (int) $request['id'] ?>"><?= $manualGcashRefund ? 'Enter the ' . (int) GCASH_REF_DIGITS . '-digit reference from the GCash transfer used to return the money.' : (($request['gateway'] ?? '') === 'paymongo' ? 'Enter the refund reference or ID shown by PayMongo after the refund is completed.' : 'Enter the transaction reference shown by the account used to return the money.') ?></small>
          </div>
        </form>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<script>
  document.querySelectorAll('input[name="refund_reference"][inputmode="numeric"]').forEach((input) => {
    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D/g, '').slice(0, input.maxLength);
    });
  });
</script>
<?php layoutFooter(); ?>
