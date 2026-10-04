<?php

/**
 * thermal_receipt.php
 * GET ?order_id=X           → print to YICHIP
 * GET ?order_id=X&preview=1 → browser text preview
 */

require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_CASHIER);
require_once __DIR__ . '/ThermalPrinter.php';

$db      = Database::getInstance();
$orderId = (int)($_GET['order_id'] ?? 0);

if (!$orderId) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Missing order_id']);
  exit;
}

$stmt = $db->prepare(
  "SELECT o.*, p.payment_method, p.amount_paid, p.change_given, p.reference_number,
            c.full_name AS cashier_name,
            COALESCE(s.full_name, f.full_name, 'Walk-in') AS customer_name
     FROM orders o
     LEFT JOIN payments p  ON o.id = p.order_id
     LEFT JOIN cashiers c  ON o.cashier_id = c.id
     LEFT JOIN students s  ON o.student_id = s.id
     LEFT JOIN faculty  f  ON o.faculty_id = f.id
     WHERE o.id = ?"
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
  http_response_code(404);
  echo json_encode(['success' => false, 'error' => 'Order not found']);
  exit;
}

$stmt = $db->prepare(
  "SELECT od.quantity, od.price_at_time, od.subtotal, od.customization_note, pr.name
     FROM order_details od
     JOIN products pr ON od.product_id = pr.id
     WHERE od.order_id = ?"
);
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

// ── Config ────────────────────────────────────────────────────────────
// In config/init.php:
//   define('THERMAL_PRINTER_CHARS', 32);
//   define('STORE_NAME',    'Kapehan ni Amang');
//   define('STORE_ADDRESS', 'EARIST Cavite Campus GMA Cavite');
//   define('STORE_TEL',     '');
//   define('RECEIPT_FOOTER','Thank you for your order!');
$W         = defined('THERMAL_PRINTER_CHARS') ? (int)THERMAL_PRINTER_CHARS : 32;
$storeName = defined('STORE_NAME')             ? STORE_NAME     : 'Canteen';
$storeAddr = defined('STORE_ADDRESS')          ? STORE_ADDRESS  : '';
$storeTel  = defined('STORE_TEL')             ? STORE_TEL     : '';
$footer    = defined('RECEIPT_FOOTER')         ? RECEIPT_FOOTER : 'Thank you for your order!';

// ════════════════════════════════════════════════════════════════════
$p = new ThermalPrinter($W);

// ── HEADER ────────────────────────────────────────────────────────────
$p->feed(1);
$p->center($storeName);
if ($storeAddr) $p->wrapCenter($storeAddr);
if ($storeTel)  $p->center('Tel: ' . $storeTel);
$p->feed(1);
$p->sep('=');
$p->center('OFFICIAL RECEIPT');
$p->sep('=');
$p->feed(1);

// ── ORDER META ────────────────────────────────────────────────────────
// Short label + value on same line where possible
$p->row('Order#', $order['order_number']);
$p->row('Date',   date('m/d/Y', strtotime($order['created_at'])));
$p->row('Time',   date('g:i A', strtotime($order['created_at'])));
$p->row('Type',   ucfirst($order['order_type']));
$p->row('Cust',   $order['customer_name']);
$p->row('By',     $order['cashier_name'] ?? '');
$p->feed(1);
$p->sep('-');
$p->feed(1);

// ── ITEMS ─────────────────────────────────────────────────────────────
foreach ($items as $idx => $item) {
  if ($idx > 0) $p->feed(1);

  $p->item(
    (int)$item['quantity'],
    $item['name'],
    (float)$item['subtotal']
  );

  if ((int)$item['quantity'] > 1) {
    $p->line('  @P' . number_format((float)$item['price_at_time'], 2) . ' each');
  }

  if (!empty($item['customization_note'])) {
    $p->wrapLeft($item['customization_note'], '  > ');
  }
}

$p->feed(1);
$p->sep('-');
$p->feed(1);

// ── TOTALS ────────────────────────────────────────────────────────────
$total = (float)$order['total_amount'];
$paid  = (float)($order['amount_paid']  ?? 0);
$chg   = (float)($order['change_given'] ?? 0);

$p->amount('Subtotal', $total);
$p->sep('-');
$p->amount('TOTAL', $total, true);
$p->feed(1);

// ── PAYMENT ───────────────────────────────────────────────────────────
if ($order['payment_method']) {
  $method = ucfirst($order['payment_method']);
  $p->sep('-');
  $p->feed(1);
  $p->amount($method . ' Paid', $paid);
  if ($chg > 0) {
    $p->amount('Change', $chg);
  }
  if (!empty($order['reference_number'])) {
    $p->row('Ref#', $order['reference_number']);
  }
  $p->feed(1);
}

// ── STATUS ────────────────────────────────────────────────────────────
$p->sep('=');
$p->center('[ ' . strtoupper($order['status']) . ' ]');
$p->sep('=');
$p->feed(1);

// ── FOOTER ────────────────────────────────────────────────────────────
$p->wrapCenter($footer);
$p->cut();

$raw = $p->getBuffer();

// ── PREVIEW ───────────────────────────────────────────────────────────
if (!empty($_GET['preview'])) {
  header('Content-Type: text/plain; charset=utf-8');
  $border = str_repeat('=', $W);
  echo $border . "\n  PREVIEW ({$W} chars)\n" . $border . "\n";
  $result = '';
  $len    = strlen($raw);
  $i      = 0;
  while ($i < $len) {
    $b = ord($raw[$i]);
    if ($b === 0x1B) {
      $n = isset($raw[$i + 1]) ? ord($raw[$i + 1]) : 0;
      $i += ($n === 0x40) ? 2 : 3;
    } elseif ($b === 0x1D) {
      $i += 3;
      if (isset($raw[$i]) && ord($raw[$i]) === 0x00) $i++;
    } elseif ($b === 0x0A) {
      $result .= "\n";
      $i++;
    } elseif ($b === 0x0D) {
      $i++;
    } elseif ($b >= 0x20 && $b <= 0x7E) {
      $result .= $raw[$i];
      $i++;
    } else {
      $i++;
    }
  }
  echo $result . "\n" . $border . "\n[END]\n";
  exit;
}

// ── PRINT ─────────────────────────────────────────────────────────────
header('Content-Type: application/json');
echo json_encode(sendToYichip($raw));
exit;

function sendToYichip(string $data): array {
  $tmp = tempnam(sys_get_temp_dir(), 'rcpt_') . '.bin';
  if (file_put_contents($tmp, $data) === false) {
    return ['success' => false, 'error' => 'Cannot write temp file'];
  }
  $cmd1 = 'copy /B "' . $tmp . '" "\\\\localhost\\YICHIP" 2>&1';
  exec($cmd1, $out1, $rc1);
  @unlink($tmp);
  if ($rc1 === 0) return ['success' => true, 'method' => 'shared'];

  $tmp2 = tempnam(sys_get_temp_dir(), 'rcpt_') . '.bin';
  file_put_contents($tmp2, $data);
  $cmd2 = 'copy /B "' . $tmp2 . '" USB001 2>&1';
  exec($cmd2, $out2, $rc2);
  @unlink($tmp2);
  if ($rc2 === 0) return ['success' => true, 'method' => 'usb001'];

  return [
    'success' => false,
    'error'   => 'M1:[' . $rc1 . '] ' . implode(' ', $out1) . ' | M2:[' . $rc2 . '] ' . implode(' ', $out2),
  ];
}
