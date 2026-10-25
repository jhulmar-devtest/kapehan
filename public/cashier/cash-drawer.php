<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/cash-drawer.php';
requireRole(ROLE_CASHIER);
$db = Database::getInstance();
$today = date('Y-m-d');
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();
  $action = $_POST['drawer_action'] ?? '';
  $amountRaw = trim((string)($_POST['amount'] ?? ''));
  $amount = is_numeric($amountRaw) ? (float)$amountRaw : -1;
  if (!is_finite($amount) || $amount > 99999999.99) $amount = -1;
  elseif ($amount >= 0) $amount = round($amount, 2);
  $drawerId = (int)($_POST['drawer_id'] ?? 0);

  if (in_array($action, ['open', 'reopen'], true)) {
    $errorMessage = 'Only an admin can open or reopen the shared drawer.';
  } elseif (in_array($action, ['handoff_submit', 'handoff_confirm', 'close', 'movement_in', 'movement_out'], true) && $amount < 0) {
    $errorMessage = 'Enter a valid amount of zero or more.';
  } elseif (in_array($action, ['handoff_submit', 'handoff_confirm', 'close', 'movement_in', 'movement_out'], true)) {
    try {
      $db->beginTransaction();
      $stmt = $db->prepare('SELECT * FROM cash_drawer_days WHERE id=? FOR UPDATE');
      $stmt->execute([$drawerId]);
      $drawer = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$drawer) throw new RuntimeException('This drawer day no longer exists. Refresh the page.');
      if ($drawer['status'] !== 'open') throw new RuntimeException('This drawer day is no longer open. Refresh the page.');

      $note = sanitizeString($_POST['note'] ?? '', 255);
      if ($action === 'handoff_submit') {
        if (cashDrawerHasPendingHandoff($db, $drawerId)) {
          throw new RuntimeException('Another handoff is waiting for the incoming cashier to confirm it.');
        }
        $expected = cashDrawerExpectedAmount($db, $drawerId, (float)$drawer['opening_amount']);
        $variance = round($amount - $expected, 2);
        if ($variance != 0.0 && $note === '') throw new RuntimeException('Add a note explaining the handoff difference.');
        $cashierId = currentUserId();
        $db->prepare("INSERT INTO cash_drawer_handoffs (drawer_day_id, handed_from_cashier_id, recorded_by, expected_amount, counted_amount, variance, status, note) VALUES (?,?,?,?,?,?,'pending',?)")
          ->execute([$drawerId, $cashierId, $cashierId, $expected, $amount, $variance, $note !== '' ? $note : null]);
        $db->commit();
        flash('global', 'Your count is recorded. The incoming cashier must recount and respond before walk-in orders resume.', 'info');
      } elseif ($action === 'handoff_confirm') {
        $handoffId = (int)($_POST['handoff_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM cash_drawer_handoffs WHERE id=? AND drawer_day_id=? AND status='pending' FOR UPDATE");
        $stmt->execute([$handoffId, $drawerId]);
        $handoff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$handoff) throw new RuntimeException('This handoff is no longer awaiting confirmation.');
        if ((int)$handoff['handed_from_cashier_id'] === currentUserId()) {
          throw new RuntimeException('A different cashier must confirm the handoff.');
        }
        $confirmationVariance = round($amount - (float)$handoff['counted_amount'], 2);
        if ($confirmationVariance != 0.0 && $note === '') {
          throw new RuntimeException('Add a note explaining the difference between the two counts.');
        }
        $status = $confirmationVariance == 0.0 ? 'confirmed' : 'disputed';
        $db->prepare('UPDATE cash_drawer_handoffs SET status=?, confirmed_by=?, confirmation_amount=?, confirmation_variance=?, confirmation_note=?, confirmed_at=NOW() WHERE id=? AND status=\'pending\'')
          ->execute([$status, currentUserId(), $amount, $confirmationVariance, $note !== '' ? $note : null, $handoffId]);
        $db->commit();
        flash('global', $status === 'confirmed'
          ? 'Handoff confirmed. Both cashiers recorded the same count.'
          : 'The handoff counts differ. The difference and your note were recorded.', $status === 'confirmed' ? 'success' : 'warning');
      } elseif ($action === 'movement_in' || $action === 'movement_out') {
        if (cashDrawerHasPendingHandoff($db, $drawerId)) {
          throw new RuntimeException('Record the incoming cashier’s recount before recording other drawer activity.');
        }
        if ($amount <= 0 || $note === '') throw new RuntimeException('Enter an amount and a reason for the cash movement.');
        $direction = $action === 'movement_in' ? 'in' : 'out';
        $db->prepare('INSERT INTO cash_drawer_movements (drawer_day_id, cashier_id, direction, amount, reason) VALUES (?,?,?,?,?)')
          ->execute([$drawerId, currentUserId(), $direction, $amount, $note]);
        $db->commit();
        flash('global', 'Cash movement recorded.', 'success');
      } elseif ($action === 'close') {
        if (cashDrawerHasPendingHandoff($db, $drawerId)) {
          throw new RuntimeException('Resolve the pending handoff before closing the drawer day.');
        }
        $expected = cashDrawerExpectedAmount($db, $drawerId, (float)$drawer['opening_amount']);
        $variance = round($amount - $expected, 2);
        $closedAt = (string)$db->query('SELECT NOW()')->fetchColumn();
        $db->prepare('INSERT INTO cash_drawer_closures (drawer_day_id, closed_by, closing_amount, expected_closing_amount, variance, closed_at) VALUES (?,?,?,?,?,?)')
          ->execute([$drawerId, currentUserId(), $amount, $expected, $variance, $closedAt]);
        $db->prepare("UPDATE cash_drawer_days SET status='closed', closing_amount=?, expected_closing_amount=?, variance=?, closed_by=?, closed_at=? WHERE id=? AND status='open'")
          ->execute([$amount, $expected, $variance, currentUserId(), $closedAt, $drawerId]);
        $db->commit();
        flash('global', 'Drawer day closed. Variance: ' . peso($variance), $variance == 0.0 ? 'success' : 'warning');
      }
      redirect(APP_URL . '/cashier/cash-drawer.php?day=' . $drawerId);
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      $errorMessage = $e->getMessage();
    }
  } else {
    $errorMessage = 'Unknown drawer action.';
  }
}

