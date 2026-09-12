<?php
require_once __DIR__ . '/../includes/http_mailer.php';
$checks = [
    [201, '{"messageId":"test-id"}', true],
    [201, '{}', false],
    [201, 'not json', false],
    [200, '{"messageId":"test-id"}', false],
    [401, '{"message":"secret must not be logged"}', false],
    [403, '{}', false],
    [429, '{}', false],
    [500, '{}', false],
];
foreach ($checks as [$status, $body, $expected]) {
    $result = brevo_mail_result($status, $body);
    if ($result['ok'] !== $expected || str_contains(json_encode($result), 'secret')) {
        throw new RuntimeException('Unexpected result for status ' . $status);
    }
}
echo "8 HTTPS mail response checks passed.\n";
