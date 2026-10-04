<?php
// ============================================================
// includes/otp.php
//
// WHAT THIS FILE DOES:
//   OTP (One-Time Password) management functions for email
//   verification and password reset. Handles generation,
//   storage, validation, and rate limiting.
// ============================================================

/**
 * generateOtp($length)
 *
 * Generates a random numeric OTP of specified length.
 *
 * @param int $length  Number of digits (default: OTP_LENGTH constant)
 * @return string       The generated OTP
 */
function generateOtp(int $length = OTP_LENGTH): string {
  $otp = '';
  for ($i = 0; $i < $length; $i++) {
    $otp .= random_int(0, 9);
  }
  return $otp;
}

/**
 * storeOtp($userType, $userId, $email, $otp, $purpose)
 *
 * Stores an OTP in the database for verification.
 * Invalidates any previous OTPs of the same purpose for this user.
 *
 * @param string $userType  'student', 'faculty', or 'cashier'
 * @param int    $userId    The user's ID
 * @param string $email     The email address
 * @param string $otp       The OTP code
 * @param string $purpose   'verification' or 'password_reset'
 *
 * @return bool             True if stored successfully
 */
function storeOtp(string $userType, int $userId, string $email, string $otp, string $purpose): bool {
  try {
    $db = Database::getInstance();

    // Invalidate any previous OTPs of the same purpose
    $stmt = $db->prepare(
      "UPDATE email_otps SET used_at = NOW()
       WHERE user_type = ? AND user_id = ? AND purpose = ? AND used_at IS NULL"
    );
    $stmt->execute([$userType, $userId, $purpose]);

    // Insert new OTP
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));
    $stmt = $db->prepare(
      "INSERT INTO email_otps (user_type, user_id, email, otp, purpose, expires_at)
       VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$userType, $userId, $email, $otp, $purpose, $expiresAt]);

    return true;
  } catch (PDOException $e) {
    error_log("Failed to store OTP: " . $e->getMessage());
    return false;
  }
}

/**
 * validateOtp($userType, $userId, $otp, $purpose)
 *
 * Validates an OTP code and marks it as used if valid.
 *
 * @param string $userType  'student', 'faculty', or 'cashier'
 * @param int    $userId    The user's ID
 * @param string $otp       The OTP code to validate
 * @param string $purpose   'verification' or 'password_reset'
 *
 * @return array            ['valid' => bool, 'message' => string]
 */
function validateOtp(string $userType, int $userId, string $otp, string $purpose): array {
  try {
    $db = Database::getInstance();

    // Find the OTP record
    $stmt = $db->prepare(
      "SELECT * FROM email_otps
       WHERE user_type = ? AND user_id = ? AND purpose = ? AND used_at IS NULL
       ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$userType, $userId, $purpose]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
      return ['valid' => false, 'message' => 'No verification code found. Please request a new one.'];
    }

    // Check if expired
    if (strtotime($record['expires_at']) < time()) {
      return ['valid' => false, 'message' => 'Verification code has expired. Please request a new one.'];
    }

    // Check attempts
    if ($record['attempts'] >= MAX_OTP_ATTEMPTS) {
      return ['valid' => false, 'message' => 'Too many failed attempts. Please request a new code.'];
    }

    // Check if OTP matches (timing-safe comparison)
    if (!hash_equals($record['otp'], $otp)) {
      // Increment attempts
      $stmt = $db->prepare("UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?");
      $stmt->execute([$record['id']]);

      $remaining = MAX_OTP_ATTEMPTS - $record['attempts'] - 1;
      return [
        'valid' => false,
        'message' => "Invalid verification code. {$remaining} attempts remaining."
      ];
    }

    // Mark as used
    $stmt = $db->prepare("UPDATE email_otps SET used_at = NOW() WHERE id = ?");
    $stmt->execute([$record['id']]);

    return ['valid' => true, 'message' => 'Verification successful.'];

  } catch (PDOException $e) {
    error_log("Failed to validate OTP: " . $e->getMessage());
    return ['valid' => false, 'message' => 'An error occurred. Please try again.'];
  }
}

/**
 * canResendOtp($userType, $userId, $purpose)
 *
 * Checks if user can resend OTP (rate limiting).
 *
 * @param string $userType  'student', 'faculty', or 'cashier'
 * @param int    $userId    The user's ID
 * @param string $purpose   'verification' or 'password_reset'
 *
 * @return bool             True if can resend, false if cooldown active
 */
