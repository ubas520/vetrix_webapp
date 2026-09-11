<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("staff");
$canViewPrivateClientData = true;
$return_to=trim((string)($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
if($return_to!=='' && (str_contains($return_to,'..') || !preg_match('#^staff/[A-Za-z0-9_/-]+\.php(?:\?[^#]*)?(?:#[A-Za-z0-9_-]+)?$#',$return_to))){
    $return_to='';
}

if(isset($_POST['update_status'])){
    verify_csrf_or_fail();
    $appointmentId=(int)($_POST['id'] ?? 0);
    $currentStmt=$conn->prepare("SELECT owner_id,status,scheduled_date,duration_minutes,assigned_vet_id,confirmation_code,admin_notes FROM appointments WHERE id=? LIMIT 1");
    $currentStmt->bind_param('i',$appointmentId);$currentStmt->execute();$current=$currentStmt->get_result()->fetch_assoc();
    if(!$current){ flash('error','Appointment not found.'); redirect_to('staff/appointments.php'); }
    $ownerId=(int)$current['owner_id'];
    $status=$_POST['status'] ?? 'pending';
    $allowedStatus=['pending','approved','completed','rejected','cancelled'];
    if(!in_array($status,$allowedStatus,true)) $status='pending';
    $assigned_vet_id = ($_POST['assigned_vet_id'] ?? '') !== '' ? (int)$_POST['assigned_vet_id'] : null;
    $scheduled_date = trim($_POST['scheduled_date'] ?? '');
    $scheduled_date = $scheduled_date !== '' ? date('Y-m-d H:i:s', strtotime($scheduled_date)) : null;
    $duration=(int)($_POST['duration_minutes'] ?? 30); $duration=max(15,min(240,$duration));
    $confirmationInput=trim($_POST['confirmation_code'] ?? '');
    $confirmation_code=$confirmationInput!==''?$confirmationInput:trim((string)($current['confirmation_code']??''));
    $note=trim((string)($_POST['admin_notes'] ?? ''));
    $currentSchedule=$current['scheduled_date']?date('Y-m-d H:i:s',strtotime($current['scheduled_date'])):null;
    $currentVet=$current['assigned_vet_id']!==null?(int)$current['assigned_vet_id']:null;
    $changed=(string)$current['status']!==$status
        || $currentSchedule!==$scheduled_date
        || (int)($current['duration_minutes']??30)!==$duration
        || $currentVet!==$assigned_vet_id
        || trim((string)($current['confirmation_code']??''))!==$confirmation_code
        || trim((string)($current['admin_notes']??''))!==$note;
    if(!$changed){
        flash('appointment_warning','No changes were made. Update at least one field before using Save & Notify.');
        $target='staff/appointments.php?appointment_id='.$appointmentId;
        if($return_to!=='') $target.='&return_to='.urlencode($return_to);
        redirect_to($target);
    }
    if ($confirmation_code === '') $confirmation_code = 'APT-' . str_pad((string)$appointmentId, 5, '0', STR_PAD_LEFT);
    if($status==='approved' && (!$assigned_vet_id || !$scheduled_date)){
        flash('error','Approved appointments require a schedule and assigned veterinarian.');
    } else {
        $conflict=appointment_slot_conflict($conn,$appointmentId,$assigned_vet_id,$scheduled_date,$duration);
        if($status==='approved' && $conflict['conflict']){
            flash('error',$conflict['message']);
        } else {
            $stmt=$conn->prepare("UPDATE appointments SET status=?, scheduled_date=?, duration_minutes=?, assigned_vet_id=?, confirmation_code=?, admin_notes=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("ssiissi",$status,$scheduled_date,$duration,$assigned_vet_id,$confirmation_code,$note,$appointmentId);
            $stmt->execute();
            notify_user($conn,$ownerId,'Appointment Update','Your appointment status is now '.$status.'.','appointment',null);
            log_action($conn,'Staff updated appointment','appointment',$appointmentId,'Status changed to '.$status);
            flash('success','Appointment updated and the client was notified.');
        }
    }
    if($return_to!=='') redirect_to($return_to);
    redirect_to('staff/appointments.php');
}

$status_filter=$_GET['status'] ?? 'all';
$allowed=['all','pending','approved','completed','rejected','cancelled'];
if(!in_array($status_filter,$allowed,true)) $status_filter='all';
$appointment_focus_id=max(0,(int)($_GET['appointment_id'] ?? 0));
$appointment_search=trim($_GET['q'] ?? '');
[$page,$perPage,$offset]=pagination_values(4,20,[4,8,12,16,'full']);
$conditions=[];
if($status_filter!=='all') $conditions[]="a.status='".$conn->real_escape_string($status_filter)."'";
if($appointment_focus_id) $conditions[]='a.id='.$appointment_focus_id;
if($appointment_search!==''){
    $safe=$conn->real_escape_string($appointment_search);
    $conditions[]="(p.name LIKE '%$safe%' OR u.full_name LIKE '%$safe%' OR u.phone LIKE '%$safe%' OR u.email LIKE '%$safe%' OR a.reason LIKE '%$safe%' OR a.confirmation_code LIKE '%$safe%' OR v.full_name LIKE '%$safe%')";
}
$where=$conditions?'WHERE '.implode(' AND ',$conditions):'';
$appointment_search_param=$appointment_search!==''?'&q='.urlencode($appointment_search):'';
$appointment_all_search_param=$appointment_search!==''?'?q='.urlencode($appointment_search):'';

$title="Appointments";
include "../includes/header.php";
include "../includes/navbar.php";
$counts=[];
$totalAppointments=(int)$conn->query("SELECT COUNT(*) c FROM appointments")->fetch_assoc()['c'];
foreach(['pending','approved','completed','rejected','cancelled'] as $statusKey){
    $counts[$statusKey]=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE status='$statusKey'")->fetch_assoc()['c'];
}
$totalRows=(int)$conn->query("SELECT COUNT(*) c FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id $where")->fetch_assoc()['c'];
$staffPrivateFields=$canViewPrivateClientData?'u.phone,u.email,u.address':'NULL AS phone,NULL AS email,NULL AS address';
$rows=$conn->query("SELECT a.*,u.full_name,$staffPrivateFields,p.name pet_name,p.species,p.breed,p.pet_photo,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id $where ORDER BY FIELD(a.status,'pending','approved','completed','rejected','cancelled'), COALESCE(a.scheduled_date,a.requested_date) ASC LIMIT $perPage OFFSET $offset");
$appointmentNoChangeWarning=flash('appointment_warning');
?>
<div class="layout"><?php include "../includes/staff_sidebar.php"; ?><main class="content staff-appointments-page" id="mainContent">
<header class="page-heading appointment-page-heading"><div><span class="eyebrow">Clinic scheduling</span><h1>Appointments</h1><p>Manage and review clinic visit requests.</p></div><a class="button-secondary" href="<?=app_url('staff/calendar.php')?>"><?=ui_icon('calendar')?>Open calendar</a></header>
<?php if($m=flash('success')):?><div class="alert alert-success friendly-alert" role="status"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>



<section class="appt-monitor">
    <div class="appt-monitor-head"><div><h2>Appointments and Scheduling</h2></div></div>
    <form class="admin-list-search unified-search-row" method="GET" action="appointments.php">
        <?php if($status_filter !== 'all'): ?><input type="hidden" name="status" value="<?=e($status_filter)?>"><?php endif; ?>
        <input class="form-control" type="search" name="q" value="<?=e($appointment_search)?>" placeholder="Search pet, owner, phone, concern, veterinarian, or code">
        <button class="btn btn-primary" type="submit">Search</button>
        <a class="btn btn-light" href="appointments.php<?= $status_filter !== 'all' ? '?status='.urlencode($status_filter) : '' ?>">Clear</a>
    </form>
<div class="appt-filter-toolbar"><div class="appt-filter-row">
        <a class="appt-filter status-all <?=$status_filter==='all'?'active':''?>" href="appointments.php<?=$appointment_all_search_param?>">All <b><?=$totalAppointments?></b></a>
        <a class="appt-filter status-pending <?=$status_filter==='pending'?'active':''?>" href="appointments.php?status=pending<?=$appointment_search_param?>">Pending <b><?=$counts['pending']?></b></a>
        <a class="appt-filter status-approved <?=$status_filter==='approved'?'active':''?>" href="appointments.php?status=approved<?=$appointment_search_param?>">Approved <b><?=$counts['approved']?></b></a>
        <a class="appt-filter status-completed <?=$status_filter==='completed'?'active':''?>" href="appointments.php?status=completed<?=$appointment_search_param?>">Completed <b><?=$counts['completed']?></b></a>
        <a class="appt-filter status-rejected <?=$status_filter==='rejected'?'active':''?>" href="appointments.php?status=rejected<?=$appointment_search_param?>">Rejected <b><?=$counts['rejected']?></b></a>
        <a class="appt-filter status-cancelled <?=$status_filter==='cancelled'?'active':''?>" href="appointments.php?status=cancelled<?=$appointment_search_param?>">Cancelled <b><?=$counts['cancelled']?></b></a>
    </div><form class="appt-category-controls" method="GET" action="appointments.php"><?php if($status_filter !== 'all'): ?><input type="hidden" name="status" value="<?=e($status_filter)?>"><?php endif; ?><input type="hidden" name="q" value="<?=e($appointment_search)?>"><label class="entries-select">Show<select class="unified-show-select" aria-label="Appointments per page" name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[4,8,12,16,'full'])?></select></label><div class="view-toggle" data-view-toggle data-target="#appointmentView" data-key="staff-appointments-unified" data-default="list"><button type="button" data-view="list" aria-label="List view"><?=ui_icon('list')?></button><button type="button" data-view="grid" aria-label="Grid view"><?=ui_icon('grid')?></button></div></form></div>
    <?php if($rows->num_rows===0): ?>
        <div class="appt-empty"><h3>No appointments found</h3><p>Try another status filter or search term.</p></div>
    <?php else: ?>
    <div class="appt-list view-list compact-appointment-list" id="appointmentView">
        <div class="appointment-list-heading" aria-hidden="true"><span>Pet</span><span>Owner</span><span>Scheduled time</span><span>Veterinarian</span><span>Status</span><span>Action</span></div>
    <?php while($r=$rows->fetch_assoc()): $schedule=$r['scheduled_date']?:$r['requested_date']; $modalId='staffApptDetails'.(int)$r['id']; $overview=['title'=>$r['pet_name'].' Appointment','eyebrow'=>'Appointment overview','fields'=>['Status'=>ucwords(str_replace('_',' ',$r['status'])),'Pet'=>$r['pet_name'].' · '.$r['species'].' · '.$r['breed'],'Owner'=>$r['full_name'],'Phone'=>$r['phone']?:'No phone saved','Email'=>$r['email']?:'No email saved','Owner address'=>$r['address']?:'No address saved','Requested date'=>date('M d, Y h:i A',strtotime($r['requested_date'])),'Clinic schedule'=>date('M d, Y h:i A',strtotime($schedule)),'Assigned veterinarian'=>$r['vet_name']?:'Not assigned','Confirmation code'=>$r['confirmation_code']?:'Not generated','Reason / Concern'=>$r['reason']?:'No reason provided.','Clinic note'=>$r['admin_notes']?:'No clinic note yet.']]; ?>
        <?php include '../includes/appointment_row.php'; ?>
        <div class="appt-modal" id="<?=$modalId?>" aria-hidden="true"><div class="appt-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?=$modalId?>Title"><div class="appt-modal-head"><div><h3 id="<?=$modalId?>Title"><?=e($r['pet_name'])?> Appointment</h3><p>Full appointment details and scheduling update panel.</p></div><button type="button" class="appt-close" onclick="closeStaffApptModal('<?=$modalId?>')" aria-label="Close appointment details"><?=ui_icon('x')?></button></div><div class="appt-modal-body"><section><?php include '../includes/appointment_details.php'; ?>
</section><form method="POST" class="appt-update" data-appointment-update-form><?=csrf_field()?><?php if($appointmentNoChangeWarning && $appointment_focus_id===(int)$r['id']):?><div class="appt-inline-warning" role="alert"><?=e($appointmentNoChangeWarning)?></div><?php else:?><div class="appt-inline-warning" role="alert" hidden>No changes were made. Update at least one field before using Save & Notify.</div><?php endif;?><?php if($return_to!==''):?><input type="hidden" name="return_to" value="<?=e($return_to)?>"><?php endif;?><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="owner_id" value="<?=$r['owner_id']?>"><div class="appt-note">Update the appointment here. The client will receive a notification after saving.</div><div><label class="form-label">Status</label><select class="form-select" name="status"><option value="pending" <?=$r['status']==='pending'?'selected':''?>>Pending - waiting</option><option value="approved" <?=$r['status']==='approved'?'selected':''?>>Approved - confirmed</option><option value="completed" <?=$r['status']==='completed'?'selected':''?>>Completed - visit done</option><option value="rejected" <?=$r['status']==='rejected'?'selected':''?>>Rejected - declined</option><option value="cancelled" <?=$r['status']==='cancelled'?'selected':''?>>Cancelled</option></select></div><div><label class="form-label">Clinic schedule</label><input class="form-control" type="datetime-local" name="scheduled_date" value="<?= $r['scheduled_date'] ? date('Y-m-d\TH:i',strtotime($r['scheduled_date'])) : '' ?>"></div><div><label class="form-label">Assign veterinarian</label><select class="form-select" name="assigned_vet_id"><option value="">Not assigned</option><?php $vets=$conn->query("SELECT id,full_name FROM users WHERE role='veterinarian' AND status='active' ORDER BY full_name");while($v=$vets->fetch_assoc()):?><option value="<?=$v['id']?>" <?=((int)$r['assigned_vet_id']===(int)$v['id'])?'selected':''?>><?=e($v['full_name'])?></option><?php endwhile;?></select></div><div><label class="form-label">Duration</label><select class="form-select" name="duration_minutes"><?php foreach([30,45,60] as $minutes):?><option value="<?=$minutes?>" <?=((int)($r['duration_minutes']??30)===$minutes)?'selected':''?>><?=$minutes?> minutes</option><?php endforeach;?></select></div><div><label class="form-label">Confirmation code</label><input class="form-control" name="confirmation_code" placeholder="Auto-generated if blank" value="<?=e($r['confirmation_code'])?>"></div><div><label class="form-label">Message / notes for client</label><textarea class="form-control" name="admin_notes" placeholder="Example: Please arrive 10 minutes early."><?=e($r['admin_notes'])?></textarea></div><button class="btn btn-primary" name="update_status">Save & Notify</button></form></div></div></div>
    <?php endwhile;?>
    </div>
    <?=render_pagination($page,$perPage,$totalRows,['status'=>$status_filter,'q'=>$appointment_search,'per_page'=>per_page_value($perPage),'appointment_id'=>$appointment_focus_id?:null,'_anchor'=>'appointmentView'])?>
    <?php endif; ?>
</section>
<script>
const staffAppointmentReturnTo=<?=json_encode($return_to!==''?app_url($return_to):'')?>;
function openStaffApptModal(id){const modal=document.getElementById(id);if(!modal)return;modal.classList.add('show');modal.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open');}
function closeStaffApptModal(id){const modal=document.getElementById(id);if(!modal)return;modal.classList.remove('show');modal.setAttribute('aria-hidden','true');document.body.classList.remove('overlay-open');<?php if($appointment_focus_id):?>if(staffAppointmentReturnTo)location.href=staffAppointmentReturnTo;<?php endif;?>}
document.addEventListener('click',e=>{if(e.target.classList?.contains('appt-modal'))closeStaffApptModal(e.target.id)});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.appt-modal.show').forEach(m=>closeStaffApptModal(m.id))});
document.querySelectorAll('[data-appointment-update-form]').forEach(form=>{
    const fields=['status','scheduled_date','assigned_vet_id','duration_minutes','confirmation_code','admin_notes'];
    const snapshot=()=>fields.map(name=>`${name}=${form.elements[name]?.value??''}`).join('&');
    const initial=snapshot();
    const warning=form.querySelector('.appt-inline-warning');
    form.addEventListener('submit',event=>{if(snapshot()!==initial)return;event.preventDefault();if(warning){warning.hidden=false;warning.scrollIntoView({block:'nearest'});}});
    const clearWarning=()=>{if(warning&&snapshot()!==initial)warning.hidden=true;};
    form.addEventListener('input',clearWarning);form.addEventListener('change',clearWarning);
});
<?php if($appointment_focus_id):?>document.addEventListener('DOMContentLoaded',()=>openStaffApptModal('staffApptDetails<?=$appointment_focus_id?>'));<?php endif;?>
</script>
</main></div><?php include "../includes/footer.php"; ?>
