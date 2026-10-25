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
$isClosingReading = $reading['reading_type'] === 'Z';
$embedded = ($_GET['embed'] ?? '') === '1';
$variance = $reading['cash_variance'] === null ? null : (float)$reading['cash_variance'];
$backPath = currentRole() === ROLE_ADMIN ? 'admin' : 'cashier';
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($reading['reading_type']) ?> Reading · <?= APP_NAME ?></title>
  <style>
    * {
      box-sizing: border-box
    }

    body {
      font: 15px/1.5 Arial, sans-serif;
      color: #251b16;
      margin: 0;
      padding: 28px 16px;
      background: #f5f1eb
    }

    .shell {
      max-width: 760px;
      margin: 0 auto
    }

    .actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin: 0 auto 16px
    }

    .actions a,
    .actions button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 42px;
      padding: 9px 15px;
      border: 1px solid #d7cbc1;
      border-radius: 9px;
      background: #fff;
      color: #412b20;
      text-decoration: none;
      font: 600 14px Arial, sans-serif;
      cursor: pointer
    }

    .actions .primary {
      background: #6a422b;
      border-color: #6a422b;
      color: #fff
    }

    .paper {
      background: #fff;
      border: 1px solid #e5ddd7;
      border-radius: 16px;
      padding: 28px;
      box-shadow: 0 12px 34px rgba(55, 35, 22, .08)
    }

    .heading {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      padding-bottom: 20px;
      border-bottom: 1px solid #eee8e2
    }

    .eyebrow {
      color: #887568;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase
    }

    h1 {
      font-size: 28px;
      line-height: 1.15;
      margin: 5px 0 6px
    }

    .lead,
    .muted {
      color: #776b63;
      margin: 0
    }

    .badge {
      flex: none;
      border-radius: 999px;
      padding: 7px 11px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase
    }

    .badge.x {
      background: #fff4dd;
      color: #845412
    }

    .badge.z {
      background: #e8f4eb;
      color: #27613b
    }

    .sales-total {
      margin: 20px 0;
      padding: 19px 20px;
      border-radius: 12px;
      background: #f8f4ef
    }

    .label {
      display: block;
      color: #796c63;
      font-size: 12px;
      font-weight: 600
    }

    .sales-total strong {
      display: block;
      font-size: 32px;
      line-height: 1.15;
      margin-top: 5px;
      font-variant-numeric: tabular-nums
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px
    }

    .metric {
      min-width: 0;
      padding: 13px 14px;
      border: 1px solid #eee8e2;
      border-radius: 10px
    }

    .metric strong {
      display: block;
      margin-top: 5px;
      font-size: 17px;
      font-variant-numeric: tabular-nums;
      overflow-wrap: anywhere
    }

    .section-title {
      font-size: 14px;
      margin: 23px 0 10px
    }

    .cash-box {
      padding: 15px;
      border: 1px solid #e6ded7;
      border-radius: 11px
    }

    .cash-row {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      padding: 7px 0;
      border-bottom: 1px solid #f0ebe7
    }

    .cash-row:last-child {
      border-bottom: 0
    }

    .cash-row strong {
      font-variant-numeric: tabular-nums;
      text-align: right
    }

    .variance-positive {
      color: #27613b
    }

    .variance-negative {
      color: #a4312b
    }

    .variance-even {
      color: #27613b
    }

    .explain {
      margin: 14px 0 0;
      padding: 11px 13px;
      border-radius: 9px;
      background: #f5f7f8;
      color: #5d6266;
      font-size: 12px
    }

    .meta {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 18px;
      margin-top: 19px;
      color: #665b54;
      font-size: 12px
    }

    .meta div {
      min-width: 0;
      overflow-wrap: anywhere
    }

    .footer-note {
      padding-top: 16px;
      margin: 18px 0 0;
      border-top: 1px solid #eee8e2;
      color: #81756d;
      font-size: 11px
    }

    @media(max-width:520px) {
      body {
        padding: 16px 10px
      }

      .paper {
        padding: 19px;
        border-radius: 12px
      }

      .heading {
        gap: 10px
      }

      h1 {
        font-size: 24px
      }

      .badge {
        padding: 6px 8px
      }

      .actions a,
      .actions button {
        padding: 9px 11px
      }

      .meta {
        grid-template-columns: 1fr
      }
    }

    body.embedded {
      padding: 0;
      background: #fff
    }

    body.embedded .shell {
      max-width: none
    }

    body.embedded .actions {
      display: none
    }

    body.embedded .paper {
      border: 0;
      border-radius: 0;
      box-shadow: none
    }

    @page {
      size: 80mm auto;
      margin: 3mm 2mm
    }

    @media print {

      html,
      body {
        width: 80mm;
        background: #fff !important;
        font: 9pt/1.4 Arial, sans-serif;
        color: #000;
        padding: 0;
        margin: 0
      }

      .shell {
        width: 100%;
        max-width: none;
        margin: 0
      }

      .actions {
        display: none !important
      }

      .paper {
        width: 100%;
        border: 0;
        border-radius: 0;
        padding: 0;
        box-shadow: none
      }

      .heading {
        padding-bottom: 8px;
        border-color: #999
      }

      h1 {
        font-size: 15pt;
        margin: 3px 0
      }

      .eyebrow,
      .label,
      .muted,
      .lead,
      .footer-note,
      .explain {
        color: #333
      }

      .badge {
        border: 1px solid #444;
        background: #fff !important;
        color: #000 !important;
        padding: 3px 6px;
        font-size: 7pt
      }

      .sales-total {
        margin: 10px 0;
        padding: 8px 0;
        background: #fff;
        border-radius: 0;
        border-bottom: 1px dashed #555
      }

      .sales-total strong {
        font-size: 17pt
      }

      .grid {
        gap: 5px
      }

      .metric {
        padding: 6px 7px;
        border-color: #bbb;
        border-radius: 0
      }

      .metric strong {
        font-size: 10pt
      }

      .section-title {
        margin: 11px 0 5px;
        font-size: 10pt
      }

      .cash-box {
        padding: 6px;
        border-color: #888;
        border-radius: 0
      }

      .cash-row {
        padding: 4px 0;
        border-color: #bbb
      }

      .explain {
        padding: 6px;
        margin-top: 7px;
        background: #fff;
        border: 1px solid #bbb;
        border-radius: 0;
        font-size: 7pt
      }

      .meta {
        margin-top: 10px;
        gap: 4px 8px;
        font-size: 7pt
      }

      .footer-note {
        padding-top: 8px;
        margin-top: 9px;
        font-size: 7pt
      }

      * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact
      }
    }
  </style>
