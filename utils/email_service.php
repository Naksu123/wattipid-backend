<?php
/**
 * Wattipid Email Service
 * 
 * Production-grade email sending via third-party Email APIs.
 * Supports: SendGrid, Brevo (Sendinblue), and Mock mode for development.
 */

require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/QueueService.php';

// ============ QUEUE HELPER ============

function queueEmail($conn, $toEmail, $toName, $subject, $htmlBody, $textBody = '') {
    $queue = new QueueService($conn);
    return $queue->push('email', [
        'to' => $toEmail,
        'name' => $toName,
        'subject' => $subject,
        'htmlBody' => $htmlBody,
        'textBody' => $textBody
    ]);
}

// ============ CORE EMAIL DISPATCHER ============

/**
 * Send an email using the configured provider.
 * 
 * @param string $toEmail    Recipient email address
 * @param string $toName     Recipient name (can be empty)
 * @param string $subject    Email subject line
 * @param string $htmlBody   HTML content of the email
 * @param string $textBody   Plain text fallback (optional)
 * @return array             ['success' => bool, 'message' => string, 'provider' => string]
 */
function sendEmail($toEmail, $toName, $subject, $htmlBody, $textBody = '') {
    $provider = EMAIL_PROVIDER;

    switch ($provider) {
        case 'sendgrid':
            return sendViaSendGrid($toEmail, $toName, $subject, $htmlBody, $textBody);
        case 'brevo':
            return sendViaBrevo($toEmail, $toName, $subject, $htmlBody, $textBody);
        case 'mock':
            return sendViaMock($toEmail, $subject, $htmlBody);
        default:
            return ['success' => false, 'message' => "Unknown email provider: $provider"];
    }
}

// ============ SENDGRID PROVIDER ============

function sendViaSendGrid($toEmail, $toName, $subject, $htmlBody, $textBody) {
    $url = 'https://api.sendgrid.com/v3/mail/send';

    $payload = [
        'personalizations' => [
            [
                'to' => [['email' => $toEmail, 'name' => $toName ?: $toEmail]],
                'subject' => $subject
            ]
        ],
        'from' => [
            'email' => SENDER_EMAIL,
            'name' => SENDER_NAME
        ],
        'content' => []
    ];

    if ($textBody) {
        $payload['content'][] = ['type' => 'text/plain', 'value' => $textBody];
    }
    $payload['content'][] = ['type' => 'text/html', 'value' => $htmlBody];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . SENDGRID_API_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false  // For XAMPP local dev
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError) {
        return ['success' => false, 'message' => "cURL error: $curlError", 'provider' => 'sendgrid'];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Email sent via SendGrid', 'provider' => 'sendgrid'];
    }

    $errorData = json_decode($response, true);
    $errorMsg = $errorData['errors'][0]['message'] ?? "HTTP $httpCode";
    return ['success' => false, 'message' => "SendGrid error: $errorMsg", 'provider' => 'sendgrid'];
}

// ============ BREVO (SENDINBLUE) PROVIDER ============

