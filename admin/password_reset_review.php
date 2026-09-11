<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
verify_csrf_or_fail();
require_once '../includes/mobile_password_reset.php';

$returnPage = ($_POST['return_page'] ?? '') === 'clients' ? 'admin/clients.php' : 'admin/users.php';
$action = $_POST['decision'] ?? '';
if (!is_string($action) || !in_array($action, ['approve', 'reject'], true)) { http_response_code(422); exit('Invalid review action.'); }
$key = $_POST['request_key'] ?? '';
if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/', $key)) { http_response_code(422); exit('Invalid request.'); }
try {
    ensure_mobile_password_reset_schema($conn);
    $user = reset_db($conn, 'SELECT email FROM users WHERE id=?', 'i', [(int) ($_POST['user_id'] ?? 0)])->get_result()->fetch_assoc();
    if (!$user) throw new DomainException('Client account not found.', 404);
    mobile_password_reset($conn, $user['email'], $action, '', '',
        static fn(array $client, string $otp): bool => send_password_reset_approval_email($conn, $client, $otp),
        (int) current_user_id(), $key);
    log_action($conn, 'Admin ' . ($action === 'approve' ? 'approved' : 'rejected') . ' password reset request', 'user', (int) $_POST['user_id'], 'Request: ' . $key);
    flash('success', $action === 'approve' ? 'Password reset approved. The OTP email was sent; the client must enter it to change their password.' : 'Password reset request rejected. No OTP was sent.');
} catch (DomainException $exception) {
    flash('error', $exception->getMessage());
} catch (Throwable $exception) {
    error_log('Password reset review failed: ' . $exception->getMessage());
    flash('error', 'Unable to review the password reset request. Reload the page and try again.');
}
redirect_to($returnPage . '#password-reset-requests');
