<?php
// Gmail SMTP configuration. Keep credentials outside tracked project source.
// Set environment variables or create config/mail.local.php from the supplied example.
$local = [];
$localFile = __DIR__ . '/mail.local.php';
if (is_file($localFile)) {
    $loaded = require $localFile;
    if (is_array($loaded)) $local = $loaded;
}
$getMail = static function (string $env, string $key, $default = '') use ($local) {
    $value = getenv($env);
    if ($value !== false && $value !== '') return $value;
    return array_key_exists($key, $local) ? $local[$key] : $default;
};
$isPlaceholder = static function ($value) {
    $value = strtolower(trim((string)$value));
    if ($value === '') return true;
    foreach (['your-', 'your_', 'yourclinic', 'yourgmail', 'replace-', 'replace_', 'paste-', 'paste_', 'changeme', 'change-me', 'placeholder', 'example.com', '<', '>'] as $marker) {
        if (strpos($value, $marker) !== false) return true;
    }
    return false;
};
$username = trim((string)$getMail('VETRIX_SMTP_USERNAME', 'username', ''));
$password = preg_replace('/\s+/', '', (string)$getMail('VETRIX_SMTP_PASSWORD', 'password', ''));
$enabledRaw = strtolower((string)$getMail('VETRIX_MAIL_ENABLED', 'enabled', 'auto'));
$smtpConfigured = filter_var($username, FILTER_VALIDATE_EMAIL) !== false
    && !$isPlaceholder($username)
    && !$isPlaceholder($password);
$enabledRequested = $enabledRaw === 'auto' || in_array($enabledRaw, ['1','true','yes','on'], true);
$enabled = $enabledRequested && $smtpConfigured;
$defaultCaFile = trim((string)ini_get('openssl.cafile'));

define('APP_MAIL_SEND_REAL_EMAIL', $enabled);
define('APP_MAIL_SMTP_CONFIGURED', $smtpConfigured);
define('APP_MAIL_FROM', (string)$getMail('VETRIX_MAIL_FROM', 'from', $username ?: 'no-reply@vetrix.local'));
define('APP_MAIL_FROM_NAME', (string)$getMail('VETRIX_MAIL_FROM_NAME', 'from_name', 'Vetrix'));
define('APP_MAIL_SMTP_HOST', (string)$getMail('VETRIX_SMTP_HOST', 'host', 'smtp.gmail.com'));
define('APP_MAIL_SMTP_PORT', (int)$getMail('VETRIX_SMTP_PORT', 'port', 587));
define('APP_MAIL_SMTP_SECURE', (string)$getMail('VETRIX_SMTP_SECURE', 'secure', 'tls'));
define('APP_MAIL_SMTP_USERNAME', $username);
define('APP_MAIL_SMTP_PASSWORD', $password);
define('APP_MAIL_SMTP_TIMEOUT', (int)$getMail('VETRIX_SMTP_TIMEOUT', 'timeout', 30));
define('APP_MAIL_SMTP_CA_FILE', trim((string)$getMail('VETRIX_SMTP_CA_FILE', 'ca_file', $defaultCaFile)));
define('APP_MAIL_SAVE_TO_OUTBOX', true);
