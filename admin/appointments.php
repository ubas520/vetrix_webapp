<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("admin");

$return_to = trim($_REQUEST['return_to'] ?? '');
if ($return_to !== '' && (!preg_match('#^(admin|staff|vet|client)/[A-Za-z0-9_./?=&%-]+$#', $return_to) || str_contains($return_to, '..'))) $return_to = '';

if(isset($_POST['update_status'])){
    verify_csrf_or_fail();
    $appointmentId=(int)($_POST['id'] ?? 0);
    $currentStmt=$conn->prepare("SELECT owner_id,status,scheduled_date,duration_minutes,assigned_vet_id,confirmation_code,admin_notes FROM appointments WHERE id=? LIMIT 1");
    $currentStmt->bind_param('i',$appointmentId);$currentStmt->execute();$current=$currentStmt->get_result()->fetch_assoc();
    if(!$current){ flash('error','Appointment not found.'); redirect_to('admin/appointments.php'); }
    $ownerId=(int)$current['owner_id'];
    $status=$_POST['status'] ?? 'pending';
    $allowedStatus=['pending','approved','completed','rejected','cancelled'];
    if(!in_array($status,$allowedStatus,true)) $status='pending';
    $assigned_vet_id = ($_POST['assigned_vet_id'] ?? '') !== '' ? (int)$_POST['assigned_vet_id'] : null;
    $scheduledInput=trim($_POST['scheduled_date'] ?? '');
    $scheduled_date=$scheduledInput!=='' ? date('Y-m-d H:i:s',strtotime($scheduledInput)) : null;
    $duration=(int)($_POST['duration_minutes'] ?? 30);$duration=max(15,min(240,$duration));
    $confirmationInput=trim($_POST['confirmation_code'] ?? '');
    $confirmation_code=$confirmationInput!==''?$confirmationInput:trim((string)($current['confirmation_code']??''));
    $adminNotes=trim((string)($_POST['admin_notes'] ?? ''));
    $currentSchedule=$current['scheduled_date']?date('Y-m-d H:i:s',strtotime($current['scheduled_date'])):null;
    $currentVet=$current['assigned_vet_id']!==null?(int)$current['assigned_vet_id']:null;
    $changed=(string)$current['status']!==$status
        || $currentSchedule!==$scheduled_date
        || (int)($current['duration_minutes']??30)!==$duration
        || $currentVet!==$assigned_vet_id
        || trim((string)($current['confirmation_code']??''))!==$confirmation_code
        || trim((string)($current['admin_notes']??''))!==$adminNotes;
    if(!$changed){
        flash('appointment_warning','No changes were made. Update at least one field before using Save & Notify.');
        $target='admin/appointments.php?appointment_id='.$appointmentId;
        if($return_to!=='') $target.='&return_to='.urlencode($return_to);
        redirect_to($target);
    }
    if($confirmation_code==='') $confirmation_code='APT-'.str_pad((string)$appointmentId,5,'0',STR_PAD_LEFT);
    if($status==='approved' && (!$assigned_vet_id || !$scheduled_date)){
        flash('error','Approved appointments require a schedule and assigned veterinarian.');
    }else{
        $conflict=appointment_slot_conflict($conn,$appointmentId,$assigned_vet_id,$scheduled_date,$duration);
        if($status==='approved' && $conflict['conflict']){
            flash('error',$conflict['message']);
        }else{
            $stmt=$conn->prepare("UPDATE appointments SET status=?, scheduled_date=?, duration_minutes=?, assigned_vet_id=?, confirmation_code=?, admin_notes=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("ssiissi",$status,$scheduled_date,$duration,$assigned_vet_id,$confirmation_code,$adminNotes,$appointmentId);
            $stmt->execute();
            notify_user($conn,$ownerId,'Appointment Update','Your appointment status is now '.$status.'.','appointment',null);
            log_action($conn,'Admin updated appointment','appointment',$appointmentId,'Status changed to '.$status);
            flash('success','Appointment updated and the client was notified.');
        }
    }
    redirect_to($return_to !== '' ? $return_to : 'admin/appointments.php?appointment_id='.$appointmentId);
}

$status_filter=$_GET['status'] ?? 'all';
$appointment_search = trim($_GET['q'] ?? '');
$appointment_focus_id = max(0, (int)($_GET['appointment_id'] ?? ($_GET['focus'] ?? 0)));
$allowed=['all','pending','approved','completed','rejected','cancelled'];
if(!in_array($status_filter,$allowed,true)) $status_filter='all';
$appointment_conditions = [];
if ($appointment_focus_id) $appointment_conditions[] = 'a.id=' . $appointment_focus_id;
if($status_filter !== 'all') {
    $appointment_conditions[] = "a.status='".$conn->real_escape_string($status_filter)."'";
}
if($appointment_search !== '') {
    $safeAppointmentSearch = $conn->real_escape_string($appointment_search);
    $appointment_conditions[] = "(p.name LIKE '%$safeAppointmentSearch%' OR p.species LIKE '%$safeAppointmentSearch%' OR u.full_name LIKE '%$safeAppointmentSearch%' OR u.email LIKE '%$safeAppointmentSearch%' OR u.phone LIKE '%$safeAppointmentSearch%' OR u.address LIKE '%$safeAppointmentSearch%' OR a.reason LIKE '%$safeAppointmentSearch%' OR a.admin_notes LIKE '%$safeAppointmentSearch%' OR a.confirmation_code LIKE '%$safeAppointmentSearch%' OR v.full_name LIKE '%$safeAppointmentSearch%')";
}
$where = $appointment_conditions ? 'WHERE '.implode(' AND ', $appointment_conditions) : '';
$appointment_search_param = $appointment_search !== '' ? '&q='.urlencode($appointment_search) : '';
$appointment_all_search_param = $appointment_search !== '' ? '?q='.urlencode($appointment_search) : '';

$title="Appointments";
include "../includes/header.php";
include "../includes/navbar.php";
$counts=[];
$totalAppointments = (int)$conn->query("SELECT COUNT(*) c FROM appointments")->fetch_assoc()['c'];
foreach(['pending','approved','completed','rejected','cancelled'] as $s){
    $counts[$s]=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE status='$s'")->fetch_assoc()['c'];
}
[$page,$perPage,$offset]=pagination_values(4,20,[4,8,12,16,'full']);
$totalFilteredAppointments=(int)$conn->query("SELECT COUNT(*) c FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id $where")->fetch_assoc()['c'];
$rows=$conn->query("SELECT a.*,u.full_name,u.phone,u.email,u.address,p.name pet_name,p.species,p.breed,p.pet_photo,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id $where ORDER BY FIELD(a.status,'pending','approved','completed','rejected','cancelled'), COALESCE(a.scheduled_date,a.requested_date) ASC LIMIT $perPage OFFSET $offset");
$appointmentNoChangeWarning=flash('appointment_warning');
?>
<div class="layout"><?php include "../includes/admin_sidebar.php"; ?><main class="content admin-appointments-page" id="mainContent">
<header class="page-heading appointment-page-heading">
    <div><span class="eyebrow">Clinic scheduling</span><h1>Appointments</h1><p>Manage and review clinic visit requests.</p></div>
    <a class="button-secondary" href="<?=app_url('admin/calendar.php')?>"><?=ui_icon('calendar')?>Open calendar</a>
</header>
<?php if($m=flash('success')):?><div class="alert alert-success friendly-alert" role="status"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>



<div class="appt-monitor">
    <div class="appt-monitor-head">
        <div>
            <h2>Appointments and Scheduling</h2>
            
        </div>
    </div>

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
    </div><form class="appt-category-controls" method="GET" action="appointments.php"><?php if($status_filter !== 'all'): ?><input type="hidden" name="status" value="<?=e($status_filter)?>"><?php endif; ?><input type="hidden" name="q" value="<?=e($appointment_search)?>"><label class="entries-select">Show<select class="unified-show-select" aria-label="Appointments per page" name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[4,8,12,16,'full'])?></select></label><div class="view-toggle" data-view-toggle data-target="#appointmentView" data-key="admin-appointments-unified" data-default="list"><button type="button" data-view="list" aria-label="List view"><?=ui_icon('list')?></button><button type="button" data-view="grid" aria-label="Grid view"><?=ui_icon('grid')?></button></div></form></div>

    <?php if($rows->num_rows===0): ?>
        <div class="appt-empty"><h3>No appointments found</h3><p>Try another status filter or search term.</p></div>
    <?php else: ?>
    <div class="appt-list view-list compact-appointment-list" id="appointmentView">
        <div class="appointment-list-heading" aria-hidden="true"><span>Pet</span><span>Owner</span><span>Scheduled time</span><span>Veterinarian</span><span>Status</span><span>Action</span></div>
        <?php while($r=$rows->fetch_assoc()): ?>
            <?php $modalId='apptDetails'.(int)$r['id']; ?>
            <?php include '../includes/appointment_row.php'; ?>

            <div class="appt-modal" id="<?=$modalId?>" aria-hidden="true">
                <div class="appt-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?=$modalId?>Title">
                    <div class="appt-modal-head">
                        <div>
                            <h3 id="<?=$modalId?>Title"><?=e($r['pet_name'])?> Appointment</h3>
                            <p>Full appointment details and scheduling update panel.</p>
                        </div>
                        <button type="button" class="appt-close" onclick="closeApptModal('<?=$modalId?>')" aria-label="Close appointment details"><?=ui_icon('x')?></button>
                    </div>
                    <div class="appt-modal-body">
                        <section>
                            <?php include '../includes/appointment_details.php'; ?>
