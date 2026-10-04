<?php
// ============================================================
// public/admin/products.php
//
// Retired — Product management now lives inside the Ordering
// tab (public/admin/ordering.php), alongside Add-ons and Sizes
// & Pricing (item 13). Kept as a redirect so old bookmarks and
// any stray links still land somewhere useful.
// ============================================================
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_ADMIN);
redirect(APP_URL . '/admin/ordering.php');
