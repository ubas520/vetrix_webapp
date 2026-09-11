<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('POST');

$input = mobile_api_input();
$email = mobile_api_normalize_email(mobile_api_string($input, 'email'));
$password = (string) ($input['password'] ?? '');
$deviceName = mobile_api_string($input, 'device_name');
if ($deviceName === '') {
    $deviceName = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Vetrix mobile client'));
}

$errors = [];
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Enter a valid email address.';
}
if ($password === '') {
    $errors['password'] = 'Enter your password.';
}
if ($errors !== []) {
    mobile_api_error(422, 'Check the highlighted fields.', $errors);
}

$hasAttempts = mobile_api_column_exists($conn, 'users', 'failed_login_attempts');
$hasLockedUntil = mobile_api_column_exists($conn, 'users', 'locked_until');
$hasDeletedAt = mobile_api_column_exists($conn, 'users', 'deleted_at');
$hasLastLogin = mobile_api_column_exists($conn, 'users', 'last_login_at');

$attemptsSelect = $hasAttempts ? 'failed_login_attempts' : '0 AS failed_login_attempts';
$lockedSelect = $hasLockedUntil ? 'locked_until' : 'NULL AS locked_until';
$deletedSelect = $hasDeletedAt ? 'deleted_at' : 'NULL AS deleted_at';

$stmt = mobile_api_prepare(
    $conn,
    "SELECT id,full_name,email,password,role,status,otp_verified_at,
            {$attemptsSelect},{$lockedSelect},{$deletedSelect}
     FROM users WHERE email=? LIMIT 1"
);
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && !empty($user['deleted_at'])) {
    mobile_api_error(403, 'This account is no longer available. Contact the clinic.');
}
if ($user && !empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
    mobile_api_error(423, 'Too many unsuccessful attempts. Try again later.');
}

$validPassword = $user && password_verify($password, (string) $user['password']);
if (!$validPassword) {
    if ($user && $hasAttempts) {
        $attempts = (int) $user['failed_login_attempts'] + 1;
        if ($hasLockedUntil) {
            $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
            $update = mobile_api_prepare(
                $conn,
                'UPDATE users SET failed_login_attempts=?,locked_until=? WHERE id=?'
            );
            $userId = (int) $user['id'];
            $update->bind_param('isi', $attempts, $lockedUntil, $userId);
            $update->execute();
            if ($attempts >= 5) {
                mobile_api_error(423, 'Too many unsuccessful attempts. This account is locked for 15 minutes.');
            }
        } else {
            $update = mobile_api_prepare($conn, 'UPDATE users SET failed_login_attempts=? WHERE id=?');
            $userId = (int) $user['id'];
            $update->bind_param('ii', $attempts, $userId);
            $update->execute();
        }
    }
    mobile_api_error(401, 'The email or password is incorrect.');
}

if (($user['role'] ?? '') !== 'client') {
    mobile_api_error(403, 'Use a client account to sign in to the Vetrix mobile app.');
}

$status = (string) ($user['status'] ?? '');
if ($status === 'pending') {
    mobile_api_error(403, 'Your account is waiting for clinic approval.');
}
if ($status === 'approved' && empty($user['otp_verified_at'])) {
    mobile_api_error(403, 'Your account still needs OTP verification.');
}
if ($status !== 'active' || empty($user['otp_verified_at'])) {
    mobile_api_error(403, 'This client account is not active and verified. Contact the clinic.');
}

mobile_api_ensure_token_table($conn);
$userId = (int) $user['id'];
$resetParts = [];
if ($hasAttempts) {
    $resetParts[] = 'failed_login_attempts=0';
}
if ($hasLockedUntil) {
    $resetParts[] = 'locked_until=NULL';
}
if ($hasLastLogin) {
    $resetParts[] = 'last_login_at=NOW()';
}

$conn->begin_transaction();
try {
    if ($resetParts !== []) {
        $reset = mobile_api_prepare($conn, 'UPDATE users SET ' . implode(',', $resetParts) . ' WHERE id=?');
        $reset->bind_param('i', $userId);
        $reset->execute();
    }

    $issued = mobile_api_issue_token($conn, $userId, $deviceName);
    mobile_api_audit($conn, $userId, 'Client signed in through mobile API', 'user', $userId, 'Bearer token issued.');
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

mobile_api_success([
    'access_token' => $issued['token'],
    'token_type' => $issued['token_type'],
    'expires_at' => $issued['expires_at'],
    'user' => mobile_api_profile_payload($conn, $userId),
], 200, 'Signed in successfully.');

