<?php
// ============================================================
// public/index.php  —  Entry point
//
// No more landing page and no more role-select gate. Everyone
// (guest, student, faculty) lands directly on the shop.
// Admin and cashier are kept out of this page entirely — they
// have their own login and their own areas.
// ============================================================
require_once __DIR__ . '/../config/init.php';

if (isLoggedIn() && in_array(currentRole(), [ROLE_ADMIN, ROLE_CASHIER], true)) {
  redirectByRole();
}

require __DIR__ . '/menu.php';
