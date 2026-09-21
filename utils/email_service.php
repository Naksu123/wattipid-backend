<?php
/**
 * Wattipid Central Email Service
 * 
 * Production-grade transactional email delivery with support for:
 * - Direct SMTP (Gmail, Brevo SMTP, Hostinger, cPanel, etc.)
 * - Brevo (Sendinblue) Transactional API (with IPv4 resolution)
 * - SendGrid Web API
 * - Development Mock Mode
 * 
 * Includes structured delivery lifecycle logging and direct synchronous dispatch.
 */

require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/QueueService.php';

// ============ STRUCTURED LOGGING HELPER ============

/**
 * Log email lifecycle stages safely without exposing sensitive data.
 * Stages: REQUEST_RECEIVED, EMAIL_PREPARED, EMAIL_PROVIDER_REQUEST,
 *         EMAIL_PROVIDER_ACCEPTED, EMAIL_PROVIDER_REJECTED, EMAIL_FAILED
 */
function logEmailLifecycle($stage, $toEmail, $provider, $details = [])
{
    $timestamp = date('Y-m-d H:i:s');

    // Mask sensitive details
    if (isset($details['otp']))
        unset($details['otp']);
    if (isset($details['password']))
        unset($details['password']);
    if (isset($details['token']))
        unset($details['token']);
    if (isset($details['access_code']))
        unset($details['access_code']);

    $detailsJson = !empty($details) ? ' | ' . json_encode($details, JSON_UNESCAPED_SLASHES) : '';
    $prefix = (strpos($stage, 'REJECTED') !== false || strpos($stage, 'FAILED') !== false) ? '[EMAIL ERROR]' : '[EMAIL]';
    $logEntry = "[$timestamp] $prefix $stage to=$toEmail provider=$provider$detailsJson\n";

    @file_put_contents(__DIR__ . '/../email_debug.log', $logEntry, FILE_APPEND);
}

// ============ QUEUE HELPER (BACKGROUND JOBS) ============

