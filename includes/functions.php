<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}


function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}



function ui_icon($name, $class = '', $title = '') {
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'calendar' => '<path d="M8 2v4M16 2v4M3 9h18"/><rect x="3" y="4" width="18" height="17" rx="3"/><path d="M8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'inventory' => '<path d="m21 8-9 5-9-5"/><path d="M3 8l9-5 9 5v8l-9 5-9-5Z"/><path d="M12 13v8"/>',
        'cart' => '<circle cx="9" cy="20" r="1"/><circle cx="19" cy="20" r="1"/><path d="M3 4h2l2.4 10.2a2 2 0 0 0 2 1.5h7.7a2 2 0 0 0 2-1.6L21 7H6"/>',
        'paw' => '<circle cx="8" cy="8" r="2"/><circle cx="16" cy="8" r="2"/><circle cx="5" cy="13" r="2"/><circle cx="19" cy="13" r="2"/><path d="M12 12c-3.3 0-6 2.7-6 6 0 1.7 1.3 3 3 3 1 0 2-.5 3-1.3 1 .8 2 1.3 3 1.3 1.7 0 3-1.3 3-3 0-3.3-2.7-6-6-6Z"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>',
        'syringe' => '<path d="m18 2 4 4M17 7l3-3M19 9 2-2M3 21l6.5-6.5M6 18l-2-2M10.5 15.5 5-5M8 13l3 3M12 9l3 3"/><path d="m14 3 7 7-8.5 8.5a2.1 2.1 0 0 1-3 0l-4-4a2.1 2.1 0 0 1 0-3Z"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM14 20h2M20 14h1"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="m7 16 4-5 4 3 5-7"/>',
        'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
        'message' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/><path d="M8 9h8M8 13h5"/>',
        'user-cog' => '<circle cx="9" cy="7" r="4"/><path d="M3 21v-2a6 6 0 0 1 6-6h2"/><circle cx="18" cy="17" r="3"/><path d="M18 12v2M18 20v2M13 17h2M21 17h2M14.5 13.5l1.4 1.4M20.1 19.1l1.4 1.4M21.5 13.5l-1.4 1.4M15.9 19.1l-1.4 1.4"/>',
        'activity' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
        'bot' => '<rect x="4" y="7" width="16" height="13" rx="3"/><path d="M12 3v4M8 12h.01M16 12h.01M8 16h8"/>',
        'stethoscope' => '<path d="M6 2v6a4 4 0 0 0 8 0V2M4 2h4M12 2h4"/><path d="M10 14v2a5 5 0 0 0 10 0v-1"/><circle cx="20" cy="12" r="2"/>',
        'heart-pulse' => '<path d="M19 14c1.5-1.5 3-3.5 3-6a5 5 0 0 0-9-3 5 5 0 0 0-9 3c0 7 9 12 9 12l1.5-1"/><path d="M3 13h4l2-4 3 8 2-4h7"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4a3 3 0 0 1 6 0v2H9Z"/><path d="M9 12h6M9 16h5"/>',
        'receipt' => '<path d="M6 2 4 4 2 2v20l2-2 2 2 2-2 2 2 2-2 2 2 2-2 2 2 2-2 2 2V2l-2 2-2-2-2 2-2-2-2 2-2-2-2 2Z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'scan' => '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'panel' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M9 3v18M13 8h4M13 12h4M13 16h4"/>',
        'logout' => '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M21 19V5a2 2 0 0 0-2-2h-6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'home' => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10M9 20v-6h6v6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21h-4v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1-2.8-2.8.1-.1A1.7 1.7 0 0 0 4.6 15 1.7 1.7 0 0 0 3 14H3v-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 2.8-2.8.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6V3h4v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1 2.8 2.8-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'more' => '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'smartphone' => '<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10 5h4M11 18h2"/>',
        'camera' => '<path d="M14.5 4 16 6h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3l1.5-2Z"/><circle cx="12" cy="13" r="3.5"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'maximize' => '<path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/>',
        'minimize' => '<path d="M9 3v6H3M15 3v6h6M9 21v-6H3M15 21v-6h6"/>',
        'arrow-left' => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'package' => '<path d="m16.5 9.4-9-5.2M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
        'layers' => '<path d="m12 2 9 5-9 5-9-5Z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/>',
        'coins' => '<ellipse cx="12" cy="5" rx="7" ry="3"/><path d="M5 5v5c0 1.7 3.1 3 7 3s7-1.3 7-3V5M5 10v5c0 1.7 3.1 3 7 3s7-1.3 7-3v-5M5 15v4c0 1.7 3.1 3 7 3s7-1.3 7-3v-4"/>',
        'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v6M14 11v6"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 15-5-5L5 20"/>',
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M3 3l18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c6 0 10 8 10 8a17.8 17.8 0 0 1-3.1 4.2"/><path d="M6.6 6.6A17.6 17.6 0 0 0 2 12s4 8 10 8a10.8 10.8 0 0 0 4.2-.9"/>',
        'refresh' => '<path d="M20 6v5h-5M4 18v-5h5"/><path d="M18 9a7 7 0 0 0-12-2L4 11M6 15a7 7 0 0 0 12 2l2-4"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'orbit' => '<circle cx="12" cy="12" r="3"/><ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(35 12 12)"/><ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(-35 12 12)"/><circle cx="19" cy="8" r="1" fill="currentColor" stroke="none"/>',
        'calendar-days' => '<rect x="3" y="4" width="18" height="17" rx="3"/><path d="M8 2v4M16 2v4M3 9h18M7 13h3M14 13h3M7 17h3"/>',
        'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="m17 11 2 2 4-4"/>',
        'sparkles' => '<path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2Z"/><path d="m18.5 14 .8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8Z"/><path d="m5 14 .9 2.6 2.6.9-2.6.9L5 21l-.9-2.6-2.6-.9 2.6-.9Z"/>',
        'help-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.6 2.6 0 1 1 4.6 1.7c-.9 1-2.3 1.3-2.3 3.1M12 17h.01"/>',
        'dog' => '<path d="M7 8 4 5v7M17 8l3-3v7"/><path d="M6 9a6 6 0 0 1 12 0v5a6 6 0 0 1-12 0Z"/><path d="M9 12h.01M15 12h.01M10 16c1 1 3 1 4 0M12 14v2"/>',
        'cat' => '<path d="M6 8 4 3l5 3a8 8 0 0 1 6 0l5-3-2 5a8 8 0 1 1-12 0Z"/><path d="M9 13h.01M15 13h.01M10 17h4M12 14v2"/>',
        'star' => '<path d="m12 2.7 2.8 5.7 6.3.9-4.6 4.5 1.1 6.3-5.6-3-5.6 3 1.1-6.3-4.6-4.5 6.3-.9Z"/>',

    ];

    $body = $icons[$name] ?? $icons['dashboard'];
    $label = $title !== '' ? '<title>' . e($title) . '</title>' : '';
    $classAttr = trim('ui-icon ' . $class);
    return '<svg class="' . e($classAttr) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $label . $body . '</svg>';
}

function role_label($role) {
    $labels = [
        'admin' => 'Administrator',
        'veterinarian' => 'Veterinarian',
        'staff' => 'Clinic Staff',
        'client' => 'Client Portal',
    ];
    return $labels[$role] ?? 'Vetrix';
}

function notification_icon_name($type) {
    $map = [
        'appointment' => 'calendar',
        'vaccine' => 'syringe',
        'record' => 'clipboard',
        'qr' => 'qr',
        'feedback' => 'message',
        'system' => 'bell',
    ];
    return $map[strtolower((string)$type)] ?? 'bell';
}

