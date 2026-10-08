<?php
// api/keepalive.php
// Called by the walk-in POS every 4 minutes to prevent session timeout.
// Must be POST + XHR. Returns JSON { ok: true }.

require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json');

// Only accept XHR POST from logged-in cashiers/admins
if (
  $_SERVER['REQUEST_METHOD'] !== 'POST' ||
  empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
  !in_array(currentRole(), [ROLE_CASHIER, ROLE_ADMIN], true)
) {
  http_response_code(403);
  echo json_encode(['ok' => false]);
  exit;
}

// Touching $_SESSION is enough to reset the session timer
$_SESSION['_keepalive'] = time();

// Also tell the cashier_sessions row we're still here, so the drawer audit
// trail can tell an idle-but-open POS from an abandoned session.
if (currentRole() === ROLE_CASHIER) {
  touchCashierSession();
}

echo json_encode(['ok' => true]);