function queueEmail($conn, $toEmail, $toName, $subject, $htmlBody, $textBody = '')
{
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
 * @param string $type       Category (e.g. 'password_reset', 'invitation', 'verification', 'general')
 * @return array             ['success' => bool, 'message' => string, 'provider' => string, 'messageId' => ?string]
 */
function sendEmail($toEmail, $toName, $subject, $htmlBody, $textBody = '', $type = 'general')
{
    $provider = strtolower(EMAIL_PROVIDER);

    logEmailLifecycle('EMAIL_PROVIDER_REQUEST', $toEmail, $provider, ['type' => $type, 'subject' => $subject]);

    switch ($provider) {
        case 'smtp':
            return sendViaSmtp($toEmail, $toName, $subject, $htmlBody, $textBody);
        case 'brevo':
            return sendViaBrevo($toEmail, $toName, $subject, $htmlBody, $textBody, $type);
        case 'sendgrid':
            return sendViaSendGrid($toEmail, $toName, $subject, $htmlBody, $textBody);
        case 'mock':
            return sendViaMock($toEmail, $subject, $htmlBody);
        default:
            $err = "Unknown email provider configured: $provider";
            logEmailLifecycle('EMAIL_FAILED', $toEmail, $provider, ['error' => $err]);
            return ['success' => false, 'message' => $err, 'provider' => $provider];
    }
}

// ============ NATIVE SMTP PROVIDER ============

/**
 * RFC 5321/5322 Compliant Socket-based SMTP Client.
 * Supports TLS / STARTTLS encryption (Gmail, Brevo SMTP, custom domains).
 */
function sendViaSmtp($toEmail, $toName, $subject, $htmlBody, $textBody = '')
{
    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
    $port = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
    $user = defined('SMTP_USER') ? SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? SMTP_PASS : '';
    $encryption = defined('SMTP_ENCRYPTION') ? strtolower(SMTP_ENCRYPTION) : 'tls';

    if (empty($host) || empty($port)) {
        $msg = 'SMTP host or port not configured in environment';
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $msg]);
        return ['success' => false, 'message' => $msg, 'provider' => 'smtp'];
    }

    $socketProtocol = ($encryption === 'ssl') ? 'ssl://' : '';
    $timeout = 15;

    $socket = @stream_socket_client($socketProtocol . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        $errorMsg = "Connection failed to $host:$port ($errno: $errstr)";
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
        return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
    }

    stream_set_timeout($socket, $timeout);

    $readResp = function () use ($socket) {
        $data = '';
        while ($line = fgets($socket, 512)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ')
                break;
        }
        return $data;
    };

    $greeting = $readResp();
    if (substr($greeting, 0, 3) !== '220') {
        fclose($socket);
        $errorMsg = 'Unexpected SMTP greeting: ' . trim($greeting);
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
        return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
    }

    $hostname = gethostname() ?: 'localhost';
    fputs($socket, "EHLO $hostname\r\n");
    $readResp();

    // STARTTLS handshake if TLS requested
    if ($encryption === 'tls' || ($port == 587 && $encryption !== 'ssl')) {
        fputs($socket, "STARTTLS\r\n");
        $tlsResp = $readResp();
        if (substr($tlsResp, 0, 3) !== '220') {
            fclose($socket);
            $errorMsg = 'STARTTLS failed: ' . trim($tlsResp);
            logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
            return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            $errorMsg = 'TLS handshake negotiation failed';
            logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
            return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
        }

        fputs($socket, "EHLO $hostname\r\n");
        $readResp();
    }

    // AUTH LOGIN if credentials supplied
    if (!empty($user) && !empty($pass)) {
        fputs($socket, "AUTH LOGIN\r\n");
        $authResp = $readResp();
        if (substr($authResp, 0, 3) !== '334') {
            fclose($socket);
            $errorMsg = 'AUTH LOGIN rejected: ' . trim($authResp);
            logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
            return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
        }

        fputs($socket, base64_encode($user) . "\r\n");
        $userResp = $readResp();
        if (substr($userResp, 0, 3) !== '334') {
            fclose($socket);
            $errorMsg = 'SMTP username rejected: ' . trim($userResp);
            logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
            return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
        }

        fputs($socket, base64_encode($pass) . "\r\n");
        $passResp = $readResp();
        if (substr($passResp, 0, 3) !== '235') {
            fclose($socket);
            $errorMsg = 'SMTP authentication failed (check credentials): ' . trim($passResp);
            logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
            return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
        }
    }

    // MAIL FROM
    $senderEmail = SENDER_EMAIL;
    fputs($socket, "MAIL FROM:<$senderEmail>\r\n");
    $mailFromResp = $readResp();
    if (substr($mailFromResp, 0, 3) !== '250') {
        fclose($socket);
        $errorMsg = 'MAIL FROM rejected: ' . trim($mailFromResp);
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
        return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
    }

    // RCPT TO
    fputs($socket, "RCPT TO:<$toEmail>\r\n");
    $rcptToResp = $readResp();
    if (substr($rcptToResp, 0, 3) !== '250' && substr($rcptToResp, 0, 3) !== '251') {
        fclose($socket);
        $errorMsg = 'Recipient rejected by server: ' . trim($rcptToResp);
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
        return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
    }

    // DATA
    fputs($socket, "DATA\r\n");
    $dataResp = $readResp();
    if (substr($dataResp, 0, 3) !== '354') {
        fclose($socket);
        $errorMsg = 'DATA command rejected: ' . trim($dataResp);
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
        return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
    }

    $msgId = '<' . bin2hex(random_bytes(16)) . '@' . ($host ?: 'wattipid.com') . '>';
    $boundary = '=_wattipid_' . md5(uniqid(time()));
    $senderName = SENDER_NAME;
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $toHeader = !empty($toName) ? '"' . addcslashes($toName, '"') . '" <' . $toEmail . '>' : $toEmail;

    $headers = [
        "From: \"$senderName\" <$senderEmail>",
        "To: $toHeader",
        "Subject: $encodedSubject",
        "Date: " . date('r'),
        "Message-ID: $msgId",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        "X-Mailer: Wattipid Energy Monitor Mailer"
    ];

    $content = implode("\r\n", $headers) . "\r\n\r\n";

    if (!empty($textBody)) {
        $content .= "--$boundary\r\n";
        $content .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $content .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $content .= chunk_split(base64_encode($textBody)) . "\r\n";
    }

    $content .= "--$boundary\r\n";
    $content .= "Content-Type: text/html; charset=UTF-8\r\n";
    $content .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $content .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $content .= "--$boundary--\r\n";
    $content .= "\r\n.\r\n";

    fputs($socket, $content);
    $sendResp = $readResp();

    fputs($socket, "QUIT\r\n");
    fclose($socket);

    if (substr($sendResp, 0, 3) === '250') {
        logEmailLifecycle('EMAIL_PROVIDER_ACCEPTED', $toEmail, 'smtp', ['messageId' => $msgId]);
        return ['success' => true, 'message' => 'Email accepted by SMTP provider', 'provider' => 'smtp', 'messageId' => $msgId];
    }

    $errorMsg = 'SMTP server rejected message body: ' . trim($sendResp);
    logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'smtp', ['error' => $errorMsg]);
    return ['success' => false, 'message' => $errorMsg, 'provider' => 'smtp'];
}

