<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_role('staff');
require_once '../includes/product_orders.php';
ensure_product_order_schema($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $name = trim((string) ($_POST['gcash_name'] ?? ''));
    $number = preg_replace('/[\s-]+/', '', (string) ($_POST['gcash_number'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !preg_match('/^(09\d{9}|\+639\d{9})$/', $number)) flash('error', 'Enter the clinic account name and a valid Philippine mobile number.');
    elseif (($_POST['confirmed'] ?? '') !== 'yes') flash('error', 'Confirm that these are the clinic’s correct GCash details.');
    else {
        order_db($conn, 'INSERT INTO product_payment_settings(id,gcash_name,gcash_number,updated_by) VALUES(1,?,?,?) ON DUPLICATE KEY UPDATE gcash_name=VALUES(gcash_name),gcash_number=VALUES(gcash_number),updated_by=VALUES(updated_by)', 'ssi', [$name, $number, (int) current_user_id()]);
        log_action($conn, 'Updated clinic GCash recipient', 'product_payment_settings', 1);
        flash('success', 'GCash details saved. New mobile orders will use this recipient. Existing orders keep their original recipient.');
    }
    redirect_to('staff/payment_settings.php');
}
$gcash = order_payment_options($conn)['gcash'];
$title = 'GCash Settings'; include '../includes/header.php'; include '../includes/navbar.php';
?>
<link rel="stylesheet" href="<?= e(app_url('assets/css/product-orders.css')) ?>?v=<?= filemtime(__DIR__ . '/../assets/css/product-orders.css') ?>">
<div class="layout"><?php include '../includes/staff_sidebar.php'; ?><main class="content product-orders-page" id="mainContent">
<section class="page-heading product-orders-heading"><h1>GCash Settings</h1><p>Choose the clinic account clients pay for product orders.</p></section>
<?php foreach (['success','error'] as $key): if ($message = flash($key)): ?><div role="alert" class="alert product-order-feedback alert-<?= $key === 'error' ? 'warning' : 'success' ?>"><?= e($message) ?></div><?php endif; endforeach; ?>
<section class="surface-card" style="max-width:650px">
<?php if (!$gcash['enabled']): ?><div class="alert product-order-feedback alert-info"><strong>Sample account only</strong><p class="mb-0">Sample Clinic GCash · 09XX XXX XXXX. Do not send money to sample details. Save the clinic’s real account to enable GCash checkout. Clients can already choose pay at the clinic.</p></div><?php endif; ?>
<form method="POST"><?= csrf_field() ?>
<label for="gcashName">GCash account name</label><input class="form-control mb-3" id="gcashName" name="gcash_name" placeholder="Sample Clinic GCash" value="<?= $gcash['enabled'] ? e($gcash['account_name']) : '' ?>" required maxlength="120">
<label for="gcashNumber">GCash mobile number</label><input class="form-control mb-3" id="gcashNumber" name="gcash_number" type="tel" placeholder="09XX XXX XXXX" value="<?= $gcash['enabled'] ? e($gcash['account_number']) : '' ?>" required maxlength="20">
<label class="d-block mb-3"><input type="checkbox" name="confirmed" value="yes" required> I verified that this account belongs to the clinic and can receive GCash payments.</label>
<button class="btn btn-primary">Save GCash details</button> <a class="btn btn-light" href="product_orders.php">Back to orders</a>
</form></section></main></div><?php include '../includes/footer.php'; ?>
