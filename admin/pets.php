<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("admin");

$return_to = trim($_REQUEST['return_to'] ?? '');
if ($return_to !== '' && (!preg_match('#^(admin|staff|vet|client)/[A-Za-z0-9_./?=&%-]+$#', $return_to) || str_contains($return_to, '..'))) $return_to = '';

function upload_pet_photo_admin($fieldName) {
    $upload = upload_image_file($_FILES[$fieldName] ?? null, 'uploads/pets', 'pet');
    if (!$upload['ok']) return [false, $upload['error']];
    return [true, $upload['path']];
}

function generate_pet_qr_if_missing($conn, $pet_id, $pet_name) {
    $check = $conn->prepare("SELECT id FROM qr_tokens WHERE pet_id=? AND status='active' LIMIT 1");
    $check->bind_param("i", $pet_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) return;
    $token = strtoupper(preg_replace('/[^A-Z0-9]/i','',$pet_name)) . "-QR-" . $pet_id . "-" . date("Y");
    $expires = date("Y-m-d H:i:s", strtotime("+1 year"));
    $qr = $conn->prepare("INSERT INTO qr_tokens(pet_id,token,expires_at,status) VALUES(?,?,?,'active')");
    $qr->bind_param("iss", $pet_id, $token, $expires);
    $qr->execute();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf_or_fail();
    $admin_id = current_user_id();

    if (isset($_POST['update_pet'])) {
        $pet_id=(int)($_POST['pet_id']??0);
        $required=['name','species','breed','sex','birth_date','weight','color','allergies','critical_notes','notes'];
        foreach($required as $field){if(trim((string)($_POST[$field]??''))===''){flash('error','Complete all pet detail fields before saving.');redirect_to('admin/pets.php?pet_id='.$pet_id.($return_to!==''?'&return_to='.urlencode($return_to):''));}}
        $existing=$conn->query("SELECT id,name,owner_id,pet_photo FROM pets WHERE id=$pet_id LIMIT 1")->fetch_assoc();
        if(!$existing){flash('error','Pet profile not found.');redirect_to('admin/pets.php');}
        $upload=upload_image_file($_FILES['pet_photo']??null,'uploads/pets','pet');
        if(!$upload['ok']){flash('error',$upload['error']);redirect_to('admin/pets.php?pet_id='.$pet_id.($return_to!==''?'&return_to='.urlencode($return_to):''));}
        $photo=$upload['path'] ?: $existing['pet_photo'];
        $weight=(float)$_POST['weight'];
        $stmt=$conn->prepare("UPDATE pets SET name=?,species=?,breed=?,sex=?,birth_date=?,weight=?,color=?,allergies=?,critical_notes=?,notes=?,pet_photo=?,last_updated_by=?,updated_at=NOW() WHERE id=?");
        $stmt->bind_param('sssssdsssssii',$_POST['name'],$_POST['species'],$_POST['breed'],$_POST['sex'],$_POST['birth_date'],$weight,$_POST['color'],$_POST['allergies'],$_POST['critical_notes'],$_POST['notes'],$photo,$admin_id,$pet_id);
        $stmt->execute();
        notify_user($conn,(int)$existing['owner_id'],'Pet Profile Updated','The clinic updated the profile for '.$_POST['name'].'.','record',null);
        log_action($conn,'Admin updated pet profile','pet',$pet_id,'Direct clinic update with administrator verification.');
        flash('success','Pet details updated and the owner was notified.');
        redirect_to('admin/pets.php?pet_id='.$pet_id.($return_to!==''?'&return_to='.urlencode($return_to):''));
    }

    if (isset($_POST['verify_pet'])) {
        $pet_id = (int)$_POST['pet_id'];
        $status = $_POST['verification_status'];
        $notes = trim($_POST['verification_notes'] ?? '');
        $allowed = ['approved','rejected','in_person_confirmation','pending'];
        if (!in_array($status, $allowed, true)) $status = 'pending';

        $pet = $conn->query("SELECT id,name,owner_id FROM pets WHERE id=$pet_id")->fetch_assoc();
        if ($pet) {
            $stmt = $conn->prepare("UPDATE pets SET verification_status=?, verification_notes=?, verified_by=?, verified_at=NOW(), last_updated_by=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("ssiii", $status, $notes, $admin_id, $admin_id, $pet_id);
            $stmt->execute();
            if ($status === 'approved') generate_pet_qr_if_missing($conn, $pet_id, $pet['name']);
            $label = $status === 'in_person_confirmation' ? 'In-person confirmation required' : ucfirst($status);
            notify_user($conn, $pet['owner_id'], 'Pet Verification Update', 'Your pet '.$pet['name'].' status is now '.$label.'. '.$notes, 'record',null);
            log_action($conn, 'Admin updated pet verification', 'pet', $pet_id, 'Status: '.$status);
            flash('success','Pet verification status updated.');
        }
        redirect_to('admin/pets.php');
    }

    if (isset($_POST['add_pet'])) {
        if (($_POST['species'] ?? '') === 'Other') $_POST['species'] = trim((string)($_POST['species_other'] ?? ''));
        $required = ['owner_id','name','species','breed','sex','birth_date','weight','color','allergies','critical_notes','notes'];
        foreach ($required as $field) {
            if (trim($_POST[$field] ?? '') === '') { flash('error','Please complete all required pet details.'); redirect_to('admin/pets.php'); }
        }
        [$ok,$photo] = upload_pet_photo_admin('pet_photo');
        if (!$ok) { flash('error',$photo); redirect_to('admin/pets.php'); }
        $status = 'approved';
        $notes = 'Added and verified by clinic admin.';
        $stmt=$conn->prepare("INSERT INTO pets(owner_id,name,species,breed,sex,birth_date,weight,color,allergies,critical_notes,notes,pet_photo,verification_status,verification_notes,verified_by,verified_at,last_updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,NOW())");
        $weight=(float)$_POST['weight'];
        $owner=(int)$_POST['owner_id'];
        $stmt->bind_param("isssssdsssssssii", $owner,$_POST['name'],$_POST['species'],$_POST['breed'],$_POST['sex'],$_POST['birth_date'],$weight,$_POST['color'],$_POST['allergies'],$_POST['critical_notes'],$_POST['notes'],$photo,$status,$notes,$admin_id,$admin_id);
        $stmt->execute();
        $pet_id=$conn->insert_id;
        generate_pet_qr_if_missing($conn, $pet_id, $_POST['name']);
        log_action($conn,'Admin added approved pet profile','pet',$pet_id,'Walk-in pet added and approved.');
        notify_user($conn,$owner,'Pet Profile Added','The clinic added and approved a pet profile: '.$_POST['name'],'record',null);
        flash('success','Pet saved as approved and QR token generated.');
        redirect_to('admin/pets.php');
    }
}
$pet_filter = $_GET['verification'] ?? 'all';
$pet_search = trim($_GET['q'] ?? '');
$species_filter = trim($_GET['species'] ?? '');
$sex_filter = trim($_GET['sex'] ?? '');
if (!in_array($sex_filter, ['', 'Male', 'Female', 'Unknown'], true)) $sex_filter = '';
$pet_sort = $_GET['sort'] ?? 'default';
if (!in_array($pet_sort, ['default','name_asc','name_desc'], true)) $pet_sort = 'default';
$pet_focus_id=max(0,(int)($_GET['pet_id']??0));
$allowed_pet_filters = ['all','pending','in_person_confirmation','approved','rejected'];
if (!in_array($pet_filter, $allowed_pet_filters, true)) $pet_filter = 'all';
$pet_conditions = [];
if($pet_focus_id)$pet_conditions[]='p.id='.$pet_focus_id;
if ($pet_filter !== 'all') {
    $pet_conditions[] = "p.verification_status='".$conn->real_escape_string($pet_filter)."'";
}
if ($species_filter !== '') $pet_conditions[] = "p.species='".$conn->real_escape_string($species_filter)."'";
if ($sex_filter !== '') $pet_conditions[] = "p.sex='".$conn->real_escape_string($sex_filter)."'";
if ($pet_search !== '') {
    $safePetSearch = $conn->real_escape_string($pet_search);
    $pet_conditions[] = "(p.name LIKE '%$safePetSearch%' OR p.species LIKE '%$safePetSearch%' OR p.breed LIKE '%$safePetSearch%' OR p.color LIKE '%$safePetSearch%' OR p.notes LIKE '%$safePetSearch%' OR p.critical_notes LIKE '%$safePetSearch%' OR p.allergies LIKE '%$safePetSearch%' OR u.full_name LIKE '%$safePetSearch%')";
}
$pet_where = $pet_conditions ? 'WHERE '.implode(' AND ', $pet_conditions) : '';
$pet_search_param = $pet_search !== '' ? '&q='.urlencode($pet_search) : '';
$pet_all_search_param = $pet_search !== '' ? '?q='.urlencode($pet_search) : '';
[$page,$perPage,$offset]=pagination_values(6,24,[6,12,18,24,'full']);
$pet_query_base = ['q'=>$pet_search,'species'=>$species_filter,'sex'=>$sex_filter,'sort'=>$pet_sort,'per_page'=>per_page_value($perPage)];
$filteredPetTotal=(int)$conn->query("SELECT COUNT(*) c FROM pets p JOIN users u ON p.owner_id=u.id $pet_where")->fetch_assoc()['c'];

$title="Pets"; include "../includes/header.php"; include "../includes/navbar.php"; ?>
<div class="layout"><?php include "../includes/admin_sidebar.php"; ?><main class="content admin-pets-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Records</span><h1>Pet Profiles</h1></div><a class="button-secondary" href="pet_edit_requests.php"><?=ui_icon('edit')?>Pet edit requests</a></header>


<?php if($m=flash('success')):?><div class="alert alert-success"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-danger"><?=e($m)?></div><?php endif;?>
<?php
$summary = ['pending'=>0,'in_person_confirmation'=>0,'approved'=>0,'rejected'=>0];
$sumResult = $conn->query("SELECT verification_status, COUNT(*) AS total FROM pets GROUP BY verification_status");
while($sr = $sumResult->fetch_assoc()){
    $key = $sr['verification_status'] ?: 'pending';
    if(isset($summary[$key])) $summary[$key] = (int)$sr['total'];
}
?>
<div class="pet-admin-shell">
    <aside class="pet-admin-form-card">
        <div class="pet-section-heading">
            <span class="pet-icon"><?= ui_icon('plus') ?></span>
            <h3>Add Walk-In Pet</h3>
        </div>
        <form method="POST" enctype="multipart/form-data" class="pet-walkin-form">
            <?= csrf_field() ?>
            <input type="hidden" name="add_pet" value="1">
            <label class="form-label">Owner</label>
            <select class="form-select" name="owner_id" required>
                <option value="">Select owner</option>
                <?php $owners=$conn->query("SELECT id,full_name FROM users WHERE role='client' ORDER BY full_name ASC"); while($o=$owners->fetch_assoc()): ?>
                    <option value="<?=$o['id']?>"><?=e($o['full_name'])?></option>
                <?php endwhile;?>
            </select>
            <div class="pet-two-col">
                <div><label class="form-label">Pet name</label><input class="form-control" name="name" placeholder="Pet name" required></div>
                <div><label class="form-label">Type</label><select class="form-select" name="species" id="adminPetSpecies" required><option value="">Pet type</option><option>Dog</option><option>Cat</option><option>Other</option></select><input class="form-control" name="species_other" id="adminPetSpeciesOther" placeholder="Specify animal type" hidden></div>
            </div>
            <div class="pet-two-col">
                <div><label class="form-label">Breed</label><input class="form-control" name="breed" list="adminBreedSuggestions" placeholder="Breed" required></div>
                <div><label class="form-label">Sex</label><select class="form-select" name="sex" required><option value="">Sex</option><option>Male</option><option>Female</option><option>Unknown</option></select></div>
            </div>
            <div class="pet-two-col">
                <div><label class="form-label">Birth date</label><input class="form-control" type="date" name="birth_date" required></div>
                <div><label class="form-label">Weight</label><input class="form-control" type="number" step="0.01" min="0" name="weight" placeholder="kg" required></div>
            </div>
            <label class="form-label">Color / markings</label><input class="form-control" name="color" list="adminColorSuggestions" placeholder="Color / markings" required>
            <label class="form-label">Allergies</label><input class="form-control" name="allergies" list="adminAllergySuggestions" placeholder="Allergies or none" required>
            <label class="form-label">Pet picture <span class="optional-label">optional</span></label><input class="form-control" type="file" name="pet_photo" accept="image/jpeg,image/png,image/webp"><small class="form-help">JPG, PNG, or WEBP up to 3 MB.</small>
            <label class="form-label">Critical notes</label><textarea class="form-control" name="critical_notes" placeholder="Critical notes or none" required></textarea>
            <label class="form-label">Owner notes</label><textarea class="form-control" name="notes" placeholder="Owner notes" required></textarea>
            <button class="btn btn-primary w-100 mt-2">Save Approved Pet</button>
        </form>
    </aside>

    <section class="pet-admin-list-card">
        <div class="pet-list-header">
            <div>
                <h3>All Pet Profiles</h3>
                
            </div>
            <div class="pet-status-summary">
                <span><b><?=array_sum($summary)?></b> Total</span>
                <span><b><?=$summary['pending']?></b> Pending</span>
                <span><b><?=$summary['in_person_confirmation']?></b> In-person</span>
                <span><b><?=$summary['approved']?></b> Approved</span>
                <span><b><?=$summary['rejected']?></b> Rejected</span>
            </div>
        </div>

        <datalist id="adminBreedSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT breed value FROM pets WHERE TRIM(COALESCE(breed,''))<>'' ORDER BY id DESC LIMIT 80");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="adminColorSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT color value FROM pets WHERE TRIM(COALESCE(color,''))<>'' ORDER BY id DESC LIMIT 60");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="adminAllergySuggestions"><?php $suggest=$conn->query("SELECT DISTINCT allergies value FROM pets WHERE TRIM(COALESCE(allergies,''))<>'' ORDER BY id DESC LIMIT 60");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><script>(()=>{const species=document.getElementById('adminPetSpecies'),other=document.getElementById('adminPetSpeciesOther');const sync=()=>{const show=species?.value==='Other';if(other){other.hidden=!show;other.required=!!show;if(!show)other.value='';}};species?.addEventListener('change',sync);sync();})();</script>
