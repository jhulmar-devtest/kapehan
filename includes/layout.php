<?php
// ============================================================
// includes/layout.php
//
// Defines layoutHeader() and layoutFooter().
// Loaded by config/init.php — do NOT include this file directly.
//
// Usage in every protected page:
//
//   layoutHeader('Page Title');
//   ... your HTML ...
//   layoutFooter();
//
// Optional extra CSS/JS per page:
//   layoutHeader('Dashboard', '<script src="chart.js"></script>');
// ============================================================

// -----------------------------------------------------------------
// layoutHeader() — outputs <html>, <head>, sidebar, topbar, <main>
// -----------------------------------------------------------------
function layoutHeader(string $pageTitle = '', string $extraHead = ''): void {
  $role     = currentRole();
  $userName = e($_SESSION['full_name'] ?? 'User');
  $appUrl   = APP_URL;

  // Build the <head> block
  echo '<!DOCTYPE html>' . "\n";
  echo '<html lang="en">' . "\n";
  echo '<head>' . "\n";
  echo '  <meta charset="UTF-8">' . "\n";
  echo '  <meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
  echo '  <meta name="csrf-token" content="' . csrfToken() . '">' . "\n";
  echo '  <title>' . e($pageTitle) . ' — ' . APP_NAME . '</title>' . "\n";
  echo '  <link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
  echo '  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&display=swap" rel="stylesheet">' . "\n";
  echo '  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">' . "\n";
  echo '  <link rel="stylesheet" href="' . $appUrl . '/../assets/css/variables.css">' . "\n";
  echo '  <link rel="stylesheet" href="' . $appUrl . '/../assets/css/layout.css">' . "\n";
  echo '  <link rel="stylesheet" href="' . $appUrl . '/../assets/css/components.css">' . "\n";
  if ($extraHead) echo '  ' . $extraHead . "\n";
  echo '</head>' . "\n";
  echo '<body>' . "\n";

  // ---- Sidebar role icon ----
  if ($role === ROLE_ADMIN) {
    $roleIcon = 'fa-user-shield';
  } elseif ($role === ROLE_CASHIER) {
    $roleIcon = 'fa-cash-register';
  } elseif ($role === ROLE_FACULTY) {
    $roleIcon = 'fa-chalkboard-teacher';
  } else {
    $roleIcon = 'fa-graduation-cap';
  }

  // ---- Build sidebar nav HTML ----
  $nav = '';

  if ($role === ROLE_ADMIN) {
    $nav .= '<div class="nav-section-label">Kapehan ni Amang</div>';
    $nav .= navItem('fa-chart-line',   'Sales',      $appUrl . '/admin/sales.php',     $pageTitle);
    $nav .= navItem('fa-mug-hot',      'Ordering',   $appUrl . '/admin/ordering.php',  $pageTitle);
    $nav .= navItem('fa-boxes-stacked', 'Inventory',  $appUrl . '/admin/inventory.php', $pageTitle);
    $nav .= navItem('fa-gear',         'Settings',   $appUrl . '/admin/settings.php',  $pageTitle);
    $nav .= '<div class="nav-section-label">System</div>';
  } elseif ($role === ROLE_CASHIER) {
    $preorderCount   = pendingPreorderCount();
    $preorderUrgency = preorderUrgency();
    $nav .= '<div class="nav-section-label">Operations</div>';
    $nav .= navItem('fa-chart-simple', 'Dashboard',   $appUrl . '/cashier/dashboard.php',  $pageTitle);
    $nav .= navItem('fa-store',        'Walk-in POS', $appUrl . '/cashier/walkin.php',      $pageTitle);
    $nav .= navItem('fa-clock',        'Pre-orders',  $appUrl . '/cashier/preorders.php',   $pageTitle, $preorderCount, $preorderUrgency);
    $nav .= navItem('fa-clock-rotate-left', 'Order History', $appUrl . '/cashier/orders.php',    $pageTitle);
    $nav .= navItem('fa-gear',  'Settings',  $appUrl . '/settings.php',    $pageTitle);
    $nav .= '<div class="nav-section-label">Session</div>';
  } elseif ($role === ROLE_STUDENT) {
    $nav .= '<div class="nav-section-label">My Account</div>';
    $nav .= navItem('fa-utensils',           'Order Now',  $appUrl . '/menu.php',              $pageTitle);
    $nav .= navItem('fa-clock-rotate-left',  'My Orders',  $appUrl . '/orders.php',    $pageTitle);
    $nav .= navItem('fa-gear',  'Settings',  $appUrl . '/settings.php',    $pageTitle);
    $nav .= '<div class="nav-section-label">Account</div>';
  } elseif ($role === ROLE_FACULTY) {
    $nav .= '<div class="nav-section-label">My Account</div>';
    $nav .= navItem('fa-utensils', 'Order Now', $appUrl . '/menu.php', $pageTitle);
    $nav .= navItem('fa-clock-rotate-left', 'My Orders', $appUrl . '/orders.php', $pageTitle);
    $nav .= navItem('fa-gear', 'Settings', $appUrl . '/settings.php', $pageTitle);
    $nav .= '<div class="nav-section-label">Account</div>';
  }

  // Logout always last
  $nav .= '<a href="' . $appUrl . '/logout.php" class="nav-item logout">'
    . '<i class="fa-solid fa-right-from-bracket"></i>'
    . '<span>Logout</span>'
    . '</a>';

  // ---- Output full sidebar ----
  echo '
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>

<aside class="sidebar" id="sidebar">

  <div class="sidebar-brand">
    <div class="sidebar-brand-icon">
      <img src="' . $appUrl . '/../assets/images/logo.png" alt="' . APP_NAME . '"
           onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">
      <i class="fa-solid fa-mug-hot" style="display:none"></i>
    </div>
    <div class="sidebar-brand-text">
      <div class="sidebar-brand-name">EARIST Coffee</div>
      <div class="sidebar-brand-tag">POS System</div>
    </div>
  </div>

  <div class="sidebar-user">
    <div class="sidebar-user-avatar"><i class="fa-solid ' . $roleIcon . '"></i></div>
    <div class="sidebar-user-info">
      <div class="sidebar-user-name">' . $userName . '</div>
      <div class="sidebar-user-role">' . e(ucfirst($role ?? '')) . '</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    ' . $nav . '
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-toggle" onclick="toggleSidebar()">
      <i class="fa-solid fa-angles-left toggle-arrow"></i>
      <span class="toggle-label">Collapse</span>
    </div>
  </div>

</aside>

<div class="main-wrapper" id="main-wrapper">

  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-hamburger" onclick="openMobileSidebar()" aria-label="Open menu">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div class="topbar-title">' . e($pageTitle) . '</div>
    </div>
    <div class="topbar-actions">
      <a href="' . $appUrl . '/logout.php" class="topbar-user" title="Logout">
        <span class="topbar-user-dot"><i class="fa-solid ' . $roleIcon . '"></i></span>
        <span class="topbar-user-name">' . $userName . '</span>
        <i class="fa-solid fa-right-from-bracket" style="font-size:11px;opacity:0.5"></i>
      </a>
    </div>
  </header>
';

  // Flash messages
  if (!empty($_SESSION['flash'])) {
    echo '<div style="padding:12px 28px 0">';
    foreach (array_keys($_SESSION['flash']) as $k) {
      showFlash($k);
    }
    echo '</div>';
  }

  echo '<main class="page-content">' . "\n";
}


