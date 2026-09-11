<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_role('veterinarian');
$uid=(int)current_user_id();

function vet_dashboard_map($result, $callback){
    $items=[];
    while($result && $row=$result->fetch_assoc()){
        $items[]=$callback($row);
    }
    return $items;
}
function vet_dashboard_list($items, $icon){
    if(!$items) return '<div class="empty-state compact"><span class="empty-icon">'.ui_icon($icon).'</span><p>No matching records found.</p></div>';
    $html='<div class="dashboard-ui-overview-list">';
    foreach($items as $item){
        $tag=!empty($item[3])?'a':'article';$href=!empty($item[3])?' href="'.e(app_url($item[3])).'"':'';$html.='<'.$tag.' class="dashboard-ui-overview-entry"'.$href.'><span>'.ui_icon($icon).'</span><div><b>'.e($item[0]).'</b><small>'.e($item[1]).'</small><em>'.e($item[2]).'</em></div><strong>Open</strong></'.$tag.'>';
    }
    return $html.'</div>';
}
function vet_dashboard_detail($title,$eyebrow,$html,$url='',$label=''){
    $detail=['title'=>$title,'eyebrow'=>$eyebrow,'html'=>$html];
    if(trim((string)$url)!==''){
        $detail['action_url']=app_url($url);
        $detail['action_label']=$label!==''?$label:'Open';
    }
    return e(json_encode($detail, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

$records=(int)$conn->query("SELECT COUNT(*) c FROM medical_records WHERE veterinarian_id=$uid OR veterinarian_id IS NULL")->fetch_assoc()['c'];
$pets=(int)$conn->query("SELECT COUNT(*) c FROM pets WHERE verification_status='approved'")->fetch_assoc()['c'];
$activeAppointments=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE (assigned_vet_id=$uid OR assigned_vet_id IS NULL) AND status IN ('approved','pending')")->fetch_assoc()['c'];
$dueSoon=(int)$conn->query("SELECT COUNT(*) c FROM vaccinations WHERE next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)")->fetch_assoc()['c'];
$approvedToday=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE assigned_vet_id=$uid AND status='approved' AND DATE(COALESCE(scheduled_date,requested_date))=CURDATE()")->fetch_assoc()['c'];
$pending=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE (assigned_vet_id=$uid OR assigned_vet_id IS NULL) AND status='pending'")->fetch_assoc()['c'];
$critical=(int)$conn->query("SELECT COUNT(*) c FROM pets WHERE verification_status='approved' AND TRIM(COALESCE(critical_notes,''))<>'' AND LOWER(TRIM(critical_notes))<>'none'")->fetch_assoc()['c'];
$attention=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE (assigned_vet_id=$uid OR assigned_vet_id IS NULL) AND ((status='pending' AND requested_date<NOW()) OR (status='approved' AND (scheduled_date IS NULL OR assigned_vet_id IS NULL OR scheduled_date<NOW())))")->fetch_assoc()['c'];
$leavePending=(int)$conn->query("SELECT COUNT(*) c FROM staff_availability WHERE user_id=$uid AND event_kind='unavailable' AND reason LIKE '[Pending]%' AND ends_at>=NOW()")->fetch_assoc()['c'];

$activeItems=vet_dashboard_map($conn->query("SELECT a.*,u.full_name owner_name,p.name pet_name,p.species FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id WHERE (a.assigned_vet_id=$uid OR a.assigned_vet_id IS NULL) AND a.status IN ('approved','pending') ORDER BY FIELD(a.status,'approved','pending'),COALESCE(a.scheduled_date,a.requested_date) ASC"), function($r){return [$r['pet_name'], $r['species'], $r['owner_name'].' · '.date('M d, Y h:i A',strtotime($r['scheduled_date']?:$r['requested_date'])).' · '.ucfirst($r['status']).' · '.($r['reason'] ?: 'No concern recorded'),'vet/appointments.php?appointment_id='.$r['id']];});
$patientItems=vet_dashboard_map($conn->query("SELECT p.id,p.name,p.species,p.breed,p.sex,u.full_name owner_name FROM pets p JOIN users u ON p.owner_id=u.id WHERE p.verification_status='approved' ORDER BY p.created_at DESC,p.id DESC"), function($r){return [$r['name'].' · '.$r['owner_name'], $r['species'].' · '.$r['breed'].' · '.$r['sex'], 'Approved patient profile','vet/pets.php?edit_id='.$r['id']];});
$recordItems=vet_dashboard_map($conn->query("SELECT m.id,m.pet_id,m.visit_date,m.diagnosis,m.treatment,p.name pet_name,p.species FROM medical_records m JOIN pets p ON m.pet_id=p.id WHERE m.veterinarian_id=$uid OR m.veterinarian_id IS NULL ORDER BY m.visit_date DESC,m.id DESC"), function($r){return [$r['pet_name'], $r['species'], date('M d, Y',strtotime($r['visit_date'])).' · '.($r['diagnosis']?:'Clinical record').' · '.($r['treatment'] ?: 'Treatment not recorded'),'vet/medical_records.php?pet_id='.$r['pet_id'].'#medicalRecord'.$r['id']];});
$vaccineItems=vet_dashboard_map($conn->query("SELECT v.*,p.name pet_name,u.full_name owner_name FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id WHERE v.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY) ORDER BY v.next_due_date ASC"), function($r){return [$r['pet_name'].' · '.$r['vaccine_name'], 'Due '.date('M d, Y',strtotime($r['next_due_date'])), $r['owner_name'],'vet/vaccinations.php?pet_id='.$r['pet_id']];});
$todayItems=vet_dashboard_map($conn->query("SELECT a.*,u.full_name owner_name,p.name pet_name,p.species FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id WHERE a.assigned_vet_id=$uid AND a.status='approved' AND DATE(COALESCE(a.scheduled_date,a.requested_date))=CURDATE() ORDER BY COALESCE(a.scheduled_date,a.requested_date) ASC"), function($r){return [$r['pet_name'], $r['species'], $r['owner_name'].' · '.date('h:i A',strtotime($r['scheduled_date']?:$r['requested_date'])).' · '.($r['reason'] ?: 'No concern recorded'),'vet/appointments.php?appointment_id='.$r['id']];});
$pendingItems=vet_dashboard_map($conn->query("SELECT a.*,u.full_name owner_name,p.name pet_name,p.species FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id WHERE (a.assigned_vet_id=$uid OR a.assigned_vet_id IS NULL) AND a.status='pending' ORDER BY a.requested_date ASC"), function($r){return [$r['pet_name'], $r['species'], $r['owner_name'].' · '.date('M d, Y h:i A',strtotime($r['requested_date'])).' · '.($r['reason'] ?: 'No concern recorded'),'vet/appointments.php?appointment_id='.$r['id']];});
$criticalItems=vet_dashboard_map($conn->query("SELECT p.id,p.name,p.species,p.critical_notes,u.full_name owner_name FROM pets p JOIN users u ON p.owner_id=u.id WHERE p.verification_status='approved' AND TRIM(COALESCE(p.critical_notes,''))<>'' AND LOWER(TRIM(p.critical_notes))<>'none' ORDER BY p.updated_at DESC,p.id DESC"), function($r){return [$r['name'].' · '.$r['owner_name'], $r['species'], $r['critical_notes'],'vet/health_monitoring.php?q='.rawurlencode($r['name'])];});
$leaveItems=vet_dashboard_map($conn->query("SELECT id,starts_at,ends_at,reason FROM staff_availability WHERE user_id=$uid AND event_kind='unavailable' AND reason LIKE '[Pending]%' AND ends_at>=NOW() ORDER BY starts_at ASC"), function($r){return [date('M d, Y h:i A',strtotime($r['starts_at'])), 'Pending unavailability request', preg_replace('/^\[Pending\]\s*/','',$r['reason']) ?: 'No reason recorded','vet/calendar.php?scope=workforce&workforce=unavailable&availability_id='.$r['id']];});

$activeHtml=vet_dashboard_list($activeItems,'calendar-days');
$patientHtml=vet_dashboard_list($patientItems,'paw');
$recordHtml=vet_dashboard_list($recordItems,'file');
$vaccineHtml=vet_dashboard_list($vaccineItems,'syringe');
$todayHtml=vet_dashboard_list($todayItems,'calendar-days');
$pendingHtml=vet_dashboard_list($pendingItems,'clock');
$criticalHtml=vet_dashboard_list($criticalItems,'heart-pulse');
$leaveHtml=vet_dashboard_list($leaveItems,'clock');

$appointments=$conn->query("SELECT a.*,u.full_name owner_name,p.name pet_name,p.species,p.pet_photo FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id WHERE (a.assigned_vet_id=$uid OR a.assigned_vet_id IS NULL) AND a.status IN ('approved','pending','completed') ORDER BY FIELD(a.status,'approved','pending','completed'),COALESCE(a.scheduled_date,a.requested_date) ASC LIMIT 5");
$recordRows=$conn->query("SELECT m.*,p.name pet_name,p.species,p.pet_photo FROM medical_records m JOIN pets p ON m.pet_id=p.id WHERE m.veterinarian_id=$uid OR m.veterinarian_id IS NULL ORDER BY m.visit_date DESC,m.id DESC LIMIT 5");
$queue=[
    ['Critical pet notes',$critical,'','vet/health_monitoring.php','heart-pulse','danger',1,$criticalHtml],
    ['Pending requests',$pending,'','vet/appointments.php?status=pending','clock','warning',2,$pendingHtml],
    ['Appointments today',$approvedToday,'','vet/appointments.php?status=approved','calendar-days','info',3,$todayHtml],
    ['Vaccinations due soon',$dueSoon,'','vet/vaccinations.php','syringe','warning',4,$vaccineHtml],
    ['My leave requests',$leavePending,'','vet/calendar.php?scope=workforce&workforce=unavailable','clock','success',5,$leaveHtml],
];
usort($queue,function($a,$b){return [$a[6],-$a[1]] <=> [$b[6],-$b[1]];});
$title='Veterinarian Dashboard';include '../includes/header.php';include '../includes/navbar.php';
?>
<div class="layout"><?php include '../includes/vet_sidebar.php';?><main class="content vet-dashboard-page" id="mainContent">
<header class="page-heading dashboard-heading vetrix-dashboard-hero">
  <div class="dashboard-hero-copy">
    <span class="eyebrow">Welcome back</span>
    <h1><?=e($_SESSION['full_name'] ?? 'Veterinarian')?>!</h1>
    <p>Here is your clinical schedule and patient-care overview for today.</p>
  </div>
  <div class="heading-actions"><a class="button-secondary" href="<?=app_url('vet/calendar.php')?>"><?=ui_icon('calendar-days')?>Calendar</a><a class="button-primary" href="<?=app_url('vet/prescription.php')?>"><?=ui_icon('plus')?>Create record</a></div>
  <div class="dashboard-hero-art" aria-hidden="true"><img src="<?=e(app_url('assets/images/vetrix-dashboard-dog.png'))?>" alt=""></div>
</header>
<section class="metric-grid"><button type="button" class="metric-card" data-record-detail='<?=vet_dashboard_detail('Active appointments','Clinical overview',$activeHtml,'vet/appointments.php','Open appointments')?>'><span class="metric-icon warning"><?=ui_icon('calendar-days')?></span><div><small>Active appointments</small><strong><?=$activeAppointments?></strong><p>Approved or pending appointments visible to you.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=vet_dashboard_detail('Approved patients','Clinical overview',$patientHtml,'vet/pets.php','Open pets')?>'><span class="metric-icon info"><?=ui_icon('paw')?></span><div><small>Approved patients</small><strong><?=$pets?></strong><p>Verified pet profiles available for clinical care.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=vet_dashboard_detail('Medical records','Clinical overview',$recordHtml,'vet/medical_records.php','Open records')?>'><span class="metric-icon success"><?=ui_icon('file')?></span><div><small>Medical records</small><strong><?=$records?></strong><p>Records created by you or available clinic records.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=vet_dashboard_detail('Vaccinations due soon','Clinical overview',$vaccineHtml,'vet/vaccinations.php','Open vaccinations')?>'><span class="metric-icon warning"><?=ui_icon('syringe')?></span><div><small>Vaccinations due soon</small><strong><?=$dueSoon?></strong><p>Follow-ups due within 14 days.</p></div></button></section>
<?php include '../includes/dashboard_schedule.php'; ?>
<div class="dashboard-main-grid dashboard-ui-equal"><section class="surface-card workflow-queue-card vet-clinical-queue-card"><div class="section-heading"><div><span class="eyebrow">Clinical queue</span><h2 class="vet-review-heading">Items requiring review</h2></div></div><?php $queueColumns=[array_slice($queue,0,3),array_slice($queue,3,2)]; ?><div class="workflow-queue-list"><?php foreach($queueColumns as $queueColumn):?><div class="workflow-queue-column"><?php foreach($queueColumn as [$label,$count,$note,$url,$icon,$color,$priority,$html]):?><a class="workflow-queue-item queue-<?=e($color)?> <?=$count > 0 ? 'queue-has-items' : 'queue-empty'?>" href="<?=app_url($url)?>" data-record-detail='<?=vet_dashboard_detail($label,'Clinical queue','<div class="clinical-queue-popup-list">'.$html.'</div>','','')?>'><span class="queue-icon"><?=ui_icon($icon)?></span><div><b><?=e($label)?></b><p><?=e($note)?></p></div><span class="queue-count-wrap"><strong class="queue-count"><?=intval($count)?></strong><?php if($count>0 && $priority<=2):?><span class="queue-urgency" aria-label="Needs attention">!</span><?php endif;?></span><span class="queue-arrow"><?=ui_icon('chevron-right')?></span></a><?php endforeach;?></div><?php endforeach;?></div></section><aside class="surface-card quick-link-card"><div class="section-heading compact"><div><span class="eyebrow">Clinical tools</span><h2>Quick access</h2></div></div><div class="quick-link-list"><a href="<?=app_url('vet/medical_records.php')?>"><?=ui_icon('file')?>Medical records<span>Review complete consultation history by pet</span></a><a href="<?=app_url('vet/pets.php')?>"><?=ui_icon('paw')?>Pet profiles<span>Open approved patient profiles</span></a><a href="<?=app_url('vet/pet_change_reviews.php')?>"><?=ui_icon('alert')?>Pet change reviews<span>Review profile changes needing consultation</span></a><a href="<?=app_url('vet/prescription.php')?>"><?=ui_icon('receipt')?>Prescription<span>Create or review clinic prescriptions</span></a></div></aside></div>
<div class="dashboard-two-tables"><section class="surface-card dashboard-table-card"><div class="section-heading"><div><span class="eyebrow">Upcoming cases</span><h2 class="vet-dashboard-table-heading">Appointments</h2></div><a href="<?=app_url('vet/appointments.php')?>">View all</a></div><div class="table-scroll-only"><table class="data-table"><thead><tr><th>Pet</th><th>Client</th><th>Date and time</th><th>Status</th></tr></thead><tbody><?php if(!$appointments->num_rows):?><tr><td colspan="4" class="empty-cell">No appointments found.</td></tr><?php endif;while($a=$appointments->fetch_assoc()):$rowDetail=['title'=>$a['pet_name'],'eyebrow'=>'Upcoming case','fields'=>['Pet'=>$a['pet_name'],'Animal type'=>$a['species'],'Client'=>$a['owner_name'],'Date and time'=>date('M d, Y h:i A',strtotime($a['scheduled_date']?:$a['requested_date'])),'Status'=>ucfirst($a['status'])]];?><tr data-record-detail='<?=e(json_encode($rowDetail))?>'><td><div class="table-identity"><?=pet_avatar_markup($a,'small')?><div><b><?=e($a['pet_name'])?></b><small><?=e($a['species'])?></small></div></div></td><td><?=e($a['owner_name'])?></td><td><?=date('M d, Y h:i A',strtotime($a['scheduled_date']?:$a['requested_date']))?></td><td><?=badge($a['status'])?></td></tr><?php endwhile;?></tbody></table></div></section><section class="surface-card dashboard-table-card"><div class="section-heading"><div><span class="eyebrow">Clinical history</span><h2 class="vet-dashboard-table-heading">Recent medical records</h2></div><a href="<?=app_url('vet/medical_records.php')?>">View all</a></div><div class="table-scroll-only"><table class="data-table"><thead><tr><th>Pet</th><th>Visit date</th><th>Diagnosis</th><th>Treatment</th></tr></thead><tbody><?php if(!$recordRows->num_rows):?><tr><td colspan="4" class="empty-cell">No medical records found.</td></tr><?php endif;while($r=$recordRows->fetch_assoc()):$rowDetail=['title'=>$r['pet_name'],'eyebrow'=>'Clinical history','fields'=>['Pet'=>$r['pet_name'],'Animal type'=>$r['species'],'Visit date'=>date('M d, Y',strtotime($r['visit_date'])),'Diagnosis'=>$r['diagnosis']?:'Not recorded','Treatment'=>$r['treatment']?:'Not recorded']];?><tr data-record-detail='<?=e(json_encode($rowDetail))?>'><td><div class="table-identity"><?=pet_avatar_markup($r,'small')?><div><b><?=e($r['pet_name'])?></b><small><?=e($r['species'])?></small></div></div></td><td><?=date('M d, Y',strtotime($r['visit_date']))?></td><td><?=e($r['diagnosis']?:'Not recorded')?></td><td><?=e($r['treatment']?:'Not recorded')?></td></tr><?php endwhile;?></tbody></table></div></section></div>
</main></div><?php include '../includes/footer.php';?>
