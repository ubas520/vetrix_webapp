<?php
declare(strict_types=1);

const VETRIX_MOBILE_API_VERSION = '1';
const VETRIX_MOBILE_DEFAULT_TOKEN_DAYS = 30;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function mobile_api_send(int $status, array $payload): never
{
    http_response_code($status);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        http_response_code(500);
        $json = '{"success":false,"message":"The response could not be encoded."}';
    }

    echo $json;
    exit;
}

function mobile_api_success(array $data = [], int $status = 200, ?string $message = null): never
{
    $payload = ['success' => true, 'data' => $data];
    if ($message !== null && $message !== '') {
        $payload['message'] = $message;
    }
    mobile_api_send($status, $payload);
}

function mobile_api_error(int $status, string $message, array $errors = []): never
{
    $payload = ['success' => false, 'message' => $message];
    if ($errors !== []) {
        $payload['errors'] = $errors;
    }
    mobile_api_send($status, $payload);
}

function mobile_api_current_origin(): string
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        return '';
    }
    return ($https ? 'https' : 'http') . '://' . $host;
}

function mobile_api_is_local_development_origin(string $origin, string $serverOrigin): bool
{
    $originParts = parse_url($origin);
    $serverParts = parse_url($serverOrigin);
    if (!is_array($originParts) || !is_array($serverParts)) {
        return false;
    }

    $originScheme = strtolower((string) ($originParts['scheme'] ?? ''));
    $originHost = strtolower((string) ($originParts['host'] ?? ''));
    $serverScheme = strtolower((string) ($serverParts['scheme'] ?? ''));
    $serverHost = strtolower((string) ($serverParts['host'] ?? ''));
    if ($originScheme !== 'http' || $serverScheme !== 'http' || $originHost === '' || $serverHost === '') {
        return false;
    }

    $loopbackHosts = ['localhost', '127.0.0.1', '::1'];
    if (in_array($originHost, $loopbackHosts, true)) {
        return true;
    }

    $serverIsLoopback = in_array($serverHost, $loopbackHosts, true);
    $serverIsPrivateIp = filter_var($serverHost, FILTER_VALIDATE_IP) !== false
        && filter_var(
            $serverHost,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;

    return ($serverIsLoopback || $serverIsPrivateIp) && hash_equals($serverHost, $originHost);
}

function mobile_api_apply_cors(): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }

    $configured = trim((string) (getenv('VETRIX_MOBILE_CORS_ORIGINS') ?: ''));
    $allowed = array_values(array_filter(array_map('trim', explode(',', $configured))));
    $sameOrigin = mobile_api_current_origin();
    $allowAll = in_array('*', $allowed, true);
    $isAllowed = $allowAll
        || ($sameOrigin !== '' && hash_equals($sameOrigin, $origin))
        || ($configured === '' && $sameOrigin !== '' && mobile_api_is_local_development_origin($origin, $sameOrigin));

    if (!$isAllowed) {
        foreach ($allowed as $candidate) {
            if ($candidate !== '*' && hash_equals(rtrim($candidate, '/'), rtrim($origin, '/'))) {
                $isAllowed = true;
                break;
            }
        }
    }

    if (!$isAllowed) {
        mobile_api_error(403, 'This web origin is not allowed to use the mobile API.');
    }

    header('Access-Control-Allow-Origin: ' . ($allowAll ? '*' : $origin));
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
    header('Access-Control-Max-Age: 600');
}

mobile_api_apply_cors();
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$databaseFile = dirname(__DIR__, 3) . '/config/database.php';
if (!is_file($databaseFile)) {
    mobile_api_error(503, 'The mobile API database configuration is unavailable.');
}
mysqli_report(MYSQLI_REPORT_OFF);
require_once $databaseFile;

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    mobile_api_error(503, 'The mobile API database is unavailable.');
}

