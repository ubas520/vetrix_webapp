<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role('admin');
ensure_review_workflow_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $vaccinationId=(int)($_POST['vaccination_id']??0);
    $stmt=$conn->prepare("SELECT v.*,p.name pet_name,p.owner_id,u.full_name owner_name FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id WHERE v.id=? LIMIT 1");
    $stmt->bind_param('i',$vaccinationId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();
    if(!$row){
        flash('error','Vaccination record not found.');
    } else {
        $limit=$conn->prepare("SELECT COUNT(*) c,MAX(created_at) last_sent FROM audit_logs WHERE entity_type='vaccination' AND entity_id=? AND action='Sent vaccination reminder' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)");
        $limit->bind_param('i',$vaccinationId);$limit->execute();$recent=$limit->get_result()->fetch_assoc();
        if((int)$recent['c']>=1){
            flash('error','Reminder limit reached for this vaccination. Only one reminder may be sent within 24 hours.');
        } else {
            $due=$row['next_due_date']?date('M d, Y',strtotime($row['next_due_date'])):'the scheduled due date';
            $notificationId=notify_user($conn,(int)$row['owner_id'],'Vaccination reminder',$row['pet_name'].' has a vaccination due on '.$due.'.','vaccine',null);
            if (!$notificationId) {
                flash('error','The reminder could not be created.');
                redirect_to('admin/vaccinations.php');
            }
            log_action($conn,'Sent vaccination reminder','vaccination',$vaccinationId,'Reminder sent for '.$row['pet_name'].' to client account #'.(int)$row['owner_id'].'.');
            flash('success','Vaccination reminder sent and recorded.');
        }
    }
    redirect_to('admin/vaccinations.php');
}