function app_url($path = '') {
    // Builds the correct base URL even if the project is inside an extra folder,
    // for example: /vetrix/ OR /vetrix_mobile_simulation/vetrix/
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
    $dir = rtrim(dirname($script), '/\\');
    $knownFolders = ['/admin', '/vet', '/staff', '/client', '/account', '/includes', '/config', '/api', '/assets'];

    foreach ($knownFolders as $folder) {
        if (substr($dir, -strlen($folder)) === $folder) {
            $dir = substr($dir, 0, -strlen($folder));
            break;
        }
    }

    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        $dir = '';
    }

    return $dir . '/' . ltrim($path, '/');
}

function redirect_to($path) {
    header("Location: " . app_url($path));
    exit;
}

function is_logged_in() { return isset($_SESSION['user_id']); }

function require_login() {
    if (!is_logged_in()) redirect_to("login.php");
    $now = time();
    $idleLimit = 2 * 60 * 60;
    if (!empty($_SESSION['last_activity_at']) && $now - (int)$_SESSION['last_activity_at'] > $idleLimit) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        redirect_to('login.php?expired=1');
    }
    $_SESSION['last_activity_at'] = $now;
}

function dashboard_for_role($role = null) {
    $role = $role ?? ($_SESSION['role'] ?? '');
    $map = [
        'admin' => 'admin/dashboard.php',
        'veterinarian' => 'vet/dashboard.php',
        'staff' => 'staff/dashboard.php'
    ];
    return $map[$role] ?? 'login.php';
}

function require_role($role) {
    require_login();
    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT role,status FROM users WHERE id=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $allowedAccountStatuses = ($account['role'] ?? '') === 'client' ? ['active','approved'] : ['active','approved'];
            if (!$account || !in_array($account['status'], $allowedAccountStatuses, true)) {
                $_SESSION = [];
                session_destroy();
                redirect_to('login.php?inactive=1');
            }
            $_SESSION['role'] = $account['role'];
        }
    }
    $allowed = is_array($role) ? $role : [$role];
    if (!in_array($_SESSION['role'], $allowed, true)) {
        redirect_to(dashboard_for_role($_SESSION['role'] ?? 'client'));
    }
}

function current_user_id() { return $_SESSION['user_id'] ?? null; }

function vetrix_brand_logo_path() {
    $default = 'assets/images/vetrix-logo.svg';
    $pointer = dirname(__DIR__) . '/uploads/profiles/vetrix-brand-logo.txt';
    if (!is_file($pointer)) return $default;
    $relative = trim((string)@file_get_contents($pointer));
    if ($relative === '' || str_contains($relative, '..') || !str_starts_with($relative, 'uploads/profiles/')) return $default;
    return is_file(dirname(__DIR__) . '/' . $relative) ? $relative : $default;
}

function save_vetrix_brand_logo($file) {
    $upload = upload_image_file($file, 'uploads/profiles', 'vetrix_brand');
    if (empty($upload['ok'])) return $upload;
    $pointer = dirname(__DIR__) . '/uploads/profiles/vetrix-brand-logo.txt';
    $old = vetrix_brand_logo_path();
    if ($old !== 'assets/images/vetrix-logo.svg' && $old !== $upload['path']) {
        $oldAbsolute = dirname(__DIR__) . '/' . $old;
        if (is_file($oldAbsolute)) @unlink($oldAbsolute);
    }
    if (@file_put_contents($pointer, $upload['path'], LOCK_EX) === false) {
        @unlink(dirname(__DIR__) . '/' . $upload['path']);
        return ['ok' => false, 'error' => 'The logo could not be saved. Check uploads/profiles permissions.'];
    }
    return $upload;
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf_or_fail() {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your session form token expired. Reload the page and try again.');
    }
}

function password_policy_errors($password) {
    $errors = [];
    if (strlen((string)$password) < 10) $errors[] = 'Use at least 10 characters.';
    if (!preg_match('/[A-Z]/', (string)$password)) $errors[] = 'Add at least one uppercase letter.';
    if (!preg_match('/[a-z]/', (string)$password)) $errors[] = 'Add at least one lowercase letter.';
    if (!preg_match('/\d/', (string)$password)) $errors[] = 'Add at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', (string)$password)) $errors[] = 'Add at least one symbol.';
    return $errors;
}

function user_initials($fullName) {
    $parts = preg_split('/\s+/', trim((string)$fullName));
    $parts = array_values(array_filter($parts));
    if (!$parts) return 'V';
    $first = function_exists('substr') ? substr($parts[0], 0, 1) : substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? (function_exists('substr') ? substr(end($parts), 0, 1) : substr(end($parts), 0, 1)) : '';
    return strtoupper($first . $last);
}

function current_user_record($conn) {
    static $cache = [];
    $uid = (int)current_user_id();
    if (!$uid || !$conn) return null;
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    $stmt = $conn->prepare("SELECT id,full_name,email,phone,address,emergency_contact,emergency_contact_name,emergency_contact_phone,role,status,profile_photo,deleted_at FROM users WHERE id=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $cache[$uid] = $stmt->get_result()->fetch_assoc() ?: null;
    return $cache[$uid];
}

function user_avatar_markup($user, $class = '') {
    $name = $user['full_name'] ?? 'Vetrix User';
    $photo = trim((string)($user['profile_photo'] ?? ''));
    $classes = trim('user-avatar ' . $class);
    if ($photo !== '') {
        return '<span class="' . e($classes) . '"><img src="' . e(app_url($photo)) . '" alt="' . e($name) . '"></span>';
    }
    return '<span class="' . e($classes) . '" aria-label="' . e($name) . '">' . e(user_initials($name)) . '</span>';
}

function profile_path_for_role($role = null) {
    $role = $role ?? ($_SESSION['role'] ?? '');
    return 'account/profile.php';
}

function default_pet_icon($species) {
    $species = strtolower(trim((string)$species));
    if (strpos($species, 'cat') !== false) return 'cat';
    if (strpos($species, 'dog') !== false) return 'dog';
    return 'other';
}

function pet_avatar_markup($pet, $class = '') {
    $photo = trim((string)($pet['pet_photo'] ?? ''));
    $name = $pet['name'] ?? 'Pet';
    $kind = default_pet_icon($pet['species'] ?? '');
    $classes = trim('pet-avatar pet-avatar-' . $kind . ' ' . $class);
    $photoFile = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $photo), '/');
    if ($photo !== '' && is_file($photoFile)) {
        return '<span class="' . e($classes) . '"><img src="' . e(app_url($photo)) . '" alt="' . e($name) . '"></span>';
    }
    $svg = [
        'cat' => '<path d="M6 8 4 3l5 3a8 8 0 0 1 6 0l5-3-2 5a8 8 0 1 1-12 0Z"/><path d="M9 13h.01M15 13h.01M10 17h4M12 14v2"/>',
        'other' => '<path d="M8.5 11.5c-1.5-1.2-2.6-1-3.2-.2-.8 1.1-.2 2.6 1.3 3.4M15.5 11.5c1.5-1.2 2.6-1 3.2-.2.8 1.1.2 2.6-1.3 3.4"/><path d="M12 10c-2.8 0-5 2.3-5 5.1C7 18 9.2 20 12 20s5-2 5-4.9C17 12.3 14.8 10 12 10Z"/><circle cx="8" cy="7" r="2"/><circle cx="16" cy="7" r="2"/>',
        'dog' => '<path d="M7 8 4 5v7M17 8l3-3v7"/><path d="M6 9a6 6 0 0 1 12 0v5a6 6 0 0 1-12 0Z"/><path d="M9 12h.01M15 12h.01M10 16c1 1 3 1 4 0M12 14v2"/>',
    ][$kind];
    return '<span class="' . e($classes) . '" aria-label="Default ' . e($kind) . ' image"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg></span>';
}

