<?php
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

// ── Filters ───────────────────────────────────────────────────
$filterCashier = (int)($_GET['cashier'] ?? 0);
$filterRating  = (int)($_GET['rating']  ?? 0);
$filterPeriod  = $_GET['period'] ?? 'all';

$periodMap = [
  'today' => "DATE(f.created_at) = CURDATE()",
  'week'  => "YEARWEEK(f.created_at,1) = YEARWEEK(CURDATE(),1)",
  'month' => "YEAR(f.created_at)=YEAR(CURDATE()) AND MONTH(f.created_at)=MONTH(CURDATE())",
  'year'  => "YEAR(f.created_at)=YEAR(CURDATE())",
  'all'   => "1=1",
];
$periodWhere = $periodMap[$filterPeriod] ?? '1=1';

$conditions = ["$periodWhere"];
$params     = [];
if ($filterCashier > 0) {
  $conditions[] = "f.cashier_id = ?";
  $params[] = $filterCashier;
}
if ($filterRating  > 0) {
  $conditions[] = "f.rating = ?";
  $params[] = $filterRating;
}
$whereClause = implode(' AND ', $conditions);

// ── All feedback rows ─────────────────────────────────────────
try {
  $stmt = $db->prepare(
    "SELECT f.id AS feedback_id, f.rating, f.comment, f.created_at,
            COALESCE(s.full_name, fa.full_name) AS customer_name,
            COALESCE(s.student_id_no, fa.faculty_id_no) AS customer_id,
            o.order_number, o.order_type,
            c.full_name AS cashier_name
     FROM order_feedback f
     JOIN orders o ON f.order_id = o.id
     LEFT JOIN students s ON f.student_id = s.id
     LEFT JOIN faculty fa ON f.faculty_id = fa.id
     LEFT JOIN cashiers c ON f.cashier_id = c.id
     WHERE $whereClause
     ORDER BY f.created_at DESC
     LIMIT 200"
  );
  $stmt->execute($params);
  $feedbackRows = $stmt->fetchAll();
} catch (\Throwable $e) {
  $feedbackRows = [];
}


// ── Summary stats ─────────────────────────────────────────────
try {
  $stmt = $db->query(
    "SELECT COUNT(*) AS total,
            ROUND(AVG(rating),2) AS avg_rating,
            SUM(rating=5) AS five_star,
            SUM(rating=4) AS four_star,
            SUM(rating=3) AS three_star,
            SUM(rating=2) AS two_star,
            SUM(rating=1) AS one_star
     FROM order_feedback"
  );
  $summary = $stmt->fetch();
} catch (\Throwable $e) {
  $summary = ['total' => 0, 'avg_rating' => 0, 'five_star' => 0, 'four_star' => 0, 'three_star' => 0, 'two_star' => 0, 'one_star' => 0];
}

// ── Per-cashier rating breakdown ──────────────────────────────
try {
  $stmt = $db->query(
    "SELECT c.id, c.full_name,
            COUNT(f.id)      AS total_reviews,
            ROUND(AVG(f.rating),2) AS avg_rating,
            SUM(f.rating=5)  AS five_star,
            SUM(f.rating=4)  AS four_star,
            SUM(f.rating=3)  AS three_star,
            SUM(f.rating<=2) AS low_star
     FROM cashiers c
     JOIN order_feedback f ON f.cashier_id = c.id
     GROUP BY c.id, c.full_name
     ORDER BY avg_rating DESC, total_reviews DESC"
  );
  $cashierRatings = $stmt->fetchAll();
} catch (\Throwable $e) {
  $cashierRatings = [];
}

// ── Cashier list for filter ───────────────────────────────────
$allCashiers = $db->query("SELECT id, full_name FROM cashiers WHERE is_active=1 ORDER BY full_name")->fetchAll();

// ── Product ratings summary ───────────────────────────────────
try {
  $topProducts = $db->query(
    "SELECT p.id, p.name, p.image_path,
            c.name AS category_name,
            COALESCE(pr.total_ratings, 0) AS total_ratings,
            pr.avg_rating,
            COALESCE(od.total_sold, 0) AS total_sold,
            COALESCE(pr.five_star, 0) AS five_star,
            COALESCE(pr.four_star, 0) AS four_star,
            COALESCE(pr.three_star, 0) AS three_star,
            COALESCE(pr.two_star, 0) AS two_star,
            COALESCE(pr.one_star, 0) AS one_star
     FROM products p
     JOIN categories c ON p.category_id = c.id
     LEFT JOIN (
       SELECT product_id,
              COUNT(id) AS total_ratings,
              ROUND(AVG(rating), 2) AS avg_rating,
              SUM(rating=5) AS five_star,
              SUM(rating=4) AS four_star,
              SUM(rating=3) AS three_star,
              SUM(rating=2) AS two_star,
              SUM(rating=1) AS one_star
       FROM product_ratings
       GROUP BY product_id
     ) pr ON pr.product_id = p.id
     LEFT JOIN (
       SELECT product_id, SUM(quantity) AS total_sold
       FROM order_details
       GROUP BY product_id
     ) od ON od.product_id = p.id
     WHERE pr.total_ratings > 0
     ORDER BY pr.avg_rating DESC, pr.total_ratings DESC
     LIMIT 20"
  )->fetchAll();
} catch (\Throwable $e) {
  $topProducts = [];
}

