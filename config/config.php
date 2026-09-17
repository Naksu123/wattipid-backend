<?php
/**
 * Global Configuration Loader for Wattipid
 */
date_default_timezone_set('Asia/Manila');

function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Strip optional surrounding double or single quotes
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

// Load environment variables
loadEnv(__DIR__ . '/../.env');

// Helper function to get config with fallback
function config($key, $default = null) {
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    $value = getenv($key);
    return ($value !== false && $value !== '') ? $value : $default;
}

// Common Constants
if (!defined('ENVIRONMENT')) define('ENVIRONMENT', config('APP_ENV', 'development'));
if (!defined('SECRET_KEY')) define('SECRET_KEY', config('SECRET_KEY', 'default_fallback_key_change_me'));
if (!defined('BREVO_API_KEY')) define('BREVO_API_KEY', config('BREVO_API_KEY', ''));
if (!defined('SENDER_EMAIL')) define('SENDER_EMAIL', config('SENDER_EMAIL', 'noreply@wattipid.com'));
if (!defined('SENDER_NAME')) define('SENDER_NAME', config('SENDER_NAME', 'Wattipid'));
if (!defined('HARDWARE_API_KEY')) define('HARDWARE_API_KEY', config('HARDWARE_API_KEY', 'wattipid_esp32_secret_2024'));

// Email & SMTP Configuration
if (!defined('EMAIL_PROVIDER')) define('EMAIL_PROVIDER', config('EMAIL_PROVIDER', 'brevo'));
if (!defined('SMTP_HOST')) define('SMTP_HOST', config('SMTP_HOST', ''));
if (!defined('SMTP_PORT')) define('SMTP_PORT', (int)config('SMTP_PORT', 587));
if (!defined('SMTP_USER')) define('SMTP_USER', config('SMTP_USER', ''));
if (!defined('SMTP_PASS')) define('SMTP_PASS', config('SMTP_PASS', ''));
if (!defined('SMTP_ENCRYPTION')) define('SMTP_ENCRYPTION', config('SMTP_ENCRYPTION', 'tls'));