function upload_image_file($file, $folder, $prefix = 'image') {
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'path' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'The image upload did not complete.'];
    if ((int)$file['size'] > 3 * 1024 * 1024) return ['ok' => false, 'error' => 'The image must be 3 MB or smaller.'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) return ['ok' => false, 'error' => 'Use a JPG, PNG, or WEBP image.'];
    $safeFolder = trim($folder, '/');
    $targetDir = dirname(__DIR__) . '/' . $safeFolder;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) return ['ok' => false, 'error' => 'The upload folder could not be created.'];
    $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $target = $targetDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) return ['ok' => false, 'error' => 'The image could not be saved.'];
    return ['ok' => true, 'path' => $safeFolder . '/' . $name];
}

function clinic_payment_qr_path() {
    $dir = dirname(__DIR__) . '/uploads/payment';
    if (!is_dir($dir)) return null;
    $pointer = $dir . '/current_qr.txt';
    if (is_file($pointer)) {
        $name = basename(trim((string)file_get_contents($pointer)));
        $pointed = $name !== '' ? $dir . '/' . $name : '';
        $ext = strtolower(pathinfo($pointed, PATHINFO_EXTENSION));
        if ($pointed !== '' && is_file($pointed) && filesize($pointed) > 0 && in_array($ext, ['png','jpg','jpeg','webp'], true)) {
            return 'uploads/payment/' . $name;
        }
    }
    $candidates = [];
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (!is_file($file) || filesize($file) <= 0) continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) continue;
        $base = basename($file);
        $priority = preg_match('/^clinic_payment_qr\.(?:png|jpe?g|webp)$/i', $base) ? 2 : 1;
        $candidates[] = ['file'=>$file,'priority'=>$priority,'mtime'=>(int)(filemtime($file) ?: 0)];
    }
    if (!$candidates) return null;
    usort($candidates, static fn($a,$b) => ($b['priority'] <=> $a['priority']) ?: ($b['mtime'] <=> $a['mtime']));
    return 'uploads/payment/' . basename($candidates[0]['file']);
}

function clinic_payment_qr_src() {
    $path = clinic_payment_qr_path();
    if (!$path) return null;
    $full = dirname(__DIR__) . '/' . $path;
    $version = is_file($full) ? (string)filemtime($full) : (string)time();
    return app_url($path) . '?v=' . rawurlencode($version);
}

function upload_proof_file($file, $folder = 'uploads/proofs', $prefix = 'proof') {
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'path' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'The proof upload did not complete.'];
    if ((int)$file['size'] > 5 * 1024 * 1024) return ['ok' => false, 'error' => 'The proof file must be 5 MB or smaller.'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) return ['ok' => false, 'error' => 'Use a JPG, PNG, WEBP, or PDF proof file.'];
    $safeFolder = trim($folder, '/');
    $targetDir = dirname(__DIR__) . '/' . $safeFolder;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) return ['ok' => false, 'error' => 'The proof upload folder could not be created.'];
    $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $target = $targetDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) return ['ok' => false, 'error' => 'The proof file could not be saved.'];
    return ['ok' => true, 'path' => $safeFolder . '/' . $name];
}

function save_clinic_payment_qr($file) {
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok'=>false,'error'=>'Choose a JPG, PNG, or WEBP QR image first.'];
    $upload = upload_image_file($file, 'uploads/payment', 'clinic_payment_qr_upload');
    if (empty($upload['ok']) || empty($upload['path'])) return $upload;
    $source = dirname(__DIR__) . '/' . $upload['path'];
    $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    $dir = dirname($source);
    foreach (glob($dir . '/clinic_payment_qr.*') ?: [] as $old) @unlink($old);
    $target = $dir . '/clinic_payment_qr.' . $ext;
    if (!@rename($source, $target)) {
        if (!@copy($source, $target)) return ['ok'=>false,'error'=>'The QR image could not be finalized.'];
        @unlink($source);
    }
    clearstatcache(true, $target);
    if (!is_file($target) || filesize($target) <= 0) return ['ok'=>false,'error'=>'The QR image was saved but could not be read back.'];
    @file_put_contents($dir . '/current_qr.txt', basename($target), LOCK_EX);
    return ['ok'=>true,'path'=>'uploads/payment/clinic_payment_qr.' . $ext];
}

function table_exists($conn, $table) {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$safe'");
    return $result && $result->num_rows > 0;
}

function column_exists($conn, $table, $column) {
    $safeTable = str_replace('`', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return $result && $result->num_rows > 0;
}

function appointment_slot_conflict($conn, $appointmentId, $vetId, $scheduledDate, $durationMinutes = 30) {
    $appointmentId = (int)$appointmentId;
    $vetId = (int)$vetId;
    $durationMinutes = max(15, min(240, (int)$durationMinutes));
    if (!$vetId || !$scheduledDate) return ['conflict' => false, 'message' => ''];
    $endDate = date('Y-m-d H:i:s', strtotime($scheduledDate . ' +' . $durationMinutes . ' minutes'));
    $stmt = $conn->prepare("SELECT a.id,p.name pet_name FROM appointments a JOIN pets p ON a.pet_id=p.id WHERE a.id<>? AND a.assigned_vet_id=? AND a.status='approved' AND a.scheduled_date IS NOT NULL AND a.scheduled_date < ? AND DATE_ADD(a.scheduled_date, INTERVAL a.duration_minutes MINUTE) > ? LIMIT 1");
    $stmt->bind_param('iiss', $appointmentId, $vetId, $endDate, $scheduledDate);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) return ['conflict' => true, 'message' => 'The veterinarian already has an appointment for ' . $row['pet_name'] . ' during this time.'];
    if (table_exists($conn, 'staff_availability')) {
        $stmt = $conn->prepare("SELECT block_type,reason FROM staff_availability WHERE user_id=? AND event_kind='unavailable' AND starts_at < ? AND ends_at > ? AND COALESCE(reason,'') NOT LIKE '[Pending]%' AND COALESCE(reason,'') NOT LIKE '[Denied]%' AND COALESCE(reason,'') NOT LIKE '[Removed]%' LIMIT 1");
        $stmt->bind_param('iss', $vetId, $endDate, $scheduledDate);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) return ['conflict' => true, 'message' => 'The veterinarian is unavailable during this time (' . ucfirst($row['block_type']) . ').'];
    }
    return ['conflict' => false, 'message' => ''];
}


