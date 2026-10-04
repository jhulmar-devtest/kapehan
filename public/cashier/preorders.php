<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_CASHIER);
$db = Database::getInstance();
$currentUser = currentUserId();

// Clear expired locks on page load (but not for orders in "preparing" status)
$db->query(
  "UPDATE orders
   SET locked_by = NULL, locked_at = NULL, lock_expire_at = NULL
   WHERE lock_expire_at IS NOT NULL AND lock_expire_at < NOW() AND status NOT IN ('preparing', 'ready')"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
  verifyCsrf();
  $oid    = (int)$_POST['order_id'];
  $status = $_POST['new_status'] ?? '';
  $allowed = [STATUS_PREPARING, STATUS_READY, STATUS_CLAIMED, STATUS_CANCELLED];

  // Check if order is locked by another cashier (unless cancelling)
  if ($status !== STATUS_CANCELLED) {
    $lock = $db->prepare(
      "SELECT locked_by, lock_expire_at, status, c.full_name AS locked_by_name
       FROM orders o
       LEFT JOIN cashiers c ON o.locked_by = c.id
       WHERE o.id = ?"
    );
    $lock->execute([$oid]);
    $lockInfo = $lock->fetch(PDO::FETCH_ASSOC);

    // For "preparing" and "ready" statuses, the lock persists regardless of expiry
    $isExpired = $lockInfo && $lockInfo['lock_expire_at'] && strtotime($lockInfo['lock_expire_at']) < time();
    $isLockedStatus = $lockInfo && in_array($lockInfo['status'], [STATUS_PREPARING, STATUS_READY], true);

    // Block if locked by someone else (and either not expired or in a locked status)
    if ($lockInfo && $lockInfo['locked_by'] && $lockInfo['locked_by'] != $currentUser) {
      if ($isLockedStatus || !$isExpired) {
        flash('global', "Order is being prepared by {$lockInfo['locked_by_name']}.", 'warning');
        redirect(APP_URL . '/cashier/preorders.php');
      }
    }
  }

  if (in_array($status, $allowed, true)) {
    // Update status - keep lock if status is "preparing" OR "ready", clear otherwise
    if ($status === STATUS_PREPARING || $status === STATUS_READY) {
      // Keep the lock for preparing status, refresh expiry
      $db->prepare("UPDATE orders SET status=?,cashier_id=?,lock_expire_at=DATE_ADD(NOW(),INTERVAL 15 MINUTE) WHERE id=?")
        ->execute([$status, $currentUser, $oid]);
    } else {
      // Clear lock for other statuses (ready, claimed, cancelled)
      $db->prepare("UPDATE orders SET status=?,cashier_id=?,locked_by=NULL,locked_at=NULL,lock_expire_at=NULL WHERE id=?")
        ->execute([$status, $currentUser, $oid]);
    }
    auditLog(ROLE_CASHIER, $currentUser, "status_{$status}", 'orders', $oid);
    flash('global', "Order updated to: {$status}.", 'success');
  }
  redirect(APP_URL . '/cashier/preorders.php');
}

// Unlock orders locked by this cashier when they leave the page
// This is handled via beforeunload event in JS

