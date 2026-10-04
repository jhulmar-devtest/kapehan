<?php
// ============================================================
// config/secrets.example.php
//
// TEMPLATE — copy this file to config/secrets.php and fill in
// YOUR OWN values. config/secrets.php is gitignored on purpose:
// it never gets committed or pushed, so everyone's local
// database password / mail credentials / security key stay
// private to their own machine.
//
// SETUP:
//   1. Copy this file:  config/secrets.php
//   2. Edit the values below to match your own setup.
//   3. That's it — config/init.php loads it automatically.
// ============================================================

// --- Database credentials ---
// Laragon default: user 'root', empty password. XAMPP is the same
// unless you changed it. Adjust if your local MySQL is different.
define('DB_HOST',    'localhost');
define('DB_NAME',    'kapehan_db');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// --- Project folder name ---
// Must match the folder name you cloned this project into, as it
// appears in your browser's address bar before "/public".
// e.g. if you browse to http://localhost/kapehan/public/..., leave
// this as '/kapehan/public'. If your folder is named differently
// (e.g. "kapehan-pos"), change it to match.
define('APP_BASE_PATH', '/kapehan/public');

// --- Mail (SMTP) credentials — only needed for OTP / password-reset emails ---
// 1. Enable 2-Factor Authentication on the Gmail account you'll send from.
// 2. Google Account > Security > App passwords > generate one for "Mail".
// 3. Paste the 16-character app password below (NOT your regular Gmail password).
// If you don't need working emails for local dev, you can leave these as
// placeholders — the app will log a warning and skip sending instead of
// crashing (see includes/mail.php).
define('MAIL_USERNAME', 'your-gmail-address@gmail.com');
define('MAIL_PASSWORD', 'your-16-char-app-password');
define('MAIL_FROM',     'your-gmail-address@gmail.com');

// --- App security key ---
// Signs QR pickup-claim codes (HMAC). Generate your own random value —
// do not reuse this placeholder, and do not reuse any value that has
// ever been committed to git or shared outside the team.
// Quick way to generate one: run this in a terminal with PHP installed:
//   php -r "echo bin2hex(random_bytes(32));"
define('APP_KEY', 'REPLACE_WITH_YOUR_OWN_RANDOM_KEY');
