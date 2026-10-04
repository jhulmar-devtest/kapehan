<?php
// ============================================================
// index.php  —  Root entry point (redirects into /public)
// ============================================================
require_once __DIR__ . '/config/init.php';

if (isLoggedIn() && in_array(currentRole(), [ROLE_ADMIN, ROLE_CASHIER], true)) {
  redirectByRole();
}

require __DIR__ . '/public/menu.php';
