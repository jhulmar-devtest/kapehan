<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/cash-drawer.php';
requireRole(ROLE_ADMIN, ROLE_CASHIER);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}
verifyCsrf();

$drawerId = (int)($_POST['drawer_id'] ?? 0);
$type = strtoupper(trim((string)($_POST['reading_type'] ?? '')));
try {
  $db = Database::getInstance();
  $db->beginTransaction();
  $readingId = createCashRegisterReading($db, $drawerId, $type, currentRole(), (int)currentUserId());
  $db->commit();
  auditLog(currentRole(), currentUserId(), 'create_' . strtolower($type) . '_reading', 'cash_register_readings', $readingId);
  $drawerPage = currentRole() === ROLE_ADMIN ? 'admin' : 'cashier';
  redirect(APP_URL . '/' . $drawerPage . '/cash-drawer.php?day=' . $drawerId . '&reading=' . $readingId);
} catch (Throwable $e) {
  if (isset($db) && $db->inTransaction()) $db->rollBack();
  error_log('Register reading creation failed: ' . $e->getMessage());
  flash('global', $e->getMessage(), 'error');
  redirect(APP_URL . (currentRole() === ROLE_ADMIN ? '/admin/cash-drawer.php' : '/cashier/cash-drawer.php') . ($drawerId ? '?day=' . $drawerId : ''));
}