function appointment_attention_details($appointment, $viewerRole = null) {
    $status = strtolower((string)($appointment['status'] ?? ''));
    $requested = !empty($appointment['requested_date']) ? strtotime((string)$appointment['requested_date']) : false;
    $scheduled = !empty($appointment['scheduled_date']) ? strtotime((string)$appointment['scheduled_date']) : false;
    $vetId = (int)($appointment['assigned_vet_id'] ?? 0);
    $isClient = strtolower((string)$viewerRole) === 'client';
    $now = time();
    if ($status === 'pending' && $requested && $requested < $now) {
        $resolution = $isClient
            ? ['Open My Appointments and review the request details.','Contact the clinic to request a new future time or cancellation.','Check notifications for the clinic’s updated decision.']
            : ['Open the appointment request.','Choose a new future schedule and veterinarian, then approve it, or reject the request when it cannot be accommodated.','Save the update so the client receives the corrected status.'];
        return ['needs_attention'=>true,'message'=>'The requested appointment time has passed while the request is still pending.','resolution'=>$resolution];
    }
    if ($status === 'approved' && !$scheduled) {
        $resolution = $isClient
            ? ['Check your notifications for a clinic schedule update.','Contact the clinic if no date and time have been provided.','Return to My Appointments after the clinic confirms the schedule.']
            : ['Open the appointment record.','Set a valid future date and time and assign a veterinarian.','Save the schedule to clear the attention notice.'];
        return ['needs_attention'=>true,'message'=>'This approved appointment does not have a clinic schedule.','resolution'=>$resolution];
    }
    if ($status === 'approved' && !$vetId) {
        $resolution = $isClient
            ? ['Keep the approved appointment details available.','Wait for the clinic to assign a veterinarian and send an update.','Contact the clinic if the scheduled visit is approaching without an assignment.']
            : ['Open the appointment record.','Assign an available veterinarian and verify the scheduled time.','Save the appointment to clear the attention notice.'];
        return ['needs_attention'=>true,'message'=>'This approved appointment does not have an assigned veterinarian.','resolution'=>$resolution];
    }
    if ($status === 'approved' && $scheduled && $scheduled < $now) {
        $issueText = mb_strtolower(trim((string)($appointment['admin_notes'] ?? '') . ' ' . (string)($appointment['reason'] ?? '')));
        if (preg_match('/resched|move(?:d)? to another|new schedule|change.*date/', $issueText)) {
            $resolution = $isClient
                ? ['Open My Appointments and review the clinic note.','Contact the clinic or use the available reschedule control to choose a future time.','Check notifications for the confirmed replacement schedule.']
                : ['Open the appointment record.','Choose a valid future date and available veterinarian.','Save the new schedule and notify the client.'];
            return ['needs_attention'=>true,'message'=>'This appointment needs a new schedule because its previous time has passed.','resolution'=>$resolution];
        }
        if (preg_match('/cancel|did not attend|no[ -]?show/', $issueText)) {
            $resolution = $isClient
                ? ['Open My Appointments and review the clinic note.','Contact the clinic to confirm the cancellation when needed.','Check notifications for the final cancelled status.']
                : ['Open the appointment record.','Confirm that the visit did not occur.','Mark the appointment cancelled and save the update.'];
            return ['needs_attention'=>true,'message'=>'This past appointment is waiting for cancellation confirmation.','resolution'=>$resolution];
        }
        if (preg_match('/complete|consultation done|visit done|attended/', $issueText)) {
            $resolution = $isClient
                ? ['Check My Appointments for the final visit status.','Contact the clinic if the completed visit is still shown as approved.','Review Records after the clinic finishes the appointment update.']
                : ['Open the appointment record.','Confirm that the consultation was completed.','Mark it completed and create the related medical record when required.'];
            return ['needs_attention'=>true,'message'=>'The visit appears to be finished but still needs to be marked completed.','resolution'=>$resolution];
        }
        $resolution = $isClient
            ? ['Open My Appointments and review the appointment details.','Contact the clinic to confirm whether the visit was completed, cancelled, or needs a new schedule.','Check notifications for the corrected status.']
            : ['Open the appointment record.','Confirm whether the visit occurred.','Mark it completed or cancelled, or move it to a valid future schedule, then save.'];
        return ['needs_attention'=>true,'message'=>'The appointment time has passed, but its final outcome has not been recorded.','resolution'=>$resolution];
    }
    return ['needs_attention'=>false,'message'=>'','resolution'=>[]];
}

function workforce_reason_state($reason) {
    $reason = (string)$reason;
    foreach (['Pending','Approved','Denied','Removed'] as $state) {
        if (str_starts_with($reason, '['.$state.']')) return strtolower($state);
    }
    return 'approved';
}

function workforce_reason_text($reason) {
    return trim((string)preg_replace('/^\[(?:Pending|Approved|Denied|Removed)\]\s*/i', '', (string)$reason));
}

function pagination_values($default = 5, $max = 20, $choices = null) {
    $requested = strtolower(trim((string)($_GET['per_page'] ?? $default)));
    $choices = $choices ?: [5, 10, 15, 20, 'full'];
    $allowed = array_map('strval', $choices);
    if (!in_array($requested, $allowed, true)) $requested = (string)$default;
    $perPage = $requested === 'full' ? 1000000 : max(1, min($max > 0 ? $max : PHP_INT_MAX, (int)$requested));
    $page = $requested === 'full' ? 1 : max(1, (int)($_GET['page'] ?? 1));
    return [$page, $perPage, ($page - 1) * $perPage];
}

function per_page_value($perPage) {
    return (int)$perPage >= 1000000 ? 'full' : (string)(int)$perPage;
}

function per_page_label($perPage) {
    return (int)$perPage >= 1000000 ? 'All records' : (int)$perPage . ' records';
}

function render_per_page_options($perPage, $choices = null) {
    $current = per_page_value($perPage);
    $choices = $choices ?: [5, 10, 15, 20, 'full'];
    $html = '';
    foreach ($choices as $choice) {
        $value = (string)$choice;
        $label = $value === 'full' ? 'Full' : $value;
        $html .= '<option value="' . e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

function medical_note_type(string $notes): string {
    if (preg_match('/^\[\[note:(positive|negative|follow_up|neutral)\]\]\s*/i', $notes, $match)) {
        return strtolower($match[1]);
    }
    $plain = strtolower(trim($notes));
    if ($plain === '') return 'neutral';
    if (preg_match('/emergency|urgent|critical|collapse|severe|not improving|poor response/', $plain)) return 'negative';
    if (preg_match('/follow[- ]?up|return for|recheck|monitor|check for|reassess/', $plain)) return 'follow_up';
    if (preg_match('/normal|healthy|stable|clear|improved|improving|better|recovering|good appetite|active appetite|no crystals|no adverse/', $plain)) return 'positive';
    return 'neutral';
}

function medical_note_text(string $notes): string {
    return trim((string)preg_replace('/^\[\[note:(?:positive|negative|follow_up|neutral)\]\]\s*/i', '', $notes));
}

function medical_note_pack(string $notes, string $type): string {
    $type = in_array($type, ['positive','negative','follow_up','neutral'], true) ? $type : 'neutral';
    $plain = medical_note_text($notes);
    return '[[note:' . $type . ']]' . ($plain === '' ? '' : ' ' . $plain);
}

function generate_inventory_sku(mysqli $conn, string $category = '', string $itemName = '', int $excludeId = 0): string {
    $prefixMap = [
        'Veterinary medicines'=>'VMED','Veterinary supplements'=>'VSUP','Ear care'=>'EAR','Ectoparasite control'=>'ECTO',
        'Milk replacers'=>'MILK','Energy supplements'=>'ENER','Pet treats'=>'TREAT','Pet hygiene'=>'HYGI',
        'Wet cat food'=>'CATF','Wet dog food'=>'DOGF','Cat litter'=>'LITT','Skin care'=>'SKIN',
        'Dental care'=>'DENT','Grooming supplies'=>'GROOM','Pet accessories'=>'ACC'
    ];
    $prefix = $prefixMap[$category] ?? '';
    if ($prefix === '') {
        $words = preg_split('/[^A-Za-z0-9]+/', trim($category !== '' ? $category : $itemName));
        $parts = [];
        foreach ($words ?: [] as $word) if ($word !== '') $parts[] = strtoupper(substr($word, 0, 1));
        $prefix = substr(implode('', $parts), 0, 5) ?: 'ITEM';
    }
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper($prefix)) ?: 'ITEM';
    $like = $prefix.'-%';
    $sql = "SELECT sku FROM inventory_items WHERE sku LIKE ?".($excludeId > 0 ? " AND id<>?" : "")." ORDER BY id";
    $stmt = $conn->prepare($sql);
    if ($excludeId > 0) $stmt->bind_param('si', $like, $excludeId); else $stmt->bind_param('s', $like);
    $stmt->execute();
    $used = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (preg_match('/^'.preg_quote($prefix,'/').'-(\d+)$/', (string)$row['sku'], $m)) $used[(int)$m[1]] = true;
    }
    $number = 1;
    while (isset($used[$number])) $number++;
    return $prefix.'-'.str_pad((string)$number, 3, '0', STR_PAD_LEFT);
}

