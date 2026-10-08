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
    case 'timeout':     return $label . ' (auto, inactive)';
    case 'deactivated': return $label . ' (account deactivated)';
    default:            return $label;
  }
}

function cashDrawerReportData(PDO $db, int $drawerId): ?array
{
  // Close sessions that expired with nobody around to log them out (closed
  // tab, walked away) so they aren't reported as "Still logged in".
  closeStaleCashierSessions($db);

  $stmt = $db->prepare(
    "SELECT d.*, opener.full_name AS opened_by_name, closer.full_name AS closed_by_name
     FROM cash_drawer_days d
     JOIN cashiers opener ON opener.id = d.opened_by
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

  // The drawer can be closed before the calendar day ends. Keep same-day
  // cashier logins visible in its audit trail, including logins made after
  // close, and label those separately in the report.
  $nextDayAt = date('Y-m-d H:i:s', strtotime($drawer['business_date'] . ' +1 day'));
  $stmt = $db->prepare(
     "SELECT s.login_at, s.logout_at, s.logout_reason,
            (d.closed_at IS NOT NULL AND s.login_at > d.closed_at) AS after_drawer_close,
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
