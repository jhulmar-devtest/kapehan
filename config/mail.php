<?php
// ============================================================
// config/mail.php
//
// WHAT THIS FILE DOES:
//   Defines SMTP settings for sending emails via Gmail.
//   Used by includes/mail.php for OTP and password reset emails.
//
// SETUP REQUIRED:
//   1. Enable 2-Factor Authentication on your Gmail account
//   2. Go to Google Account > Security > App passwords
//   3. Generate a new app password for "Mail"
//   4. Copy the 16-character password to MAIL_PASSWORD below
// ============================================================

// Gmail SMTP settings
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_ENCRYPTION', 'tls');           // TLS encryption

// MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM moved to config/secrets.php
// (gitignored — see config/secrets.example.php for the template).
define('MAIL_FROM_NAME', APP_NAME);         // Sender name (uses app name)