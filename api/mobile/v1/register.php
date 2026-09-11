<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('POST');

$input = mobile_api_input();
$fullName = mobile_api_string(
    $input,
    array_key_exists('full_name', $input) ? 'full_name' : (array_key_exists('fullName', $input) ? 'fullName' : 'name')
);
$fullName = trim((string) preg_replace('/\s+/u', ' ', $fullName));
$email = mobile_api_normalize_email(mobile_api_string($input, 'email'));
$phone = mobile_api_string($input, 'phone');
$address = mobile_api_string($input, 'address');
$password = (string) ($input['password'] ?? '');
$confirmation = (string) ($input['confirm_password'] ?? $input['confirmPassword'] ?? '');

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
$passwordErrors = mobile_api_password_errors($password);
if ($passwordErrors !== []) {
    $errors['password'] = implode(' ', $passwordErrors);
}
if ($confirmation !== '' && !hash_equals($password, $confirmation)) {
    $errors['confirm_password'] = 'The passwords do not match.';
}
if ($errors !== []) {
    mobile_api_error(422, 'Check the registration details.', $errors);
}

$duplicate = mobile_api_prepare($conn, 'SELECT id FROM users WHERE email=? LIMIT 1');
$duplicate->bind_param('s', $email);
$duplicate->execute();
if ($duplicate->get_result()->fetch_assoc()) {
    mobile_api_error(409, 'That email address is already registered.', ['email' => 'Sign in or use another email address.']);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$role = 'client';
$source = 'self_registered';
$status = 'pending';
$createdBy = null;

$conn->begin_transaction();
try {
    $insert = mobile_api_prepare(
        $conn,
        'INSERT INTO users(full_name,email,phone,address,password,role,account_source,created_by,status)
         VALUES(?,?,?,?,?,?,?,?,?)'
    );
    $insert->bind_param(
        'sssssssis',
        $fullName,
        $email,
        $phone,
        $address,
        $passwordHash,
        $role,
        $source,
        $createdBy,
        $status
    );
    if (!$insert->execute()) {
        if ((int) $insert->errno === 1062) {
            throw new DomainException('duplicate_email');
        }
        throw new RuntimeException('Client registration failed: ' . $insert->error);
    }
    $userId = (int) $conn->insert_id;

    mobile_api_audit(
        $conn,
        null,
        'Client self-registered through mobile API',
        'user',
        $userId,
        'New client account is waiting for administrator approval.'
    );

    $adminWhere = "role='admin' AND status IN ('active','approved')";
    if (mobile_api_column_exists($conn, 'users', 'deleted_at')) {
        $adminWhere .= ' AND deleted_at IS NULL';
    }
    $admins = $conn->query("SELECT id FROM users WHERE {$adminWhere}");
    while ($admins && $admin = $admins->fetch_assoc()) {
        mobile_api_notify_user(
            $conn,
            (int) $admin['id'],
            'Client Account Pending',
            $fullName . ' submitted a client registration for review.',
            'system'
        );
    }

    $conn->commit();
} catch (DomainException $exception) {
    $conn->rollback();
    if ($exception->getMessage() === 'duplicate_email') {
        mobile_api_error(409, 'That email address is already registered.', ['email' => 'Sign in or use another email address.']);
    }
    throw $exception;
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

mobile_api_success([
    'status' => 'pending',
    'registration' => [
        'id' => $userId,
        'email' => $email,
        'status' => 'pending',
        'next_step' => 'Wait for clinic approval and the one-time OTP email.',
    ],
], 201, 'Your registration was submitted for clinic approval.');
