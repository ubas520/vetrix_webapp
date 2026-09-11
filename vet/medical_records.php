<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");

$q=trim($_GET['q']??'');
$petId=max(0,(int)($_GET['pet_id']??0));
$sort=(string)($_GET['sort']??'recent');
if(!in_array($sort,['recent','pet_az','owner_az'],true))$sort='recent';
[$page,$perPage,$offset]=pagination_values(6,24,[6,12,18,24,'full']);

$conditions=["p.verification_status='approved'","EXISTS(SELECT 1 FROM medical_records mx WHERE mx.pet_id=p.id)"];
if($petId>0)$conditions[]="p.id=".$petId;
if($q!==''){
    $safe=$conn->real_escape_string($q);
    $conditions[]="(p.name LIKE '%$safe%' OR p.species LIKE '%$safe%' OR p.breed LIKE '%$safe%' OR u.full_name LIKE '%$safe%' OR p.allergies LIKE '%$safe%' OR p.critical_notes LIKE '%$safe%' OR EXISTS(SELECT 1 FROM medical_records sm WHERE sm.pet_id=p.id AND (sm.diagnosis LIKE '%$safe%' OR sm.treatment LIKE '%$safe%' OR sm.prescription LIKE '%$safe%' OR sm.notes LIKE '%$safe%' OR sm.veterinarian LIKE '%$safe%')))";
}
$where='WHERE '.implode(' AND ',$conditions);
$orderSql=match($sort){
    'pet_az'=>'p.name ASC,u.full_name ASC',
    'owner_az'=>'u.full_name ASC,p.name ASC',
    default=>"COALESCE((SELECT MAX(visit_date) FROM medical_records m WHERE m.pet_id=p.id),'1900-01-01') DESC,p.name ASC",
};
$total=(int)$conn->query("SELECT COUNT(*) c FROM pets p JOIN users u ON p.owner_id=u.id $where")->fetch_assoc()['c'];
$pets=$conn->query("SELECT p.*,u.full_name owner_name,
    (SELECT COUNT(*) FROM medical_records m WHERE m.pet_id=p.id) record_count,
    (SELECT MAX(visit_date) FROM medical_records m WHERE m.pet_id=p.id) last_visit,
    (SELECT diagnosis FROM medical_records m WHERE m.pet_id=p.id ORDER BY visit_date DESC,id DESC LIMIT 1) latest_diagnosis,
    (SELECT veterinarian FROM medical_records m WHERE m.pet_id=p.id ORDER BY visit_date DESC,id DESC LIMIT 1) latest_vet
    FROM pets p JOIN users u ON p.owner_id=u.id $where ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
$summary=$conn->query("SELECT COUNT(*) total_records,COUNT(DISTINCT pet_id) pets_with_records,MAX(visit_date) last_visit FROM medical_records")->fetch_assoc();
$title='Medical Records';
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/vet_sidebar.php";?><main class="content vet-medical-records-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Clinical history</span><h1>Medical Records</h1></div><a class="button-primary" href="<?=app_url('vet/prescription.php')?>"><?=ui_icon('plus')?>Add consultation</a></header>

<section class="admin-summary-grid medical-record-summary"><div class="admin-summary-card"><span>Total consultations</span><b><?=intval($summary['total_records']??0)?></b></div><div class="admin-summary-card"><span>Patients with records</span><b><?=intval($summary['pets_with_records']??0)?></b></div><div class="admin-summary-card"><span>Matching patients</span><b><?=$total?></b></div><div class="admin-summary-card"><span>Latest consultation</span><b><?=!empty($summary['last_visit'])?e(date('M d, Y',strtotime($summary['last_visit']))):'—'?></b></div></section>

<section class="surface-card medical-record-finder">
    <div class="section-heading compact"><div><span class="eyebrow">Record search</span><h2>Browse clinical histories</h2></div><form class="toolbar-actions moved-show-control" method="GET" action="medical_records.php"><input type="hidden" name="q" value="<?=e($q)?>"><input type="hidden" name="pet_id" value="<?=$petId?>"><input type="hidden" name="sort" value="<?=e($sort)?>"><label class="entries-select">Show<select class="unified-show-select" name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[6,12,18,24,'full'])?></select></label></form></div>
    <form class="medical-record-toolbar" method="GET">
        <input class="form-control medical-record-search" type="search" name="q" value="<?=e($q)?>" placeholder="Search pet, owner, diagnosis, or note">
        <select class="form-select" name="pet_id"><option value="0">All patients with records</option><?php $options=$conn->query("SELECT p.id,p.name,u.full_name owner_name FROM pets p JOIN users u ON p.owner_id=u.id WHERE p.verification_status='approved' AND EXISTS(SELECT 1 FROM medical_records m WHERE m.pet_id=p.id) ORDER BY p.name");while($p=$options->fetch_assoc()):?><option value="<?=$p['id']?>" <?=$petId===(int)$p['id']?'selected':''?>><?=e($p['name'].' · '.$p['owner_name'])?></option><?php endwhile;?></select>
        <select class="form-select" name="sort" aria-label="Sort medical records"><option value="recent" <?=$sort==='recent'?'selected':''?>>Latest visit</option><option value="pet_az" <?=$sort==='pet_az'?'selected':''?>>Pet A–Z</option><option value="owner_az" <?=$sort==='owner_az'?'selected':''?>>Owner A–Z</option></select>
        
        <button class="button-primary" type="submit">Search</button>
        <?php if($q!==''||$petId||$sort!=='recent'):?><a class="button-secondary" href="medical_records.php">Clear</a><?php endif;?>
    </form>
