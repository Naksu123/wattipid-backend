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
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $time = time();
        $key = "ratelimit_" . md5($ip . $action);
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wattipid_ratelimits';
        
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }
        
        $file = $tempDir . DIRECTORY_SEPARATOR . $key . '.json';
        $limit = 20;

        $fp = @fopen($file, 'c+');
        if (!$fp) return true; // Fail open if permission issues

        if (flock($fp, LOCK_EX)) {
            $size = filesize($file);
            $data = $size > 0 ? json_decode(fread($fp, $size), true) : null;
            
            if ($data && ($time - $data['first_request'] <= 10)) {
                if ($data['count'] >= $limit) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    http_response_code(429);
                    header('Content-Type: application/json');
                    echo json_encode(["success" => false, "message" => "Too many requests. Please slow down."]);
                    exit();
                }
                $data['count']++;
            } else {
                $data = ['count' => 1, 'first_request' => $time];
            }
            
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        
        // Randomly garbage collect old files (1% chance) to prevent disk bloat
        if (mt_rand(1, 100) === 1) {
            foreach (glob($tempDir . '/*.json') as $f) {
                if ($time - filemtime($f) > 60) {
                    @unlink($f);
                }
            }
        }
        
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

    /**
     * Secure Access Code Management Functions
     */
    
    // Hash code for database lookup using HMAC-SHA256
    public static function hashAccessCode($code) {
        return hash_hmac('sha256', $code, SECRET_KEY);
    }

    // Encrypt code using AES-256-CBC for storage
    public static function encryptAccessCode($code) {
        $method = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $encrypted = openssl_encrypt($code, $method, SECRET_KEY, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    // Decrypt code when resending email
    public static function decryptAccessCode($encryptedCode) {
        $method = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($method);
        $data = base64_decode($encryptedCode);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, $method, SECRET_KEY, 0, $iv);
    }

    // Generate masked code for frontend display
    public static function maskAccessCode($code) {
        if (strlen($code) <= 5) return str_repeat('*', strlen($code));
        $first = substr($code, 0, 3);
        $last = substr($code, -2);
        return $first . '*****' . $last;
    }
}
