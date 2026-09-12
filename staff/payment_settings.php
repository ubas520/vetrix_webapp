<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_role('staff');
require_once '../includes/product_orders.php';
ensure_product_order_schema($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        if (($_POST['action'] ?? '') === 'disable') {
            order_disable_account($conn, (int) current_user_id(), (int) ($_POST['id'] ?? 0));
            flash('success', 'Account disabled for new orders. Existing orders keep their original payment details.');
        } else {
            order_save_account($conn, (int) current_user_id(), $_POST, order_account_image($_FILES['qr'] ?? []));
            flash('success', 'Payment account saved. Clients can select it for new orders.');
        }
    } catch (ProductOrderError $e) { flash('error', $e->getMessage()); }
    catch (Throwable $e) { error_log($e->getMessage()); flash('error', 'Could not save payment settings. Please try again.'); }
    redirect_to('staff/payment_settings.php');
}
$accounts = order_accounts($conn);
$gcash = order_payment_options($conn)['gcash'];
$title = 'Payment Settings'; include '../includes/header.php'; include '../includes/navbar.php';
?>
<link rel="stylesheet" href="<?= e(app_url('assets/css/product-orders.css')) ?>?v=<?= filemtime(__DIR__ . '/../assets/css/product-orders.css') ?>">
<div class="layout"><?php include '../includes/staff_sidebar.php'; ?><main class="content product-orders-page" id="mainContent">
<section class="page-heading product-orders-heading"><h1>Payment Settings</h1><p>Add the clinic's GCash QR and bank accounts for mobile product orders.</p></section>
<?php foreach (['success','error'] as $key): if ($message = flash($key)): ?><div role="alert" class="alert product-order-feedback alert-<?= $key === 'error' ? 'warning' : 'success' ?>"><?= e($message) ?></div><?php endif; endforeach; ?>
<section class="surface-card mb-4" style="max-width:760px"><h2>Receiving accounts</h2>
<p>Clients choose an account at checkout, then upload proof of payment. Verify the actual transfer before accepting it.</p>
<?php if (!$accounts): ?><p>No QR or bank accounts added yet. Pay at the clinic remains available.</p><?php endif; ?>
<?php if ($gcash['enabled'] && empty($gcash['id'])): ?><p>Existing GCash recipient: <?= e($gcash['account_name']) ?> · <?= e($gcash['account_number']) ?>. Adding a GCash account below replaces this default for new orders.</p><?php endif; ?>
<?php foreach ($accounts as $account): ?><article class="border rounded p-3 mb-3">
<h3><?= e($account['provider']) ?></h3><p><?= e($account['account_name']) ?><br><?= e($account['account_number']) ?></p>
<?php if ($account['qr_url']): ?><a href="../<?= e($account['qr_url']) ?>" target="_blank" rel="noopener"><img src="../<?= e($account['qr_url']) ?>" alt="<?= e($account['provider']) ?> receiving QR" style="width:240px;max-width:100%;height:240px;object-fit:contain;background:white"></a><?php endif; ?>
<form method="POST" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="disable"><input type="hidden" name="id" value="<?= $account['id'] ?>"><button class="btn btn-outline-danger" onclick="return confirm('Disable this account for new orders? Existing orders keep these details.')">Disable for new orders</button></form></article><?php endforeach; ?>
</section>
<section class="surface-card" style="max-width:760px"><h2>Add or replace an account</h2>
<form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
<label for="paymentMethod">Payment type</label><select class="form-select mb-3" id="paymentMethod" name="method"><option value="gcash">GCash</option><option value="bank">Bank transfer</option></select>
<label for="provider">Bank name (for bank transfers)</label><input class="form-control mb-3" id="provider" name="provider" maxlength="80" placeholder="e.g. BPI, BDO, Metrobank">
<label for="accountName">Account holder name</label><input class="form-control mb-3" id="accountName" name="account_name" maxlength="120" required>
<label for="accountNumber">GCash mobile number or bank account number</label><input class="form-control mb-3" id="accountNumber" name="account_number" maxlength="50" required>
<label for="paymentQr">Receiving QR image (optional)</label><input class="form-control mb-2" type="file" id="paymentQr" name="qr" accept="image/png,image/jpeg">
<p class="text-muted">Upload the receiving QR exported from the clinic's wallet or banking app. PNG or JPEG, up to 3 MB. Customers can use the account number when no QR is provided.</p>
<label for="replaceAccount">Replace an existing account</label><select class="form-select mb-3" name="replace_id" id="replaceAccount"><option value="0">Add as another payment option</option><?php foreach ($accounts as $account): ?><option value="<?= $account['id'] ?>"><?= e($account['provider'] . ' · ' . $account['account_name'] . ' · ' . $account['account_number']) ?></option><?php endforeach; ?></select>
<p class="text-muted">Replacing disables the old option for new orders. Existing orders retain their original recipient and QR. Upload the QR again when replacing an account.</p>
<label class="d-block mb-3"><input type="checkbox" name="confirmed" value="yes" required> I checked that these account details and the uploaded QR belong to the clinic and can receive payments.</label>
<button class="btn btn-primary">Save payment account</button> <a class="btn btn-light" href="product_orders.php">Back to orders</a>
</form></section></main></div><?php include '../includes/footer.php'; ?>
