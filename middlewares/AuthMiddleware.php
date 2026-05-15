<?php
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class AuthMiddleware {
    private $secretKey;
    private $conn;

    public function __construct($secretKey, $dbConnection) {
        $this->secretKey = $secretKey;
        $this->conn = $dbConnection;
    }

    /**
     * Verifies the JWT token from the Authorization header.
     * Returns the decoded user data or sends an error response.
     */
    public function handle() {
        $authHeader = '';
        $headers = getallheaders();
        if ($headers) {
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization' || strtolower($key) === 'x-authorization') {
                    $authHeader = $value;
                    break;
                }
            }
        }

        if (empty($authHeader)) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        if (empty($authHeader)) {
            ResponseHelper::error("Unauthorized: Missing authorization header.", 401);
        }

        $token = str_replace('Bearer ', '', $authHeader);
        $payload = $this->verifyToken($token);

        if (!$payload) {
            ResponseHelper::error("Unauthorized: Invalid token signature or expired.", 401);
        }

        // --- SECURITY: TOKEN VERSION CHECK ---
        // This allows for immediate global logout by bumping the user's token_version in DB.
        $stmt = $this->conn->prepare("SELECT token_version, room_id FROM users WHERE id = ?");
        $stmt->execute([$payload['id']]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userRow || (int)$payload['ver'] !== (int)$userRow['token_version']) {
            ResponseHelper::error("Unauthorized: Session revoked. Please log in again.", 401);
        }

        // Inject the most up-to-date room ID so controllers can perform strict access checks
        $payload['room_id'] = $userRow['room_id'];

        return $payload;
    }

    private function verifyToken($token) {
        try {
            $parts = explode('.', $token);
            if (count($parts) != 3) return false;

            list($header, $payload, $signature) = $parts;

            $validSignature = hash_hmac('sha256', "$header.$payload", $this->secretKey, true);
            $validSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($validSignature));

            if ($signature !== $validSignature) return false;

            $data = json_decode(base64_decode($payload), true);
            if ($data['exp'] < time()) return false;

            return $data;
        } catch (Exception $e) {
            return false;
        }
    }
}
