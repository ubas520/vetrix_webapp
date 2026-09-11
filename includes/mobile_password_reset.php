<?php
declare(strict_types=1);

function reset_db(mysqli $conn, string $sql, string $types = '', array $values = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Password reset database preparation failed.');
    if ($types !== '') $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) throw new RuntimeException('Password reset database operation failed.');
    return $stmt;
}

function ensure_mobile_password_reset_schema(mysqli $conn): void
{
    foreach (['mobile_password_resets', 'mobile_password_reset_requests'] as $table) {
        $exists = reset_db($conn, 'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', 's', [$table])->get_result()->fetch_row();
        if (!$exists && !$conn->query(file_get_contents(__DIR__ . '/../migrations/' . $table . '.sql'))) {
            throw new RuntimeException('Run the password reset migrations before using this feature.');
        }
    }
}

function send_password_reset_approval_email(mysqli $conn, array $user, string $code): bool
{
    require_once __DIR__ . '/functions.php';
    require_once __DIR__ . '/../config/mail.php';
    if (!APP_MAIL_SEND_REAL_EMAIL) throw new DomainException('Password reset email is not configured. Enable the clinic SMTP settings before approving.', 503);
    $sent = send_app_email($conn, $user['email'], $user['full_name'], 'Vetrix password reset approved - Your OTP',
        "The clinic admin approved your password reset request.\n\nYour password reset OTP is: {$code}\n\nThis code expires in 10 minutes and can only be used once. Enter it in the Vetrix app with your new password.\n\nIf you did not request this, contact the clinic.", 'client_password_reset_otp');
    return $sent && (get_last_mail_result()['status'] ?? '') === 'sent';
}