</head>

<body class="<?= $embedded ? 'embedded' : '' ?>">
  <div class="shell">
    <div class="actions">
      <a href="<?= APP_URL ?>/<?= $backPath ?>/cash-drawer.php?day=<?= (int)$reading['drawer_day_id'] ?>">← Back to cash drawer</a>
      <button class="primary" onclick="window.print()">Print / Save PDF</button>
    </div>
    <main class="paper">
      <header class="heading">
        <div>
          <div class="eyebrow"><?= e(APP_NAME) ?> · Register report</div>
          <h1><?= $isClosingReading ? 'Z Reading' : 'X Reading' ?></h1>
          <p class="lead"><?= $isClosingReading ? 'End-of-day closing snapshot' : 'Interim snapshot while the drawer is open' ?></p>
        </div>
        <span class="badge <?= $isClosingReading ? 'z' : 'x' ?>"><?= $isClosingReading ? 'Drawer closed' : 'Drawer open' ?></span>
      </header>

      <section class="sales-total" aria-label="Paid sales total">
        <span class="label">Paid sales</span>
        <strong><?= peso($reading['sales_total']) ?></strong>
      </section>

      <section class="grid" aria-label="Sales breakdown">
        <div class="metric"><span class="label">Transactions</span><strong><?= number_format((int)$reading['transaction_count']) ?></strong></div>
        <div class="metric"><span class="label">Cash sales</span><strong><?= peso($reading['cash_sales']) ?></strong></div>
        <div class="metric"><span class="label">Non-cash sales</span><strong><?= peso($reading['non_cash_sales']) ?></strong></div>
        <div class="metric"><span class="label">Cash added</span><strong><?= peso($reading['cash_in']) ?></strong></div>
        <div class="metric"><span class="label">Cash removed</span><strong><?= peso($reading['cash_out']) ?></strong></div>
      </section>

      <h2 class="section-title">Drawer cash</h2>
      <section class="cash-box" aria-label="Drawer cash reconciliation">
        <div class="cash-row"><span>Expected in drawer</span><strong><?= peso($reading['expected_cash']) ?></strong></div>
        <?php if ($isClosingReading): ?>
          <div class="cash-row"><span>Counted at close</span><strong><?= peso($reading['cash_counted']) ?></strong></div>
          <div class="cash-row">
            <span>Difference</span>
            <strong class="<?= $variance > 0 ? 'variance-positive' : ($variance < 0 ? 'variance-negative' : 'variance-even') ?>"><?= $variance > 0 ? '+' : '' ?><?= peso($variance) ?></strong>
          </div>
          <p class="explain"><?= $variance === 0.0 ? 'The counted cash matches the expected amount.' : ($variance > 0 ? 'The count is above the expected amount.' : 'The count is below the expected amount.') ?> Difference = counted cash minus expected cash.</p>
        <?php else: ?>
          <p class="explain">This is an interim snapshot. The drawer has not been counted and closed yet.</p>
        <?php endif; ?>
      </section>

      <section class="meta" aria-label="Reading details">
        <div><span class="label">Business date</span><?= date('F j, Y', strtotime($reading['business_date'])) ?></div>
        <div><span class="label">Reading number</span>#<?= (int)$reading['id'] ?></div>
        <div><span class="label">Created</span><?= date('M j, Y g:i:s A', strtotime($reading['issued_at'])) ?></div>
        <div><span class="label">Created by</span><?= e($reading['issuer_name']) ?></div>
        <div><span class="label">Activity included through</span><?= date('M j, Y g:i:s A', strtotime($reading['activity_through'])) ?></div>
      </section>
      <p class="footer-note">Saved point-in-time report. Creating a reading does not change or erase sales records.</p>
    </main>
  </div>
</body>

</html>