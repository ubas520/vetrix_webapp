<?php
declare(strict_types=1);
// Recipient QR images are intended for customers; random immutable URLs avoid
// sequential enumeration and preserve the recipient shown on existing orders.
require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('GET');
require_once dirname(__DIR__, 3) . '/includes/product_orders.php';
ensure_product_order_schema($conn);
$token = (string) ($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $token)) { http_response_code(404); exit; }
$qr = order_db($conn, 'SELECT qr_data,qr_mime FROM product_payment_accounts WHERE qr_token=?', 's', [$token])->get_result()->fetch_assoc();
if (!$qr || !$qr['qr_data']) { http_response_code(404); exit; }
header('Content-Type: ' . $qr['qr_mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
echo $qr['qr_data'];
