<?php
$host = getenv('VETRIX_DB_HOST') ?: 'localhost';
$user = getenv('VETRIX_DB_USER') ?: 'root';
$pass = getenv('VETRIX_DB_PASS') !== false ? getenv('VETRIX_DB_PASS') : '';
$db   = getenv('VETRIX_DB_NAME') ?: 'vetrix';
$port = (int)(getenv('VETRIX_DB_PORT') ?: 3306);

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    error_log('Vetrix database connection failed: ' . $conn->connect_error);
    http_response_code(503);
    die("<div style='max-width:680px;margin:48px auto;font-family:Inter;padding:24px;color:#7f1d1d;background:#fff;border:1px solid #fecaca;border-radius:18px;box-shadow:0 20px 50px rgba(15,23,42,.08)'><h2 style='margin-top:0'>Vetrix is temporarily unavailable</h2><p>The database connection could not be opened. Start MySQL and import <b>vetrix.sql</b>, then reload this page.</p></div>");
}

$conn->set_charset('utf8mb4');
$conn->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
?>
