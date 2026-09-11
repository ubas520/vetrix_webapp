<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");
$uid = (int)current_user_id();
function vet_health_safe_return($fallback = 'vet/health_monitoring.php') {
    $return = trim((string)($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
    if ($return !== '' && preg_match('/^vet\/health_monitoring\.php(?:\?.*)?$/', $return) && strpos($return, '..') === false) return $return;
    return $fallback;
}
function vet_health_label($field) {
    return ['allergies'=>'Allergies', 'critical_notes'=>'Critical Notes', 'notes'=>'Care Notes'][$field] ?? ucfirst(str_replace('_',' ', $field));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vet_update_health'])) {
    verify_csrf_or_fail();
    $pet_id = (int)($_POST['pet_id'] ?? 0);
    $stmt = $conn->prepare("SELECT p.*, u.full_name AS owner_name FROM pets p JOIN users u ON p.owner_id=u.id WHERE p.id=? LIMIT 1");
    $stmt->bind_param("i", $pet_id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    if (!$old) {
        flash('error', 'Health monitoring record was not found.');
        redirect_to(vet_health_safe_return());
    }
    $fields = ['allergies','critical_notes','notes'];
    $data = [];
    foreach ($fields as $field) $data[$field] = trim((string)($_POST[$field] ?? ''));
    $changed = [];
    $oldValues = [];
    $newValues = [];
    foreach ($data as $field => $newValue) {
        $oldValue = (string)($old[$field] ?? '');
        if ($newValue !== $oldValue) {
            $changed[] = vet_health_label($field);
            $oldValues[$field] = $oldValue;
            $newValues[$field] = $newValue;
        }
    }
    if (!$changed) {
        flash('error', 'Nothing changed, so no health monitoring update was saved.');
        redirect_to(vet_health_safe_return());
    }
    $update = $conn->prepare("UPDATE pets SET allergies=?, critical_notes=?, notes=?, last_updated_by=?, updated_at=NOW() WHERE id=?");
    $update->bind_param("sssii", $data['allergies'], $data['critical_notes'], $data['notes'], $uid, $pet_id);
    $update->execute();
    $summary = 'Health monitoring updated by '.($_SESSION['full_name'] ?? 'Veterinarian').'. Changed: '.implode(', ', $changed).'.';
    record_pet_update($conn, $pet_id, $summary, json_encode($oldValues, JSON_UNESCAPED_UNICODE), json_encode($newValues, JSON_UNESCAPED_UNICODE));
    notify_user($conn, (int)$old['owner_id'], 'Pet Health Monitoring Updated', 'Health monitoring notes were updated for '.$old['name'].'. Changed fields: '.implode(', ', $changed).'.', 'record', null);
    log_action($conn, 'Veterinarian updated health monitoring', 'pet', $pet_id, $summary);
    flash('success', 'Health monitoring details updated.');
    redirect_to(vet_health_safe_return());
}
$title="Pet Health Monitoring";
include "../includes/header.php";
include "../includes/navbar.php";
$health_search = trim($_GET['q'] ?? '');
$health_status = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($health_status, ['all','approved','pending'], true)) $health_status = 'all';
$health_show = strtolower(trim((string)($_GET['per_page'] ?? '6')));
if (!in_array($health_show, ['6','12','full'], true)) $health_show = '6';
$health_limit = $health_show === 'full' ? '' : ' LIMIT ' . (int)$health_show;
$health_conditions = [];
if ($health_status !== 'all') {
    $health_conditions[] = "p.verification_status='" . $conn->real_escape_string($health_status) . "'";
}
if ($health_search !== '') {
    $safeHealthSearch = $conn->real_escape_string($health_search);
    $health_conditions[] = "(p.name LIKE '%$safeHealthSearch%' OR p.species LIKE '%$safeHealthSearch%' OR p.breed LIKE '%$safeHealthSearch%' OR p.color LIKE '%$safeHealthSearch%' OR p.allergies LIKE '%$safeHealthSearch%' OR p.critical_notes LIKE '%$safeHealthSearch%' OR p.notes LIKE '%$safeHealthSearch%' OR u.full_name LIKE '%$safeHealthSearch%' OR u.email LIKE '%$safeHealthSearch%' OR u.phone LIKE '%$safeHealthSearch%')";
}
$health_where = $health_conditions ? 'WHERE ' . implode(' AND ', $health_conditions) : '';
$healthReturnTo = 'vet/health_monitoring.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
$rows=$conn->query("SELECT p.*,u.full_name owner_name,u.phone,u.email FROM pets p JOIN users u ON p.owner_id=u.id $health_where ORDER BY p.updated_at DESC, p.created_at DESC$health_limit");
$total=$conn->query("SELECT COUNT(*) c FROM pets")->fetch_assoc()['c'];
$approved=$conn->query("SELECT COUNT(*) c FROM pets WHERE verification_status='approved'")->fetch_assoc()['c'];
$pending=$conn->query("SELECT COUNT(*) c FROM pets WHERE verification_status='pending'")->fetch_assoc()['c'];
?>
<div class="layout"><?php include "../includes/vet_sidebar.php"; ?>
<main class="content vet-health-monitoring-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Health overview</span><h1>Pet Health Monitoring</h1></div><a class="button-secondary" href="<?=app_url('vet/pets.php?return_to=' . rawurlencode($healthReturnTo))?>"><?=ui_icon('paw')?>Pet profiles</a></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>

<section class="surface-card data-toolbar vet-toolbar-card">
<div class="filter-tabs" id="healthFilterBar">
    <?php foreach(['all'=>'All Pets','approved'=>'Approved','pending'=>'Pending'] as $key=>$label): $query=['status'=>$key,'q'=>$health_search,'per_page'=>$health_show]; $count=$key==='all'?$total:($key==='approved'?$approved:$pending); ?>
    <a class="reference-filter-chip status-<?=e($key)?> <?=$health_status===$key?'active':''?>" href="?<?=e(http_build_query(array_filter($query, fn($v)=>$v!==''&&$v!==null)))?>" data-filter="<?=e($key)?>" <?=$health_status===$key?'data-active-chip="1" aria-pressed="true"':'aria-pressed="false"'?>><?=e($label)?> <span class="count"><?=intval($count)?></span></a>
    <?php endforeach; ?>
</div>
<form class="toolbar-actions moved-show-control" method="GET" action="health_monitoring.php"><input type="hidden" name="status" value="<?=e($health_status)?>"><input type="hidden" name="q" value="<?=e($health_search)?>"><label class="entries-select">Show<select class="unified-show-select" name="per_page" aria-label="Pets per page" onchange="this.form.submit()"><option value="6" <?=$health_show==='6'?'selected':''?>>6</option><option value="12" <?=$health_show==='12'?'selected':''?>>12</option><option value="full" <?=$health_show==='full'?'selected':''?>>Full</option></select></label></form></section>
<form class="admin-list-search" method="GET" action="health_monitoring.php">
    <input type="hidden" name="status" value="<?=e($health_status)?>">
    <input class="form-control" type="search" name="q" value="<?=e($health_search)?>" placeholder="Search pet, owner, allergy, critical note, or care note">
    
    <button class="btn btn-primary" type="submit">Search</button>
    <a class="btn btn-light" href="health_monitoring.php?status=<?=e($health_status)?>&per_page=<?=e($health_show)?>">Clear</a>
</form>

<div class="pet-card-list view-grid vet-grid-only" id="vetHealthGrid">
<?php if($rows->num_rows===0): ?><div class="admin-empty-card">No pets found. Try another status filter or search term.</div><?php endif; ?>
<?php while($p=$rows->fetch_assoc()):
    $detail=['title'=>$p['name'].' health monitoring','eyebrow'=>'Health monitoring snapshot','fields'=>['Allergies'=>$p['allergies'] ?: 'No allergies saved.','Critical notes'=>$p['critical_notes'] ?: 'No critical notes.','Care notes'=>$p['notes'] ?: 'No notes saved.','Last updated'=>!empty($p['updated_at']) ? date('M d, Y h:i A', strtotime($p['updated_at'])) : 'Not updated','_dialog'=>'health-monitoring']];
    $healthEditPayload=['id'=>$p['id'],'name'=>$p['name'],'allergies'=>$p['allergies'],'critical_notes'=>$p['critical_notes'],'notes'=>$p['notes']];
?>
    <article class="pet-review-card status-<?=e(strtolower($p['verification_status'] ?? 'registered'))?>" data-record-detail='<?=e(json_encode($detail))?>' data-status="<?=e(strtolower($p['verification_status'] ?? 'registered'))?>">
        <div class="pet-main-info">
            <?=pet_avatar_markup($p,'pet-photo-wrap')?>
            <div class="pet-title-block">
                <div class="pet-name-row"><h4><?=e($p['name'])?></h4><?=badge($p['verification_status'] ?? 'registered')?></div>
                <p class="pet-owner">Owner: <b><?=e($p['owner_name'])?></b></p>
                <div class="pet-detail-chips"><span><?=e($p['species'])?></span><span><?=e($p['breed'] ?: 'Breed not set')?></span><span><?=e(pet_age($p['birth_date']))?></span><span><?=e($p['weight'] ?: '0')?> kg</span></div>
                <div class="pet-mini-details"><p><b>Allergies:</b> <?=e($p['allergies'] ?: 'No allergies saved.')?></p><p><b>Critical notes:</b> <?=e($p['critical_notes'] ?: 'No critical notes.')?></p><p><b>Care notes:</b> <?=e($p['notes'] ?: 'No notes saved.')?></p></div>
            </div>
        </div>
        <footer class="pet-review-actions"><span class="pet-record-date">Updated <?=e(!empty($p['updated_at']) ? date('M d, Y', strtotime($p['updated_at'])) : 'Not updated')?></span><div><button class="button-secondary" type="button" data-edit-health='<?=e(json_encode($healthEditPayload))?>'><?=ui_icon('edit')?>Update Health Monitoring</button></div></footer>
    </article>
<?php endwhile; ?>
    <div class="admin-empty-card reference-empty-filter" id="healthEmptyFilter" style="display:none;">No pets found for this filter.</div>
</div>
<section class="calendar-dialog" id="vetHealthEditDialog" aria-hidden="true"><div class="dialog-scrim" data-close-vet-health-edit></div><div class="dialog-card health-monitoring-update-dialog"><header><div><span class="eyebrow">Health monitoring</span><h2>Update health notes</h2><p>Only allergies, critical notes, and care notes are edited here.</p></div><button class="icon-button" type="button" data-close-vet-health-edit><?=ui_icon('x')?></button></header><form method="POST" class="form-stack health-monitoring-update-form"><?=csrf_field()?><input type="hidden" name="vet_update_health" value="1"><input type="hidden" name="pet_id" id="vetHealthEditPetId"><input type="hidden" name="return_to" value="<?=e($healthReturnTo)?>"><label>Allergies<input class="form-control" name="allergies" id="vetHealthEditAllergies"></label><label>Critical notes<textarea class="form-control" name="critical_notes" id="vetHealthEditCritical"></textarea></label><label>Care notes<textarea class="form-control" name="notes" id="vetHealthEditNotes"></textarea></label><div class="form-actions"><button class="button-secondary" type="button" data-close-vet-health-edit>Cancel</button><button class="button-primary" type="submit">Save Health Monitoring</button></div></form></div></section>
<script>(()=>{const dialog=document.getElementById('vetHealthEditDialog');if(!dialog)return;const open=data=>{document.getElementById('vetHealthEditPetId').value=data.id||'';document.getElementById('vetHealthEditAllergies').value=data.allergies||'';document.getElementById('vetHealthEditCritical').value=data.critical_notes||'';document.getElementById('vetHealthEditNotes').value=data.notes||'';dialog.classList.add('open');dialog.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open')};document.querySelectorAll('[data-edit-health]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();open(JSON.parse(button.dataset.editHealth||'{}'))}));const close=()=>{dialog.classList.remove('open');dialog.setAttribute('aria-hidden','true');document.body.classList.remove('overlay-open')};document.querySelectorAll('[data-close-vet-health-edit]').forEach(button=>button.addEventListener('click',close));})();</script>
</main></div><?php include "../includes/footer.php"; ?>
