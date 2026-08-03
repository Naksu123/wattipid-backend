<?php
require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../repositories/RoomRepository.php';
require_once __DIR__ . '/../repositories/InvitationRepository.php';
require_once __DIR__ . '/../repositories/PasswordResetRepository.php';

// We require email_service since it holds the sendVerificationOTP global function
require_once __DIR__ . '/../utils/email_service.php';

class AuthService {
    private $conn;
    private $userRepo;
    private $roomRepo;
    private $invitationRepo;
    private $passwordResetRepo;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection; // Save connection for transactions
        $this->userRepo = new UserRepository($dbConnection);
        $this->roomRepo = new RoomRepository($dbConnection);
        $this->invitationRepo = new InvitationRepository($dbConnection);
        $this->passwordResetRepo = new PasswordResetRepository($dbConnection);
    }

    public function login($email, $password) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        // 1. Brute-force Protection (15-min lockout after 5 fails)
        if ($this->isLockedOut($email)) {
            return ['success' => false, 'message' => 'Account locked due to too many failed attempts. Try again in 15 minutes.'];
        }

        $user = $this->userRepo->findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->logLoginAttempt($email, false, $ip);
            return ['success' => false, 'message' => 'Invalid email or password'];
        }

        if (!$user['is_verified']) {
            return ['success' => false, 'message' => 'Account not verified'];
        }

        // Success!
        $this->logLoginAttempt($email, true, $ip);
        $this->userRepo->updateLastLogin($user['id']);

        // Log to activity_logs
        $this->conn->prepare("INSERT INTO activity_logs (user_id, room_id, type, title, message) VALUES (?, ?, 'auth', 'User Login', 'User logged in successfully.')")->execute([$user['id'], $user['room_id']]);

        // 2. Generate Dual Tokens (Access + Refresh)
        // Access Token: Short-lived (15 mins), carries 'ver' (token_version)
        $accessToken = $this->generateAccessToken($user);
        
        // Refresh Token: Long-lived (7 days), stored hashed in DB
        $refreshToken = $this->generateAndStoreRefreshToken($user['id']);

        // Check Terms Acceptance for Tenants
        $requiresTerms = false;
        if ($user['role'] === 'tenant') {
            $stmt = $this->conn->prepare("SELECT id FROM terms_versions WHERE is_active = TRUE ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $activeVersion = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($activeVersion) {
                $stmt = $this->conn->prepare("SELECT id FROM terms_acceptance_logs WHERE tenant_id = ? AND version_id = ?");
                $stmt->execute([$user['id'], $activeVersion['id']]);
                if ($stmt->rowCount() == 0) {
                    $requiresTerms = true;
                }
            }
        }

        unset($user['password_hash']);
        return [
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $accessToken,
                'refreshToken' => $refreshToken,
                'requires_terms_acceptance' => $requiresTerms
            ]
        ];
    }

    private function isLockedOut($identifier) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND success = 0 AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$identifier]);
        return (int)$stmt->fetchColumn() >= 5;
    }

    private function logLoginAttempt($identifier, $success, $ip) {
        $stmt = $this->conn->prepare("INSERT INTO login_attempts (identifier, success, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$identifier, $success ? 1 : 0, $ip]);
    }

    private function generateAccessToken($user) {
        $payload = [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'ver' => $user['token_version'], // Embed version for instant global revocation
            'iat' => time(),
            'exp' => time() + (60 * 15) // 15 Minutes
        ];
        return $this->createJWT($payload);
    }

    private function generateAndStoreRefreshToken($userId) {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiry = date('Y-m-d H:i:s', time() + (3600 * 24 * 7)); // 7 Days

        $stmt = $this->conn->prepare("INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $hash, $expiry]);
        
        return $token;
    }

    public function refreshToken($refreshToken) {
        $hash = hash('sha256', $refreshToken);
        
        // Find valid, non-revoked token
        $stmt = $this->conn->prepare("SELECT * FROM refresh_tokens WHERE token_hash = ? AND revoked = 0 AND expires_at > NOW()");
        $stmt->execute([$hash]);
        $record = $stmt->fetch();

        if (!$record) {
            return ['success' => false, 'message' => 'Invalid or expired session. Please log in again.'];
        }

        // Token Rotation: Revoke the used one immediately
        $this->conn->prepare("UPDATE refresh_tokens SET revoked = 1 WHERE id = ?")->execute([$record['id']]);

        $user = $this->userRepo->findById($record['user_id']);
        if (!$user) return ['success' => false, 'message' => 'User not found.'];

        // Issue new pair (Rotation)
        $newAccess = $this->generateAccessToken($user);
        $newRefresh = $this->generateAndStoreRefreshToken($user['id']);

        return [
            'success' => true,
            'data' => [
                'token' => $newAccess,
                'refreshToken' => $newRefresh
            ]
        ];
    }

    public function logout($userId) {
        // 1. Revoke all sessions for this user
        $stmt = $this->conn->prepare("UPDATE refresh_tokens SET revoked = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // 2. Increment Token Version (Invalidates all active Access Tokens globally)
        $stmt = $this->conn->prepare("UPDATE users SET token_version = token_version + 1 WHERE id = ?");
        $stmt->execute([$userId]);

        // Log to activity_logs
        $this->conn->prepare("INSERT INTO activity_logs (user_id, type, title, message) VALUES (?, 'auth', 'User Logout', 'User logged out.')")->execute([$userId]);

        return ['success' => true, 'message' => 'Logged out successfully.'];
    }

    private function createJWT($payload) {
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
        $signature = hash_hmac('sha256', "$base64Header.$base64Payload", SECRET_KEY, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        return "$base64Header.$base64Payload.$base64Signature";
    }

    private function logAccessCodeAudit($action, $email, $ip) {
        try {
            // Find room ID related to this email invitation
            $stmt = $this->conn->prepare("SELECT room_id FROM invitations WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            $roomId = $inv ? $inv['room_id'] : 'unknown';

            $stmt = $this->conn->prepare("INSERT INTO access_code_audits (room_id, action, ip_address) VALUES (?, ?, ?)");
            $stmt->execute([$roomId, $action, $ip]);
        } catch (Exception $e) {}
    }

    public function register($name, $email, $password, $role = 'tenant', $code = null, $termsVersionId = null, $ipAddress = null, $deviceInfo = null) {
        $roomId = null;
        $invitationId = null;

        if ($role === 'tenant') {
            if (!$code) {
                return ['success' => false, 'message' => 'Access code is required for tenants'];
            }

            $invitation = $this->invitationRepo->getPendingInvitationByEmail($email);
            if (!$invitation) {
                $this->logAccessCodeAudit('Failed Registration - No Invitation', $email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
                return ['success' => false, 'message' => 'No invitation exists for this email.'];
            }
            if (strtotime($invitation['expires_at']) < time()) {
                $this->logAccessCodeAudit('Failed Registration - Expired', $email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
                return ['success' => false, 'message' => 'Your Access Code has expired. Please contact your landlord to request a new invitation.'];
            }
            if (hash('sha256', $code) !== $invitation['access_code_hash']) {
                $this->logAccessCodeAudit('Failed Registration - Wrong Code', $email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
                return ['success' => false, 'message' => 'The Access Code you entered is incorrect.'];
            }
            $roomId = $invitation['room_id'];
            $invitationId = $invitation['id'];
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $this->conn->beginTransaction();

            // 1. Create User
            $userId = $this->userRepo->createUser($name, $email, $passwordHash, $role, $roomId);
            if (!$userId) {
                throw new Exception("Registration failed: Could not create user record.");
            }

            // 2. Room & Invitation Updates (Atomic)
            if ($role === 'tenant' && $roomId) {
                if (!$this->invitationRepo->markAsRegistered($invitationId, $userId)) {
                    throw new Exception("Registration failed: Could not mark invitation as registered.");
                }
                if (!$this->roomRepo->markAsOccupied($roomId, $name)) {
                    throw new Exception("Registration failed: Could not assign room.");
                }

                $this->logAccessCodeAudit('Registered', $email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');

                if ($termsVersionId) {
                    $stmt = $this->conn->prepare("INSERT INTO terms_acceptance_logs (tenant_id, version_id, ip_address, device_info) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$userId, $termsVersionId, $ipAddress, $deviceInfo]);
                }
            }

            // 3. Generate Initial Session for Landlord
            $token = null;
            $refreshToken = null;
            if ($role === 'landlord') {
                $user = $this->userRepo->findById($userId);
                $token = $this->generateAccessToken($user);
                $refreshToken = $this->generateAndStoreRefreshToken($userId);
            }

            $this->conn->commit();

            if ($role === 'tenant') {
                $emailResult = sendVerificationOTP($this->conn, $email, $name);
                $response = [
                    'success' => true,
                    'message' => 'Registered successfully',
                    'needsVerification' => true
                ];
                return $response;
            } else {
                return [
                    'success' => true,
                    'message' => 'Registered successfully',
                    'needsVerification' => false,
                    'data' => [
                        'user' => [
                            'id' => $userId,
                            'name' => $name,
                            'email' => $email,
                            'role' => $role
                        ],
                        'token' => $token,
                        'refreshToken' => $refreshToken
                    ]
                ];
            }

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Registration Transaction Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Registration failed due to a system error. Please try again.'];
        }
    }

    public function requestPasswordReset($email) {
        $user = $this->userRepo->findByEmail($email);
        if (!$user) {
            // Anti-enumeration: still say it was sent even if the email doesn't exist
            return ['success' => true, 'message' => 'If this email is registered, you will receive a reset code.'];
        }

        $otp = rand(100000, 999999);
        $this->passwordResetRepo->createResetToken($email, $otp);

        $subject = "Password Reset Code - Wattipid";
        $body = "
            <div style='font-family: sans-serif; padding: 20px;'>
                <h2>Password Reset Request</h2>
                <p>You requested to reset your Wattipid account password. Use the following code to proceed:</p>
                <h1 style='color: #2196F3; letter-spacing: 5px;'>$otp</h1>
                <p>This code will expire in 10 minutes.</p>
                <hr/>
                <p style='font-size: 12px; color: #666;'>If you didn't request this, please ignore this email.</p>
            </div>
        ";
        
        $result = queueEmail($this->conn, $email, "", $subject, $body, "");

        if ($result) {
            return ['success' => true, 'message' => 'Reset code sent to your email'];
        } else {
            return ['success' => false, 'message' => 'Failed to send email. Please try again later.'];
        }
    }

    public function verifyResetOTP($email, $otp) {
        $resetId = $this->passwordResetRepo->findValidResetToken($email, $otp);
        if ($resetId) {
            return ['success' => true, 'message' => 'OTP verified successfully'];
        }
        return ['success' => false, 'message' => 'Invalid or expired reset code'];
    }

    public function resetPassword($email, $otp, $newPassword) {
        try {
            $this->conn->beginTransaction();

            $resetId = $this->passwordResetRepo->findValidResetToken($email, $otp);
            if (!$resetId) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Verification expired. Please start over.'];
            }

            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update the user's password (we need a method for this in UserRepository or we just do it here)
            $stmt = $this->conn->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
            $stmt->execute([$newPasswordHash, $email]);

            $this->passwordResetRepo->markAsUsed($resetId);

            $this->conn->commit();
            return ['success' => true, 'message' => 'Password reset successfully. You can now log in.'];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Failed to reset password.'];
        }
    }

    public function sendVerificationCode($email, $tenantName = null) {
        // Rate limit check
        $rateCheck = checkOTPRateLimit($this->conn, $email);
        if (!$rateCheck['allowed']) {
            return ['success' => false, 'message' => $rateCheck['message'], 'data' => ['wait_seconds' => $rateCheck['wait_seconds']]];
        }

        $otp = generateOTP();
        storeOTP($this->conn, $email, $otp, 'verification');

        $subject = 'Your Wattipid Verification Code: ' . $otp;
        $htmlBody = getOTPEmailTemplate($tenantName ?: $email, $otp, 'verification');
        $textBody = getOTPEmailPlainText($tenantName ?: $email, $otp, 'verification');
        $emailResult = sendEmail($email, $tenantName, $subject, $htmlBody, $textBody);
        
        logEmailDelivery($this->conn, $email, 'verification', $emailResult['success'] ? 'sent' : 'failed', $emailResult['provider'] ?? 'unknown', $emailResult['success'] ? null : $emailResult['message']);

        if ($emailResult['success']) {
            $responseData = ['emailSent' => true];
            return ['success' => true, 'message' => 'Verification code sent to your email.', 'data' => $responseData];
        } else {
            return ['success' => false, 'message' => 'Failed to send verification email.', 'data' => ['error' => $emailResult['message']]];
        }
    }

    public function verifyOTP($email, $code, $type = 'verification') {
        $result = validateOTP($this->conn, $email, $code, $type);
        
        if ($result['success'] && $type === 'verification') {
            // 1. Mark the user as verified in the database
            $this->userRepo->markEmailAsVerified($email);
            
            // 2. Automatically log the user in by generating tokens
            $user = $this->userRepo->findByEmail($email);
            if ($user) {
                $token = $this->generateAccessToken($user);
                $refreshToken = $this->generateAndStoreRefreshToken($user['id']);
                
                unset($user['password_hash']);
                $result['data'] = [
                    'user' => $user,
                    'token' => $token,
                    'refreshToken' => $refreshToken
                ];
            }
        }
        
        return $result;
    }
}
