<?php
require_once __DIR__ . '/../includes/sms.php';
set_error_handler(function ($severity, $message) { throw new RuntimeException($message); });
$path = tempnam(sys_get_temp_dir(), 'vetrix_sms_');
$config = ['enabled' => true, 'username' => 'test', 'password' => 'test', 'base_url' => 'https://api.sms-gate.app', 'status_file' => $path];
$calls = 0;
$transport = function ($config, $payload) use (&$calls) {
    $calls++;
    if ($payload !== ['textMessage' => ['text' => 'Test'], 'phoneNumbers' => ['+639171234567']]) throw new RuntimeException('Wrong SMSGate payload');
    return ['status' => 202, 'body' => '{"id":"test-message","state":"Pending"}'];
};
function check($condition, $label) { if (!$condition) throw new RuntimeException($label); }
function state($path, $connected, $heartbeatAge = 0, $responseAge = 0) {
    file_put_contents($path, json_encode(['connected' => $connected,
        'updated_at' => microtime(true) - $heartbeatAge, 'last_response_at' => microtime(true) - $responseAge]));
}
try {
    check(vetrix_sms_notification_type('appointment'), 'Appointments must use SMS');
    check(vetrix_sms_notification_type('vaccine'), 'Vaccinations must use SMS');
    foreach (['system','record','qr','feedback','client_approval_otp'] as $type) {
        check(!vetrix_sms_notification_type($type), 'Other notifications must not use SMS');
    }
    foreach (['', 'invalid', '{}'] as $raw) {
        file_put_contents($path, $raw);
        check(!vetrix_sms_send('09171234567', 'Test', $config, $transport)['accepted'], 'Malformed status must block');
    }
    foreach ([[false,0,0], [true,3,0], [true,0,13], [true,-10,0], [true,0,-10]] as $args) {
        state($path, ...$args);
        check(!vetrix_sms_send('09171234567', 'Test', $config, $transport)['accepted'], 'Unavailable hardware must block');
    }
    check($calls === 0, 'Blocked requests must never call provider');
    state($path, true);
    check(vetrix_sms_send('09171234567', 'Test', $config, $transport)['accepted'], 'Fresh response should enable API');
    state($path, false);
    check(!vetrix_sms_send('09171234567', 'Test', $config, $transport)['accepted'], 'Disconnect must block');
    state($path, true);
    check(vetrix_sms_send('09171234567', 'Test', $config, $transport)['accepted'], 'Reconnect should enable API');
    $config['enabled'] = false;
    check(!vetrix_sms_send('09171234567', 'Test', $config, $transport)['accepted'], 'Disabled config must block');
    $config['enabled'] = true;
    check(!vetrix_sms_send('bad number', 'Test', $config, $transport)['accepted'], 'Invalid number must block');
    check($calls === 2, 'Only allowed sends should call provider');
    foreach (['http://api.sms-gate.app', 'https://api.sms-gate.app.evil.test', 'https://user@api.sms-gate.app', 'https://api.sms-gate.app/path'] as $badUrl) {
        $badConfig = array_replace($config, ['base_url' => $badUrl]);
        check(!vetrix_sms_send('09171234567', 'Test', $badConfig, $transport)['accepted'], 'Invalid API origin must block');
    }
    check($calls === 2, 'Invalid origins must not call provider');
    foreach (['{"id":"failed","state":"Failed"}', '{"state":"Pending"}'] as $body) {
        check(!vetrix_sms_send('09171234567', 'Test', $config, fn() => ['status' => 200, 'body' => $body])['accepted'], 'HTTP success alone must not mean accepted');
    }
    foreach ([[401,'{}'], [500,'{}'], [202,'invalid'], [202,'{}']] as [$status,$body]) {
        check(!vetrix_sms_send('09171234567', 'Test', $config, fn() => ['status' => $status, 'body' => $body])['accepted'], 'Provider failure must not claim acceptance');
    }
    echo "PASS: gate, expiry, disconnect/reconnect, configuration, validation, provider responses. No SMS sent.\n";
} finally { unlink($path); }