<div class="pet-category-toolbar"><div class="filter-tabs pet-filter-pills">
            <?php foreach(['all'=>'All','pending'=>'Pending','in_person_confirmation'=>'In-person','approved'=>'Approved','rejected'=>'Rejected'] as $key=>$label): $count=$key==='all'?array_sum($summary):$summary[$key]; $query=array_filter(array_merge($pet_query_base,['verification'=>$key==='all'?null:$key]),fn($value)=>$value!==''&&$value!==null); ?>
            <a class="filter-tab filter-<?=e($key)?> <?=$pet_filter===$key?'active':''?>" href="?<?=e(http_build_query($query))?>"><?=e($label)?><b><?=intval($count)?></b></a>
            <?php endforeach;?>
        </div><form class="pet-category-controls" method="GET" action="pets.php"><input type="hidden" name="verification" value="<?=e($pet_filter)?>"><input type="hidden" name="q" value="<?=e($pet_search)?>"><input type="hidden" name="species" value="<?=e($species_filter)?>"><input type="hidden" name="sex" value="<?=e($sex_filter)?>"><label class="entries-select">Show<select class="unified-show-select" name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[6,12,18,24,'full'])?></select></label><div class="view-toggle" data-view-toggle data-target="#adminPetView" data-key="admin-pets-unified" data-default="grid"><button type="button" data-view="list" aria-label="List view"><?=ui_icon('list')?></button><button type="button" data-view="grid" aria-label="Grid view"><?=ui_icon('grid')?></button></div></form></div>
