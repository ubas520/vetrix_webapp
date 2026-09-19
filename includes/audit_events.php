<?php
/** Structured audit data shared by the web app and mobile API. */
function audit_safe_data($value, int $depth = 0) {
    if ($depth > 7) return '[Depth limit]';
    if (is_array($value)) {
        $safe = [];
        foreach (array_slice($value, 0, 100, true) as $key => $item) {
            $safe[$key] = preg_match('/password|passwd|secret|token|authorization|cookie|csrf|otp|verification_code|confirmation_code|api.?key/i', (string)$key)
                ? '[Redacted]' : audit_safe_data($item, $depth + 1);
        }
        if (count($value) > 100) $safe['_truncated'] = 'Only the first 100 fields are retained.';
        return $safe;
    }
    if (is_string($value)) {
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [Redacted]', $value);
        return strlen($value) > 4000 ? substr($value, 0, 4000) . ' [Truncated]' : $value;
    }
    if (is_float($value) && !is_finite($value)) return '[Non-finite number]';
    return is_scalar($value) || $value === null ? $value : '[Unsupported value]';
}

function audit_request_data(): array {
    $submitted = $_POST;
    if (!$submitted && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        $stream = fopen('php://input', 'rb');
        $raw = $stream ? stream_get_contents($stream, 65537) : '';
        if ($stream) fclose($stream);
        $decoded = strlen($raw) <= 65536 ? json_decode($raw, true) : null;
        $submitted = is_array($decoded) ? $decoded : ['body' => '[Invalid or oversized JSON body; raw content omitted]'];
    }
    return audit_safe_data([
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'path' => $_SERVER['SCRIPT_NAME'] ?? '',
        'query' => $_GET,
        'submitted' => $submitted,
    ]);
}

function audit_event_json(string $message, array $context = []): string {
    $event = audit_safe_data(array_replace([
        'version' => 1,
        'outcome' => 'recorded',
        'messages' => [$message],
        'result' => ['message' => $message],
        'request' => audit_request_data(),
        'transfers' => [],
        'errors' => [],
    ], $context));
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($event, $flags);
    if (strlen($json) > 65536) {
        $event['request'] = ['note' => 'Payload omitted because the event exceeded 64 KB.'];
        $event['transfers'] = [];
        unset($event['before'], $event['after'], $event['response']);
        $event['truncated'] = true;
        $json = json_encode($event, $flags);
        if (strlen($json) > 65536) $json = json_encode(['version'=>1,'outcome'=>$event['outcome'],'messages'=>['Event data exceeded 64 KB. See the activity summary.'],'truncated'=>true], $flags);
    }
    return $json;
}

function audit_write_event($conn, ?int $actor, string $action, ?string $entityType, ?int $entityId, string $details = '', array $context = []): bool {
    if (!($conn instanceof mysqli)) return false;
    try {
        $json = audit_event_json($details, $context);
        $stmt = $conn->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,details,event_data) VALUES(?,?,?,?,?,?)');
        if (!$stmt) throw new RuntimeException('Audit statement unavailable. Apply the activity JSON migration.');
        $stmt->bind_param('ississ', $actor, $action, $entityType, $entityId, $details, $json);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) throw new RuntimeException('Audit event could not be stored.');
        return true;
    } catch (Throwable $error) {
        // Audit storage must never turn a completed clinic operation into a failure.
        error_log('Vetrix structured audit write failed. Check audit_logs storage and migration.');
        return false;
    }
}

function audit_decode_event($json): ?array {
    if (!is_string($json) || $json === '') return null;
    $event = json_decode($json, true);
    return is_array($event) ? audit_safe_data($event) : null;
}

function audit_pretty_json($value): string {
    return json_encode(audit_safe_data($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    $actor = $GLOBALS['audit_mobile_actor'] ?? ($_SESSION['user_id'] ?? null);
    // Do not persist raw stack traces or SQL text, which may contain credentials.
    audit_write_event($GLOBALS['conn'] ?? null, $actor ? (int)$actor : null, 'Request stopped unexpectedly', 'request', null,
        'An unexpected server error interrupted this request. Consult the server error log for the full diagnostic.', [
            'outcome'=>'error',
            'errors'=>[['message'=>'Unexpected server error','type'=>$error['type'],'file'=>basename($error['file']),'line'=>$error['line']]],
        ]);
});
