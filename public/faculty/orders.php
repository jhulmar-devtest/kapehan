<?php
// Retired — merged into public/orders.php (shared with student).
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_FACULTY);
redirect(APP_URL . '/orders.php');