// ============ BREVO (SENDINBLUE) PROVIDER ============

function sendViaBrevo($toEmail, $toName, $subject, $htmlBody, $textBody, $type = 'general')
{
    $apiKeyLoaded = !empty(BREVO_API_KEY);
    $senderEmailLoaded = !empty(SENDER_EMAIL);

    // Section 5: Log environment variables state without exposing secrets
    error_log("[Email Env] BREVO_API_KEY loaded: " . ($apiKeyLoaded ? 'true' : 'false'));
    error_log("[Email Env] BREVO_SENDER_EMAIL loaded: " . ($senderEmailLoaded ? 'true' : 'false'));

    if (!$apiKeyLoaded) {
        $err = 'Brevo API key is not configured in .env';
        error_log("[Email] Brevo request failed | Status: 500 | Error: $err");
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'brevo', ['error' => $err]);
        return ['success' => false, 'message' => $err, 'provider' => 'brevo'];
    }

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
        'htmlContent' => $htmlBody,
        'replyTo' => [
            'email' => SENDER_EMAIL,
            'name' => SENDER_NAME
        ]
    ];

    if ($textBody) {
        $payload['textContent'] = $textBody;
    }

    // Section 4: Log request initiation without exposing tokens or secrets
    error_log("[Email] Sending $type email");
    error_log("[Email] Recipient: $toEmail");

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
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4 // Force IPv4 to prevent unrecognised IPv6 mismatch
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo = curl_getinfo($ch);

    error_log("[Email] Brevo response status: $httpCode");

    if ($curlError) {
        $errorMsg = "cURL Error ($curlError). DNS: " . ($curlInfo['primary_ip'] ?: 'Failed');
        error_log("[Email] Brevo request failed | Status: $httpCode | Error: $errorMsg");
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'brevo', ['error' => $errorMsg]);
        return ['success' => false, 'message' => "Connection failed: $errorMsg", 'provider' => 'brevo'];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $responseData = json_decode($response, true);
        $messageId = $responseData['messageId'] ?? "HTTP_$httpCode";
        error_log("[Email] Message ID: $messageId");
        logEmailLifecycle('EMAIL_PROVIDER_ACCEPTED', $toEmail, 'brevo', ['messageId' => $messageId]);
        return ['success' => true, 'message' => 'Email accepted by Brevo', 'provider' => 'brevo', 'messageId' => $messageId];
    }

    $errorData = json_decode($response, true);
    $errorMsg = $errorData['message'] ?? $response ?? "HTTP $httpCode";
    error_log("[Email] Brevo request failed | Status: $httpCode | Error: $errorMsg");
    logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'brevo', ['code' => $httpCode, 'error' => $errorMsg]);
    return ['success' => false, 'message' => "Brevo rejected request ($httpCode): $errorMsg", 'provider' => 'brevo', 'errorCode' => $httpCode];
}

// ============ SENDGRID PROVIDER ============

function sendViaSendGrid($toEmail, $toName, $subject, $htmlBody, $textBody)
{
    if (empty(SENDGRID_API_KEY)) {
        $err = 'SendGrid API key is not configured';
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'sendgrid', ['error' => $err]);
        return ['success' => false, 'message' => $err, 'provider' => 'sendgrid'];
    }

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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError) {
        logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'sendgrid', ['error' => $curlError]);
        return ['success' => false, 'message' => "cURL error: $curlError", 'provider' => 'sendgrid'];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        logEmailLifecycle('EMAIL_PROVIDER_ACCEPTED', $toEmail, 'sendgrid', ['code' => $httpCode]);
        return ['success' => true, 'message' => 'Email sent via SendGrid', 'provider' => 'sendgrid'];
    }

    $errorData = json_decode($response, true);
    $errorMsg = $errorData['errors'][0]['message'] ?? "HTTP $httpCode";
    logEmailLifecycle('EMAIL_PROVIDER_REJECTED', $toEmail, 'sendgrid', ['code' => $httpCode, 'error' => $errorMsg]);
    return ['success' => false, 'message' => "SendGrid error: $errorMsg", 'provider' => 'sendgrid'];
}

// ============ MOCK PROVIDER (Development) ============

