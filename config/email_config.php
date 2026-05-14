<?php
/**
 * Email API Configuration for Wattipid
 */

// ============ EMAIL PROVIDER SELECTION ============
// Options: 'sendgrid', 'brevo', 'mock'
define('EMAIL_PROVIDER', 'brevo');

// Sign up at https://sendgrid.com (100 emails/day free tier)
define('SENDGRID_API_KEY', config('SENDGRID_API_KEY', ''));

// ============ OTP SETTINGS ============
define('OTP_LENGTH', 6);
define('OTP_EXPIRY_MINUTES', 5);
define('OTP_MAX_ATTEMPTS', 5);           // Max failed verification attempts
define('OTP_RESEND_COOLDOWN_SECONDS', 60); // Minimum wait between resends
define('OTP_RATE_LIMIT_PER_HOUR', 10);   // Max OTPs per email per hour
