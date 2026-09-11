<?php
// Configure on the server, never in EXPO_PUBLIC variables.
$paymentConfig = [
    'gcash_name' => trim((string) getenv('VETRIX_GCASH_NAME')),
    'gcash_number' => trim((string) getenv('VETRIX_GCASH_NUMBER')),
];
if (is_file(__DIR__ . '/payments.local.php')) {
    $paymentConfig = array_replace($paymentConfig, require __DIR__ . '/payments.local.php');
}
return $paymentConfig;
