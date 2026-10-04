<?php
// ============================================================
// public/logout.php
//
// WHAT THIS FILE DOES:
//   Logs out the current user by destroying their session,
//   then redirects to the login page.
//
//   We also log this event to the audit_log table so admins
//   can see who logged out and when.
// ============================================================

require_once __DIR__ . '/../config/init.php';

// Log the logout before destroying the session (we need the session data)
if (isLoggedIn()) {
  auditLog(currentRole(), currentUserId(), 'logout');
}

// This function (in includes/auth.php) destroys the session and redirects
logoutUser();
