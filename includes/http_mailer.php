<?php
// HTTPS transport for hosts that block outbound SMTP.
function brevo_mail_ready(&$reason = null) {
    $reason = null;
    if (!APP_MAIL_SEND_REAL_EMAIL || APP_MAIL_BREVO_API_KEY === '') {
        $reason = 'Set VETRIX_BREVO_API_KEY and enable email delivery.';
    } elseif (preg_match('/[\r\n]/', APP_MAIL_BREVO_API_KEY)) {
        $reason = 'The Brevo API key contains invalid characters.';
    } elseif (!filter_var(APP_MAIL_FROM, FILTER_VALIDATE_EMAIL) || str_ends_with(APP_MAIL_FROM, '.local')) {
        $reason = 'Set VETRIX_MAIL_FROM to your verified Brevo sender address.';
    } elseif (!extension_loaded('openssl') || !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $reason = 'HTTPS email requires OpenSSL and allow_url_fopen.';
    }
    return $reason === null;
}

function brevo_mail_result($status, $body) {
    $data = json_decode((string)$body, true);
    if ($status === 201 && is_array($data) && !empty($data['messageId'])) {
        return ['ok' => true, 'method' => 'brevo_https'];
    }
    // Do not log provider response bodies: they can echo recipient data or secrets.
    $reasons = [
        400 => 'Brevo rejected the message. Check the verified sender and transactional email logs.',
        401 => 'Brevo API authentication failed. Check VETRIX_BREVO_API_KEY.',
        402 => 'Brevo sending credits are exhausted.',
        403 => 'Brevo denied sending. Check account activation, sender verification, and API access.',
        429 => 'Brevo rate limit reached. Try again later.',
    ];
    return ['ok' => false, 'error' => $reasons[$status] ?? 'Brevo did not confirm email acceptance (HTTP ' . (int)$status . ').'];
}

function brevo_send_mail($email, $name, $subject, $body) {
    if (!brevo_mail_ready($reason)) return ['ok' => false, 'error' => $reason];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Invalid recipient email address.'];
    $payload = json_encode([
        'sender' => ['email' => APP_MAIL_FROM, 'name' => APP_MAIL_FROM_NAME],
        'to' => [['email' => $email, 'name' => $name ?: $email]],
        'subject' => $subject,
        'textContent' => $body,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\napi-key: " . APP_MAIL_BREVO_API_KEY . "\r\n",
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
    if ($response === false) return ['ok' => false, 'error' => 'Could not reach Brevo over HTTPS. Check Railway outbound access and retry.'];
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    return brevo_mail_result($status, $response);
}
