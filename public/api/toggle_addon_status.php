<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

verifyCsrf();

$addonId = (int)($_POST['addon_id'] ?? 0);
$status  = $_POST['status'] ?? '';

if ($addonId < 1 || !in_array($status, ['active', 'inactive'])) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
  exit;
}

try {
  $db = Database::getInstance();
  if ($status === 'active') {
    $check = $db->prepare("SELECT COUNT(*) FROM addon_ingredients WHERE addon_id = ?");
    $check->execute([$addonId]);
    if ((int) $check->fetchColumn() === 0) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'Link this add-on to an inventory item before activating it']);
      exit;
    }
  }
  $db->prepare("UPDATE addons SET status = ? WHERE id = ?")
    ->execute([$status, $addonId]);
  auditLog(ROLE_ADMIN, currentUserId(), 'toggle_addon_status', 'addons', $addonId);
  echo json_encode(['success' => true, 'status' => $status]);
} catch (\Throwable $e) {
  error_log("Toggle addon status error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to update status']);
}
