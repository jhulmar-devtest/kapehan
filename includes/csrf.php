<?php
// ============================================================
// includes/csrf.php
//
// WHAT THIS FILE DOES:
//   CSRF = Cross-Site Request Forgery.
//   This attack tricks a logged-in user's browser into sending
//   a request they didn't intend (e.g., someone tricks you into
//   submitting a form that deletes your account).
//
// HOW WE PREVENT IT:
//   Every HTML form includes a hidden field with a secret random
//   token. When the form is submitted, we verify the token matches
//   what we stored in the session. An attacker can't know the token.
//
// HOW TO USE:
//   In every HTML form:
//     echo csrfField()
//
//   At the top of every form-handling PHP:
//     verifyCsrf();
// ============================================================

/**
 * csrfToken()
 *
 * Returns the current CSRF token, creating it if it doesn't exist.
 * The token is stored in the session and is unique per session.
 */
function csrfToken(): string {
  if (empty($_SESSION['csrf_token'])) {
    // random_bytes(32) generates 32 truly random bytes
    // bin2hex converts them to a readable 64-character hex string
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

/**
 * csrfField()
 *
 * Returns a complete HTML hidden input tag ready to paste in a form.
 * Example output:
 *   <input type="hidden" name="csrf_token" value="a3f9...">
 */
function csrfField(): string {
  return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

/**
 * verifyCsrf()
 *
 * Compares the token from the submitted form against the session token.
 * If they don't match → reject the request with 403.
 *
 * hash_equals() is used instead of == to prevent timing attacks.
 */
function verifyCsrf(): void {
  $submitted = $_POST['csrf_token'] ?? '';
  $stored    = $_SESSION['csrf_token'] ?? '';

  if (!hash_equals($stored, $submitted)) {
    http_response_code(403);
    die('Security check failed. Please go back and try again.');
  }
}

/**
 * verifyCsrfAjax()
 *
 * For AJAX requests, the token is sent in a custom header instead
 * of the POST body. Call this in API endpoint files.
 */
function verifyCsrfAjax(): void {
  $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
  $stored    = $_SESSION['csrf_token'] ?? '';

  if (!hash_equals($stored, $submitted)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
  }
}
