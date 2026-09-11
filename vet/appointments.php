<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");
$uid = (int)current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    verify_csrf_or_fail();
    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $stmt = $conn->prepare("SELECT a.*, p.name AS pet_name FROM appointments a JOIN pets p ON a.pet_id=p.id WHERE a.id=? AND (a.assigned_vet_id=? OR a.assigned_vet_id IS NULL) AND a.status='approved'");
    $stmt->bind_param("ii", $appointment_id, $uid);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    if ($appt) {
        $update = $conn->prepare("UPDATE appointments SET status='completed' WHERE id=?");
        $update->bind_param("i", $appointment_id);
        $update->execute();
        notify_user($conn, $appt['owner_id'], 'Appointment Completed', 'The appointment for ' . $appt['pet_name'] . ' has been marked completed by the veterinarian.', 'appointment', null);
        log_action($conn, 'Veterinarian marked appointment completed', 'appointment', $appointment_id, 'Appointment completed for ' . $appt['pet_name']);
        flash('success', 'Appointment marked as completed. Continue with the consultation prescription for this pet.');
        redirect_to('vet/prescription.php?appointment_id=' . $appointment_id . '&pet_id=' . (int)$appt['pet_id']);
    }
    redirect_to('vet/appointments.php');
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
    $whereStatus .= " AND (p.name LIKE '%$safeAppointmentSearch%' OR p.species LIKE '%$safeAppointmentSearch%' OR p.breed LIKE '%$safeAppointmentSearch%' OR u.full_name LIKE '%$safeAppointmentSearch%' OR u.email LIKE '%$safeAppointmentSearch%' OR u.phone LIKE '%$safeAppointmentSearch%' OR a.reason LIKE '%$safeAppointmentSearch%' OR a.confirmation_code LIKE '%$safeAppointmentSearch%' OR a.admin_notes LIKE '%$safeAppointmentSearch%')";
}
$appointment_search_param = $appointment_search !== '' ? '&q='.urlencode($appointment_search) : '';
$appointment_all_search_param = $appointment_search !== '' ? '?q='.urlencode($appointment_search) : '';

$title="Assigned Appointments";
include "../includes/header.php";
include "../includes/navbar.php";
$counts=[];
foreach(['pending','approved','completed','rejected','cancelled'] as $s){
    $counts[$s]=$conn->query("SELECT COUNT(*) c FROM appointments a WHERE (a.assigned_vet_id=$uid OR a.assigned_vet_id IS NULL) AND a.status='$s'")->fetch_assoc()['c'];
}
$total=array_sum(array_map('intval',$counts));
$totalRows=(int)$conn->query("SELECT COUNT(*) c FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id WHERE (a.assigned_vet_id=$uid OR a.assigned_vet_id IS NULL) AND a.status IN ('approved','pending','completed','rejected','cancelled') $whereStatus")->fetch_assoc()['c'];
$rows=$conn->query("SELECT a.*,u.full_name,u.phone,u.email,u.address,p.name pet_name,p.species,p.breed,p.pet_photo,p.birth_date,p.critical_notes,p.allergies,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id WHERE (a.assigned_vet_id=$uid OR a.assigned_vet_id IS NULL) AND a.status IN ('approved','pending','completed','rejected','cancelled') $whereStatus ORDER BY FIELD(a.status,'approved','pending','completed','rejected','cancelled'), COALESCE(a.scheduled_date,a.requested_date) ASC LIMIT $perPage OFFSET $offset");
?>
<div class="layout"><?php include "../includes/vet_sidebar.php"; ?>
<main class="content vet-appointments-page" id="mainContent">
<header class="page-heading appointment-page-heading"><div><span class="eyebrow">Clinic scheduling</span><h1>Appointments</h1><p>Manage and review clinic visit requests.</p></div><a class="button-secondary" href="<?=app_url('vet/calendar.php')?>"><?=ui_icon('calendar')?>Open calendar</a></header>
<?php if($m=flash('success')):?><div class="alert alert-success friendly-alert"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger"><?=e($m)?></div><?php endif;?>

<section class="appt-monitor">
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
<div class="appt-filter-toolbar"><div class="appt-filter-row" id="vetAppointmentsFilterBar">
    <a class="appt-filter status-all <?=$status_filter==='all'?'active':''?>" href="appointments.php<?=$appointment_all_search_param?>">All <b><?=$total?></b></a>
    <a class="appt-filter status-pending <?=$status_filter==='pending'?'active':''?>" href="appointments.php?status=pending<?=$appointment_search_param?>">Pending <b><?=$counts['pending']?></b></a>
    <a class="appt-filter status-approved <?=$status_filter==='approved'?'active':''?>" href="appointments.php?status=approved<?=$appointment_search_param?>">Approved <b><?=$counts['approved']?></b></a>
    <a class="appt-filter status-completed <?=$status_filter==='completed'?'active':''?>" href="appointments.php?status=completed<?=$appointment_search_param?>">Completed <b><?=$counts['completed']?></b></a>
    <a class="appt-filter status-rejected <?=$status_filter==='rejected'?'active':''?>" href="appointments.php?status=rejected<?=$appointment_search_param?>">Rejected <b><?=$counts['rejected']?></b></a>
    <a class="appt-filter status-cancelled <?=$status_filter==='cancelled'?'active':''?>" href="appointments.php?status=cancelled<?=$appointment_search_param?>">Cancelled <b><?=$counts['cancelled']?></b></a>
</div><form class="appt-category-controls" method="GET" action="appointments.php"><?php if($status_filter !== 'all'): ?><input type="hidden" name="status" value="<?=e($status_filter)?>"><?php endif; ?><input type="hidden" name="q" value="<?=e($appointment_search)?>"><label class="entries-select">Show<select class="unified-show-select" aria-label="Appointments per page" name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[4,8,12,16,'full'])?></select></label><div class="view-toggle" data-view-toggle data-target="#vetAppointmentView" data-key="vet-appointments-unified" data-default="list"><button type="button" data-view="list" aria-label="List view"><?=ui_icon('list')?></button><button type="button" data-view="grid" aria-label="Grid view"><?=ui_icon('grid')?></button></div></form></div>

<?php if($rows->num_rows===0): ?>
<div class="appt-empty"><h3>No appointments found</h3><p>Try another status filter or search term.</p></div>
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
                <section><?php include '../includes/appointment_details.php'; ?></section>
                <div class="appointment-clinical-actions"><?php if($r['status']==='approved'): ?>
                    <form method="POST" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="appointment_id" value="<?=$r['id']?>">
                        <button class="button-primary" name="mark_completed" data-confirm-message="Mark this appointment as completed? This will notify the client.">Mark Completed</button>
                    </form>
                <?php elseif($r['status']==='completed'): ?>
                    <a class="button-primary" href="create_record_from_appointment.php?id=<?=$r['id']?>">Create Medical Record</a>
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