set_exception_handler(static function (Throwable $exception): void {
    error_log('Vetrix mobile API error: ' . $exception->getMessage());
    mobile_api_error(500, 'The server could not complete this request.');
});

function mobile_api_require_method(string|array $allowed): string
{
    $allowedMethods = array_map('strtoupper', (array) $allowed);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $allowedMethods, true)) {
        header('Allow: ' . implode(', ', $allowedMethods));
        mobile_api_error(405, 'Method not allowed.');
    }
    return $method;
}

function mobile_api_input(): array
{
    static $input = null;
    if (is_array($input)) {
        return $input;
    }

    $raw = (string) file_get_contents('php://input');
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));

    if ($contentType === 'application/json' || ($raw !== '' && in_array($raw[0] ?? '', ['{', '['], true))) {
        if ($raw === '') {
            $input = [];
            return $input;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            mobile_api_error(400, 'The request body must contain valid JSON.');
        }
        $input = $decoded;
        return $input;
    }

    if ($_POST !== []) {
        $input = $_POST;
        return $input;
    }

    $parsed = [];
    if ($raw !== '') {
        parse_str($raw, $parsed);
    }
    $input = is_array($parsed) ? $parsed : [];
    return $input;
}

function mobile_api_string(array $input, string $key, string $default = ''): string
{
    $value = $input[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

function mobile_api_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function mobile_api_normalize_email(string $email): string
{
    $email = trim($email);
    return function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);
}

function mobile_api_password_errors(string $password): array
{
    $errors = [];
    if (strlen($password) < 10) {
        $errors[] = 'Use at least 10 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Add at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Add at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Add at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Add at least one symbol.';
    }
    return $errors;
}

function mobile_api_prepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Database statement preparation failed: ' . $conn->error);
    }
    return $stmt;
}

function mobile_api_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return $cache[$table] = ($result instanceof mysqli_result && $result->num_rows > 0);
}

function mobile_api_column_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    $escaped = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escaped}'");
    return $cache[$key] = ($result instanceof mysqli_result && $result->num_rows > 0);
}

function mobile_api_ensure_token_table(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    if (mobile_api_table_exists($conn, 'mobile_api_tokens')) {
        $ensured = true;
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS mobile_api_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        token_prefix VARCHAR(16) NOT NULL,
        device_name VARCHAR(120) NULL,
        expires_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        revoked_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mobile_api_tokens_hash (token_hash),
        KEY idx_mobile_api_tokens_user_active (user_id, revoked_at, expires_at),
        CONSTRAINT fk_mobile_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        error_log('Vetrix mobile API token table check failed: ' . $conn->error);
        mobile_api_error(503, 'The mobile API is not initialized. Run the mobile API migration.');
    }
    $ensured = true;
}

function mobile_api_bearer_token(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = trim((string) $value);
                break;
            }
        }
    }

    if (!preg_match('/^Bearer\s+([A-Za-z0-9_\-]+)$/i', $header, $matches)) {
        mobile_api_error(401, 'A valid bearer token is required.');
    }
    return $matches[1];
}

function mobile_api_token_ttl_days(): int
{
    $configured = (int) (getenv('VETRIX_MOBILE_TOKEN_TTL_DAYS') ?: VETRIX_MOBILE_DEFAULT_TOKEN_DAYS);
    return max(1, min(90, $configured));
}

function mobile_api_issue_token(mysqli $conn, int $userId, string $deviceName = ''): array
{
    mobile_api_ensure_token_table($conn);
    $token = 'vtx1_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash = hash('sha256', $token);
    $prefix = substr($token, 0, 16);
    $deviceName = trim($deviceName);
    if (mobile_api_text_length($deviceName) > 120) {
        $deviceName = function_exists('mb_substr') ? mb_substr($deviceName, 0, 120) : substr($deviceName, 0, 120);
    }
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . mobile_api_token_ttl_days() . ' days'));

    $stmt = mobile_api_prepare(
        $conn,
        'INSERT INTO mobile_api_tokens(user_id,token_hash,token_prefix,device_name,expires_at) VALUES(?,?,?,?,?)'
    );
    $stmt->bind_param('issss', $userId, $hash, $prefix, $deviceName, $expiresAt);
    if (!$stmt->execute()) {
        throw new RuntimeException('Mobile token creation failed: ' . $stmt->error);
    }

    return [
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_at' => date(DATE_ATOM, strtotime($expiresAt)),
    ];
}

