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
    $check = $db->prepare(
      'SELECT COUNT(*) AS total_rows,
              SUM(i.id IS NOT NULL AND i.archived_at IS NULL AND ai.qty_per_unit > 0) AS usable_rows
         FROM addon_ingredients ai
         LEFT JOIN inventory_items i ON i.id = ai.inventory_item_id
        WHERE ai.addon_id = ?'
    );
    $check->execute([$addonId]);
    $ingredients = $check->fetch(PDO::FETCH_ASSOC);
    if ((int) ($ingredients['total_rows'] ?? 0) === 0 || (int) ($ingredients['usable_rows'] ?? 0) !== (int) ($ingredients['total_rows'] ?? 0)) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'Link this add-on to active stock items with valid portions before activating it.']);
      exit;
    }
  }
  $stmt = $db->prepare("UPDATE addons SET status = ? WHERE id = ? AND archived_at IS NULL");
  $stmt
    ->execute([$status, $addonId]);
  if (!$stmt->rowCount()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Add-on not found or already archived.']);
    exit;
  }
  auditLog(ROLE_ADMIN, currentUserId(), 'toggle_addon_status', 'addons', $addonId);
  echo json_encode(['success' => true, 'status' => $status]);
} catch (\Throwable $e) {
  error_log("Toggle addon status error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to update status']);
}
