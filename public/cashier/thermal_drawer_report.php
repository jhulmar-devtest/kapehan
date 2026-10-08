<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/cash-drawer.php';
require_once __DIR__ . '/ThermalPrinter.php';
requireRole(ROLE_CASHIER);

$db = Database::getInstance();
$drawerId = (int)($_GET['drawer_day_id'] ?? 0);
if (!$drawerId) {
  http_response_code(400);
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => 'Missing drawer day.']);
  exit;
}

$report = cashDrawerReportData($db, $drawerId);
if (!$report) {
  http_response_code(404);
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => 'Drawer report not found.']);
  exit;
}

$width = defined('THERMAL_PRINTER_CHARS') ? (int)THERMAL_PRINTER_CHARS : 32;
$printer = new ThermalPrinter($width);
$printer->feed(1);
$printer->center(defined('STORE_NAME') ? STORE_NAME : 'Kapehan');
$printer->sep('=');
$printer->center('CASH DRAWER REPORT');
$printer->sep('=');
$printer->row('Date', date('m/d/Y', strtotime($report['business_date'])));
$printer->row('Status', strtoupper($report['status']));
$printer->row('Opened', date('g:i A', strtotime($report['opened_at'])));
$printer->wrapLeft('By: ' . $report['opened_by_name']);
if ($report['closed_at']) {
  $printer->row('Closed', date('g:i A', strtotime($report['closed_at'])));
  $printer->wrapLeft('By: ' . ($report['closed_by_name'] ?? 'Cashier'));
}
$printer->sep('-');
$printer->amount('Opening fund', (float)$report['opening_amount']);
foreach ($report['payment_totals'] as $payment) {
  $label = ucfirst($payment['payment_method']) . ' sales';
  $printer->amount($label, (float)$payment['sales_total']);
}
$movementNet = 0.0;
foreach ($report['movements'] as $movement) {
  $movementNet += $movement['direction'] === 'in' ? (float)$movement['amount'] : -(float)$movement['amount'];
}
$printer->amount('Cash in/out net', $movementNet);
$printer->amount('Expected cash', (float)$report['expected_now'], true);
if ($report['status'] === 'closed') {
  $printer->amount('Cash counted', (float)$report['closing_amount']);
  $printer->amount('Difference', (float)$report['variance'], true);
}
$printer->feed(1);
$printer->sep('-');
$printer->center('CASH MOVEMENTS');
if (!$report['movements']) {
  $printer->line('No cash movements');
} else {
  foreach ($report['movements'] as $movement) {
    $prefix = $movement['direction'] === 'in' ? '+' : '-';
    $printer->amount($prefix . ucfirst($movement['direction']) . ' ' . $movement['reason'], (float)$movement['amount']);
    $printer->wrapLeft($movement['cashier_name'] . ' · ' . date('g:i A', strtotime($movement['created_at'])));
  }
}
$printer->feed(1);
$printer->sep('-');
$printer->center('CASHIER LOGIN TIMES');
foreach ($report['cashier_sessions'] as $session) {
  $printer->wrapLeft($session['cashier_name']);
  $printer->row(date('g:i A', strtotime($session['login_at'])), $session['logout_at'] ? date('g:i A', strtotime($session['logout_at'])) : 'Still logged in');
}
$printer->feed(1);
$printer->sep('-');
$printer->center('HANDOFF COUNTS');
if (!$report['handoffs']) {
  $printer->line('No handoff counts');
} else {
  foreach ($report['handoffs'] as $handoff) {
    $printer->wrapLeft($handoff['handed_from_name'] . ' -> ' . $handoff['cashier_name'] . ' · ' . date('g:i A', strtotime($handoff['recorded_at'])));
    $printer->amount('Expected', (float)$handoff['expected_amount']);
    $printer->amount('Counted', (float)$handoff['counted_amount']);
    $printer->amount('Difference', (float)$handoff['variance']);
  }
}
$printer->feed(2);
$printer->center('END OF REPORT');
$printer->feed(1)->cut();

$raw = $printer->getBuffer();
$tmp = tempnam(sys_get_temp_dir(), 'drawer_') . '.bin';
if (file_put_contents($tmp, $raw) === false) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => 'Cannot prepare the report for printing.']);
  exit;
}
$command = 'copy /B "' . $tmp . '" "\\\\localhost\\YICHIP" 2>&1';
exec($command, $output, $code);
@unlink($tmp);
if ($code !== 0) {
  $tmp = tempnam(sys_get_temp_dir(), 'drawer_') . '.bin';
  file_put_contents($tmp, $raw);
  $command = 'copy /B "' . $tmp . '" USB001 2>&1';
  exec($command, $output, $code);
  @unlink($tmp);
}
header('Content-Type: application/json');
echo json_encode($code === 0
  ? ['success' => true]
  : ['success' => false, 'error' => 'Thermal printer did not accept the report.']);
