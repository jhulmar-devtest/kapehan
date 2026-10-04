<?php
// ============================================================
// includes/auth.php
//
// WHAT THIS FILE DOES:
//   Contains functions that check WHO is logged in and WHETHER
//   they are allowed to view a particular page.
//
//   Every protected page calls one of these functions at the top.
//   If the check fails, the user is redirected immediately.
// ============================================================

/**
 * requireLogin()
 *
 * Checks if anyone is logged in at all.
 * If no one is logged in, redirect to login page.
 *
 * Use this on any page that needs a logged-in user,
 * before checking their specific role.
 */
function requireLogin(): void {
  if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
  }
}

/**
 * requireRole(string ...$roles)
 *
 * Checks if the logged-in user has one of the allowed roles.
 * The '...$roles' syntax means you can pass one OR many roles.
 *
 * Examples:
 *   requireRole(ROLE_ADMIN);                        // Only admins
 *   requireRole(ROLE_CASHIER);                      // Only cashiers
 *   requireRole(ROLE_ADMIN, ROLE_CASHIER);          // Admins OR cashiers
 *
 * If role doesn't match → HTTP 403 Forbidden.
 */
function requireRole(string ...$roles): void {
  if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    // Send unauthenticated visitors to the login screen that actually
    // matches what they were trying to reach, not always the customer
    // modal (item 10/11 — admin/cashier have separate entry points).
    if (in_array(ROLE_ADMIN, $roles, true) && !in_array(ROLE_CASHIER, $roles, true)) {
      header('Location: ' . APP_URL . '/staff-login.php');
    } elseif (in_array(ROLE_CASHIER, $roles, true) && !in_array(ROLE_ADMIN, $roles, true)) {
      header('Location: ' . APP_URL . '/cashier-select.php');
    } else {
      header('Location: ' . APP_URL . '/login.php');
    }
    exit;
  }

  if (!in_array($_SESSION['role'], $roles, true)) {
    http_response_code(403);
    // Show a friendly error page instead of a blank screen
    include __DIR__ . '/../public/errors/403.php';
    exit;
  }

  // BUG FIX: admin/cashiers.php lets an admin "Deactivate" a cashier
  // account, but is_active was previously only ever checked once, at
  // login (api/cashier-login.php). An already-logged-in cashier's
  // session stayed fully valid regardless — since ordinary activity
  // keeps refreshing the session timeout, a deactivated cashier could
  // keep ringing up orders and handling cash indefinitely. Cashiers
  // handle real money, so this one gets a live re-check on every
  // protected page rather than waiting for their session to expire
  // naturally.
  if ($_SESSION['role'] === ROLE_CASHIER) {
    $stmt = Database::getInstance()->prepare("SELECT is_active FROM cashiers WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if (!$stmt->fetchColumn()) {
      session_unset();
      session_destroy();
      header('Location: ' . APP_URL . '/cashier-select.php?reason=deactivated');
      exit;
    }
  }
}

/**
 * isLoggedIn()
 *
 * Returns true/false — useful for showing/hiding UI elements.
 * Does NOT redirect.
 */
function isLoggedIn(): bool {
  return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

/**
 * currentRole()
 *
 * Returns the role string of the logged-in user, or null.
 */
function currentRole(): ?string {
  return $_SESSION['role'] ?? null;
}

/**
 * currentUserId()
 *
 * Returns the ID of the logged-in user, or null.
 */
function currentUserId(): ?int {
  return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * loginUser(array $user, string $role)
 *
 * Called after successful password verification.
 * Stores user info in the session.
 *
 * IMPORTANT: session_regenerate_id(true) creates a NEW session ID
 * after login. This prevents "session fixation" attacks where an
 * attacker pre-sets a session ID before you log in.
 */
function loginUser(array $user, string $role): void {
  // Regenerate session ID to prevent session fixation
  session_regenerate_id(true);

  $_SESSION['user_id']   = $user['id'];
  $_SESSION['role']      = $role;
  $_SESSION['full_name'] = $user['full_name'];

  // Store extra info per role
  if ($role === ROLE_STUDENT) {
    $_SESSION['student_id_no'] = $user['student_id_no'];
  }
  if ($role === ROLE_FACULTY) {
    $_SESSION['faculty_id_no'] = $user['faculty_id_no'];
  }

  $_SESSION['last_activity'] = time();
  $_SESSION['login_time']    = time();

  // Track cashier login session
  if ($role === ROLE_CASHIER) {
    try {
      $db   = Database::getInstance();
      $stmt = $db->prepare(
        "INSERT INTO cashier_sessions (cashier_id, login_at) VALUES (?, NOW())"
      );
      $stmt->execute([$user['id']]);
      $_SESSION['cashier_session_id'] = (int) $db->lastInsertId();
    } catch (\Throwable $e) {
      // Non-fatal — don't block login if table doesn't exist yet
      error_log('cashier_sessions insert failed: ' . $e->getMessage());
    }
  }
}

/**
 * closeCashierSession()
 *
 * Stamps logout_at on the active cashier_sessions row.
 * Called by logoutUser() when role is cashier.
 */
function closeCashierSession(): void {
  if (!empty($_SESSION['cashier_session_id'])) {
    try {
      $db = Database::getInstance();
      $db->prepare(
        "UPDATE cashier_sessions SET logout_at = NOW()
                  WHERE id = ? AND logout_at IS NULL"
      )->execute([$_SESSION['cashier_session_id']]);
    } catch (\Throwable $e) {
      error_log('cashier_sessions update failed: ' . $e->getMessage());
    }
  }
}

/**
 * logoutUser()
 *
 * Destroys the session and redirects to login.
 * Always call this on logout.php.
 */
function logoutUser(): void {
  $role = $_SESSION['role'] ?? null;

  // Close cashier session tracking before wiping the session
  if ($role === ROLE_CASHIER) {
    closeCashierSession();
  }

  // Clear all session variables
  $_SESSION = [];

  // Delete the session cookie from the browser
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params['path'],
      $params['domain'],
      $params['secure'],
      $params['httponly']
    );
  }

  // Destroy the session on the server
  session_destroy();

  // Send each role back to the entry point that actually makes sense for
  // them — a cashier signing out mid-shift needs the profile picker again
  // for the next person, not the customer shop page.
  switch ($role) {
    case ROLE_CASHIER:
      header('Location: ' . APP_URL . '/cashier-select.php?logout=1');
      break;
    case ROLE_ADMIN:
      header('Location: ' . APP_URL . '/staff-login.php?logout=1');
      break;
    default:
      header('Location: ' . APP_URL . '/menu.php?logout=1');
  }
  exit;
}

/**
 * redirectByRole()
 *
 * After login, send each role to their home page.
 */
function redirectByRole(): void {
  switch ($_SESSION['role']) {
    case ROLE_ADMIN:
      header('Location: ' . APP_URL . '/admin/sales.php');
      break;
    case ROLE_CASHIER:
      header('Location: ' . APP_URL . '/cashier/walkin.php');
      break;
    case ROLE_STUDENT:
    case ROLE_FACULTY:
      // No more separate dashboard/ordering page — the shop page IS the dashboard.
      header('Location: ' . APP_URL . '/menu.php');
      break;
    default:
      header('Location: ' . APP_URL . '/login.php');
  }
  exit;
}
