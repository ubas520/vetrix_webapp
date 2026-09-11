<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");
$uid=(int)current_user_id();
$vetName=(string)($_SESSION['full_name']??'Veterinarian');
$appointmentFocus=max(0,(int)($_GET['appointment_id']??0));
$petPrefill=max(0,(int)($_GET['pet_id']??0));
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf_or_fail();
    $petId=(int)($_POST['pet_id']??0);
    $appointmentId=max(0,(int)($_POST['appointment_id']??0));
    if($appointmentId>0){
        $appointmentCheck=$conn->prepare("SELECT id,pet_id FROM appointments WHERE id=? AND status='completed' AND (assigned_vet_id=? OR assigned_vet_id IS NULL) LIMIT 1");
        $appointmentCheck->bind_param('ii',$appointmentId,$uid);$appointmentCheck->execute();$linkedAppointment=$appointmentCheck->get_result()->fetch_assoc();
        if(!$linkedAppointment || (int)$linkedAppointment['pet_id']!==$petId)$appointmentId=0;
    }
    $visitDate=trim((string)($_POST['visit_date']??''))?:date('Y-m-d');
    $diagnosis=trim((string)($_POST['diagnosis']??''));
    $treatment=trim((string)($_POST['treatment']??''));
    $prescription=trim((string)($_POST['prescription']??''));
    $notes=trim((string)($_POST['notes']??''));
    $noteType=$_POST['note_type']??'neutral';if(!in_array($noteType,['positive','negative','follow_up','neutral'],true))$noteType='neutral';
    $storedNotes=medical_note_pack($notes,$noteType);
    $stmt=$conn->prepare("INSERT INTO medical_records(pet_id,veterinarian,veterinarian_id,appointment_id,visit_date,diagnosis,treatment,prescription,notes) VALUES(?,?,?,?,?,?,?,?,?)");
    $linkedAppointmentId=$appointmentId>0?$appointmentId:null;
    $stmt->bind_param("isiisssss",$petId,$vetName,$uid,$linkedAppointmentId,$visitDate,$diagnosis,$treatment,$prescription,$storedNotes);
    $stmt->execute();
    $rid=(int)$conn->insert_id;
    $owner=$conn->query("SELECT owner_id,name FROM pets WHERE id=".$petId)->fetch_assoc();
    if($owner) notify_user($conn,(int)$owner['owner_id'],'Medical Record Added','A new medical record was added for '.$owner['name'].'.','record',null);
    log_action($conn,'Veterinarian added medical record','medical_record',$rid,'Manual record entry by '.$vetName.'.');
    flash('success','Record saved.');
    redirect_to('vet/prescription.php?pet_id='.$petId.'#medicalRecord'.$rid);
}


$record_search = trim($_GET['q'] ?? '');
$record_filter = $_GET['filter'] ?? 'all';
$pet_filter = $petPrefill;
if(!in_array($record_filter,['all','neutral','positive','negative','follow_up'],true)) $record_filter='all';
[$page,$perPage,$offset] = pagination_values(6,24,[6,12,18,24,'full']);
$conditions=[];
$noteTypeSql = "CASE
    WHEN m.notes LIKE '[[note:positive]]%' THEN 'positive'
    WHEN m.notes LIKE '[[note:negative]]%' THEN 'negative'
    WHEN m.notes LIKE '[[note:follow_up]]%' THEN 'follow_up'
    WHEN m.notes LIKE '[[note:neutral]]%' THEN 'neutral'
    WHEN TRIM(COALESCE(m.notes,''))='' THEN 'neutral'
    WHEN LOWER(m.notes) REGEXP 'emergency|urgent|critical|collapse|severe|not improving|poor response' THEN 'negative'
    WHEN LOWER(m.notes) REGEXP 'follow[- ]?up|return for|recheck|monitor|check for|reassess' THEN 'follow_up'
    WHEN LOWER(m.notes) REGEXP 'normal|healthy|stable|clear|improved|improving|better|recovering|good appetite|active appetite|no crystals|no adverse' THEN 'positive'
    ELSE 'neutral'
