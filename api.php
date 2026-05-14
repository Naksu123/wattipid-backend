<?php
/**
 * Wattipid API - Production Hardened Entry Point
 * 
 * Features:
 * - Global Output Buffering (Prevents XSS/Malformed JSON)
 * - Strict JSON Error Handling
 * - Production-safe Error Hiding
 */

// 1. Initialize Output Buffering to catch accidental echoes or whitespace
ob_start();

// 2. Production Error Reporting (Log to file, hide from client)
define('DEBUG_MODE', true); // SET TO FALSE IN PRODUCTION
ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 3. Centralized JSON Error Handler
function sendJsonError($message, $code = 500) {
    ob_clean(); // Wipe any accidental output (warnings/notices)
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $message,
        'debug_info' => (defined('DEBUG_MODE') && DEBUG_MODE) ? error_get_last() : null
    ]);
    exit;
}

// Handle Fatal Errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        sendJsonError("A fatal server error occurred. Please check logs.", 500);
    }
});

// Handle Exceptions
set_exception_handler(function ($e) {
    error_log("Wattipid Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    sendJsonError("Server Error: " . $e->getMessage(), 500);
});

try {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/helpers/ResponseHelper.php';
    require_once __DIR__ . '/routes/Router.php';

    // Set JSON headers
    header('Content-Type: application/json');
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Bypass-Tunnel-Reminder");
    header("Access-Control-Allow-Credentials: true");

    // Handle Pre-flight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }

    // Process Request
    $json = file_get_contents('php://input');
    $data = json_decode($json, true) ?? [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    // Auth Middleware (Only enforce if not a public action)
    $publicActions = ['login', 'register', 'verifyOTP', 'refreshToken', 'requestPasswordReset', 'verifyResetOTP', 'resetPassword', 'sendVerificationCode', 'resendVerificationCode', 'getTenantInvitationByEmail'];
    
    $authenticatedUser = null;
    require_once __DIR__ . '/middlewares/AuthMiddleware.php';
    $auth = new AuthMiddleware(SECRET_KEY, $conn);
    
    if (!in_array($action, $publicActions)) {
        $authenticatedUser = $auth->handle();
    }

    // Route Request
    $router = new Router($conn);
    if (!$router->handle($action, $data, $authenticatedUser)) {
        ResponseHelper::error("Action '$action' not found.", 404);
    }

    // If we reached here, flush the buffer
    ob_end_flush();

} catch (Throwable $t) {
    sendJsonError($t->getMessage(), 500);
}
