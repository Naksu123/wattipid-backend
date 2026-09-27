<?php
/**
 * IDE Helper for Wattipid Backend
 * 
 * Provides static analysis symbols (Devsense PHP Tools, Intelephense)
 * with explicit unconditional definitions for constants.
 * 
 * NOTE: This file is purely for static analysis / IDE autocompletion.
 * It is never executed at runtime.
 */

// Environment & App Configuration Constants
define('ENVIRONMENT', 'development');
define('DEBUG_MODE', true);
define('SECRET_KEY', 'wattipid_secret_key');
define('HARDWARE_API_KEY', 'wattipid_esp32_secret_2024');

// Mail & Provider Configuration Constants
define('EMAIL_PROVIDER', 'brevo');
define('BREVO_API_KEY', '');
define('SENDER_EMAIL', 'noreply@wattipid.com');
define('SENDER_NAME', 'Wattipid');
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_ENCRYPTION', 'tls');
