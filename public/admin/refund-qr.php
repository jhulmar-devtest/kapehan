<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/refund-qr.php';
requireRole(ROLE_ADMIN);

$requestId = (int) ($_GET['id'] ?? 0);
$stmt = Database::getInstance()->prepare(
  "SELECT refund_qr_filename
     FROM order_cancellation_requests
    WHERE id = ? AND status = 'refund_required'"
);
$stmt->execute([$requestId]);
$filename = $stmt->fetchColumn();
$path = refundQrFilePath(is_string($filename) ? $filename : null);
if (!$path) {
  http_response_code(404);
  exit;
}

header('Content-Type: ' . refundQrMimeType((string) $filename));
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="refund-qr.' . pathinfo((string) $filename, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
