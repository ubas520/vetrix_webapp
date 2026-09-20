<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/product_orders.php';

function payment_check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// Connection-local tables shadow live tables. No clinic records are modified.
$schemas = [
    'users' => 'id INT PRIMARY KEY,role VARCHAR(30),status VARCHAR(30)',
    'audit_logs' => 'id INT AUTO_INCREMENT PRIMARY KEY,actor_user_id INT,action VARCHAR(160),entity_type VARCHAR(80),entity_id INT,details TEXT',
    'product_payment_settings' => 'id INT PRIMARY KEY,gcash_name VARCHAR(120),gcash_number VARCHAR(40)',
    'product_payment_accounts' => 'id INT AUTO_INCREMENT PRIMARY KEY,method VARCHAR(10),provider VARCHAR(80),account_name VARCHAR(120),account_number VARCHAR(40),qr_token CHAR(48),qr_data MEDIUMBLOB,qr_mime VARCHAR(30),enabled TINYINT DEFAULT 1,created_by INT',
    'product_order_recipients' => 'order_id INT PRIMARY KEY,account_id INT',
];
try {
    foreach ($schemas as $table => $columns) order_db($conn, "CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB");
    order_db($conn, "INSERT INTO users VALUES(1,'staff','active'),(2,'client','active')");
    order_db($conn, "INSERT INTO product_payment_settings VALUES(1,'Legacy test clinic','09170000000')");
    $input = ['method'=>'gcash','account_name'=>'Test clinic','account_number'=>'09171111111','confirmed'=>'yes'];
    $gcashId = order_save_account($conn, 1, $input);
    $options = order_payment_options($conn);
    payment_check($options['gcash']['enabled'] && $options['gcash']['id'] === $gcashId, 'Saved GCash missing from mobile options');
    payment_check(count($options['accounts']) === 1 && $options['clinic'], 'Unexpected checkout options');
    payment_check((int)order_db($conn, 'SELECT COUNT(*) c FROM audit_logs')->get_result()->fetch_assoc()['c'] === 1, 'Legacy audit entry was not retained');

    // Replacement must not change the recipient on an existing order.
    order_db($conn, 'INSERT INTO product_order_recipients VALUES(99,?)', 'i', [$gcashId]);
    $newId = order_save_account($conn, 1, array_replace($input, ['replace_id'=>$gcashId,'account_number'=>'09172222222']));
    payment_check(order_recipient($conn, 99)['account_number'] === '09171111111', 'Existing order recipient changed');
    payment_check(order_payment_options($conn)['gcash']['id'] === $newId, 'Mobile options retained replaced account');
    order_disable_account($conn, 1, $newId);
    payment_check(!order_payment_options($conn)['gcash']['enabled'], 'Disabled GCash incorrectly fell back to legacy account');

    // The same save path must retain structured events after migration.
    order_db($conn, 'ALTER TABLE audit_logs ADD COLUMN event_data JSON NULL');
    $bankId = order_save_account($conn, 1, ['method'=>'bank','provider'=>'Test Bank','account_name'=>'Test clinic','account_number'=>'1234567890','confirmed'=>'yes']);
    $accounts = order_payment_options($conn)['accounts'];
    payment_check(count($accounts) === 1 && $accounts[0]['id'] === $bankId && $accounts[0]['method'] === 'bank', 'Bank missing from mobile options');
    $event = order_db($conn, 'SELECT event_data FROM audit_logs ORDER BY id DESC LIMIT 1')->get_result()->fetch_assoc();
    payment_check(is_array(json_decode($event['event_data'], true)), 'Structured audit event missing');
    try { order_save_account($conn, 2, $input); throw new RuntimeException('Client saved a payment account'); }
    catch (ProductOrderError $error) { payment_check($error->getCode() === 403, 'Wrong authorization error'); }
    payment_check(count(order_accounts($conn)) === 1, 'Rejected save changed payment options');
    echo "Payment regression checks passed: legacy/new audit schemas, GCash and bank options, replacements, disabled accounts, and staff authorization.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Payment regression check failed: ' . $error->getMessage() . "\n");
    exit(1);
}