</section>

<section class="medical-record-index" id="medicalPetHistory">
<div class="medical-record-index-grid">
<?php if(!$pets->num_rows):?><div class="empty-state surface-card medical-record-empty"><h2>No medical history found</h2><p>Try a broader term such as an owner name, diagnosis, breed, medicine, or note.</p></div><?php endif;?>
<?php while($pet=$pets->fetch_assoc()):?>
<article class="medical-record-index-card pet-type-<?=e(strtolower(preg_replace('/[^a-z0-9]+/i','-',(string)$pet['species'])))?>">
    <header class="medical-index-header"><span class="medical-pet-avatar pet-type-<?=e(strtolower(preg_replace('/[^a-z0-9]+/i','-',(string)$pet['species'])))?>"><?=pet_avatar_markup($pet,'entity-avatar')?></span><div><span class="eyebrow medical-species-label pet-type-<?=e(strtolower(preg_replace('/[^a-z0-9]+/i','-',(string)$pet['species'])))?>"><?=e($pet['species'])?></span><h2><?=e($pet['name'])?></h2><p><?=e($pet['breed']?:'Breed not recorded')?> · <?=e($pet['owner_name'])?></p></div></header>
    <div class="medical-index-facts"><span><small>Last visit</small><b><?=$pet['last_visit']?e(date('M d, Y',strtotime($pet['last_visit']))):'No visit date'?></b></span><span><small>Consultations</small><b><?=intval($pet['record_count'])?></b></span><span><small>Latest diagnosis</small><b><?=e($pet['latest_diagnosis']?:'General consultation')?></b></span><span><small>Veterinarian</small><b><?=e($pet['latest_vet']?:'Not recorded')?></b></span></div>
    <div class="medical-index-notes"><p><b>Allergies</b><span><?=e($pet['allergies']?:'None recorded')?></span></p><p><b>Critical notes</b><span><?=e($pet['critical_notes']?:'None recorded')?></span></p></div>
    <?php $historyOpen=$petId===(int)$pet['id'];?><footer><?php if($historyOpen):?><a class="button-secondary" href="?<?=e(http_build_query(['sort'=>$sort,'q'=>$q,'per_page'=>per_page_value($perPage)]))?>#medicalPetHistory"><?=ui_icon('x')?>Close history</a><?php else:?><a class="button-secondary" href="?<?=e(http_build_query(['pet_id'=>(int)$pet['id'],'sort'=>'recent','per_page'=>per_page_value($perPage)]))?>#patientHistory"><?=ui_icon('file')?>Browse History</a><?php endif;?></footer>
</article>
<?php endwhile;?>
</div>
<?=render_pagination($page,$perPage,$total,['q'=>$q,'pet_id'=>$petId?:null,'sort'=>$sort,'per_page'=>per_page_value($perPage),'_anchor'=>'medicalPetHistory'])?>
</section>

<?php if($petId>0):
$focusStmt=$conn->prepare("SELECT p.*,u.full_name owner_name FROM pets p JOIN users u ON p.owner_id=u.id WHERE p.id=? AND p.verification_status='approved' LIMIT 1");
$focusStmt->bind_param('i',$petId);$focusStmt->execute();$focusedPet=$focusStmt->get_result()->fetch_assoc();
$historyStmt=$conn->prepare("SELECT m.* FROM medical_records m WHERE m.pet_id=? ORDER BY m.visit_date DESC,m.id DESC");$historyStmt->bind_param('i',$petId);$historyStmt->execute();$history=$historyStmt->get_result();
if($focusedPet):?>
<section class="surface-card medical-patient-history pet-type-<?=e(strtolower(preg_replace('/[^a-z0-9]+/i','-',(string)$focusedPet['species'])))?>" id="patientHistory">
    <div class="section-heading"><div><span class="eyebrow">Patient history</span><h2><?=e($focusedPet['name'])?></h2><p><?=e($focusedPet['owner_name'])?> · <?=e($focusedPet['species'])?><?=trim((string)$focusedPet['breed'])!==''?' · '.e($focusedPet['breed']):''?></p></div></div>
    <div class="medical-history-list">
    <?php while($r=$history->fetch_assoc()):$clean=medical_note_text((string)($r['notes']??''));$type=medical_note_type((string)($r['notes']??''));$presentation=['neutral'=>['general','General'],'positive'=>['improving','Improving'],'negative'=>['urgent','Urgent'],'follow_up'=>['follow-up','Follow-up']][$type]??['general','General'];[$tone,$label]=$presentation;?>
        <article class="medical-history-row" data-note-status="<?=e($tone)?>" id="medicalRecord<?=$r['id']?>">
            <header><div><time><?=e(date('M d, Y',strtotime($r['visit_date'])))?></time><h3><?=e($r['diagnosis']?:'General consultation')?></h3></div><span class="medical-note-type"><?=e($label)?></span></header>
            <div class="medical-history-grid"><div><small>Veterinarian</small><p><?=e($r['veterinarian']?:'Not recorded')?></p></div><div><small>Treatment</small><p><?=e($r['treatment']?:'Not recorded')?></p></div><div><small>Prescription</small><p><?=e($r['prescription']?:'No prescription')?></p></div><div><small>Clinical note</small><p><?=e($clean?:'No note')?></p></div></div>
            
        </article>
    <?php endwhile;?>
    </div>
</section>
<?php endif;endif;?>
</main></div><?php include "../includes/footer.php";?>
