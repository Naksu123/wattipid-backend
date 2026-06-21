<?php
/**
 * Phase 6: Security Hardening Middleware
 * Handles global rate limiting, input sanitization, and request validation.
 */
class SecurityMiddleware
{

    /**
     * Basic IP-based Rate Limiter (in-memory/session for simplicity on Windows XAMPP)
     * In a true production Linux environment, this would use Redis.
     */
    public static function checkRateLimit($action)
    {
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
    public static function sanitizeInput($data)
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                // Ignore base64 files to prevent corrupting uploads
                if (is_string($value) && (strpos($value, 'data:image') === 0 || strpos($value, 'data:application/pdf') === 0)) {
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

    /**
     * Feature 4: Secure File Upload Validation
     * Validates Base64 uploads for size (10MB max), format, and hidden malware (magic bytes)
     */
    public static function validateFileUpload($base64String)
    {
        if (empty($base64String))
            return true; // Optional

        // 1. Max 10MB limit. Base64 encoding inflates size by 33%.
        // 10MB actual = ~13.3MB base64 string = ~13981013 chars.
        if (strlen($base64String) > 13981013) {
            throw new Exception("File size exceeds 10MB maximum limit.");
        }

        // 2. Format validation (Image or PDF)
        if (!preg_match('/^data:(image\/(jpeg|png|jpg)|application\/pdf);base64,/', $base64String)) {
            throw new Exception("Invalid file format. Only JPG, PNG, and PDF are allowed.");
        }

        // 3. Extract base64 payload
        $base64Data = substr($base64String, strpos($base64String, ',') + 1);
        $decodedData = base64_decode($base64Data, true);
        if ($decodedData === false) {
            throw new Exception("File upload is corrupted.");
        }

        // 4. Validate Magic Bytes
        if (class_exists('finfo')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $decodedData);

            $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
            if (!in_array($mimeType, $allowedMimeTypes)) {
                throw new Exception("Security Alert: File signature does not match allowed types. Detected: $mimeType");
            }
        } else {
            // Fallback magic byte check if finfo extension is disabled in XAMPP
            $header = bin2hex(substr($decodedData, 0, 4));
            $validHeaders = [
                'ffd8ffe0', // JPEG
                'ffd8ffe1', // JPEG EXIF
                'ffd8ffe2', // JPEG
                'ffd8ffe8', // SPIFF
                '89504e47', // PNG
                '25504446'  // PDF (%PDF-)
            ];
            $isValid = false;
            foreach ($validHeaders as $valid) {
                if (strpos($header, $valid) === 0) {
                    $isValid = true;
                    break;
                }
            }
            if (!$isValid) {
                throw new Exception("Security scan failed: Invalid file header signature.");
            }
        }

        return true;
    }
}

