<?php
declare(strict_types=1);

class ProductOrderError extends RuntimeException {}
require_once __DIR__ . '/payment_accounts.php';

function order_db(mysqli $conn, string $sql, string $types = '', array $args = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException($conn->error);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error, $stmt->errno);
    return $stmt;
}

function ensure_product_order_schema(mysqli $conn): void
{
    static $ready = false;
    if ($ready) return;
    $existing = order_db($conn, "SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('product_orders','product_order_items','product_order_proofs','product_order_gcash_references','product_order_notifications','product_payment_settings')")->get_result()->fetch_assoc();
    if ((int) $existing['c'] !== 6) {
    // DDL runs before business transactions; never implicitly commit an order.
    $sql = file_get_contents(__DIR__ . '/../migrations/product_orders.sql');
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '' && !$conn->query($statement)) throw new RuntimeException($conn->error);
    }
    }
    $accounts = order_db($conn, "SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('product_payment_accounts','product_order_recipients','product_order_bank_references')")->get_result()->fetch_assoc();
    if ((int) $accounts['c'] !== 3) {
        foreach (explode(';', file_get_contents(__DIR__ . '/../migrations/payment_accounts.sql')) as $statement) {
            if (trim($statement) !== '' && !$conn->query($statement)) throw new RuntimeException($conn->error);
        }
    }
    $ready = true;
}

function order_payment_options(mysqli $conn): array
{
    $config = require __DIR__ . '/../config/payments.php';
    $saved = order_db($conn, 'SELECT gcash_name,gcash_number FROM product_payment_settings WHERE id=1')->get_result()->fetch_assoc();
    if ($saved) $config = $saved;
    $name = trim((string) ($config['gcash_name'] ?? ''));
    $number = trim((string) ($config['gcash_number'] ?? ''));
    $accounts = order_accounts($conn);
    $gcashAccounts = array_values(array_filter($accounts, fn($a) => $a['method'] === 'gcash'));
    $hasSavedGcash = (bool) order_db($conn, "SELECT id FROM product_payment_accounts WHERE method='gcash' LIMIT 1")->get_result()->fetch_row();
    $gcash = $gcashAccounts[0] ?? ['enabled' => !$hasSavedGcash && $name !== '' && preg_match('/^(09\d{9}|\+639\d{9})$/', $number) === 1,
        'account_name' => $name ?: 'Sample Clinic GCash', 'account_number' => $number ?: '09XX XXX XXXX'];
    return [
        'clinic' => true,
        'gcash' => $gcash,
        'accounts' => $accounts,
        'fulfillment' => 'clinic_pickup',
    ];
}

function order_audit(mysqli $conn, int $actor, int $id, string $action): void
{
    order_db($conn, "INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,details) VALUES(?,?,'product_order',?,'')", 'isi', [$actor, $action, $id]);
}

function order_has_column(mysqli $conn, string $table, string $column): bool
{
    return (bool) order_db($conn, 'SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', 'ss', [$table, $column])->get_result()->fetch_row();
}

function order_notify(mysqli $conn, int $id, int $client, string $title, string $message, bool $staff = false): void
{
    $recipients = $staff
        ? array_column(order_db($conn, "SELECT id FROM users WHERE role='staff' AND status='active'")->get_result()->fetch_all(MYSQLI_ASSOC), 'id')
        : [$client];
    $hasAction = order_has_column($conn, 'notifications', 'action_url');
    foreach ($recipients as $recipient) {
        if ($hasAction) {
            order_db($conn, "INSERT INTO notifications(user_id,title,message,type,status,action_url) VALUES(?,?,?,'system','unread',?)", 'isss', [(int) $recipient, $title, $message, $staff ? 'staff/product_orders.php?order_id=' . $id : null]);
        } else {
            order_db($conn, "INSERT INTO notifications(user_id,title,message,type,status) VALUES(?,?,?,'system','unread')", 'iss', [(int) $recipient, $title, $message]);
        }
        order_db($conn, 'INSERT INTO product_order_notifications(notification_id,order_id) VALUES(?,?)', 'ii', [$conn->insert_id, $id]);
    }
}