// Only the admin controller supplies review actions and the authenticated reviewer ID.
// The mobile endpoint permits request/reset exclusively. Schema is ensured before transactions.
function mobile_password_reset(mysqli $conn, string $email, string $action, string $otp = '', string $password = '', ?callable $send = null, int $adminId = 0, string $requestKey = ''): void
{
    if (!in_array($action, ['request', 'reset', 'approve', 'reject'], true)) throw new DomainException('Invalid password reset action.', 422);
    $conn->begin_transaction();
    try {
        if (in_array($action, ['approve', 'reject'], true)) {
            $admin = reset_db($conn, 'SELECT * FROM users WHERE id=?', 'i', [$adminId])->get_result()->fetch_assoc();
            if (!$admin || $admin['role'] !== 'admin' || !in_array($admin['status'], ['active', 'approved'], true) || !empty($admin['deleted_at'])) {
                throw new DomainException('Only an active admin can review password resets.', 403);
            }
        }
        // Serializes sends and resets for the same account, including first requests.
        $user = reset_db($conn, 'SELECT * FROM users WHERE email=? LIMIT 1 FOR UPDATE', 's', [$email])->get_result()->fetch_assoc();
        if (!$user || $user['role'] !== 'client' || $user['status'] !== 'active' || empty($user['otp_verified_at']) || !empty($user['deleted_at'])) {
            if ($action === 'request') { $conn->commit(); return; }
            throw new DomainException('Invalid or expired OTP. Request a new code.', 422);
        }
        $id = (int) $user['id'];
        $record = reset_db($conn, 'SELECT *,UNIX_TIMESTAMP(sent_at) AS sent_epoch,UNIX_TIMESTAMP(expires_at) AS expires_epoch,UNIX_TIMESTAMP(window_started_at) AS window_epoch FROM mobile_password_resets WHERE user_id=? FOR UPDATE', 'i', [$id])->get_result()->fetch_assoc();
        $request = reset_db($conn, 'SELECT *,UNIX_TIMESTAMP(requested_at) AS requested_epoch FROM mobile_password_reset_requests WHERE user_id=? FOR UPDATE', 'i', [$id])->get_result()->fetch_assoc();
        $now = time();
        $inWindow = $record && (int) $record['window_epoch'] > $now - 3600;
        $limited = $record && ((int) $record['sent_epoch'] > $now - 60 || ($inWindow && ((int) $record['send_count'] >= 5 || (int) $record['attempts'] >= 10)));
        if ($action === 'request') {
            if ($limited || ($request && ($request['status'] === 'pending' || (int) $request['requested_epoch'] > $now - 60))) {
                $conn->commit(); return;
            }
            // Queue only. No OTP is generated or emailed until an admin approves.
            $key = bin2hex(random_bytes(16));
            reset_db($conn, "INSERT INTO mobile_password_reset_requests(user_id,request_key,status,requested_at) VALUES(?,?,'pending',NOW()) ON DUPLICATE KEY UPDATE request_key=VALUES(request_key),status='pending',requested_at=NOW(),reviewed_at=NULL,reviewed_by=NULL", 'is', [$id, $key]);
            $conn->commit(); return;
        }
        if (in_array($action, ['approve', 'reject'], true)) {
            if (!$request || $request['status'] !== 'pending' || !hash_equals($request['request_key'], $requestKey)) {
                throw new DomainException('This request was already reviewed or replaced. Reload the account page.', 409);
            }
            if ($action === 'reject') {
                reset_db($conn, "UPDATE mobile_password_reset_requests SET status='rejected',reviewed_at=NOW(),reviewed_by=? WHERE user_id=?", 'ii', [$adminId, $id]);
                $conn->commit(); return;
            }
            if ($limited) throw new DomainException('The OTP request limit was reached. Try again later.', 429);
            $code = (string) random_int(100000, 999999);
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $expires = $now + 600;
            $window = $inWindow ? (int) $record['window_epoch'] : $now;
            $count = $inWindow ? (int) $record['send_count'] + 1 : 1;
            $attempts = $inWindow ? (int) $record['attempts'] : 0;
            reset_db($conn, 'INSERT INTO mobile_password_resets(user_id,otp_hash,expires_at,sent_at,window_started_at,send_count,attempts) VALUES(?,?,FROM_UNIXTIME(?),FROM_UNIXTIME(?),FROM_UNIXTIME(?),?,?) ON DUPLICATE KEY UPDATE otp_hash=VALUES(otp_hash),expires_at=VALUES(expires_at),sent_at=VALUES(sent_at),window_started_at=VALUES(window_started_at),send_count=VALUES(send_count),attempts=VALUES(attempts),used_at=NULL', 'isiiiii', [$id, $hash, $expires, $now, $window, $count, $attempts]);
            if (!$send || !$send($user, $code)) throw new DomainException('Unable to send the reset email. Please try again later.', 503);
            reset_db($conn, "UPDATE mobile_password_reset_requests SET status='approved',reviewed_at=NOW(),reviewed_by=? WHERE user_id=?", 'ii', [$adminId, $id]);
        } else {
            if (!$request || $request['status'] !== 'approved') {
                throw new DomainException('An admin must approve your password reset request before you can use an OTP. Contact the clinic if you need help.', 422);
            }
            if (!$record || $record['used_at'] !== null || (int) $record['expires_epoch'] <= $now || (int) $record['attempts'] >= 10) {
                throw new DomainException('Invalid or expired OTP. Request a new code, or try again in an hour if you reached the attempt limit.', 422);
            }
            if (!password_verify($otp, $record['otp_hash'])) {
                reset_db($conn, 'UPDATE mobile_password_resets SET attempts=attempts+1 WHERE user_id=?', 'i', [$id]);
                $conn->commit(); // Persist failed attempts even though verification fails.
                throw new DomainException('Incorrect OTP. Check the code in your email.', 422);
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            reset_db($conn, 'UPDATE users SET password=? WHERE id=?', 'si', [$hash, $id]);
            reset_db($conn, 'UPDATE mobile_password_resets SET used_at=NOW() WHERE user_id=?', 'i', [$id]);
            reset_db($conn, 'UPDATE mobile_api_tokens SET revoked_at=COALESCE(revoked_at,NOW()) WHERE user_id=?', 'i', [$id]);
            reset_db($conn, "UPDATE mobile_password_reset_requests SET status='completed' WHERE user_id=?", 'i', [$id]);
        }
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
