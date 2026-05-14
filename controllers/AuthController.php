<?php
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../helpers/ResponseHelper.php';

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

        if (!$name || !$email || !$password) {
            ResponseHelper::error("Missing required fields", 400);
        }

        $result = $this->authService->register($name, $email, $password, $role, $code);

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
            ResponseHelper::error($result['message'], 500);
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
        $result = $this->authService->sendVerificationCode($data['email'], $data['name'] ?? null);
        ResponseHelper::sendRaw($result);
    }

    public function verifyOTP($data) {
        $result = $this->authService->verifyOTP($data['email'], $data['code'], $data['type'] ?? 'verification');
        ResponseHelper::sendRaw($result);
    }

    public function refreshToken($data) {
        if (!isset($data['refreshToken'])) {
            ResponseHelper::error("Refresh token is required", 400);
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
}