layoutHeader('Feedback');
?>
<style>
  .star-display {
    letter-spacing: 1px;
  }

  .star-filled {
    color: var(--accent-color);
  }

  .star-empty {
    color: var(--border-strong);
  }

  .rating-bar-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-bottom: 5px;
  }

  .rating-bar-label {
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--text-muted);
    width: 14px;
    text-align: right;
    flex-shrink: 0;
  }

  .rating-bar-track {
    flex: 1;
    height: 7px;
    background: var(--surface-sunken);
    border-radius: 4px;
    overflow: hidden;
  }

  .rating-bar-fill {
    height: 100%;
    background: var(--accent-color);
    border-radius: 4px;
    transition: width 0.4s ease;
  }

  .rating-bar-count {
    font-size: 0.70rem;
    color: var(--text-muted);
    width: 28px;
    text-align: right;
    flex-shrink: 0;
  }

  .avg-big {
    font-size: 2.8rem;
    font-weight: 800;
    color: var(--text-color);
    line-height: 1;
  }

  .avg-star {
    font-size: 1.10rem;
    color: var(--accent-color);
  }

  .cashier-rating-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: 10px var(--space-4);
    border-bottom: 1px solid var(--border-color);
  }

  .cashier-rating-row:last-child {
    border-bottom: none;
  }

  .cr-name {
    font-size: 0.84rem;
    font-weight: 700;
    color: var(--text-color);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .cr-stars {
    font-size: 0.82rem;
    color: var(--accent-color);
    flex-shrink: 0;
  }

  .cr-avg {
    font-size: 0.92rem;
    font-weight: 800;
    color: var(--text-color);
    flex-shrink: 0;
    width: 36px;
    text-align: right;
  }

  .cr-count {
    font-size: 0.70rem;
    color: var(--text-muted);
    flex-shrink: 0;
    width: 52px;
    text-align: right;
  }

  .feedback-comment {
    font-size: 0.80rem;
    color: var(--text-secondary);
    font-style: italic;
    margin-top: 2px;
    line-height: 1.4;
  }
</style>

<div class="page-header">
  <div>
    <div class="page-header-title">Customer Feedback</div>
    <div class="page-header-sub">
      <?= (int)($summary['total'] ?? 0) ?> review<?= ($summary['total'] ?? 0) != 1 ? 's' : '' ?> total
      <?php if (($summary['avg_rating'] ?? 0) > 0): ?>
        · <?= number_format($summary['avg_rating'], 1) ?> avg rating
      <?php endif; ?>
    </div>
  </div>
</div>
<?php showFlash('global'); ?>

<?php if (empty($feedbackRows) && empty($cashierRatings)): ?>
  <div class="card">
    <div class="empty-state" style="padding:var(--space-10)">
      <i class="fa-solid fa-star"></i>
      <h3>No feedback yet</h3>
      <p>Once students rate their claimed orders, reviews will appear here.<br>
        Make sure you have run <code style="background:var(--surface-sunken);padding:1px 6px;border-radius:3px">feedback_migration.sql</code> first.</p>
    </div>
  </div>
<?php else: ?>

  <!-- Row 1: Overall stats | Per-cashier ratings -->
  <div style="display:grid;grid-template-columns:280px 1fr;gap:var(--space-5);margin-bottom:var(--space-5)">

    <!-- Overall summary -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-chart-simple"></i> Overall Rating</div>
      </div>
      <div class="card-body" style="text-align:center;padding-bottom:var(--space-4)">
        <div class="avg-big"><?= number_format((float)($summary['avg_rating'] ?? 0), 1) ?></div>
        <div class="avg-star" style="margin:6px 0 2px">
          <?php $avg = (float)($summary['avg_rating'] ?? 0);
          for ($s = 1; $s <= 5; $s++) echo $s <= $avg ? '★' : '☆'; ?>
        </div>
        <div style="font-size:0.74rem;color:var(--text-muted);margin-bottom:var(--space-4)">
          out of 5 · <?= (int)($summary['total'] ?? 0) ?> review<?= ($summary['total'] ?? 0) != 1 ? 's' : '' ?>
        </div>
        <?php
        $total = max(1, (int)($summary['total'] ?? 0));
        foreach ([5, 4, 3, 2, 1] as $star):
          $key  = ['five_star', 'four_star', 'three_star', 'two_star', 'one_star'][$star - 1] ?? ($star . '_star');
          $keyMap = [5 => 'five_star', 4 => 'four_star', 3 => 'three_star', 2 => 'two_star', 1 => 'one_star'];
          $count = (int)($summary[$keyMap[$star]] ?? 0);
          $pct   = round($count / $total * 100);
        ?>
          <div class="rating-bar-row">
            <div class="rating-bar-label"><?= $star ?></div>
            <div class="rating-bar-track">
              <div class="rating-bar-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="rating-bar-count"><?= $count ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Per-cashier ratings -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-user-check"></i> Ratings by Cashier</div>
      </div>
      <?php if (empty($cashierRatings)): ?>
        <div style="padding:var(--space-6);text-align:center;color:var(--text-muted);font-size:0.84rem">
          No feedback linked to cashiers yet
        </div>
      <?php else: ?>
        <?php foreach ($cashierRatings as $cr):
          $avgR = (float)$cr['avg_rating'];
          $filledStars = round($avgR);
        ?>
          <div class="cashier-rating-row">
            <div class="cr-name"><?= e($cr['full_name']) ?></div>
            <div class="cr-stars">
              <?php for ($s = 1; $s <= 5; $s++): ?>
                <span style="color:<?= $s <= $filledStars ? 'var(--accent-color)' : 'var(--border-strong)' ?>">★</span>
              <?php endfor; ?>
            </div>
            <div class="cr-avg"><?= number_format($avgR, 1) ?></div>
            <div class="cr-count"><?= $cr['total_reviews'] ?> review<?= $cr['total_reviews'] != 1 ? 's' : '' ?></div>
            <div style="flex-shrink:0">
              <a href="?cashier=<?= $cr['id'] ?>" class="btn btn-ghost btn-sm">Filter</a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

  <!-- Row 2: Feedback list with filters -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-comments"></i> All Reviews</div>
      <!-- Filters -->
      <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;align-items:center">
        <!-- Period -->
        <select class="form-control filter-select"
          onchange="applyFilter('period',this.value)">
          <?php foreach (['all' => 'All Time', 'today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $filterPeriod === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <!-- Rating -->
        <select class="form-control filter-select"
          onchange="applyFilter('rating',this.value)">
          <option value="0">All Ratings</option>
          <?php for ($r = 5; $r >= 1; $r--): ?>
            <option value="<?= $r ?>" <?= $filterRating === $r ? 'selected' : '' ?>><?= $r ?> Star<?= $r != 1 ? 's' : '' ?></option>
          <?php endfor; ?>
        </select>
        <!-- Cashier -->
        <select class="form-control filter-select"
          onchange="applyFilter('cashier',this.value)">
          <option value="0">All Cashiers</option>
          <?php foreach ($allCashiers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterCashier === $c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($filterCashier || $filterRating || $filterPeriod !== 'all'): ?>
          <a href="?" class="btn btn-ghost btn-sm">
            <i class="fa-solid fa-xmark"></i> Clear
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($feedbackRows)): ?>
      <div style="padding:var(--space-6);text-align:center;color:var(--text-muted);font-size:0.84rem">
        No reviews match the selected filters
      </div>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table class="data-table">
          <thead>
            <tr>
              <th>Order</th>
              <th>Student</th>
              <th>Cashier</th>
              <th>Rating</th>
              <th>Comment</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($feedbackRows as $fb): ?>
              <tr>
                <td>
                  <strong><?= e($fb['order_number']) ?></strong><br>
                  <span class="badge badge-<?= $fb['order_type'] === 'walk-in' ? 'walkin' : 'preorder' ?>"><?= e($fb['order_type']) ?></span>
                </td>
                <td>
                  <?= e($fb['customer_name'] ?? '—') ?><br>
                    <small style="color:var(--text-muted)">
                  <?= e($fb['customer_id'] ?? '') ?>
                    </small>
                </td>

                <td><?= $fb['cashier_name'] ? e($fb['cashier_name']) : '<span class="text-muted">—</span>' ?></td>
                <td>
                  <div class="star-display">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                      <span class="<?= $s <= $fb['rating'] ? 'star-filled' : 'star-empty' ?>">★</span>
                    <?php endfor; ?>
                  </div>
                </td>
                <td style="max-width:260px">
                  <?php if ($fb['comment']): ?>
                    <div class="feedback-comment">"<?= e($fb['comment']) ?>"</div>
                  <?php else: ?>
                    <span class="text-muted" style="font-size:0.78rem">No comment</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted"><?= date('M j, Y g:i A', strtotime($fb['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<script>
  const url = new URL(window.location.href);

  function applyFilter(key, val) {
    url.searchParams.set(key, val);
    window.location.href = url.toString();
  }
</script>

<!-- ── Product Ratings / Best Sellers ─────────────────────── -->
<div class="page-header" style="margin-top:var(--space-6)">
  <div>
    <div class="page-header-title">Best Sellers by Rating</div>
    <div class="page-header-sub">Products ranked by average customer rating</div>
  </div>
</div>

<?php if (empty($topProducts)): ?>
  <div class="card">
    <div class="empty-state">
      <i class="fa-solid fa-star"></i>
      <h3>No product ratings yet</h3>
      <p>Ratings appear after students rate their claimed orders.<br>
        Make sure you have run <code style="background:var(--surface-sunken);padding:1px 6px;border-radius:3px">product_ratings_migration.sql</code> first.</p>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Product</th>
            <th>Category</th>
            <th class="num">Avg Rating</th>
            <th class="num">Ratings</th>
            <th class="num">Times Sold</th>
            <th>Distribution</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topProducts as $i => $pr): ?>
            <tr>
              <td style="color:var(--text-muted);font-size:0.80rem;font-weight:700"><?= $i + 1 ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:var(--space-3)">
                  <?php if (!empty($pr['image_path']) && file_exists(UPLOAD_DIR . $pr['image_path'])): ?>
                    <img src="<?= APP_URL ?>/../uploads/products/<?= e($pr['image_path']) ?>"
                      style="width:36px;height:36px;object-fit:cover;border-radius:var(--radius-xs);border:1px solid var(--border-color);flex-shrink:0"
                      alt="<?= e($pr['name']) ?>">
                  <?php else: ?>
                    <div style="width:36px;height:36px;background:var(--surface-sunken);border-radius:var(--radius-xs);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                      <i class="fa-solid fa-mug-hot" style="color:var(--border-strong);font-size:13px"></i>
                    </div>
                  <?php endif; ?>
                  <strong><?= e($pr['name']) ?></strong>
                </div>
              </td>
              <td class="text-muted"><?= e($pr['category_name']) ?></td>
              <td class="num">
                <div style="display:inline-flex;align-items:center;gap:4px;background:var(--accent-subtle);border:1px solid rgba(240,180,41,0.25);border-radius:var(--radius-full);padding:3px 10px">
                  <span style="color:var(--accent-color);font-weight:800">★</span>
                  <span style="font-weight:800;color:var(--text-color)"><?= number_format($pr['avg_rating'], 1) ?></span>
                </div>
              </td>
              <td class="num"><?= $pr['total_ratings'] ?></td>
              <td class="num">
                <?php if ($pr['total_sold'] > 0): ?>
                  <span style="background:var(--primary-subtle);color:var(--primary-color);font-weight:700;font-size:0.78rem;padding:2px 8px;border-radius:var(--radius-full);border:1px solid rgba(192,57,43,0.15)">
                    <?= number_format($pr['total_sold']) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">0</span>
                <?php endif; ?>
              </td>
              <td style="min-width:160px">
                <?php
                $bars = [5 => $pr['five_star'], 4 => $pr['four_star'], 3 => $pr['three_star'], 2 => $pr['two_star'], 1 => $pr['one_star']];
                foreach ($bars as $stars => $count):
                  $pct = $pr['total_ratings'] > 0 ? round((int)$count / $pr['total_ratings'] * 100) : 0;
                ?>
                  <div style="display:flex;align-items:center;gap:5px;margin-bottom:2px">
                    <span style="font-size:0.62rem;font-weight:700;color:var(--text-muted);width:8px;text-align:right"><?= $stars ?></span>
                    <div style="flex:1;height:6px;background:var(--surface-sunken);border-radius:3px;overflow:hidden">
                      <div style="width:<?= $pct ?>%;height:100%;background:var(--accent-color);border-radius:3px;transition:width 0.4s ease"></div>
                    </div>
                    <span style="font-size:0.62rem;color:var(--text-muted);width:22px;text-align:right"><?= (int)$count ?></span>
                  </div>
                <?php endforeach; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php layoutFooter(); ?>
