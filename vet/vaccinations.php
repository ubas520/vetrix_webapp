<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");
$uid=(int)current_user_id();
$vetName=(string)($_SESSION['full_name']??'Veterinarian');
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf_or_fail();
    $action=$_POST['action']??'save_vaccine';
    if($action==='send_owner_notification'){
        $vaccinationId=(int)($_POST['vaccination_id']??0);
        $stmt=$conn->prepare("SELECT v.vaccine_name,v.next_due_date,p.id pet_id,p.name pet_name,p.owner_id FROM vaccinations v JOIN pets p ON v.pet_id=p.id WHERE v.id=? LIMIT 1");
        $stmt->bind_param('i',$vaccinationId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();
        if($row){
            $due=$row['next_due_date']?date('M d, Y',strtotime($row['next_due_date'])):'not recorded';
            notify_user($conn,(int)$row['owner_id'],'Vaccination follow-up needed',$row['pet_name'].' needs owner follow-up for '.$row['vaccine_name'].'. Due date: '.$due.'. Sent by '.$vetName.'.','vaccine',null);
            log_action($conn,'Sent vaccination follow-up notification','vaccination',$vaccinationId,'Sent by '.$vetName.'.');
            flash('success','Owner notification sent.');
        }
    } else {
        $petId=(int)($_POST['pet_id']??0);
        $vaccine=trim((string)($_POST['vaccine_name']??''));
        $dateGiven=trim((string)($_POST['date_given']??''))?:null;
        $nextDue=trim((string)($_POST['next_due_date']??''))?:null;
        $remarks=trim((string)($_POST['remarks']??''));
        $stmt=$conn->prepare("INSERT INTO vaccinations(pet_id,vaccine_name,date_given,next_due_date,administered_by,administered_by_id,remarks) VALUES(?,?,?,?,?,?,?)");
        $stmt->bind_param("issssis",$petId,$vaccine,$dateGiven,$nextDue,$vetName,$uid,$remarks);
        $stmt->execute();
        log_action($conn,'Veterinarian added vaccination','vaccination',(int)$stmt->insert_id,'Administered by '.$vetName.'.');
        flash('success','Vaccination saved.');
    }
    redirect_to('vet/vaccinations.php');
}