$activeDrawer = getOpenCashDrawer($db);
$todayDrawerStmt = $db->prepare('SELECT * FROM cash_drawer_days WHERE business_date=? LIMIT 1');
$todayDrawerStmt->execute([$today]);
$todayDrawer = $todayDrawerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$drawer = $activeDrawer;
if (!$drawer && !empty($_GET['day'])) {
  $stmt = $db->prepare('SELECT * FROM cash_drawer_days WHERE id=?');
  $stmt->execute([(int)$_GET['day']]);
  $drawer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$drawer) $drawer = $todayDrawer;
$report = $drawer ? cashDrawerReportData($db, (int)$drawer['id']) : null;
$hasPendingHandoff = $activeDrawer ? cashDrawerHasPendingHandoff($db, (int)$activeDrawer['id']) : false;
$lateClose = $report && $report['status'] === 'closed'
  && date('Y-m-d', strtotime($report['closed_at'])) > $report['business_date'];
$expectedNow = $drawer && $drawer['status'] === 'open'
  ? cashDrawerExpectedAmount($db, (int)$drawer['id'], (float)$drawer['opening_amount'])
  : (float)($drawer['expected_closing_amount'] ?? 0);

layoutHeader('Cash Drawer');
?>
<style>
  .drawer-confirm-overlay {
    position: fixed;
    inset: 0;
    z-index: 1200;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(22, 14, 10, .62);
  }

  .drawer-confirm-overlay.open {
    display: flex;
  }

  .drawer-confirm-dialog {
    width: min(100%, 440px);
    border-radius: var(--radius-lg);
    background: var(--surface-color);
    color: var(--text-color);
    box-shadow: var(--shadow-lg);
    padding: 24px;
  }

  .drawer-confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
  }