function mobile_api_revoke_token(mysqli $conn, int $tokenId): void
{
    $stmt = mobile_api_prepare($conn, 'UPDATE mobile_api_tokens SET revoked_at=COALESCE(revoked_at,NOW()) WHERE id=?');
    $stmt->bind_param('i', $tokenId);
    $stmt->execute();
}

function mobile_api_authenticate(mysqli $conn): array
{
    static $authenticated = null;
    if (is_array($authenticated)) {
        return $authenticated;
    }

    mobile_api_ensure_token_table($conn);
    $token = mobile_api_bearer_token();
    $hash = hash('sha256', $token);
    $deletedSelect = mobile_api_column_exists($conn, 'users', 'deleted_at') ? 'u.deleted_at' : 'NULL AS deleted_at';
    $lockedSelect = mobile_api_column_exists($conn, 'users', 'locked_until') ? 'u.locked_until' : 'NULL AS locked_until';
    $photoSelect = mobile_api_column_exists($conn, 'users', 'profile_photo') ? 'u.profile_photo' : 'NULL AS profile_photo';

    $stmt = mobile_api_prepare(
        $conn,
        "SELECT t.id AS token_id,t.expires_at AS token_expires_at,
                u.id,u.full_name,u.email,u.phone,u.address,u.role,u.status,u.otp_verified_at,
                {$deletedSelect},{$lockedSelect},{$photoSelect}
         FROM mobile_api_tokens t
         JOIN users u ON u.id=t.user_id
         WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at>NOW()
         LIMIT 1"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        mobile_api_error(401, 'Your mobile session is invalid or expired. Sign in again.');
    }

    $tokenId = (int) $user['token_id'];
    if (!empty($user['deleted_at'])) {
        mobile_api_revoke_token($conn, $tokenId);
        mobile_api_error(403, 'This account is no longer available.');
    }
    if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
        mobile_api_error(423, 'This account is temporarily locked. Try again later.');
    }
    if (($user['role'] ?? '') !== 'client') {
        mobile_api_revoke_token($conn, $tokenId);
        mobile_api_error(403, 'This mobile API is available to client accounts only.');
    }
    if (($user['status'] ?? '') !== 'active' || empty($user['otp_verified_at'])) {
        mobile_api_revoke_token($conn, $tokenId);
        mobile_api_error(403, 'This client account is not active and verified.');
    }

    $touch = mobile_api_prepare($conn, 'UPDATE mobile_api_tokens SET last_used_at=NOW() WHERE id=?');
    $touch->bind_param('i', $tokenId);
    $touch->execute();

    $user['id'] = (int) $user['id'];
    $user['token_id'] = $tokenId;
    $authenticated = $user;
    return $authenticated;
}

function mobile_api_app_base_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $marker = '/api/mobile/v1/';
    $position = strpos($script, $marker);
    if ($position === false) {
        return '/';
    }
    $base = substr($script, 0, $position);
    return rtrim($base, '/') . '/';
}

function mobile_api_public_base_url(): string
{
    $configured = rtrim(trim((string) (getenv('VETRIX_PUBLIC_BASE_URL') ?: '')), '/');
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL)) {
        return $configured;
    }

    $origin = mobile_api_current_origin();
    if ($origin === '') {
        return rtrim(mobile_api_app_base_path(), '/');
    }
    return $origin . rtrim(mobile_api_app_base_path(), '/');
}