$vaccine_search = trim($_GET['q'] ?? '');
$vaccine_filter = $_GET['filter'] ?? 'all';
$pet_filter = (int)($_GET['pet_id'] ?? 0);
$vaccine_sort = $_GET['sort'] ?? 'default';
if(!in_array($vaccine_sort,['default','az','za'],true)) $vaccine_sort='default';
if(!in_array($vaccine_filter,['all','due_soon','overdue','scheduled'],true)) $vaccine_filter='all';
[$page,$perPage,$offset]=pagination_values(6,24,[6,12,18,24,'full']);
$conditions=[];
if ($vaccine_search !== '') { $safeVaccineSearch = $conn->real_escape_string($vaccine_search); $conditions[]="(p.name LIKE '%$safeVaccineSearch%' OR v.vaccine_name LIKE '%$safeVaccineSearch%' OR v.administered_by LIKE '%$safeVaccineSearch%' OR v.remarks LIKE '%$safeVaccineSearch%')"; }
if($pet_filter>0) $conditions[]="v.pet_id=$pet_filter";
if($vaccine_filter==='due_soon') $conditions[]="v.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)";
if($vaccine_filter==='overdue') $conditions[]="v.next_due_date < CURDATE()";
if($vaccine_filter==='scheduled') $conditions[]="(v.next_due_date IS NULL OR v.next_due_date >= DATE_ADD(CURDATE(), INTERVAL 15 DAY))";
$vaccine_where=$conditions?'WHERE '.implode(' AND ',$conditions):'';
$vaccineOrder=$vaccine_sort==='az'?'v.vaccine_name ASC, v.id DESC':($vaccine_sort==='za'?'v.vaccine_name DESC, v.id DESC':'next_due_date IS NULL, next_due_date ASC, v.id DESC');
$vaccineSummary = $conn->query("SELECT COUNT(*) total_vaccines, SUM(next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)) due_soon, SUM(next_due_date < CURDATE()) overdue, COUNT(DISTINCT pet_id) pets_vaccinated FROM vaccinations")->fetch_assoc();
$filterCounts=['all'=>(int)($vaccineSummary['total_vaccines']??0),'due_soon'=>(int)($vaccineSummary['due_soon']??0),'overdue'=>(int)($vaccineSummary['overdue']??0),'scheduled'=>(int)$conn->query("SELECT COUNT(*) c FROM vaccinations WHERE next_due_date IS NULL OR next_due_date >= DATE_ADD(CURDATE(), INTERVAL 15 DAY)")->fetch_assoc()['c']];
$totalFiltered=(int)$conn->query("SELECT COUNT(*) c FROM vaccinations v JOIN pets p ON v.pet_id=p.id $vaccine_where")->fetch_assoc()['c'];
$title="Vaccinations"; include "../includes/header.php"; include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/vet_sidebar.php"; ?>
<main class="content vet-vaccinations-page" id="mainContent">
    <header class="page-heading"><div><span class="eyebrow">Preventive care</span><h1>Vaccinations</h1></div><div style="display:flex;flex-wrap:wrap;gap:10px"><a class="button-secondary" href="<?=app_url('vet/calendar.php')?>"><?=ui_icon('calendar')?>Calendar</a><button class="button-primary" type="button" id="vaccineFormToggle" aria-controls="vaccineFormPanel" aria-expanded="false"><?=ui_icon('plus')?>Add vaccine <span aria-hidden="true" id="vaccineFormChevron">&#9662;</span></button></div></header>
    <style>
    body.enterprise-ui[data-user-role="veterinarian"] #mainContent.vet-vaccinations-page .vet-vaccine-layout{display:grid!important;grid-template-columns:minmax(0,1fr)!important;height:auto!important;min-height:0!important;max-height:none!important;align-items:start!important}
    body.enterprise-ui[data-user-role="veterinarian"] #mainContent.vet-vaccinations-page #vaccineFormPanel[hidden]{display:none!important}
    body.enterprise-ui[data-user-role="veterinarian"] #mainContent.vet-vaccinations-page #vaccineFormPanel{position:static!important;width:100%!important;max-width:720px!important;height:auto!important;min-height:0!important;max-height:none!important;overflow:visible!important}
    body.enterprise-ui[data-user-role="veterinarian"] #mainContent.vet-vaccinations-page .vet-vaccine-list-card{width:100%!important;height:auto!important;min-height:0!important;max-height:none!important}
    </style>
    <?php if($m=flash('success')):?><div class="alert alert-success"><?=e($m)?></div><?php endif;?>
    <section class="metric-grid compact-metrics">
        <?php foreach([
            ['syringe','Vaccine records','total_vaccines','All vaccination entries.'],
            ['paw','Monitored pets','pets_vaccinated','Pets with vaccination history.'],
            ['clock','Due soon','due_soon','Upcoming follow-ups in 14 days.'],
            ['alert','Overdue','overdue','Vaccinations needing follow-up.']
        ] as [$icon,$label,$key,$description]):?>
        <article class="metric-card"><span class="metric-icon"><?=ui_icon($icon)?></span><div><small><?=$label?></small><strong><?=intval($vaccineSummary[$key]??0)?></strong><p><?=$description?></p></div></article>
        <?php endforeach;?>
    </section>
        <div class="soft-card vet-form-card vet-entry-card" id="vaccineFormPanel" hidden><div class="vet-form-title"><span class="pet-icon"><?=ui_icon('plus')?></span><div><h3>Add Vaccine</h3></div></div><form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="save_vaccine"><label class="form-label fw-bold mb-1">Pet</label><select class="form-select" name="pet_id"><?php $pets=$conn->query("SELECT id,name FROM pets WHERE verification_status='approved'"); while($p=$pets->fetch_assoc()): ?><option value="<?=$p['id']?>"><?=e($p['name'])?></option><?php endwhile;?></select><label class="form-label fw-bold mb-1">Vaccine Name</label><input class="form-control" name="vaccine_name" list="vaccineNameSuggestions" placeholder="Vaccine" required><label class="form-label fw-bold mb-1">Date Given</label><input class="form-control" type="date" name="date_given"><div class="field-help">Use the actual administration date.</div><label class="form-label fw-bold mb-1">Next Due Date</label><input class="form-control" type="date" name="next_due_date"><div class="field-help">Use the next booster or follow-up date.</div><label class="form-label fw-bold mb-1">Administered By</label><input class="form-control" value="<?=e($vetName)?>" readonly><small class="field-help">Current logged-in veterinarian</small><label class="form-label fw-bold mb-1">Remarks</label><input class="form-control" name="remarks" list="vaccineRemarksSuggestions" placeholder="Remarks"><datalist id="vaccineNameSuggestions"><option value="Rabies Vaccine"><option value="5-in-1 Vaccine (DHPPi)"><option value="6-in-1 Vaccine"><option value="8-in-1 Vaccine"><option value="Kennel Cough Vaccine"><option value="FVRCP Vaccine"><option value="FeLV Vaccine"><?php $suggest=$conn->query("SELECT DISTINCT vaccine_name value FROM vaccinations WHERE TRIM(vaccine_name)<>'' ORDER BY id DESC LIMIT 40");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="vaccineRemarksSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT remarks value FROM vaccinations WHERE TRIM(COALESCE(remarks,''))<>'' ORDER BY id DESC LIMIT 40");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><button class="btn btn-primary w-100 mt-2">Save</button><button class="button-secondary mt-2" type="button" id="vaccineFormClose">Close form</button></form></div>

            <form class="surface-card admin-record-finder" method="GET"><div class="admin-record-finder-heading"><div><span class="eyebrow">Record search</span><h2>Browse vaccination records</h2></div><label class="entries-select">Show<select name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[6,12,18,24,"full"])?></select></label></div><div class="admin-record-search"><input class="form-control" type="search" name="q" value="<?=e($vaccine_search)?>" placeholder="Search pet, vaccine, administered by" aria-label="Search vaccinations">
