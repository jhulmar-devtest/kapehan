<?php
// ============================================================
// includes/functions.php
//
// WHAT THIS FILE DOES:
//   A collection of small, reusable helper functions used across
//   the entire application. Think of these as your toolbox.
// ============================================================

// ---- OUTPUT SAFETY ----

/**
 * e($value)
 *
 * Safely outputs a value in HTML by escaping special characters.
 * ALWAYS use this when displaying user-supplied data on screen.
 * This prevents XSS (Cross-Site Scripting) attacks.
 *
 * Example:
 *   echo e($user['full_name']);   // Safe: shows text as-is
 *   echo $user['full_name'];      // UNSAFE: could inject HTML/JS
 */
function e(mixed $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ---- MONEY FORMATTING ----

/**
 * peso($amount)
 *
 * Formats a number as Philippine Peso currency.
 * Example: peso(1234.5) → "₱1,234.50"
 */
function peso(float|int|string $amount): string {
  return '₱' . number_format((float)$amount, 2);
}

// ---- ORDER NUMBER GENERATION ----

/**
 * generateOrderNumber()
 *
 * Creates a human-readable order number like: ORD-20240602-0047
 * Format: ORD-{YYYYMMDD}-{4-digit sequential number for today}
 *
 * Uses the database to count today's orders so the number is always
 * accurate even if the app restarts.
 */
function generateOrderNumber(): string {
  $db   = Database::getInstance();
  $stmt = $db->prepare(
    "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()"
  );
  $stmt->execute();
  $count = (int)$stmt->fetchColumn() + 1;

  return sprintf('ORD-%s-%04d', date('Ymd'), $count);
}

// ---- INPUT VALIDATION ----

/**
 * sanitizeString($input, $maxLength)
 *
 * Trims whitespace, strips HTML tags, and enforces a max length.
 * Use on text fields like names, descriptions, etc.
 */
function sanitizeString(string $input, int $maxLength = 255): string {
  $clean = strip_tags(trim($input));
  return mb_substr($clean, 0, $maxLength);
}

/**
 * validatePassword($password)
 *
 * Returns an error message string if the password is too weak,
 * or null if it's acceptable.
 * Rules: at least 8 characters.
 * (Add more rules if needed: uppercase, numbers, symbols)
 */
function validatePassword(string $password): ?string {
  if (strlen($password) < 8) {
    return 'Password must be at least 8 characters long.';
  }
  return null; // null means "no error" = password is valid
}

// ---- AJAX / API HELPERS ----

/**
 * jsonResponse($data, $statusCode)
 *
 * Sends a JSON response and stops execution.
 * Used in all api/ endpoint files.
 *
 * Example:
 *   jsonResponse(['success' => true, 'message' => 'Order created']);
 *   jsonResponse(['success' => false, 'message' => 'Not found'], 404);
 */
function jsonResponse(array $data, int $statusCode = 200): never {
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

/**
 * isAjax()
 *
 * Returns true if the request was made via AJAX (fetch/XMLHttpRequest).
 */
function isAjax(): bool {
  return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ---- REDIRECT ----

/**
 * redirect($url)
 *
 * Redirects to a URL and stops execution.
 */
function redirect(string $url): never {
  header('Location: ' . $url);
  exit;
}

/**
 * redirectBack($fallback)
 *
 * Redirects to the page the user came from (using Referer header).
 * If no Referer is available, redirects to $fallback.
 */
function redirectBack(string $fallback = ''): never {
  $fallback = $fallback ?: APP_URL . '/login.php';
  $back = $_SERVER['HTTP_REFERER'] ?? $fallback;
  redirect($back);
}

// ---- FLASH MESSAGES ----
// Flash messages are one-time messages shown after a redirect.
// Example: after saving a product → flash "Product saved!" → redirect → show message → clear it.

/**
 * flash($key, $message)
 *
 * Stores a message in the session to be shown once.
 * $type can be: 'success', 'error', 'warning', 'info'
 */
function flash(string $key, string $message, string $type = 'success'): void {
  $_SESSION['flash'][$key] = ['message' => $message, 'type' => $type];
}

/**
 * getFlash($key)
 *
 * Retrieves and DELETES a flash message.
 * Returns null if no message with that key exists.
 */
function getFlash(string $key): ?array {
  if (isset($_SESSION['flash'][$key])) {
    $msg = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $msg;
  }
  return null;
}

/**
 * flashOldInput($data)
 *
 * Stashes submitted form fields in the session so a redirect-after-
 * failed-validation can redisplay the form pre-filled instead of
 * silently discarding everything the user typed. One-time use, same
 * pattern as flash()/getFlash() above. Never pass $_FILES here — only
 * text fields (the uploaded file itself can't survive a redirect).
 */
function flashOldInput(array $data): void {
  unset($data['csrf_token']); // no reason to round-trip this
  $_SESSION['old_input'] = $data;
}

/**
 * getOldInput()
 *
 * Retrieves and DELETES the stashed form fields from flashOldInput().
 * Returns null if there isn't any (the normal case — most page loads
 * aren't redisplaying a failed submission).
 */
function getOldInput(): ?array {
  if (isset($_SESSION['old_input'])) {
    $data = $_SESSION['old_input'];
    unset($_SESSION['old_input']);
    return $data;
  }
  return null;
}

/**
 * showFlash($key)
 *
 * Outputs the flash message as an HTML toast notification.
 * Call this in pages where you want to display feedback.
 */
function showFlash(string $key): void {
  $flash = getFlash($key);
  if (!$flash) return;

  $type = e($flash['type']);
  $msg  = e($flash['message']);

  $icons = [
    'success' => 'fa-circle-check',
    'error'   => 'fa-circle-xmark',
    'warning' => 'fa-triangle-exclamation',
    'info'    => 'fa-circle-info',
  ];
  $icon = $icons[$type] ?? 'fa-circle-info';

  echo "<div class=\"toast toast-{$type}\" role=\"alert\">
          <i class=\"fa-solid {$icon}\"></i>
          <span>{$msg}</span>
          <button class=\"toast-close\" onclick=\"this.parentElement.remove()\">
            <i class=\"fa-solid fa-xmark\"></i>
          </button>
        </div>";
}

/**
 * showFlashAsToast($key)
 *
 * Same as showFlash(), but for pages that already have the floating
 * toast system from assets/js/cart-drawer.js loaded (menu.php,
 * orders.php, account.php) — feeds the flash message into that
 * existing showToast() JS function instead of echoing a static HTML
 * block at whatever point in the page this is called. That block was
 * a normal in-flow element with no positioning of its own, so it
 * pushed the rest of the page's content down every time it appeared;
 * routing it through the JS toast stack means it floats over the page
 * instead, and auto-dismisses using the same logic every other toast
 * on these pages already uses.
 */
function showFlashAsToast(string $key): void {
  $flash = getFlash($key);
  if (!$flash) return;

  $type = json_encode($flash['type']);
  $msg  = json_encode($flash['message']);

  echo "<script>document.addEventListener('DOMContentLoaded', function() {
    if (typeof showToast === 'function') showToast({$type}, {$msg});
  });</script>";
}

// ---- LOGIN ATTEMPT THROTTLE ----

/**
 * checkLoginAttempts($identifier)
 *
 * Blocks login after 5 failed attempts for 15 minutes.
 * $identifier is usually the username or student ID being attempted.
 */
/**
 * checkLoginAttempts($identifier)
 *
 * Returns true if this identifier (e.g. a username) is allowed to
 * attempt a login/verification right now, false if it's locked out.
 *
 * BUG FIX: this used to store the counter and lockout in $_SESSION,
 * which meant the throttle only ever applied to one browser session —
 * clearing cookies or using a private window reset it completely, for
 * anyone, including against the admin-password check in
 * api/add-cashier.php. Now backed by the login_attempts table (see
 * database/migrations/add_login_attempts_table.sql) so the lock holds
 * regardless of which session or browser is doing the attempting.
 */
function checkLoginAttempts(string $identifier): bool {
  $hash = md5($identifier);
  $db = Database::getInstance();
  $stmt = $db->prepare("SELECT locked_until FROM login_attempts WHERE identifier_hash = ?");
  $stmt->execute([$hash]);
  $lockedUntil = $stmt->fetchColumn();

  if ($lockedUntil) {
    if (strtotime($lockedUntil) > time()) {
      return false; // still locked
    }
    // Lock expired — reset
    $db->prepare("DELETE FROM login_attempts WHERE identifier_hash = ?")->execute([$hash]);
  }
  return true; // allowed
}

/**
 * recordFailedLogin($identifier)
 *
 * Increments the failed attempt counter. Locks after 5 attempts.
 */
function recordFailedLogin(string $identifier): void {
  $hash = md5($identifier);
  $db = Database::getInstance();

  $stmt = $db->prepare("SELECT attempts FROM login_attempts WHERE identifier_hash = ?");
  $stmt->execute([$hash]);
  $attempts = (int) ($stmt->fetchColumn() ?: 0) + 1;
  $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null; // 900s = 15 min

  $db->prepare(
    "INSERT INTO login_attempts (identifier_hash, attempts, locked_until)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until)"
  )->execute([$hash, $attempts, $lockedUntil]);
}

/**
 * clearLoginAttempts($identifier)
 *
 * Called after a successful login to reset the counter.
 */
function clearLoginAttempts(string $identifier): void {
  $db = Database::getInstance();
  $db->prepare("DELETE FROM login_attempts WHERE identifier_hash = ?")->execute([md5($identifier)]);
}

// ---- AUDIT LOG ----

/**
 * auditLog($actorType, $actorId, $action, $target, $targetId)
 *
 * Writes a record to the audit_log table.
 * Call this whenever something important happens:
 * login, order created, product deleted, refund approved, etc.
 */
function auditLog(
  string $actorType,
  int    $actorId,
  string $action,
  string $target   = '',
  ?int   $targetId = null
): void {
  try {
    $db   = Database::getInstance();
    $stmt = $db->prepare(
      "INSERT INTO audit_log (actor_type, actor_id, action, target, target_id, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
      $actorType,
      $actorId,
      $action,
      $target   ?: null,
      $targetId,
      $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
  } catch (PDOException $e) {
    // Audit log failure should never crash the app — just log silently
    error_log('Audit log failed: ' . $e->getMessage());
  }
}

// ---- ID VALIDATION ----

/**
 * validateStudentId($id)
 *
 * Validates Student ID format: NNNN-NNNNNL (e.g., 2316-00001C)
 * Format: 4 digits, hyphen, 5 digits, 1 uppercase letter
 *
 * Returns null if valid, error message string if invalid.
 */
function validateStudentId(string $id): ?string {
  $id = trim($id);
  if (!preg_match('/^\d{4}-\d{5}[A-Z]$/', $id)) {
    return 'Student ID must be in format: 2316-00001C (4 digits, hyphen, 5 digits, 1 uppercase letter)';
  }
  return null; // null means valid
}

/**
 * validateFacultyId($id)
 *
 * Validates Faculty ID format: YYYY-NNNN (e.g., 2023-0001)
 * Format: 4 digits, hyphen, 4 digits
 *
 * Returns null if valid, error message string if invalid.
 */
function validateFacultyId(string $id): ?string {
  $id = trim($id);
  if (!preg_match('/^\d{4}-\d{4}$/', $id)) {
    return 'Faculty ID must be in format: 2023-0001 (4 digits, hyphen, 4 digits)';
  }
  return null; // null means valid
}

/**
 * maskEmail($email)
 *
 * Masks an email address for display: j***n@gmail.com
 * Used in OTP verification screens.
 */
function maskEmail(string $email): string {
  $parts = explode('@', $email);
  if (count($parts) !== 2) {
    return '***@***.***';
  }
  $local = $parts[0];
  $domain = $parts[1];

  if (strlen($local) <= 2) {
    $masked = $local[0] . '***';
  } else {
    $masked = $local[0] . str_repeat('*', strlen($local) - 2) . $local[strlen($local) - 1];
  }

  return $masked . '@' . $domain;
}

/**
 * getSetting(key, default)
 *
 * Reads a value from app_settings (see database/migrations/2026_08_rebuild.sql).
 * Falls back to $default if the table/row doesn't exist yet, so this is safe
 * to call even before the migration has been run.
 */
function getSetting(string $key, string $default = ''): string {
  static $cache = [];
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $db   = Database::getInstance();
    $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    $cache[$key] = ($val !== false && $val !== null) ? (string) $val : $default;
  } catch (\Throwable $e) {
    $cache[$key] = $default;
  }
  return $cache[$key];
}

/**
 * setSetting(key, value)
 *
 * Inserts or updates a row in app_settings.
 */
function setSetting(string $key, string $value): void {
  $db = Database::getInstance();
  $db->prepare(
    "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
  )->execute([$key, $value]);
}

/**
 * getSpotlightProducts($limit)
 *
 * Picks the products shown in the homepage "Spotlight" carousel
 * (public/menu.php) using a hybrid rule:
 *
 *   1. PINNED: any product an admin manually pinned
 *      (products.spotlight_pinned = 1) that hasn't passed its
 *      optional spotlight_pinned_until expiry. These always show,
 *      in the order they were last updated.
 *   2. AUTOMATIC: remaining slots are filled from the top-selling,
 *      well-rated products of the last 7 days (excludes anything
 *      already pinned). To avoid always showing the same 1-2 items,
 *      the top 10 candidates are shuffled with a seed tied to
 *      today's date — stable all day, different tomorrow.
 *   3. FALLBACK: if nothing has sold yet (e.g. a brand-new shop),
 *      candidates still come back (just all scored 0) so the
 *      carousel is never empty as long as there are available
 *      products.
 *
 * Only ever considers products.is_available = 1. Defensive against
 * the spotlight_pinned/_until migration not having been run yet —
 * falls back to automatic-only in that case.
 *
 * Returns an array of product rows (same shape as the main menu
 * query: includes cat_name, avg_rating, has_addons) ready to render
 * with the existing menu-card markup/JS.
 */
function getSpotlightProducts(int $limit = 5): array {
  $db = Database::getInstance();

  $hasAddonsExpr = "CASE WHEN EXISTS (
      SELECT 1 FROM product_addons pa
      JOIN addons a ON pa.addon_id = a.id
      WHERE pa.product_id = p.id AND a.status = 'active'
    ) THEN 1 ELSE 0 END AS has_addons";

  $pinned = [];
  try {
    $stmt = $db->query(
      "SELECT p.*, c.name AS cat_name,
              COALESCE(ROUND(AVG(pr.rating),1), 0) AS avg_rating,
              $hasAddonsExpr
       FROM products p
       JOIN categories c ON p.category_id = c.id
       LEFT JOIN product_ratings pr ON pr.product_id = p.id
       WHERE p.is_available = 1
         AND p.spotlight_pinned = 1
         AND (p.spotlight_pinned_until IS NULL OR p.spotlight_pinned_until >= NOW())
       GROUP BY p.id
       ORDER BY p.updated_at DESC"
    );
    $pinned = $stmt->fetchAll();
  } catch (\Throwable $e) {
    $pinned = []; // migration not run yet — automatic-only for now
  }

  $needed = $limit - count($pinned);
  if ($needed <= 0) {
    return array_slice($pinned, 0, $limit);
  }

  $pinnedIds  = array_column($pinned, 'id');
  $exclude    = $pinnedIds ? implode(',', array_fill(0, count($pinnedIds), '?')) : '0';

  $stmt = $db->prepare(
    "SELECT p.*, c.name AS cat_name,
            COALESCE(ROUND(AVG(pr.rating),1), 0) AS avg_rating,
            $hasAddonsExpr,
            COALESCE(SUM(CASE
              WHEN o.created_at >= (NOW() - INTERVAL 7 DAY)
               AND o.status NOT IN ('cancelled','no_show')
              THEN od.quantity ELSE 0 END), 0) AS sold_7d
     FROM products p
     JOIN categories c ON p.category_id = c.id
     LEFT JOIN order_details od ON od.product_id = p.id
     LEFT JOIN orders o         ON o.id = od.order_id
     LEFT JOIN product_ratings pr ON pr.product_id = p.id
     WHERE p.is_available = 1
       AND c.name != 'Add-ons'
       AND p.id NOT IN ($exclude)
     GROUP BY p.id
     ORDER BY (sold_7d * (0.7 + 0.3 * (avg_rating / 5))) DESC, p.id ASC
     LIMIT 10"
  );
  $stmt->execute($pinnedIds);
  $candidates = $stmt->fetchAll();

  // Stable-for-the-day shuffle of the top candidates, so the automatic
  // slots aren't frozen on whichever 1-2 products are always best-sellers.
  $seed = date('Ymd');
  usort($candidates, fn($a, $b) => crc32($seed . '-' . $a['id']) <=> crc32($seed . '-' . $b['id']));

  return array_merge($pinned, array_slice($candidates, 0, $needed));
}