</section>

                        <form method="POST" class="appt-update" data-appointment-update-form>
                            <?= csrf_field() ?>
                            <?php if($appointmentNoChangeWarning && $appointment_focus_id===(int)$r['id']):?><div class="appt-inline-warning" role="alert"><?=e($appointmentNoChangeWarning)?></div><?php else:?><div class="appt-inline-warning" role="alert" hidden>No changes were made. Update at least one field before using Save & Notify.</div><?php endif;?>
                            <input type="hidden" name="id" value="<?=$r['id']?>">
                            <input type="hidden" name="owner_id" value="<?=$r['owner_id']?>">
                            <?php if($return_to!==''):?><input type="hidden" name="return_to" value="<?=e($return_to)?>"><?php endif;?>
                            <div class="appt-note">Update the appointment here. The client will receive a notification after saving.</div><a class="button-secondary" href="<?=app_url('admin/pets.php?pet_id='.(int)$r['pet_id'])?>"><?=ui_icon('edit')?>Edit pet details</a>
                            <div>
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="pending" <?=$r['status']=='pending'?'selected':''?>>Pending - waiting</option>
                                    <option value="approved" <?=$r['status']=='approved'?'selected':''?>>Approved - confirmed</option>
                                    <option value="completed" <?=$r['status']=='completed'?'selected':''?>>Completed - visit done</option>
                                    <option value="rejected" <?=$r['status']=='rejected'?'selected':''?>>Rejected - declined</option>
                                    <option value="cancelled" <?=$r['status']=='cancelled'?'selected':''?>>Cancelled</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Clinic schedule</label>
                                <input class="form-control" type="datetime-local" name="scheduled_date" value="<?= $r['scheduled_date'] ? date('Y-m-d\TH:i', strtotime($r['scheduled_date'])) : '' ?>">
                            </div>
                            <div>
                                <label class="form-label">Assign veterinarian</label>
                                <select class="form-select" name="assigned_vet_id">
                                    <option value="">Not assigned</option>
                                    <?php $vets=$conn->query("SELECT id,full_name FROM users WHERE role='veterinarian' AND status='active' ORDER BY full_name"); while($v=$vets->fetch_assoc()): ?>
                                        <option value="<?=$v['id']?>" <?=$r['assigned_vet_id']==$v['id']?'selected':''?>><?=e($v['full_name'])?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
