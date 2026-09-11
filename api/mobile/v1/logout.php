<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('POST');

$auth = mobile_api_authenticate($conn);
$userId = (int) $auth['id'];
mobile_api_revoke_token($conn, (int) $auth['token_id']);
mobile_api_audit($conn, $userId, 'Client signed out from mobile API', 'user', $userId, 'Bearer token revoked.');

mobile_api_success([], 200, 'Signed out successfully.');