function order_get(mysqli $conn, int $id, ?int $client = null, bool $lock = false): array
{
    $sql = 'SELECT * FROM product_orders WHERE id=?';
    $args = [$id];
    $types = 'i';
    if ($client !== null) { $sql .= ' AND client_id=?'; $args[] = $client; $types .= 'i'; }
    if ($lock) $sql .= ' FOR UPDATE';
    $order = order_db($conn, $sql, $types, $args)->get_result()->fetch_assoc();
    if (!$order) throw new ProductOrderError('Order not found.', 404);
    $order['id'] = (int) $order['id'];
    $order['total_amount'] = (float) $order['total_amount'];
    unset($order['request_hash'], $order['request_key']);
    $order['items'] = order_db($conn, 'SELECT item_id,item_name,unit_price,quantity,line_total FROM product_order_items WHERE order_id=? ORDER BY item_id', 'i', [$id])->get_result()->fetch_all(MYSQLI_ASSOC);
    $order['proof'] = order_db($conn, 'SELECT id,reference,status,review_note,created_at,reviewed_at FROM product_order_proofs WHERE order_id=? ORDER BY id DESC LIMIT 1', 'i', [$id])->get_result()->fetch_assoc();
    $order['payment_recipient'] = order_recipient($conn, $id);
    return $order;
}

function order_list(mysqli $conn, int $client): array
{
    $rows = order_db($conn, 'SELECT id FROM product_orders WHERE client_id=? ORDER BY id DESC LIMIT 100', 'i', [$client])->get_result()->fetch_all(MYSQLI_ASSOC);
    return array_map(fn($row) => order_get($conn, (int) $row['id'], $client), $rows);
}