<select class="form-select" name="filter" aria-label="Vaccination status" onchange="this.form.submit()"><?php foreach(['all'=>'All','due_soon'=>'Due soon','overdue'=>'Overdue','scheduled'=>'Scheduled'] as $key=>$label):?><option value="<?=$key?>" <?=$vaccine_filter===$key?'selected':''?>><?=e($label)?> (<?=intval($filterCounts[$key]??0)?>)</option><?php endforeach;?></select>
<select class="form-select" name="pet_id" aria-label="Filter by pet"><option value="0">All approved pets</option><?php $petOptions=$conn->query("SELECT id,name FROM pets WHERE verification_status='approved' ORDER BY name");while($po=$petOptions->fetch_assoc()):?><option value="<?=$po['id']?>" <?=$pet_filter===(int)$po['id']?'selected':''?>><?=e($po['name'])?></option><?php endwhile;?></select>
<select class="form-select" name="sort" aria-label="Sort vaccinations"><?php foreach(['default'=>'Due date order','az'=>'Vaccine name A-Z','za'=>'Vaccine name Z-A'] as $key=>$label):?><option value="<?=$key?>" <?=$vaccine_sort===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select><button class="button-primary" type="submit">Search</button><a class="button-secondary" href="vaccinations.php">Clear</a></div></form>
<?php $rows=$conn->query("SELECT v.*,p.name pet_name,p.owner_id,p.species,p.breed,p.pet_photo,u.full_name owner_name,u.phone,rr.last_sent FROM vaccinations v JOIN pets p ON v.pet_id=p.id LEFT JOIN users u ON u.id=p.owner_id LEFT JOIN (SELECT entity_id,MAX(created_at) last_sent FROM audit_logs WHERE entity_type='vaccination' AND action IN ('Sent vaccination reminder','Sent vaccination follow-up notification') GROUP BY entity_id) rr ON rr.entity_id=v.id $vaccine_where ORDER BY $vaccineOrder LIMIT $perPage OFFSET $offset"); ?>
<section class="surface-card management-table-card" id="vaccinationFollowups">
    <div class="section-heading"><div><span class="eyebrow">Follow-up queue</span><h2>Vaccination follow-ups</h2></div></div>
    <div class="table-scroll-only"><table class="data-table vaccination-table">
        <thead><tr><th>Pet</th><th>Owner</th><th>Vaccine</th><th>Date given</th><th>Next due</th><th>Administered by</th><th>Status</th><th>Reminder history</th><th>Action</th></tr></thead>
        <tbody>
        <?php if(!$rows->num_rows):?><tr><td colspan="9" class="empty-cell">No vaccination records found.</td></tr><?php endif;?>
        <?php while($r=$rows->fetch_assoc()):
            $dueLabel='No due date';$badgeClass='secondary';
            if($r['next_due_date']){
                if($r['next_due_date']<date('Y-m-d')){$dueLabel='Overdue';$badgeClass='danger';}
                elseif($r['next_due_date']<=date('Y-m-d',strtotime('+14 days'))){$dueLabel='Due soon';$badgeClass='warning';}
                else{$dueLabel='Scheduled';$badgeClass='success';}
            }
            $detail=['title'=>$r['pet_name'].' vaccination','eyebrow'=>'Vaccination record','fields'=>[
                'Owner'=>$r['owner_name']?:'Not recorded','Phone'=>$r['phone']?:'No phone saved',
                'Vaccine'=>$r['vaccine_name'],'Status'=>$dueLabel,
                'Date given'=>$r['date_given']?:'Not recorded','Next due'=>$r['next_due_date']?:'Not scheduled',
                'Administered by'=>$r['administered_by']?:'Not recorded',
                'Last reminder'=>$r['last_sent']?:'Never sent','Remarks'=>$r['remarks']?:'No remarks saved.'
            ]];
        ?>
        <tr data-record-detail='<?=e(json_encode($detail))?>'>
            <td><div class="table-identity"><?=pet_avatar_markup($r,'small')?><div><b><?=e($r['pet_name'])?></b><small><?=e($r['species'])?></small></div></div></td>
            <td><b><?=e($r['owner_name']?:'Not recorded')?></b><small><?=e($r['phone']?:'No phone saved')?></small></td>
            <td><?=e($r['vaccine_name'])?></td>
            <td><?=$r['date_given']?date('M d, Y',strtotime($r['date_given'])):'Not recorded'?></td>
            <td><?=$r['next_due_date']?date('M d, Y',strtotime($r['next_due_date'])):'Not scheduled'?></td>
            <td><?=e($r['administered_by']?:'Not recorded')?></td>
            <td><span class="badge text-bg-<?=$badgeClass?>"><?=e($dueLabel)?></span></td>
            <td><?=$r['last_sent']?date('M d, Y h:i A',strtotime($r['last_sent'])):'Never sent'?></td>
            <td><?php if($dueLabel==='Overdue'):?><form method="POST" data-confirm-message="Send a vaccination follow-up notification to the owner?"><?=csrf_field()?><input type="hidden" name="action" value="send_owner_notification"><input type="hidden" name="vaccination_id" value="<?=$r['id']?>"><button class="button-secondary" type="submit"><?=ui_icon('bell')?>Send reminder</button></form><?php else:?><span class="text-muted">No follow-up needed</span><?php endif;?></td>
        </tr>
        <?php endwhile;?>
        </tbody>
    </table></div>
    <?=render_pagination($page,$perPage,$totalFiltered,['filter'=>$vaccine_filter,'q'=>$vaccine_search,'pet_id'=>$pet_filter?:null,'sort'=>$vaccine_sort,'per_page'=>per_page_value($perPage),'_anchor'=>'vaccinationFollowups'])?>
</section>
<script>
(()=>{
    const toggle=document.getElementById('vaccineFormToggle');
    const panel=document.getElementById('vaccineFormPanel');
    const setExpanded=expanded=>{
        panel.hidden=!expanded;
        toggle.setAttribute('aria-expanded',String(expanded));
        document.getElementById('vaccineFormChevron').textContent=expanded?'\u25b4':'\u25be';
    };
    toggle.addEventListener('click',()=>setExpanded(panel.hidden));
    document.getElementById('vaccineFormClose').addEventListener('click',()=>{setExpanded(false);toggle.focus();});
})();
</script>
</main></div><?php include "../includes/footer.php"; ?>
