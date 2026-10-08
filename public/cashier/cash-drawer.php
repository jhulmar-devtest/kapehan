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

  if (in_array($action, ['open', 'handoff', 'close', 'movement_in', 'movement_out'], true) && $amount < 0) {
    $errorMessage = 'Enter a valid amount of zero or more.';
  } elseif ($action === 'open') {
    try {
      $db->beginTransaction();
      $active = $db->query("SELECT id FROM cash_drawer_days WHERE status='open' LIMIT 1 FOR UPDATE")->fetchColumn();
      if ($active) {
        throw new RuntimeException('The shared drawer is already open. Record a handoff or close the current drawer day.');
      }
      $stmt = $db->prepare('INSERT INTO cash_drawer_days (business_date, opening_amount, opened_by) VALUES (?, ?, ?)');
      $stmt->execute([$today, $amount, currentUserId()]);
      $drawerId = (int)$db->lastInsertId();
      $db->commit();
      flash('global', 'Drawer day opened. The starting fund has been recorded.', 'success');
      redirect(APP_URL . '/cashier/cash-drawer.php?day=' . $drawerId);
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      $errorMessage = $e instanceof PDOException && $e->getCode() === '23000'
        ? 'A drawer record already exists for today.'
        : $e->getMessage();
    }
  } elseif (in_array($action, ['handoff', 'close', 'movement_in', 'movement_out'], true)) {
    try {
      $db->beginTransaction();
      $stmt = $db->prepare("SELECT * FROM cash_drawer_days WHERE id=? AND status='open' FOR UPDATE");
      $stmt->execute([$drawerId]);
      $drawer = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$drawer) throw new RuntimeException('This drawer day is no longer open. Refresh the page.');

      $note = sanitizeString($_POST['note'] ?? '', 255);
      if ($action === 'handoff') {
        $expected = cashDrawerExpectedAmount($db, $drawerId, (float)$drawer['opening_amount']);
        $variance = round($amount - $expected, 2);
        $fromCashierId = (int)($_POST['handed_from_cashier_id'] ?? 0);
        if ($fromCashierId <= 0) throw new RuntimeException('Select the cashier handing over the drawer.');
        if ($variance != 0.0 && $note === '') throw new RuntimeException('Add a note explaining the handoff difference.');
        $db->prepare('INSERT INTO cash_drawer_handoffs (drawer_day_id, handed_from_cashier_id, recorded_by, expected_amount, counted_amount, variance, note) VALUES (?,?,?,?,?,?,?)')
          ->execute([$drawerId, $fromCashierId, currentUserId(), $expected, $amount, $variance, $note !== '' ? $note : null]);
        $db->commit();
        flash('global', 'Handoff count recorded. Variance: ' . peso($variance), $variance == 0.0 ? 'success' : 'warning');
      } elseif ($action === 'movement_in' || $action === 'movement_out') {
        if ($amount <= 0 || $note === '') throw new RuntimeException('Enter an amount and a reason for the cash movement.');
        $direction = $action === 'movement_in' ? 'in' : 'out';
        $db->prepare('INSERT INTO cash_drawer_movements (drawer_day_id, cashier_id, direction, amount, reason) VALUES (?,?,?,?,?)')
          ->execute([$drawerId, currentUserId(), $direction, $amount, $note]);
        $db->commit();
        flash('global', 'Cash movement recorded.', 'success');
      } else {
        $expected = cashDrawerExpectedAmount($db, $drawerId, (float)$drawer['opening_amount']);
        $variance = round($amount - $expected, 2);
        $db->prepare("UPDATE cash_drawer_days SET status='closed', closing_amount=?, expected_closing_amount=?, variance=?, closed_by=?, closed_at=NOW() WHERE id=? AND status='open'")
          ->execute([$amount, $expected, $variance, currentUserId(), $drawerId]);
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

$drawer = null;
if (!empty($_GET['day'])) {
  $stmt = $db->prepare('SELECT * FROM cash_drawer_days WHERE id=?');
  $stmt->execute([(int)$_GET['day']]);
  $drawer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$drawer) $drawer = getOpenCashDrawer($db);
if (!$drawer) {
  $stmt = $db->prepare('SELECT * FROM cash_drawer_days WHERE business_date=? LIMIT 1');
  $stmt->execute([$today]);
  $drawer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$report = $drawer ? cashDrawerReportData($db, (int)$drawer['id']) : null;
$cashiers = $db->query('SELECT id,full_name FROM cashiers WHERE is_active=1 ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
$expectedNow = $drawer && $drawer['status'] === 'open'
  ? cashDrawerExpectedAmount($db, (int)$drawer['id'], (float)$drawer['opening_amount'])
  : (float)($drawer['expected_closing_amount'] ?? 0);

layoutHeader('Cash Drawer');
?>
<div class="page-header">
  <div>
    <div class="page-header-title">Shared Cash Drawer</div>
    <div class="page-header-sub">One opening and closing count for the physical drawer each day.</div>
  </div>
  <?php if ($report): ?>
    <div class="page-header-actions">
      <button class="btn btn-primary" id="drawer-print-btn" onclick="printDrawerReport(<?= (int)$report['id'] ?>)"><i class="fa-solid fa-print"></i> Print Report</button>
    </div>
  <?php endif; ?>
</div>

<?php showFlash('global'); ?>
<?php if ($errorMessage !== ''): ?>
  <div class="alert alert-warning mb-4"><i class="fa-solid fa-triangle-exclamation"></i><div><?= e($errorMessage) ?></div></div>
<?php endif; ?>

<?php if (!$drawer): ?>
  <div class="card" style="max-width:680px">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-cash-register"></i> Open today’s drawer</div></div>
    <div class="card-body">
      <p class="text-muted mb-4">Count the cash already in the physical drawer and enter it once. Other cashiers can log in without entering another starting fund.</p>
      <form method="POST" style="display:flex;align-items:end;gap:var(--space-3);flex-wrap:wrap">
        <?= csrfField() ?>
        <input type="hidden" name="drawer_action" value="open">
        <div class="form-group" style="margin:0;min-width:230px;flex:1">
          <label class="form-label" for="opening-amount">Starting fund (₱)</label>
          <input class="form-control" id="opening-amount" name="amount" type="number" min="0" step="0.01" required placeholder="e.g. 1500.00">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-lock-open"></i> Open Drawer Day</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="stats-grid">
    <div class="stat-card stat-gold"><div class="stat-icon gold"><i class="fa-solid fa-wallet"></i></div><div class="stat-content"><div class="stat-label">Opening Fund</div><div class="stat-value"><?= peso($report['opening_amount']) ?></div><div class="stat-sub">Opened by <?= e($report['opened_by_name']) ?> · <?= date('g:i A', strtotime($report['opened_at'])) ?></div></div></div>
    <div class="stat-card stat-red"><div class="stat-icon red"><i class="fa-solid fa-calculator"></i></div><div class="stat-content"><div class="stat-label">Expected Cash</div><div class="stat-value"><?= peso($expectedNow) ?></div><div class="stat-sub">Opening fund + net cash activity</div></div></div>
    <div class="stat-card <?= $report['status'] === 'open' ? 'stat-green' : '' ?>"><div class="stat-icon <?= $report['status'] === 'open' ? 'green' : 'brown' ?>"><i class="fa-solid fa-circle-<?= $report['status'] === 'open' ? 'check' : 'minus' ?>"></i></div><div class="stat-content"><div class="stat-label">Drawer Status</div><div class="stat-value" style="text-transform:capitalize"><?= e($report['status']) ?></div><div class="stat-sub">Business date: <?= date('F j, Y', strtotime($report['business_date'])) ?></div></div></div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-column"></i> Sales during this drawer day</div></div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-3)">
      <?php if (empty($report['payment_totals'])): ?><div class="text-muted">No paid orders recorded during this drawer day yet.</div><?php endif; ?>
      <?php foreach ($report['payment_totals'] as $total): ?>
        <div><div class="text-muted" style="font-size:.78rem;text-transform:capitalize"><?= e($total['payment_method']) ?> · <?= (int)$total['transaction_count'] ?> orders</div><strong style="font-size:1.15rem"><?= peso($total['sales_total']) ?></strong></div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($report['status'] === 'open'): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:var(--space-4)">
      <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-right-left"></i> Cashier handoff count</div></div>
        <div class="card-body">
          <p class="text-muted mb-3">Optional. Count the shared drawer when responsibility changes. The system records any difference without resetting the day’s expected balance.</p>
          <form method="POST">
            <?= csrfField() ?><input type="hidden" name="drawer_action" value="handoff"><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>">
            <div class="form-group"><label class="form-label" for="handoff-from">Cashier handing over the drawer</label><select class="form-control" id="handoff-from" name="handed_from_cashier_id" required><option value="">Select cashier</option><?php foreach ($cashiers as $cashier): ?><option value="<?= (int)$cashier['id'] ?>"><?= e($cashier['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label" for="handoff-amount">Cash counted (₱)</label><input class="form-control" id="handoff-amount" name="amount" type="number" min="0" step="0.01" required></div>
            <div class="form-group"><label class="form-label" for="handoff-note">Note <span class="text-muted">(required if there’s a difference)</span></label><input class="form-control" id="handoff-note" name="note" maxlength="255" placeholder="Explain any difference"></div>
            <button class="btn btn-ghost" type="submit"><i class="fa-solid fa-clipboard-check"></i> Record Handoff Count</button>
          </form>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-money-bill-transfer"></i> Record cash movement</div></div>
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
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-lock"></i> Close drawer day</div></div>
        <div class="card-body">
          <p class="text-muted mb-3">Count all the cash in the drawer, including the starting fund. Expected cash right now is <strong><?= peso($expectedNow) ?></strong>.</p>
          <form method="POST" onsubmit="return confirm('Close the shared drawer for today? This records the final count and variance.');">
            <?= csrfField() ?><input type="hidden" name="drawer_action" value="close"><input type="hidden" name="drawer_id" value="<?= (int)$report['id'] ?>">
            <div class="form-group"><label class="form-label" for="closing-amount">Cash counted at closing (₱)</label><input class="form-control" id="closing-amount" name="amount" type="number" min="0" step="0.01" required></div>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-lock"></i> Close &amp; Save Count</button>
          </form>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="alert <?= (float)$report['variance'] === 0.0 ? 'alert-success' : 'alert-warning' ?> mb-4">
      <i class="fa-solid fa-<?= (float)$report['variance'] === 0.0 ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      <div>Closed by <strong><?= e($report['closed_by_name'] ?? 'Cashier') ?></strong> at <?= date('g:i A', strtotime($report['closed_at'])) ?>. Counted <?= peso($report['closing_amount']) ?>; variance <?= peso($report['variance']) ?>.</div>
    </div>
  <?php endif; ?>

  <div class="card mt-4">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-list-check"></i> Drawer audit trail</div></div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--space-5)">
      <section><h3 style="font-size:.95rem;margin-bottom:var(--space-2)">Cashier logins</h3>
        <?php if (!$report['cashier_sessions']): ?><div class="text-muted">No login sessions overlap this drawer day.</div><?php else: ?><div style="display:grid;gap:8px"><?php foreach ($report['cashier_sessions'] as $session): ?><div><strong><?= e($session['cashier_name']) ?></strong><div class="text-muted" style="font-size:.8rem"><?= date('g:i A', strtotime($session['login_at'])) ?> – <?= $session['logout_at'] ? date('g:i A', strtotime($session['logout_at'])) : 'Still logged in' ?></div></div><?php endforeach; ?></div><?php endif; ?>
      </section>
      <section><h3 style="font-size:.95rem;margin-bottom:var(--space-2)">Handoff counts</h3>
        <?php if (!$report['handoffs']): ?><div class="text-muted">No handoff counts recorded.</div><?php else: ?><div style="display:grid;gap:8px"><?php foreach ($report['handoffs'] as $handoff): ?><div><strong><?= e($handoff['handed_from_name']) ?> → <?= e($handoff['cashier_name']) ?></strong><div class="text-muted" style="font-size:.8rem"><?= date('g:i A', strtotime($handoff['recorded_at'])) ?> · Expected <?= peso($handoff['expected_amount']) ?> · Counted <?= peso($handoff['counted_amount']) ?> · Difference <?= peso($handoff['variance']) ?><?= $handoff['note'] ? ' · ' . e($handoff['note']) : '' ?></div></div><?php endforeach; ?></div><?php endif; ?>
      </section>
      <section><h3 style="font-size:.95rem;margin-bottom:var(--space-2)">Cash movements</h3>
        <?php if (!$report['movements']): ?><div class="text-muted">No cash movements recorded.</div><?php else: ?><div style="display:grid;gap:8px"><?php foreach ($report['movements'] as $movement): ?><div><strong><?= ucfirst($movement['direction']) ?> <?= peso($movement['amount']) ?></strong><div class="text-muted" style="font-size:.8rem"><?= e($movement['cashier_name']) ?> · <?= date('g:i A', strtotime($movement['created_at'])) ?> · <?= e($movement['reason']) ?></div></div><?php endforeach; ?></div><?php endif; ?>
      </section>
    </div>
  </div>
<?php endif; ?>

<?php
$recentDrawerDays = $db->query('SELECT id,business_date,status,opening_amount,closing_amount,variance FROM cash_drawer_days ORDER BY business_date DESC LIMIT 14')->fetchAll(PDO::FETCH_ASSOC);
if ($recentDrawerDays && (count($recentDrawerDays) > 1 || !$report)):
?>
  <div class="card mt-4">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-calendar-days"></i> Recent drawer reports</div></div>
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
    .then(data => { if (!data.success) throw new Error(data.error || 'Printer error'); btn.innerHTML = '<i class="fa-solid fa-check"></i> Sent to printer'; setTimeout(() => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-print"></i> Print Report'; }, 2500); })
    .catch(err => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-print"></i> Print Report'; alert(err.message); });
}
</script>
<?php layoutFooter(); ?>
