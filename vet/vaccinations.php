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
    <header class="page-heading"><div><span class="eyebrow">Preventive care</span><h1>Vaccinations</h1></div><a class="button-secondary" href="<?=app_url('vet/calendar.php')?>"><?=ui_icon('calendar')?>Calendar</a></header>
    <?php if($m=flash('success')):?><div class="alert alert-success"><?=e($m)?></div><?php endif;?>
    <section class="admin-summary-grid mb-4"><div class="admin-summary-card"><span>Total Vaccines</span><b><?= (int)($vaccineSummary['total_vaccines'] ?? 0) ?></b></div><div class="admin-summary-card"><span>Due Soon</span><b><?= (int)($vaccineSummary['due_soon'] ?? 0) ?></b></div><div class="admin-summary-card"><span>Overdue</span><b><?= (int)($vaccineSummary['overdue'] ?? 0) ?></b></div><div class="admin-summary-card"><span>Pets Vaccinated</span><b><?= (int)($vaccineSummary['pets_vaccinated'] ?? 0) ?></b></div></section>
    <div class="vet-admin-split vet-vaccine-layout vet-clinical-admin-layout">
        <div class="soft-card vet-form-card vet-entry-card"><div class="vet-form-title"><span class="pet-icon"><?=ui_icon('plus')?></span><div><h3>Add Vaccine</h3></div></div><form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="save_vaccine"><label class="form-label fw-bold mb-1">Pet</label><select class="form-select" name="pet_id"><?php $pets=$conn->query("SELECT id,name FROM pets WHERE verification_status='approved'"); while($p=$pets->fetch_assoc()): ?><option value="<?=$p['id']?>"><?=e($p['name'])?></option><?php endwhile;?></select><label class="form-label fw-bold mb-1">Vaccine Name</label><input class="form-control" name="vaccine_name" list="vaccineNameSuggestions" placeholder="Vaccine" required><label class="form-label fw-bold mb-1">Date Given</label><input class="form-control" type="date" name="date_given"><div class="field-help">Use the actual administration date.</div><label class="form-label fw-bold mb-1">Next Due Date</label><input class="form-control" type="date" name="next_due_date"><div class="field-help">Use the next booster or follow-up date.</div><label class="form-label fw-bold mb-1">Administered By</label><input class="form-control" value="<?=e($vetName)?>" readonly><small class="field-help">Current logged-in veterinarian</small><label class="form-label fw-bold mb-1">Remarks</label><input class="form-control" name="remarks" list="vaccineRemarksSuggestions" placeholder="Remarks"><datalist id="vaccineNameSuggestions"><option value="Rabies Vaccine"><option value="5-in-1 Vaccine (DHPPi)"><option value="6-in-1 Vaccine"><option value="8-in-1 Vaccine"><option value="Kennel Cough Vaccine"><option value="FVRCP Vaccine"><option value="FeLV Vaccine"><?php $suggest=$conn->query("SELECT DISTINCT vaccine_name value FROM vaccinations WHERE TRIM(vaccine_name)<>'' ORDER BY id DESC LIMIT 40");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="vaccineRemarksSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT remarks value FROM vaccinations WHERE TRIM(COALESCE(remarks,''))<>'' ORDER BY id DESC LIMIT 40");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><button class="btn btn-primary w-100 mt-2">Save</button></form></div>
        <div class="table-card vet-list-card vet-vaccine-list-card"><div class="section-head mb-3 clinical-list-heading"><div><h3>Vaccination List</h3></div></div>
            <section class="surface-card data-toolbar vet-toolbar-card"><div class="filter-tabs"><?php foreach(['all'=>'All','due_soon'=>'Due soon','overdue'=>'Overdue','scheduled'=>'Scheduled'] as $key=>$label): $query=['filter'=>$key,'q'=>$vaccine_search,'pet_id'=>$pet_filter?:null,'per_page'=>per_page_value($perPage)]; ?><a class="filter-tab status-<?=e($key)?> <?=$vaccine_filter===$key?'active':''?>" href="?<?=e(http_build_query(array_filter($query,fn($v)=>$v!==''&&$v!==null)))?>"><?=e($label)?><b><?=intval($filterCounts[$key]??0)?></b></a><?php endforeach;?></div><div class="toolbar-actions"><label class="entries-select">Show<select class="unified-show-select" name="per_page"><?=render_per_page_options($perPage,[6,12,18,24,'full'])?></select></label></div></section>
            <form class="admin-list-search" method="GET" action="vaccinations.php"><input type="hidden" name="filter" value="<?=e($vaccine_filter)?>"><input type="hidden" name="per_page" value="<?=e(per_page_value($perPage))?>"><input class="form-control" type="search" name="q" value="<?=e($vaccine_search)?>" placeholder="Search pet, vaccine, administered by"><select class="form-select" name="pet_id"><option value="0">Filter by approved pet</option><?php $petOptions=$conn->query("SELECT id,name FROM pets WHERE verification_status='approved' ORDER BY name"); while($po=$petOptions->fetch_assoc()):?><option value="<?=$po['id']?>" <?=$pet_filter===(int)$po['id']?'selected':''?>><?=e($po['name'])?></option><?php endwhile;?></select><select class="form-select" name="sort" aria-label="Sort vaccine names"><option value="default" <?=$vaccine_sort==='default'?'selected':''?>>Due date order</option><option value="az" <?=$vaccine_sort==='az'?'selected':''?>>Vaccine name A–Z</option><option value="za" <?=$vaccine_sort==='za'?'selected':''?>>Vaccine name Z–A</option></select><button class="btn btn-primary" type="submit">Search</button><a class="btn btn-light" href="vaccinations.php">Clear</a></form>
            <?php $rows=$conn->query("SELECT v.*,p.name pet_name,p.owner_id FROM vaccinations v JOIN pets p ON v.pet_id=p.id $vaccine_where ORDER BY $vaccineOrder LIMIT $perPage OFFSET $offset"); ?>
            <?php if($rows->num_rows===0): ?><div class="admin-empty-state"><h3>No vaccination records found</h3><p>Try another filter or add a new vaccination.</p></div><?php else: ?><div class="admin-card-list view-grid" id="vetVaccineList"><?php while($r=$rows->fetch_assoc()): $dueLabel='Scheduled';$dueClass='is-scheduled'; if(!empty($r['next_due_date'])&&$r['next_due_date']<date('Y-m-d')){$dueLabel='Overdue';$dueClass='is-overdue';}elseif(!empty($r['next_due_date'])&&$r['next_due_date']<=date('Y-m-d',strtotime('+14 days'))){$dueLabel='Due soon';$dueClass='is-due-soon';} $detail=['title'=>$r['pet_name'].' vaccination','eyebrow'=>'Vaccination record','fields'=>['Vaccine'=>$r['vaccine_name'],'Status'=>$dueLabel,'Date given'=>$r['date_given'] ?: 'N/A','Next due'=>$r['next_due_date'] ?: 'N/A','Administered by'=>$r['administered_by'] ?: 'N/A','Remarks'=>$r['remarks'] ?: 'No remarks saved.']]; ?><article class="admin-review-card vaccination-card <?=$dueClass?>" data-record-detail='<?=e(json_encode($detail))?>'><div class="admin-review-main"><div class="admin-row-title"><h3><?=e($r['vaccine_name'])?></h3><span class="badge vet-vaccine-status"><?=e($dueLabel)?></span></div><p><?=e($r['remarks'] ?: 'No remarks saved.')?></p><div class="admin-meta-row"><span>Pet: <?=e($r['pet_name'])?></span><span>Date given: <?=e($r['date_given'] ?: 'N/A')?></span><span>Next due: <?=e($r['next_due_date'] ?: 'N/A')?></span><span>By: <?=e($r['administered_by'] ?: 'N/A')?></span></div><?php if($dueLabel==='Overdue'):?><div class="admin-note-box main-note owner-follow-up"><span>Owner follow-up</span><p>Contact the owner to arrange the overdue vaccination.</p><form method="POST" data-confirm-message="Send a vaccination follow-up notification to the owner?"><?=csrf_field()?><input type="hidden" name="action" value="send_owner_notification"><input type="hidden" name="vaccination_id" value="<?=$r['id']?>"><button class="button-secondary" type="submit"><?=ui_icon('bell')?>Send notification</button></form></div><?php endif;?></div></article><?php endwhile;?></div><?=render_pagination($page,$perPage,$totalFiltered,['filter'=>$vaccine_filter,'q'=>$vaccine_search,'pet_id'=>$pet_filter?:null,'sort'=>$vaccine_sort,'per_page'=>per_page_value($perPage),'_anchor'=>'vetVaccineList'])?><?php endif; ?>
        </div>
    </div>
</main></div><?php include "../includes/footer.php"; ?>
