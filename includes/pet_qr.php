<?php
require_once __DIR__ . '/functions.php';

function pet_qr_token($input): string {
    if (!is_string($input)) return '';
    $token = trim($input);
    // Clinic scanners may provide either the complete QR link or a legacy token.
    if (preg_match('#^https?://#i', $token)) {
        $query = parse_url($token, PHP_URL_QUERY);
        if (!is_string($query)) return '';
        parse_str($query, $params);
        $token = $params['token'] ?? '';
    }
    if (!is_string($token)) return '';
    $token = trim($token);
    return $token !== '' && strlen($token) <= 255 && !preg_match('/[\x00-\x1f\x7f]/', $token) ? $token : '';
}

function pet_qr_path(string $token): string {
    return 'qr.php?token=' . rawurlencode($token);
}

function pet_qr_url(string $token): string {
    $base = rtrim(trim((string) (getenv('VETRIX_PUBLIC_BASE_URL') ?: '')), '/');
    if (preg_match('#^https?://#i', $base) && filter_var($base, FILTER_VALIDATE_URL)) {
        return $base . '/' . pet_qr_path($token);
    }
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . app_url(pet_qr_path($token));
}

function pet_qr_login_destination(string $role): string {
    $token = pet_qr_token($_SESSION['pending_pet_qr_token'] ?? '');
    unset($_SESSION['pending_pet_qr_token']);
    // Store only the token, never an arbitrary return URL.
    return $token !== '' ? pet_qr_path($token) : dashboard_for_role($role);
}

function pet_qr_record(mysqli $conn, string $token, string $role, int $userId): ?array {
    $token = pet_qr_token($token);
    if ($token === '' || $userId <= 0 || !in_array($role, ['admin', 'staff', 'veterinarian', 'client'], true)) return null;

    $sql = "SELECT p.*,p.id AS pet_id,u.full_name,u.email,u.phone,u.address
            FROM qr_tokens q JOIN pets p ON q.pet_id=p.id JOIN users u ON p.owner_id=u.id
            WHERE q.token=? AND q.status='active' AND p.verification_status='approved'
              AND (q.expires_at IS NULL OR q.expires_at>NOW())";
    if ($role === 'client') $sql .= ' AND p.owner_id=?';
    $stmt = $conn->prepare($sql . ' LIMIT 1');
    if ($role === 'client') $stmt->bind_param('si', $token, $userId);
    else $stmt->bind_param('s', $token);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $record ?: null;
}
