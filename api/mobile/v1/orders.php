<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$method = mobile_api_require_method(['GET', 'POST']);
$user = mobile_api_authenticate($conn);
require_once dirname(__DIR__, 3) . '/includes/product_orders.php';
ensure_product_order_schema($conn);
try {
    if ($method === 'GET') {
        if (isset($_GET['id'])) mobile_api_success(['order' => order_get($conn, (int) $_GET['id'], $user['id'])]);
        mobile_api_success(['orders' => order_list($conn, $user['id']), 'payment_options' => order_payment_options($conn)]);
    }
    $input = mobile_api_input();
    $action = $input['action'] ?? 'create';
    if ($action === 'create') mobile_api_success(['order' => order_create($conn, $user['id'], $input)], 201, 'Your order was placed and staff were notified.');
    if ($action === 'cancel') mobile_api_success(['order' => order_update($conn, $user['id'], (int) ($input['id'] ?? 0), 'cancel')]);
    if ($action === 'proof') mobile_api_success(['order' => order_upload_proof($conn, $user['id'], (int) ($input['id'] ?? 0), (string) ($input['reference'] ?? ''), $_FILES['proof'] ?? [])], 200, 'Payment proof submitted for staff verification.');
    mobile_api_error(422, 'Unknown order action.');
} catch (ProductOrderError $e) {
    mobile_api_error($e->getCode() ?: 422, $e->getMessage());
}
