<?php
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';
require_once __DIR__ . '/../utils/email_service.php';

/**
 * Class AuthController
 * @method void testEmailDelivery(array $data)
 */
class AuthController {
    private $authService;

    public function __construct($dbConnection) {
        $this->authService = new AuthService($dbConnection);
    }

    public function login($data) {
        if (empty($data['email']) || empty($data['password'])) {
            ResponseHelper::error("Missing email or password", 400);
        }

        $result = $this->authService->login($data['email'], $data['password']);

        if ($result['success']) {
            ResponseHelper::send(true, $result['message'], $result['data']);
        } else {
            ResponseHelper::error($result['message'], 401);
        }
    }

    public function register($data) {
        $name = $data['name'] ?? null;
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $role = $data['role'] ?? 'tenant';
        $code = $data['code'] ?? null;
        
        $termsVersionId = $data['terms_version_id'] ?? null;
        $ipAddress = $data['ip_address'] ?? null;
        $deviceInfo = $data['device_info'] ?? null;

        if (!$name || !$email || !$password) {
            ResponseHelper::error("Missing required fields", 400);
        }

        $result = $this->authService->register($name, $email, $password, $role, $code, $termsVersionId, $ipAddress, $deviceInfo);

        if ($result['success']) {
            ResponseHelper::sendRaw($result); // Send exactly as formatted in AuthService
        } else {
            ResponseHelper::error($result['message'], 400);
        }
    }

    public function requestPasswordReset($data) {
        if (empty($data['email'])) {
            ResponseHelper::error("Email is required", 400);
        }
        $result = $this->authService->requestPasswordReset($data['email']);
        if ($result['success']) {
            ResponseHelper::success(null, $result['message']);
        } else {
            ResponseHelper::error($result['message'], 400);
        }
    }

    public function verifyResetOTP($data) {
        if (empty($data['email']) || empty($data['otp'])) {
            ResponseHelper::error("Email and OTP are required", 400);
        }
        $result = $this->authService->verifyResetOTP($data['email'], $data['otp']);
        if ($result['success']) {
            ResponseHelper::success(null, $result['message']);
        } else {
            ResponseHelper::error($result['message'], 400);
        }
    }

    public function resetPassword($data) {
        if (empty($data['email']) || empty($data['otp']) || empty($data['password'])) {
            ResponseHelper::error("Email, OTP, and new password are required", 400);
        }
        $result = $this->authService->resetPassword($data['email'], $data['otp'], $data['password']);
        if ($result['success']) {
            ResponseHelper::success(null, $result['message']);
        } else {
            ResponseHelper::error($result['message'], 400);
        }
    }

    public function sendVerificationCode($data) {
        $email = $data['email'] ?? null;
        if (empty($email)) {
            ResponseHelper::error("Email is required", 400);
            return;
        }
        $result = $this->authService->sendVerificationCode($email, $data['name'] ?? null);
        ResponseHelper::sendRaw($result);
    }

    public function verifyOTP($data) {
        $email = $data['email'] ?? null;
        $code = $data['code'] ?? null;
        if (empty($email) || empty($code)) {
            ResponseHelper::error("Email and verification code are required", 400);
            return;
        }
        $result = $this->authService->verifyOTP($email, $code, $data['type'] ?? 'verification');
        ResponseHelper::sendRaw($result);
    }

    public function refreshToken($data) {
        if (empty($data['refreshToken'])) {
            ResponseHelper::error("Refresh token is required", 400);
            return;
        }
        $result = $this->authService->refreshToken($data['refreshToken']);
        ResponseHelper::sendRaw($result);
    }

    public function logout($authenticatedUser) {
        if (!$authenticatedUser) {
            ResponseHelper::error("Unauthorized", 401);
        }
        $result = $this->authService->logout($authenticatedUser['id']);
        ResponseHelper::sendRaw($result);
    }

    public function testEmailDelivery($data) {
        $email = $data['email'] ?? '';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ResponseHelper::error("Valid recipient email is required", 400);
        }

        $subject = "Wattipid Email Delivery Test - " . date('Y-m-d H:i:s');
        $htmlBody = "
            <div style='font-family: sans-serif; padding: 20px; max-width: 500px; margin: 0 auto; background: #ffffff; border: 1px solid #22c55e; border-radius: 8px;'>
                <h2 style='color: #15803d;'>Wattipid Email Delivery Test</h2>
                <p>This is a diagnostic verification email sent by the Wattipid system.</p>
                <p><strong>Configured Provider:</strong> " . htmlspecialchars(EMAIL_PROVIDER) . "</p>
                <p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>
                <p>If you received this message, transactional email delivery is operating correctly.</p>
            </div>
        ";
        $textBody = "Wattipid Email Delivery Test\n\nProvider: " . EMAIL_PROVIDER . "\nTimestamp: " . date('Y-m-d H:i:s') . "\n\nDelivery successful.";

        logEmailLifecycle('REQUEST_RECEIVED', $email, EMAIL_PROVIDER, ['type' => 'diagnostic_test']);
        logEmailLifecycle('EMAIL_PREPARED', $email, EMAIL_PROVIDER, ['subject' => $subject]);

        $result = sendEmail($email, 'Test Recipient', $subject, $htmlBody, $textBody, 'test');

        if ($result['success']) {
            ResponseHelper::sendRaw([
                'success' => true,
                'provider' => $result['provider'] ?? EMAIL_PROVIDER,
                'message' => 'Email accepted by provider',
                'messageId' => $result['messageId'] ?? null
            ]);
        } else {
            ResponseHelper::sendRaw([
                'success' => false,
                'provider' => $result['provider'] ?? EMAIL_PROVIDER,
                'message' => 'Email provider rejected the message',
                'errorCode' => $result['errorCode'] ?? 400,
                'error' => $result['message'] ?? 'Unknown rejection'
            ], 400);
        }
    }
}
