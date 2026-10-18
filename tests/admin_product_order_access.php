<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/product_orders.php';
// Temporary tables keep the authorization checks isolated from clinic data.
order_db($conn, 'CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, role VARCHAR(30), status VARCHAR(30)) ENGINE=InnoDB');
order_db($conn, 'CREATE TEMPORARY TABLE product_orders (id INT PRIMARY KEY) ENGINE=InnoDB');
order_db($conn, "INSERT INTO users VALUES (1,'admin','active'),(2,'staff','active'),(3,'client','active'),(4,'veterinarian','active'),(5,'admin','inactive')");
foreach ([1=>404,2=>404,3=>403,4=>403,5=>403] as $actor=>$expected) {
    try { order_update($conn, $actor, 999, 'ready', [], true); throw new RuntimeException('Missing rejection'); }
    catch (ProductOrderError $error) {
        if ($error->getCode() !== $expected) throw new RuntimeException('Unexpected order access for actor '.$actor);
    }
}
echo "Order authorization passed: active admins/staff allowed; clients, vets and inactive admins denied.\n";