function ensure_inventory_skus(mysqli $conn): void {
    $result = $conn->query("SELECT id,category,item_name FROM inventory_items WHERE sku IS NULL OR TRIM(sku)='' ORDER BY id");
    if (!$result) return;
    $update = $conn->prepare("UPDATE inventory_items SET sku=? WHERE id=? AND (sku IS NULL OR TRIM(sku)='')");
    if (!$update) return;
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['id'];
        $sku = generate_inventory_sku($conn, (string)$row['category'], (string)$row['item_name'], $id);
        $update->bind_param('si', $sku, $id);
        $update->execute();
    }
}

function render_pagination($page, $perPage, $total, $extra = []) {
    $perPage = max(1, (int)$perPage);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min((int)$page, $pages));
    if ($pages <= 1) return '';

    $base = array_merge($_GET, $extra);
    $anchor = isset($base['_anchor']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$base['_anchor']) : '';
    unset($base['_anchor'], $base['page']);
    $suffix = $anchor !== '' ? '#' . $anchor : '';

    $href = function(int $target) use ($base, $suffix): string {
        $query = $base;
        $query['page'] = max(1, $target);
        return '?' . e(http_build_query($query)) . $suffix;
    };

    $html = '<nav class="data-pagination natural-pagination" aria-label="Pagination" data-pagination-pages="' . $pages . '" data-pagination-current="' . $page . '" data-pagination-anchor="' . e($anchor) . '">';
    $html .= '<div class="pagination-current-page"><label>Page <input class="pagination-page-input" type="number" min="1" max="' . $pages . '" value="' . $page . '" inputmode="numeric" aria-label="Current page"> <span>of ' . $pages . '</span></label></div>';
    $html .= '<div class="pagination-nav">';

    if ($page > 1) {
        $html .= '<a class="page-step page-prev" href="' . $href($page - 1) . '" aria-label="Previous page">' . ui_icon('chevron-left') . '<span>Previous</span></a>';
    } else {
        $html .= '<span class="page-step page-prev disabled" aria-disabled="true">' . ui_icon('chevron-left') . '<span>Previous</span></span>';
    }

    $html .= '<div class="pagination-pages">';
    foreach ([$page - 1, $page, $page + 1] as $number) {
        if ($number < 1 || $number > $pages) continue;
        if ($number === $page) {
            $html .= '<span class="page-number current" aria-current="page">' . $number . '</span>';
        } else {
            $html .= '<a class="page-number" href="' . $href($number) . '" aria-label="Page ' . $number . '">' . $number . '</a>';
        }
    }
    $html .= '</div>';

    if ($page < $pages) {
        $html .= '<a class="page-step page-next" href="' . $href($page + 1) . '" aria-label="Next page"><span>Next</span>' . ui_icon('chevron-right') . '</a>';
    } else {
        $html .= '<span class="page-step page-next disabled" aria-disabled="true"><span>Next</span>' . ui_icon('chevron-right') . '</span>';
    }
    $html .= '</div></nav>';
    return $html;
}

