<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_role('staff');
require_once '../includes/product_orders.php';
ensure_pos_product_schema($conn);
ensure_product_order_schema($conn);
$selected = (int) ($_GET['order_id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        order_update($conn, (int) current_user_id(), $selected, (string) ($_POST['action'] ?? ''), $_POST, true);
        flash('success', 'Order updated. The client has been notified.');
    } catch (ProductOrderError $e) { flash('error', $e->getMessage()); }
    catch (Throwable $e) { error_log($e->getMessage()); flash('error', 'The order could not be updated. Refresh and try again.'); }
    redirect_to('staff/product_orders.php?id=' . $selected);
}
$detail = null;
if ($selected) {
    try { $detail = order_get($conn, $selected); }
    catch (ProductOrderError $e) { http_response_code(404); }
}
$filter = $_GET['status'] ?? 'open';
$where = "o.status IN ('placed','ready')";
if (in_array($filter, ['placed','ready','completed','cancelled'], true)) $where = "o.status='" . $filter . "'";
if ($filter === 'all') $where = '1=1';
$rows = order_db($conn, "SELECT o.*,u.full_name,u.phone FROM product_orders o JOIN users u ON u.id=o.client_id WHERE $where ORDER BY o.id DESC LIMIT 100")->get_result();
$title = 'Product Orders';
include '../includes/header.php'; include '../includes/navbar.php';
?>
<link rel="stylesheet" href="<?= e(app_url('assets/css/product-orders.css')) ?>?v=<?= filemtime(__DIR__ . '/../assets/css/product-orders.css') ?>">
<div class="layout"><?php include '../includes/staff_sidebar.php'; ?><main class="content product-orders-page" id="mainContent">
<section class="page-heading product-orders-heading"><h1>Product Orders</h1><p>Manage mobile purchases, verify GCash payments, and prepare clinic pickups.</p><a class="btn btn-outline-primary" href="payment_settings.php">GCash settings</a></section>
<?php foreach (['success','error'] as $key): if ($message = flash($key)): ?><div role="alert" class="alert product-order-feedback alert-<?= $key === 'error' ? 'warning' : 'success' ?>"><?= e($message) ?></div><?php endif; endforeach; ?>
<?php if ($selected && !$detail): ?><div class="alert product-order-feedback alert-warning">Order not found.</div><?php endif; ?>
<?php if ($detail): $open = in_array($detail['status'], ['placed','ready'], true); $proof = $detail['proof']; ?>
<section class="surface-card mb-4" id="order-detail">
<h2>Order #<?= $detail['id'] ?></h2>
<p><?= badge($detail['status']) ?> <?= badge(str_replace('_',' ', $detail['payment_status'])) ?> · <?= $detail['payment_method'] === 'gcash' ? 'GCash' : 'Pay at clinic' ?> · <?= e($detail['created_at']) ?></p>
<?php $client = order_db($conn, 'SELECT full_name,phone FROM users WHERE id=?', 'i', [(int) $detail['client_id']])->get_result()->fetch_assoc(); ?>
<p><strong><?= e($client['full_name']) ?></strong> · <?= e($client['phone'] ?: 'No phone supplied') ?></p>
<div class="table-responsive"><table class="table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>
<?php foreach ($detail['items'] as $line): ?><tr><td><?= e($line['item_name']) ?></td><td><?= (int) $line['quantity'] ?></td><td>PHP <?= number_format((float) $line['unit_price'],2) ?></td><td>PHP <?= number_format((float) $line['line_total'],2) ?></td></tr><?php endforeach; ?>
</tbody></table></div><h3>Total: PHP <?= number_format($detail['total_amount'],2) ?></h3>
<p>Client note: <?= e($detail['notes'] ?: 'None') ?></p><p>Staff note: <?= e($detail['staff_note'] ?: 'None') ?></p>
<?php if ($detail['payment_method'] === 'gcash'): ?><p>GCash recipient for this order: <?= e($detail['gcash_name']) ?> · <?= e($detail['gcash_number']) ?></p><?php endif; ?>
<?php if ($proof): ?><div class="mb-3"><strong>GCash reference: <?= e($proof['reference']) ?></strong><br><a href="order_proof.php?id=<?= (int) $proof['id'] ?>" target="_blank" rel="noopener">View payment proof</a><br><img src="order_proof.php?id=<?= (int) $proof['id'] ?>" alt="GCash payment proof for order <?= $detail['id'] ?>" style="max-width:100%;max-height:360px;object-fit:contain;margin-top:12px"></div><?php endif; ?>
<?php if ($open): ?>
<form method="POST" class="mt-3">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= $detail['id'] ?>"><input type="hidden" name="proof_id" value="<?= (int) ($proof['id'] ?? 0) ?>">
<label for="orderNote">Note to client (required when rejecting proof)</label><textarea class="form-control mb-3" id="orderNote" name="note" maxlength="500" rows="2"></textarea>
<?php if ($detail['payment_status'] === 'under_review'): ?>
<label class="d-block mb-3"><input type="checkbox" name="confirmed" value="yes"> I checked the reference, recipient, and exact amount received in the clinic GCash account.</label>
<button class="btn btn-success me-2 mb-2" name="action" value="verify">Verify GCash payment</button><button class="btn btn-outline-danger mb-2" name="action" value="reject">Reject payment proof</button>
<?php endif; ?>
<?php if ($detail['status'] === 'placed' && ($detail['payment_method'] === 'clinic' || $detail['payment_status'] === 'paid')): ?><button class="btn btn-primary me-2 mb-2" name="action" value="ready">Mark ready for pickup</button><?php endif; ?>
<?php if ($detail['status'] === 'ready'): ?>
<?php if ($detail['payment_method'] === 'clinic' && $detail['payment_status'] !== 'paid'): ?>
<label for="cashReceived">Amount received (PHP)</label><input class="form-control mb-2" id="cashReceived" name="cash_received" type="number" min="<?= e($detail['total_amount']) ?>" step="0.01">
<label class="d-block mb-3"><input type="checkbox" name="confirmed" value="yes"> I collected the full payment and handed over the products.</label>
<?php endif; ?><button class="btn btn-success me-2 mb-2" name="action" value="complete">Complete pickup<?= $detail['payment_method'] === 'clinic' ? ' and record payment' : '' ?></button><?php endif; ?>
<?php if (in_array($detail['payment_status'], ['pending','rejected'], true)): ?><button class="btn btn-outline-danger mb-2" name="action" value="cancel" onclick="return confirm('Cancel this order and release its reserved stock?')">Cancel order</button><?php endif; ?>
</form><?php endif; ?>
<?php if ($detail['transaction_id']): ?><p class="mt-3">POS payment record #<?= (int) $detail['transaction_id'] ?> · <?php if (file_exists(__DIR__ . '/receipt.php')): ?><a href="receipt.php?transaction_id=<?= (int) $detail['transaction_id'] ?>">View receipt</a> · <?php endif; ?><a href="pos.php">Open POS cashier</a></p><?php endif; ?>
</section><?php endif; ?>
<form method="GET" class="d-flex gap-2 mb-3"><label for="orderStatus" class="visually-hidden">Order status</label><select name="status" id="orderStatus" class="form-select" style="max-width:220px"><?php foreach (['open'=>'Open orders','placed'=>'Placed','ready'=>'Ready for pickup','completed'=>'Completed','cancelled'=>'Cancelled','all'=>'All orders'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filter === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><button class="btn btn-primary">Filter</button><a class="btn btn-light" href="product_orders.php">Refresh</a></form>
<div class="table-card"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Order</th><th>Client</th><th>Total</th><th>Payment</th><th>Pickup</th><th>Created</th></tr></thead><tbody>
<?php if (!$rows->num_rows): ?><tr><td colspan="6">No matching orders.</td></tr><?php endif; ?>
<?php while ($row = $rows->fetch_assoc()): ?><tr><td><a href="product_orders.php?id=<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?></a></td><td><?= e($row['full_name']) ?></td><td>PHP <?= number_format((float) $row['total_amount'],2) ?></td><td><?= $row['payment_method'] === 'gcash' ? 'GCash' : 'At clinic' ?><br><?= badge(str_replace('_',' ', $row['payment_status'])) ?></td><td><?= badge($row['status']) ?></td><td><?= e($row['created_at']) ?></td></tr><?php endwhile; ?>
</tbody></table></div></div><p class="text-muted mt-2">Latest 100 matching orders. New orders and payment proofs appear in the notification bell automatically.</p>
</main></div><?php include '../includes/footer.php'; ?>