function sendViaBrevo($toEmail, $toName, $subject, $htmlBody, $textBody) {
    $url = 'https://api.brevo.com/v3/smtp/email';

    $payload = [
        'sender' => [
            'name' => SENDER_NAME,
            'email' => SENDER_EMAIL
        ],
        'to' => [
            ['email' => $toEmail, 'name' => $toName ?: $toEmail]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlBody
    ];

    if ($textBody) {
        $payload['textContent'] = $textBody;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false 
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo = curl_getinfo($ch);

    if ($curlError) {
        $errorMsg = "cURL Error ($curlError). DNS: " . ($curlInfo['primary_ip'] ?: 'Failed');
        file_put_contents(__DIR__ . '/../email_debug.log', date('[Y-m-d H:i:s]') . " BREVO ERROR: $errorMsg\n", FILE_APPEND);
        return ['success' => false, 'message' => "Connection failed: $errorMsg", 'provider' => 'brevo'];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Email sent via Brevo', 'provider' => 'brevo'];
    }

    $errorData = json_decode($response, true);
    $errorMsg = $errorData['message'] ?? "HTTP $httpCode";
    file_put_contents(__DIR__ . '/../email_debug.log', date('[Y-m-d H:i:s]') . " BREVO REJECTION ($httpCode): $errorMsg\n", FILE_APPEND);
    return ['success' => false, 'message' => "Brevo rejected request: $errorMsg", 'provider' => 'brevo'];
}

// ============ MOCK PROVIDER (Development) ============

function sendViaMock($toEmail, $subject, $htmlBody) {
    $logEntry = date('[Y-m-d H:i:s]') . " MOCK EMAIL to: $toEmail | Subject: $subject\n";
    file_put_contents(__DIR__ . '/../email_debug.log', $logEntry, FILE_APPEND);

    return [
        'success' => true,
        'message' => "Mock email logged (not actually sent)",
        'provider' => 'mock'
    ];
}


// ============ OTP GENERATION ============

function generateOTP() {
    $min = pow(10, OTP_LENGTH - 1);
    $max = pow(10, OTP_LENGTH) - 1;
    return (string) random_int($min, $max);
}

function hashOTP($otp) {
    return hash('sha256', $otp);
}


// ============ OTP DATABASE OPERATIONS ============

function storeOTP($conn, $email, $otp, $type = 'verification') {
    $stmt = $conn->prepare("UPDATE email_otps SET status = 'invalidated' WHERE email = ? AND type = ? AND status = 'pending'");
    $stmt->execute([$email, $type]);

    $hashedOtp = hashOTP($otp);
    $expiryMinutes = (int)OTP_EXPIRY_MINUTES;
    $stmt = $conn->prepare("INSERT INTO email_otps (email, otp_hash, type, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL $expiryMinutes MINUTE))");
    return $stmt->execute([$email, $hashedOtp, $type]);
}

function validateOTP($conn, $email, $otp, $type = 'verification') {
    $stmt = $conn->prepare("SELECT * FROM email_otps WHERE email = ? AND type = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$email, $type]);
    $record = $stmt->fetch();

    if (!$record) {
        return ['success' => false, 'message' => 'No verification code found.', 'status' => 'not_found'];
    }
    
    if ($record['status'] !== 'pending') {
        if ($record['status'] === 'expired') return ['success' => false, 'message' => 'Verification code has expired.', 'status' => 'expired'];
        if ($record['status'] === 'locked') return ['success' => false, 'message' => 'Too many failed attempts.', 'status' => 'locked'];
        if ($record['status'] === 'used') return ['success' => false, 'message' => 'This code has already been used.', 'status' => 'used'];
        return ['success' => false, 'message' => 'Verification code is invalid.', 'status' => 'invalidated'];
    }

    if (strtotime($record['expires_at']) < time()) {
        $stmt = $conn->prepare("UPDATE email_otps SET status = 'expired' WHERE id = ?");
        $stmt->execute([$record['id']]);
        return ['success' => false, 'message' => 'Verification code has expired.', 'status' => 'expired'];
    }

    if ($record['attempts'] >= OTP_MAX_ATTEMPTS) {
        $stmt = $conn->prepare("UPDATE email_otps SET status = 'locked' WHERE id = ?");
        $stmt->execute([$record['id']]);
        return ['success' => false, 'message' => 'Too many failed attempts.', 'status' => 'locked'];
    }

    if (hashOTP($otp) !== $record['otp_hash']) {
        $stmt = $conn->prepare("UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?");
        $stmt->execute([$record['id']]);
        $remaining = OTP_MAX_ATTEMPTS - $record['attempts'] - 1;
        return ['success' => false, 'message' => "Incorrect code. $remaining attempts remaining.", 'status' => 'invalid'];
    }

    $stmt = $conn->prepare("UPDATE email_otps SET status = 'used', verified_at = NOW() WHERE id = ?");
    $stmt->execute([$record['id']]);

    return ['success' => true, 'message' => 'Verification successful!', 'status' => 'valid'];
}

function checkOTPRateLimit($conn, $email) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM email_otps WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$email]);
    $hourCount = $stmt->fetch()['cnt'];

    if ($hourCount >= OTP_RATE_LIMIT_PER_HOUR) {
        return ['allowed' => false, 'message' => 'Too many code requests. Please try again in 1 hour.', 'wait_seconds' => 3600];
    }

    $stmt = $conn->prepare("SELECT created_at FROM email_otps WHERE email = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$email]);
    $lastOtp = $stmt->fetch();

    if ($lastOtp) {
        $elapsed = time() - strtotime($lastOtp['created_at']);
        if ($elapsed < OTP_RESEND_COOLDOWN_SECONDS) {
            $wait = OTP_RESEND_COOLDOWN_SECONDS - $elapsed;
            return ['allowed' => false, 'message' => "Please wait $wait seconds.", 'wait_seconds' => $wait];
        }
    }

    return ['allowed' => true, 'message' => 'OK', 'wait_seconds' => 0];
}

// ============ EMAIL TEMPLATES ============

function getOTPEmailTemplate($recipientName, $otpCode, $type = 'verification') {
    if ($type === 'access_code') {
        $title = 'Wattipid Room Access Code';
        $subtitle = 'Welcome to Wattipid Smart Electricity Monitoring System.<br>Your room has been successfully registered.<br>Use the access code below to complete your account registration.';
        $footerText = 'Important: Keep this code private. Do not share it with anyone.';
    } else {
        $title = 'Verify Your Email';
        $subtitle = 'Enter the code below to verify your email address and complete your registration.';
        $footerText = '⏱ Expires in ' . OTP_EXPIRY_MINUTES . ' minutes';
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#0a0f1a; font-family: sans-serif;">
    <table width="100%" style="background-color:#0a0f1a; padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="100%" style="max-width:480px; background:#111827; border-radius:16px; border:1px solid #22c55e;">
                    <tr>
                        <td style="padding:32px; text-align:center;">
                            <h1 style="color:#ffffff;">{$title}</h1>
                            <p style="color:#9ca3af;">{$subtitle}</p>
                            <div style="background:#22c55e; color:white; padding:20px; font-size:32px; font-weight:bold; letter-spacing:8px; border-radius:12px; margin:20px 0;">
                                {$otpCode}
                            </div>
                            <p style="color:#f59e0b;">{$footerText}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

function getOTPEmailPlainText($recipientName, $otpCode, $type = 'verification') {
    if ($type === 'access_code') {
        return "Wattipid Room Access Code\n\nWelcome to Wattipid Smart Electricity Monitoring System.\nYour room has been successfully registered.\nUse the access code below to complete your account registration.\n\nAccess Code: {$otpCode}\n\nImportant: Keep this code private. Do not share it with anyone.";
    }
    return "Wattipid Email Verification\n\nYour code is: {$otpCode}\n\nExpires in " . OTP_EXPIRY_MINUTES . " minutes.";
}

// ============ HIGH-LEVEL SEND FUNCTIONS ============

function sendVerificationOTP($conn, $email, $tenantName = '') {
    $rateCheck = checkOTPRateLimit($conn, $email);
    if (!$rateCheck['allowed']) {
        return ['success' => false, 'message' => $rateCheck['message'], 'wait_seconds' => $rateCheck['wait_seconds']];
    }

    $otp = generateOTP();
    storeOTP($conn, $email, $otp, 'verification');
    
    $subject = 'Your Wattipid Verification Code: ' . $otp;
    $htmlBody = getOTPEmailTemplate($tenantName ?: $email, $otp, 'verification');
    $textBody = getOTPEmailPlainText($tenantName ?: $email, $otp, 'verification');

    $result = sendEmail($email, $tenantName, $subject, $htmlBody, $textBody);

    return ['success' => $result['success'], 'message' => $result['success'] ? 'Verification email sent.' : $result['message']];
}

function sendAccessCodeEmail($conn, $email, $accessCode, $roomId) {
    $subject = 'Your Wattipid Room Access Code';
    $htmlBody = getOTPEmailTemplate($email, $accessCode, 'access_code');
    $textBody = getOTPEmailPlainText($email, $accessCode, 'access_code');

    $result = sendEmail($email, '', $subject, $htmlBody, $textBody);

    return ['success' => $result['success'], 'message' => $result['success'] ? 'Access code email sent.' : $result['message']];
}

function logEmailDelivery($conn, $email, $type, $status, $provider, $errorMessage = null) {
    try {
        $stmt = $conn->prepare("INSERT INTO email_logs (email, type, status, provider, error_message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$email, $type, $status, $provider, $errorMessage]);
    } catch (Exception $e) {}
}

// ============ INVITATION TEMPLATE ============

function getInvitationEmailTemplate($tenantName, $roomNumber, $accessCode, $expiresAt) {
    $dateFmt = date('F j, Y g:i A', strtotime($expiresAt));
    return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>
            <div style='background-color: #2563EB; padding: 20px; text-align: center;'>
                <h1 style='color: white; margin: 0; font-size: 24px;'>Your Wattipid Registration Invitation</h1>
            </div>
            <div style='padding: 20px; background-color: #f9fafb; border: 1px solid #e5e7eb;'>
                <p>Dear <strong>{$tenantName}</strong>,</p>
                <p>You have been invited to register for the Wattipid Smart Electricity Monitoring System.</p>
                
                <h3 style='color: #111827; border-bottom: 2px solid #2563EB; padding-bottom: 5px;'>Registration Details:</h3>
                <p><strong>Room Number:</strong> {$roomNumber}</p>
                <p><strong>Your Access Code is:</strong> <span style='font-size: 20px; font-weight: bold; color: #2563EB; letter-spacing: 2px;'>{$accessCode}</span></p>
                <p style='color: #DC2626; font-weight: bold;'>This Access Code is valid until: {$dateFmt}</p>
                
                <h4>To complete your registration:</h4>
                <ol>
                    <li>Open the Wattipid mobile application.</li>
                    <li>Enter your registered email address.</li>
                    <li>Continue to the registration page.</li>
                    <li>Enter your Access Code.</li>
                    <li>Create your account.</li>
                </ol>
                
                <p>If your Access Code expires before registration is completed, please contact your landlord to request a new invitation.</p>
                <p style='font-size: 12px; color: #6b7280;'>If you did not expect this invitation, you may safely ignore this email.</p>
                <br>
                <p>Regards,</p>
                <p><strong>Wattipid Smart Electricity Monitoring System</strong></p>
            </div>
        </div>
    ";
}

function getInvitationEmailPlainText($tenantName, $roomNumber, $accessCode, $expiresAt) {
    $dateFmt = date('F j, Y g:i A', strtotime($expiresAt));
    return "Your Wattipid Registration Invitation\n\nDear {$tenantName},\n\nYou have been invited to register for the Wattipid Smart Electricity Monitoring System.\n\nRoom Number: {$roomNumber}\nYour Access Code is: {$accessCode}\nThis Access Code is valid until: {$dateFmt}\n\nTo complete your registration:\n1. Open the Wattipid mobile application.\n2. Enter your registered email address.\n3. Continue to the registration page.\n4. Enter your Access Code.\n5. Create your account.\n\nIf your Access Code expires before registration is completed, please contact your landlord to request a new invitation.\n\nIf you did not expect this invitation, you may safely ignore this email.\n\nRegards,\nWattipid Smart Electricity Monitoring System";
}

function queueInvitationEmail($conn, $email, $tenantName, $roomNumber, $accessCode, $expiresAt) {
    $subject = 'Your Wattipid Registration Invitation';
    $htmlBody = getInvitationEmailTemplate($tenantName, $roomNumber, $accessCode, $expiresAt);
    $textBody = getInvitationEmailPlainText($tenantName, $roomNumber, $accessCode, $expiresAt);
    return queueEmail($conn, $email, $tenantName, $subject, $htmlBody, $textBody);
}
// Force LSP re-index
