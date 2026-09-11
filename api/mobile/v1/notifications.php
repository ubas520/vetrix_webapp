<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method(['POST', 'PATCH']);

$auth = mobile_api_authenticate($conn);
$userId = (int) $auth['id'];
$input = mobile_api_input();
$action = strtolower(mobile_api_string($input, 'action', 'mark_read'));
$hasUpdatedAt = mobile_api_column_exists($conn, 'notifications', 'updated_at');
$updatedSql = $hasUpdatedAt ? ',updated_at=NOW()' : '';

if (in_array($action, ['mark_all', 'mark_all_read', 'read_all'], true)) {
    $stmt = mobile_api_prepare(
        $conn,
        "UPDATE notifications SET status='read'{$updatedSql}
         WHERE user_id=? AND status='unread' AND (title IS NULL OR title NOT LIKE '[Deleted] %')"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    mobile_api_success([
        'updated_count' => max(0, $stmt->affected_rows),
        'unread_count' => mobile_api_unread_count($conn, $userId),
    ], 200, 'All notifications were marked as read.');
}

if (!in_array($action, ['mark_read', 'mark_one', 'read'], true)) {
    mobile_api_error(400, 'Unknown notification action.');
}

$notificationId = (int) (
    $input['id']
    ?? $input['notification_id']
    ?? $input['notificationId']
    ?? 0
);
if ($notificationId < 1) {
    mobile_api_error(422, 'Choose a notification to update.', ['notification_id' => 'Notification ID is required.']);
}

$find = mobile_api_prepare(
    $conn,
    "SELECT id,title,message,type,status,created_at
     FROM notifications
     WHERE id=? AND user_id=? AND (title IS NULL OR title NOT LIKE '[Deleted] %') LIMIT 1"
);
$find->bind_param('ii', $notificationId, $userId);
$find->execute();
$notification = $find->get_result()->fetch_assoc();
if (!$notification) {
    mobile_api_error(404, 'The notification was not found.');
}

$update = mobile_api_prepare(
    $conn,
    "UPDATE notifications SET status='read'{$updatedSql} WHERE id=? AND user_id=?"
);
$update->bind_param('ii', $notificationId, $userId);
$update->execute();
$notification['id'] = (int) $notification['id'];
$notification['status'] = 'read';

mobile_api_success([
    'notification' => $notification,
    'unread_count' => mobile_api_unread_count($conn, $userId),
], 200, 'The notification was marked as read.');