$orders = $db->query(
  "SELECT o.id, o.order_number, o.status, o.total_amount, o.created_at, o.notes,
          o.locked_by, o.locked_at, o.lock_expire_at, o.pickup_time,
          COALESCE(s.full_name, f.full_name) AS customer_name,
          COALESCE(s.student_id_no, f.faculty_id_no) AS customer_id,
          p.payment_method, p.reference_number,
          c.full_name AS locked_by_name,
          GROUP_CONCAT(CONCAT(od.quantity,'× ',pr.name,
            IF(od.customization_note IS NOT NULL AND od.customization_note != '',
               CONCAT(' (',od.customization_note,')'), ''))
            ORDER BY pr.name SEPARATOR '\n') AS items
   FROM orders o
   LEFT JOIN students s ON o.student_id = s.id
   LEFT JOIN faculty f ON o.faculty_id = f.id
   JOIN order_details od ON o.id = od.order_id
   JOIN products pr ON od.product_id = pr.id
   LEFT JOIN payments p ON o.id = p.order_id
   LEFT JOIN cashiers c ON o.locked_by = c.id
   WHERE o.order_type = 'pre-order'
     AND o.status IN ('pending','preparing','ready')
   GROUP BY o.id, o.order_number, o.status, o.total_amount, o.created_at, o.notes,
            o.locked_by, o.locked_at, o.lock_expire_at,
            customer_name, customer_id, p.payment_method, p.reference_number, c.full_name
   ORDER BY
     FIELD(o.status,'ready','preparing','pending'),
     CASE WHEN o.pickup_time = 'ASAP' THEN 0 ELSE 1 END ASC,
     CASE WHEN o.pickup_time IS NULL OR o.pickup_time = 'ASAP' THEN NULL ELSE STR_TO_DATE(o.pickup_time, '%H:%i') END ASC,
     o.created_at ASC"
)->fetchAll();


$counts = ['pending' => 0, 'preparing' => 0, 'ready' => 0];
foreach ($orders as $o) $counts[$o['status']] = ($counts[$o['status']] ?? 0) + 1;

// Payment method icon + color map
$payIcons = [
  'GCash'          => ['icon' => 'fa-mobile-screen-button', 'label' => 'GCash'],
  'PayMaya'        => ['icon' => 'fa-wallet',               'label' => 'PayMaya'],
  'Online Banking' => ['icon' => 'fa-building-columns',     'label' => 'Bank Transfer'],
  'online'         => ['icon' => 'fa-credit-card',          'label' => 'Online'],
  'cash'           => ['icon' => 'fa-money-bill-wave',       'label' => 'Cash'],
];