function canResendOtp(string $userType, int $userId, string $purpose): bool {
  try {
    $db = Database::getInstance();

    $stmt = $db->prepare(
      "SELECT created_at FROM email_otps
       WHERE user_type = ? AND user_id = ? AND purpose = ?
       ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$userType, $userId, $purpose]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
      return true; // No previous OTP, can send
    }

    $created = strtotime($record['created_at']);
    $cooldownEnd = $created + OTP_RESEND_COOLDOWN;

    return time() >= $cooldownEnd;

  } catch (PDOException $e) {
    return true; // On error, allow resend
  }
}

/**
 * getOtpCooldownRemaining($userType, $userId, $purpose)
 *
 * Returns seconds remaining before resend is allowed.
 *
 * @param string $userType  'student', 'faculty', or 'cashier'
 * @param int    $userId    The user's ID
 * @param string $purpose   'verification' or 'password_reset'
 *
 * @return int              Seconds remaining (0 if can resend now)
 */
function getOtpCooldownRemaining(string $userType, int $userId, string $purpose): int {
  try {
    $db = Database::getInstance();

    $stmt = $db->prepare(
      "SELECT created_at FROM email_otps
       WHERE user_type = ? AND user_id = ? AND purpose = ?
       ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$userType, $userId, $purpose]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
      return 0;
    }

    $created = strtotime($record['created_at']);
    $cooldownEnd = $created + OTP_RESEND_COOLDOWN;
    $remaining = $cooldownEnd - time();

    return max(0, $remaining);

  } catch (PDOException $e) {
    return 0;
  }
}

/**
 * getOtpByEmail($email, $purpose)
 *
 * Finds a pending OTP by email address.
 * Used for password reset flow where we don't have userId yet.
 *
 * @param string $email    The email address
 * @param string $purpose  'verification' or 'password_reset'
 *
 * @return array|null      OTP record or null if not found
 */
function getOtpByEmail(string $email, string $purpose): ?array {
  try {
    $db = Database::getInstance();

    $stmt = $db->prepare(
      "SELECT * FROM email_otps
       WHERE email = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
       ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$email, $purpose]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return $record ?: null;

  } catch (PDOException $e) {
    return null;
  }
}

/**
 * validateOtpByEmail($email, $otp, $purpose)
 *
 * Validates an OTP by email address (for password reset).
 *
 * @param string $email    The email address
 * @param string $otp      The OTP code
 * @param string $purpose  'verification' or 'password_reset'
 *
 * @return array           ['valid' => bool, 'message' => string, 'record' => array|null]
 */
function validateOtpByEmail(string $email, string $otp, string $purpose): array {
  try {
    $db = Database::getInstance();

    // Find the OTP record
    $stmt = $db->prepare(
      "SELECT * FROM email_otps
       WHERE email = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
       ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$email, $purpose]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
      return ['valid' => false, 'message' => 'No verification code found. Please request a new one.', 'record' => null];
    }

    // Check attempts
    if ($record['attempts'] >= MAX_OTP_ATTEMPTS) {
      return ['valid' => false, 'message' => 'Too many failed attempts. Please request a new code.', 'record' => null];
    }

    // Check if OTP matches (timing-safe comparison)
    if (!hash_equals($record['otp'], $otp)) {
      // Increment attempts
      $stmt = $db->prepare("UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?");
      $stmt->execute([$record['id']]);

      $remaining = MAX_OTP_ATTEMPTS - $record['attempts'] - 1;
      return [
        'valid' => false,
        'message' => "Invalid verification code. {$remaining} attempts remaining.",
        'record' => null
      ];
    }

    // Mark as used
    $stmt = $db->prepare("UPDATE email_otps SET used_at = NOW() WHERE id = ?");
    $stmt->execute([$record['id']]);

    return ['valid' => true, 'message' => 'Verification successful.', 'record' => $record];

  } catch (PDOException $e) {
    error_log("Failed to validate OTP by email: " . $e->getMessage());
    return ['valid' => false, 'message' => 'An error occurred. Please try again.', 'record' => null];
  }
}

/**
 * cleanupExpiredOtps()
 *
 * Removes expired OTPs from the database.
 * Call this periodically (e.g., via cron job or on login).
 *
 * @return int  Number of records deleted
 */
function cleanupExpiredOtps(): int {
  try {
    $db = Database::getInstance();
    $stmt = $db->prepare("DELETE FROM email_otps WHERE expires_at < NOW()");
    $stmt->execute();
    return $stmt->rowCount();
  } catch (PDOException $e) {
    error_log("Failed to cleanup expired OTPs: " . $e->getMessage());
    return 0;
  }
}