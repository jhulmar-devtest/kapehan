<?php
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
  http_response_code(401);
  echo json_encode([]);
  exit;
}

$date = sanitizeString($_GET['date'] ?? '', 10);
date_default_timezone_set('Asia/Manila');
if (empty($date) || $date !== date('Y-m-d')) {
  echo json_encode([]);
  exit;
}

$db = Database::getInstance();
$open     = getSetting('store_open_time', '07:00');
$close    = getSetting('store_close_time', '20:00');
$interval = (int) getSetting('pickup_slot_interval_minutes', '15');
$capacity = (int) getSetting('pickup_slot_capacity', '6');

// How many active orders already exist per slot on this date
$stmt = $db->prepare(
  "SELECT pickup_time, COUNT(*) AS c FROM orders
    WHERE pickup_date = ? AND status IN ('pending','preparing','ready')
    GROUP BY pickup_time"
);
$stmt->execute([$date]);
$taken = [];
foreach ($stmt->fetchAll() as $row) $taken[$row['pickup_time']] = (int) $row['c'];

$slots = [];
$cursor = strtotime($date . ' ' . $open);
$end    = strtotime($date . ' ' . $close);
$now    = time();
$minimumPickupTime = $now + (PICKUP_MIN_LEAD_MINUTES * 60);

while ($cursor < $end) {
  $label = date('H:i', $cursor);
  // Give the shop at least five minutes to prepare orders; a slot inside
  // this lead-time window is no longer offered.
  if ($cursor >= $minimumPickupTime) {
    $slots[] = [
      'value' => $label,
      'label' => date('g:i A', $cursor),
      'full'  => ($taken[$label] ?? 0) >= $capacity,
    ];
  }
  $cursor += $interval * 60;
}

// "ASAP" is just the earliest still-available slot today, relabeled — it
// reuses the exact same value, capacity check, and past-time filtering
// above rather than being a separate concept, so nothing downstream that
// reads pickup_time (order display, urgency badges, receipts, reports)
// needs to know it exists.
foreach ($slots as $i => $s) {
  if (!$s['full']) {
    $slots[$i]['label'] = 'ASAP (~' . $s['label'] . ')';
    $slots[$i]['asap']  = true;
    break;
  }
}

echo json_encode($slots);
