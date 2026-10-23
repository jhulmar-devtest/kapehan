<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN, ROLE_CASHIER);
$db = Database::getInstance();
$stmt = $db->prepare(
  "SELECT r.*, COALESCE(c.full_name, a.full_name, 'Staff') AS issuer_name
     FROM cash_register_readings r
     LEFT JOIN cashiers c ON r.issued_by_role='cashier' AND c.id=r.issued_by_id
     LEFT JOIN admins a ON r.issued_by_role='admin' AND a.id=r.issued_by_id
    WHERE r.id=?"
);
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$reading = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reading) {
  http_response_code(404);
  exit('Reading not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($reading['reading_type']) ?> Reading · <?= APP_NAME ?></title>
  <style>
    body{font:16px/1.5 Arial,sans-serif;color:#241b17;margin:0;padding:32px;background:#f5f1eb}
    .report{max-width:720px;margin:auto;background:#fff;padding:32px;border:1px solid #ddd;border-radius:12px}
    h1,h2,p{margin-top:0} h1{font-size:24px} .sub{color:#665d57}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:24px 0}
    .metric{padding:14px;border:1px solid #e4ded8;border-radius:8px}.metric span{display:block;color:#665d57;font-size:13px}.metric strong{font-size:20px}
    .actions{display:flex;gap:10px;margin:20px auto;max-width:720px}.actions button,.actions a{padding:10px 16px;border:0;border-radius:7px;background:#6a422b;color:white;text-decoration:none;font:inherit;cursor:pointer}
    @media print{body{background:#fff;padding:0}.report{border:0;border-radius:0;padding:12px}.actions{display:none}}
  </style>
</head>
<body>
  <div class="actions">
    <button onclick="window.print()">Print reading</button>
    <a href="<?= APP_URL ?>/<?= currentRole() === ROLE_ADMIN ? 'admin' : 'cashier' ?>/cash-drawer.php?day=<?= (int)$reading['drawer_day_id'] ?>">Back to drawer</a>
  </div>
  <main class="report">
    <h1><?= e($reading['reading_type']) ?> Reading</h1>
    <p class="sub"><?= e(APP_NAME) ?> · Business date <?= date('F j, Y', strtotime($reading['business_date'])) ?></p>
    <p>Reading #<?= (int)$reading['id'] ?> · Issued <?= date('M j, Y g:i:s A', strtotime($reading['issued_at'])) ?> by <?= e($reading['issuer_name']) ?> · Activity through <?= date('M j, Y g:i:s A', strtotime($reading['activity_through'])) ?></p>
    <div class="grid">
      <div class="metric"><span>Paid sales</span><strong><?= peso($reading['sales_total']) ?></strong></div>
      <div class="metric"><span>Transactions</span><strong><?= number_format((int)$reading['transaction_count']) ?></strong></div>
      <div class="metric"><span>Cash sales</span><strong><?= peso($reading['cash_sales']) ?></strong></div>
      <div class="metric"><span>Non-cash sales</span><strong><?= peso($reading['non_cash_sales']) ?></strong></div>
      <div class="metric"><span>Cash added</span><strong><?= peso($reading['cash_in']) ?></strong></div>
      <div class="metric"><span>Cash removed</span><strong><?= peso($reading['cash_out']) ?></strong></div>
      <div class="metric"><span>Expected drawer cash</span><strong><?= peso($reading['expected_cash']) ?></strong></div>
      <?php if ($reading['reading_type'] === 'Z'): ?>
        <div class="metric"><span>Cash counted</span><strong><?= peso($reading['cash_counted']) ?></strong></div>
        <div class="metric"><span>Cash variance</span><strong><?= peso($reading['cash_variance']) ?></strong></div>
      <?php endif; ?>
    </div>
    <p class="sub">This is a saved snapshot of the drawer day. X readings are interim reports. Z readings capture the closed drawer; issuing a report does not change or erase sales records.</p>
  </main>
</body>
</html>