<form class="surface-card data-search-bar pet-search-filters" method="GET" action="pets.php">
            <input type="hidden" name="verification" value="<?=e($pet_filter)?>">
            <input type="hidden" name="sort" value="default">
            <input type="search" name="q" value="<?=e($pet_search)?>" placeholder="Search pet, owner, breed, notes, allergies">
            <select class="form-select" name="species"><option value="">All animal types</option><?php $speciesRows=$conn->query("SELECT DISTINCT species FROM pets WHERE species<>'' ORDER BY species");while($speciesRow=$speciesRows->fetch_assoc()):?><option value="<?=e($speciesRow['species'])?>" <?=$species_filter===$speciesRow['species']?'selected':''?>><?=e($speciesRow['species'])?></option><?php endwhile;?></select>
            <button class="button-primary" type="submit">Search</button>
            <a class="button-secondary" href="?<?=e(http_build_query(['verification'=>$pet_filter,'sort'=>'default','per_page'=>per_page_value($perPage)]))?>">Clear</a>
        </form>
<div class="pet-card-list view-grid vet-pet-grid-fix" id="adminPetView">
        <?php $petOrder = $pet_sort==='name_asc' ? "p.name ASC, p.id ASC" : ($pet_sort==='name_desc' ? "p.name DESC, p.id DESC" : "FIELD(p.verification_status,'pending','in_person_confirmation','rejected','approved'), p.created_at DESC"); $rows=$conn->query("SELECT p.*,u.full_name FROM pets p JOIN users u ON p.owner_id=u.id $pet_where ORDER BY $petOrder LIMIT $perPage OFFSET $offset"); if($rows->num_rows===0): ?>
            <div class="admin-empty-card">No pet profiles found for this filter or search.</div>
        <?php endif; while($r=$rows->fetch_assoc()): ?>
            <?php $petDetail=['title'=>$r['name'].' profile','eyebrow'=>'Owned by '.$r['full_name'],'fields'=>['Species'=>$r['species'],'Breed'=>$r['breed'],'Sex'=>$r['sex'],'Age'=>pet_age($r['birth_date']),'Weight'=>$r['weight'].' kg','Color / markings'=>$r['color'],'Allergies'=>$r['allergies'],'Critical notes'=>$r['critical_notes'],'Care notes'=>$r['notes'],'Verification'=>ucwords(str_replace('_',' ',$r['verification_status']))]]; $petReviewPayload=['id'=>$r['id'],'name'=>$r['name'],'owner'=>$r['full_name'],'species'=>$r['species'],'breed'=>$r['breed'],'sex'=>$r['sex'],'age'=>pet_age($r['birth_date']),'birth_date'=>$r['birth_date'],'weight'=>$r['weight'],'color'=>$r['color'],'allergies'=>$r['allergies'],'critical_notes'=>$r['critical_notes'],'notes'=>$r['notes'],'verification_status'=>$r['verification_status'],'verification_notes'=>$r['verification_notes']]; $petEditPayload=['id'=>$r['id'],'name'=>$r['name'],'species'=>$r['species'],'breed'=>$r['breed'],'sex'=>$r['sex'],'birth_date'=>$r['birth_date'],'weight'=>$r['weight'],'color'=>$r['color'],'allergies'=>$r['allergies'],'critical_notes'=>$r['critical_notes'],'notes'=>$r['notes']];?>
            <article class="pet-review-card status-<?=e($r['verification_status'])?>" data-record-detail='<?=e(json_encode($petDetail))?>'>
                <div class="pet-main-info">
                    <?= pet_avatar_markup($r, 'pet-photo-wrap') ?>
                    <div class="pet-title-block">
                        <div class="pet-name-row">
                            <h4><?=e($r['name'])?></h4>
                            <?=badge($r['verification_status'])?>
                        </div>
                        <p class="pet-owner">Owner: <b><?=e($r['full_name'])?></b></p>
                        <div class="pet-detail-chips">
                            <span><?=e($r['species'])?></span>
                            <span><?=e($r['breed'])?></span>
                            <span><?=e($r['sex'])?></span>
                            <span><?=e(pet_age($r['birth_date']))?></span>
                            <span><?=e($r['weight'])?> kg</span>
                        </div>
                        <div class="pet-mini-details pet-profile-mini-details">
                            <p><b>Color:</b> <?=e($r['color'] ?: 'Not recorded')?></p>
                            <p><b>Allergies:</b> <?=e($r['allergies'] ?: 'None recorded')?></p>
                            <p><b>Critical notes:</b> <?=e($r['critical_notes'] ?: 'None recorded')?></p>
                        </div>
                    </div>
                </div>

                <footer class="pet-review-actions">
                    <span class="pet-record-date">Added <?=date('M d, Y',strtotime($r['created_at']))?></span>
                    <div class="pet-profile-actions"><button class="button-secondary pet-profile-action" type="button" data-review-pet='<?=e(json_encode($petReviewPayload))?>'>Review profile</button><span hidden data-edit-pet='<?=e(json_encode($petEditPayload))?>' data-pet-id="<?=$r['id']?>"></span><?php if($r['verification_status']==='approved'):?><a class="button-secondary pet-profile-action pet-print-action" href="report_pet.php?pet_id=<?=$r['id']?>">Print / Save PDF</a><?php endif;?></div>
                </footer>
            </article>
        <?php endwhile;?>
        </div>
        <?= render_pagination($page,$perPage,$filteredPetTotal,['verification'=>$pet_filter,'q'=>$pet_search,'species'=>$species_filter,'sex'=>$sex_filter,'per_page'=>per_page_value($perPage),'_anchor'=>'adminPetView']) ?>
    </section>
