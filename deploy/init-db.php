<?php
// CLI only: initialize an empty database without executing the local reset SQL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    foreach (['HOST', 'USER', 'PASS', 'NAME'] as $key) {
        if (getenv('VETRIX_DB_' . $key) === false || getenv('VETRIX_DB_' . $key) === '') {
            throw new RuntimeException('Missing VETRIX_DB_' . $key . ' environment variable.');
        }
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(getenv('VETRIX_DB_HOST'), getenv('VETRIX_DB_USER'), getenv('VETRIX_DB_PASS'), getenv('VETRIX_DB_NAME'), (int)(getenv('VETRIX_DB_PORT') ?: 3306));
    $db->set_charset('utf8mb4');
    if ((int)$db->query("SELECT GET_LOCK('vetrix_initial_setup', 60)")->fetch_row()[0] !== 1) {
        throw new RuntimeException('Could not lock database initialization.');
    }

    $sql = file_get_contents(dirname(__DIR__) . '/vetrix.sql');
    preg_match_all('/CREATE TABLE `([^`]+)`\s*\(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $matches);
    if (count($matches[0]) !== 20) {
        throw new RuntimeException('Expected the 20-table Vetrix schema.');
    }
    $tables = array_column($db->query('SHOW TABLES')->fetch_all(), 0);
    if ($tables) {
        if (array_diff($matches[1], $tables)) {
            throw new RuntimeException('Database is not empty and its schema is incomplete. No existing tables were changed.');
        }
        if (!(int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetch_row()[0]) {
            throw new RuntimeException('Existing database has no active administrator; restore or review it before deploying.');
        }
        echo "Existing Vetrix database retained.\n";
        exit(0);
    }

    $email = trim((string)getenv('VETRIX_ADMIN_EMAIL'));
    $password = (string)getenv('VETRIX_ADMIN_PASSWORD');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120 || strlen($password) < 12 || strlen($password) > 72) {
        throw new RuntimeException('Set VETRIX_ADMIN_EMAIL and VETRIX_ADMIN_PASSWORD (12-72 bytes) for first deployment.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($matches[0] as $statement) {
        $db->query($statement);
    }
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    $stmt = $db->prepare("INSERT INTO users (full_name,email,password,role,account_source,status,approved_at,otp_verified_at,password_changed_at) VALUES ('Vetrix Administrator',?,?,'admin','system_seed','active',NOW(),NOW(),NOW())");
    $stmt->bind_param('ss', $email, $hash);
    $stmt->execute();
    echo "Created Vetrix schema and administrator. No demo accounts were imported.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Vetrix setup failed: ' . $error->getMessage() . "\n");
    exit(1);
}
