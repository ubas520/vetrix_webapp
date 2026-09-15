<?php
// Credentials belong in sms.local.php (ignored by Git), never in browser code.
$localFile = __DIR__ . '/sms.local.php';
$local = is_file($localFile) ? require $localFile : [];
return array_replace([
    'enabled' => false,
    'username' => '',
    'password' => '',
    'base_url' => 'https://api.sms-gate.app',
    'status_file' => dirname(__DIR__) . '/artifacts/gsm/status.json',
], is_array($local) ? $local : []);
