<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/cash-drawer.php';
requireRole(ROLE_ADMIN);

$db = Database::getInstance();
$today = date('Y-m-d');
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verifyCsrf();
  $action = (string)($_POST['drawer_action'] ?? '');
  $rawAmount = trim((string)($_POST['amount'] ?? ''));
  $amount = is_numeric($rawAmount) ? round((float)$rawAmount, 2) : -1;
  if (!is_finite($amount) || $amount < 0 || $amount > 99999999.99) $amount = -1;

  if (!in_array($action, ['open', 'reopen'], true)) {
    $errorMessage = 'Unknown drawer action.';
  } elseif ($amount < 0) {
    $errorMessage = 'Enter a valid cash count of zero or more.';
  } else {
    try {
      $db->beginTransaction();
      if ($action === 'open') {
        $activeId = $db->query("SELECT id FROM cash_drawer_days WHERE status='open' LIMIT 1 FOR UPDATE")->fetchColumn();
        if ($activeId) throw new RuntimeException('A drawer day is already open. Ask a cashier to close the overdue day before starting a new one.');
        $todayStmt = $db->prepare('SELECT id FROM cash_drawer_days WHERE business_date=? LIMIT 1 FOR UPDATE');
        $todayStmt->execute([$today]);
        if ($todayStmt->fetchColumn()) throw new RuntimeException('A drawer record already exists for today. If it was closed by mistake, use Reopen instead.');
        $db->prepare('INSERT INTO cash_drawer_days (business_date, opening_amount, opened_by, opened_by_admin_id) VALUES (?, ?, NULL, ?)')
          ->execute([$today, $amount, currentUserId()]);
        $drawerId = (int)$db->lastInsertId();
        $db->commit();
        auditLog(ROLE_ADMIN, currentUserId(), 'open_cash_drawer', 'cash_drawer_days', $drawerId);
        flash('global', 'Today’s drawer is open. Starting fund: ' . peso($amount), 'success');
      } else {
        $drawerId = (int)($_POST['drawer_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM cash_drawer_days WHERE id=? FOR UPDATE');
        $stmt->execute([$drawerId]);
        $drawer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$drawer || $drawer['status'] !== 'closed') throw new RuntimeException('This drawer is no longer closed. Refresh the page.');
        if ($drawer['business_date'] !== $today || empty($drawer['closed_at']) || date('Y-m-d', strtotime($drawer['closed_at'])) !== $today) {
          throw new RuntimeException('Only a drawer closed today can be reopened. Previous business days must stay closed.');
        }
        $activeId = $db->query("SELECT id FROM cash_drawer_days WHERE status='open' LIMIT 1 FOR UPDATE")->fetchColumn();
        if ($activeId) throw new RuntimeException('Another drawer day is already open. Resolve it before reopening this day.');
        if (cashDrawerHasPendingHandoff($db, $drawerId)) throw new RuntimeException('Resolve the pending handoff before reopening the drawer day.');

        $reason = sanitizeString($_POST['reason'] ?? '', 255);
        if ($reason === '') throw new RuntimeException('Enter a reason for reopening the drawer.');
        $postCloseActivity = $db->prepare(
          "SELECT EXISTS(SELECT 1 FROM payments WHERE drawer_day_id=? AND payment_method='cash' AND payment_status='paid' AND paid_at>?)
               OR EXISTS(SELECT 1 FROM cash_drawer_movements WHERE drawer_day_id=? AND created_at>?)"
        );
        $postCloseActivity->execute([$drawerId, $drawer['closed_at'], $drawerId, $drawer['closed_at']]);
        if ((bool)$postCloseActivity->fetchColumn()) {
          throw new RuntimeException('Cash activity was recorded after this drawer closed. Review it before reopening.');
        }
        $closureStmt = $db->prepare('SELECT id FROM cash_drawer_closures WHERE drawer_day_id=? AND closed_at=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $closureStmt->execute([$drawerId, $drawer['closed_at']]);
        $closureId = (int)$closureStmt->fetchColumn();
        if ($closureId < 1) throw new RuntimeException('The close event is missing from the audit trail, so this drawer cannot be reopened safely.');

        $expected = cashDrawerExpectedAmount($db, $drawerId, (float)$drawer['opening_amount']);
        $variance = round($amount - $expected, 2);
        $db->prepare('INSERT INTO cash_drawer_reopens (drawer_day_id, closure_id, reopened_by, reopened_by_admin_id, reason, expected_amount, recounted_amount, variance) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)')
          ->execute([$drawerId, $closureId, currentUserId(), $reason, $expected, $amount, $variance]);
        $db->prepare("UPDATE cash_drawer_days SET status='open', closing_amount=NULL, expected_closing_amount=NULL, variance=NULL, closed_by=NULL, closed_at=NULL WHERE id=? AND status='closed'")
          ->execute([$drawerId]);
        $db->commit();
        auditLog(ROLE_ADMIN, currentUserId(), 'reopen_cash_drawer', 'cash_drawer_days', $drawerId);
        flash('global', 'Drawer reopened. Recount difference: ' . peso($variance) . '.', $variance == 0.0 ? 'success' : 'warning');
      }
      redirect(APP_URL . '/admin/cash-drawer.php');
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      $errorMessage = $e instanceof PDOException && $e->getCode() === '23000'
        ? 'A drawer record already exists for today.'
        : $e->getMessage();
    }
  }
}

