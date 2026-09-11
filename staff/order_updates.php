<?php
require_once '../config/database.php'; require_once '../includes/functions.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!is_logged_in() || ($_SESSION['role'] ?? '') !== 'staff') { http_response_code(401); echo '{}'; exit; }
require_once '../includes/product_orders.php'; ensure_product_order_schema($conn);
$latest = order_db($conn, "SELECT MAX(n.id) id FROM notifications n JOIN product_order_notifications p ON p.notification_id=n.id WHERE n.user_id=? AND n.status='unread'", 'i', [(int) current_user_id()])->get_result()->fetch_assoc();
echo json_encode(['count' => get_nav_notification_count($conn), 'latest_order_notification' => (int) ($latest['id'] ?? 0)]);
