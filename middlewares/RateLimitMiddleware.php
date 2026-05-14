<?php
/**
 * Wattipid RateLimitMiddleware
 * 
 * Prevents API abuse by limiting the number of requests per IP/Endpoint.
 */

require_once __DIR__ . '/../helpers/ResponseHelper.php';

class RateLimitMiddleware {
    private $conn;
    private $limit = 100; // Requests per minute
    private $window = 60; // Seconds

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function handle($action) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $endpoint = $action ?: 'global';

        // Clean up old entries (simple garbage collection)
        if (rand(1, 100) === 1) {
            $this->conn->prepare("DELETE FROM rate_limits WHERE last_request < DATE_SUB(NOW(), INTERVAL 1 HOUR)")->execute();
        }

        // Check current limit
        $stmt = $this->conn->prepare("SELECT request_count, last_request FROM rate_limits WHERE ip_address = ? AND endpoint = ?");
        $stmt->execute([$ip, $endpoint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $lastRequest = strtotime($row['last_request']);
            if (time() - $lastRequest > $this->window) {
                // Reset window
                $this->conn->prepare("UPDATE rate_limits SET request_count = 1, last_request = NOW() WHERE ip_address = ? AND endpoint = ?")
                           ->execute([$ip, $endpoint]);
            } else {
                if ($row['request_count'] >= $this->limit) {
                    ResponseHelper::error("Too many requests. Please try again in a minute.", 429);
                }
                // Increment
                $this->conn->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ? AND endpoint = ?")
                           ->execute([$ip, $endpoint]);
            }
        } else {
            // First request
            $this->conn->prepare("INSERT INTO rate_limits (ip_address, endpoint, request_count) VALUES (?, ?, 1)")
                       ->execute([$ip, $endpoint]);
        }

        return true;
    }
}
