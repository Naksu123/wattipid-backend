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
        // Ensure success => false results in HTTP 400 error status so client interceptors catch it
        if (is_array($result) && isset($result['success']) && $result['success'] === false && $code === 200) {
            $code = 400;
        }
        http_response_code($code);
        
        $sanitizedResult = SecurityHelper::sanitize($result);

        // Ensure standard structure while preserving custom payload fields (e.g. skipped, reason, delta)
        $final = is_array($sanitizedResult) ? $sanitizedResult : [];
        if (!isset($final['success'])) $final['success'] = true;
        if (!isset($final['message'])) $final['message'] = ($final['success'] ? 'Operation successful' : 'Operation failed');
        if (!isset($final['error_code'])) $final['error_code'] = null;
        if (!isset($final['data'])) $final['data'] = null;

        echo json_encode($final);
        exit;
    }
}