$statusFilter=$_GET['status']??'all';if(!in_array($statusFilter,['all','overdue','due_soon','scheduled','unscheduled'],true))$statusFilter='all';
$q=trim($_GET['q']??'');
$speciesOptions=$conn->query("SELECT DISTINCT species FROM pets WHERE species IS NOT NULL AND TRIM(species)<>'' ORDER BY species")->fetch_all(MYSQLI_ASSOC);
$vetOptions=$conn->query("SELECT id,full_name FROM users WHERE role='veterinarian' AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$speciesFilter=trim($_GET['species']??'all');
$validSpecies=array_map(fn($row)=>$row['species'],$speciesOptions);if($speciesFilter!=='all'&&!in_array($speciesFilter,$validSpecies,true))$speciesFilter='all';
$vetFilter=(int)($_GET['vet']??0);if($vetFilter&&!in_array($vetFilter,array_map(fn($row)=>(int)$row['id'],$vetOptions),true))$vetFilter=0;
$conditions=[];
if($statusFilter==='overdue')$conditions[]="v.next_due_date<CURDATE()";
elseif($statusFilter==='due_soon')$conditions[]="v.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY)";
elseif($statusFilter==='scheduled')$conditions[]="v.next_due_date>DATE_ADD(CURDATE(),INTERVAL 60 DAY)";
elseif($statusFilter==='unscheduled')$conditions[]="v.next_due_date IS NULL";
if($speciesFilter!=='all')$conditions[]="p.species='".$conn->real_escape_string($speciesFilter)."'";
if($vetFilter)$conditions[]='v.administered_by_id='.$vetFilter;
if($q!==''){$safe=$conn->real_escape_string($q);$conditions[]="(p.name LIKE '%$safe%' OR p.species LIKE '%$safe%' OR u.full_name LIKE '%$safe%' OR u.phone LIKE '%$safe%' OR v.vaccine_name LIKE '%$safe%' OR v.administered_by LIKE '%$safe%')";}
$where=$conditions?'WHERE '.implode(' AND ',$conditions):'';
$totalVaccines=(int)$conn->query("SELECT COUNT(*) c FROM vaccinations")->fetch_assoc()['c'];
$monitoredPets=(int)$conn->query("SELECT COUNT(DISTINCT pet_id) c FROM vaccinations")->fetch_assoc()['c'];
$dueSoon=(int)$conn->query("SELECT COUNT(*) c FROM vaccinations WHERE next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY)")->fetch_assoc()['c'];
$overdue=(int)$conn->query("SELECT COUNT(*) c FROM vaccinations WHERE next_due_date<CURDATE()")->fetch_assoc()['c'];
$scheduled=(int)$conn->query("SELECT COUNT(*) c FROM vaccinations WHERE next_due_date>DATE_ADD(CURDATE(),INTERVAL 60 DAY)")->fetch_assoc()['c'];
$unscheduled=(int)$conn->query("SELECT COUNT(*) c FROM vaccinations WHERE next_due_date IS NULL")->fetch_assoc()['c'];
$summaryRecords=$conn->query("SELECT v.id,v.vaccine_name,v.next_due_date,p.name pet_name,p.species,u.full_name owner_name FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id ORDER BY v.created_at DESC,v.id DESC LIMIT 12")->fetch_all(MYSQLI_ASSOC);
$summaryPets=$conn->query("SELECT p.id,p.name,p.species,u.full_name owner_name,COUNT(v.id) vaccine_count,MAX(v.next_due_date) latest_due FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id GROUP BY p.id ORDER BY p.name LIMIT 12")->fetch_all(MYSQLI_ASSOC);
$summaryDue=$conn->query("SELECT v.id,v.vaccine_name,v.next_due_date,p.name pet_name,u.full_name owner_name FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id WHERE v.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) ORDER BY v.next_due_date LIMIT 20")->fetch_all(MYSQLI_ASSOC);
$summaryOverdue=$conn->query("SELECT v.id,v.vaccine_name,v.next_due_date,p.name pet_name,u.full_name owner_name FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id WHERE v.next_due_date<CURDATE() ORDER BY v.next_due_date LIMIT 20")->fetch_all(MYSQLI_ASSOC);
function vaccination_popup_html(array $rows,string $mode='record'):string{
    if(!$rows)return '<div class="empty-state compact"><p>No matching vaccination records.</p></div>';
    $html='<div class="dashboard-detail-list">';
    foreach($rows as $row){
        if($mode==='pet'){
            $html.='<a class="dashboard-detail-entry" href="'.e(app_url('admin/pets.php?pet_id='.(int)$row['id'].'&return_to=admin/vaccinations.php')).'"><span><b>'.e($row['name']).' · '.e($row['species']).'</b><small>'.e($row['owner_name']).'</small><em>'.intval($row['vaccine_count']).' vaccination record'.((int)$row['vaccine_count']===1?'':'s').'</em></span><strong>View</strong></a>';
        } else {
            $due=!empty($row['next_due_date'])?date('M d, Y',strtotime($row['next_due_date'])):'No due date';
            $html.='<a class="dashboard-detail-entry" href="'.e(app_url('admin/vaccinations.php?vaccination_id='.$row['id'])).'"><span><b>'.e($row['pet_name']).' · '.e($row['vaccine_name']).'</b><small>'.e($row['owner_name']).'</small><em>Next due: '.e($due).'</em></span><strong>Open</strong></a>';
        }
    }
    return $html.'</div>';
}
[$page,$perPage,$offset]=pagination_values(5,20);
$totalFiltered=(int)$conn->query("SELECT COUNT(*) c FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id $where")->fetch_assoc()['c'];
$rows=$conn->query("SELECT v.*,p.name pet_name,p.species,p.breed,p.pet_photo,u.full_name owner_name,u.phone,u.email,rr.last_sent,rr.sent_24h FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id LEFT JOIN (SELECT entity_id vaccination_id,MAX(created_at) last_sent,SUM(created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)) sent_24h FROM audit_logs WHERE entity_type='vaccination' AND action='Sent vaccination reminder' GROUP BY entity_id) rr ON rr.vaccination_id=v.id $where ORDER BY CASE WHEN v.next_due_date IS NULL THEN 1 ELSE 0 END,v.next_due_date ASC LIMIT $perPage OFFSET $offset");
$title='Vaccination Monitoring';include "../includes/header.php";include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/admin_sidebar.php";?><main class="content admin-vaccinations-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Preventive care</span><h1>Vaccination Monitoring</h1></div><a class="button-secondary" href="<?=app_url('admin/reports.php')?>"><?=ui_icon('chart')?>Open reports</a></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>
<section class="metric-grid compact-metrics"><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Vaccine records','eyebrow'=>'Recent vaccination entries','html'=>vaccination_popup_html($summaryRecords)]))?>'><span class="metric-icon"><?=ui_icon('syringe')?></span><div><small>Vaccine records</small><strong><?=$totalVaccines?></strong><p>Open recent vaccination entries.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Monitored pets','eyebrow'=>'Pets with vaccination history','html'=>vaccination_popup_html($summaryPets,'pet')]))?>'><span class="metric-icon info"><?=ui_icon('paw')?></span><div><small>Monitored pets</small><strong><?=$monitoredPets?></strong><p>Review pets with vaccination history.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Vaccinations due soon','eyebrow'=>'Next 60 days','html'=>vaccination_popup_html($summaryDue)]))?>'><span class="metric-icon warning"><?=ui_icon('clock')?></span><div><small>Due soon</small><strong><?=$dueSoon?></strong><p>Open upcoming follow-ups.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Overdue vaccinations','eyebrow'=>'Immediate follow-up','html'=>vaccination_popup_html($summaryOverdue)]))?>'><span class="metric-icon danger"><?=ui_icon('alert')?></span><div><small>Overdue</small><strong><?=$overdue?></strong><p>Open overdue vaccination records.</p></div></button></section>
<section class="surface-card data-toolbar vaccination-toolbar"><div class="filter-tabs"><?php $tabs=['all'=>['All',$totalVaccines],'overdue'=>['Overdue',$overdue],'due_soon'=>['Due soon',$dueSoon],'scheduled'=>['Scheduled',$scheduled],'unscheduled'=>['No due date',$unscheduled]];foreach($tabs as $key=>$tab):?><a class="filter-tab <?=$statusFilter===$key?'active':''?> status-<?=e($key)?>" href="?<?=e(http_build_query(['status'=>$key,'q'=>$q,'species'=>$speciesFilter,'vet'=>$vetFilter,'per_page'=>per_page_value($perPage)]))?>"><?=e($tab[0])?><b><?=intval($tab[1])?></b></a><?php endforeach;?></div><form class="vaccination-show-controls" method="GET"><input type="hidden" name="status" value="<?=e($statusFilter)?>"><input type="hidden" name="q" value="<?=e($q)?>"><input type="hidden" name="species" value="<?=e($speciesFilter)?>"><input type="hidden" name="vet" value="<?=$vetFilter?>"><label class="entries-select">Show<select class="unified-show-select" name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage)?></select></label></form></section>
<form class="surface-card data-search-bar vaccination-search-row" method="GET"><input type="hidden" name="status" value="<?=e($statusFilter)?>"><input type="hidden" name="per_page" value="<?=e(per_page_value($perPage))?>"><input type="search" name="q" value="<?=e($q)?>" placeholder="Search pet, owner, phone, vaccine, or veterinarian" aria-label="Search vaccinations"><select class="form-select" name="species" aria-label="Animal type"><option value="all">All animal types</option><?php foreach($speciesOptions as $option):?><option value="<?=e($option['species'])?>" <?=$speciesFilter===$option['species']?'selected':''?>><?=e($option['species'])?></option><?php endforeach;?></select><select class="form-select" name="vet" aria-label="Veterinarian"><option value="0">All veterinarians</option><?php foreach($vetOptions as $vet):?><option value="<?=$vet['id']?>" <?=$vetFilter===(int)$vet['id']?'selected':''?>><?=e($vet['full_name'])?></option><?php endforeach;?></select><button class="button-primary" type="submit">Search</button><a class="button-secondary" href="?<?=e(http_build_query(['status'=>$statusFilter,'per_page'=>per_page_value($perPage)]))?>">Clear</a></form>
<section class="surface-card management-table-card" id="vaccinationFollowups"><div class="section-heading"><div><span class="eyebrow">Follow-up queue</span><h2>Vaccination follow-ups</h2></div></div><div class="table-scroll-only"><table class="data-table vaccination-table"><thead><tr><th>Pet</th><th>Owner</th><th>Vaccine</th><th>Date given</th><th>Next due</th><th>Administered by</th><th>Status</th><th>Reminder history</th><th>Action</th></tr></thead><tbody>
<?php if(!$rows->num_rows):?><tr><td colspan="9" class="empty-cell">No vaccination records found.</td></tr><?php endif;while($r=$rows->fetch_assoc()):$status='No due date';$badgeClass='secondary';if($r['next_due_date']){$dueTs=strtotime($r['next_due_date']);if($dueTs<strtotime(date('Y-m-d'))){$status='Overdue';$badgeClass='danger';}elseif($dueTs<=strtotime('+60 days')){$status='Due soon';$badgeClass='warning';}else{$status='Scheduled';$badgeClass='success';}}$detail=['title'=>$r['pet_name'].' · '.$r['vaccine_name'],'eyebrow'=>'Vaccination record','fields'=>['Owner'=>$r['owner_name'],'Phone'=>$r['phone']?:'No phone saved','Email'=>$r['email'],'Animal type'=>$r['species'],'Breed'=>$r['breed']?:'Not recorded','Date given'=>$r['date_given']?date('M d, Y',strtotime($r['date_given'])):'Not recorded','Next due'=>$r['next_due_date']?date('M d, Y',strtotime($r['next_due_date'])):'Not scheduled','Administered by'=>$r['administered_by']?:'Not recorded','Status'=>$status,'Last reminder'=>$r['last_sent']?date('M d, Y h:i A',strtotime($r['last_sent'])):'Never sent','Reminders in last 24 hours'=>(int)($r['sent_24h']??0),'Remarks'=>$r['remarks']?:'No remarks']];?><tr data-vaccination-id="<?=intval($r['id'])?>" data-record-detail='<?=e(json_encode($detail))?>'><td><div class="table-identity"><?=pet_avatar_markup($r,'small')?><div><b><?=e($r['pet_name'])?></b><small><?=e($r['species'])?></small></div></div></td><td><b><?=e($r['owner_name'])?></b><small><?=e($r['phone']?:'No phone saved')?></small></td><td><?=e($r['vaccine_name'])?></td><td><?=$r['date_given']?date('M d, Y',strtotime($r['date_given'])):'Not recorded'?></td><td><?=$r['next_due_date']?date('M d, Y',strtotime($r['next_due_date'])):'Not scheduled'?></td><td><?=e($r['administered_by']?:'Not recorded')?></td><td><span class="badge text-bg-<?=$badgeClass?>"><?=e($status)?></span></td><td><b><?=$r['last_sent']?date('M d, h:i A',strtotime($r['last_sent'])):'Never sent'?></b><small><?=intval($r['sent_24h']??0)?> of 1 used in 24 hours</small></td><td><form method="POST" data-confirm-message="Send this vaccination reminder now?"><?=csrf_field()?><input type="hidden" name="vaccination_id" value="<?=$r['id']?>"><button class="button-secondary" type="submit" <?=intval($r['sent_24h']??0)>=1?'disabled title="24-hour reminder limit reached"':''?>><?=ui_icon('bell')?>Send reminder</button></form></td></tr><?php endwhile;?></tbody></table></div><?=render_pagination($page,$perPage,$totalFiltered,['status'=>$statusFilter,'q'=>$q,'species'=>$speciesFilter,'vet'=>$vetFilter,'per_page'=>per_page_value($perPage),'_anchor'=>'vaccinationFollowups'])?></section>
<script>(()=>{const id=new URL(location.href).searchParams.get('vaccination_id');if(!id)return;const row=document.querySelector(`[data-vaccination-id="${CSS.escape(id)}"]`);if(row)setTimeout(()=>row.click(),100);})();</script>
</main></div><?php include "../includes/footer.php";?>