END";
if ($record_search !== '') {
    $safeRecordSearch = $conn->real_escape_string($record_search);
    $conditions[] = "(p.name LIKE '%$safeRecordSearch%' OR m.veterinarian LIKE '%$safeRecordSearch%' OR m.diagnosis LIKE '%$safeRecordSearch%' OR m.treatment LIKE '%$safeRecordSearch%' OR m.prescription LIKE '%$safeRecordSearch%' OR m.notes LIKE '%$safeRecordSearch%')";
}
if($pet_filter>0) $conditions[] = "m.pet_id=$pet_filter";
if($record_filter!=='all') $conditions[] = "($noteTypeSql)='$record_filter'";
$record_where = $conditions ? 'WHERE '.implode(' AND ',$conditions) : '';
$recordSummary = $conn->query("SELECT COUNT(*) total_records, SUM(TRIM(COALESCE(prescription,''))<>'') prescriptions, COUNT(DISTINCT pet_id) pets_with_records FROM medical_records")->fetch_assoc();
$filterCounts = [
    'all'=>(int)($recordSummary['total_records'] ?? 0),
    'neutral'=>0,
    'positive'=>0,
    'negative'=>0,
    'follow_up'=>0,
];
$noteCountRows=$conn->query("SELECT ($noteTypeSql) note_type,COUNT(*) c FROM medical_records m GROUP BY note_type");
while($noteCount=$noteCountRows->fetch_assoc())if(array_key_exists($noteCount['note_type'],$filterCounts))$filterCounts[$noteCount['note_type']]=(int)$noteCount['c'];
$totalFiltered = (int)$conn->query("SELECT COUNT(*) c FROM medical_records m JOIN pets p ON m.pet_id=p.id $record_where")->fetch_assoc()['c'];
$title="Prescription";
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/vet_sidebar.php"; ?>
<main class="content vet-records-page vet-prescription-page" id="mainContent">
    <header class="page-heading"><div><span class="eyebrow">Clinical prescriptions</span><h1>Prescription</h1></div></header>

    <?php if($m=flash('success')):?><div class="alert alert-success"><?=e($m)?></div><?php endif;?>
    <?php if($m=flash('error')):?><div class="alert alert-danger"><?=e($m)?></div><?php endif;?>

    <section class="admin-summary-grid mb-4">
        <div class="admin-summary-card"><span>Clinical records</span><b><?= (int)($recordSummary['total_records'] ?? 0) ?></b></div>
        <div class="admin-summary-card"><span>Prescriptions</span><b><?= (int)($recordSummary['prescriptions'] ?? 0) ?></b></div>
        <div class="admin-summary-card"><span>Pets represented</span><b><?= (int)($recordSummary['pets_with_records'] ?? 0) ?></b></div>
        <div class="admin-summary-card"><span>Visible results</span><b><?= $totalFiltered ?></b></div>
    </section>

    <div class="vet-admin-split vet-records-layout vet-clinical-admin-layout">
        <div class="soft-card vet-form-card vet-entry-card">
            <div class="vet-form-title clinical-form-title"><span class="pet-icon"><?=ui_icon('plus')?></span><div><h3>Add Prescription</h3></div></div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="appointment_id" value="<?=$appointmentFocus?>">
                <label class="form-label fw-bold mb-1">Pet</label>
                <select class="form-select" name="pet_id"><?php $pets=$conn->query("SELECT id,name FROM pets WHERE verification_status='approved' ORDER BY name"); while($p=$pets->fetch_assoc()): ?><option value="<?=$p['id']?>" <?=$petPrefill===(int)$p['id']?'selected':''?>><?=e($p['name'])?></option><?php endwhile;?></select>
                <label class="form-label fw-bold mb-1">Veterinarian</label><input class="form-control" value="<?=e($vetName)?>" readonly><small class="field-help">Current logged-in veterinarian</small>
                <label class="form-label fw-bold mb-1">Visit Date</label><input class="form-control" type="date" name="visit_date" value="<?=date('Y-m-d')?>" required><div class="field-help">Use the actual consultation, check-up, or treatment date.</div>
                <label class="form-label fw-bold mb-1">Diagnosis</label><input class="form-control" name="diagnosis" list="recordDiagnosisSuggestions" placeholder="Diagnosis">
                <label class="form-label fw-bold mb-1">Treatment</label><input class="form-control" name="treatment" list="recordTreatmentSuggestions" placeholder="Treatment">
                <fieldset class="record-prescription-entry" id="prescriptionCreator"><legend>Prescription <small>optional</small></legend><label class="form-label fw-bold mb-1">Medicine and instructions</label><textarea class="form-control" name="prescription" list="recordPrescriptionSuggestions" rows="3" placeholder="Medicine, dose, frequency, and duration"></textarea><small class="field-help">Saved prescriptions can be downloaded as a clinic PDF after the record is saved.</small></fieldset>
                <label class="form-label fw-bold mb-1">Note type</label><select class="form-select" name="note_type"><option value="neutral">General note</option><option value="positive">Improving / stable</option><option value="negative">Urgent concern</option><option value="follow_up">Needs follow-up</option></select><label class="form-label fw-bold mb-1">Notes</label><input class="form-control" name="notes" list="recordNotesSuggestions" placeholder="Clinical note">
                <datalist id="recordDiagnosisSuggestions"><?php foreach(['Gastroenteritis','Allergic dermatitis','Otitis externa (ear infection)','Upper respiratory infection','Urinary tract infection','Dental disease','Intestinal parasites','Flea infestation','Canine parvovirus infection','Feline upper respiratory infection'] as $commonDiagnosis):?><option value="<?=e($commonDiagnosis)?>"><?php endforeach;?><?php $suggest=$conn->query("SELECT DISTINCT diagnosis value FROM medical_records WHERE TRIM(COALESCE(diagnosis,''))<>'' ORDER BY id DESC LIMIT 40");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="recordTreatmentSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT treatment value FROM medical_records WHERE TRIM(COALESCE(treatment,''))<>'' ORDER BY id DESC LIMIT 40");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="recordPrescriptionSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT prescription value FROM medical_records WHERE TRIM(COALESCE(prescription,''))<>'' ORDER BY id DESC LIMIT 40");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="recordNotesSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT notes value FROM medical_records WHERE TRIM(COALESCE(notes,''))<>'' ORDER BY id DESC LIMIT 40");while($x=$suggest->fetch_assoc()): $cleanSuggestion=medical_note_text((string)$x['value']); if($cleanSuggestion==='') continue;?><option value="<?=e($cleanSuggestion)?>"><?php endwhile;?></datalist><button class="btn btn-primary w-100 mt-2">Save</button>
            </form>
        </div>
        <div class="table-card vet-list-card vet-record-list-card">
            <div class="section-head mb-3 clinical-list-heading"><div><h3>Prescription Records</h3></div></div>
            <section class="surface-card data-toolbar vet-toolbar-card">
                <div class="filter-tabs">
                    <?php foreach(['all'=>'All','neutral'=>'General','positive'=>'Improving','negative'=>'Urgent','follow_up'=>'Follow-up'] as $key=>$label): $query=['filter'=>$key,'q'=>$record_search,'pet_id'=>$pet_filter?:null,'per_page'=>per_page_value($perPage)]; ?>
                    <a class="filter-tab prescription-filter filter-<?=e($key)?> status-<?=e($key)?> <?=$record_filter===$key?'active':''?>" href="?<?=e(http_build_query(array_filter($query,fn($v)=>$v!==''&&$v!==null)))?>"><?=e($label)?><b><?=intval($filterCounts[$key]??0)?></b></a>
                    <?php endforeach;?>
                </div>
                <form class="toolbar-actions prescription-show-control" method="GET" action="prescription.php">
                    <input type="hidden" name="filter" value="<?=e($record_filter)?>"><input type="hidden" name="q" value="<?=e($record_search)?>"><input type="hidden" name="pet_id" value="<?=$pet_filter?>">
                    <label class="entries-select">Show<select name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[6,12,18,24,'full'])?></select></label>
                </form>
            </section>
            <form class="admin-list-search" method="GET" action="prescription.php">
                <input type="hidden" name="filter" value="<?=e($record_filter)?>"><input type="hidden" name="per_page" value="<?=e(per_page_value($perPage))?>">
                <input class="form-control" type="search" name="q" value="<?=e($record_search)?>" placeholder="Search records">
                <select class="form-select" name="pet_id"><option value="0">Filter by approved pet</option><?php $petOptions=$conn->query("SELECT id,name FROM pets WHERE verification_status='approved' ORDER BY name"); while($po=$petOptions->fetch_assoc()):?><option value="<?=$po['id']?>" <?=$pet_filter===(int)$po['id']?'selected':''?>><?=e($po['name'])?></option><?php endwhile;?></select>
                <button class="btn btn-primary" type="submit">Search</button><a class="btn btn-light" href="prescription.php">Clear</a>
            </form>
            <?php $rows=$conn->query("SELECT m.*,p.name pet_name FROM medical_records m JOIN pets p ON m.pet_id=p.id $record_where ORDER BY visit_date DESC, m.id DESC LIMIT $perPage OFFSET $offset"); ?>
            <?php if($rows->num_rows===0): ?><div class="admin-empty-state"><h3>No medical records found</h3><p>Try another filter or add a new record.</p></div><?php else: ?>
            <div class="admin-card-list view-grid" id="vetRecordList">
                <?php while($r=$rows->fetch_assoc()): $cleanNotes=medical_note_text((string)($r['notes']??'')); $detail=['title'=>$r['pet_name'].' medical record','eyebrow'=>'Medical record','fields'=>['Visit date'=>$r['visit_date'] ?: 'No date','Veterinarian'=>$r['veterinarian'] ?: 'Not specified','Diagnosis'=>$r['diagnosis'] ?: 'No diagnosis saved.','Treatment'=>$r['treatment'] ?: 'No treatment saved.','Prescription'=>$r['prescription'] ?: 'No prescription saved.','Notes'=>$cleanNotes !== '' ? $cleanNotes : 'No notes saved.']]; ?>
                <?php $noteType=medical_note_type((string)($r['notes']??''));$presentation=['positive'=>['improving','Improving'],'negative'=>['urgent','Urgent'],'follow_up'=>['follow-up','Follow-up'],'neutral'=>['general','General']][$noteType]??['general','General'];[$noteTone,$noteLabel]=$presentation; ?>
                <article id="medicalRecord<?=$r['id']?>" class="admin-review-card record-card prescription-note-card" data-note-status="<?=e($noteTone)?>" data-record-detail='<?=e(json_encode($detail))?>'><div class="admin-review-main"><div class="admin-row-title"><h3><?=e($r['pet_name'])?></h3><span><?=e($r['visit_date'] ?: 'No date')?></span></div><p><?=e($r['diagnosis'] ?: 'No diagnosis saved.')?></p><div class="admin-meta-row"><span>Visit: <?=e($r['visit_date'] ?: 'No date')?></span><span>Vet: <?=e($r['veterinarian'] ?: 'Not specified')?></span></div><div class="admin-compare-grid"><div><span>Treatment</span><p><?=e($r['treatment'] ?: 'No treatment saved.')?></p></div><div><span>Prescription</span><p><?=e($r['prescription'] ?: 'No prescription saved.')?></p></div></div><div class="admin-note-box record-note"><span>Note · <?=e($noteLabel)?></span><p><?=e($cleanNotes!==''?$cleanNotes:'No notes saved.')?></p></div><div class="record-document-actions"><?php if(trim((string)($r['prescription']??''))!==''):?><a class="button-secondary" data-detail-bypass href="<?=app_url('vet/prescription_pdf.php?record_id='.$r['id'])?>"><?=ui_icon('eye')?>View prescription</a><?php else:?><span class="button-secondary disabled" aria-disabled="true"><?=ui_icon('file')?>No prescription</span><?php endif;?></div></div></article>
                <?php endwhile;?>
            </div>
            <?=render_pagination($page,$perPage,$totalFiltered,['filter'=>$record_filter,'q'=>$record_search,'pet_id'=>$pet_filter?:null,'per_page'=>per_page_value($perPage),'_anchor'=>'vetRecordList'])?>
            <?php endif; ?>
        </div>
    </div>
</main></div><?php include "../includes/footer.php"; ?>
