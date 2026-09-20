<?php
// Read-only integration check against the configured local database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$admin = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch_assoc();
if (!$admin) { fwrite(STDERR, "An active local administrator is required.\n"); exit(1); }
$_SESSION['user_id'] = (int)$admin['id'];
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SERVER['REQUEST_METHOD'] = 'POST';
$testCase = $argv[1] ?? 'analytics';
$_POST = ['kind' => $testCase, 'csrf_token' => $_SESSION['csrf_token'], 'from' => date('Y-m-01'), 'to' => date('Y-m-t'), 'days' => '60', 'pet_id' => '0', 'token' => 'invalid-test-token'];
if ($testCase === 'invalid_dates') { $_POST['kind'] = 'appointments'; $_POST['from'] = '2026-02-30'; }
chdir(__DIR__ . '/../admin');
ob_start();
require 'report_chart_data.php';
$output = ob_get_clean();
$data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
$expectsError = in_array($testCase, ['pet', 'qr', 'invalid_dates'], true);
if ($expectsError ? (http_response_code() !== 422 || empty($data['error'])) : (!isset($data['labels'], $data['datasets']) || isset($data['error']))) {
    fwrite(STDERR, "FAIL $testCase: $output\n"); exit(1);
}
foreach ($data['datasets'] ?? [] as $set) {
    if (count($set['data']) !== count($data['labels'])) { fwrite(STDERR, "Mismatched chart labels.\n"); exit(1); }
}
session_destroy();
echo "PASS $testCase\n";
