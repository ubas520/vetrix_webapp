<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");
$uid = current_user_id();

function vet_upload_pet_photo($fieldName) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return [true, null];
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $tmp = $_FILES[$fieldName]['tmp_name'];
    $mime = mime_content_type($tmp);
    if (!isset($allowed[$mime])) return [false, 'Pet picture must be JPG, PNG, or WEBP only.'];
    if ($_FILES[$fieldName]['size'] > 3 * 1024 * 1024) return [false, 'Pet picture must not exceed 3MB.'];
    $dir = __DIR__ . '/../uploads/pets';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $filename = 'pet_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, $dir . '/' . $filename)) return [false, 'Unable to save pet picture.'];
    return [true, 'uploads/pets/' . $filename];
}

function pretty_field_name($field) {
    $labels = [
        'name'=>'Name', 'species'=>'Pet Type', 'breed'=>'Breed', 'sex'=>'Sex', 'birth_date'=>'Birth Date',
        'weight'=>'Weight', 'color'=>'Color / Markings', 'allergies'=>'Allergies',
        'critical_notes'=>'Critical Notes', 'notes'=>'Behavior / Care Notes', 'pet_photo'=>'Pet Photo'
    ];
    return $labels[$field] ?? ucfirst(str_replace('_',' ', $field));
}
function vet_pet_safe_return_value($return = null) {
    $return = trim((string)($return ?? ($_POST['return_to'] ?? $_GET['return_to'] ?? '')));
    if ($return !== '' && preg_match('/^(?:vet|admin|staff|client)\/[A-Za-z0-9_\/.-]+\.php(?:\?.*)?$/', $return) && strpos($return, '..') === false) return $return;
    return '';
}
function vet_pet_edit_path($petId) {
    $path = 'vet/pets.php?edit='.(int)$petId;
    $return = vet_pet_safe_return_value();
    if ($return !== '') $path .= '&return_to=' . rawurlencode($return);
    return $path;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vet_update_pet'])) {
    verify_csrf_or_fail();
    $pet_id = (int)($_POST['pet_id'] ?? 0);
    $stmt = $conn->prepare("SELECT p.*, u.full_name AS owner_name, u.email AS owner_email FROM pets p JOIN users u ON p.owner_id=u.id WHERE p.id=? LIMIT 1");
    $stmt->bind_param("i", $pet_id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();

    if (!$old) {
        flash('error', 'Pet record was not found.');
        redirect_to('vet/pets.php');
    }

    $fields = ['name','species','breed','sex','birth_date','weight','color'];
    $data = [];
    foreach ($fields as $field) $data[$field] = trim($_POST[$field] ?? '');
    if ($data['weight'] !== '') $data['weight'] = number_format((float)$data['weight'], 2, '.', '');

    [$photoOk, $photoPath] = vet_upload_pet_photo('pet_photo');
    if (!$photoOk) {
        flash('error', $photoPath);
        redirect_to(vet_pet_edit_path($pet_id));
    }
    if ($photoPath) $data['pet_photo'] = $photoPath;

    $changed = [];
    $oldValues = [];
    $newValues = [];
    foreach ($data as $field => $newValue) {
        $oldValue = (string)($old[$field] ?? '');
        if ((string)$newValue !== $oldValue) {
            $changed[] = pretty_field_name($field);
            $oldValues[$field] = $oldValue;
            $newValues[$field] = $newValue;
        }
    }

    if (!$changed) {
        flash('success', 'No pet details were changed.');
        $returnAfterSave = vet_pet_safe_return_value();
        redirect_to($returnAfterSave !== '' ? $returnAfterSave : vet_pet_edit_path($pet_id));
    }

    $sql = "UPDATE pets SET name=?, species=?, breed=?, sex=?, birth_date=?, weight=?, color=?";
    $types = "sssssds";
    $params = [$data['name'], $data['species'], $data['breed'], $data['sex'], $data['birth_date'], (float)$data['weight'], $data['color']];
    if ($photoPath) {
        $sql .= ", pet_photo=?";
        $types .= "s";
        $params[] = $photoPath;
    }
    $sql .= ", last_updated_by=?, updated_at=NOW() WHERE id=?";
    $types .= "ii";
    $params[] = $uid;
    $params[] = $pet_id;

    $update = $conn->prepare($sql);
    $update->bind_param($types, ...$params);
    $update->execute();

    $vet = $conn->query("SELECT full_name,email,phone FROM users WHERE id=".(int)$uid)->fetch_assoc();
    $vetName = $vet['full_name'] ?? 'Veterinarian';
    $vetEmail = $vet['email'] ?? 'No email saved';
    $vetPhone = $vet['phone'] ?? 'No phone saved';
    $summary = 'Updated by '.$vetName.' ('.$vetEmail.') on '.date('M d, Y h:i A').'. Changed: '.implode(', ', $changed).'.';
    $oldJson = json_encode($oldValues, JSON_UNESCAPED_UNICODE);
    $newJson = json_encode($newValues, JSON_UNESCAPED_UNICODE);
    record_pet_update($conn, $pet_id, $summary, $oldJson, $newJson);

    $message = 'Dr./Vet '.$vetName.' edited the details of '.$old['name'].'. Changed fields: '.implode(', ', $changed).'. Vet details: '.$vetName.' | '.$vetEmail.' | '.$vetPhone.'.';
    notify_user($conn, (int)$old['owner_id'], 'Pet Details Updated by Vet', $message, 'record', null);
    log_action($conn, 'Veterinarian edited pet details', 'pet', $pet_id, $summary);

    flash('success', 'Pet details updated. The client was notified with your details for transparency.');
    $returnAfterSave = vet_pet_safe_return_value();
    redirect_to($returnAfterSave !== '' ? $returnAfterSave : vet_pet_edit_path($pet_id));
}

$title = "Pets";
include "../includes/header.php";
include "../includes/navbar.php";
$status = strtolower($_GET['status'] ?? 'all');
if (!in_array($status, ['all','approved','pending','rejected','in_person_confirmation'], true)) $status = 'all';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$returnTo = vet_pet_safe_return_value();
$petSearch = trim($_GET['q'] ?? '');
[$page,$perPage,$offset] = pagination_values(4,20,[4,8,12,16,20,'full']);
$conditions = [];
if ($status !== 'all') $conditions[] = "p.verification_status='".$conn->real_escape_string($status)."'";
if ($petSearch !== '') {
    $safeSearch = $conn->real_escape_string($petSearch);
    $conditions[] = "(p.name LIKE '%$safeSearch%' OR p.species LIKE '%$safeSearch%' OR p.breed LIKE '%$safeSearch%' OR p.color LIKE '%$safeSearch%' OR p.allergies LIKE '%$safeSearch%' OR p.critical_notes LIKE '%$safeSearch%' OR p.notes LIKE '%$safeSearch%' OR u.full_name LIKE '%$safeSearch%' OR u.email LIKE '%$safeSearch%' OR u.phone LIKE '%$safeSearch%')";
}
$whereSql = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';
$totalFiltered = (int)$conn->query("SELECT COUNT(*) c FROM pets p JOIN users u ON p.owner_id=u.id $whereSql")->fetch_assoc()['c'];
$counts = $conn->query("SELECT COUNT(*) total,SUM(verification_status='approved') approved,SUM(verification_status='pending') pending,SUM(verification_status='rejected') rejected,SUM(verification_status='in_person_confirmation') in_person_confirmation FROM pets")->fetch_assoc();
$rows = $conn->query("SELECT p.*,u.full_name owner_name,u.phone,u.email,updater.full_name updated_by_name,updater.role updated_by_role FROM pets p JOIN users u ON p.owner_id=u.id LEFT JOIN users updater ON p.last_updated_by=updater.id $whereSql ORDER BY p.updated_at DESC,p.created_at DESC LIMIT $perPage OFFSET $offset");
?>
<div class="layout"><?php include "../includes/vet_sidebar.php"; ?>
<main class="content vet-pets-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Clinical directory</span><h1>Pet Profiles</h1></div><a class="button-secondary" href="<?=app_url('vet/appointments.php')?>"><?=ui_icon('calendar')?>Appointments</a></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>

<section class="surface-card data-toolbar">
    <div class="filter-tabs" aria-label="Pet verification filters">
        <?php foreach(['all'=>'All','approved'=>'Approved','pending'=>'Pending','in_person_confirmation'=>'In-person','rejected'=>'Rejected'] as $key=>$label):$count=$key==='all'?($counts['total']??0):($counts[$key]??0);?>
        <a class="filter-tab status-<?=e($key)?> <?=$status===$key?'active':''?>" href="?status=<?=$key?>&q=<?=urlencode($petSearch)?>&per_page=<?=e(per_page_value($perPage))?>"><?=e($label)?><b><?=intval($count)?></b></a>
        <?php endforeach;?>
    </div>
    <div class="toolbar-actions"><label class="entries-select">Show<select name="per_page"><?=render_per_page_options($perPage,[4,8,12,16,20,'full'])?></select></label></div>
</section>
<form class="surface-card data-search-bar" method="GET"><input type="hidden" name="status" value="<?=e($status)?>"><input type="hidden" name="per_page" value="<?=e(per_page_value($perPage))?>"><input type="search" name="q" value="<?=e($petSearch)?>" placeholder="Search pet, owner, species, breed, or color"><button class="button-primary" type="submit">Search</button><?php if($petSearch!==''):?><a class="button-secondary" href="?status=<?=e($status)?>&per_page=<?=e(per_page_value($perPage))?>">Clear</a><?php endif;?></form>

<div class="pet-card-list view-grid vet-pet-grid-fix" id="vetPetCollection">
<?php if(!$rows->num_rows): ?><div class="admin-empty-card">No pet profiles found. Try another status filter or search term.</div><?php endif; ?>
<?php while($p=$rows->fetch_assoc()):
    $petDetail=['title'=>$p['name'].' profile','eyebrow'=>'Owned by '.$p['owner_name'],'fields'=>['Owner'=>$p['owner_name'],'Email'=>$p['email'] ?? '','Phone'=>$p['phone'] ?: 'No phone saved','Species'=>$p['species'],'Breed'=>$p['breed'] ?: 'Breed not set','Sex'=>$p['sex'],'Birth date'=>$p['birth_date'] ? date('M d, Y', strtotime($p['birth_date'])) : 'Not recorded','Age'=>pet_age($p['birth_date']),'Weight'=>$p['weight'].' kg','Color / markings'=>$p['color'] ?: 'Not specified','Verification'=>ucwords(str_replace('_',' ',$p['verification_status'] ?? 'registered')),'Updated by'=>$p['updated_by_name'] ? $p['updated_by_name'].' ('.$p['updated_by_role'].')' : 'Not recorded']];
    $petEditPayload=['id'=>$p['id'],'name'=>$p['name'],'species'=>$p['species'],'breed'=>$p['breed'],'sex'=>$p['sex'],'birth_date'=>$p['birth_date'],'weight'=>$p['weight'],'color'=>$p['color'],'pet_photo'=>$p['pet_photo']??''];
?>
    <article class="pet-review-card status-<?=e($p['verification_status'] ?? 'registered')?>" data-record-detail='<?=e(json_encode($petDetail))?>'>
        <div class="pet-main-info">
            <?=pet_avatar_markup($p,'pet-photo-wrap')?>
            <div class="pet-title-block">
                <div class="pet-name-row"><h4><?=e($p['name'])?></h4><?=badge($p['verification_status'] ?? 'registered')?></div>
                <p class="pet-owner">Owner: <b><?=e($p['owner_name'])?></b></p>
                <div class="pet-detail-chips"><span><?=e($p['species'])?></span><span><?=e($p['breed'] ?: 'Breed not set')?></span><span><?=e($p['sex'])?></span><span><?=e(pet_age($p['birth_date']))?></span><span><?=e($p['weight'])?> kg</span></div>
                <div class="pet-mini-details pet-profile-mini-details"><p><b>Color:</b> <?=e($p['color'] ?: 'Not specified')?></p><p><b>Sex:</b> <?=e($p['sex'] ?: 'Not recorded')?></p><p class="pet-birthdate-row"><b>Birth date:</b> <?=e($p['birth_date'] ? date('M d, Y', strtotime($p['birth_date'])) : 'Not recorded')?></p></div>
            </div>
        </div>
        <footer class="pet-review-actions"><span class="pet-record-date">Updated <?=e(!empty($p['updated_at']) ? date('M d, Y', strtotime($p['updated_at'])) : 'Not updated')?></span><div><button class="button-secondary" type="button" data-edit-pet='<?=e(json_encode($petEditPayload))?>' data-pet-id="<?=$p['id']?>"><?=ui_icon('edit')?>Edit</button></div></footer>
    </article>
<?php endwhile; ?>
</div>
<?=render_pagination($page,$perPage,$totalFiltered,['status'=>$status,'q'=>$petSearch,'per_page'=>per_page_value($perPage)])?>
<section class="calendar-dialog" id="vetPetEditDialog" aria-hidden="true"><div class="dialog-scrim" data-close-vet-pet-edit></div><div class="dialog-card pet-verified-update-dialog"><header><div><span class="eyebrow">Veterinarian update</span><h2>Edit pet details</h2><p>Only profile details are edited here. Health monitoring notes stay on the Health Monitoring page.</p></div><button class="icon-button" type="button" data-close-vet-pet-edit><?=ui_icon('x')?></button></header><form method="POST" enctype="multipart/form-data" class="form-stack pet-verified-update-form"><?=csrf_field()?><div class="vet-edit-photo-preview" id="vetEditPetPhotoPreview"><span><?=ui_icon('paw')?></span><small>No photo uploaded</small></div><input type="hidden" name="vet_update_pet" value="1"><input type="hidden" name="pet_id" id="vetEditPetId"><input type="hidden" name="return_to" value="<?=e($returnTo)?>"><div class="form-grid-two"><label>Pet name<input class="form-control" name="name" id="vetEditPetName" required></label><label>Animal type<select class="form-select" name="species" id="vetEditPetSpecies" required><option>Dog</option><option>Cat</option><option>Other</option></select></label></div><div class="form-grid-two"><label>Breed<input class="form-control" name="breed" id="vetEditPetBreed" required></label><label>Sex<select class="form-select" name="sex" id="vetEditPetSex" required><option>Male</option><option>Female</option><option>Unknown</option></select></label></div><div class="form-grid-two"><label>Birth date<input class="form-control" type="date" name="birth_date" id="vetEditPetBirth" required></label><label>Weight in kg<input class="form-control" type="number" step="0.01" min="0" name="weight" id="vetEditPetWeight" required></label></div><label>Color / markings<input class="form-control" name="color" id="vetEditPetColor" required></label><label>Replace pet photo<input class="form-control" type="file" name="pet_photo" accept="image/jpeg,image/png,image/webp"><small>Leave blank to keep the current photo.</small></label><div class="form-actions"><button class="button-secondary" type="button" data-close-vet-pet-edit>Cancel</button><button class="button-primary" type="submit">Save and Notify Client</button></div></form></div></section>
<script>(()=>{const dialog=document.getElementById('vetPetEditDialog');if(!dialog)return;const returnTo='<?=e($returnTo)?>';const base=(window.VETRIX_BASE||'<?=e(app_url(''))?>').replace(/\/$/,'');const open=data=>{document.getElementById('vetEditPetId').value=data.id||'';document.getElementById('vetEditPetName').value=data.name||'';document.getElementById('vetEditPetSpecies').value=data.species||'Other';document.getElementById('vetEditPetBreed').value=data.breed||'';document.getElementById('vetEditPetSex').value=data.sex||'Unknown';document.getElementById('vetEditPetBirth').value=data.birth_date||'';document.getElementById('vetEditPetWeight').value=data.weight||'';document.getElementById('vetEditPetColor').value=data.color||'';const photo=document.getElementById('vetEditPetPhotoPreview');if(photo){if(data.pet_photo){photo.innerHTML='<img src="'+base+'/'+String(data.pet_photo).replace(/^\//,'')+'" alt="Current pet photo">';}else{photo.innerHTML='<span class="pet-photo-placeholder-icon">🐾</span><small>No photo uploaded</small>';}}dialog.classList.add('open');dialog.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open')};document.querySelectorAll('[data-edit-pet]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();open(JSON.parse(button.dataset.editPet||'{}'))}));const close=()=>{if(returnTo){location.href=base+'/'+returnTo.replace(/^\//,'');return;}dialog.classList.remove('open');dialog.setAttribute('aria-hidden','true');document.body.classList.remove('overlay-open')};document.querySelectorAll('[data-close-vet-pet-edit]').forEach(button=>button.addEventListener('click',close));<?php if($editId):?>const focus=document.querySelector('[data-edit-pet][data-pet-id="<?=$editId?>"]');if(focus)setTimeout(()=>focus.click(),80);<?php endif;?>})();</script>
</main></div><?php include "../includes/footer.php"; ?>