</style>
<div class="page-header">
  <div>
    <div class="page-header-title">Shared Cash Drawer</div>
    <div class="page-header-sub">Admins enter the starting fund. Cashiers record handoffs, cash movements, and the closing count.</div>
  </div>
  <?php if ($report): ?>
    <div class="page-header-actions">
      <?php if ($report['status'] === 'open'): ?>
        <form method="POST" action="<?= APP_URL ?>/api/create-register-reading.php" style="display:inline">
          <?= csrfField() ?><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="reading_type" value="X">
          <button class="btn btn-outline" type="submit"><i class="fa-solid fa-chart-line"></i> X Reading</button>
        </form>
      <?php else: ?>
        <form method="POST" action="<?= APP_URL ?>/api/create-register-reading.php" style="display:inline">
          <?= csrfField() ?><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="reading_type" value="Z">
          <button class="btn btn-outline" type="submit"><i class="fa-solid fa-file-invoice-dollar"></i> Z Reading</button>
        </form>
      <?php endif; ?>
      <button class="btn btn-primary" id="drawer-print-btn" onclick="printDrawerReport(<?= (int)$report['id'] ?>)" title="Thermal print the full drawer audit report"><i class="fa-solid fa-print"></i> Print Full Drawer Report</button>
    </div>
  <?php endif; ?>
</div>

<?php showFlash('global'); ?>
<?php if ($errorMessage !== ''): ?>
  <div class="alert alert-warning mb-4"><i class="fa-solid fa-triangle-exclamation"></i>
    <div><?= e($errorMessage) ?></div>
  </div>
<?php endif; ?>

<?php if (!$drawer): ?>
  <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i>
    <div>The drawer has not been opened for today yet. Ask an admin to enter the starting fund before processing walk-in orders.</div>
  </div>