function sendViaMock($toEmail, $subject, $htmlBody)
{
    $mockId = '<mock_' . uniqid() . '@wattipid.local>';
    logEmailLifecycle('EMAIL_PROVIDER_ACCEPTED', $toEmail, 'mock', ['messageId' => $mockId]);

    return [
        'success' => true,
        'message' => 'Mock email logged (not actually sent)',
        'provider' => 'mock',
        'messageId' => $mockId
    ];
}

// ============ OTP GENERATION & VALIDATION ============

function generateOTP()
{
    $min = pow(10, OTP_LENGTH - 1);
    $max = pow(10, OTP_LENGTH) - 1;
    return (string) random_int($min, $max);
}

function hashOTP($otp)
{
    return hash('sha256', $otp);
}

function storeOTP($conn, $email, $otp, $type = 'verification')
{
    $stmt = $conn->prepare("UPDATE email_otps SET status = 'invalidated' WHERE email = ? AND type = ? AND status = 'pending'");
    $stmt->execute([$email, $type]);

    $hashedOtp = hashOTP($otp);
    $expiryMinutes = (int) OTP_EXPIRY_MINUTES;
    $stmt = $conn->prepare("INSERT INTO email_otps (email, otp_hash, type, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL $expiryMinutes MINUTE))");
    return $stmt->execute([$email, $hashedOtp, $type]);
}

