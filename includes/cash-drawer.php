<?php
// Shared-drawer helpers. The physical drawer is counted by staff; this file
// only records the counts and payments associated with the current business day.

function getOpenCashDrawer(PDO $db, bool $forUpdate = false): ?array
{
  $sql = "SELECT * FROM cash_drawer_days WHERE status = 'open' ORDER BY id DESC LIMIT 1";
  if ($forUpdate) $sql .= ' FOR UPDATE';
  $stmt = $db->query($sql);
  $drawer = $stmt->fetch(PDO::FETCH_ASSOC);
  return $drawer ?: null;
}

function cashDrawerHasPendingHandoff(PDO $db, int $drawerId): bool
{
  $stmt = $db->prepare("SELECT 1 FROM cash_drawer_handoffs WHERE drawer_day_id=? AND status='pending' LIMIT 1");
  $stmt->execute([$drawerId]);
  return (bool)$stmt->fetchColumn();
}

function cashDrawerExpectedAmount(PDO $db, int $drawerId, float $openingAmount, ?string $asOf = null): float
{
  $paymentSql = "SELECT COALESCE(SUM(p.amount_paid - p.change_given), 0)
                 FROM payments p
                 WHERE p.drawer_day_id = ? AND p.payment_method = 'cash'
                   AND p.payment_status = 'paid'";
  $movementSql = "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)
                  FROM cash_drawer_movements WHERE drawer_day_id = ?";
  $paymentParams = [$drawerId];
  $movementParams = [$drawerId];
  if ($asOf !== null) {
    $paymentSql .= ' AND p.paid_at <= ?';
    $movementSql .= ' AND created_at <= ?';
    $paymentParams[] = $asOf;
    $movementParams[] = $asOf;
  }
  $paymentStmt = $db->prepare($paymentSql);
  $paymentStmt->execute($paymentParams);
  $movementStmt = $db->prepare($movementSql);
  $movementStmt->execute($movementParams);
  return round($openingAmount + (float)$paymentStmt->fetchColumn() + (float)$movementStmt->fetchColumn(), 2);
}

// Text for the "logged out" end of a cashier_sessions row in the audit trail.
function cashierSessionEndLabel(array $session, string $timeFormat = 'g:i A'): string
{
  if (empty($session['logout_at'])) return 'Still logged in';
  $label = date($timeFormat, strtotime($session['logout_at']));
  switch ($session['logout_reason'] ?? null) {
    case 'timeout':
      return $label . ' (auto, inactive)';
    case 'deactivated':
      return $label . ' (account deactivated)';
    default:
      return $label;
  }
}

