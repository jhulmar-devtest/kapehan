<?php
// ============================================================
// config/init.php
//
// THE MASTER BOOTSTRAP FILE.
// Every protected PHP page includes ONLY this one file at the top:
//
//   require_once __DIR__ . '/../../config/init.php';
//
// This file then loads everything else in the correct order.
// ============================================================

// Show errors during development — change to 0 before going live
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set once, globally, for every entry point (pages AND standalone api/*.php
// scripts). Previously this was only ever set inside one function body in
// includes/layout.php, so any script that didn't happen to call that
// function — like api/pickup-slots.php — ran on the server's default
// timezone instead of the store's, which threw off every "is this pickup
// slot still in the future?" comparison.
date_default_timezone_set('Asia/Manila');

// 0. Secrets (DB credentials, mail credentials, APP_KEY, APP_BASE_PATH).
//    This file is gitignored — every developer has their own local copy.
//    Loaded first because constants.php's APP_URL computation and
//    database.php both need values it defines.
if (!file_exists(__DIR__ . '/secrets.php')) {
  die(
    'Setup needed: config/secrets.php is missing. Copy config/secrets.example.php ' .
    'to config/secrets.php and fill in your own database/mail credentials. ' .
    'This file is intentionally left out of git — see README.md.'
  );
}
require_once __DIR__ . '/secrets.php';

// 1. Constants (role names, status names, APP_URL, etc.)
require_once __DIR__ . '/constants.php';

// 2. Mail configuration (SMTP settings for email)
require_once __DIR__ . '/mail.php';

// 3. Session configuration + session_start()
require_once __DIR__ . '/session.php';

// 4. Database class (PDO singleton)
require_once __DIR__ . '/database.php';

// 5. General helper functions (peso(), e(), flash(), auditLog(), etc.)
require_once __DIR__ . '/../includes/functions.php';

// 6. Authentication helpers (requireRole(), loginUser(), logoutUser(), etc.)
require_once __DIR__ . '/../includes/auth.php';

// 7. CSRF helpers (csrfToken(), csrfField(), verifyCsrf(), etc.)
require_once __DIR__ . '/../includes/csrf.php';

// 8. Email helpers (sendEmail(), sendOtpEmail(), etc.)
require_once __DIR__ . '/../includes/mail.php';

// 9. OTP helpers (generateOtp(), storeOtp(), validateOtp(), etc.)
require_once __DIR__ . '/../includes/otp.php';

// 10. Layout helpers (layoutHeader(), layoutFooter(), navItem())
require_once __DIR__ . '/../includes/layout.php';

// SECURITY: APP_KEY signs QR pickup-claim codes (see api/qr-generate.php /
// api/qr-claim.php) via HMAC. It used to be a literal value hardcoded
// right here in source — which meant anyone with repo access (and, once
// pushed, anyone on GitHub) could compute a valid signature for ANY
// ready order and claim someone else's paid pre-order without ever
// scanning a real QR code. It now lives in config/secrets.php instead
// (gitignored, one random value per developer/deployment — see
// config/secrets.example.php for how to generate your own).

define('THERMAL_PRINTER_CHARS', 32);  // 58mm paper width
define('STORE_NAME',    'Kapehan ni Amang');
define('STORE_ADDRESS', 'EARIST Cavite Campus GMA Cavite');
define('STORE_TEL',     '');
define('RECEIPT_FOOTER', 'Thank you for your order!');