<?php else: ?>
  <div class="stats-grid">
    <div class="stat-card stat-gold">
      <div class="stat-icon gold"><i class="fa-solid fa-wallet"></i></div>
      <div class="stat-content">
        <div class="stat-label">Opening Fund</div>
        <div class="stat-value"><?= peso($report['opening_amount']) ?></div>
        <div class="stat-sub">Opened by <?= e($report['opened_by_name']) ?> · <?= date('g:i A', strtotime($report['opened_at'])) ?></div>
      </div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-icon red"><i class="fa-solid fa-calculator"></i></div>
      <div class="stat-content">
        <div class="stat-label">Expected Cash</div>
        <div class="stat-value"><?= peso($expectedNow) ?></div>
        <div class="stat-sub">Opening fund + net cash activity</div>
      </div>
    </div>
    <div class="stat-card <?= $report['status'] === 'open' ? 'stat-green' : '' ?>">
      <div class="stat-icon <?= $report['status'] === 'open' ? 'green' : 'brown' ?>"><i class="fa-solid fa-circle-<?= $report['status'] === 'open' ? 'check' : 'minus' ?>"></i></div>
      <div class="stat-content">
        <div class="stat-label">Drawer Status</div>
        <div class="stat-value" style="text-transform:capitalize"><?= e($report['status']) ?></div>
        <div class="stat-sub">Business date: <?= date('F j, Y', strtotime($report['business_date'])) ?></div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-chart-column"></i> Sales during this drawer day</div>
    </div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-3)">
      <?php if (empty($report['payment_totals'])): ?><div class="text-muted">No paid orders recorded during this drawer day yet.</div><?php endif; ?>
      <?php foreach ($report['payment_totals'] as $total): ?>
        <div>
          <div class="text-muted" style="font-size:.78rem;text-transform:capitalize"><?= e($total['payment_method']) ?> · <?= (int)$total['transaction_count'] ?> orders</div><strong style="font-size:1.15rem"><?= peso($total['sales_total']) ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($report['status'] === 'open'): ?>
    <?php if ($report['business_date'] < $today): ?>
      <div class="alert alert-warning mb-4"><i class="fa-solid fa-triangle-exclamation"></i>
        <div>This drawer day is overdue. Walk-in sales are paused until you count and close it. After closing, you can open today’s drawer.</div>
      </div>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:var(--space-4)">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fa-solid fa-right-left"></i> Cashier handoff count</div>
        </div>
        <div class="card-body">
          <?php if ($hasPendingHandoff): ?>
            <p class="text-muted mb-3">A handoff is waiting for the incoming cashier to recount and confirm it. Walk-in payments stay paused until they respond.</p>
          <?php else: ?>
            <p class="text-muted mb-3">The outgoing cashier submits a count first. The incoming cashier must recount it under their own login. The expected day balance is not reset.</p>
            <form method="POST">
              <?= csrfField() ?><input type="hidden" name="drawer_action" value="handoff_submit"><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>">
              <div class="form-group"><label class="form-label" for="handoff-amount">Your count of the drawer (₱)</label><input class="form-control" id="handoff-amount" name="amount" type="number" min="0" step="0.01" required></div>
              <div class="form-group"><label class="form-label" for="handoff-note">Note <span class="text-muted">(required if your count differs from expected)</span></label><input class="form-control" id="handoff-note" name="note" maxlength="255" placeholder="Explain any difference"></div>
              <button class="btn btn-ghost" type="submit"><i class="fa-solid fa-share-from-square"></i> Submit Outgoing Count</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fa-solid fa-money-bill-transfer"></i> Record cash movement</div>
        </div>
        <div class="card-body">
          <p class="text-muted mb-3">Record cash put into or taken out of the drawer, such as a cash refund or payout, so the expected balance stays accurate.</p>
          <form method="POST">
            <?= csrfField() ?><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>">
            <div class="form-group"><label class="form-label" for="movement-amount">Amount (₱)</label><input class="form-control" id="movement-amount" name="amount" type="number" min="0.01" step="0.01" required></div>
            <div class="form-group"><label class="form-label" for="movement-note">Reason</label><input class="form-control" id="movement-note" name="note" maxlength="255" required placeholder="e.g. Cash refund for order #..."></div>
            <div style="display:flex;gap:var(--space-2);flex-wrap:wrap"><button class="btn btn-ghost" name="drawer_action" value="movement_in" type="submit"><i class="fa-solid fa-arrow-down"></i> Cash In</button><button class="btn btn-ghost" name="drawer_action" value="movement_out" type="submit"><i class="fa-solid fa-arrow-up"></i> Cash Out</button></div>
          </form>
        </div>
      </div>
      <div class="card" style="border-color:var(--status-cancelled)">
        <div class="card-header">
          <div class="card-title"><i class="fa-solid fa-lock"></i> Close drawer day</div>
        </div>
        <div class="card-body">
          <p class="text-muted mb-3">Count all the cash in the drawer, including the starting fund. Expected cash right now is <strong><?= peso($expectedNow) ?></strong>.</p>
          <?php if ($hasPendingHandoff): ?>
            <p class="text-muted">Resolve the pending handoff before closing.</p>
            <button class="btn btn-primary" type="button" disabled><i class="fa-solid fa-hourglass-half"></i> Handoff Needs Confirmation</button>
          <?php else: ?>
            <button class="btn btn-primary" type="button" onclick="openDrawerCloseModal()"><i class="fa-solid fa-lock"></i> Count &amp; Close Drawer</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="alert <?= (float)$report['variance'] === 0.0 && !$lateClose ? 'alert-success' : 'alert-warning' ?> mb-4">
      <i class="fa-solid fa-<?= (float)$report['variance'] === 0.0 && !$lateClose ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      <div>
        Closed by <strong><?= e($report['closed_by_name'] ?? 'Cashier') ?></strong> at <?= date('M j, g:i A', strtotime($report['closed_at'])) ?>. Counted <?= peso($report['closing_amount']) ?>; variance <?= peso($report['variance']) ?>.
        <?php if ($lateClose): ?><br><strong>Late close:</strong> drawer day for <?= date('M j', strtotime($report['business_date'])) ?> was closed on <?= date('M j', strtotime($report['closed_at'])) ?>.<?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($report['status'] === 'open' && $hasPendingHandoff): ?>
    <div class="card mt-4">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-user-check"></i> Incoming cashier confirmation</div>
      </div>
      <div class="card-body">
        <?php foreach ($report['handoffs'] as $handoff): if ($handoff['status'] !== 'pending') continue; ?>
          <?php if ((int)$handoff['handed_from_cashier_id'] === currentUserId()): ?>
            <div class="alert alert-info"><i class="fa-solid fa-hourglass-half"></i>
              <div>Outgoing count of <?= peso($handoff['counted_amount']) ?> submitted by <strong><?= e($handoff['handed_from_name']) ?></strong> at <?= date('g:i A', strtotime($handoff['recorded_at'])) ?>. Waiting for a different cashier to recount.</div>
            </div>
          <?php else: ?>
            <div class="alert alert-warning"><i class="fa-solid fa-scale-balanced"></i>
              <div><strong><?= e($handoff['handed_from_name']) ?></strong> recorded <?= peso($handoff['counted_amount']) ?> at <?= date('g:i A', strtotime($handoff['recorded_at'])) ?>; the system expected <?= peso($handoff['expected_amount']) ?>. Recount the physical drawer and enter your own count.</div>
            </div>
            <form method="POST" style="max-width:500px">
              <?= csrfField() ?><input type="hidden" name="drawer_action" value="handoff_confirm"><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="handoff_id" value="<?= (int)$handoff['id'] ?>">
              <div class="form-group"><label class="form-label" for="confirm-handoff-amount">Your recount (₱)</label><input class="form-control" id="confirm-handoff-amount" name="amount" type="number" min="0" step="0.01" required></div>
              <div class="form-group"><label class="form-label" for="confirm-handoff-note">Note <span class="text-muted">(required if your count differs)</span></label><input class="form-control" id="confirm-handoff-note" name="note" maxlength="255" placeholder="Explain the difference"></div>
              <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> Confirm Recount</button>
            </form>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card mt-4">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-list-check"></i> Drawer audit trail</div>
    </div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--space-5)">
      <section>
        <h3 style="font-size:.95rem;margin-bottom:var(--space-2)">Cashier logins</h3>
        <?php if (!$report['cashier_sessions']): ?><div class="text-muted">No cashier logins recorded for this business date.</div><?php else: ?><div style="display:grid;gap:8px"><?php foreach ($report['cashier_sessions'] as $session): ?><div><strong><?= e($session['cashier_name']) ?></strong>
                <div class="text-muted" style="font-size:.8rem"><?= date('g:i A', strtotime($session['login_at'])) ?> – <?= e(cashierSessionEndLabel($session)) ?><?= !empty($session['after_drawer_close']) ? ' · after drawer close' : '' ?></div>
              </div><?php endforeach; ?></div><?php endif; ?>
      </section>
      <section>
        <h3 style="font-size:.95rem;margin-bottom:var(--space-2)">Handoff counts</h3>
        <?php if (!$report['handoffs']): ?><div class="text-muted">No handoff counts recorded.</div><?php else: ?><div style="display:grid;gap:8px"><?php foreach ($report['handoffs'] as $handoff): ?><div><strong><?= e($handoff['handed_from_name']) ?> → <?= e($handoff['confirmed_by_name'] ?? ($handoff['status'] === 'pending' ? 'Awaiting cashier' : 'Unverified')) ?></strong>
                <div class="text-muted" style="font-size:.8rem"><?= date('g:i A', strtotime($handoff['recorded_at'])) ?> · Expected <?= peso($handoff['expected_amount']) ?> · Outgoing count <?= peso($handoff['counted_amount']) ?> · Expected difference <?= peso($handoff['variance']) ?> · Status: <?= ucfirst(e($handoff['status'])) ?><?= $handoff['confirmation_amount'] !== null ? ' · Incoming count ' . peso($handoff['confirmation_amount']) . ' · Count difference ' . peso($handoff['confirmation_variance']) . ' · Confirmed ' . date('g:i A', strtotime($handoff['confirmed_at'])) : '' ?><?= $handoff['note'] ? ' · ' . e($handoff['note']) : '' ?><?= $handoff['confirmation_note'] ? ' · Incoming note: ' . e($handoff['confirmation_note']) : '' ?></div>
              </div><?php endforeach; ?></div><?php endif; ?>
      </section>
      <section>
        <h3 style="font-size:.95rem;margin-bottom:var(--space-2)">Cash movements</h3>
        <?php if (!$report['movements']): ?><div class="text-muted">No cash movements recorded.</div><?php else: ?><div style="display:grid;gap:8px"><?php foreach ($report['movements'] as $movement): ?><div><strong><?= ucfirst($movement['direction']) ?> <?= peso($movement['amount']) ?></strong>
                <div class="text-muted" style="font-size:.8rem"><?= e($movement['cashier_name']) ?> · <?= date('g:i A', strtotime($movement['created_at'])) ?> · <?= e($movement['reason']) ?></div>
              </div><?php endforeach; ?></div><?php endif; ?>
      </section>
      <section>
        <h3 style="font-size:.95rem;margin-bottom:var(--space-2)">Close/reopen history</h3>
        <?php if (empty($report['closure_events'])): ?><div class="text-muted">No drawer closes or reopens recorded.</div><?php else: ?><div style="display:grid;gap:10px"><?php foreach ($report['closure_events'] as $event): ?><div><strong>Closed <?= date('g:i A', strtotime($event['closed_at'])) ?> · <?= e($event['closed_by_name'] ?? 'Cashier') ?></strong>
                <div class="text-muted" style="font-size:.8rem">Counted <?= peso($event['closing_amount']) ?> · Expected <?= peso($event['expected_closing_amount']) ?> · Difference <?= peso($event['variance']) ?></div><?php if (!empty($event['reopen_id'])): ?><div class="text-muted" style="font-size:.8rem">Reopened <?= date('g:i A', strtotime($event['reopened_at'])) ?> by <?= e($event['reopened_by_name'] ?? 'Cashier') ?> · Recount <?= peso($event['reopen_recounted_amount']) ?> vs expected <?= peso($event['reopen_expected_amount']) ?> · Difference <?= peso($event['reopen_variance']) ?> · <?= e($event['reopen_reason']) ?></div><?php endif; ?>
              </div><?php endforeach; ?></div><?php endif; ?>
      </section>
    </div>
  </div>
