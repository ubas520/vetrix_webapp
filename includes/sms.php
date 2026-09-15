<?php

function vetrix_sms_config(): array {
    return require __DIR__ . '/../config/sms.php';
}

function vetrix_sms_notification_type(string $type): bool {
    return in_array($type, ['appointment', 'vaccine'], true);
}

function vetrix_sms_send_to_client($conn, int $userId, string $message): array {
    $state = vetrix_sms_status();
    if (!$state['ready']) return ['accepted' => false, 'message' => $state['message']];
    $stmt = $conn->prepare("SELECT phone FROM users WHERE id=? AND role='client' AND deleted_at IS NULL AND status IN ('active','approved') LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $recipient = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$recipient) return ['accepted' => false, 'message' => 'No eligible client recipient.'];
    return vetrix_sms_send((string)$recipient['phone'], $message);
}

function vetrix_sms_endpoint(array $config): ?string {
    $base = rtrim(trim((string)($config['base_url'] ?? '')), '/');
    if ($base !== 'https://api.sms-gate.app') return null;
    return $base . '/3rdparty/v1/messages';
}

function vetrix_sms_status(?array $config = null): array {
    $config = $config ?? vetrix_sms_config();
    $raw = @file_get_contents($config['status_file']);
    $state = $raw === false ? null : json_decode($raw, true);
    $now = microtime(true);
    $heartbeatAge = $now - (float)($state['updated_at'] ?? 0);
    $responseAge = $now - (float)($state['last_response_at'] ?? 0);
    $detected = is_array($state) && ($state['connected'] ?? false) === true
        && $heartbeatAge >= 0 && $heartbeatAge <= 2
        && $responseAge >= 0 && $responseAge <= 12;
    $configured = $config['enabled'] === true && trim((string)($config['username'] ?? '')) !== ''
        && trim((string)($config['password'] ?? '')) !== '' && vetrix_sms_endpoint($config) !== null;
    return [
        'detected' => $detected,
        'ready' => $detected && $configured && function_exists('curl_init'),
        'provider' => 'SMSGate API',
        'message' => !$detected ? 'GSM not detected or monitor unavailable. SMS is blocked.'
            : (!$configured ? 'GSM detected. Configure SMSGate to enable SMS.'
                : (!function_exists('curl_init') ? 'PHP cURL is required.' : 'GSM detected. SMSGate API sending enabled.')),
    ];
}

function vetrix_sms_phone(string $phone): ?string {
    $phone = preg_replace('/[\s()\-]/', '', $phone);
    if (preg_match('/^09\d{9}$/', $phone)) $phone = '+63' . substr($phone, 1);
    if (preg_match('/^639\d{9}$/', $phone)) $phone = '+' . $phone;
    return preg_match('/^\+639\d{9}$/', $phone) ? $phone : null;
}

function vetrix_sms_http(array $config, array $payload): array {
    $endpoint = vetrix_sms_endpoint($config);
    if ($endpoint === null) {
        return ['status' => 0, 'body' => ''];
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $config['username'] . ':' . $config['password'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body === false ? '' : $body];
}

function vetrix_sms_send(string $phone, string $message, ?array $config = null, ?callable $transport = null): array {
    $config = $config ?? vetrix_sms_config();
    $number = vetrix_sms_phone($phone);
    if ($number === null || trim($message) === '' || strlen($message) > 1600) {
        return ['accepted' => false, 'message' => 'A Philippine mobile number and message of up to 1600 bytes are required.'];
    }
    // Enforce on the server for every send, even if a browser button is enabled.
    $state = vetrix_sms_status($config);
    if (!$state['ready']) return ['accepted' => false, 'message' => $state['message']];
    try {
        $payload = ['textMessage' => ['text' => $message], 'phoneNumbers' => [$number]];
        $response = ($transport ?? 'vetrix_sms_http')($config, $payload);
        $body = json_decode($response['body'], true);
        if ($response['status'] >= 200 && $response['status'] < 300 && is_array($body) && !empty($body['id']) && ($body['state'] ?? '') !== 'Failed') {
            return ['accepted' => true, 'message' => 'Accepted by SMSGate API. Check the Android app for delivery status.', 'id' => (string)$body['id']];
        }
        if (in_array($response['status'], [401, 403], true)) {
            return ['accepted' => false, 'message' => 'SMSGate authorization failed. Check the Cloud Server username and password.'];
        }
    } catch (Throwable $e) {
        // Do not expose provider credentials or message content in errors.
    }
    return ['accepted' => false, 'message' => 'SMSGate did not confirm acceptance. Check its message history before retrying.'];
}
