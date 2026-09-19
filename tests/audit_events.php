<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(sys_get_temp_dir());
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
function audit_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
// A connection-local table shadows the real audit trail and disappears on exit.
$created = $conn->query('CREATE TEMPORARY TABLE audit_logs (id INT AUTO_INCREMENT PRIMARY KEY, actor_user_id INT NULL, action VARCHAR(160), entity_type VARCHAR(80), entity_id INT NULL, details TEXT, event_data JSON, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
if (!$created) {
    // Exit cleanly: a failed test setup must not invoke fatal-error auditing
    // against the permanent table when the temporary table was not created.
    fwrite(STDERR, "Audit database tests blocked: temporary table unavailable. Check database health.\n");
    exit(1);
}
$_POST = ['name'=>'Test pet','password'=>'NeverStoreThis','nested'=>['access_token'=>'NeverStoreToken'], 'notes'=>['first','second']];
$_GET = ['csrf_token'=>'NeverStoreCsrf'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/vetrix/test-audit';
audit_check(audit_write_event($conn, null, 'Audit test', 'test', 1, 'Saved test', ['outcome'=>'success','result'=>['id'=>1],'transfers'=>[['from'=>'form','to'=>'record']]]), 'Write failed.');
$row=$conn->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch_assoc();
$event=audit_decode_event($row['event_data']);
audit_check($event['request']['submitted']['notes'] === ['first','second'], 'Multiple texts did not round trip.');
audit_check($event['result']['id'] === 1, 'Result was lost.');
foreach (['NeverStoreThis','NeverStoreToken','NeverStoreCsrf'] as $secret) audit_check(strpos($row['event_data'],$secret) === false, 'Secret was stored.');
$_SESSION['user_id']=1;
flash('error','A required field is missing.');
$error=$conn->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch_assoc();
audit_check(audit_decode_event($error['event_data'])['outcome'] === 'error', 'Web error was not captured.');
audit_check(flash('error') === 'A required field is missing.', 'Flash behavior changed.');
audit_check(json_decode(audit_event_json("Invalid bytes \xFF"),true) !== null, 'Invalid UTF-8 broke JSON.');
audit_check(json_decode(audit_event_json('Non-finite value',['result'=>['value'=>INF]]),true) !== null, 'Non-finite number broke JSON.');
audit_check(strlen(audit_event_json('Large payload',['response'=>array_fill(0,100,str_repeat('x',8000))])) <= 65536, 'Payload limit failed.');
audit_check(audit_decode_event(null) === null, 'Legacy entries must remain legacy.');
// Verify HTML escaping and native expansion using the production renderer.
$stmt=$conn->prepare('INSERT INTO audit_logs(action,details,event_data) VALUES(?,?,?)');
$action='<script>alert(1)</script>';$details='<img src=x onerror=alert(1)>';$json=audit_event_json($details);
$stmt->bind_param('sss',$action,$details,$json);$stmt->execute();
$rows=$conn->query("SELECT *,NULL AS full_name,NULL AS role FROM audit_logs ORDER BY id DESC");
$visibleCount=$rows->num_rows;$page=1;$perPage=6;$q='';$roleFilter='all';$userFilter=0;$entityFilter='all';$period='all';
ob_start();include __DIR__ . '/../includes/activity_feed.php';$html=ob_get_clean();
audit_check(strpos($html,'<details class="audit-event">') !== false,'Expand control missing.');
audit_check(strpos($html,'<script>alert') === false && strpos($html,'<img src=x') === false,'Unescaped data in feed.');
echo "Audit checks passed: JSON round trip, multiple values, redaction, error capture, limits, legacy handling, and safe rendering.\n";
