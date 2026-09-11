<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('POST');

$functionsFile = dirname(__DIR__, 3) . '/includes/functions.php';
if (!is_file($functionsFile)) {
    mobile_api_error(503, 'The account verification service is unavailable.');
}
require_once $functionsFile;

if (!function_exists('verify_client_otp')) {
    mobile_api_error(503, 'The account verification service is unavailable.');
}

$input = mobile_api_input();
$email = mobile_api_normalize_email(mobile_api_string($input, 'email'));
$otp = mobile_api_string($input, 'otp');
$errors = [];
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Enter the email address used for registration.';
}
if (!preg_match('/^\d{6}$/', $otp)) {
    $errors['otp'] = 'Enter the six-digit OTP from the clinic email.';
}
if ($errors !== []) {
    mobile_api_error(422, 'Check the verification details.', $errors);
}

$deletedSelect = mobile_api_column_exists($conn, 'users', 'deleted_at') ? 'deleted_at' : 'NULL AS deleted_at';
$lockedSelect = mobile_api_column_exists($conn, 'users', 'locked_until') ? 'locked_until' : 'NULL AS locked_until';
$stmt = mobile_api_prepare(
    $conn,
    "SELECT id,role,status,otp_verified_at,{$deletedSelect},{$lockedSelect}
     FROM users WHERE email=? LIMIT 1"
);
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user || ($user['role'] ?? '') !== 'client') {
    mobile_api_error(404, 'No client verification request was found for that email.');
}
if (!empty($user['deleted_at'])) {
    mobile_api_error(403, 'This account is no longer available. Contact the clinic.');
}
if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
    mobile_api_error(423, 'This account is temporarily locked. Try again later.');
}
if (($user['status'] ?? '') === 'active' && !empty($user['otp_verified_at'])) {
    mobile_api_success([
        'status' => 'active',
        'verification' => [
            'email' => $email,
            'status' => 'active',
            'otp_verified' => true,
        ],
    ], 200, 'This account is already verified. Sign in to continue.');
}
if (!in_array((string) ($user['status'] ?? ''), ['approved', 'active'], true)) {
    mobile_api_error(403, 'The clinic must approve this account before OTP verification.');
}

$userId = (int) $user['id'];
$result = verify_client_otp($conn, $userId, $otp);
if (empty($result['ok'])) {
    $message = trim((string) ($result['message'] ?? 'The OTP could not be verified.'));
    $status = stripos($message, 'expired') !== false ? 410 : 422;
    mobile_api_error($status, $message, ['otp' => $message]);
}

// The newer website helper already activates the account. The staged workspace
// helper leaves it approved, so normalize both versions to the mobile contract.
$activate = mobile_api_prepare(
    $conn,
    "UPDATE users SET status='active' WHERE id=? AND role='client' AND otp_verified_at IS NOT NULL"
);
$activate->bind_param('i', $userId);
$activate->execute();
mobile_api_audit(
    $conn,
    $userId,
    'Client verified OTP through mobile API',
    'user',
    $userId,
    'Account activation OTP was accepted.'
);

mobile_api_success([
    'status' => 'active',
    'verification' => [
        'email' => $email,
        'status' => 'active',
        'otp_verified' => true,
    ],
], 200, 'Your account is verified. Sign in to continue.');
