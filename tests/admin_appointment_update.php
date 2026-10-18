<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/admin_appointment_update.php';
function appointment_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$valid=['status'=>'approved','assigned_vet_id'=>'7','scheduled_date'=>'2028-02-29T09:30','duration_minutes'=>'45','confirmation_code'=>'APT-42','admin_notes'=>'Arrive early.'];
$parsed=admin_appointment_values($valid);
appointment_check($parsed['scheduled_date']==='2028-02-29 09:30:00' && $parsed['assigned_vet_id']===7, 'Valid appointment should retain its exact time and veterinarian.');
$invalid=[['scheduled_date'=>'2027-02-29T09:30'],['scheduled_date'=>'2028-02-30T09:30'],['scheduled_date'=>'2028-02-29T25:00'],['scheduled_date'=>'tomorrow'],['scheduled_date'=>''],['assigned_vet_id'=>''],['assigned_vet_id'=>'-1'],['assigned_vet_id'=>'abc'],['status'=>'unknown'],['duration_minutes'=>'14'],['duration_minutes'=>'241'],['duration_minutes'=>'30.5'],['confirmation_code'=>str_repeat('x',41)],['admin_notes'=>str_repeat('x',65536)],['status'=>['approved']]];
foreach($invalid as $changes){
    $rejected=false;
    try { admin_appointment_values(array_replace($valid,$changes)); }
    catch(InvalidArgumentException $e){$rejected=true;}
    appointment_check($rejected,'Invalid input was accepted: '.implode(',',array_keys($changes)));
}
$pending=admin_appointment_values(array_replace($valid,['status'=>'pending','assigned_vet_id'=>'','scheduled_date'=>'']));
appointment_check($pending['assigned_vet_id']===null && $pending['scheduled_date']===null,'Unscheduled pending requests must remain supported.');
appointment_check(admin_appointment_version($parsed)!==admin_appointment_version(array_replace($parsed,['admin_notes'=>'Changed by another user'])),'Concurrent edits must invalidate the version.');
echo "Appointment validation checks passed (18 cases).\n";

if (!in_array('--database',$argv,true)) exit;
mysqli_report(MYSQLI_REPORT_OFF);
$conn=@new mysqli(getenv('VETRIX_DB_HOST')?:'localhost',getenv('VETRIX_DB_USER')?:'root',getenv('VETRIX_DB_PASS')?:'',getenv('VETRIX_DB_NAME')?:'vetrix',(int)(getenv('VETRIX_DB_PORT')?:3306));
if($conn->connect_errno){fwrite(STDERR,"Database integration checks unavailable: connection failed.\n");exit(2);}
// Read aggregate counts only; do not modify or print clinic records.
foreach(['appointments','vaccinations','medical_records'] as $table){
    $result=$conn->query("SELECT COUNT(*) AS total FROM $table");
    if(!$result)throw new RuntimeException('Clinic table could not be read: '.$table);
    echo $table.': '.$result->fetch_assoc()['total']." existing rows\n";
}
echo "Live data read checks passed; no clinic data changed.\n";
// Connection-local tables shadow live tables and disappear when this test exits.
$tables=[
    'users'=>'id INT PRIMARY KEY,role VARCHAR(30),status VARCHAR(30),deleted_at DATETIME NULL',
    'pets'=>'id INT PRIMARY KEY,name VARCHAR(60)',
    'appointments'=>'id INT PRIMARY KEY,owner_id INT,pet_id INT,status VARCHAR(30),scheduled_date DATETIME NULL,duration_minutes INT,assigned_vet_id INT NULL,confirmation_code VARCHAR(40),admin_notes TEXT,updated_at DATETIME NULL',
    'staff_availability'=>'id INT PRIMARY KEY,user_id INT,event_kind VARCHAR(30),starts_at DATETIME,ends_at DATETIME,reason TEXT,block_type VARCHAR(30)',
];
foreach($tables as $table=>$columns) appointment_check((bool)$conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB"),'Could not create temporary fixture: '.$table);
appointment_check((bool)$conn->query("INSERT INTO users VALUES(7,'veterinarian','active',NULL),(8,'staff','active',NULL),(9,'veterinarian','inactive',NULL),(10,'veterinarian','active','2026-01-01')"),'Users fixture failed');
$conn->query("INSERT INTO pets VALUES(1,'Test patient')");
$conn->query("INSERT INTO appointments VALUES(42,1,1,'pending',NULL,30,NULL,'APT-42','',NULL),(43,1,1,'approved','2028-02-29 09:30:00',30,7,'APT-43','',NULL)");
$current=fn()=> $conn->query('SELECT * FROM appointments WHERE id=42')->fetch_assoc();
$attempt=function(array $changes=[])use($conn,$valid,$current){return admin_save_appointment($conn,42,array_replace($valid,['appointment_version'=>admin_appointment_version($current())],$changes));};
$mustReject=function(callable $operation,string $message){
    $failed=false;
    try{$operation();}catch(InvalidArgumentException $e){$failed=true;}
    appointment_check($failed,$message);
};
$mustReject(fn()=> $attempt(),'Overlapping appointment must be rejected');
appointment_check($current()['status']==='pending','Conflict must leave appointment unchanged');
foreach([8,9,10,999] as $vet) $mustReject(fn()=> $attempt(['assigned_vet_id'=>(string)$vet]),'Wrong-role, inactive, deleted and nonexistent veterinarians must be rejected');
$conn->query("INSERT INTO staff_availability VALUES(1,7,'unavailable','2028-02-29 11:00:00','2028-02-29 12:00:00','Approved leave','leave')");
$mustReject(fn()=> $attempt(['scheduled_date'=>'2028-02-29T11:00']),'Unavailable veterinarian must be rejected');
$saved=$attempt(['scheduled_date'=>'2028-02-29T10:00']);
appointment_check($saved['changed'] && $current()['status']==='approved' && $current()['scheduled_date']==='2028-02-29 10:00:00','Non-overlapping update must commit');
appointment_check(!$attempt(['scheduled_date'=>'2028-02-29T10:00'])['changed'],'Unchanged appointment must not be saved again');
$mustReject(fn()=> $attempt(['appointment_version'=>str_repeat('0',64)]),'Stale form must not overwrite a newer appointment');
appointment_check($current()['scheduled_date']==='2028-02-29 10:00:00','Rejected stale update must retain newer schedule');
$conn->query('ALTER TABLE appointments DROP COLUMN admin_notes');
$failed=false;
try{$attempt(['scheduled_date'=>'2028-02-29T13:00']);}catch(RuntimeException $e){$failed=true;}
appointment_check($failed && $current()['scheduled_date']==='2028-02-29 10:00:00','Database write failure must throw and retain the original appointment');
echo "Appointment database checks passed: conflicts, veterinarian eligibility, leave, successful save, no-op, stale form, failed write.\n";