</div>
<section class="calendar-dialog" id="petReviewDialog" aria-hidden="true"><div class="dialog-scrim" data-close-pet-review></div><div class="dialog-card pet-review-dialog"><header><div><span class="eyebrow">Pet profile review</span><h2 id="reviewPetTitle">Review pet</h2><p id="reviewPetOwner"></p></div><button class="icon-button" type="button" data-close-pet-review><?=ui_icon('x')?></button></header><div class="pet-review-detail-grid" id="reviewPetFacts"></div><form method="POST" class="form-stack"><?=csrf_field()?><input type="hidden" name="verify_pet" value="1"><input type="hidden" name="pet_id" id="reviewPetId"><label>Current verification status<select class="form-select" name="verification_status" id="reviewPetStatus"><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="in_person_confirmation">In-person confirmation</option></select></label><label>Administrator note<textarea class="form-control" name="verification_notes" id="reviewPetNotes" rows="3" placeholder="Reason, verification finding, or follow-up instruction"></textarea></label><div class="form-actions"><button class="button-secondary" type="button" id="reviewEditDetails"><?=ui_icon('edit')?>Edit full details</button><button class="button-primary" type="submit">Update verification</button></div></form></div></section>
<section class="calendar-dialog" id="petEditDialog" aria-hidden="true"><div class="dialog-scrim" data-close-pet-edit></div><div class="dialog-card pet-verified-update-dialog"><header><div><span class="eyebrow">Verified clinic update</span><h2>Edit pet details</h2><p>Changes are saved directly by the administrator and recorded in the audit log.</p></div><button class="icon-button" type="button" data-close-pet-edit><?=ui_icon('x')?></button></header><form method="POST" enctype="multipart/form-data" class="form-stack pet-verified-update-form"><?=csrf_field()?><input type="hidden" name="update_pet" value="1"><input type="hidden" name="pet_id" id="editPetId"><?php if($return_to!==''):?><input type="hidden" name="return_to" value="<?=e($return_to)?>"><?php endif;?><div class="form-grid-two"><label>Pet name<input class="form-control" name="name" id="editPetName" required></label><label>Animal type<select class="form-select" name="species" id="editPetSpecies" required><option>Dog</option><option>Cat</option><option>Other</option></select></label></div><div class="form-grid-two"><label>Breed<input class="form-control" name="breed" id="editPetBreed" required></label><label>Sex<select class="form-select" name="sex" id="editPetSex" required><option>Male</option><option>Female</option><option>Unknown</option></select></label></div><div class="form-grid-two"><label>Birth date<input class="form-control" type="date" name="birth_date" id="editPetBirth" required></label><label>Weight in kg<input class="form-control" type="number" step="0.01" min="0" name="weight" id="editPetWeight" required></label></div><label>Color / markings<input class="form-control" name="color" id="editPetColor" required></label><label>Allergies<input class="form-control" name="allergies" id="editPetAllergies" required><small>Direct administrator updates should reflect documented owner or veterinarian confirmation.</small></label><label>Critical notes<textarea class="form-control" name="critical_notes" id="editPetCritical" required></textarea></label><label>Care notes<textarea class="form-control" name="notes" id="editPetNotes" required></textarea></label><label>Replace pet photo<input class="form-control" type="file" name="pet_photo" accept="image/jpeg,image/png,image/webp"><small>Leave blank to keep the current photo.</small></label><div class="form-actions"><button class="button-secondary" type="button" data-close-pet-edit>Cancel</button><button class="button-primary" type="submit">Save verified changes</button></div></form></div></section>
<script>(()=>{const review=document.getElementById('petReviewDialog'),facts=document.getElementById('reviewPetFacts');let current=null;const closeReview=()=>{review.classList.remove('open');review.setAttribute('aria-hidden','true');document.body.classList.remove('overlay-open')};document.querySelectorAll('[data-review-pet]').forEach(button=>button.addEventListener('click',event=>{event.stopPropagation();current=JSON.parse(button.dataset.reviewPet);document.getElementById('reviewPetId').value=current.id;document.getElementById('reviewPetTitle').textContent=current.name;document.getElementById('reviewPetOwner').textContent='Owner: '+current.owner;document.getElementById('reviewPetStatus').value=current.verification_status;document.getElementById('reviewPetNotes').value=current.verification_notes||'';facts.innerHTML=[['Animal type',current.species],['Breed',current.breed],['Sex',current.sex],['Age',current.age],['Weight',current.weight+' kg'],['Color / markings',current.color],['Allergies',current.allergies],['Critical notes',current.critical_notes],['Care notes',current.notes]].map(([label,value])=>`<div><small>${label}</small><b>${String(value||'Not recorded').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}</b></div>`).join('');review.classList.add('open');review.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open')}));document.querySelectorAll('[data-close-pet-review]').forEach(button=>button.addEventListener('click',closeReview));document.getElementById('reviewEditDetails')?.addEventListener('click',()=>{if(!current)return;closeReview();document.querySelector(`[data-edit-pet][data-pet-id="${current.id}"]`)?.click()});})();</script>
<script>(()=>{const dialog=document.getElementById('petEditDialog');const open=data=>{document.getElementById('editPetId').value=data.id||'';document.getElementById('editPetName').value=data.name||'';document.getElementById('editPetSpecies').value=data.species||'Other';document.getElementById('editPetBreed').value=data.breed||'';document.getElementById('editPetSex').value=data.sex||'Unknown';document.getElementById('editPetBirth').value=data.birth_date||'';document.getElementById('editPetWeight').value=data.weight||'';document.getElementById('editPetColor').value=data.color||'';document.getElementById('editPetAllergies').value=data.allergies||'';document.getElementById('editPetCritical').value=data.critical_notes||'';document.getElementById('editPetNotes').value=data.notes||'';dialog.classList.add('open');dialog.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open')};document.querySelectorAll('[data-edit-pet]').forEach(button=>button.addEventListener('click',event=>{event.stopPropagation();open(JSON.parse(button.dataset.editPet))}));const params=new URLSearchParams(location.search),returnTo=params.get('return_to');document.querySelectorAll('[data-close-pet-edit]').forEach(button=>button.addEventListener('click',()=>{dialog.classList.remove('open');dialog.setAttribute('aria-hidden','true');document.body.classList.remove('overlay-open');if(returnTo&&!/^(?:[a-z]+:|\/\/)/i.test(returnTo)&&!returnTo.includes('..'))location.href=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'')+'/'+returnTo.replace(/^\/+/, '')}));<?php if($pet_focus_id):?>const focused=document.querySelector('[data-edit-pet][data-pet-id="<?=$pet_focus_id?>"]');if(focused)setTimeout(()=>focused.click(),80);<?php endif;?>})();</script>

<script>document.querySelectorAll('.admin-pets-page a[href*="report_pet.php"]').forEach(link=>link.addEventListener('click',event=>event.stopPropagation()));</script>
</main></div><?php include "../includes/footer.php"; ?>
