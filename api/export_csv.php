<?php
require_once "../config/database.php"; require_once "../includes/functions.php"; require_role("admin");
$type=$_GET['type'] ?? 'clients';
$allowed=[
'clients'=>"SELECT id,full_name,email,phone,emergency_contact,address,account_source,status,created_at FROM users WHERE role='client'",
'pets'=>"SELECT p.id,p.name,p.species,p.breed,p.sex,p.birth_date,p.weight,p.color,p.allergies,p.critical_notes,u.full_name owner FROM pets p JOIN users u ON p.owner_id=u.id",
'appointments'=>"SELECT a.id,u.full_name client,p.name pet_name,a.requested_date,a.scheduled_date,a.reason,a.status,a.admin_notes FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id",
'records'=>"SELECT m.id,p.name pet_name,m.veterinarian,m.visit_date,m.symptoms,m.diagnosis,m.treatment,m.prescription,m.notes FROM medical_records m JOIN pets p ON m.pet_id=p.id"
];
if(!isset($allowed[$type])) die('Invalid export.');
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="vetrix_'.$type.'_'.date('Ymd').'.csv"');
$out=fopen('php://output','w');
$res=$conn->query($allowed[$type]);
$first=true;
while($row=$res->fetch_assoc()){
    if($type==='records' && array_key_exists('notes',$row)) $row['notes']=medical_note_text((string)$row['notes']);
    if($first){ fputcsv($out,array_keys($row)); $first=false; }
    fputcsv($out,$row);
}
fclose($out);
exit;
?>
