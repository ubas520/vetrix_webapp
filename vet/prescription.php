<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");
require_once __DIR__ . '/../includes/prescription_entry.php';

$record_search = trim($_GET['q'] ?? '');
$record_filter = $_GET['filter'] ?? 'all';
$pet_filter = $petPrefill;
if(!in_array($record_filter,['all','neutral','positive','negative','follow_up'],true)) $record_filter='all';
[$page,$perPage,$offset] = pagination_values(6,24,[6,12,18,24,'full']);
$conditions=["TRIM(COALESCE(m.prescription,''))<>''"];
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
    'all'=>(int)($recordSummary['prescriptions'] ?? 0),
    'neutral'=>0,
    'positive'=>0,
    'negative'=>0,
    'follow_up'=>0,
];
$noteCountRows=$conn->query("SELECT ($noteTypeSql) note_type,COUNT(*) c FROM medical_records m WHERE TRIM(COALESCE(m.prescription,''))<>'' GROUP BY note_type");
while($noteCount=$noteCountRows->fetch_assoc())if(array_key_exists($noteCount['note_type'],$filterCounts))$filterCounts[$noteCount['note_type']]=(int)$noteCount['c'];
$totalFiltered = (int)$conn->query("SELECT COUNT(*) c FROM medical_records m JOIN pets p ON m.pet_id=p.id $record_where")->fetch_assoc()['c'];
$title="Prescription";
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/vet_sidebar.php"; ?>
<main class="content vet-records-page vet-prescription-page" id="mainContent">
    <header class="page-heading"><div><span class="eyebrow">Clinical prescriptions</span><h1>Prescription</h1></div><button class="button-primary" type="button" id="prescriptionFormToggle" aria-controls="prescriptionFormPanel" aria-expanded="<?=$prescriptionFormOpen?'true':'false'?>"><?=ui_icon('plus')?>Add prescription <span aria-hidden="true" id="prescriptionFormChevron"><?=$prescriptionFormOpen?'&#9652;':'&#9662;'?></span></button></header>
    <style>
    body.enterprise-ui:is([data-user-role="veterinarian"], [data-user-role="admin"]) #mainContent.vet-prescription-page .vet-records-layout{display:grid!important;grid-template-columns:minmax(0,1fr)!important;height:auto!important;min-height:0!important;max-height:none!important;align-items:start!important}
    body.enterprise-ui:is([data-user-role="veterinarian"], [data-user-role="admin"]) #mainContent.vet-prescription-page #prescriptionFormPanel[hidden]{display:none!important}
    body.enterprise-ui:is([data-user-role="veterinarian"], [data-user-role="admin"]) #mainContent.vet-prescription-page #prescriptionFormPanel{position:static!important;width:100%!important;max-width:720px!important;height:auto!important;min-height:0!important;max-height:none!important;overflow:visible!important}
    body.enterprise-ui:is([data-user-role="veterinarian"], [data-user-role="admin"]) #mainContent.vet-prescription-page .vet-record-list-card{width:100%!important;height:auto!important;min-height:0!important;max-height:none!important}
    </style>

    <?php if($m=flash('success')):?><div class="alert alert-success"><?=e($m)?></div><?php endif;?>
    <?php if($m=flash('error')):?><div class="alert alert-danger"><?=e($m)?></div><?php endif;?>

    <section class="admin-summary-grid medical-record-summary">
        <div class="admin-summary-card"><span>Clinical records</span><b><?= (int)($recordSummary['total_records'] ?? 0) ?></b></div>
        <div class="admin-summary-card"><span>Prescriptions</span><b><?= (int)($recordSummary['prescriptions'] ?? 0) ?></b></div>
        <div class="admin-summary-card"><span>Pets represented</span><b><?= (int)($recordSummary['pets_with_records'] ?? 0) ?></b></div>
        <div class="admin-summary-card"><span>Visible results</span><b><?= $totalFiltered ?></b></div>
    </section>

    <div class="vet-admin-split vet-records-layout vet-clinical-admin-layout">
        <?php include __DIR__ . '/../includes/prescription_form.php'; ?>
        <div class="table-card vet-list-card vet-record-list-card">
            <section class="surface-card prescription-finder">
              <div class="section-heading compact">
                <div><span class="eyebrow">Record search</span><h2>Browse prescription records</h2></div>
                <form class="toolbar-actions prescription-show-control" method="GET" action="prescription.php">
                    <input type="hidden" name="filter" value="<?=e($record_filter)?>"><input type="hidden" name="q" value="<?=e($record_search)?>"><input type="hidden" name="pet_id" value="<?=$pet_filter?>">
                    <label class="entries-select">Show<select name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[6,12,18,24,'full'])?></select></label>
                </form>
              </div>
            <form class="prescription-search-toolbar" method="GET" action="prescription.php">
                <input type="hidden" name="per_page" value="<?=e(per_page_value($perPage))?>">
                <input class="form-control" type="search" name="q" value="<?=e($record_search)?>" placeholder="Search pet, diagnosis, treatment, or note" aria-label="Search prescription records">
                <select class="form-select" name="pet_id" aria-label="Filter by pet"><option value="0">All approved pets</option><?php $petOptions=$conn->query("SELECT id,name FROM pets WHERE verification_status='approved' ORDER BY name"); while($po=$petOptions->fetch_assoc()):?><option value="<?=$po['id']?>" <?=$pet_filter===(int)$po['id']?'selected':''?>><?=e($po['name'])?></option><?php endwhile;?></select>
                <select class="form-select" name="filter" aria-label="Filter by clinical status"><?php foreach(['all'=>'All statuses','neutral'=>'General','positive'=>'Improving','negative'=>'Urgent','follow_up'=>'Follow-up'] as $key=>$label):?><option value="<?=e($key)?>" <?=$record_filter===$key?'selected':''?>><?=e($label)?> (<?=intval($filterCounts[$key]??0)?>)</option><?php endforeach;?></select>
                <button class="button-primary" type="submit">Search</button><?php if($record_search!==''||$pet_filter||$record_filter!=='all'):?><a class="button-secondary" href="prescription.php">Clear</a><?php endif;?>
            </form>
            </section>
            <?php $rows=$conn->query("SELECT m.*,p.name pet_name,p.species,p.breed,p.pet_photo,u.full_name owner_name FROM medical_records m JOIN pets p ON m.pet_id=p.id JOIN users u ON p.owner_id=u.id $record_where ORDER BY visit_date DESC, m.id DESC LIMIT $perPage OFFSET $offset"); ?>
            <?php if($rows->num_rows===0): ?><div class="admin-empty-state"><h3>No prescriptions found</h3><p>Try another filter or add medication instructions to an existing consultation.</p></div><?php else: ?>
            <section class="medical-record-index" id="medicalPetHistory">
            <div class="medical-record-index-grid" id="vetRecordList">
                <?php while($r=$rows->fetch_assoc()): $cleanNotes=medical_note_text((string)($r['notes']??'')); $detail=['title'=>$r['pet_name'].' medical record','eyebrow'=>'Medical record','fields'=>['Visit date'=>$r['visit_date'] ?: 'No date','Veterinarian'=>$r['veterinarian'] ?: 'Not specified','Diagnosis'=>$r['diagnosis'] ?: 'No diagnosis saved.','Treatment'=>$r['treatment'] ?: 'No treatment saved.','Prescription'=>$r['prescription'] ?: 'No prescription saved.','Notes'=>$cleanNotes !== '' ? $cleanNotes : 'No notes saved.']]; ?>
                <?php $noteType=medical_note_type((string)($r['notes']??''));$presentation=['positive'=>['improving','Improving'],'negative'=>['urgent','Urgent'],'follow_up'=>['follow-up','Follow-up'],'neutral'=>['general','General']][$noteType]??['general','General'];[$noteTone,$noteLabel]=$presentation; ?>
                <?php $petType=strtolower(preg_replace('/[^a-z0-9]+/i','-',(string)$r['species'])); ?>
                <article id="medicalRecord<?=$r['id']?>" class="medical-record-index-card pet-type-<?=e($petType)?>" data-record-detail='<?=e(json_encode($detail))?>'>
                    <header class="medical-index-header">
                        <span class="medical-pet-avatar pet-type-<?=e($petType)?>"><?=pet_avatar_markup($r,'entity-avatar')?></span>
                        <div><span class="eyebrow medical-species-label pet-type-<?=e($petType)?>"><?=e($r['species'])?></span><h2><?=e($r['pet_name'])?></h2><p><?=e($r['breed']?:'Breed not recorded')?> &middot; <?=e($r['owner_name'])?></p></div>
                    </header>
                    <div class="medical-index-facts">
                        <span><small>Visit date</small><b><?=$r['visit_date']?e(date('M d, Y',strtotime($r['visit_date']))):'No visit date'?></b></span>
                        <span><small>Status</small><b><?=e($noteLabel)?></b></span>
                        <span><small>Diagnosis</small><b><?=e($r['diagnosis']?:'General consultation')?></b></span>
                        <span><small>Veterinarian</small><b><?=e($r['veterinarian']?:'Not recorded')?></b></span>
                    </div>
                    <footer><a class="button-secondary" data-detail-bypass href="<?=app_url('vet/prescription_pdf.php?record_id='.$r['id'])?>"><?=ui_icon('file')?>View prescription</a></footer>
                </article>
                <?php endwhile;?>
            </div>
            <?=render_pagination($page,$perPage,$totalFiltered,['filter'=>$record_filter,'q'=>$record_search,'pet_id'=>$pet_filter?:null,'per_page'=>per_page_value($perPage),'_anchor'=>'vetRecordList'])?>
            </section>
            <?php endif; ?>
        </div>
    </div>
<script>
(()=>{
    const toggle=document.getElementById('prescriptionFormToggle');
    const panel=document.getElementById('prescriptionFormPanel');
    const setExpanded=expanded=>{
        panel.hidden=!expanded;
        toggle.setAttribute('aria-expanded',String(expanded));
        document.getElementById('prescriptionFormChevron').textContent=expanded?'\u25b4':'\u25be';
    };
    toggle.addEventListener('click',()=>setExpanded(panel.hidden));
    document.getElementById('prescriptionFormClose').addEventListener('click',()=>{setExpanded(false);toggle.focus();});
    const openFromHash=()=>{if(location.hash==='#prescriptionCreator'){setExpanded(true);document.getElementById('prescriptionCreator').scrollIntoView({block:'center'});}};
    window.addEventListener('hashchange',openFromHash);
    openFromHash();
})();
</script>
</main></div><?php include "../includes/footer.php"; ?>
