<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_role('staff');
require_once '../includes/product_orders.php';
ensure_product_order_schema($conn);
$proof = order_db($conn, 'SELECT image_data,image_mime FROM product_order_proofs WHERE id=?', 'i', [(int) ($_GET['id'] ?? 0)])->get_result()->fetch_assoc();
if (!$proof) { http_response_code(404); exit('Payment proof not found.'); }
header('Content-Type: ' . $proof['image_mime']);
header('Content-Length: ' . strlen($proof['image_data']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
echo $proof['image_data'];