function order_stock(mysqli $conn, int $item, int $quantity, bool $restore, int $actor, string $remarks): void
{
    // stock_qty is available stock: reserve at order creation, restore on cancellation.
    $delta = $restore ? $quantity : -$quantity;
    $stmt = order_db($conn, "UPDATE inventory_items SET
        status=CASE WHEN status='inactive' THEN status WHEN stock_qty+?<=0 THEN 'out_of_stock' WHEN stock_qty+?<=reorder_level THEN 'low_stock' ELSE 'available' END,
        stock_qty=stock_qty+? WHERE id=? AND (?=1 OR (status IN ('available','low_stock') AND stock_qty>=?))", 'iiiiii', [$delta, $delta, $delta, $item, (int) $restore, $quantity]);
    if ($stmt->affected_rows !== 1) throw new ProductOrderError('A product no longer has enough stock. Refresh your cart.', 409);
    order_db($conn, 'INSERT INTO inventory_movements(item_id,movement_type,quantity,remarks,created_by) VALUES(?,?,?,?,?)', 'isisi', [$item, $restore ? 'stock_in' : 'stock_out', $quantity, $remarks, $actor]);
}

function order_create(mysqli $conn, int $client, array $input): array
{
    $method = $input['payment_method'] ?? '';
    $key = $input['request_key'] ?? '';
    $notes = $input['notes'] ?? '';
    if (!in_array($method, ['clinic', 'gcash', 'bank'], true)) throw new ProductOrderError('Choose GCash, bank transfer, or pay at the clinic.', 422);
    $accountId = filter_var($input['payment_account_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($accountId === false || $accountId < 0 || ($method === 'clinic' && $accountId)) throw new ProductOrderError('Choose a valid payment account.', 422);
    if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{16,80}$/', $key)) throw new ProductOrderError('A valid checkout request key is required.', 422);
    if (!is_string($notes) || mb_strlen($notes) > 500) throw new ProductOrderError('Keep the order note under 500 characters.', 422);
    $items = $input['items'] ?? [];
    if (!is_array($items) || count($items) < 1 || count($items) > 50) throw new ProductOrderError('Choose between 1 and 50 products.', 422);
    $cart = [];
    foreach ($items as $line) {
        if (!is_array($line)) throw new ProductOrderError('Invalid cart item.', 422);
        $id = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);
        $price = filter_var($line['unit_price_centavos'] ?? null, FILTER_VALIDATE_INT);
        if ($id < 1 || $qty < 1 || $qty > 99 || $price < 1 || isset($cart[$id])) throw new ProductOrderError('Invalid product, quantity, or duplicate cart item.', 422);
        $cart[$id] = ['quantity' => $qty, 'price' => $price];
    }
    ksort($cart, SORT_NUMERIC);
    $hash = hash('sha256', json_encode([$method, $cart, trim($notes)]));
    if ($accountId) $hash = hash('sha256', $hash . ':' . $accountId);
    $conn->begin_transaction();
    try {
        // Serializes requests for one client, including double taps and network retries.
        $user = order_db($conn, "SELECT full_name FROM users WHERE id=? AND role='client' AND status='active' AND otp_verified_at IS NOT NULL FOR UPDATE", 'i', [$client])->get_result()->fetch_assoc();
        if (!$user) throw new ProductOrderError('An active verified client account is required.', 403);
        $existing = order_db($conn, 'SELECT id,request_hash FROM product_orders WHERE client_id=? AND request_key=?', 'is', [$client, $key])->get_result()->fetch_assoc();
        if ($existing) {
            if (!hash_equals($existing['request_hash'], $hash)) throw new ProductOrderError('This checkout key was already used. Start a new checkout.', 409);
            $conn->commit();
            return order_get($conn, (int) $existing['id'], $client);
        }
        $open = order_db($conn, "SELECT COUNT(*) c FROM product_orders WHERE client_id=? AND status IN ('placed','ready')", 'i', [$client])->get_result()->fetch_assoc()['c'];
        if ($open >= 5) throw new ProductOrderError('You already have five open orders. Collect or cancel an existing order first.', 409);
        $options = order_payment_options($conn)['gcash'];
        if ($method === 'gcash' && !$options['enabled']) throw new ProductOrderError('GCash is not configured yet. Choose pay at the clinic.', 409);
        $recipient = null;
        $selectedAccount = $accountId ?: ($method === 'gcash' ? (int) ($options['id'] ?? 0) : 0);
        if ($selectedAccount) {
            $row = order_db($conn, 'SELECT id,method,provider,account_name,account_number,qr_token,qr_mime,enabled FROM product_payment_accounts WHERE id=? AND enabled=1 FOR UPDATE', 'i', [$selectedAccount])->get_result()->fetch_assoc();
            if (!$row || $row['method'] !== $method) throw new ProductOrderError('This payment account is no longer available. Refresh checkout.', 409);
            $recipient = order_account_payload($row);
            $options = $recipient;
        } elseif ($method === 'bank') throw new ProductOrderError('Choose a bank account for this transfer.', 422);
        $lines = []; $total = 0;
        foreach ($cart as $id => $line) {
            $item = order_db($conn, 'SELECT * FROM inventory_items WHERE id=? FOR UPDATE', 'i', [$id])->get_result()->fetch_assoc();
            if (!$item || !in_array($item['status'], ['available','low_stock'], true) || (int) $item['stock_qty'] < $line['quantity']) throw new ProductOrderError('A product is unavailable or has insufficient stock. Refresh your cart.', 409);
            $price = (int) round((float) $item['sale_price'] * 100);
            if ($price < 1 || $price !== $line['price']) throw new ProductOrderError('A product price changed. Refresh and review the total before ordering.', 409);
            $subtotal = $price * $line['quantity'];
            $total += $subtotal;
            $lines[] = [$id, $item['item_name'], $price / 100, $line['quantity'], $subtotal / 100];
        }
        if ($total > 9999999999) throw new ProductOrderError('Order total exceeds the checkout limit.', 422);
        order_db($conn, 'INSERT INTO product_orders(client_id,request_key,request_hash,payment_method,total_amount,notes,gcash_name,gcash_number) VALUES(?,?,?,?,?,?,?,?)', 'isssdsss', [$client, $key, $hash, $method, $total / 100, trim($notes), $method === 'gcash' ? $options['account_name'] : '', $method === 'gcash' ? $options['account_number'] : '']);
        $id = $conn->insert_id;
        if ($recipient) order_db($conn, 'INSERT INTO product_order_recipients(order_id,account_id) VALUES(?,?)', 'ii', [$id, $recipient['id']]);
        foreach ($lines as $line) {
            order_db($conn, 'INSERT INTO product_order_items(order_id,item_id,item_name,unit_price,quantity,line_total) VALUES(?,?,?,?,?,?)', 'iisdid', [$id, ...$line]);
            order_stock($conn, $line[0], $line[3], false, $client, 'Reserved for product order #' . $id);
        }
        $message = $user['full_name'] . ' placed order #' . $id . ' for PHP ' . number_format($total / 100, 2) . '. ' . ($method === 'clinic' ? 'Pay at clinic on pickup.' : 'Transfer payment proof is pending.');
        order_notify($conn, $id, $client, 'New product order #' . $id, $message, true);
        order_notify($conn, $id, $client, 'Order #' . $id . ' placed', 'Your products are reserved for clinic pickup. ' . ($method === 'clinic' ? 'Pay at the clinic when collecting your order.' : 'Send the exact total to the recipient shown in your order, then upload payment proof.'));
        order_audit($conn, $client, $id, 'Placed product order');
        $conn->commit();
        return order_get($conn, $id, $client);
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function order_upload_proof(mysqli $conn, int $client, int $id, string $reference, array $file): array
{
    $reference = order_reference(order_get($conn, $id, $client)['payment_method'], $reference);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new ProductOrderError('Choose a payment screenshot (JPEG or PNG, up to 5 MB).', 422);
    if (filesize($file['tmp_name']) > 5 * 1024 * 1024) throw new ProductOrderError('Payment proof must be at most 5 MB.', 422);
    $image = file_get_contents($file['tmp_name']);
    $info = @getimagesizefromstring($image);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($image);
    if (!$info || !in_array($mime, ['image/jpeg','image/png'], true) || $info[0] * $info[1] > 40000000) throw new ProductOrderError('Upload a valid JPEG or PNG payment screenshot.', 422);
    return order_save_proof($conn, $client, $id, $reference, $image, $mime);
}

// Image validation is performed by order_upload_proof at the HTTP boundary.
function order_save_proof(mysqli $conn, int $client, int $id, string $reference, string $image, string $mime): array
{
    $conn->begin_transaction();
    try {
        $order = order_get($conn, $id, $client, true);
        $reference = order_reference($order['payment_method'], $reference);
        $hash = hash('sha256', $image);
        $previous = order_db($conn, 'SELECT order_id,image_hash FROM product_order_proofs WHERE order_id=? AND reference=? AND image_hash=?', 'iss', [$id, $reference, $hash])->get_result()->fetch_assoc();
        if ($previous) {
            if ((int) $previous['order_id'] !== $id || !hash_equals($previous['image_hash'], $hash)) throw new ProductOrderError('This GCash reference has already been submitted. Contact staff if you need help.', 409);
            $conn->commit(); return $order;
        }
        if (!in_array($order['payment_method'], ['gcash','bank'], true) || !in_array($order['status'], ['placed','ready'], true) || !in_array($order['payment_status'], ['pending','rejected'], true)) throw new ProductOrderError('This order is not accepting payment proof.', 409);
        if ($order['payment_method'] === 'gcash') {
        order_db($conn, 'INSERT INTO product_order_gcash_references(reference,order_id) VALUES(?,?) ON DUPLICATE KEY UPDATE reference=VALUES(reference)', 'si', [$reference, $id]);
        $referenceOwner = order_db($conn, 'SELECT order_id FROM product_order_gcash_references WHERE reference=?', 's', [$reference])->get_result()->fetch_assoc();
        if ((int) $referenceOwner['order_id'] !== $id) throw new ProductOrderError('This GCash reference has already been used for another order.', 409);
        } else {
            $recipient = $order['payment_recipient'];
            $referenceKey = hash('sha256', strtolower($recipient['provider']) . ':' . strtoupper($recipient['account_number']) . ':' . $reference);
            order_db($conn, 'INSERT INTO product_order_bank_references(reference_key,order_id) VALUES(?,?) ON DUPLICATE KEY UPDATE reference_key=VALUES(reference_key)', 'si', [$referenceKey, $id]);
            $owner = order_db($conn, 'SELECT order_id FROM product_order_bank_references WHERE reference_key=?', 's', [$referenceKey])->get_result()->fetch_assoc();
            if ((int) $owner['order_id'] !== $id) throw new ProductOrderError('This bank reference has already been used for another order.', 409);
        }
        order_db($conn, 'INSERT INTO product_order_proofs(order_id,reference,image_data,image_mime,image_hash) VALUES(?,?,?,?,?)', 'issss', [$id, $reference, $image, $mime, $hash]);
        order_db($conn, "UPDATE product_orders SET payment_status='under_review',staff_note='' WHERE id=?", 'i', [$id]);
        order_notify($conn, $id, $client, 'Payment proof for order #' . $id, 'A transfer payment proof is awaiting verification. Check the transfer in the clinic receiving account before accepting.', true);
        order_audit($conn, $client, $id, 'Submitted transfer payment proof');
        $conn->commit(); return order_get($conn, $id, $client);
    } catch (Throwable $e) {
        $conn->rollback();
        if ($e->getCode() === 1062) throw new ProductOrderError('This GCash reference has already been submitted.', 409);
        throw $e;
    }
}

function order_record_sale(mysqli $conn, array $order, int $staff, ?float $cashReceived = null): void
{
    if ($order['transaction_id'] !== null) return;
    $id = $order['id'];
    $notes = 'Product order #' . $id . ' / ' . ($order['payment_method'] === 'clinic' ? 'Pay at clinic' : ($order['payment_method'] === 'gcash' ? 'GCash (verified by staff)' : 'Bank transfer (verified by staff)'));
    if ($cashReceived !== null) $notes .= "\nCash received: ₱" . number_format($cashReceived, 2) . ' | Change: ₱' . number_format($cashReceived - $order['total_amount'], 2);
    if (order_has_column($conn, 'pos_transactions', 'payment_method')) {
        order_db($conn, "INSERT INTO pos_transactions(client_id,handled_by,total_amount,payment_status,payment_method,notes) VALUES(?,?,?,'paid',?,?)", 'iidss', [(int) $order['client_id'], $staff, $order['total_amount'], $order['payment_method'] !== 'clinic' ? 'qr' : 'cash', $notes]);
    } else {
        order_db($conn, "INSERT INTO pos_transactions(client_id,handled_by,total_amount,payment_status,notes) VALUES(?,?,?,'paid',?)", 'iids', [(int) $order['client_id'], $staff, $order['total_amount'], $notes]);
    }
    $transaction = $conn->insert_id;
    foreach ($order['items'] as $line) {
        order_db($conn, 'INSERT INTO pos_transaction_items(transaction_id,item_id,item_name,unit_price,quantity,line_total) VALUES(?,?,?,?,?,?)', 'iisdid', [$transaction, (int) $line['item_id'], $line['item_name'], (float) $line['unit_price'], (int) $line['quantity'], (float) $line['line_total']]);
    }
    if (order_has_column($conn, 'pos_receipts', 'receipt_number')) {
        order_db($conn, 'INSERT INTO pos_receipts(transaction_id,receipt_number,generated_by,paper_width_mm) VALUES(?,?,?,80)', 'isi', [$transaction, 'VTX-' . str_pad((string) $transaction, 6, '0', STR_PAD_LEFT), $staff]);
    }
    order_db($conn, "UPDATE product_orders SET transaction_id=?,payment_status='paid' WHERE id=?", 'ii', [$transaction, $id]);
}

function order_update(mysqli $conn, int $actor, int $id, string $action, array $input = [], bool $staff = false): array
{
    $conn->begin_transaction();
    try {
        if ($staff && !order_db($conn, "SELECT id FROM users WHERE id=? AND role='staff' AND status='active'", 'i', [$actor])->get_result()->fetch_assoc()) throw new ProductOrderError('Only active staff can manage orders.', 403);
        if (!$staff && $action !== 'cancel') throw new ProductOrderError('This action is available to staff only.', 403);
        $order = order_get($conn, $id, $staff ? null : $actor, true);
        if (!in_array($order['status'], ['placed','ready'], true)) throw new ProductOrderError('This order has already been completed or cancelled.', 409);
        $client = (int) $order['client_id'];
        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 500) throw new ProductOrderError('Keep the note under 500 characters.', 422);
        if ($action === 'cancel') {
            if (in_array($order['payment_status'], ['paid','under_review'], true)) throw new ProductOrderError('Payment must be reconciled with staff before this order can be cancelled.', 409);
            foreach ($order['items'] as $line) order_stock($conn, (int) $line['item_id'], (int) $line['quantity'], true, $actor, 'Cancelled product order #' . $id);
            order_db($conn, "UPDATE product_orders SET status='cancelled',staff_note=? WHERE id=?", 'si', [$note, $id]);
            $message = 'Order cancelled. Reserved stock has been released.';
        } elseif ($action === 'ready') {
            if ($order['status'] !== 'placed') throw new ProductOrderError('This order is already ready for pickup.', 409);
            if ($order['payment_method'] !== 'clinic' && $order['payment_status'] !== 'paid') throw new ProductOrderError('Verify the transfer payment before marking the order ready.', 409);
            order_db($conn, "UPDATE product_orders SET status='ready',staff_note=? WHERE id=?", 'si', [$note, $id]);
            $message = 'Your order is ready for pickup at the clinic.';
        } elseif (in_array($action, ['verify','reject'], true)) {
            if ($order['payment_status'] !== 'under_review' || (int) ($input['proof_id'] ?? 0) !== (int) ($order['proof']['id'] ?? 0)) throw new ProductOrderError('This payment proof has changed or was already reviewed. Refresh the order.', 409);
            if ($action === 'reject' && $note === '') throw new ProductOrderError('Explain why the proof was rejected so the client can correct it.', 422);
            if ($action === 'verify' && ($input['confirmed'] ?? '') !== 'yes') throw new ProductOrderError('Confirm that you checked the reference and exact amount in the clinic receiving account.', 422);
            order_db($conn, 'UPDATE product_order_proofs SET status=?,reviewed_by=?,review_note=?,reviewed_at=NOW() WHERE id=?', 'sisi', [$action === 'verify' ? 'accepted' : 'rejected', $actor, $note, (int) $order['proof']['id']]);
            if ($action === 'verify') order_record_sale($conn, $order, $actor);
            else order_db($conn, "UPDATE product_orders SET payment_status='rejected' WHERE id=?", 'i', [$id]);
            order_db($conn, 'UPDATE product_orders SET staff_note=? WHERE id=?', 'si', [$note, $id]);
            $message = $action === 'verify' ? 'Your transfer payment has been verified.' : 'Payment proof was rejected: ' . $note . ' Contact the clinic if money was deducted; do not pay twice.';
        } elseif ($action === 'complete') {
            if ($order['status'] !== 'ready') throw new ProductOrderError('Mark the order ready before completing pickup.', 409);
            if ($order['payment_method'] === 'clinic' && $order['payment_status'] !== 'paid') {
                $cash = filter_var($input['cash_received'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($cash === false || !is_finite($cash) || round($cash * 100) < round($order['total_amount'] * 100) || ($input['confirmed'] ?? '') !== 'yes') throw new ProductOrderError('Confirm collection of the full payment and enter the amount received.', 422);
                order_record_sale($conn, $order, $actor, (float) $cash);
            } elseif ($order['payment_status'] !== 'paid') throw new ProductOrderError('Payment must be verified before pickup.', 409);
            order_db($conn, "UPDATE product_orders SET status='completed',staff_note=? WHERE id=?", 'si', [$note, $id]);
            $message = 'Your order has been collected. Thank you!';
        } else throw new ProductOrderError('Unknown order action.', 422);
        order_notify($conn, $id, $client, 'Order #' . $id . ' updated', $message);
        if (!$staff) order_notify($conn, $id, $client, 'Order #' . $id . ' cancelled', 'The client cancelled this order. Reserved stock has been released.', true);
        order_audit($conn, $actor, $id, 'Product order: ' . $action);
        $conn->commit(); return order_get($conn, $id, $staff ? null : $actor);
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
