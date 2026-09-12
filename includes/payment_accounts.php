<?php
declare(strict_types=1);

function order_account_payload(array $row): array
{
    return ['id' => (int) $row['id'], 'method' => $row['method'], 'provider' => $row['provider'],
        'account_name' => $row['account_name'], 'account_number' => $row['account_number'],
        'enabled' => (bool) $row['enabled'],
        'qr_url' => $row['qr_mime'] ? 'api/mobile/v1/payment_qr.php?token=' . $row['qr_token'] : null];
}

function order_accounts(mysqli $conn, bool $activeOnly = true): array
{
    $rows = order_db($conn, 'SELECT id,method,provider,account_name,account_number,qr_token,qr_mime,enabled FROM product_payment_accounts' . ($activeOnly ? ' WHERE enabled=1' : '') . ' ORDER BY id DESC')->get_result()->fetch_all(MYSQLI_ASSOC);
    return array_map('order_account_payload', $rows);
}

function order_account_image(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new ProductOrderError('Choose a QR image in PNG or JPEG format, up to 3 MB.', 422);
    if (filesize($file['tmp_name']) > 3 * 1024 * 1024) throw new ProductOrderError('The QR image must be at most 3 MB.', 422);
    $data = file_get_contents($file['tmp_name']);
    $info = @getimagesizefromstring($data);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    if (!$info || !in_array($mime, ['image/png', 'image/jpeg'], true) || $info[0] * $info[1] > 16000000) throw new ProductOrderError('Choose a valid PNG or JPEG QR image.', 422);
    return ['data' => $data, 'mime' => $mime];
}

// Account versions are immutable: replacing an account never changes an existing order's recipient or QR.
function order_save_account(mysqli $conn, int $staff, array $input, ?array $qr = null): int
{
    $method = (string) ($input['method'] ?? '');
    $provider = $method === 'gcash' ? 'GCash' : trim((string) ($input['provider'] ?? ''));
    $name = trim((string) ($input['account_name'] ?? ''));
    $number = preg_replace('/[\s-]+/', '', (string) ($input['account_number'] ?? ''));
    if (!in_array($method, ['gcash', 'bank'], true) || mb_strlen($provider) < 2 || mb_strlen($provider) > 80 || mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new ProductOrderError('Enter the bank or wallet, account name, and account number.', 422);
    if (!preg_match($method === 'gcash' ? '/^(09\d{9}|\+639\d{9})$/' : '/^[A-Za-z0-9]{6,40}$/', $number)) throw new ProductOrderError('Enter a valid GCash mobile number or bank account number.', 422);
    if (($input['confirmed'] ?? '') !== 'yes') throw new ProductOrderError('Confirm that the account and QR belong to the clinic.', 422);
    $conn->begin_transaction();
    try {
        if (!order_db($conn, "SELECT id FROM users WHERE id=? AND role='staff' AND status='active' FOR UPDATE", 'i', [$staff])->get_result()->fetch_assoc()) throw new ProductOrderError('Only active staff can change payment accounts.', 403);
        $replace = (int) ($input['replace_id'] ?? 0);
        if ($replace) {
            $old = order_db($conn, 'SELECT id FROM product_payment_accounts WHERE id=? AND enabled=1 FOR UPDATE', 'i', [$replace])->get_result()->fetch_assoc();
            if (!$old) throw new ProductOrderError('That account was already disabled or replaced. Refresh settings.', 409);
            order_db($conn, 'UPDATE product_payment_accounts SET enabled=0 WHERE id=?', 'i', [$replace]);
        }
        order_db($conn, 'INSERT INTO product_payment_accounts(method,provider,account_name,account_number,qr_token,qr_data,qr_mime,created_by) VALUES(?,?,?,?,?,?,?,?)', 'sssssssi', [$method, $provider, $name, $number, bin2hex(random_bytes(24)), $qr['data'] ?? null, $qr['mime'] ?? null, $staff]);
        $id = $conn->insert_id;
        order_audit($conn, $staff, $id, 'Saved clinic payment account');
        $conn->commit();
        return $id;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function order_disable_account(mysqli $conn, int $staff, int $id): void
{
    $conn->begin_transaction();
    try {
        if (!order_db($conn, "SELECT id FROM users WHERE id=? AND role='staff' AND status='active' FOR UPDATE", 'i', [$staff])->get_result()->fetch_assoc()) throw new ProductOrderError('Only active staff can change payment accounts.', 403);
        order_db($conn, 'UPDATE product_payment_accounts SET enabled=0 WHERE id=?', 'i', [$id]);
        order_audit($conn, $staff, $id, 'Disabled clinic payment account for new orders');
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function order_recipient(mysqli $conn, int $orderId): ?array
{
    $row = order_db($conn, 'SELECT a.id,a.method,a.provider,a.account_name,a.account_number,a.qr_token,a.qr_mime,a.enabled FROM product_order_recipients r JOIN product_payment_accounts a ON a.id=r.account_id WHERE r.order_id=?', 'i', [$orderId])->get_result()->fetch_assoc();
    return $row ? order_account_payload($row) : null;
}

function order_reference(string $method, string $reference): string
{
    $reference = strtoupper(preg_replace('/[\s-]+/', '', $reference));
    if (!preg_match($method === 'gcash' ? '/^\d{10,20}$/' : '/^[A-Z0-9]{6,40}$/', $reference)) throw new ProductOrderError($method === 'gcash' ? 'Enter the 10–20 digit GCash reference number.' : 'Enter the bank transfer reference (6–40 letters or digits).', 422);
    return $reference;
}