<?php endif; ?>

<?php if (!$activeDrawer && !$todayDrawer && $report && $report['business_date'] !== $today): ?>
  <div class="alert alert-info mt-4"><i class="fa-solid fa-circle-info"></i>
    <div>The previous drawer day is closed. Ask an admin to enter today’s starting fund before processing walk-in orders.</div>
  </div>
<?php endif; ?>

<?php if ($report && $report['status'] === 'open' && !$hasPendingHandoff): ?>
  <div class="drawer-confirm-overlay" id="drawer-close-overlay" onclick="if(event.target===this)closeDrawerCloseModal()" aria-hidden="true">
    <section class="drawer-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="drawer-close-title" aria-describedby="drawer-close-description">
      <h2 id="drawer-close-title" style="font-size:1.2rem;margin-bottom:8px"><i class="fa-solid fa-lock"></i> Close drawer day?</h2>
      <p id="drawer-close-description" class="text-muted">This records the final cash count and closes <?= e(date('F j, Y', strtotime($report['business_date']))) ?>. You’ll need to open a new drawer day before processing walk-in orders again.</p>
      <p style="margin:12px 0 16px">Expected cash: <strong><?= peso($expectedNow) ?></strong></p>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="drawer_action" value="close"><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>">
        <div class="form-group"><label class="form-label" for="closing-amount">Cash counted in the drawer (₱)</label><input class="form-control" id="closing-amount" name="amount" type="number" min="0" step="0.01" required></div>
        <div class="drawer-confirm-actions">
          <button class="btn btn-ghost" type="button" onclick="closeDrawerCloseModal()">Keep Drawer Open</button>
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-lock"></i> Confirm Close</button>
        </div>
      </form>
    </section>
  </div>
  <script>
    function openDrawerCloseModal() {
      const overlay = document.getElementById('drawer-close-overlay');
      overlay.classList.add('open');
      overlay.setAttribute('aria-hidden', 'false');
      document.getElementById('closing-amount').focus();
    }

    function closeDrawerCloseModal() {
      const overlay = document.getElementById('drawer-close-overlay');
      overlay.classList.remove('open');
      overlay.setAttribute('aria-hidden', 'true');
    }
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeDrawerCloseModal();
    });
  </script>