function flash($key, $message = null) {
    if ($message !== null) { $_SESSION['flash'][$key] = $message; return; }
    if (isset($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

function badge($status) {
    $status = strtolower($status ?? '');
    $map = [
        'pending' => 'warning', 'approved' => 'success', 'completed' => 'primary',
        'cancelled' => 'danger', 'active' => 'success', 'inactive' => 'secondary',
        'read' => 'secondary', 'unread' => 'info', 'rejected' => 'danger',
        'in_person_confirmation' => 'info', 'queued' => 'warning', 'sent' => 'success', 'failed' => 'danger'
    ];
    $class = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class='badge text-bg-$class'>" . e($label) . "</span>";
}

function log_action($conn, $action, $entity_type, $entity_id = null, $details = '') {
    $actor = current_user_id();
    $stmt = $conn->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)");
    $stmt->bind_param("issis", $actor, $action, $entity_type, $entity_id, $details);
    $stmt->execute();
}

function staff_private_client_access_status($conn, $staff_id = null) {
    $staff_id = (int)($staff_id ?? current_user_id());
    $empty = ['state' => 'none', 'created_at' => null, 'granted_until' => null, 'details' => ''];
    if (!$conn || !$staff_id) return $empty;

    $actions = [
        'Requested client private data access',
        'Granted client private data access',
        'Denied client private data access',
        'Revoked client private data access',
        'Expired client private data access',
    ];
    $placeholders = implode(',', array_fill(0, count($actions), '?'));
    $stmt = $conn->prepare("SELECT action,details,created_at FROM audit_logs WHERE entity_type='staff_private_access' AND entity_id=? AND action IN ($placeholders) ORDER BY id DESC LIMIT 1");
    if (!$stmt) return $empty;
    $types = 'i' . str_repeat('s', count($actions));
    $params = array_merge([$staff_id], $actions);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return $empty;

    $stateMap = [
        'Requested client private data access' => 'pending',
        'Granted client private data access' => 'approved',
        'Denied client private data access' => 'denied',
        'Revoked client private data access' => 'revoked',
        'Expired client private data access' => 'expired',
    ];
    $state = $stateMap[$row['action']] ?? 'none';
    $grantedUntil = null;
    if ($state === 'approved') {
        $grantedAt = strtotime((string)$row['created_at']);
        $grantedUntil = $grantedAt ? date('Y-m-d H:i:s', $grantedAt + 86400) : null;
        if (!$grantedUntil || strtotime($grantedUntil) <= time()) {
            log_action($conn, 'Expired client private data access', 'staff_private_access', $staff_id, 'The approved 24-hour private client data access period ended automatically.');
            notify_user($conn, $staff_id, 'Private Data Access Expired', 'Your 24-hour access to private client and pet owner information has ended. Submit a new request when access is required again.', 'system', 'staff/clients.php');
            $state = 'expired';
            $grantedUntil = null;
        }
    }
    return [
        'state' => $state,
        'created_at' => $row['created_at'] ?? null,
        'granted_until' => $grantedUntil,
        'details' => $row['details'] ?? '',
    ];
}

function staff_has_private_client_access($conn, $staff_id = null) {
    return staff_private_client_access_status($conn, $staff_id)['state'] === 'approved';
}

function request_staff_private_client_access($conn, $staff_id = null, $context = 'Client and pet private information') {
    $staff_id = (int)($staff_id ?? current_user_id());
    if (!$conn || !$staff_id) return false;
    $staff = $conn->prepare("SELECT full_name FROM users WHERE id=? AND role='staff' AND status='active' AND deleted_at IS NULL LIMIT 1");
    $staff->bind_param('i', $staff_id);
    $staff->execute();
    $staffRow = $staff->get_result()->fetch_assoc();
    if (!$staffRow) return false;

    $current = staff_private_client_access_status($conn, $staff_id);
    if (in_array($current['state'], ['pending', 'approved'], true)) return true;

    $context = trim((string)$context) ?: 'Client and pet private information';
    log_action($conn, 'Requested client private data access', 'staff_private_access', $staff_id, 'Requested access for: ' . $context . '.');
    $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active' AND deleted_at IS NULL");
    if ($admins) {
        while ($admin = $admins->fetch_assoc()) {
            notify_user($conn, (int)$admin['id'], 'Staff Private Data Access Request', $staffRow['full_name'] . ' requested temporary access to private client and pet owner information. Review the staff account to approve or deny the request.', 'system', 'admin/users.php?role=staff');
        }
    }
    return true;
}

function set_staff_private_client_access($conn, $staff_id, $allowed, $denied = false) {
    $staff_id = (int)$staff_id;
    if (!$conn || !$staff_id) return false;
    $stmt = $conn->prepare("SELECT id FROM users WHERE id=? AND role='staff' AND deleted_at IS NULL LIMIT 1");
    $stmt->bind_param('i', $staff_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) return false;

    if ($denied) {
        log_action($conn, 'Denied client private data access', 'staff_private_access', $staff_id, 'Administrator denied the pending staff request for private client and pet owner information.');
        notify_user($conn, $staff_id, 'Private Data Access Request Denied', 'Your request to view private client and pet owner information was not approved.', 'system', 'staff/clients.php');
        return true;
    }
    if ($allowed) {
        $until = date('M d, Y h:i A', time() + 86400);
        log_action($conn, 'Granted client private data access', 'staff_private_access', $staff_id, 'Administrator approved temporary access to private client and pet owner information for 24 hours, through ' . $until . '.');
        notify_user($conn, $staff_id, 'Private Data Access Approved', 'Your request was approved. You can view private client and pet owner information for 24 hours, through ' . $until . '.', 'system', 'staff/clients.php');
        return true;
    }

    log_action($conn, 'Revoked client private data access', 'staff_private_access', $staff_id, 'Administrator removed temporary access to private client and pet owner information.');
    notify_user($conn, $staff_id, 'Private Data Access Removed', 'Your temporary access to private client and pet owner information was removed.', 'system', 'staff/clients.php');
    return true;
}

function get_nav_notification_count($conn, $role = null, $user_id = null) {
    if (!$conn) return 0;
    $user_id = (int)($user_id ?? current_user_id());
    if (!$user_id) return 0;
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id=? AND status='unread' AND title NOT LIKE '[Deleted] %'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function queue_sms_alert($conn, $user_id, $message, $type = 'system', $related_type = null, $related_id = null) {
    // GSM/SMS delivery is intentionally disabled until a gateway is integrated.
    return false;
}


function notification_action_has_exact_target($action_url) {
    $value = trim((string)($action_url ?? ''));
    if ($value === '' || str_contains($value, '..') || preg_match('/^(?:[a-z]+:|\/\/)/i', $value)) return false;
    $parts = parse_url($value);
    if ($parts === false) return false;
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    foreach (['appointment_id','pet_id','client_id','feedback_id','vaccination_id','transaction_id','record_id','request_id','edit_request_id','notification_id','order_id','token'] as $key) {
        if (isset($query[$key]) && trim((string)$query[$key]) !== '') return true;
    }
    $path = (string)($parts['path'] ?? $value);
    return (bool)preg_match('/(?:verify_account\.php|download_app\.php|profile\.php)$/i', $path);
}

function notify_user($conn, $user_id, $title, $message, $type='system', $action_url = null) {
    $allowed = ['system','appointment','vaccine','record','qr','feedback'];
    if ($type === 'pet') $type = 'record';
    if (!in_array($type, $allowed, true)) $type = 'system';
    $actor = current_user_id() ?: null;
    $actionUrl = trim((string)($action_url ?? ''));
    if ($actionUrl !== '' && (str_contains($actionUrl, '..') || preg_match('/^(?:[a-z]+:|\/\/)/i', $actionUrl))) $actionUrl = '';
    $actionUrl = $actionUrl !== '' ? ltrim($actionUrl, '/') : null;
    $hasActionUrl = column_exists($conn, 'notifications', 'action_url');
    $hasCreatedBy = column_exists($conn, 'notifications', 'created_by');
    if ($hasActionUrl && $hasCreatedBy) {
        $stmt = $conn->prepare("INSERT INTO notifications(user_id,title,message,type,action_url,created_by) VALUES(?,?,?,?,?,?)");
        $stmt->bind_param("issssi", $user_id, $title, $message, $type, $actionUrl, $actor);
    } elseif ($hasActionUrl) {
        $stmt = $conn->prepare("INSERT INTO notifications(user_id,title,message,type,action_url) VALUES(?,?,?,?,?)");
        $stmt->bind_param("issss", $user_id, $title, $message, $type, $actionUrl);
    } elseif ($hasCreatedBy) {
        $stmt = $conn->prepare("INSERT INTO notifications(user_id,title,message,type,created_by) VALUES(?,?,?,?,?)");
        $stmt->bind_param("isssi", $user_id, $title, $message, $type, $actor);
    } else {
        $stmt = $conn->prepare("INSERT INTO notifications(user_id,title,message,type) VALUES(?,?,?,?)");
        $stmt->bind_param("isss", $user_id, $title, $message, $type);
    }
    if (!$stmt->execute()) return false;
    return (int)$stmt->insert_id;
}

function record_pet_update($conn, $pet_id, $summary, $old_values = null, $new_values = null) {
    $details = trim((string)$summary);
    if ($old_values !== null || $new_values !== null) {
        $details .= ($details !== '' ? "\n" : '') . 'Previous: ' . (is_string($old_values) ? $old_values : json_encode($old_values, JSON_UNESCAPED_UNICODE));
        $details .= "\nUpdated: " . (is_string($new_values) ? $new_values : json_encode($new_values, JSON_UNESCAPED_UNICODE));
    }
    log_action($conn, 'Updated pet profile', 'pet', (int)$pet_id, $details);
}

function split_emergency_contact($value) {
    $value = trim((string)$value);
    if ($value === '') return ['', ''];
    foreach ([' | ', ' — ', ' - '] as $separator) {
        if (strpos($value, $separator) !== false) {
            [$name, $phone] = array_map('trim', explode($separator, $value, 2));
            return [$name, $phone];
        }
    }
    return [$value, ''];
}

function emergency_contact_display($user) {
    $name = trim((string)($user['emergency_contact_name'] ?? ''));
    $phone = trim((string)($user['emergency_contact_phone'] ?? ''));
    if ($name === '' && $phone === '') {
        [$name, $phone] = split_emergency_contact($user['emergency_contact'] ?? '');
    }
    if ($name !== '' && $phone !== '') return $name . ' · ' . $phone;
    return $name !== '' ? $name : $phone;
}

function ensure_vetrix_schema($conn) {
    if (!$conn || !table_exists($conn, 'users')) return;
    $userColumns = [
        'emergency_contact_name' => "VARCHAR(120) NULL AFTER emergency_contact",
        'emergency_contact_phone' => "VARCHAR(40) NULL AFTER emergency_contact_name",
        'deleted_at' => "DATETIME NULL AFTER locked_until",
    ];
    foreach ($userColumns as $name => $definition) {
        if (!column_exists($conn, 'users', $name)) $conn->query("ALTER TABLE users ADD COLUMN `$name` $definition");
    }
    if (table_exists($conn, 'staff_availability')) {
        $conn->query("ALTER TABLE staff_availability MODIFY block_type ENUM('break','lunch','leave','sick','training','personal','on_duty','consultation','other') NOT NULL DEFAULT 'other'");
        $availabilityColumns = [
            'event_kind' => "ENUM('available','unavailable','clinic_schedule') NOT NULL DEFAULT 'unavailable' AFTER user_id",
            'is_recurring' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER reason",
            'recurrence_group' => "VARCHAR(64) NULL AFTER is_recurring",
        ];
        foreach ($availabilityColumns as $name => $definition) {
            if (!column_exists($conn, 'staff_availability', $name)) $conn->query("ALTER TABLE staff_availability ADD COLUMN `$name` $definition");
        }
    }
}

function create_client_otp($conn, $user_id, $purpose = 'account_activation') {
    $otp = (string)random_int(100000, 999999);
    $token_hash = password_hash($otp, PASSWORD_DEFAULT);
    if ($token_hash === false) return false;
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $cancel = $conn->prepare("UPDATE account_verification_tokens SET status='expired' WHERE user_id=? AND purpose=? AND used_at IS NULL AND status='active'");
    if (!$cancel) return false;
    if (!$cancel->bind_param("is", $user_id, $purpose) || !$cancel->execute()) {
        $cancel->close();
        return false;
    }
    $cancel->close();

    $stmt = $conn->prepare("INSERT INTO account_verification_tokens(user_id, token_hash, purpose, expires_at, created_by) VALUES(?,?,?,?,?)");
    if (!$stmt) return false;
    $actor = current_user_id();
    if (!$stmt->bind_param("isssi", $user_id, $token_hash, $purpose, $expires_at, $actor) || !$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $stmt->close();
    return $otp;
}

function mail_delivery_ready(&$reason = null) {
    $reason = null;
    $configFile = __DIR__ . '/../config/mail.php';
    $mailerFile = __DIR__ . '/smtp_mailer.php';

    if (!is_file($configFile)) {
        $reason = 'Mail configuration file was not found.';
        return false;
    }
    require_once $configFile;

    if (APP_MAIL_TRANSPORT === 'brevo') {
        require_once __DIR__ . '/http_mailer.php';
        return brevo_mail_ready($reason);
    }
    if (APP_MAIL_TRANSPORT !== 'smtp') {
        $reason = 'Unsupported mail transport.';
        return false;
    }

    if (!is_file($mailerFile)) {
        $reason = 'SMTP mailer file was not found.';
        return false;
    }
    require_once $mailerFile;

    $username = defined('APP_MAIL_SMTP_USERNAME') ? trim((string)APP_MAIL_SMTP_USERNAME) : '';
    $password = defined('APP_MAIL_SMTP_PASSWORD') ? preg_replace('/\s+/', '', (string)APP_MAIL_SMTP_PASSWORD) : '';
    $from = defined('APP_MAIL_FROM') ? trim((string)APP_MAIL_FROM) : '';
    $host = defined('APP_MAIL_SMTP_HOST') ? trim((string)APP_MAIL_SMTP_HOST) : '';
    $port = defined('APP_MAIL_SMTP_PORT') ? (int)APP_MAIL_SMTP_PORT : 0;
    $secure = defined('APP_MAIL_SMTP_SECURE') ? strtolower(trim((string)APP_MAIL_SMTP_SECURE)) : '';

    if ($username === '' || $password === '') {
        $reason = 'SMTP username or password is missing.';
        return false;
    }
    $placeholder = strtolower($username . ' ' . $password);
    foreach (['your-gmail-address', 'your-16-character', 'yourclinic', 'paste_your'] as $marker) {
        if (strpos($placeholder, $marker) !== false) {
            $reason = 'SMTP credentials still contain example placeholder values.';
            return false;
        }
    }
    if (!defined('APP_MAIL_SEND_REAL_EMAIL') || !APP_MAIL_SEND_REAL_EMAIL) {
        $reason = 'Real email delivery is disabled.';
        return false;
    }
    if (!function_exists('smtp_send_mail')) {
        $reason = 'SMTP sender is unavailable.';
        return false;
    }
    if ($host === '' || $port < 1 || $port > 65535 || !in_array($secure, ['tls', 'ssl'], true)) {
        $reason = 'SMTP host, port, or encryption settings are invalid.';
        return false;
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $reason = 'SMTP sender address is invalid.';
        return false;
    }

    return true;
}

function log_mail_delivery_failure($context, $detail) {
    $safeContext = preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', (string)$context);
    $safeDetail = trim(preg_replace('/[\r\n]+/', ' ', (string)$detail));
    error_log('Vetrix mail delivery failure [' . $safeContext . ']: ' . ($safeDetail !== '' ? $safeDetail : 'Unknown error.'));
}

function send_app_email($conn, $to_email, $to_name, $subject, $body, $type = 'system', $related_type = null, $related_id = null) {
    $deliveryError = null;
    $deliveryReady = mail_delivery_ready($deliveryError);
    $saveToOutbox = defined('APP_MAIL_SAVE_TO_OUTBOX') ? APP_MAIL_SAVE_TO_OUTBOX : true;
    $status = 'failed';
    $error = $deliveryReady ? 'Email delivery was not attempted.' : $deliveryError;
    $method = null;

    if ($deliveryReady) {
        $result = APP_MAIL_TRANSPORT === 'brevo'
            ? brevo_send_mail($to_email, $to_name, $subject, $body)
            : smtp_send_mail($to_email, $to_name, $subject, $body);
        $status = !empty($result['ok']) ? 'sent' : 'failed';
        $error = !empty($result['ok']) ? null : ($result['error'] ?? 'Email sending failed.');
        $method = $result['method'] ?? null;
    }
    if ($status === 'failed') {
        $context = (string)$type;
        if ($related_type !== null || $related_id !== null) {
            $context .= ':' . (string)$related_type . ':' . (string)$related_id;
        }
        log_mail_delivery_failure($context, $error);
    }

    $outboxId = null;
    $isUndeliverableOtp = !$deliveryReady && $type === 'client_approval_otp';
    $outboxBody = $type === 'client_approval_otp' ? '[Sensitive OTP email content omitted.]' : $body;
    if ($saveToOutbox && $conn && !$isUndeliverableOtp) {
        $userId = null;
        $lookup = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
        if ($lookup) {
            $lookup->bind_param("s", $to_email);
            if ($lookup->execute()) {
                $row = $lookup->get_result()->fetch_assoc();
                $userId = $row ? (int)$row['id'] : null;
            }
            $lookup->close();
        }

        // Use the richer schema when the migration has been applied, while
        // retaining compatibility with existing installations.
        $hasContextColumns = false;
        $columnCheck = $conn->query("SHOW COLUMNS FROM email_outbox LIKE 'user_id'");
        if ($columnCheck) {
            $hasContextColumns = $columnCheck->num_rows > 0;
            $columnCheck->free();
        }

        if ($hasContextColumns) {
            $stmt = $conn->prepare("INSERT INTO email_outbox(user_id,related_type,related_id,to_email,to_name,subject,body,email_type,status,error_message) VALUES(?,?,?,?,?,?,?,?,?,?)");
            if ($stmt) {
                $stmt->bind_param("isisssssss", $userId, $related_type, $related_id, $to_email, $to_name, $subject, $outboxBody, $type, $status, $error);
                if ($stmt->execute()) {
                    $outboxId = $stmt->insert_id;
                }
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO email_outbox(to_email,to_name,subject,body,email_type,status,error_message) VALUES(?,?,?,?,?,?,?)");
            if ($stmt) {
                $stmt->bind_param("sssssss", $to_email, $to_name, $subject, $outboxBody, $type, $status, $error);
                if ($stmt->execute()) {
                    $outboxId = $stmt->insert_id;
                }
                $stmt->close();
            }
        }
    }

    $GLOBALS['APP_LAST_MAIL_RESULT'] = array(
        'ok' => ($status === 'sent'),
        'status' => $status,
        'error' => $error,
        'delivery_ready' => $deliveryReady,
        'method' => $method,
        'to' => $to_email,
        'subject' => $subject,
        'type' => $type,
        'related_type' => $related_type,
        'related_id' => $related_id,
        'outbox_id' => $outboxId
    );

    return $GLOBALS['APP_LAST_MAIL_RESULT']['ok'];
}

function get_last_mail_result() {
    return $GLOBALS['APP_LAST_MAIL_RESULT'] ?? null;
}

function flash_mail_result($successText, $fallbackText = '') {
    $mail = get_last_mail_result();
    if ($mail && !empty($mail['ok'])) {
        $detail = !empty($mail['method']) ? ' Method: '.$mail['method'].'.' : '';
        flash('success', $successText . $detail);
        return true;
    }

    $prefix = trim((string)$fallbackText);
    if ($prefix !== '') $prefix .= ' ';
    $emailLabel = $prefix === '' ? 'Email' : 'email';
    $message = empty($mail['delivery_ready'])
        ? $prefix . $emailLabel . ' delivery is unavailable. Configure the mail service and try again.'
        : $prefix . $emailLabel . ' could not be sent. Check the mail service and try again.';
    flash('error', $message);
    return false;
}

function send_client_approval_email($conn, $user, $otp) {
    $subject = 'Vetrix Account Approved - Your One-Time OTP';
    $body = "Hello {$user['full_name']},

" .
            "Your Vetrix client account has been approved by the clinic admin.

" .
            "Your one-time OTP is: {$otp}

" .
            "This OTP can only be used once and will expire in 24 hours. Please enter this code on the account verification page to open your account.

" .
            "Thank you,
Vetrix";
    return send_app_email($conn, $user['email'], $user['full_name'], $subject, $body, 'client_approval_otp', 'user', (int)$user['id']);
}

function send_client_rejection_email($conn, $user) {
    $subject = 'Vetrix Account Verification Update';
    $body = "Hello {$user['full_name']},

" .
            "We are sorry, but your Vetrix client account verification request was rejected.
" .
            "Please contact the clinic or submit correct information if you believe this needs to be reviewed again.

" .
            "Thank you,
Vetrix";
    return send_app_email($conn, $user['email'], $user['full_name'], $subject, $body, 'client_rejected', 'user', (int)$user['id']);
}

function send_client_welcome_email($conn, $user) {
    $subject = 'Welcome to Vetrix!';
    $body = "Hello {$user['full_name']},

" .
            "Welcome to Vetrix. Your client account is now successfully opened.

" .
            "You can now log in, add your pets, book appointments, view medical records, receive reminders, and access your pet QR code.

" .
            "Thank you for trusting Vetrix with your pet care needs.

" .
            "Best regards,
Vetrix";
    return send_app_email($conn, $user['email'], $user['full_name'], $subject, $body, 'client_welcome', 'user', (int)$user['id']);
}

function verify_client_otp($conn, $user_id, $otp) {
    $stmt = $conn->prepare("SELECT * FROM account_verification_tokens WHERE user_id=? AND purpose='account_activation' AND used_at IS NULL AND status='active' ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $token = $stmt->get_result()->fetch_assoc();

    if (!$token) return ['ok' => false, 'message' => 'No active OTP was found. Please ask the admin to resend your OTP.'];
    if (strtotime($token['expires_at']) < time()) {
        $up = $conn->prepare("UPDATE account_verification_tokens SET status='expired' WHERE id=?");
        $up->bind_param("i", $token['id']);
        $up->execute();
        return ['ok' => false, 'message' => 'This OTP has expired. Please ask the admin to resend a new OTP.'];
    }
    if (!password_verify($otp, $token['token_hash'])) {
        return ['ok' => false, 'message' => 'Invalid OTP. Please check the code sent to your Gmail.'];
    }

    $now = date('Y-m-d H:i:s');
    $up = $conn->prepare("UPDATE account_verification_tokens SET used_at=?, status='used' WHERE id=?");
    $up->bind_param("si", $now, $token['id']);
    $up->execute();

    $upUser = $conn->prepare("UPDATE users SET otp_verified_at=?, status='active' WHERE id=? AND role='client'");
    $upUser->bind_param("si", $now, $user_id);
    $upUser->execute();
    return ['ok' => true, 'message' => 'Your account has been verified and is ready to use.'];
}

function pet_age($birth_date) {
    if (!$birth_date) return 'Unknown';
    $dob = new DateTime($birth_date);
    $now = new DateTime();
    $diff = $now->diff($dob);
    return $diff->y . ' yr ' . $diff->m . ' mo';
}


function ensure_review_workflow_schema($conn) {
    $columns = [
        'proof_path' => "VARCHAR(255) NULL AFTER new_value",
        'proof_note' => "TEXT NULL AFTER proof_path",
        'vet_approval_status' => "VARCHAR(30) NOT NULL DEFAULT 'not_required' AFTER proof_note",
        'vet_approved_by' => "INT NULL AFTER vet_approval_status",
        'vet_approved_at' => "DATETIME NULL AFTER vet_approved_by",
        'appeal_status' => "VARCHAR(30) NOT NULL DEFAULT 'none' AFTER vet_approved_at",
        'appeal_note' => "TEXT NULL AFTER appeal_status",
        'appeal_proof_path' => "VARCHAR(255) NULL AFTER appeal_note",
        'appealed_at' => "DATETIME NULL AFTER appeal_proof_path",
    ];
    foreach ($columns as $name => $definition) {
        if (!column_exists($conn, 'edit_requests', $name)) $conn->query("ALTER TABLE edit_requests ADD COLUMN `$name` $definition");
    }

}

function ensure_pos_product_schema($conn) {
    $hasPrice = $conn->query("SHOW COLUMNS FROM inventory_items LIKE 'sale_price'");
    if ($hasPrice && $hasPrice->num_rows === 0) {
        $conn->query("ALTER TABLE inventory_items ADD COLUMN sale_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER category");
    }
    $hasSku = $conn->query("SHOW COLUMNS FROM inventory_items LIKE 'sku'");
    if ($hasSku && $hasSku->num_rows === 0) {
        $conn->query("ALTER TABLE inventory_items ADD COLUMN sku VARCHAR(60) NULL AFTER id");
    }
    $hasUpdated = $conn->query("SHOW COLUMNS FROM inventory_items LIKE 'updated_at'");
    if ($hasUpdated && $hasUpdated->num_rows === 0) {
        $conn->query("ALTER TABLE inventory_items ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }
    $hasPhoto = $conn->query("SHOW COLUMNS FROM inventory_items LIKE 'product_photo'");
    if ($hasPhoto && $hasPhoto->num_rows === 0) {
        $conn->query("ALTER TABLE inventory_items ADD COLUMN product_photo VARCHAR(255) NULL AFTER category");
    }
    $missingSkuRows = $conn->query("SELECT id,item_name,category FROM inventory_items WHERE TRIM(COALESCE(sku,''))='' ORDER BY id");
    while ($missingSkuRows && $item = $missingSkuRows->fetch_assoc()) {
        $sku = generate_inventory_sku($conn, (string)($item['category'] ?? ''), (string)($item['item_name'] ?? ''), (int)$item['id']);
        $stmtSku = $conn->prepare("UPDATE inventory_items SET sku=? WHERE id=? AND TRIM(COALESCE(sku,''))=''");
        if ($stmtSku) {
            $itemId = (int)$item['id'];
            $stmtSku->bind_param('si', $sku, $itemId);
            $stmtSku->execute();
        }
    }
    if (!column_exists($conn, 'pos_transactions', 'payment_method')) $conn->query("ALTER TABLE pos_transactions ADD COLUMN payment_method ENUM('cash','qr') NOT NULL DEFAULT 'cash' AFTER payment_status");
    $conn->query("CREATE TABLE IF NOT EXISTS pos_transaction_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NOT NULL,
        item_id INT NULL,
        item_name VARCHAR(160) NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 1,
        line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (transaction_id) REFERENCES pos_transactions(id) ON DELETE CASCADE,
        FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE SET NULL
    )");
    $conn->query("CREATE TABLE IF NOT EXISTS pos_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NOT NULL UNIQUE,
        receipt_number VARCHAR(40) NOT NULL UNIQUE,
        generated_by INT NULL,
        generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paper_width_mm SMALLINT NOT NULL DEFAULT 80,
        FOREIGN KEY (transaction_id) REFERENCES pos_transactions(id) ON DELETE CASCADE,
        FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $conn->query("DELETE r FROM pos_receipts r JOIN pos_transactions t ON t.id=r.transaction_id WHERE t.payment_status<>'paid'");
    $conn->query("INSERT IGNORE INTO pos_receipts(transaction_id,receipt_number,generated_by,generated_at,paper_width_mm) SELECT id,CONCAT('VTX-',LPAD(id,6,'0')),handled_by,transaction_date,80 FROM pos_transactions WHERE payment_status='paid'");
}

if (isset($conn) && $conn instanceof mysqli) ensure_vetrix_schema($conn);

?>
