<?php
// ============================================================
// config/session.php
//
// WHAT THIS FILE DOES:
//   Configures PHP sessions securely BEFORE starting the session.
//   Sessions are how PHP remembers who is logged in between pages.
//   When a user logs in, we store their ID and role in the session.
//   Every protected page checks the session to verify access.
//
// IMPORTANT: This file must be included BEFORE session_start().
//   That's why init.php calls this first.
// ============================================================

// HttpOnly = JavaScript cannot read the session cookie.
// This protects against XSS (Cross-Site Scripting) attacks.
ini_set('session.cookie_httponly', 1);

// SameSite=Strict = Cookie is not sent on cross-site requests.
// Protects against CSRF (Cross-Site Request Forgery) attacks.
ini_set('session.cookie_samesite', 'Strict');

// Strict mode = PHP will reject unknown session IDs.
// Prevents session fixation attacks.
ini_set('session.use_strict_mode', 1);

// Only allow cookies to carry session ID (not URL ?PHPSESSID=...)
ini_set('session.use_only_cookies', 1);

// Session expires after SESSION_TIMEOUT seconds of inactivity
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);

// Name our session cookie something less obvious than PHPSESSID
session_name('earist_pos_session');

// Now start the session
session_start();

// ---- Session timeout check ----
// If the user was active before, check how long ago their last activity was.
if (isset($_SESSION['last_activity'])) {
  $inactive = time() - $_SESSION['last_activity'];
  if ($inactive > SESSION_TIMEOUT) {
    // Too long — destroy everything and send to login.
    //
    // BUG FIX: this used to unconditionally redirect, including for
    // api/*.php fetch() calls. A redirect isn't valid JSON, so every
    // endpoint's `.then(r => r.json())` would throw and land in a
    // generic `.catch(() => showToast('error', 'Network error...'))`
    // — a customer whose session simply expired mid-checkout saw
    // "Network error, please try again" instead of "please log in
    // again," and retrying would never work. Detect API/AJAX-style
    // requests and respond with JSON + 401 instead.
    //
    // BUG FIX: the timeout used to just destroy the session. Nothing recorded
    // that the user had been logged out, so the cashier_sessions row kept
    // logout_at = NULL and the cash drawer audit trail showed the cashier as
    // "Still logged in" forever. Record the logout BEFORE the session (and the
    // data we need to identify who it was) is wiped. This file runs before
    // functions.php / auth.php are loaded by init.php, so load them here;
    // they only define functions, and require_once makes the later load a no-op.
    $expiredRole      = $_SESSION['role']               ?? null;
    $expiredUserId    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $expiredCashierId = isset($_SESSION['cashier_session_id']) ? (int)$_SESSION['cashier_session_id'] : null;
    if ($expiredRole && $expiredUserId) {
      require_once __DIR__ . '/database.php';
      require_once __DIR__ . '/../includes/functions.php';
      require_once __DIR__ . '/../includes/auth.php';
      recordSessionTimeout($expiredRole, $expiredUserId, $expiredCashierId);
    }

    session_unset();
    session_destroy();

    $isApiRequest = (
      (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') ||
      strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
    );

    if ($isApiRequest) {
      http_response_code(401);
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'message' => 'Your session has expired. Please log in again.']);
      exit;
    }

    header('Location: ' . APP_URL . '/login.php?reason=timeout');
    exit;
  }
}
// Update their last activity timestamp on every page load
$_SESSION['last_activity'] = time();
