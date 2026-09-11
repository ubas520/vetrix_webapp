<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('GET');

$auth = mobile_api_authenticate($conn);
$userId = (int) $auth['id'];

mobile_api_success([
    'user' => mobile_api_profile_payload($conn, $userId),
    'session' => [
        'token_type' => 'Bearer',
        'expires_at' => date(DATE_ATOM, strtotime((string) $auth['token_expires_at'])),
    ],
]);

