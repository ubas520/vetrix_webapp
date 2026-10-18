<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../config/database.php';
function mini_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
// Temporary tables shadow live tables only in this connection.
foreach([
    'users'=>'id INT PRIMARY KEY,full_name VARCHAR(120)',
    'pets'=>'id INT PRIMARY KEY,name VARCHAR(120)',
    'appointments'=>'id INT PRIMARY KEY,pet_id INT,owner_id INT,assigned_vet_id INT NULL,status VARCHAR(30),scheduled_date DATETIME NULL,requested_date DATETIME'
] as $table=>$columns) mini_check((bool)$conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB"),'Temporary table could not be created');
$conn->query("INSERT INTO users VALUES(1,'Test owner'),(2,'Test veterinarian')");
$conn->query("INSERT INTO pets VALUES(1,'<script>Test patient</script>')");
$conn->query("INSERT INTO appointments VALUES(1,1,1,2,'approved','2028-02-29 10:00:00','2028-01-01 10:00:00'),(2,1,1,NULL,'pending',NULL,'2028-02-01 09:00:00'),(3,1,1,2,'cancelled','2028-02-29 11:00:00','2028-02-01 10:00:00'),(4,1,1,2,'approved','2028-03-01 10:00:00','2028-02-01 10:00:00')");
$overviewTab='sales';$diagnosisPeriod='quarterly';
$_GET=['mini_month'=>'2028-02'];
ob_start();include __DIR__.'/../includes/dashboard_mini_calendar.php';$html=ob_get_clean();
mini_check($miniTotal===3 && count($miniVisits['2028-02-29'])===2,'Month counts must use scheduled time first and include all statuses');
preg_match_all("/data-record-detail='([^']*)'/",$html,$matches);
mini_check(count($matches[1])===29,'Leap-year February must contain 29 day buttons');
$last=json_decode(html_entity_decode($matches[1][28],ENT_QUOTES,'UTF-8'),true,512,JSON_THROW_ON_ERROR);
mini_check(str_contains($last['html'],'appointment_id=1') && str_contains($last['html'],'appointment_id=3'),'Day details must link to its actual appointments');
mini_check(!str_contains($last['html'],'<script>') && str_contains($last['html'],'&lt;script&gt;'),'Pet names must remain escaped inside day details');
mini_check(str_contains($last['html'],'overview%3Dsales') && str_contains($last['html'],'diagnosis_period%3Dquarterly'),'Returning from a visit must retain dashboard selections');
$empty=json_decode(html_entity_decode($matches[1][1],ENT_QUOTES,'UTF-8'),true,512,JSON_THROW_ON_ERROR);
mini_check(str_contains($empty['html'],'No appointments'),'Empty dates must explain the empty state');
foreach(['2028-13','garbage',"2028-02\0",['2028-02']] as $invalid){
    $_GET=['mini_month'=>$invalid];ob_start();include __DIR__.'/../includes/dashboard_mini_calendar.php';ob_end_clean();
    mini_check($miniMonth->format('Y-m')===substr($miniToday,0,7),'Invalid month must fall back to current clinic month');
}
$_GET=['mini_month'=>'2027-02'];ob_start();include __DIR__.'/../includes/dashboard_mini_calendar.php';$html=ob_get_clean();
mini_check(substr_count($html,'data-record-detail=')===28 && $miniTotal===0,'Empty non-leap February must contain 28 days');
echo "Mini calendar checks passed: month boundaries, leap years, actual visit links, escaping, empty days, return context, invalid months. No clinic data changed.\n";
