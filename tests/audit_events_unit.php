<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/audit_events.php';
function check_audit_value($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$_POST = ['notes'=>['first text','second text'], 'password'=>'private-value', 'nested'=>['api_key'=>'private-key']];
$_GET = ['token'=>'private-token'];
$json = audit_event_json('Request failed', ['outcome'=>'error','result'=>['saved'=>false],'errors'=>[['message'=>'Invalid input']]]);
$data = audit_decode_event($json);
check_audit_value($data['request']['submitted']['notes'] === ['first text','second text'], 'Multiple values lost');
check_audit_value($data['errors'][0]['message'] === 'Invalid input', 'Error lost');
check_audit_value($data['result']['saved'] === false, 'Result lost');
foreach (['private-value','private-key','private-token'] as $secret) check_audit_value(strpos($json,$secret) === false, 'Secret not redacted');
check_audit_value(audit_decode_event(null) === null, 'Legacy record incorrectly converted');
check_audit_value(json_decode(audit_event_json("Invalid UTF-8 \xFF",['result'=>['number'=>INF]]),true) !== null, 'JSON invalid');
check_audit_value(strlen(audit_event_json('Large',['response'=>array_fill(0,100,str_repeat('x',8000))])) <= 65536, 'Size limit exceeded');
echo "Audit unit checks passed: submitted values, results, errors, redaction, legacy data, encoding and size limits.\n";
