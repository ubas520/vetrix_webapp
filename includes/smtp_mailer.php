<?php
/**
 * Gmail SMTP sender for local XAMPP.
 * - No Composer required
 * - Supports SSL 465 and STARTTLS 587
 * - Automatically removes spaces from Gmail App Password
 * - PHP 7.4+ compatible
 */

function smtp_has_text($haystack, $needle) {
    return strpos((string)$haystack, (string)$needle) !== false;
}

function smtp_is_placeholder($value) {
    $value = strtolower(trim((string)$value));
    if ($value === '') return true;
    foreach (array('your-', 'your_', 'yourclinic', 'yourgmail', 'replace-', 'replace_', 'paste-', 'paste_', 'changeme', 'change-me', 'placeholder', 'example.com', '<', '>') as $marker) {
        if (smtp_has_text($value, $marker)) return true;
    }
    return false;
}

function smtp_read_response($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtp_expect($socket, $expectedCodes, &$error, $step) {
    $response = smtp_read_response($socket);
    $code = substr($response, 0, 3);
    if (!in_array($code, (array)$expectedCodes, true)) {
        $error = $step . ' failed: ' . trim($response);
        return false;
    }
    return true;
}

function smtp_command($socket, $command, $expectedCodes, &$error, $step) {
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expectedCodes, $error, $step);
}

