<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../includes/appointment_feedback.php';
mobile_api_require_method('POST');
$auth = mobile_api_authenticate($conn);
if (!mobile_api_column_exists($conn, 'feedback', 'appointment_id')) {
    mobile_api_error(503, 'Visit ratings are temporarily unavailable. Please try again later.');
}
try {
    $feedback = appointment_feedback_submit($conn, (int) $auth['id'], mobile_api_input());
} catch (DomainException $exception) {
    mobile_api_error($exception->getCode(), $exception->getMessage());
}
mobile_api_success(['feedback' => $feedback], 200, 'Thank you for rating your visit.');