// -----------------------------------------------------------------
// layoutFooter() — closes <main>, loads JS, closes </body></html>
// -----------------------------------------------------------------
function layoutFooter(string $extraScripts = ''): void {
  $appUrl = APP_URL;

  echo "\n" . '  </main>' . "\n";
  echo '</div><!-- /main-wrapper -->' . "\n\n";
  echo '<script src="' . $appUrl . '/../assets/js/sidebar.js"></script>' . "\n";
  echo '<script src="' . $appUrl . '/../assets/js/utils.js"></script>' . "\n";

  // Auto-dismiss any server-rendered flash toast (showFlash()) after a
  // few seconds — it only ever had a manual close button before, so it
  // would otherwise sit on screen indefinitely.
  echo '<script>
document.querySelectorAll(".toast").forEach(function(t) {
  setTimeout(function() {
    t.classList.add("toast-exit");
    setTimeout(function() { t.remove(); }, 300);
  }, 5000);
});
</script>' . "\n";

  // Cashier: silently poll pre-order badge every 30s (no page refresh)
  if (currentRole() === ROLE_CASHIER) {
    echo '
<script>
(function() {
  function updatePreorderBadge() {
    fetch("' . $appUrl . '/api/preorder-badge.php", {
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
    .then(r => r.json())
    .then(data => {
      const links = document.querySelectorAll(".nav-item");
      links.forEach(link => {
        if (!link.href.includes("preorders.php")) return;
        let badge = link.querySelector(".nav-badge");
        if (data.count > 0) {
          if (!badge) {
            badge = document.createElement("span");
            link.appendChild(badge);
          }
          badge.textContent = data.count;
          badge.className = "nav-badge" +
            (data.urgency === 3 ? " nav-badge--danger" :
             data.urgency === 2 ? " nav-badge--warning" : "");
        } else {
          if (badge) badge.remove();
        }
      });
    })
    .catch(() => {}); // fail silently — cashier still works normally
  }

  // Run immediately then every 30s
  updatePreorderBadge();
  setInterval(updatePreorderBadge, 30000);
})();
</script>' . "\n";
  }

  if ($extraScripts) {
    echo $extraScripts . "\n";
  }
  echo '</body>' . "\n";
  echo '</html>' . "\n";
}


// -----------------------------------------------------------------
// navItem() — builds a single <a> sidebar link
// -----------------------------------------------------------------
function navItem(
  string $icon,
  string $label,
  string $href,
  string $currentPage = '',
  int    $badge       = 0,
  int    $urgency     = 1  // 1=normal, 2=warning, 3=danger
): string {
  $active    = ($currentPage === $label) ? ' active' : '';

  $badgeHtml = '';
  if ($badge > 0) {
    $urgencyClass = match ($urgency) {
      3       => 'nav-badge nav-badge--danger',
      2       => 'nav-badge nav-badge--warning',
      default => 'nav-badge',
    };
    $badgeHtml = '<span class="' . $urgencyClass . '">' . $badge . '</span>';
  }

  return '<a href="' . $href . '" class="nav-item' . $active . '">'
    . '<i class="fa-solid ' . $icon . '"></i>'
    . '<span>' . e($label) . '</span>'
    . $badgeHtml
    . '</a>' . "\n";
}


// -----------------------------------------------------------------
// Badge count helpers used by the sidebar
// -----------------------------------------------------------------

function pendingRefundCount(): int {
  try {
    $db   = Database::getInstance();
    $stmt = $db->query("SELECT COUNT(*) FROM refund_requests WHERE status = 'pending'");
    return (int) $stmt->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

function pendingPreorderCount(): int {
  try {
    $db   = Database::getInstance();
    $stmt = $db->prepare(
      "SELECT COUNT(*) FROM orders
             WHERE order_type = 'pre-order'
               AND status IN ('pending', 'preparing', 'ready')"
    );
    $stmt->execute();
    return (int) $stmt->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

/**
 * Returns urgency level for the pre-order sidebar badge:
 *   0 = no active pre-orders
 *   1 = has orders but none urgent (green badge)
 *   2 = at least one order pickup time is within 30 mins (yellow)
 *   3 = at least one order is overdue / ASAP and still pending (red)
 */
function preorderUrgency(): int {
  try {
    date_default_timezone_set('Asia/Manila');
    $db   = Database::getInstance();
    $stmt = $db->prepare(
      "SELECT pickup_time, status FROM orders
        WHERE order_type = 'pre-order'
          AND status IN ('pending','preparing','ready')"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) return 0;

    $now      = time();
    $urgency  = 1; // has orders, not urgent yet

    foreach ($rows as $row) {
      $pt = $row['pickup_time'] ?? '';

      // ASAP orders that are still pending = red
      if ($pt === 'ASAP' && $row['status'] === 'pending') {
        return 3;
      }

      if ($pt && $pt !== 'ASAP') {
        $pickupTs = strtotime($pt);
        $diff     = $pickupTs - $now;

        if ($diff <= 0) {
          // Overdue — red
          return 3;
        } elseif ($diff <= 30 * 60) {
          // Within 30 mins — yellow (keep checking for red)
          $urgency = max($urgency, 2);
        }
      }
    }

    return $urgency;
  } catch (Throwable $e) {
    return 0;
  }
}

function studentCartCount(): int {
  // Student cart lives in sessionStorage (JS), not PHP session.
  // This returns 0 — the JS side updates the badge count dynamically.
  return 0;
}