<div><label class="form-label">Duration</label><select class="form-select" name="duration_minutes"><option value="30" <?=((int)($r['duration_minutes'] ?? 30)===30)?'selected':''?>>30 minutes</option><option value="45" <?=((int)($r['duration_minutes'] ?? 30)===45)?'selected':''?>>45 minutes</option><option value="60" <?=((int)($r['duration_minutes'] ?? 30)===60)?'selected':''?>>60 minutes</option></select></div>
                            <div>
                                <label class="form-label">Confirmation code</label>
                                <input class="form-control" name="confirmation_code" placeholder="Auto-generated if blank" value="<?=e($r['confirmation_code'])?>">
                            </div>
                            <div>
                                <label class="form-label">Message / notes for client</label>
                                <textarea class="form-control" name="admin_notes" placeholder="Example: Please arrive 10 minutes early."><?=e($r['admin_notes'])?></textarea>
                            </div>
                            <button class="btn btn-primary" name="update_status">Save & Notify</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    <?=render_pagination($page,$perPage,$totalFilteredAppointments,['status'=>$status_filter,'q'=>$appointment_search,'per_page'=>per_page_value($perPage)])?>
    <?php endif; ?>
</div>

<script>
const appointmentReturnTo=<?=json_encode($return_to !== '' ? app_url($return_to) : '')?>;
function openApptModal(id){
    const modal=document.getElementById(id);
    if(!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
}
function closeApptModal(id){
    const modal=document.getElementById(id);
    if(!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow='';
    if(appointmentReturnTo && id==='apptDetails<?= (int)$appointment_focus_id ?>') window.location.href=appointmentReturnTo;
}
document.addEventListener('click',function(e){
    if(e.target.classList && e.target.classList.contains('appt-modal')) closeApptModal(e.target.id);
});
document.addEventListener('keydown',function(e){
    if(e.key==='Escape') document.querySelectorAll('.appt-modal.show').forEach(m=>closeApptModal(m.id));
});

document.querySelectorAll('[data-appointment-update-form]').forEach(form=>{
    const fields=['status','scheduled_date','assigned_vet_id','duration_minutes','confirmation_code','admin_notes'];
    const snapshot=()=>fields.map(name=>`${name}=${form.elements[name]?.value??''}`).join('&');
    const initial=snapshot();
    form.addEventListener('submit',event=>{
        if(snapshot()!==initial) return;
        event.preventDefault();
        const warning=form.querySelector('.appt-inline-warning');
        if(warning){warning.hidden=false;warning.scrollIntoView({block:'nearest'});}
    });
    form.addEventListener('input',()=>{const warning=form.querySelector('.appt-inline-warning');if(warning&&snapshot()!==initial)warning.hidden=true;});
    form.addEventListener('change',()=>{const warning=form.querySelector('.appt-inline-warning');if(warning&&snapshot()!==initial)warning.hidden=true;});
});
<?php if($appointment_focus_id):?>document.addEventListener('DOMContentLoaded',()=>openApptModal('apptDetails<?=$appointment_focus_id?>'));<?php endif;?>
</script>
</main></div><?php include "../includes/footer.php"; ?>
