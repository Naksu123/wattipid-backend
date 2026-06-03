<?php
/**
 * Global Configuration Loader for Wattipid
 */
date_default_timezone_set('Asia/Manila');

function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load environment variables
loadEnv(__DIR__ . '/../.env');

// Helper function to get config with fallback
function config($key, $default = null) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

// Common Constants
if (!defined('ENVIRONMENT')) define('ENVIRONMENT', config('APP_ENV', 'development'));
if (!defined('SECRET_KEY')) define('SECRET_KEY', config('SECRET_KEY', 'default_fallback_key_change_me'));
if (!defined('BREVO_API_KEY')) define('BREVO_API_KEY', config('BREVO_API_KEY', ''));
if (!defined('SENDER_EMAIL')) define('SENDER_EMAIL', config('SENDER_EMAIL', 'noreply@wattipid.com'));
if (!defined('SENDER_NAME')) define('SENDER_NAME', config('SENDER_NAME', 'Wattipid'));
if (!defined('HARDWARE_API_KEY')) define('HARDWARE_API_KEY', config('HARDWARE_API_KEY', 'wattipid_esp32_secret_2024'));