$activeDrawer = getOpenCashDrawer($db);
$todayStmt = $db->prepare('SELECT * FROM cash_drawer_days WHERE business_date=? LIMIT 1');
$todayStmt->execute([$today]);
$todayDrawer = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$drawer = $activeDrawer ?: $todayDrawer;
$report = $drawer ? cashDrawerReportData($db, (int)$drawer['id']) : null;
$canReopenToday = !$activeDrawer && $report && $report['status'] === 'closed'
  && $report['business_date'] === $today && !empty($report['closed_at'])
  && date('Y-m-d', strtotime($report['closed_at'])) === $today;
$expectedNow = $drawer && $drawer['status'] === 'open'
  ? cashDrawerExpectedAmount($db, (int)$drawer['id'], (float)$drawer['opening_amount'])
  : (float)($drawer['expected_closing_amount'] ?? 0);

layoutHeader('Cash Drawer');
?>
<div class="page-header">
  <div>
    <div class="page-header-title">Cash Drawer Control</div>
    <div class="page-header-sub">Admins open the drawer day and handle same-day reopen requests. Cashiers record handoffs and close counts.</div>
  </div>
</div>

<?php showFlash('global'); ?>
<?php if ($errorMessage !== ''): ?>
  <div class="alert alert-warning mb-4"><i class="fa-solid fa-triangle-exclamation"></i>
    <div><?= e($errorMessage) ?></div>
  </div>
<?php endif; ?>
<?php if ($report): ?>
  <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
    <form method="POST" action="<?= APP_URL ?>/api/create-register-reading.php">
      <?= csrfField() ?><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="reading_type" value="<?= $report['status'] === 'open' ? 'X' : 'Z' ?>">
      <button class="btn btn-outline" type="submit"><i class="fa-solid <?= $report['status'] === 'open' ? 'fa-chart-line' : 'fa-file-invoice-dollar' ?>"></i> <?= $report['status'] === 'open' ? 'X Reading' : 'Z Reading' ?></button>
    </form>
  </div>
<?php endif; ?>

<?php if (!$drawer): ?>
  <div class="card" style="max-width:720px">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-cash-register"></i> Open today’s drawer</div>
    </div>
    <div class="card-body">
      <p class="text-muted mb-4">Count the starting cash physically in the shared drawer. The amount and your admin account will be recorded for today.</p>
      <form method="POST" style="display:flex;align-items:end;gap:var(--space-3);flex-wrap:wrap">
        <?= csrfField() ?><input type="hidden" name="drawer_action" value="open">
        <div class="form-group" style="margin:0;min-width:230px;flex:1"><label class="form-label" for="opening-amount">Starting fund (₱)</label><input class="form-control" id="opening-amount" name="amount" type="number" min="0" max="99999999.99" step="0.01" required placeholder="e.g. 1500.00"></div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-lock-open"></i> Open Drawer Day</button>
      </form>
    </div>
  </div>
