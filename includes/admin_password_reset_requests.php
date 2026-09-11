<?php
// Shared by the admin Users and Client Accounts pages.
require_role('admin');
require_once __DIR__ . '/mobile_password_reset.php';
$resetRows = [];
$resetQueueError = '';
try {
    ensure_mobile_password_reset_schema($conn);
    $resetRows = reset_db($conn, "SELECT r.*,u.full_name,u.email FROM mobile_password_reset_requests r JOIN users u ON u.id=r.user_id WHERE r.status='pending' ORDER BY r.requested_at ASC")->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $exception) {
    error_log('Password reset queue failed: ' . $exception->getMessage());
    $resetQueueError = 'Password reset requests are unavailable. Check the password reset migrations.';
}
$resetReturnPage = basename($_SERVER['SCRIPT_NAME']) === 'clients.php' ? 'clients' : 'users';
?>
<section id="password-reset-requests" class="user-admin-list-card mb-4" aria-labelledby="password-reset-heading">
    <div class="user-list-header">
        <div>
            <h3 id="password-reset-heading">Password reset requests <span class="badge text-bg-warning"><?=count($resetRows)?></span></h3>
            <p>Approve a request to email a 6-digit OTP. The client must verify it before changing their password. Codes expire 10 minutes after approval.</p>
        </div>
    </div>
    <?php if ($resetQueueError !== ''): ?>
        <div class="alert alert-danger"><?=e($resetQueueError)?></div>
    <?php elseif (!$resetRows): ?>
        <p class="text-muted mb-0">No password reset requests waiting for approval.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Client</th><th>Email</th><th>Requested</th><th>Review</th></tr></thead>
                <tbody>
                <?php foreach ($resetRows as $resetRow): ?>
                    <tr>
                        <td><?=e($resetRow['full_name'])?></td>
                        <td><?=e($resetRow['email'])?></td>
                        <td><?=e($resetRow['requested_at'])?></td>
                        <td>
                            <form method="POST" action="<?=e(app_url('admin/password_reset_review.php'))?>" class="d-flex flex-wrap gap-2">
                                <?=csrf_field()?>
                                <input type="hidden" name="user_id" value="<?=e($resetRow['user_id'])?>">
                                <input type="hidden" name="request_key" value="<?=e($resetRow['request_key'])?>">
                                <input type="hidden" name="return_page" value="<?=e($resetReturnPage)?>">
                                <button type="submit" class="btn btn-sm btn-primary" name="decision" value="approve">Approve &amp; send OTP</button>
                                <button type="submit" class="btn btn-sm btn-outline-danger" name="decision" value="reject">Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
