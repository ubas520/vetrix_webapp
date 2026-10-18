<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/consultation_save.php';
require_once __DIR__.'/../config/database.php';
function consultation_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
// Only connection-local temporary tables are written; live clinic data is untouched.
$tables=[
    'pets'=>'id INT PRIMARY KEY,owner_id INT,name VARCHAR(80),verification_status VARCHAR(30)',
    'appointments'=>'id INT PRIMARY KEY,pet_id INT,status VARCHAR(30),assigned_vet_id INT NULL,scheduled_date DATETIME NULL,requested_date DATETIME',
    'medical_records'=>'id INT AUTO_INCREMENT PRIMARY KEY,pet_id INT,veterinarian VARCHAR(120),veterinarian_id INT,appointment_id INT NULL,visit_date DATE,diagnosis TEXT,treatment TEXT,prescription TEXT,notes TEXT'
];
foreach($tables as $table=>$columns)consultation_check((bool)$conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB"),'Temporary table failed');
$conn->query("INSERT INTO pets VALUES(1,10,'Patient one','approved'),(2,11,'Patient two','approved'),(3,12,'Pending patient','pending')");
$conn->query("INSERT INTO appointments VALUES(1,1,'completed',7,'2026-10-10 09:30:00','2026-10-09 09:30:00'),(2,1,'approved',7,NULL,'2026-10-11 10:00:00'),(3,3,'completed',7,NULL,'2026-10-11 10:00:00')");
$input=['pet_id'=>'1','appointment_id'=>'1','visit_date'=>'2026-10-10','diagnosis'=>'Test diagnosis','treatment'=>'Test treatment','notes'=>'Test note','note_type'=>'follow_up'];
$count=fn()=>(int)$conn->query('SELECT COUNT(*) n FROM medical_records')->fetch_assoc()['n'];
foreach([
    ['pet_id'=>'2'],['appointment_id'=>'999'],['appointment_id'=>'2'],['appointment_id'=>'3','pet_id'=>'3'],['appointment_id'=>'0','pet_id'=>'3'],['visit_date'=>'2026-02-30'],['visit_date'=>''],['pet_id'=>'invalid'],['appointment_id'=>'-1'],['note_type'=>'invalid'],['diagnosis'=>['bad']],['notes'=>str_repeat('x',65536)]
] as $changes){
    $rejected=false;
    try{save_consultation($conn,array_replace($input,$changes),7,'Test veterinarian',false);}catch(InvalidArgumentException $error){$rejected=true;}
    consultation_check($rejected && $count()===0,'Invalid input must not create an unlinked record');
}
$denied=false;
try{save_consultation($conn,$input,8,'Other veterinarian',false);}catch(InvalidArgumentException $error){$denied=true;}
consultation_check($denied && $count()===0,'Another veterinarian cannot use the appointment');
$context=consultation_appointment($conn,1,8,true);
consultation_check((int)$context['pet_id']===1 && substr($context['scheduled_date'],0,10)==='2026-10-10','Admin context must carry the pet and visit date');
$saved=save_consultation($conn,$input,8,'Test admin',true);
$row=$conn->query('SELECT * FROM medical_records WHERE id='.(int)$saved['id'])->fetch_assoc();
consultation_check((int)$row['appointment_id']===1 && (int)$row['pet_id']===1 && $row['visit_date']==='2026-10-10' && $row['diagnosis']==='Test diagnosis','Linked record must retain visit context');
$manual=save_consultation($conn,array_replace($input,['appointment_id'=>'0','pet_id'=>'2']),7,'Test veterinarian',false);
$row=$conn->query('SELECT * FROM medical_records WHERE id='.(int)$manual['id'])->fetch_assoc();
consultation_check($row['appointment_id']===null && (int)$row['pet_id']===2,'Manual consultation remains available');
$before=$count();
$_SESSION['user_id']=8;
$_SESSION['role']='admin';
$_SESSION['full_name']='Test admin';
$_GET=['appointment_id'=>'1','pet_id'=>'1'];
$_POST=[];
$_SERVER['REQUEST_METHOD']='GET';
include __DIR__.'/../includes/clinical_record_entry.php';
consultation_check($petPrefill===1 && $consultationValues['visit_date']==='2026-10-10' && !$consultationBlocked,'Linked form must prefill from appointment');
$_SERVER['REQUEST_METHOD']='POST';
$_POST=array_replace($input,['visit_date'=>'2026-02-30','diagnosis'=>'Keep <this> diagnosis','csrf_token'=>csrf_token()]);
include __DIR__.'/../includes/clinical_record_entry.php';
consultation_check($consultationError!=='' && $consultationValues['diagnosis']==='Keep <this> diagnosis' && $count()===$before,'Failed form must retain entries without saving');
$consultationEntry=true;
$pets='existing patient list';
ob_start();include __DIR__.'/../includes/clinical_record_form.php';$html=ob_get_clean();
consultation_check(str_contains($html,'Keep &lt;this&gt; diagnosis') && str_contains($html,'name="pet_id" value="1"') && !str_contains($html,'<select class="form-select" id="consultationPet"'),'Linked form must safely preserve values and fix the pet');
consultation_check($pets==='existing patient list','Form must not overwrite patient history result');
$_GET=['appointment_id'=>'bad'];$_SERVER['REQUEST_METHOD']='GET';
include __DIR__.'/../includes/clinical_record_entry.php';
consultation_check($consultationBlocked,'Invalid appointment links must not become manual consultations');
$conn->query('ALTER TABLE medical_records DROP COLUMN treatment');
$failed=false;
try{save_consultation($conn,$input,7,'Test veterinarian',false);}catch(RuntimeException $error){$failed=true;}
consultation_check($failed && $count()===$before,'Failed insert must not report a saved record');
echo "Consultation checks passed: validation, appointment permissions, pet mismatch, visit linkage, manual entry, failed insert. No clinic data changed.\n";
