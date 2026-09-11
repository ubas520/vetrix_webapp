<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('POST');
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/mobile_password_reset.php';

$input = mobile_api_input();
$email = mobile_api_normalize_email(mobile_api_string($input, 'email'));
$action = mobile_api_string($input, 'action');
$otp = mobile_api_string($input, 'otp');
$password = is_string($input['password'] ?? null) ? $input['password'] : '';
$confirmation = is_string($input['confirmPassword'] ?? null) ? $input['confirmPassword'] : '';
$errors = [];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) $errors['email'] = 'Enter a valid email address.';
if (!in_array($action, ['request', 'reset'], true)) mobile_api_error(422, 'Invalid password reset action.');
if ($action === 'reset') {
    if (!preg_match('/^\d{6}$/', $otp)) $errors['otp'] = 'Enter the 6-digit OTP from your reset email.';
    $passwordErrors = mobile_api_password_errors($password);
    if (strlen($password) > 72) $passwordErrors[] = 'Use at most 72 bytes for your password.';
    if ($passwordErrors) $errors['password'] = implode(' ', $passwordErrors);
    if (!hash_equals($password, $confirmation)) $errors['confirmPassword'] = 'The passwords do not match.';
}
if ($errors) mobile_api_error(422, 'Check the highlighted fields.', $errors);

ensure_mobile_password_reset_schema($conn);
mobile_api_ensure_token_table($conn);
try {
    mobile_password_reset($conn, $email, $action, $otp, $password);
} catch (DomainException $exception) {
    mobile_api_error($exception->getCode(), $exception->getMessage(), $exception->getCode() === 422 ? ['otp' => $exception->getMessage()] : []);
}
mobile_api_success(['status' => $action === 'request' ? 'pending' : 'completed'], 200, $action === 'request'
    ? 'If this email belongs to an active, verified client account, your request is waiting for admin approval. The clinic will email a reset OTP after approval. Repeated requests do not speed up approval. Enter the latest approved OTP below when it arrives.'
    : 'Your password has been reset. Sign in with your new password.');