<?php elseif ($report['status'] === 'open'): ?>
  <?php if ($report['business_date'] < $today): ?>
    <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i>
      <div>The previous drawer day is still open. Ask a cashier to count and close it before starting today’s drawer.</div>
    </div>
  <?php endif; ?>
  <div class="stats-grid">
    <div class="stat-card stat-gold">
      <div class="stat-icon gold"><i class="fa-solid fa-wallet"></i></div>
      <div class="stat-content">
        <div class="stat-label">Starting Fund</div>
        <div class="stat-value"><?= peso($report['opening_amount']) ?></div>
        <div class="stat-sub">Entered by <?= e($report['opened_by_name']) ?> · <?= date('g:i A', strtotime($report['opened_at'])) ?></div>
      </div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-icon red"><i class="fa-solid fa-calculator"></i></div>
      <div class="stat-content">
        <div class="stat-label">Expected Cash</div>
        <div class="stat-value"><?= peso($expectedNow) ?></div>
        <div class="stat-sub">Starting fund + net cash activity</div>
      </div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
      <div class="stat-content">
        <div class="stat-label">Drawer Status</div>
        <div class="stat-value">Open</div>
        <div class="stat-sub">Business date: <?= date('F j, Y', strtotime($report['business_date'])) ?></div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="alert alert-warning mb-4"><i class="fa-solid fa-lock"></i>
    <div>Closed by <strong><?= e($report['closed_by_name'] ?? 'Cashier') ?></strong> at <?= date('M j, g:i A', strtotime($report['closed_at'])) ?>. Counted <?= peso($report['closing_amount']) ?>; variance <?= peso($report['variance']) ?>.</div>
  </div>
  <?php if ($canReopenToday): ?>
    <div class="card" style="max-width:760px">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-arrow-rotate-left"></i> Reopen today’s drawer</div>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3">If this was closed by mistake, recount the cash physically in the drawer. The original close and this admin-authorized recount will both stay in the audit trail.</p>
        <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--space-3);align-items:end">
          <?= csrfField() ?><input type="hidden" name="drawer_action" value="reopen"><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>">
          <div class="form-group" style="margin:0"><label class="form-label" for="reopen-amount">Cash counted now (₱)</label><input class="form-control" id="reopen-amount" name="amount" type="number" min="0" max="99999999.99" step="0.01" required></div>
          <div class="form-group" style="margin:0"><label class="form-label" for="reopen-reason">Reason</label><input class="form-control" id="reopen-reason" name="reason" maxlength="255" required placeholder="e.g. Closed by mistake"></div>
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-lock-open"></i> Recount &amp; Reopen Drawer</button>
        </form>
        <p class="text-muted mt-3" style="margin-bottom:0">Expected cash: <strong><?= peso($expectedNow) ?></strong>. Previous business days cannot be reopened.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i>
      <div>This drawer day cannot be reopened because it was not closed today. Start today’s drawer if no other drawer is open.</div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($report): ?>
  <div class="card mt-4">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-list-check"></i> Close/reopen audit trail</div>
    </div>
    <div class="card-body" style="display:grid;gap:12px">
      <?php if (empty($report['closure_events'])): ?><div class="text-muted">No drawer closes or reopens recorded.</div><?php else: ?>
        <?php foreach ($report['closure_events'] as $event): ?>
          <div>
            <strong>Closed <?= date('M j, g:i A', strtotime($event['closed_at'])) ?> by <?= e($event['closed_by_name'] ?? 'Cashier') ?></strong>
            <div class="text-muted" style="font-size:.85rem">Counted <?= peso($event['closing_amount']) ?> · Expected <?= peso($event['expected_closing_amount']) ?> · Difference <?= peso($event['variance']) ?></div>
            <?php if (!empty($event['reopen_id'])): ?><div class="text-muted" style="font-size:.85rem">Reopened <?= date('M j, g:i A', strtotime($event['reopened_at'])) ?> by <?= e($event['reopened_by_name'] ?? 'Administrator') ?> · Recount <?= peso($event['reopen_recounted_amount']) ?> vs expected <?= peso($event['reopen_expected_amount']) ?> · Difference <?= peso($event['reopen_variance']) ?> · <?= e($event['reopen_reason']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
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