layoutHeader('Pre-orders', '');
?>
<style>
  /* ── Pre-order queue ───────────────────────────────────────── */
  .queue-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: var(--space-4);
  }

  /* Status accent left-border */
  .order-card {
    background: var(--surface-color);
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  .order-card.status-ready {
    border-color: var(--status-ready-border);
  }

  .order-card.status-preparing {
    border-color: var(--status-preparing-border);
  }

  .order-card.status-pending {
    border-color: var(--status-pending-border);
  }

  /* Card header */
  .order-card-head {
    padding: var(--space-3) var(--space-4);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    border-bottom: 1px solid var(--border-color);
  }

  .order-card-head.status-ready {
    background: var(--status-ready-bg);
  }

  .order-card-head.status-preparing {
    background: var(--status-preparing-bg);
  }

  .order-card-head.status-pending {
    background: var(--status-pending-bg);
  }

  .order-number {
    font-size: 0.86rem;
    font-weight: 800;
    letter-spacing: -0.01em;
  }

  .order-time {
    font-size: 0.72rem;
    color: var(--text-muted);
  }

  /* Card body */
  .order-card-body {
    padding: var(--space-4);
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  /* Student row */
  .student-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
  }

  .student-avatar {
    width: 34px;
    height: 34px;
    border-radius: var(--radius-full);
    background: var(--surface-sunken);
    border: 1.5px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 13px;
    flex-shrink: 0;
  }

  .student-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--text-color);
  }

  .student-id {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 1px;
    font-family: monospace;
    letter-spacing: 0.04em;
  }

  /* Items summary */
  .items-box {
    background: var(--surface-raised);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-xs);
    padding: var(--space-2) var(--space-3);
    font-size: 0.78rem;
    color: var(--text-secondary);
    line-height: 1.6;
  }

  /* Payment verification box — the KEY section */
  .payment-verify {
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-sm);
    overflow: hidden;
  }

  .payment-verify-header {
    background: var(--surface-raised);
    border-bottom: 1px solid var(--border-color);
    padding: 6px 12px;
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    color: var(--text-muted);
  }

  .payment-verify-header i {
    font-size: 11px;
    color: var(--primary-color);
  }

  .payment-verify-body {
    padding: var(--space-3) var(--space-3);
    display: flex;
    align-items: center;
    gap: var(--space-3);
  }

  .payment-method-badge {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    background: var(--surface-sunken);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-xs);
    padding: 4px 10px;
    font-size: 0.76rem;
    font-weight: 700;
    color: var(--text-secondary);
    white-space: nowrap;
    flex-shrink: 0;
  }

  .payment-method-badge i {
    font-size: 12px;
    color: var(--primary-color);
  }

  .ref-number-wrap {
    flex: 1;
    min-width: 0;
  }

  .ref-label {
    font-size: 0.62rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 2px;
  }

  .ref-number {
    font-family: 'Courier New', monospace;
    font-size: 0.92rem;
    font-weight: 700;
    color: var(--text-color);
    letter-spacing: 0.04em;
    word-break: break-all;
  }

  .ref-missing {
    font-size: 0.80rem;
    color: var(--status-cancelled);
    font-style: italic;
    font-weight: 500;
  }

  /* Total row */
  .order-total-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .order-total-amount {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--primary-color);
    letter-spacing: -0.01em;
  }

  /* Notes */
  .order-notes {
    font-size: 0.76rem;
    color: var(--text-secondary);
    font-style: italic;
    padding: var(--space-2) var(--space-3);
    background: var(--accent-subtle);
    border: 1px solid rgba(240, 180, 41, 0.20);
    border-radius: var(--radius-xs);
  }

  .order-notes i {
    color: var(--accent-dark);
    margin-right: 4px;
  }

  /* Action footer */
  .order-card-foot {
    padding: var(--space-3) var(--space-4);
    border-top: 1px solid var(--border-color);
    background: var(--surface-raised);
    display: flex;
    gap: var(--space-2);
    align-items: center;
    flex-wrap: wrap;
  }

  /* Verify warning on pending */
  .verify-notice {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    font-size: 0.74rem;
    color: var(--status-pending);
    padding: var(--space-2) var(--space-3);
    background: var(--status-pending-bg);
    border: 1px solid var(--status-pending-border);
    border-radius: var(--radius-xs);
    margin-bottom: var(--space-3);
    line-height: 1.5;
  }

  .verify-notice i {
    font-size: 12px;
    flex-shrink: 0;
    margin-top: 1px;
  }

  .verify-notice strong {
    font-weight: 700;
  }

  /* Locked order indicator */
  .locked-banner {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    background: var(--status-pending-bg);
    border: 1px solid var(--status-pending-border);
    border-radius: var(--radius-xs);
    margin-bottom: var(--space-3);
    font-size: 0.74rem;
    color: var(--status-pending);
  }

  .locked-banner i {
    font-size: 12px;
  }

  .locked-banner strong {
    font-weight: 600;
  }

  /* Urgency states — same semantic colors as toasts/badges elsewhere,
     not a separate ad-hoc palette, so "danger" always means the same
     red across the whole app. */
  .order-card.urgency-warning {
    border-color: var(--color-warning-border);
    box-shadow: 0 0 0 2px var(--color-warning-bg);
  }

  .order-card.urgency-danger {
    border-color: var(--color-error-border);
    box-shadow: 0 0 0 3px var(--color-error-bg);
    animation: pulse-card 1.5s ease-in-out infinite;
  }

  @keyframes pulse-card {

    0%,
    100% {
      box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
    }

    50% {
      box-shadow: 0 0 0 6px rgba(220, 38, 38, 0.25);
    }
  }

  /* Pickup time pill on card */
  .pickup-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: var(--radius-full);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    white-space: nowrap;
  }

  .pickup-pill.normal {
    background: var(--surface-raised);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
  }

  .pickup-pill.warning {
    background: var(--color-warning-bg);
    color: var(--color-warning);
    border: 1px solid var(--color-warning-border);
  }

  .pickup-pill.danger {
    background: var(--color-error-bg);
    color: var(--color-error);
    border: 1px solid var(--color-error-border);
  }

  .pickup-pill.asap {
    background: var(--color-success-bg);
    color: var(--color-success);
    border: 1px solid var(--color-success-border);
  }

  .countdown {
    font-variant-numeric: tabular-nums;
  }

  /* Locked card state */
  .order-card.is-locked {
    opacity: 0.72;
    pointer-events: none;
  }

  .order-card.is-locked .order-card-foot {
    background: var(--surface-sunken);
  }

  .order-card.is-locked .btn {
    opacity: 0.5;
    cursor: not-allowed;
  }

  /* Locked by me indicator */
  .locked-by-me {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: var(--status-preparing-bg);
    border: 1px solid var(--status-preparing-border);
    border-radius: var(--radius-xs);
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--status-preparing);
    margin-left: var(--space-2);
  }

  .locked-by-me i {
    font-size: 10px;
  }