<?php endif; ?>


<?php
$recentDrawerDays = $db->query('SELECT id,business_date,status,opening_amount,closing_amount,variance FROM cash_drawer_days ORDER BY business_date DESC LIMIT 14')->fetchAll(PDO::FETCH_ASSOC);
if ($recentDrawerDays && (count($recentDrawerDays) > 1 || !$report)):
?>
  <div class="card mt-4">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-calendar-days"></i> Recent drawer reports</div>
    </div>
    <div class="card-body" style="display:grid;gap:8px">
      <?php foreach ($recentDrawerDays as $recentDay): ?>
        <a href="<?= APP_URL ?>/cashier/cash-drawer.php?day=<?= (int)$recentDay['id'] ?>" style="display:flex;justify-content:space-between;gap:12px;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-color);color:inherit">
          <span><strong><?= date('M j, Y', strtotime($recentDay['business_date'])) ?></strong> · <?= ucfirst(e($recentDay['status'])) ?></span>
          <span class="text-muted"><?= $recentDay['closing_amount'] !== null ? 'Counted ' . peso($recentDay['closing_amount']) . ' · Difference ' . peso($recentDay['variance']) : 'Opening ' . peso($recentDay['opening_amount']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<script>
  function printDrawerReport(dayId) {
    const btn = document.getElementById('drawer-print-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Printing…';
    fetch('<?= APP_URL ?>/cashier/thermal_drawer_report.php?drawer_day_id=' + encodeURIComponent(dayId))
      .then(r => r.json())
      .then(data => {
        if (!data.success) throw new Error(data.error || 'Printer error');
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Sent to printer';
        setTimeout(() => {
          btn.disabled = false;
          btn.innerHTML = '<i class="fa-solid fa-print"></i> Print Full Drawer Report';
        }, 2500);
      })
      .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-print"></i> Print Full Drawer Report';
        alert(err.message);
      });
  }