function validateOTP($conn, $email, $otp, $type = 'verification')
{
    $stmt = $conn->prepare("SELECT * FROM email_otps WHERE email = ? AND type = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$email, $type]);
    $record = $stmt->fetch();

    if (!$record) {
        return ['success' => false, 'message' => 'No verification code found.', 'status' => 'not_found'];
    }

    if ($record['status'] !== 'pending') {
        if ($record['status'] === 'expired')
            return ['success' => false, 'message' => 'Verification code has expired.', 'status' => 'expired'];
        if ($record['status'] === 'locked')
            return ['success' => false, 'message' => 'Too many failed attempts.', 'status' => 'locked'];
        if ($record['status'] === 'used')
            return ['success' => false, 'message' => 'This code has already been used.', 'status' => 'used'];
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

function checkOTPRateLimit($conn, $email)
{
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

function getOTPEmailTemplate($recipientName, $otpCode, $type = 'verification')
{
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
<body style="margin:0; padding:0; background-color:#0a0f1a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <table width="100%" style="background-color:#0a0f1a; padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="100%" style="max-width:480px; background:#111827; border-radius:16px; border:1px solid #22c55e;">
                    <tr>
                        <td style="padding:32px; text-align:center;">
                            <h1 style="color:#ffffff; margin:0 0 12px 0; font-size:24px;">{$title}</h1>
                            <p style="color:#9ca3af; font-size:14px; line-height:1.5; margin:0 0 24px 0;">{$subtitle}</p>
                            <div style="background:#22c55e; color:white; padding:16px 24px; font-size:32px; font-weight:bold; letter-spacing:8px; border-radius:12px; margin:20px 0; display:inline-block;">
                                {$otpCode}
                            </div>
                            <p style="color:#f59e0b; font-size:13px; margin:16px 0 0 0;">{$footerText}</p>
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

function getOTPEmailPlainText($recipientName, $otpCode, $type = 'verification')
{
    if ($type === 'access_code') {
        return "Wattipid Room Access Code\n\nWelcome to Wattipid Smart Electricity Monitoring System.\nYour room has been successfully registered.\nUse the access code below to complete your account registration.\n\nAccess Code: {$otpCode}\n\nImportant: Keep this code private. Do not share it with anyone.";
    }
    return "Wattipid Email Verification\n\nYour code is: {$otpCode}\n\nExpires in " . OTP_EXPIRY_MINUTES . " minutes.";
}

function getInvitationEmailTemplate($tenantName, $roomNumber, $accessCode, $expiresAt)
{
    $dateFmt = date('F j, Y g:i A', strtotime($expiresAt));
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <table width="100%" style="background-color:#f3f4f6; padding:40px 16px;">
        <tr>
            <td align="center">
                <table width="100%" style="max-width:540px; background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
                    <tr>
                        <td style="background:#2563eb; padding:28px 24px; text-align:center;">
                            <h1 style="color:#ffffff; margin:0; font-size:22px; font-weight:bold;">Wattipid Registration Invitation</h1>
                            <p style="color:#bfdbfe; margin:8px 0 0 0; font-size:14px;">Smart Electricity Monitoring System</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px;">
                            <p style="color:#374151; font-size:15px; margin:0 0 16px 0;">Hello <strong>{$tenantName}</strong>,</p>
                            <p style="color:#4b5563; font-size:14px; line-height:1.6; margin:0 0 20px 0;">You have been invited to register for the Wattipid Smart Electricity Monitoring System.</p>
                            
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin:0 0 24px 0;">
                                <p style="margin:0 0 8px 0; color:#64748b; font-size:13px;">Assigned Room: <strong style="color:#1e293b; font-size:15px;">{$roomNumber}</strong></p>
                                <p style="margin:8px 0 0 0; color:#64748b; font-size:13px;">Your Registration Access Code:</p>
                                <div style="background:#2563eb; color:#ffffff; font-size:26px; font-weight:bold; letter-spacing:6px; text-align:center; padding:14px; border-radius:8px; margin:10px 0;">
                                    {$accessCode}
                                </div>
                                <p style="margin:10px 0 0 0; color:#dc2626; font-size:12px; font-weight:bold;">Expires: {$dateFmt}</p>
                            </div>
                            
                            <h4 style="color:#1e293b; font-size:14px; margin:0 0 12px 0;">How to Complete Your Registration:</h4>
                            <ol style="color:#4b5563; font-size:13px; line-height:1.7; padding-left:20px; margin:0 0 24px 0;">
                                <li>Open the Wattipid mobile application.</li>
                                <li>Select <strong>Register as Tenant</strong>.</li>
                                <li>Enter this email address and your Access Code: <strong>{$accessCode}</strong></li>
                                <li>Set your account password and finish.</li>
                            </ol>
                            
                            <p style="font-size:12px; color:#9ca3af; margin:0 0 16px 0;">If you did not expect this invitation, you can safely ignore this email.</p>
                            <hr style="border:none; border-top:1px solid #e5e7eb; margin:20px 0;">
                            <p style="font-size:12px; color:#6b7280; margin:0;">Regards,<br><strong>Wattipid Administration</strong></p>
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

function getInvitationEmailPlainText($tenantName, $roomNumber, $accessCode, $expiresAt)
{
    $dateFmt = date('F j, Y g:i A', strtotime($expiresAt));
    return "Wattipid Registration Invitation\n\nDear {$tenantName},\n\nYou have been invited to register for the Wattipid Smart Electricity Monitoring System.\n\nRoom Number: {$roomNumber}\nAccess Code: {$accessCode}\nValid until: {$dateFmt}\n\nTo register:\n1. Open the Wattipid mobile application.\n2. Select Register as Tenant.\n3. Enter your email and Access Code: {$accessCode}\n4. Set your account password.\n\nIf you did not expect this invitation, please ignore this email.\n\nRegards,\nWattipid Administration";
}

// ============ HIGH-LEVEL DIRECT SEND FUNCTIONS ============

/**
 * Send Password Reset OTP email directly and synchronously.
 */
function sendPasswordResetEmail($conn, $email, $otp, $tenantName = '')
{
    logEmailLifecycle('REQUEST_RECEIVED', $email, EMAIL_PROVIDER, ['type' => 'password_reset']);

    $subject = "Password Reset Code - Wattipid";
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#0f172a; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <table width="100%" style="background-color:#0f172a; padding:40px 16px;">
        <tr>
            <td align="center">
                <table width="100%" style="max-width:480px; background:#1e293b; border-radius:14px; border:1px solid #334155; padding:32px;">
                    <tr>
                        <td align="center">
                            <h2 style="color:#ffffff; margin:0 0 12px 0; font-size:22px;">Password Reset Request</h2>
                            <p style="color:#94a3b8; font-size:14px; margin:0 0 24px 0;">Use the 6-digit verification code below to reset your Wattipid account password:</p>
                            <div style="background:#2563eb; color:#ffffff; font-size:32px; font-weight:bold; letter-spacing:8px; padding:18px 24px; border-radius:10px; margin:0 0 20px 0; display:inline-block;">
                                {$otp}
                            </div>
                            <p style="color:#f59e0b; font-size:13px; font-weight:600; margin:0 0 20px 0;">⏱ This code expires in 10 minutes.</p>
                            <hr style="border:none; border-top:1px solid #334155; margin:20px 0;">
                            <p style="color:#64748b; font-size:12px; margin:0;">If you did not request a password reset, please ignore this email or check your account security.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    $textBody = "Password Reset Request - Wattipid\n\nYour 6-digit password reset code is: {$otp}\n\nThis code will expire in 10 minutes.\n\nIf you did not request this, please ignore this email.";

    logEmailLifecycle('EMAIL_PREPARED', $email, EMAIL_PROVIDER, ['subject' => $subject]);

    $result = sendEmail($email, $tenantName, $subject, $htmlBody, $textBody, 'password_reset');

    logEmailDelivery($conn, $email, 'password_reset', $result['success'] ? 'sent' : 'failed', $result['provider'] ?? EMAIL_PROVIDER, $result['success'] ? null : ($result['message'] ?? 'Failed'));

    return $result;
}

/**
 * Send Tenant Invitation email directly and synchronously.
 */
function sendInvitationEmailDirect($conn, $email, $tenantName, $roomNumber, $accessCode, $expiresAt)
{
    logEmailLifecycle('REQUEST_RECEIVED', $email, EMAIL_PROVIDER, ['type' => 'invitation', 'room' => $roomNumber]);

    $subject = 'Your Wattipid Registration Invitation';
    $htmlBody = getInvitationEmailTemplate($tenantName, $roomNumber, $accessCode, $expiresAt);
    $textBody = getInvitationEmailPlainText($tenantName, $roomNumber, $accessCode, $expiresAt);

    logEmailLifecycle('EMAIL_PREPARED', $email, EMAIL_PROVIDER, ['subject' => $subject]);

    $result = sendEmail($email, $tenantName, $subject, $htmlBody, $textBody, 'invitation');

    logEmailDelivery($conn, $email, 'invitation', $result['success'] ? 'sent' : 'failed', $result['provider'] ?? EMAIL_PROVIDER, $result['success'] ? null : ($result['message'] ?? 'Failed'));

    return $result;
}

function queueInvitationEmail($conn, $email, $tenantName, $roomNumber, $accessCode, $expiresAt)
{
    $subject = 'Your Wattipid Registration Invitation';
    $htmlBody = getInvitationEmailTemplate($tenantName, $roomNumber, $accessCode, $expiresAt);
    $textBody = getInvitationEmailPlainText($tenantName, $roomNumber, $accessCode, $expiresAt);
    return queueEmail($conn, $email, $tenantName, $subject, $htmlBody, $textBody);
}

function sendVerificationOTP($conn, $email, $tenantName = '')
{
    $rateCheck = checkOTPRateLimit($conn, $email);
    if (!$rateCheck['allowed']) {
        return ['success' => false, 'message' => $rateCheck['message'], 'wait_seconds' => $rateCheck['wait_seconds']];
    }

    $otp = generateOTP();
    storeOTP($conn, $email, $otp, 'verification');

    $subject = 'Your Wattipid Verification Code: ' . $otp;
    $htmlBody = getOTPEmailTemplate($tenantName ?: $email, $otp, 'verification');
    $textBody = getOTPEmailPlainText($tenantName ?: $email, $otp, 'verification');

    $result = sendEmail($email, $tenantName, $subject, $htmlBody, $textBody, 'verification');

    logEmailDelivery($conn, $email, 'verification', $result['success'] ? 'sent' : 'failed', $result['provider'] ?? EMAIL_PROVIDER, $result['success'] ? null : ($result['message'] ?? 'Failed'));

    return ['success' => $result['success'], 'message' => $result['success'] ? 'Verification email sent.' : $result['message'], 'messageId' => $result['messageId'] ?? null];
}

function sendAccessCodeEmail($conn, $email, $accessCode, $roomId)
{
    $subject = 'Your Wattipid Room Access Code';
    $htmlBody = getOTPEmailTemplate($email, $accessCode, 'access_code');
    $textBody = getOTPEmailPlainText($email, $accessCode, 'access_code');

    $result = sendEmail($email, '', $subject, $htmlBody, $textBody, 'access_code');

    logEmailDelivery($conn, $email, 'access_code', $result['success'] ? 'sent' : 'failed', $result['provider'] ?? EMAIL_PROVIDER, $result['success'] ? null : ($result['message'] ?? 'Failed'));

    return ['success' => $result['success'], 'message' => $result['success'] ? 'Access code email sent.' : $result['message'], 'messageId' => $result['messageId'] ?? null];
}

function logEmailDelivery($conn, $email, $type, $status, $provider, $errorMessage = null)
{
    try {
        if ($conn) {
            $stmt = $conn->prepare("INSERT INTO email_logs (email, type, status, provider, error_message) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$email, $type, $status, $provider, $errorMessage]);
        }
    } catch (Exception $e) {
    }
}
