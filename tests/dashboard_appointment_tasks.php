<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../config/database.php';
function dashboard_rows(mysqli $conn,string $sql):array {
    $result=$conn->query($sql);if(!$result)throw new RuntimeException('Task query failed');return $result->fetch_all(MYSQLI_ASSOC);
}
function dashboard_list_html(array $rows,callable $renderer,string $empty):string {return $rows?implode('',array_map($renderer,$rows)):$empty;}
// Temporary fixtures never modify clinic records.
foreach([
    'users'=>'id INT PRIMARY KEY,full_name VARCHAR(80)',
    'pets'=>'id INT PRIMARY KEY,name VARCHAR(80)',
    'appointments'=>'id INT PRIMARY KEY,pet_id INT,owner_id INT,assigned_vet_id INT NULL,status VARCHAR(30),scheduled_date DATETIME NULL,requested_date DATETIME',
    'medical_records'=>'id INT PRIMARY KEY,appointment_id INT,pet_id INT'
] as $table=>$columns)if(!$conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB"))throw new RuntimeException('Fixture creation failed');
$conn->query("INSERT INTO users VALUES(1,'Test owner'),(2,'Test vet')");
$conn->query("INSERT INTO pets VALUES(1,'Test patient')");
$conn->query("INSERT INTO appointments VALUES
 (1,1,1,NULL,'pending',NULL,NOW()-INTERVAL 1 DAY),
 (2,1,1,NULL,'approved',NULL,NOW()+INTERVAL 1 DAY),
 (3,1,1,2,'approved',NOW()-INTERVAL 1 DAY,NOW()-INTERVAL 1 DAY),
 (4,1,1,2,'completed',NOW()-INTERVAL 1 DAY,NOW()-INTERVAL 1 DAY),
 (5,1,1,2,'completed',NOW()-INTERVAL 1 DAY,NOW()-INTERVAL 1 DAY),
 (6,1,1,2,'cancelled',NOW()-INTERVAL 1 DAY,NOW()-INTERVAL 1 DAY),
 (7,1,1,2,'pending',NULL,NOW()+INTERVAL 1 DAY)");
// A record for another pet must not satisfy this visit's documentation requirement.
$conn->query('INSERT INTO medical_records VALUES(1,5,1),(2,4,999)');
include __DIR__.'/../includes/dashboard_appointment_tasks.php';
$expected=['past_requests'=>1,'unassigned'=>2,'unscheduled'=>2,'visit_updates'=>3,'consultations'=>4];
foreach($dashboardAppointmentTaskQueues as $item){
    $key=$item[7]['queue_key'];
    if($item[1]!==1 || !str_contains($item[7]['html'],'appointment_id='.$expected[$key]))throw new RuntimeException('Count or destination mismatch: '.$key);
    if($key==='consultations' && !str_contains($item[7]['html'],'vet/appointments.php'))throw new RuntimeException('Missing consultation must open the clinical workflow');
}
echo "Dashboard task checks passed: distinct issues, overlapping actions, completed visits without matching records, and exact review links. No clinic data changed.\n";
