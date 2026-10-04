<?php
// Retired — merged into public/admin/sales.php (item 13).
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
redirect(APP_URL . '/admin/sales.php');
