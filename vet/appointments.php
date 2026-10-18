<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");
$uid = (int)current_user_id();
$isAdmin = (int)(($_SESSION['role'] ?? '') === 'admin');
require_once '../includes/appointment_return.php';
$appointmentModalOverride='openVetApptModal';
$listReturn=clinical_appointment_return('vet/appointments.php?'.http_build_query($_GET));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    verify_csrf_or_fail();
    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $stmt = $conn->prepare("SELECT a.*, p.name AS pet_name FROM appointments a JOIN pets p ON a.pet_id=p.id WHERE a.id=? AND ($isAdmin=1 OR a.assigned_vet_id=? OR a.assigned_vet_id IS NULL) AND a.status='approved'");
    $stmt->bind_param("ii", $appointment_id, $uid);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    if ($appt) {
        $update = $conn->prepare("UPDATE appointments SET status='completed',updated_at=NOW() WHERE id=? AND status='approved' AND ($isAdmin=1 OR assigned_vet_id=$uid OR assigned_vet_id IS NULL)");
        $update->bind_param("i", $appointment_id);
        if(!$update->execute() || $update->affected_rows!==1){
            flash('error','The visit could not be completed or was changed by another user. Review it before trying again.');
            redirect_to($listReturn);
        }
        try { notify_user($conn, $appt['owner_id'], 'Appointment Completed', 'The appointment for ' . $appt['pet_name'] . ' has been marked completed by the clinic.', 'appointment', null); }
        catch(Throwable $error){ error_log('Vetrix completion notification failed: '.$error->getMessage()); }
        log_action($conn, 'Veterinarian marked appointment completed', 'appointment', $appointment_id, 'Appointment completed for ' . $appt['pet_name']);
        flash('success', 'Appointment marked as completed. Record the consultation for this visit.');
        redirect_to('vet/medical_records.php?action=add&appointment_id=' . $appointment_id . '&pet_id=' . (int)$appt['pet_id'].'&return_to='.rawurlencode($listReturn));
    }
    flash('error','This appointment is no longer approved or is unavailable to your account.');
    redirect_to($listReturn);
}

$status_filter=$_GET['status'] ?? 'all';
$appointment_search = trim($_GET['q'] ?? '');
$appointment_focus_id=max(0,(int)($_GET['appointment_id'] ?? 0));
[$page,$perPage,$offset]=pagination_values(4,20,[4,8,12,16,'full']);
$allowed=['all','pending','approved','completed','rejected','cancelled'];
if(!in_array($status_filter,$allowed,true)) $status_filter='all';
$whereStatus = $status_filter==='all' ? "" : " AND a.status='".$conn->real_escape_string($status_filter)."'";
if($appointment_focus_id) $whereStatus .= ' AND a.id='.$appointment_focus_id;
if ($appointment_search !== '') {
    $safeAppointmentSearch = $conn->real_escape_string($appointment_search);
    $whereStatus .= " AND (p.name LIKE '%$safeAppointmentSearch%' OR p.species LIKE '%$safeAppointmentSearch%' OR p.breed LIKE '%$safeAppointmentSearch%' OR u.full_name LIKE '%$safeAppointmentSearch%' OR u.email LIKE '%$safeAppointmentSearch%' OR u.phone LIKE '%$safeAppointmentSearch%' OR v.full_name LIKE '%$safeAppointmentSearch%' OR a.reason LIKE '%$safeAppointmentSearch%' OR a.confirmation_code LIKE '%$safeAppointmentSearch%' OR a.admin_notes LIKE '%$safeAppointmentSearch%')";
}
$appointment_search_param = $appointment_search !== '' ? '&q='.urlencode($appointment_search) : '';
$appointment_all_search_param = $appointment_search !== '' ? '?q='.urlencode($appointment_search) : '';

