<?php
require_once __DIR__ . '/product_orders.php';

// Shares inventory locks with mobile checkout while preserving the website cashier UI.
function product_order_pos_checkout(mysqli $conn, int $staff, array $input): array
{
    $method = $input['payment_method'] ?? 'cash';
    $status = $method === 'qr' ? 'paid' : ($input['payment_status'] ?? 'paid');
    if (!in_array($method, ['cash','qr'], true) || !in_array($status, ['paid','pending','cancelled'], true)) throw new ProductOrderError('Choose a valid payment method and status.', 422);
    $ids = $input['item_id'] ?? []; $quantities = $input['quantity'] ?? [];
    if (!is_array($ids) || !is_array($quantities) || count($ids) < 1 || count($ids) > 50) throw new ProductOrderError('Choose between 1 and 50 products.', 422);
    $cart = [];
    foreach ($ids as $index => $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        $qty = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_INT);
        if ($id < 1 || $qty < 1 || $qty > 999 || isset($cart[$id])) throw new ProductOrderError('Check the product quantities and remove duplicate products.', 422);
        $cart[$id] = $qty;
    }
    ksort($cart, SORT_NUMERIC);
    $client = ($input['client_id'] ?? '') === '' ? null : filter_var($input['client_id'], FILTER_VALIDATE_INT);
    if ($client !== null && ($client < 1 || !order_db($conn, "SELECT id FROM users WHERE id=? AND role='client'", 'i', [$client])->get_result()->fetch_row())) throw new ProductOrderError('Select a valid client.', 422);
    $notes = trim((string) ($input['notes'] ?? ''));
    $conn->begin_transaction();
    try {
        $lines = []; $centavos = 0;
        foreach ($cart as $id => $qty) {
            $item = order_db($conn, 'SELECT item_name,sale_price,stock_qty,status FROM inventory_items WHERE id=? FOR UPDATE', 'i', [$id])->get_result()->fetch_assoc();
            if (!$item || !in_array($item['status'], ['available','low_stock'], true) || (int) $item['stock_qty'] < $qty) throw new ProductOrderError('A product no longer has enough available stock. Refresh the cashier cart.', 409);
            $price = (int) round((float) $item['sale_price'] * 100);
            if ($price < 1) throw new ProductOrderError('A product has no valid sale price.', 422);
            $centavos += $price * $qty;
            $lines[] = [$id, $item['item_name'], $price / 100, $qty, ($price * $qty) / 100];
        }
        if ($centavos > 9999999999) throw new ProductOrderError('Sale total exceeds the limit.', 422);
        $total = $centavos / 100;
        if ($method === 'cash' && trim((string) ($input['cash_received'] ?? '')) !== '') {
            $cash = filter_var($input['cash_received'], FILTER_VALIDATE_FLOAT);
            if ($cash === false || !is_finite($cash) || $cash < 0 || ($status === 'paid' && round($cash * 100) < $centavos)) throw new ProductOrderError('The cash received must cover the full paid sale.', 422);
            $notes .= ($notes ? "\n" : '') . 'Cash received: ₱' . number_format($cash, 2) . ' | Change: ₱' . number_format(max(0, $cash - $total), 2);
        }
        order_db($conn, 'INSERT INTO pos_transactions(client_id,handled_by,total_amount,payment_status,payment_method,notes) VALUES(?,?,?,?,?,?)', 'iidsss', [$client, $staff, $total, $status, $method, $notes]);
        $transaction = $conn->insert_id;
        foreach ($lines as $line) {
            order_db($conn, 'INSERT INTO pos_transaction_items(transaction_id,item_id,item_name,unit_price,quantity,line_total) VALUES(?,?,?,?,?,?)', 'iisdid', [$transaction, ...$line]);
            if ($status !== 'cancelled') order_stock($conn, $line[0], $line[3], false, $staff, 'POS sale transaction #' . $transaction);
        }
        if ($status === 'paid') order_db($conn, 'INSERT INTO pos_receipts(transaction_id,receipt_number,generated_by,paper_width_mm) VALUES(?,?,?,80)', 'isi', [$transaction, 'VTX-' . str_pad((string) $transaction, 6, '0', STR_PAD_LEFT), $staff]);
        order_db($conn, "INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,details) VALUES(?,'Processed POS product sale','pos_transaction',?,?)", 'iis', [$staff, $transaction, 'Amount: ' . $total . '; Items: ' . count($lines)]);
        $conn->commit();
        return ['id' => $transaction, 'status' => $status];
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
