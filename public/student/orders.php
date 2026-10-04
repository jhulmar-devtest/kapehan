<?php
// Retired — merged into public/orders.php (shared with faculty).
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_STUDENT);
redirect(APP_URL . '/orders.php');
