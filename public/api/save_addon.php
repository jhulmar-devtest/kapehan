<?php

/**
 * Retired endpoint: add-ons must be created through Inventory so they have
 * a stock item and a usage quantity before they can be assigned to products.
 */
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

verifyCsrf();
http_response_code(410);
echo json_encode([
  'success' => false,
  'error' => 'Add-ons must be created from Inventory and linked to a stock item.'
]);
