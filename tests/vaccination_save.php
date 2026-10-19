<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/vaccination_save.php';
// All writes are isolated to connection-local temporary tables.
foreach ([
    'users'=>'id INT PRIMARY KEY,full_name VARCHAR(120),role VARCHAR(30),status VARCHAR(30),deleted_at DATETIME NULL',
    'pets'=>'id INT PRIMARY KEY,verification_status VARCHAR(30),name VARCHAR(120),owner_id INT',
    'notifications'=>'id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,title VARCHAR(160),message TEXT,type VARCHAR(30),status VARCHAR(30)',
    'vaccinations'=>'id INT AUTO_INCREMENT PRIMARY KEY,pet_id INT,vaccine_name VARCHAR(120),date_given DATE NULL,next_due_date DATE NULL,administered_by VARCHAR(120),administered_by_id INT,remarks TEXT'
] as $table=>$columns) {
    if (!$conn->query("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB")) throw new RuntimeException('Fixture failed');
}
$conn->query("INSERT INTO users VALUES(1,'Test vet','veterinarian','active',NULL),(2,'Test admin','admin','active',NULL),(3,'Test staff','staff','active',NULL),(4,'Inactive vet','veterinarian','inactive',NULL)");
ensure_vaccination_completion_schema($conn);
$conn->query("INSERT INTO pets VALUES(1,'approved','Buddy',10),(2,'pending','Luna',11)");
$valid=['pet_id'=>1,'vaccine_name'=>'Test vaccine','date_given'=>'2026-10-18','next_due_date'=>'2026-11-18','remarks'=>'Test only'];
foreach ([['pet_id'=>0],['pet_id'=>2],['pet_id'=>999],['vaccine_name'=>' '],['vaccine_name'=>str_repeat('x',121)],['date_given'=>'2026-02-30'],['next_due_date'=>'2026-10-17'],['next_due_date'=>'2026-10-18'],['remarks'=>['invalid']]] as $invalid) {
    try { save_vaccination($conn,array_replace($valid,$invalid),1); throw new RuntimeException('Invalid input saved'); }
    catch (InvalidArgumentException $expected) {}
}
foreach ([3,4,999] as $actor) {
    try { save_vaccination($conn,$valid,$actor); throw new RuntimeException('Unauthorized actor saved'); }
    catch (InvalidArgumentException $expected) {}
}
$id=save_vaccination($conn,$valid,1);
$row=$conn->query('SELECT * FROM vaccinations WHERE id='.$id)->fetch_assoc();
if ($row['administered_by']!=='Test vet' || $row['next_due_date']!=='2026-11-18') throw new RuntimeException('Saved values incorrect');
save_vaccination($conn,array_replace($valid,['date_given'=>'','next_due_date'=>'']),2);
if ((int)$conn->query('SELECT COUNT(*) n FROM vaccinations')->fetch_assoc()['n']!==2) throw new RuntimeException('Unexpected record count');
$notifications=$conn->query('SELECT * FROM notifications ORDER BY id')->fetch_all(MYSQLI_ASSOC);
if(count($notifications)!==2) throw new RuntimeException('Expected one inbox confirmation per saved record');
foreach($notifications as $notification) {
    if((int)$notification['user_id']!==10 || $notification['status']!=='unread' || $notification['type']!=='vaccine') throw new RuntimeException('Wrong notification recipient or state');
}
if(!str_contains($notifications[0]['message'],'Buddy') || !str_contains($notifications[0]['message'],'Test vaccine') || !str_contains($notifications[0]['message'],'October 18, 2026') || !str_contains($notifications[0]['message'],'November 18, 2026')) throw new RuntimeException('Missing confirmation details');
if(!str_contains($notifications[1]['message'],'has not been recorded') || !str_contains($notifications[1]['message'],'No next due date')) throw new RuntimeException('Missing dates misrepresented');
$completion=array_replace($valid,['previous_vaccination_id'=>$id,'administered_by_id'=>1,'date_given'=>$conn->query('SELECT CURDATE() d')->fetch_assoc()['d'],'next_due_date'=>'']);
$completedId=save_vaccination($conn,$completion,2);
$old=$conn->query('SELECT * FROM vaccinations WHERE id='.$id)->fetch_assoc();
$new=$conn->query('SELECT * FROM vaccinations WHERE id='.$completedId)->fetch_assoc();
if((int)$old['completed_by_record_id']!==$completedId || $old['next_due_date']!==$valid['next_due_date']) throw new RuntimeException('Completion did not preserve history');
if((int)$new['recorded_by_id']!==2 || (int)$new['administered_by_id']!==1 || !$new['confirmation_notification_id']) throw new RuntimeException('Completion attribution or confirmation missing');
try {save_vaccination($conn,$completion,2);throw new LogicException('Duplicate follow-up accepted');} catch(InvalidArgumentException $expected) {}
if((int)$conn->query('SELECT COUNT(*) n FROM notifications')->fetch_assoc()['n']!==3) throw new RuntimeException('Duplicate notification created');
$conn->query('ALTER TABLE notifications DROP COLUMN message');
try { save_vaccination($conn,$valid,1); throw new LogicException('Notification failure reported as success'); }
catch (RuntimeException $expected) {}
if((int)$conn->query('SELECT COUNT(*) n FROM vaccinations')->fetch_assoc()['n']!==3) throw new RuntimeException('Notification failure did not roll back vaccination');
$conn->query('ALTER TABLE vaccinations DROP COLUMN remarks');
try { save_vaccination($conn,$valid,1); throw new LogicException('Database failure reported as success'); }
catch (RuntimeException $expected) { if ($expected instanceof LogicException) throw $expected; }
echo "PASS vaccination saves, dates, approved pets, role checks, optional dates and database failure handling.\n";
