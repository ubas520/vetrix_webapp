<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/product_orders.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$locked = false;
try {
    $locked = (int) $conn->query("SELECT GET_LOCK('vetrix_payment_accounts', 60)")->fetch_row()[0] === 1;
    if (!$locked) throw new RuntimeException('Could not lock payment migration.');
    ensure_product_order_schema($conn);
    echo "Payment account schema is ready. Existing orders and recipients retained.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Payment migration failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($locked) $conn->query("SELECT RELEASE_LOCK('vetrix_payment_accounts')");
}