function smtp_header_encode($text) {
    $text = (string)$text;
    if ($text === '') return '';
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function smtp_sanitize_email($email) {
    return filter_var(trim((string)$email), FILTER_SANITIZE_EMAIL);
}

function smtp_normalize_body($body) {
    $body = (string)$body;
    $body = str_replace(array("\r\n", "\r"), "\n", $body);
    $body = str_replace("\n.", "\n..", $body); // dot-stuffing
    return str_replace("\n", "\r\n", $body);
}

function smtp_open_socket($host, $port, $secure, $timeout, $caFile, &$error) {
    $errno = 0;
    $errstr = '';
    $remote = ($secure === 'ssl') ? 'ssl://' . $host : $host;

    $sslOptions = array(
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'peer_name' => $host,
        'SNI_enabled' => true,
    );
    $caFile = trim((string)$caFile);
    if ($caFile !== '') {
        if (!is_file($caFile) || !is_readable($caFile)) {
            $error = 'The configured SMTP CA file could not be read.';
            return false;
        }
        $sslOptions['cafile'] = $caFile;
    }
    $context = stream_context_create(array('ssl' => $sslOptions));

    $socket = @stream_socket_client($remote . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        $error = "Could not connect to SMTP server {$host}:{$port}. {$errstr} ({$errno}). Check internet, firewall, and OpenSSL in XAMPP.";
        return false;
    }
    stream_set_timeout($socket, $timeout);
    return $socket;
}

function smtp_send_mail_once($toEmail, $toName, $subject, $body, $host, $port, $secure, $username, $password, $timeout, $fromEmail, $fromName, $caFile) {
    $toEmail = smtp_sanitize_email($toEmail);
    $fromEmail = smtp_sanitize_email($fromEmail);

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return array('ok' => false, 'error' => 'Invalid recipient email address.');
    }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return array('ok' => false, 'error' => 'Invalid sender email address in config/mail.php.');
    }

    $error = '';
    $socket = smtp_open_socket($host, $port, $secure, $timeout, $caFile, $error);
    if (!$socket) return array('ok' => false, 'error' => $error);

    if (!smtp_expect($socket, array('220'), $error, 'SMTP connect')) { fclose($socket); return array('ok' => false, 'error' => $error); }
    if (!smtp_command($socket, 'EHLO localhost', array('250'), $error, 'EHLO')) { fclose($socket); return array('ok' => false, 'error' => $error); }

    if ($secure === 'tls') {
        if (!smtp_command($socket, 'STARTTLS', array('220'), $error, 'STARTTLS')) { fclose($socket); return array('ok' => false, 'error' => $error); }
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return array('ok' => false, 'error' => 'TLS encryption failed. Enable OpenSSL in XAMPP PHP or try SSL port 465.');
        }
        if (!smtp_command($socket, 'EHLO localhost', array('250'), $error, 'EHLO after TLS')) { fclose($socket); return array('ok' => false, 'error' => $error); }
    }

    if (!smtp_command($socket, 'AUTH LOGIN', array('334'), $error, 'AUTH LOGIN')) { fclose($socket); return array('ok' => false, 'error' => $error); }
    if (!smtp_command($socket, base64_encode($username), array('334'), $error, 'SMTP username')) { fclose($socket); return array('ok' => false, 'error' => $error); }
    if (!smtp_command($socket, base64_encode($password), array('235'), $error, 'SMTP password')) {
        fclose($socket);
        return array('ok' => false, 'error' => $error . ' Make sure this is the 16-character Google App Password, not the normal Gmail password.');
    }

    if (!smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', array('250'), $error, 'MAIL FROM')) { fclose($socket); return array('ok' => false, 'error' => $error); }
    if (!smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', array('250', '251'), $error, 'RCPT TO')) { fclose($socket); return array('ok' => false, 'error' => $error); }
    if (!smtp_command($socket, 'DATA', array('354'), $error, 'DATA')) { fclose($socket); return array('ok' => false, 'error' => $error); }

    $safeSubject = smtp_header_encode($subject);
    $safeFromName = smtp_header_encode($fromName);
    $safeToName = trim((string)$toName) !== '' ? smtp_header_encode($toName) : $toEmail;

    $headers = array();
    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = 'From: ' . $safeFromName . ' <' . $fromEmail . '>';
    $headers[] = 'To: ' . $safeToName . ' <' . $toEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'Subject: ' . $safeSubject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'X-Mailer: Vetrix XAMPP SMTP Mailer';

    $message = implode("\r\n", $headers) . "\r\n\r\n" . smtp_normalize_body($body) . "\r\n.";
    fwrite($socket, $message . "\r\n");

    if (!smtp_expect($socket, array('250'), $error, 'Message send')) { fclose($socket); return array('ok' => false, 'error' => $error); }

    @smtp_command($socket, 'QUIT', array('221'), $error, 'QUIT');
    fclose($socket);
    return array('ok' => true, 'error' => null);
}

function smtp_send_mail($toEmail, $toName, $subject, $body) {
    $host = defined('APP_MAIL_SMTP_HOST') ? APP_MAIL_SMTP_HOST : 'smtp.gmail.com';
    $port = defined('APP_MAIL_SMTP_PORT') ? (int)APP_MAIL_SMTP_PORT : 587;
    $secure = defined('APP_MAIL_SMTP_SECURE') ? strtolower(APP_MAIL_SMTP_SECURE) : 'tls';
    $username = defined('APP_MAIL_SMTP_USERNAME') ? trim(APP_MAIL_SMTP_USERNAME) : '';
    $password = defined('APP_MAIL_SMTP_PASSWORD') ? preg_replace('/\s+/', '', APP_MAIL_SMTP_PASSWORD) : '';
    $timeout = defined('APP_MAIL_SMTP_TIMEOUT') ? (int)APP_MAIL_SMTP_TIMEOUT : 30;
    $fromEmail = defined('APP_MAIL_FROM') ? APP_MAIL_FROM : $username;
    $fromName = defined('APP_MAIL_FROM_NAME') ? APP_MAIL_FROM_NAME : 'Vetrix';
    $caFile = defined('APP_MAIL_SMTP_CA_FILE') ? APP_MAIL_SMTP_CA_FILE : trim((string)ini_get('openssl.cafile'));

    if (!filter_var($username, FILTER_VALIDATE_EMAIL) || smtp_is_placeholder($username) || smtp_is_placeholder($password)) {
        return array('ok' => false, 'error' => 'Gmail SMTP credentials are missing, invalid, or still contain placeholder values.');
    }

    $attempts = array();
    $attempts[] = array('host' => $host, 'port' => $port, 'secure' => $secure);

    // Automatic fallback. Some networks block 465, others block 587.
    if (!($port === 587 && $secure === 'tls')) {
        $attempts[] = array('host' => 'smtp.gmail.com', 'port' => 587, 'secure' => 'tls');
    }
    if (!($port === 465 && $secure === 'ssl')) {
        $attempts[] = array('host' => 'smtp.gmail.com', 'port' => 465, 'secure' => 'ssl');
    }

    $errors = array();
    foreach ($attempts as $a) {
        $result = smtp_send_mail_once($toEmail, $toName, $subject, $body, $a['host'], $a['port'], $a['secure'], $username, $password, $timeout, $fromEmail, $fromName, $caFile);
        if (!empty($result['ok'])) {
            return array('ok' => true, 'error' => null, 'method' => $a['secure'] . ':' . $a['port']);
        }
        $errors[] = strtoupper($a['secure']) . ' ' . $a['port'] . ' => ' . ($result['error'] ?? 'Unknown SMTP error');
    }

    return array('ok' => false, 'error' => implode(' | ', $errors));
}
?>
