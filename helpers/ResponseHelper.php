<?php
/**
 * Wattipid ResponseHelper
 * 
 * Centralized response handling with automatic recursive security sanitization.
 */

require_once __DIR__ . '/SecurityHelper.php';

class ResponseHelper {
    public static function success($data = null, $message = "Success") {
        self::send(true, $message, $data, 200);
    }

    public static function error($message, $statusCode = 400) {
        self::send(false, $message, null, $statusCode);
    }

    public static function send($success, $message, $data = null, $code = 200) {
        if (ob_get_length()) ob_clean(); // Wipe accidental output
        http_response_code($code);
        
        // --- SECURITY: Output Sanitization (XSS Prevention) ---
        $sanitizedData = SecurityHelper::sanitize($data);

        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $sanitizedData
        ]);
        exit;
    }

    public static function sendRaw($result, $code = 200) {
        if (ob_get_length()) ob_clean();
        http_response_code($code);
        
        $sanitizedResult = SecurityHelper::sanitize($result);

        // Ensure the standard structure: success, message, data
        $final = [
            'success' => $sanitizedResult['success'] ?? true,
            'message' => $sanitizedResult['message'] ?? 'Operation successful',
            'data'    => $sanitizedResult['data'] ?? (isset($sanitizedResult['success']) ? null : $sanitizedResult)
        ];

        echo json_encode($final);
        exit;
    }
}
