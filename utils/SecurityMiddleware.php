<?php
/**
 * Phase 6: Security Hardening Middleware
 * Handles global rate limiting, input sanitization, and request validation.
 */
class SecurityMiddleware {
    
    /**
     * Basic IP-based Rate Limiter (in-memory/session for simplicity on Windows XAMPP)
     * In a true production Linux environment, this would use Redis.
     */
    public static function checkRateLimit($action) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $time = time();
        $key = "ratelimit_{$ip}_{$action}";

        // Allowed requests per 10 seconds
        $limit = 20;

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'count' => 1,
                'first_request' => $time
            ];
            return true;
        }

        $data = $_SESSION[$key];
        $timeDiff = $time - $data['first_request'];

        if ($timeDiff > 10) {
            // Reset after 10 seconds
            $_SESSION[$key] = [
                'count' => 1,
                'first_request' => $time
            ];
            return true;
        }

        if ($data['count'] >= $limit) {
            // Rate limit exceeded
            http_response_code(429);
            echo json_encode(["success" => false, "message" => "Too many requests. Please slow down."]);
            exit();
        }

        $_SESSION[$key]['count']++;
        return true;
    }

    /**
     * Deep sanitize inputs recursively to prevent XSS and SQL Injection.
     * PDO Prepared Statements already block SQL injection, but this strips malicious HTML/JS.
     */
    public static function sanitizeInput($data) {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                // Ignore base64 images to prevent corrupting uploads
                if (is_string($value) && strpos($value, 'data:image') === 0) {
                    $sanitized[$key] = $value;
                } else {
                    $sanitized[$key] = self::sanitizeInput($value);
                }
            }
            return $sanitized;
        }

        if (is_string($data)) {
            // Strip tags and encode special characters
            return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
        }

        return $data;
    }
}
?>
