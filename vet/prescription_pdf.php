<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../includes/report_helpers.php";
require_login();

$uid=(int)current_user_id();
$role=$_SESSION['role']??'';
if(!in_array($role,['admin','veterinarian','client'],true)){http_response_code(403);exit('Not authorized.');}

$recordId=(int)($_GET['record_id']??0);
$stmt=$conn->prepare("SELECT m.*,p.name pet_name,p.species,p.breed,p.sex,p.weight,p.owner_id,u.full_name owner_name,vu.full_name vet_account_name FROM medical_records m JOIN pets p ON m.pet_id=p.id JOIN users u ON p.owner_id=u.id LEFT JOIN users vu ON m.veterinarian_id=vu.id WHERE m.id=? LIMIT 1");
$stmt->bind_param('i',$recordId);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();
$allowed=$r && ($role==='admin' || $role==='veterinarian' || ($role==='client'&&(int)$r['owner_id']===$uid));
if(!$allowed){
    if($role==='veterinarian'){flash('error','Medical record not found.');redirect_to('vet/prescription.php');}
    http_response_code(404);exit('Medical record not found.');
}

$vet=trim((string)($r['vet_account_name']?:$r['veterinarian']))?:'Veterinarian';
$title='Prescription';
include "../includes/header.php";
echo report_actions();
?>
<div class="print-page prescription-report-page">
<?=report_header('Prescription', clean_report($r['pet_name'].' · '.$r['species']))?>

<div class="print-section">
    <h3>Patient Information</h3>
    <table class="print-table">
        <tr><th>Pet</th><td><?=e($r['pet_name'])?></td><th>Species</th><td><?=e($r['species'])?></td></tr>
        <tr><th>Breed</th><td><?=e($r['breed']?:'Not recorded')?></td><th>Sex</th><td><?=e($r['sex']?:'Not recorded')?></td></tr>
        <tr><th>Owner</th><td><?=e($r['owner_name'])?></td><th>Visit date</th><td><?=e(date('F d, Y',strtotime($r['visit_date'])))?></td></tr>
        <tr><th>Veterinarian</th><td colspan="3"><?=e($vet)?></td></tr>
    </table>
</div>

<div class="print-section">
    <h3>Clinical Details</h3>
    <table class="print-table">
        <tr><th>Diagnosis</th><td><?=e($r['diagnosis']?:'Not recorded')?></td></tr>
        <?php if(trim((string)$r['treatment'])!==''):?><tr><th>Treatment</th><td><?=e($r['treatment'])?></td></tr><?php endif;?>
    </table>
</div>

<div class="print-section prescription-print-section">
    <h3>Prescription</h3>
    <div class="print-note prescription-print-note"><?=trim((string)$r['prescription'])!==''?nl2br(e($r['prescription'])):'No prescription was saved for this consultation.'?></div>
</div>

<div class="print-section">
    <table class="print-table"><tr><th>Prescribed by</th><td><?=e($vet)?></td><th>Medical record</th><td>#<?=intval($r['id'])?></td></tr></table>
</div>
</div>
<?php include "../includes/footer.php"; ?>