function cashDrawerReportData(PDO $db, int $drawerId): ?array
{
  // Close sessions that expired with nobody around to log them out (closed
  // tab, walked away) so they aren't reported as "Still logged in".
  closeStaleCashierSessions($db);

  $stmt = $db->prepare(
    "SELECT d.*, COALESCE(opener.full_name, admin_opener.full_name, 'Administrator') AS opened_by_name,
            closer.full_name AS closed_by_name
     FROM cash_drawer_days d
     LEFT JOIN cashiers opener ON opener.id = d.opened_by
     LEFT JOIN admins admin_opener ON admin_opener.id = d.opened_by_admin_id
     LEFT JOIN cashiers closer ON closer.id = d.closed_by
     WHERE d.id = ?"
  );
  $stmt->execute([$drawerId]);
  $drawer = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$drawer) return null;

  $endAt = $drawer['closed_at'] ?: date('Y-m-d H:i:s');
  $stmt = $db->prepare(
    "SELECT p.payment_method, COUNT(*) AS transaction_count,
            COALESCE(SUM(o.total_amount), 0) AS sales_total
     FROM payments p
     JOIN orders o ON o.id = p.order_id
     WHERE p.payment_status = 'paid' AND p.paid_at >= ? AND p.paid_at <= ?
       AND o.status <> 'cancelled'
     GROUP BY p.payment_method ORDER BY p.payment_method"
  );
  $stmt->execute([$drawer['opened_at'], $endAt]);
  $drawer['payment_totals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $drawer['expected_now'] = cashDrawerExpectedAmount($db, $drawerId, (float)$drawer['opening_amount'], $endAt);

  $stmt = $db->prepare(
    "SELECT h.*, c.full_name AS cashier_name, source.full_name AS handed_from_name,
            receiver.full_name AS confirmed_by_name
     FROM cash_drawer_handoffs h
     JOIN cashiers c ON c.id = h.recorded_by
     JOIN cashiers source ON source.id = h.handed_from_cashier_id
     LEFT JOIN cashiers receiver ON receiver.id = h.confirmed_by
     WHERE h.drawer_day_id = ? ORDER BY h.recorded_at"
  );
  $stmt->execute([$drawerId]);
  $drawer['handoffs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $stmt = $db->prepare(
    "SELECT m.*, c.full_name AS cashier_name
     FROM cash_drawer_movements m JOIN cashiers c ON c.id = m.cashier_id
     WHERE m.drawer_day_id = ? ORDER BY m.created_at"
  );
  $stmt->execute([$drawerId]);
  $drawer['movements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $stmt = $db->prepare(
    "SELECT e.*, c.full_name AS closed_by_name,
            r.id AS reopen_id, r.reopened_at, r.reason AS reopen_reason,
            r.expected_amount AS reopen_expected_amount,
            r.recounted_amount AS reopen_recounted_amount,
            r.variance AS reopen_variance,
            COALESCE(rc.full_name, ra.full_name, 'Administrator') AS reopened_by_name
       FROM cash_drawer_closures e
       LEFT JOIN cashiers c ON c.id = e.closed_by
       LEFT JOIN cash_drawer_reopens r ON r.closure_id = e.id
       LEFT JOIN cashiers rc ON rc.id = r.reopened_by
       LEFT JOIN admins ra ON ra.id = r.reopened_by_admin_id
      WHERE e.drawer_day_id = ?
      ORDER BY e.closed_at DESC, e.id DESC, r.reopened_at DESC"
  );
  $stmt->execute([$drawerId]);
  $drawer['closure_events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $stmt = $db->prepare(
    "SELECT r.*, COALESCE(c.full_name, a.full_name, 'Administrator') AS reopened_by_name
       FROM cash_drawer_reopens r
       LEFT JOIN cashiers c ON c.id = r.reopened_by
       LEFT JOIN admins a ON a.id = r.reopened_by_admin_id
      WHERE r.drawer_day_id = ? ORDER BY r.reopened_at DESC, r.id DESC"
  );
  $stmt->execute([$drawerId]);
  $drawer['reopen_events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // The drawer can be closed before the calendar day ends. Keep same-day
  // cashier logins visible in its audit trail, including logins made after
  // close, and label those separately in the report.
  $nextDayAt = date('Y-m-d H:i:s', strtotime($drawer['business_date'] . ' +1 day'));
  $stmt = $db->prepare(
    "SELECT s.login_at, s.logout_at, s.logout_reason,
            EXISTS (
              SELECT 1 FROM cash_drawer_closures e
              LEFT JOIN cash_drawer_reopens r ON r.closure_id = e.id
              WHERE e.drawer_day_id = d.id AND s.login_at > e.closed_at
                AND (r.reopened_at IS NULL OR s.login_at < r.reopened_at)
            ) AS after_drawer_close,
            c.full_name AS cashier_name
     FROM cashier_sessions s
     JOIN cash_drawer_days d ON d.id = ?
     JOIN cashiers c ON c.id = s.cashier_id
     WHERE s.login_at >= ? AND s.login_at < ?
     ORDER BY s.login_at DESC"
  );
  $stmt->execute([$drawerId, $drawer['business_date'] . ' 00:00:00', $nextDayAt]);
  $drawer['cashier_sessions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
  return $drawer;
}

/** Persist a point-in-time X reading or end-of-day Z reading for one drawer day. */
function createCashRegisterReading(PDO $db, int $drawerId, string $readingType, string $issuerRole, int $issuerId): int
{
  if (!$db->inTransaction()) throw new LogicException('Register readings must be created inside a transaction.');
  if (!in_array($readingType, ['X', 'Z'], true)) throw new InvalidArgumentException('Choose an X or Z reading.');

  $drawerStmt = $db->prepare('SELECT * FROM cash_drawer_days WHERE id=? FOR UPDATE');
  $drawerStmt->execute([$drawerId]);
  $drawer = $drawerStmt->fetch(PDO::FETCH_ASSOC);
  if (!$drawer) throw new RuntimeException('The drawer day could not be found.');
  if ($readingType === 'X' && $drawer['status'] !== 'open') {
    throw new RuntimeException('An X reading can only be created while the drawer is open.');
  }
  if ($readingType === 'Z' && $drawer['status'] !== 'closed') {
    throw new RuntimeException('Close the drawer before creating a Z reading.');
  }

  $issuedAt = (string)$db->query('SELECT NOW()')->fetchColumn();
  $activityThrough = $readingType === 'Z' ? $drawer['closed_at'] : $issuedAt;
  $salesStmt = $db->prepare(
    "SELECT p.payment_method, COALESCE(SUM(o.total_amount), 0) AS sales_total
       FROM payments p JOIN orders o ON o.id=p.order_id
      WHERE p.payment_status='paid' AND p.paid_at >= ? AND p.paid_at <= ?
        AND o.status <> 'cancelled'
      GROUP BY p.payment_method"
  );
  $salesStmt->execute([$drawer['opened_at'], $activityThrough]);
  $paymentTotals = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
  $salesTotal = 0.0;
  $cashSales = 0.0;
  $nonCashSales = 0.0;
  foreach ($paymentTotals as $payment) {
    $method = (string)$payment['payment_method'];
    $amount = (float)$payment['sales_total'];
    $salesTotal += $amount;
    if (strcasecmp($method, 'cash') === 0) $cashSales += $amount;
    else $nonCashSales += $amount;
  }
  $countStmt = $db->prepare(
    "SELECT COUNT(DISTINCT o.id) FROM payments p JOIN orders o ON o.id=p.order_id
      WHERE p.payment_status='paid' AND p.paid_at >= ? AND p.paid_at <= ? AND o.status <> 'cancelled'"
  );
  $countStmt->execute([$drawer['opened_at'], $activityThrough]);
  $transactionCount = (int)$countStmt->fetchColumn();

  $movementStmt = $db->prepare(
    "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) AS cash_in,
            COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) AS cash_out
       FROM cash_drawer_movements WHERE drawer_day_id=? AND created_at <= ?"
  );
  $movementStmt->execute([$drawerId, $activityThrough]);
  $movements = $movementStmt->fetch(PDO::FETCH_ASSOC) ?: ['cash_in' => 0, 'cash_out' => 0];
  $expectedCash = cashDrawerExpectedAmount($db, $drawerId, (float)$drawer['opening_amount'], $activityThrough);
  $countedCash = $readingType === 'Z' ? (float)$drawer['closing_amount'] : null;
  $variance = $readingType === 'Z' ? round($countedCash - $expectedCash, 2) : null;

  $insert = $db->prepare(
    'INSERT INTO cash_register_readings
      (drawer_day_id, reading_type, business_date, issued_at, activity_through, issued_by_role, issued_by_id,
       sales_total, transaction_count, cash_sales, non_cash_sales, expected_cash,
       cash_counted, cash_variance, cash_in, cash_out)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $insert->execute([
    $drawerId,
    $readingType,
    $drawer['business_date'],
    $issuedAt,
    $activityThrough,
    $issuerRole,
    $issuerId,
    round($salesTotal, 2),
    $transactionCount,
    round($cashSales, 2),
    round($nonCashSales, 2),
    $expectedCash,
    $countedCash,
    $variance,
    (float)$movements['cash_in'],
    (float)$movements['cash_out'],
  ]);
  return (int)$db->lastInsertId();
}
