<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("admin");

$return_to = trim($_REQUEST['return_to'] ?? '');
if ($return_to !== '' && (!preg_match('~^(admin|staff|vet|client)/[A-Za-z0-9_./?=&%#-]+$~', $return_to) || str_contains($return_to, '..'))) $return_to = '';

require_once '../includes/admin_appointment_update.php';
if(isset($_POST['update_status'])){
    verify_csrf_or_fail();
    $appointmentId=max(0,(int)($_POST['id'] ?? 0));
    $target='admin/appointments.php?appointment_id='.$appointmentId;
    if($return_to!=='') $target.='&return_to='.urlencode($return_to);
    $draft=[];
    foreach(['status','assigned_vet_id','scheduled_date','duration_minutes','confirmation_code','admin_notes'] as $field) {
        $draft[$field]=is_scalar($_POST[$field]??'') ? (string)($_POST[$field]??'') : '';
    }
    try {
        $saved=admin_save_appointment($conn,$appointmentId,$_POST);
    } catch (Throwable $error) {
        $_SESSION['admin_appointment_draft']=['id'=>$appointmentId,'values'=>$draft];
        $message=$error instanceof InvalidArgumentException ? $error->getMessage() : 'The appointment could not be saved. Your entries have been kept; please try again.';
        if(!($error instanceof InvalidArgumentException)) error_log('Vetrix admin appointment save failed: '.$error->getMessage());
        flash('error',$message);
        redirect_to($target);
    }
    unset($_SESSION['admin_appointment_draft']);
    if(!$saved['changed']){
        flash('appointment_warning','No changes were made. Update at least one field before using Save & Notify.');
        redirect_to($target);
    }
    // A notification failure must not misrepresent or undo a committed appointment.
    $notified=false;
    try { $notified=(bool)notify_user($conn,$saved['owner_id'],'Appointment Update','Your appointment status is now '.$saved['status'].'.','appointment',null); }
    catch(Throwable $error){ error_log('Vetrix appointment notification failed: '.$error->getMessage()); }
    try { log_action($conn,'Admin updated appointment','appointment',$appointmentId,'Status changed to '.$saved['status']); }
    catch(Throwable $error){ error_log('Vetrix appointment audit failed: '.$error->getMessage()); }
    flash('success','Appointment saved.');
    if(!$notified){
        flash('appointment_warning','The appointment was saved, but its client notification could not be confirmed. Check Notifications before trying to notify the client again.');
        redirect_to($target);
    }
    flash('success','Appointment saved and an in-system notification recorded for the client.');
    redirect_to($return_to !== '' ? $return_to : $target);
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
$appointmentError=flash('error');
$appointmentDraft=$_SESSION['admin_appointment_draft'] ?? null;
unset($_SESSION['admin_appointment_draft']);
?>
<div class="layout"><?php include "../includes/admin_sidebar.php"; ?><main class="content admin-appointments-page" id="mainContent">
<header class="page-heading appointment-page-heading">
    <div><span class="eyebrow">Clinic scheduling</span><h1>Appointments</h1><p>Manage and review clinic visit requests.</p></div>
    <a class="button-secondary" href="<?=app_url('admin/calendar.php')?>"><?=ui_icon('calendar')?>Open calendar</a>
</header>
<?php if($m=flash('success')):?><div class="alert alert-success friendly-alert" role="status"><?=e($m)?></div><?php endif;?>
<?php if($appointmentError):?><div class="alert alert-danger" role="alert"><?=e($appointmentError)?></div><?php endif;?>



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
        <div class="appt-empty"><h3>No appointments found</h3><p>No <?=e($status_filter==='all'?'':$status_filter.' ')?>appointments match the current filters. <a href="appointments.php">Clear filters</a></p></div>
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
                            <div class="toolbar-actions" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px">
                                <a class="button-secondary" href="<?=app_url('vet/medical_records.php?pet_id='.(int)$r['pet_id'])?>">View medical history</a>
                                <?php if($r['status']==='completed'): ?>
                                <a class="button-primary" href="<?=app_url('admin/create_record_from_appointment.php?id='.(int)$r['id'])?>">Add consultation</a>
                                <?php endif; ?>
                            </div>
                            <?php if($r['status']!=='completed'): ?><p class="field-help">Mark this appointment completed to add its consultation.</p><?php endif; ?>
</section>

                        <form method="POST" class="appt-update" data-appointment-update-form>
                            <?= csrf_field() ?>
                            <input type="hidden" name="appointment_version" value="<?=e(admin_appointment_version($r))?>">
                            <?php if($appointmentError && $appointment_focus_id===(int)$r['id']):?><div class="alert alert-danger" role="alert"><?=e($appointmentError)?></div><?php endif;?>
                            <?php $edit=$r; $hasDraft=$appointmentDraft && (int)$appointmentDraft['id']===(int)$r['id']; if($hasDraft) $edit=array_replace($r,$appointmentDraft['values']); ?>
                            <?php if($appointmentNoChangeWarning && $appointment_focus_id===(int)$r['id']):?><div class="appt-inline-warning" role="alert"><?=e($appointmentNoChangeWarning)?></div><?php else:?><div class="appt-inline-warning" role="alert" hidden>No changes were made. Update at least one field before using Save & Notify.</div><?php endif;?>
                            <input type="hidden" name="id" value="<?=$r['id']?>">
                            <input type="hidden" name="owner_id" value="<?=$r['owner_id']?>">
                            <?php if($return_to!==''):?><input type="hidden" name="return_to" value="<?=e($return_to)?>"><?php endif;?>
                            <div class="appt-note">Update the appointment here. The client will receive a notification after saving.</div><a class="button-secondary" href="<?=app_url('admin/pets.php?pet_id='.(int)$r['pet_id'])?>"><?=ui_icon('edit')?>Edit pet details</a>
                            <div>
                                <label class="form-label" for="<?=$modalId?>Status">Status</label>
                                <select class="form-select" name="status" id="<?=$modalId?>Status">
                                    <option value="pending" <?=$edit['status']=='pending'?'selected':''?>>Pending - waiting</option>
                                    <option value="approved" <?=$edit['status']=='approved'?'selected':''?>>Approved - confirmed</option>
                                    <option value="completed" <?=$edit['status']=='completed'?'selected':''?>>Completed - visit done</option>
                                    <option value="rejected" <?=$edit['status']=='rejected'?'selected':''?>>Rejected - declined</option>
                                    <option value="cancelled" <?=$edit['status']=='cancelled'?'selected':''?>>Cancelled</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="<?=$modalId?>Schedule">Clinic schedule</label>
                                <input class="form-control" type="datetime-local" name="scheduled_date" id="<?=$modalId?>Schedule" value="<?=e($hasDraft ? $edit['scheduled_date'] : ($r['scheduled_date'] ? date('Y-m-d\TH:i', strtotime($r['scheduled_date'])) : ''))?>">
                            </div>
                            <div>
                                <label class="form-label" for="<?=$modalId?>Vet">Assign veterinarian</label>
                                <select class="form-select" name="assigned_vet_id" id="<?=$modalId?>Vet">
                                    <option value="">Not assigned</option>
                                    <?php $vets=$conn->query("SELECT id,full_name FROM users WHERE role='veterinarian' AND status='active' AND deleted_at IS NULL ORDER BY full_name"); while($v=$vets->fetch_assoc()): ?>
                                        <option value="<?=$v['id']?>" <?=$edit['assigned_vet_id']==$v['id']?'selected':''?>><?=e($v['full_name'])?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
<div><label class="form-label" for="<?=$modalId?>Duration">Duration (minutes)</label><input class="form-control" type="number" name="duration_minutes" id="<?=$modalId?>Duration" min="15" max="240" step="1" required value="<?=e($edit['duration_minutes'] ?? 30)?>"></div>
                            <div>
                                <label class="form-label" for="<?=$modalId?>Code">Confirmation code</label>
                                <input class="form-control" name="confirmation_code" id="<?=$modalId?>Code" maxlength="40" placeholder="Auto-generated if blank" value="<?=e($edit['confirmation_code'])?>">
                            </div>
                            <div>
                                <label class="form-label" for="<?=$modalId?>Notes">Message / notes for client</label>
                                <textarea class="form-control" name="admin_notes" id="<?=$modalId?>Notes" placeholder="Example: Please arrive 10 minutes early."><?=e($edit['admin_notes'])?></textarea>
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
    const retrying=<?=json_encode((bool)$appointmentDraft)?>;
    form.addEventListener('submit',event=>{
        if(retrying || snapshot()!==initial) return;
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