</script>
<?php if ($report):
  $readingStmt = $db->prepare('SELECT id, reading_type, issued_at FROM cash_register_readings WHERE drawer_day_id=? ORDER BY issued_at DESC, id DESC');
  $readingStmt->execute([(int)$report['id']]);
  $savedReadings = $readingStmt->fetchAll(PDO::FETCH_ASSOC);
  if ($savedReadings):
?>
    <div class="card mt-4">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-receipt"></i> X/Z readings for this drawer day</div>
      </div>
      <div class="card-body" style="display:grid;gap:8px">
        <?php foreach ($savedReadings as $savedReading): ?>
          <a href="<?= APP_URL ?>/cashier/register-reading.php?id=<?= (int)$savedReading['id'] ?>" class="reading-modal-link" data-reading-id="<?= (int)$savedReading['id'] ?>" data-reading-type="<?= e($savedReading['reading_type']) ?>" style="display:flex;justify-content:space-between;gap:12px;color:inherit">
            <strong><?= e($savedReading['reading_type']) ?> Reading #<?= (int)$savedReading['id'] ?></strong><span class="text-muted"><?= date('M j, g:i A', strtotime($savedReading['issued_at'])) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
<?php endif;
endif; ?>
<?php require __DIR__ . '/../../includes/register-reading-modal.php'; ?>
<?php layoutFooter(); ?>