$title="Assigned Appointments";
include "../includes/header.php";
include "../includes/navbar.php";
$counts=[];
foreach(['pending','approved','completed','rejected','cancelled'] as $s){
    $counts[$s]=$conn->query("SELECT COUNT(*) c FROM appointments a WHERE ($isAdmin=1 OR a.assigned_vet_id=$uid OR a.assigned_vet_id IS NULL) AND a.status='$s'")->fetch_assoc()['c'];
}
$total=array_sum(array_map('intval',$counts));
$totalRows=(int)$conn->query("SELECT COUNT(*) c FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id WHERE ($isAdmin=1 OR a.assigned_vet_id=$uid OR a.assigned_vet_id IS NULL) AND a.status IN ('approved','pending','completed','rejected','cancelled') $whereStatus")->fetch_assoc()['c'];
$rows=$conn->query("SELECT a.*,u.full_name,u.phone,u.email,u.address,p.name pet_name,p.species,p.breed,p.pet_photo,p.birth_date,p.critical_notes,p.allergies,v.full_name vet_name,(SELECT MAX(m.id) FROM medical_records m WHERE m.appointment_id=a.id AND m.pet_id=a.pet_id) consultation_id FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id WHERE ($isAdmin=1 OR a.assigned_vet_id=$uid OR a.assigned_vet_id IS NULL) AND a.status IN ('approved','pending','completed','rejected','cancelled') $whereStatus ORDER BY (DATE(COALESCE(a.scheduled_date,a.requested_date))=CURDATE()) DESC, FIELD(a.status,'approved','pending','completed','rejected','cancelled'), COALESCE(a.scheduled_date,a.requested_date) ASC LIMIT $perPage OFFSET $offset");
?>
<div class="layout"><?php include "../includes/vet_sidebar.php"; ?>
<main class="content vet-appointments-page" id="mainContent">
<header class="page-heading appointment-page-heading"><div><span class="eyebrow">Clinic scheduling</span><h1>Appointments</h1><p>Today's visits appear first. Completed visits show whether a consultation record is saved.</p></div><a class="button-secondary" href="<?=app_url('vet/calendar.php')?>"><?=ui_icon('calendar')?>Open calendar</a></header>
<?php if($m=flash('success')):?><div class="alert alert-success friendly-alert"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger"><?=e($m)?></div><?php endif;?>

<section class="appt-monitor">
<div class="appt-monitor-head">
    <div>
        <h2>Appointments and Scheduling</h2>
        
    </div>
</div>
<form class="surface-card admin-record-finder" method="GET" action="appointments.php">
    <div class="admin-record-finder-heading">
        <div><span class="eyebrow">Record search</span><h2>Browse appointments</h2></div>
        <label class="entries-select">Show<select name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[4,8,12,16,'full'])?></select></label>
    </div>
    <div class="admin-record-search">
        <input class="form-control" type="search" name="q" value="<?=e($appointment_search)?>" placeholder="Search pet, owner, phone, concern, veterinarian, or code" aria-label="Search appointments">
        <select class="form-select" name="status" aria-label="Appointment status" onchange="this.form.submit()">
            <?php foreach(['all'=>'All','pending'=>'Pending','approved'=>'Approved','completed'=>'Completed','rejected'=>'Rejected','cancelled'=>'Cancelled'] as $key=>$label):?><option value="<?=$key?>" <?=$status_filter===$key?'selected':''?>><?=$label?> (<?=$key==='all'?$total:intval($counts[$key]??0)?>)</option><?php endforeach;?>
        </select>
        <button class="button-primary" type="submit">Search</button><a class="button-secondary" href="appointments.php">Clear</a>
    </div>
</form>
<?php if($rows->num_rows===0): ?>
<div class="appt-empty"><h3>No appointments found</h3><p>No <?=e($status_filter==='all'?'':$status_filter.' ')?>appointments match the current filters. <a href="appointments.php">Clear filters</a></p></div>
<?php else: ?>
<div class="appt-list view-list compact-appointment-list" id="vetAppointmentView">
        <div class="appointment-list-heading" aria-hidden="true"><span>Pet</span><span>Owner</span><span>Scheduled time</span><span>Veterinarian</span><span>Status</span><span>Action</span></div>
