<?php
// ============================================================
// includes/mail.php
//
// WHAT THIS FILE DOES:
//   Email sending wrapper using PHPMailer.
//   Handles SMTP configuration and provides simple functions
//   for sending emails (OTP verification, password reset).
//
// REQUIREMENTS:
//   - PHPMailer installed via Composer
//   - SMTP credentials configured in config/mail.php
// ============================================================

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * sendEmail($to, $subject, $body, $altBody)
 *
 * Sends an email using PHPMailer with configured SMTP settings.
 *
 * @param string $to       Recipient email address
 * @param string $subject  Email subject line
 * @param string $body     HTML email body
 * @param string $altBody  Plain text fallback (optional)
 *
 * @return array ['success' => bool, 'message' => string]
 */
function sendEmail(string $to, string $subject, string $body, string $altBody = ''): array {
  // Validate required configuration
  if (empty(MAIL_USERNAME) || empty(MAIL_PASSWORD) || empty(MAIL_FROM)) {
    return [
      'success' => false,
      'message' => 'Email not configured. Please set up SMTP credentials in config/mail.php'
    ];
  }

  $mail = new PHPMailer(true);

  try {
    // Server settings
    $mail->SMTPDebug = SMTP::DEBUG_OFF;          // Disable debug output (use DEBUG_SERVER for testing)
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    // Recipients
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress($to);

    // Content
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->AltBody = $altBody ?: strip_tags($body);

    $mail->send();

    return [
      'success' => true,
      'message' => 'Email sent successfully'
    ];

  } catch (PHPMailerException $e) {
    error_log("Email send failed: {$mail->ErrorInfo}");
    return [
      'success' => false,
      'message' => 'Failed to send email. Please try again later.'
    ];
  }
}

/**
 * otpEmailTemplate($otp, $purpose, $expiryMinutes)
 *
 * Generates an HTML email template for OTP verification.
 *
 * @param string $otp            The OTP code to display
 * @param string $purpose        'verification' or 'password_reset'
 * @param int    $expiryMinutes  Minutes until OTP expires
 *
 * @return string HTML email body
 */
function otpEmailTemplate(string $otp, string $purpose, int $expiryMinutes = 15): string {
  $appName = APP_NAME;
  $actionText = $purpose === 'password_reset' ? 'reset your password' : 'verify your email address';
  $introText = $purpose === 'password_reset'
    ? 'You requested to reset your password. Use the OTP below to proceed:'
    : 'Thank you for registering! Please use the following OTP to verify your email address:';

  return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verify Your Email</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f4f4;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 40px 20px;">
    <tr>
      <td align="center">
        <table width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
          <!-- Header -->
          <tr>
            <td style="background: linear-gradient(135deg, #C0392B 0%, #962D22 100%); padding: 30px; text-align: center;">
              <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">{$appName}</h1>
            </td>
          </tr>
          <!-- Content -->
          <tr>
            <td style="padding: 40px 30px;">
              <h2 style="margin: 0 0 20px; color: #333; font-size: 20px;">{$introText}</h2>
              <p style="margin: 0 0 30px; color: #666; font-size: 14px; line-height: 1.6;">
                Please enter this verification code in the app to {$actionText}.
              </p>
              <!-- OTP Code -->
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center">
                    <table cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="background-color: #f8f9fa; border: 2px solid #C0392B; border-radius: 8px; padding: 20px 40px;">
                          <span style="font-size: 36px; font-weight: 700; letter-spacing: 8px; color: #C0392B; font-family: 'Courier New', monospace;">{$otp}</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
              <p style="margin: 30px 0 0; color: #999; font-size: 13px; text-align: center;">
                This code will expire in <strong>{$expiryMinutes} minutes</strong>.<br>
                If you did not request this code, please ignore this email.
              </p>
            </td>
          </tr>
          <!-- Footer -->
          <tr>
            <td style="background-color: #f8f9fa; padding: 20px 30px; border-top: 1px solid #eee;">
              <p style="margin: 0; color: #999; font-size: 12px; text-align: center;">
                &copy; {$appName} - EARIST Cavite Campus<br>
                This is an automated message. Please do not reply.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * sendOtpEmail($to, $otp, $purpose)
 *
 * Sends an OTP verification email using the template.
 *
 * @param string $to       Recipient email
 * @param string $otp      The OTP code
 * @param string $purpose  'verification' or 'password_reset'
 *
 * @return array ['success' => bool, 'message' => string]
 */
function sendOtpEmail(string $to, string $otp, string $purpose): array {
  $subjectMap = [
    'verification'   => 'Verify Your Email Address',
    'password_reset' => 'Reset Your Password'
  ];

  $subject = $subjectMap[$purpose] ?? 'Verification Code';
  $body = otpEmailTemplate($otp, $purpose, OTP_EXPIRY_MINUTES);
  $altBody = "Your verification code is: {$otp}. This code expires in " . OTP_EXPIRY_MINUTES . " minutes.";

  return sendEmail($to, $subject, $body, $altBody);
}