function mobile_api_media_url(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $path) && filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }

    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($path === '' || str_contains($path, '..')) {
        return null;
    }
    return mobile_api_public_base_url() . '/' . $path;
}

function mobile_api_profile_payload(mysqli $conn, int $userId): array
{
    $photoSelect = mobile_api_column_exists($conn, 'users', 'profile_photo') ? 'profile_photo' : 'NULL AS profile_photo';
    $emergencyNameSelect = mobile_api_column_exists($conn, 'users', 'emergency_contact_name')
        ? 'emergency_contact_name'
        : 'NULL AS emergency_contact_name';
    $emergencyPhoneSelect = mobile_api_column_exists($conn, 'users', 'emergency_contact_phone')
        ? 'emergency_contact_phone'
        : 'NULL AS emergency_contact_phone';
    $emergencySelect = mobile_api_column_exists($conn, 'users', 'emergency_contact')
        ? 'emergency_contact'
        : 'NULL AS emergency_contact';

    $stmt = mobile_api_prepare(
        $conn,
        "SELECT id,full_name,email,phone,address,status,otp_verified_at,created_at,
                {$photoSelect},{$emergencySelect},{$emergencyNameSelect},{$emergencyPhoneSelect}
         FROM users WHERE id=? AND role='client' LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        mobile_api_error(404, 'The client profile was not found.');
    }

    return [
        'id' => (int) $row['id'],
        'full_name' => (string) $row['full_name'],
        'email' => (string) $row['email'],
        'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
        'address' => $row['address'] !== null ? (string) $row['address'] : null,
        'emergency_contact' => $row['emergency_contact'] !== null ? (string) $row['emergency_contact'] : null,
        'emergency_contact_name' => $row['emergency_contact_name'] !== null ? (string) $row['emergency_contact_name'] : null,
        'emergency_contact_phone' => $row['emergency_contact_phone'] !== null ? (string) $row['emergency_contact_phone'] : null,
        'profile_photo' => $row['profile_photo'] !== null ? (string) $row['profile_photo'] : null,
        'profile_photo_url' => mobile_api_media_url($row['profile_photo'] ?? null),
        'status' => (string) $row['status'],
        'otp_verified' => !empty($row['otp_verified_at']),
        'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
    ];
}

function mobile_api_audit(
    mysqli $conn,
    ?int $actorId,
    string $action,
    string $entityType,
    ?int $entityId = null,
    string $details = ''
): void {
    if (!mobile_api_table_exists($conn, 'audit_logs')) {
        return;
    }
    $stmt = mobile_api_prepare(
        $conn,
        'INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)'
    );
    $stmt->bind_param('issis', $actorId, $action, $entityType, $entityId, $details);
    if (!$stmt->execute()) {
        error_log('Vetrix mobile API audit log failed: ' . $stmt->error);
    }
}

function mobile_api_unread_count(mysqli $conn, int $userId): int
{
    $stmt = mobile_api_prepare(
        $conn,
        "SELECT COUNT(*) AS total FROM notifications WHERE user_id=? AND status='unread'"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
}

function mobile_api_notify_user(
    mysqli $conn,
    int $userId,
    string $title,
    string $message,
    string $type = 'system'
): void {
    if (!mobile_api_table_exists($conn, 'notifications')) {
        return;
    }
    $allowedTypes = ['system', 'appointment', 'vaccine', 'record', 'qr', 'feedback'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'system';
    }
    $stmt = mobile_api_prepare(
        $conn,
        "INSERT INTO notifications(user_id,title,message,type,status) VALUES(?,?,?,?,'unread')"
    );
    $stmt->bind_param('isss', $userId, $title, $message, $type);
    if (!$stmt->execute()) {
        error_log('Vetrix mobile API notification failed: ' . $stmt->error);
    }
}

function mobile_api_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    try {
        $date = new DateTimeImmutable($value);
        $timezone = new DateTimeZone(date_default_timezone_get());
        return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}
