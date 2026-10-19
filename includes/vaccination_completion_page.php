<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_role(['admin','veterinarian']);
require_once __DIR__.'/vaccination_save.php';
ensure_vaccination_completion_schema($conn);
$area=($_SESSION['role']??'')==='admin'?'admin':'vet';
$previous=(int)($_POST['previous_vaccination_id']??$_GET['vaccination_id']??0);
$prior=null;$error='';
if ($previous) {
    $stmt=$conn->prepare('SELECT v.*,p.name pet_name FROM vaccinations v JOIN pets p ON p.id=v.pet_id WHERE v.id=?');
    $stmt->bind_param('i',$previous);$stmt->execute();$prior=$stmt->get_result()->fetch_assoc();
    if (!$prior || $prior['completed_by_record_id'] || !$prior['next_due_date']) {
        flash('error','This vaccination follow-up is unavailable or already completed.');
        redirect_to($area.'/vaccinations.php');
    }
}
$values=['pet_id'=>$prior['pet_id']??'','vaccine_name'=>$prior['vaccine_name']??'','date_given'=>'','next_due_date'=>'','administered_by_id'=>'','remarks'=>''];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf_or_fail();
    foreach($values as $key=>$value) $values[$key]=is_scalar($_POST[$key]??'')?(string)($_POST[$key]??''):'';
    try {
        if (($_POST['confirmed']??'')!=='yes') throw new InvalidArgumentException('Confirm that the vaccination was administered.');
        $id=save_vaccination($conn,array_merge($values,['previous_vaccination_id'=>$previous]),(int)current_user_id());
        log_action($conn,'Recorded completed vaccination','vaccination',$id,'Previous follow-up: '.$previous.'. In-app confirmation created.');
        flash('success',"Vaccination recorded and confirmation added to the client's app.");
        redirect_to($area.'/vaccinations.php'.($area==='admin'?'?vaccination_id='.$id:''));
    } catch (InvalidArgumentException $e) {$error=$e->getMessage();}
    catch (Throwable $e) {error_log('Vaccination completion failed: '.$e->getMessage());$error='Nothing was saved. Please try again.';}
}
$pets=$conn->query("SELECT p.id,p.name,u.full_name owner_name FROM pets p JOIN users u ON u.id=p.owner_id WHERE p.verification_status='approved' ORDER BY p.name")->fetch_all(MYSQLI_ASSOC);
$clinicians=$conn->query("SELECT id,full_name,role FROM users WHERE role IN ('veterinarian','admin') AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$title='Record completed vaccination';include __DIR__.'/header.php';include __DIR__.'/navbar.php';
?>
<div class="layout"><?php include __DIR__.'/'.$area.'_sidebar.php'; ?><main class="content" id="mainContent">
<header class="page-heading"><h1>Record completed vaccination</h1><a class="button-secondary" href="vaccinations.php">Back to vaccinations</a></header>
<section class="surface-card" style="max-width:760px;padding:24px">
<p>Record the actual vaccination, then notify the pet owner in the app.</p>
<?php if($prior): ?><p>Completing <?=e($prior['pet_name'])?>'s <?=e($prior['vaccine_name'])?> follow-up, originally due <?=e($prior['next_due_date'])?>. The original record will stay in the history.</p><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?>
<form method="POST" style="display:grid;gap:12px">
<?=csrf_field()?>
<input type="hidden" name="previous_vaccination_id" value="<?=$previous?>">
<label for="completionPet">Pet and owner</label>
<select class="form-select" id="completionPet" name="pet_id" required><option value="">Select a pet</option><?php foreach($pets as $pet): if($prior && (int)$pet['id']!==(int)$prior['pet_id'])continue; ?><option value="<?=$pet['id']?>" <?=(int)$values['pet_id']===(int)$pet['id']?'selected':''?>><?=e($pet['name'].' — '.$pet['owner_name'])?></option><?php endforeach; ?></select>
<label for="completionVaccine">Vaccine</label><input class="form-control" id="completionVaccine" name="vaccine_name" maxlength="120" required value="<?=e($values['vaccine_name'])?>" <?=$prior?'readonly':''?>>
<label for="completionGiven">Actual date given</label><input class="form-control" type="date" id="completionGiven" name="date_given" required value="<?=e($values['date_given'])?>">
<label for="completionClinician">Administered by</label><select class="form-select" id="completionClinician" name="administered_by_id" required><option value="">Select the administering clinician</option><?php foreach($clinicians as $clinician): ?><option value="<?=$clinician['id']?>" <?=(int)$values['administered_by_id']===(int)$clinician['id']?'selected':''?>><?=e($clinician['full_name'].' ('.$clinician['role'].')')?></option><?php endforeach; ?></select>
<label for="completionDue">Next due date (optional)</label><input class="form-control" type="date" id="completionDue" name="next_due_date" value="<?=e($values['next_due_date'])?>">
<label for="completionRemarks">Remarks</label><textarea class="form-control" id="completionRemarks" name="remarks" rows="3"><?=e($values['remarks'])?></textarea>
<label><input type="checkbox" name="confirmed" value="yes" required> I confirm this vaccination was administered and these details are correct.</label>
<button class="button-primary" type="submit">Save &amp; notify client</button>
</form></section></main></div><?php include __DIR__.'/footer.php'; ?>
