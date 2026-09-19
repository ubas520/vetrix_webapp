<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
$column = $conn->query("SHOW COLUMNS FROM audit_logs LIKE 'event_data'");
if (!$column) throw new RuntimeException('Cannot inspect audit_logs.');
if (!$column->num_rows) {
    if (!$conn->query('ALTER TABLE audit_logs ADD COLUMN event_data JSON NULL AFTER details')) {
        throw new RuntimeException('Could not add audit_logs.event_data: ' . $conn->error);
    }
    echo "Added audit_logs.event_data JSON; existing activity entries were preserved.\n";
} else echo "audit_logs.event_data already exists; no change required.\n";
