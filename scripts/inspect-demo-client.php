<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
if (!isset($conn) || $conn->connect_error) { exit(1); }
$result = $conn->query("SELECT id,role,status,otp_verified_at IS NOT NULL AS verified FROM users WHERE email='testaccclient123@gmail.com'");
echo json_encode(['client' => $result->fetch_assoc()], JSON_PRETTY_PRINT), PHP_EOL;
foreach (['pets','appointments','medical_records','vaccinations','notifications'] as $table) {
    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    echo $table, ': ', json_encode($result->fetch_all(MYSQLI_ASSOC)), PHP_EOL;
}
