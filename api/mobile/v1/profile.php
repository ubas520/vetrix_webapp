<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method(['POST', 'PATCH']);

$auth = mobile_api_authenticate($conn);
$userId = (int) $auth['id'];
$input = mobile_api_input();

$currentStmt = mobile_api_prepare(
    $conn,
    "SELECT full_name,email,phone,address FROM users WHERE id=? AND role='client' LIMIT 1"
);
$currentStmt->bind_param('i', $userId);
$currentStmt->execute();
$current = $currentStmt->get_result()->fetch_assoc();
if (!$current) {
    mobile_api_error(404, 'The client profile was not found.');
}

$hasName = array_key_exists('full_name', $input) || array_key_exists('fullName', $input) || array_key_exists('name', $input);
$hasEmail = array_key_exists('email', $input);
$hasPhone = array_key_exists('phone', $input);
$hasAddress = array_key_exists('address', $input);
if (!$hasName && !$hasEmail && !$hasPhone && !$hasAddress) {
    mobile_api_error(422, 'Provide at least one profile field to update.');
}

$fullName = $hasName
    ? mobile_api_string(
        $input,
        array_key_exists('full_name', $input) ? 'full_name' : (array_key_exists('fullName', $input) ? 'fullName' : 'name')
    )
    : (string) $current['full_name'];
$fullName = trim((string) preg_replace('/\s+/u', ' ', $fullName));
$email = $hasEmail ? mobile_api_normalize_email(mobile_api_string($input, 'email')) : (string) $current['email'];
$phone = $hasPhone ? mobile_api_string($input, 'phone') : (string) ($current['phone'] ?? '');
$address = $hasAddress ? mobile_api_string($input, 'address') : (string) ($current['address'] ?? '');

$errors = [];
if (mobile_api_text_length($fullName) < 2 || mobile_api_text_length($fullName) > 120) {
    $errors['full_name'] = 'Use a name between 2 and 120 characters.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mobile_api_text_length($email) > 120) {
    $errors['email'] = 'Enter a valid email address up to 120 characters.';
}
if (mobile_api_text_length($phone) > 30 || ($phone !== '' && !preg_match('/^[0-9+() .\-]+$/', $phone))) {
    $errors['phone'] = 'Enter a valid phone number up to 30 characters.';
}
if (mobile_api_text_length($address) > 255) {
    $errors['address'] = 'Keep the address under 256 characters.';
}
if ($errors !== []) {
    mobile_api_error(422, 'Check the profile details.', $errors);
}

$duplicate = mobile_api_prepare($conn, 'SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
$duplicate->bind_param('si', $email, $userId);
$duplicate->execute();
if ($duplicate->get_result()->fetch_assoc()) {
    mobile_api_error(409, 'That email address is already registered.', ['email' => 'Use another email address.']);
}

$update = mobile_api_prepare(
    $conn,
    "UPDATE users SET full_name=?,email=?,phone=?,address=? WHERE id=? AND role='client'"
);
$update->bind_param('ssssi', $fullName, $email, $phone, $address, $userId);
$update->execute();

mobile_api_audit(
    $conn,
    $userId,
    'Client updated profile through mobile API',
    'user',
    $userId,
    'Name, email, phone, or address was updated.'
);

mobile_api_success([
    'profile' => mobile_api_profile_payload($conn, $userId),
], 200, 'Your profile was updated.');

