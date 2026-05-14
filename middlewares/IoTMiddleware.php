<?php
/**
 * Wattipid IoTMiddleware
 * 
 * Secures ESP32 -> Server communication using HMAC-SHA256 signing
 * and timestamp validation to prevent Replay Attacks.
 */

require_once __DIR__ . '/../helpers/ResponseHelper.php';
require_once __DIR__ . '/../config/db.php';

class IoTMiddleware {
    private $conn;
    private $allowed_clock_skew = 300; // 5 minutes in seconds

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    /**
     * Validate the IoT request.
     * Expected Headers:
     * - X-Wattipid-RoomID: The room identifier
     * - X-Wattipid-Timestamp: Current Unix timestamp from NTP
     * - X-Wattipid-Signature: HMAC-SHA256(room_id + timestamp + raw_body, secret)
     */
    public function handle($action, $data) {
        // Only protect IoT logging actions
        if ($action !== 'logConsumption') {
            return true;
        }

        $headers = getallheaders();
        // Handle case-insensitive headers
        $roomId = $headers['X-Wattipid-RoomID'] ?? $headers['x-wattipid-roomid'] ?? null;
        $timestamp = $headers['X-Wattipid-Timestamp'] ?? $headers['x-wattipid-timestamp'] ?? null;
        $signature = $headers['X-Wattipid-Signature'] ?? $headers['x-wattipid-signature'] ?? null;

        if (!$roomId || !$timestamp || !$signature) {
            ResponseHelper::error("IoT Security Error: Missing required security headers.", 403);
        }

        // 1. Anti-Replay Attack Check (Clock Skew)
        $now = time();
        if (abs($now - (int)$timestamp) > $this->allowed_clock_skew) {
            ResponseHelper::error("IoT Security Error: Request expired (Timestamp drift). Ensure ESP32 has NTP sync.", 403);
        }

        // 2. Fetch Device Secret from DB
        $stmt = $this->conn->prepare("SELECT device_secret FROM rooms WHERE room_id = ?");
        $stmt->execute([$roomId]);
        $secret = $stmt->fetchColumn();

        if (!$secret) {
            ResponseHelper::error("IoT Security Error: Unregistered device.", 403);
        }

        // 3. HMAC Signature Validation
        // We sign: RoomID + Timestamp + Raw JSON Body
        $rawBody = file_get_contents('php://input');
        $expectedSignature = hash_hmac('sha256', $roomId . $timestamp . $rawBody, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            ResponseHelper::error("IoT Security Error: Invalid signature.", 403);
        }

        return true; // Validated
    }
}