</style>

<script>
  // Order locking system
  const currentCashierId = <?= $currentUser ?>;
  let lockedOrders = {}; // Track which orders are locked by whom

  // Poll for lock updates every 10 seconds
  function pollLockStatus() {
    fetch('<?= APP_URL ?>/api/order-lock.php?action=list', {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
      .then(r => r.json())
      .then(data => {
        if (data.success && data.locks) {
          lockedOrders = {};
          data.locks.forEach(lock => {
            lockedOrders[lock.id] = {
              locked_by: parseInt(lock.locked_by),
              locked_by_name: lock.locked_by_name,
              order_number: lock.order_number,
              status: lock.status
            };
          });
          console.log('Locked orders:', lockedOrders); // Debug
          updateLockIndicators();
        }
      })
      .catch(err => console.error('Failed to poll lock status:', err));
  }

  // Update visual indicators for locked orders
  function updateLockIndicators() {
    document.querySelectorAll('.order-card[data-order-id]').forEach(card => {
      const orderId = parseInt(card.dataset.orderId);
      const lockInfo = lockedOrders[orderId];
      const lockBanner = card.querySelector('.locked-banner:not([data-manual])');

      if (lockInfo && lockInfo.locked_by !== currentCashierId) {
        // Locked by someone else - show locked state
        card.classList.add('is-locked');
        if (!lockBanner) {
          const banner = document.createElement('div');
          banner.className = 'locked-banner';
          banner.innerHTML = `<i class="fa-solid fa-lock"></i><span>Being prepared by <strong>${escapeHtml(lockInfo.locked_by_name)}</strong></span>`;
          card.querySelector('.order-card-body').prepend(banner);
        } else {
          lockBanner.querySelector('strong').textContent = lockInfo.locked_by_name;
        }
      } else {
        // Not locked or locked by current cashier - allow interaction
        card.classList.remove('is-locked');
        if (lockBanner) {
          lockBanner.remove();
        }
      }
    });
  }

  // Lock order before action
  async function lockOrder(orderId) {
    try {
      const res = await fetch('<?= APP_URL ?>/api/order-lock.php?action=lock', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: `order_id=${orderId}`
      });
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (e) {
        console.error('Invalid JSON response:', text);
        return {
          success: false,
          error: 'server_error',
          message: 'Server returned invalid response'
        };
      }
    } catch (err) {
      console.error('Lock request failed:', err);
      return {
        success: false,
        error: 'network_error',
        message: 'Network request failed'
      };
    }
  }

  // Unlock order
  async function unlockOrder(orderId) {
    navigator.sendBeacon(
      '<?= APP_URL ?>/api/order-lock.php?action=unlock',
      new URLSearchParams({
        order_id: orderId,
        csrf_token: document.querySelector('meta[name="csrf-token"]')?.content || ''
      })
    );
  }

  // Unlock all orders locked by this cashier when page closes
  window.addEventListener('beforeunload', () => {
    if (isSubmitting) return;

    Object.keys(lockedOrders).forEach(orderId => {
      if (
        lockedOrders[orderId].locked_by === currentCashierId &&
        !['preparing', 'ready'].includes(lockedOrders[orderId].status)
      ) {
        unlockOrder(orderId);
      }
    });
  });

  // Start polling
  setInterval(pollLockStatus, 10000);
  pollLockStatus(); // Initial load
</script>

<div class="page-header">
  <div>
    <div class="page-header-title">Pre-order Queue</div>
    <div class="page-header-sub"><?= count($orders) ?> active · <?= $counts['ready'] ?> ready to claim</div>
  </div>
  <div class="page-header-actions">
    <?php if ($counts['ready'] > 0): ?>
      <span class="badge badge-ready"><?= $counts['ready'] ?> ready</span>
    <?php endif; ?>
    <?php if ($counts['preparing'] > 0): ?>
      <span class="badge badge-preparing"><?= $counts['preparing'] ?> preparing</span>
    <?php endif; ?>
    <?php if ($counts['pending'] > 0): ?>
      <span class="badge badge-pending"><?= $counts['pending'] ?> pending</span>
    <?php endif; ?>
  </div>
</div>
<?php showFlash('global'); ?>

<div class="alert alert-info mb-5" style="margin-bottom:var(--space-5)">
  <i class="fa-solid fa-circle-info"></i>
  <div>
    <strong>How to verify payment:</strong> Before pressing <em>Start Preparing</em>, check the reference number below against your <strong>GCash / PayMaya inbox</strong> or ask to see the student's payment screenshot.
    Always verify the student's <strong>physical school ID</strong> before marking as <em>Claimed</em>.
  </div>
</div>

<?php if (empty($orders)): ?>
  <div class="card">
    <div class="empty-state">
      <i class="fa-solid fa-circle-check"></i>
      <h3>Queue is clear!</h3>
      <p>No active pre-orders right now.</p>
    </div>
  </div>
<?php else: ?>
  <div class="queue-grid">
    <?php foreach ($orders as $o):
      $pay = $payIcons[$o['payment_method']] ?? $payIcons['online'];
      $isLockedByOther = !empty($o['locked_by']) && $o['locked_by'] != $currentUser && strtotime($o['lock_expire_at'] ?? '') > time();
      $isLockedByMe = !empty($o['locked_by']) && $o['locked_by'] == $currentUser;

      // Per-card urgency
      date_default_timezone_set('Asia/Manila');
      $pt          = $o['pickup_time'] ?? '';
      $cardUrgency = '';
      $pillClass   = 'normal';
      $pillLabel   = '';
      $pillIcon    = 'fa-clock';
      if ($pt === 'ASAP') {
        $cardUrgency = ($o['status'] === 'pending') ? 'urgency-danger' : '';
        $pillClass   = 'asap';
        $pillLabel   = 'ASAP';
        $pillIcon    = 'fa-bolt';
      } elseif ($pt) {
        $pickupTs  = strtotime($pt);
        $diff      = $pickupTs - time();
        $pillLabel = date('g:i A', $pickupTs);
        if ($diff <= 0) {
          $cardUrgency = 'urgency-danger';
          $pillClass   = 'danger';
          $pillIcon    = 'fa-triangle-exclamation';
        } elseif ($diff <= 30 * 60) {
          $cardUrgency = 'urgency-warning';
          $pillClass   = 'warning';
          $pillIcon    = 'fa-hourglass-half';
        }
      }
    ?>
      <div class="order-card status-<?= e($o['status']) ?><?= $isLockedByOther ? ' is-locked' : '' ?> <?= $cardUrgency ?>"
        data-order-id="<?= $o['id'] ?>"
        data-status="<?= e($o['status']) ?>"
        data-pickup="<?= e($pt) ?>">

        <!-- Header -->
        <div class="order-card-head status-<?= e($o['status']) ?>">
          <div>
            <div class="order-number">
              <?= e($o['order_number']) ?>
              <?php if ($isLockedByMe): ?>
                <span class="locked-by-me"><i class="fa-solid fa-user-check"></i> You</span>
              <?php endif; ?>
            </div>
            <div class="order-time"><?= date('g:i A · M j', strtotime($o['created_at'])) ?></div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
            <span class="badge badge-<?= e($o['status']) ?>"><?= ucfirst(e($o['status'])) ?></span>
            <?php if ($pillLabel): ?>
              <span class="pickup-pill <?= $pillClass ?>" data-countdown="<?= $pt !== 'ASAP' ? e($pt) : '' ?>">
                <i class="fa-solid <?= $pillIcon ?>"></i>
                <span class="countdown"><?= $pillLabel ?></span>
              </span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Body -->
        <div class="order-card-body">

          <?php if ($isLockedByOther): ?>
            <!-- Locked by another cashier (server-side) -->
            <div class="locked-banner" data-manual="true">
              <i class="fa-solid fa-lock"></i>
              <span>Being prepared by <strong><?= e($o['locked_by_name']) ?></strong></span>
            </div>
          <?php endif; ?>

          <!-- Customer -->
          <div class="customer-row">
            <div class="customer-avatar"><i class="fa-solid fa-id-card"></i></div>
            <div>
              <div class="customer-name"><?= e($o['customer_name']) ?></div>
              <div class="customer-id"><?= e($o['customer_id']) ?></div>
            </div>
          </div>

          <!-- Items -->
          <div class="items-box">
            <?php foreach (explode("\n", $o['items']) as $line): ?>
              <div><?= e($line) ?></div>
            <?php endforeach; ?>
          </div>

          <!-- ★ Payment Verification Block ★ -->
          <div class="payment-verify">
            <div class="payment-verify-header">
              <i class="fa-solid fa-shield-check"></i> Payment Verification
            </div>
            <div class="payment-verify-body">
              <div class="payment-method-badge">
                <i class="fa-solid <?= $pay['icon'] ?>"></i>
                <?= $pay['label'] ?>
              </div>
              <div class="ref-number-wrap">
                <div class="ref-label">Reference No.</div>
                <?php if (!empty($o['reference_number'])): ?>
                  <div class="ref-number"><?= e($o['reference_number']) ?></div>
                <?php else: ?>
                  <div class="ref-missing">No reference number</div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Verify notice (only on pending) -->
          <?php if ($o['status'] === STATUS_PENDING): ?>
            <div class="verify-notice">
              <i class="fa-solid fa-triangle-exclamation"></i>
              <div><strong>Verify before preparing:</strong> Check this reference number in your <?= $pay['label'] ?> inbox before pressing Start Preparing.</div>
            </div>
          <?php endif; ?>

          <!-- Notes -->
          <?php if (!empty($o['notes'])): ?>
            <div class="order-notes">
              <i class="fa-solid fa-note-sticky"></i><?= e($o['notes']) ?>
            </div>
          <?php endif; ?>

          <!-- Total -->
          <div class="order-total-row">
            <span style="font-size:0.74rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em">Order Total</span>
            <span class="order-total-amount"><?= peso($o['total_amount']) ?></span>
          </div>

        </div><!-- /body -->

        <!-- Actions footer -->
        <div class="order-card-foot">
          <form method="POST" style="display:flex;gap:var(--space-2);flex:1;flex-wrap:wrap" id="order-form-<?= $o['id'] ?>">
            <?= csrfField() ?>
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">

            <?php if ($o['status'] === STATUS_PENDING): ?>
              <button type="button" class="btn btn-primary btn-sm flex-1" onclick="handleStartPreparing(<?= $o['id'] ?>, '<?= e($o['order_number']) ?>', '<?= e($pay['label']) ?>')">
                <i class="fa-solid fa-fire"></i> Start Preparing
              </button>

            <?php elseif ($o['status'] === STATUS_PREPARING): ?>
              <button type="button" class="btn btn-accent btn-sm flex-1" onclick="handleMarkReady(<?= $o['id'] ?>, '<?= e($o['order_number']) ?>')">
                <i class="fa-solid fa-bell"></i> Mark Ready
              </button>

            <?php elseif ($o['status'] === STATUS_READY): ?>
              <button type="button" class="btn btn-success btn-sm flex-1"
                onclick="handleClaimOrder(<?= $o['id'] ?>, '<?= e($o['customer_name']) ?>')">
                <i class="fa-solid fa-id-card"></i> Claim Order
              </button>
            <?php endif; ?>


            <button type="button" class="btn btn-danger btn-sm" onclick="handleCancelOrder(<?= $o['id'] ?>, '<?= e($o['order_number']) ?>')" title="Cancel order">
              <i class="fa-solid fa-xmark"></i>
            </button>

          </form>
        </div>

      </div><!-- /order-card -->
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
  // Modal-based handlers for order actions
  let isSubmitting = false;
  async function handleStartPreparing(orderId, orderNumber, paymentMethod) {
    // First try to lock the order
    const lockRes = await lockOrder(orderId);
    console.log('Lock result:', lockRes);

    if (!lockRes.success) {
      if (lockRes.error === 'order_locked') {
        showLockedOrderModal({
          locked_by_name: lockRes.locked_by_name,
          order_number: orderNumber
        });
      } else {
        alertModal(lockRes.message || 'Could not lock order', {
          title: 'Error',
          icon: 'fa-circle-xmark',
          iconColor: 'danger'
        });
      }
      return;
    }

    // Show verification modal
    const confirmed = await confirmModal(
      `Have you verified the <strong>${paymentMethod}</strong> reference number for order <strong>${orderNumber}</strong>?`, {
        title: 'Verify Payment',
        icon: 'fa-shield-check',
        iconColor: 'warning',
        confirmText: 'Yes, verified'
      }
    );

    if (confirmed) {
      document.querySelector(`#order-form-${orderId} input[name="new_status"]`)?.remove();
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'new_status';
      input.value = 'preparing';
      document.querySelector(`#order-form-${orderId}`).appendChild(input);
      isSubmitting = true;
      document.querySelector(`#order-form-${orderId}`).submit();
    } else {
      // Unlock the order if cancelled
      unlockOrder(orderId);
    }
  }

  async function handleMarkReady(orderId, orderNumber) {
    // Refresh/assert lock before proceeding
    const lockRes = await lockOrder(orderId);
    if (!lockRes.success) {
      if (lockRes.error === 'order_locked') {
        showLockedOrderModal({
          locked_by_name: lockRes.locked_by_name,
          order_number: orderNumber
        });
      } else {
        alertModal(lockRes.message || 'Could not lock order', {
          title: 'Error',
          icon: 'fa-circle-xmark',
          iconColor: 'danger'
        });
      }
      return;
    }

    const confirmed = await confirmModal(
      `Mark order <strong>${orderNumber}</strong> as ready for pickup?`, {
        title: 'Mark Ready',
        icon: 'fa-bell',
        iconColor: 'info',
        confirmText: 'Yes, mark ready',
        confirmClass: 'btn-accent'
      }
    );

    if (confirmed) {
      document.querySelector(`#order-form-${orderId} input[name="new_status"]`)?.remove();
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'new_status';
      input.value = 'ready';
      document.querySelector(`#order-form-${orderId}`).appendChild(input);
      isSubmitting = true;
      document.querySelector(`#order-form-${orderId}`).submit();
    } else {
      unlockOrder(orderId); // release if they cancel
    }
  }

  async function handleClaimOrder(orderId, studentName) {
    const confirmed = await confirmModal(
      `Confirm school ID has been verified for <strong>${studentName}</strong>?`, {
        title: 'Verify ID & Claim',
        icon: 'fa-id-card',
        iconColor: 'success',
        confirmText: 'Yes, ID verified',
        confirmClass: 'btn-success'
      }
    );

    if (confirmed) {
      document.querySelector(`#order-form-${orderId} input[name="new_status"]`)?.remove();
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'new_status';
      input.value = 'claimed';
      document.querySelector(`#order-form-${orderId}`).appendChild(input);
      isSubmitting = true;
      document.querySelector(`#order-form-${orderId}`).submit();
    } else {
      unlockOrder(orderId); // release if they cancel
    }
  }

  async function handleCancelOrder(orderId, orderNumber) {
    const confirmed = await confirmModal(
      `Cancel order <strong>${orderNumber}</strong>? This action cannot be undone.`, {
        title: 'Cancel Order',
        icon: 'fa-triangle-exclamation',
        iconColor: 'danger',
        confirmText: 'Yes, cancel order',
        danger: true
      }
    );

    if (confirmed) {
      document.querySelector(`#order-form-${orderId} input[name="new_status"]`)?.remove();
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'new_status';
      input.value = 'cancelled';
      document.querySelector(`#order-form-${orderId}`).appendChild(input);
      isSubmitting = true;
      document.querySelector(`#order-form-${orderId}`).submit();
    }
  }

  /* ── Live countdown on pickup pills ──────────────── */
  function updateCountdowns() {
    document.querySelectorAll('.pickup-pill[data-countdown]').forEach(pill => {
      const timeStr = pill.dataset.countdown;
      if (!timeStr) return;

      const now = new Date();
      const [h, m] = timeStr.split(':').map(Number);
      const target = new Date();
      target.setHours(h, m, 0, 0);
      const diff = Math.floor((target - now) / 1000); // seconds

      const card = pill.closest('.order-card');
      const label = pill.querySelector('.countdown');
      const icon = pill.querySelector('i');

      if (diff <= 0) {
        // Overdue
        const abs = Math.abs(diff);
        const mm = String(Math.floor(abs / 60)).padStart(2, '0');
        const ss = String(abs % 60).padStart(2, '0');
        label.textContent = `${mm}:${ss} overdue`;
        pill.className = 'pickup-pill danger';
        icon.className = 'fa-solid fa-triangle-exclamation';
        card?.classList.remove('urgency-warning');
        card?.classList.add('urgency-danger');
      } else if (diff <= 30 * 60) {
        // Urgent — show countdown
        const mm = String(Math.floor(diff / 60)).padStart(2, '0');
        const ss = String(diff % 60).padStart(2, '0');
        label.textContent = `${mm}:${ss} left`;
        pill.className = 'pickup-pill warning';
        icon.className = 'fa-solid fa-hourglass-half';
        card?.classList.add('urgency-warning');
        card?.classList.remove('urgency-danger');
      } else {
        // Normal — show formatted time
        label.textContent = target.toLocaleTimeString('en-PH', {
          hour: 'numeric',
          minute: '2-digit'
        });
        pill.className = 'pickup-pill normal';
        icon.className = 'fa-solid fa-clock';
        card?.classList.remove('urgency-warning', 'urgency-danger');
      }
    });
  }

  // Tick every second for countdown accuracy
  setInterval(updateCountdowns, 1000);
  updateCountdowns();

  /* ── Auto-refresh (safe here, no active inputs) ── */
  let refreshTimer = setTimeout(() => location.reload(), 45000);

  // Reset the timer on any button click so we don't refresh mid-action
  document.querySelectorAll('button').forEach(btn => {
    btn.addEventListener('click', () => {
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(() => location.reload(), 45000);
    });
  });
</script>
<?php layoutFooter(); ?>