<?php while($r=$rows->fetch_assoc()): $modalId='vetApptDetails'.(int)$r['id']; ?>
    <?php include '../includes/appointment_row.php'; ?>
    <div class="appt-modal" id="<?=$modalId?>" aria-hidden="true">
        <div class="appt-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?=$modalId?>Title">
            <div class="appt-modal-head">
                <div><h3 id="<?=$modalId?>Title"><?=e($r['pet_name'])?> Appointment</h3><p>Appointment details and clinical actions.</p></div>
                <button type="button" class="appt-close" onclick="closeVetApptModal('<?=$modalId?>')" aria-label="Close appointment details"><?=ui_icon('x')?></button>
            </div>
            <div class="appt-modal-body">
                <section><?php include '../includes/appointment_details.php'; ?>
                <?php if($isAdmin): ?>
                <div class="appt-box full"><small>Allergies</small><span><?=e($r['allergies']?:'No allergies recorded')?></span></div>
                <div class="appt-box full"><small>Critical notes</small><span><?=e($r['critical_notes']?:'No critical notes recorded')?></span></div>
                <?php endif; ?>
                <?php if(trim((string)$r['allergies'])!=='' || trim((string)$r['critical_notes'])!==''): ?><p class="alert alert-warning" role="note">Review the recorded allergies and critical notes before completing this visit.</p><?php endif; ?>
                </section>
                <div class="appointment-clinical-actions"><?php if($r['status']==='approved'): ?>
                    <form method="POST" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="appointment_id" value="<?=$r['id']?>">
                        <button class="button-primary" name="mark_completed" data-confirm-message="Complete this visit and open the consultation form? The visit will be completed now; the consultation is saved separately.">Complete visit &amp; add consultation</button>
                    </form>
                <?php elseif($r['status']==='completed'): ?>
                    <span class="badge text-bg-<?=$r['consultation_id']?'success':'warning'?>"><?=$r['consultation_id']?'Record saved':'Record needed'?></span>
                    <?php if($r['consultation_id']): ?>
                    <a class="button-primary" href="<?=app_url('vet/medical_records.php?pet_id='.(int)$r['pet_id'].'&return_to='.rawurlencode($listReturn).'#medicalRecord'.(int)$r['consultation_id'])?>">View consultation</a>
                    <?php else: ?>
                    <a class="button-primary" href="create_record_from_appointment.php?id=<?=$r['id']?>&amp;return_to=<?=e(rawurlencode($listReturn))?>">Add consultation</a>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge text-bg-warning">Waiting for admin approval/schedule</span>
                <?php endif; ?></div>
            </div>
        </div>
    </div>
<?php endwhile; ?>
</div>
<?=render_pagination($page,$perPage,$totalRows,['status'=>$status_filter,'q'=>$appointment_search,'per_page'=>per_page_value($perPage),'appointment_id'=>$appointment_focus_id?:null,'_anchor'=>'vetAppointmentView'])?>
<?php endif; ?>
</section>
<script>
function openVetApptModal(id){
    const modal=document.getElementById(id);
    if(!modal)return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
}
function closeVetApptModal(id){
    const modal=document.getElementById(id);
    if(!modal)return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow='';
}
document.addEventListener('click',event=>{if(event.target.classList?.contains('appt-modal'))closeVetApptModal(event.target.id);});
document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelectorAll('.appt-modal.show').forEach(modal=>closeVetApptModal(modal.id));});
<?php if($appointment_focus_id): ?>document.addEventListener('DOMContentLoaded',()=>openVetApptModal('vetApptDetails<?=(int)$appointment_focus_id?>'));<?php endif; ?>
</script>
</main></div><?php include "../includes/footer.php"; ?>
