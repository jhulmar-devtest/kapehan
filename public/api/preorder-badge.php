<?php
// api/preorder-badge.php
// Called by the sidebar polling script every 30s.
// Returns JSON: { count: int, urgency: int }
// urgency: 1=normal, 2=warning (≤30 mins), 3=danger (overdue/ASAP pending)

require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json');

// Must be a cashier (or admin) and an XHR request
if (
  !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
  !in_array(currentRole(), [ROLE_CASHIER, ROLE_ADMIN], true)
) {
  echo json_encode(['count' => 0, 'urgency' => 0]);
  exit;
}

echo json_encode([
  'count'   => pendingPreorderCount(),
  'urgency' => preorderUrgency(),
]);
