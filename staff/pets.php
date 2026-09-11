<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("staff");

$staffId = (int)current_user_id();
$canViewPrivateClientData = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = $_POST['action'] ?? 'create_pet';
    $required = ['owner_id','name','species','breed','sex','birth_date','weight','color','allergies','critical_notes','notes'];
    foreach ($required as $field) {
        if (trim((string)($_POST[$field] ?? '')) === '') {
            flash('error', 'Please complete every required pet field.');
            redirect_to('staff/pets.php');
        }
    }

    $ownerId = (int)$_POST['owner_id'];
    $species = trim($_POST['species']);
    if($species==='Other')$species=trim((string)($_POST['species_other']??''));
    $sex = trim($_POST['sex']);
    $birthDate = trim($_POST['birth_date']);
    $weight = (float)$_POST['weight'];
    if ($species==='' || !in_array($sex, ['Male','Female','Unknown'], true)) {
        flash('error', 'Choose a valid animal type and sex.');
        redirect_to('staff/pets.php');
    }
    if ($weight < 0 || !$birthDate || strtotime($birthDate) > strtotime(date('Y-m-d'))) {
        flash('error', 'Enter a valid birth date and weight.');
        redirect_to('staff/pets.php');
    }
    $ownerCheck = $conn->prepare("SELECT id FROM users WHERE id=? AND role='client' AND status IN ('approved','active') LIMIT 1");
    $ownerCheck->bind_param('i', $ownerId);
    $ownerCheck->execute();
    if (!$ownerCheck->get_result()->num_rows) {
        flash('error', 'Choose an approved or active client account.');
        redirect_to('staff/pets.php');
    }

    $upload = upload_image_file($_FILES['pet_photo'] ?? null, 'uploads/pets', 'pet');
    if (!$upload['ok']) {
        flash('error', $upload['error']);
        redirect_to('staff/pets.php');
    }
    $photo = $upload['path'];
    $status = 'pending';
    $verificationNotes = 'Submitted by clinic staff. Waiting for admin verification.';
    $stmt = $conn->prepare("INSERT INTO pets(owner_id,name,species,breed,sex,birth_date,weight,color,allergies,critical_notes,notes,pet_photo,verification_status,verification_notes,last_updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param(
        "isssssdsssssssi",
        $ownerId,
        $_POST['name'],
        $species,
        $_POST['breed'],
        $sex,
        $birthDate,
        $weight,
        $_POST['color'],
        $_POST['allergies'],
        $_POST['critical_notes'],
        $_POST['notes'],
        $photo,
        $status,
        $verificationNotes,
        $staffId
    );
    if ($stmt->execute()) {
        $petId = $conn->insert_id;
        log_action($conn, 'Staff submitted pet profile for admin verification', 'pet', $petId, 'Pet added by staff and pending admin verification.');
        notify_user($conn, $ownerId, 'Pet Profile Submitted', 'Clinic staff submitted a pet profile for ' . $_POST['name'] . '. Admin verification is required before appointment booking.', 'record', null);
        $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND status IN ('approved','active')");
        while ($admin = $admins->fetch_assoc()) {
            notify_user($conn, (int)$admin['id'], 'Pet Profile Awaiting Review', $_POST['name'] . ' was encoded by clinic staff and needs verification.', 'record', 'admin/pets.php?pet_id=' . $petId);
        }
        flash('success', 'Pet profile submitted for admin verification.');
    } else {
        flash('error', 'The pet profile could not be saved. Please try again.');
    }
    redirect_to('staff/pets.php');
}

$status = strtolower($_GET['status'] ?? 'all');
if (!in_array($status, ['all','approved','pending','rejected','in_person_confirmation'], true)) $status = 'all';
$q = trim($_GET['q'] ?? '');
$species_filter = trim($_GET['species'] ?? '');
$sex_filter = trim($_GET['sex'] ?? '');
if (!in_array($sex_filter, ['', 'Male', 'Female', 'Unknown'], true)) $sex_filter = '';
[$page,$perPage,$offset] = pagination_values(6,24,[6,12,18,24,'full']);
$where = [];
if ($status !== 'all') $where[] = "p.verification_status='" . $conn->real_escape_string($status) . "'";
if ($species_filter !== '') $where[] = "p.species='" . $conn->real_escape_string($species_filter) . "'";
if ($sex_filter !== '') $where[] = "p.sex='" . $conn->real_escape_string($sex_filter) . "'";
if ($q !== '') {
    $safe = $conn->real_escape_string($q);
    $privateSearch = $canViewPrivateClientData ? " OR u.email LIKE '%$safe%'" : '';
    $where[] = "(p.name LIKE '%$safe%' OR p.species LIKE '%$safe%' OR p.breed LIKE '%$safe%' OR u.full_name LIKE '%$safe%'$privateSearch)";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$totalFiltered = (int)$conn->query("SELECT COUNT(*) c FROM pets p JOIN users u ON p.owner_id=u.id $whereSql")->fetch_assoc()['c'];
$staffOwnerFields=$canViewPrivateClientData?'u.email owner_email,u.phone owner_phone':'NULL AS owner_email,NULL AS owner_phone';
$rows = $conn->query("SELECT p.*,u.full_name owner_name,$staffOwnerFields FROM pets p JOIN users u ON p.owner_id=u.id $whereSql ORDER BY FIELD(p.verification_status,'pending','in_person_confirmation','rejected','approved'),p.created_at DESC LIMIT $perPage OFFSET $offset");
$counts = $conn->query("SELECT COUNT(*) total,SUM(verification_status='approved') approved,SUM(verification_status='pending') pending,SUM(verification_status='rejected') rejected,SUM(verification_status='in_person_confirmation') in_person_confirmation FROM pets")->fetch_assoc();

$title = "Pet Profiles";
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/staff_sidebar.php"; ?>
<main class="content staff-pets-page" id="mainContent">
<header class="page-heading">
    <div><span class="eyebrow">Pet encoding</span><h1>Pet Profiles</h1></div>
    <a class="button-secondary" href="<?=app_url('staff/appointments.php')?>"><?=ui_icon('calendar')?>Appointments</a>
</header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>

<div class="pet-admin-shell staff-pet-admin-shell">
<aside class="pet-admin-form-card">
    <div class="pet-section-heading pet-section-heading-inline"><span class="pet-icon"><?=ui_icon('plus')?></span><h3>Add Walk-In Pet</h3></div>
    <form method="POST" enctype="multipart/form-data" class="pet-walkin-form">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="create_pet">
        <label class="form-label">Owner</label><select class="form-select" name="owner_id" required><option value="">Select owner</option><?php $ownerPrivateField=$canViewPrivateClientData?'email':'NULL AS email';$owners=$conn->query("SELECT id,full_name,$ownerPrivateField FROM users WHERE role='client' AND status IN ('approved','active') ORDER BY full_name");while($owner=$owners->fetch_assoc()):?><option value="<?=$owner['id']?>"><?=e($owner['full_name'].($canViewPrivateClientData?' · '.$owner['email']:''))?></option><?php endwhile;?></select>
        <div class="pet-two-col"><div><label class="form-label">Pet name</label><input class="form-control" name="name" placeholder="Pet name" required></div><div><label class="form-label">Type</label><select class="form-select" name="species" id="staffPetSpecies" required><option value="">Pet type</option><option>Dog</option><option>Cat</option><option>Other</option></select><input class="form-control" name="species_other" id="staffPetSpeciesOther" placeholder="Specify animal type" hidden></div></div>
        <div class="pet-two-col"><div><label class="form-label">Breed</label><input class="form-control" name="breed" id="staffPetBreed" list="staffBreedSuggestions" placeholder="Breed" required></div><div><label class="form-label">Sex</label><select class="form-select" name="sex" required><option value="">Sex</option><option>Male</option><option>Female</option><option>Unknown</option></select></div></div>
        <div class="pet-two-col"><div><label class="form-label">Birth date</label><input class="form-control" type="date" name="birth_date" max="<?=date('Y-m-d')?>" required></div><div><label class="form-label">Weight</label><input class="form-control" type="number" step="0.01" min="0" name="weight" placeholder="kg" required></div></div>
        <label class="form-label">Color / markings</label><input class="form-control" name="color" list="staffColorSuggestions" placeholder="Color / markings" required>
        <label class="form-label">Allergies</label><input class="form-control" name="allergies" list="staffAllergySuggestions" placeholder="Allergies or none" required>
        <label class="form-label">Pet picture <span class="optional-label">optional</span></label><input class="form-control" type="file" name="pet_photo" accept="image/jpeg,image/png,image/webp"><small class="form-help">JPG, PNG, or WEBP up to 3 MB.</small>
        <label class="form-label">Critical notes</label><input class="form-control" name="critical_notes" list="staffCriticalSuggestions" placeholder="Critical notes or none" required>
        <label class="form-label">Owner notes</label><input class="form-control" name="notes" list="staffNotesSuggestions" placeholder="Behavior or care notes" required>
        <button class="btn btn-primary w-100 mt-2" type="submit">Submit for Verification</button>
    <datalist id="staffBreedSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT breed value FROM pets WHERE TRIM(COALESCE(breed,''))<>'' ORDER BY id DESC LIMIT 80");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="staffColorSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT color value FROM pets WHERE TRIM(COALESCE(color,''))<>'' ORDER BY id DESC LIMIT 50");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="staffAllergySuggestions"><?php $suggest=$conn->query("SELECT DISTINCT allergies value FROM pets WHERE TRIM(COALESCE(allergies,''))<>'' ORDER BY id DESC LIMIT 50");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="staffCriticalSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT critical_notes value FROM pets WHERE TRIM(COALESCE(critical_notes,''))<>'' ORDER BY id DESC LIMIT 50");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist><datalist id="staffNotesSuggestions"><?php $suggest=$conn->query("SELECT DISTINCT notes value FROM pets WHERE TRIM(COALESCE(notes,''))<>'' ORDER BY id DESC LIMIT 50");while($x=$suggest->fetch_assoc()):?><option value="<?=e($x['value'])?>"><?php endwhile;?></datalist></form>
<script>(()=>{const species=document.getElementById('staffPetSpecies'),other=document.getElementById('staffPetSpeciesOther'),breed=document.getElementById('staffPetBreed');const known={Dog:['Aspin','Shih Tzu','Pomeranian','Labrador Retriever','Golden Retriever','Beagle','Chihuahua','Poodle','Siberian Husky'],Cat:['Puspin','Persian','Siamese','British Shorthair','Maine Coon']};const list=document.getElementById('staffBreedSuggestions');species?.addEventListener('change',()=>{const isOther=species.value==='Other';other.hidden=!isOther;other.required=isOther;if(!isOther)other.value='';if(list&&known[species.value]){const saved=[...list.options].map(o=>o.value);const values=[...new Set([...known[species.value],...saved])];list.replaceChildren(...values.map(value=>{const option=document.createElement('option');option.value=value;return option}))}});})();</script>
</aside>

<section class="pet-admin-list-card">
    <div class="pet-list-header"><div><h3>All Pet Profiles</h3></div></div>
    <div class="pet-category-toolbar"><div class="filter-tabs pet-filter-pills" aria-label="Pet status filters">
        <?php foreach(['all'=>'All','pending'=>'Pending','in_person_confirmation'=>'In-person','approved'=>'Approved','rejected'=>'Rejected'] as $key=>$label):$count=$key==='all'?($counts['total']??0):($counts[$key]??0);$query=array_filter(['status'=>$key,'q'=>$q,'species'=>$species_filter,'sex'=>$sex_filter,'per_page'=>per_page_value($perPage)],fn($v)=>$v!==''&&$v!==null);?>
        <a class="filter-tab status-<?=e($key)?> <?=$status===$key?'active':''?>" href="?<?=e(http_build_query($query))?>"><?=e($label)?><b><?=intval($count)?></b></a>
        <?php endforeach;?>
    </div><form class="pet-category-controls" method="GET" action="pets.php"><input type="hidden" name="status" value="<?=e($status)?>"><input type="hidden" name="q" value="<?=e($q)?>"><input type="hidden" name="species" value="<?=e($species_filter)?>"><input type="hidden" name="sex" value="<?=e($sex_filter)?>"><label class="entries-select">Show<select class="unified-show-select" name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[6,12,18,24,'full'])?></select></label><div class="view-toggle" data-view-toggle data-target="#staffPetCollection" data-key="staff-pets-unified" data-default="grid"><button type="button" data-view="list" aria-label="List view"><?=ui_icon('list')?></button><button type="button" data-view="grid" aria-label="Grid view"><?=ui_icon('grid')?></button></div></form></div>
<form class="surface-card data-search-bar pet-search-filters" method="GET" action="pets.php">
        <input type="hidden" name="status" value="<?=e($status)?>">
        <input type="search" name="q" value="<?=e($q)?>" placeholder="Search pet, owner, or breed">
        <select class="form-select" name="species"><option value="">All animal types</option><?php $speciesRows=$conn->query("SELECT DISTINCT species FROM pets WHERE species<>'' ORDER BY species");while($speciesRow=$speciesRows->fetch_assoc()):?><option value="<?=e($speciesRow['species'])?>" <?=$species_filter===$speciesRow['species']?'selected':''?>><?=e($speciesRow['species'])?></option><?php endwhile;?></select>
        <button class="button-primary" type="submit">Search</button><a class="button-secondary" href="?<?=e(http_build_query(['status'=>$status,'per_page'=>per_page_value($perPage)]))?>">Clear</a>
    </form>
<div class="pet-card-list view-grid" id="staffPetCollection">
    <?php if(!$rows->num_rows):?><div class="empty-state"><span><?=ui_icon('paw')?></span><h3>No pet profiles found</h3><p>Try another filter or search term.</p></div><?php endif;?>
    <?php while($pet=$rows->fetch_assoc()): $petDetail=['title'=>$pet['name'],'eyebrow'=>'Pet profile','fields'=>['Owner'=>$pet['owner_name'],'Animal type'=>$pet['species'],'Breed'=>$pet['breed'],'Sex'=>$pet['sex'],'Age'=>pet_age($pet['birth_date']),'Weight'=>$pet['weight'].' kg','Color / markings'=>$pet['color']?:'Not recorded','Allergies'=>$pet['allergies']?:'None recorded','Critical notes'=>$pet['critical_notes']?:'None recorded','Care notes'=>$pet['notes']?:'None recorded','Verification status'=>ucwords(str_replace('_',' ',$pet['verification_status'])),'Verification note'=>$pet['verification_notes']?:'Waiting for clinic review.','Submitted'=>date('M d, Y',strtotime($pet['created_at']))]];?>
        <article class="pet-review-card status-<?=e($pet['verification_status'])?>">
            <div class="pet-main-info"><?=pet_avatar_markup($pet,'pet-photo-wrap')?><div class="pet-title-block"><div class="pet-name-row"><h4><?=e($pet['name'])?></h4><?=badge($pet['verification_status'])?></div><p class="pet-owner">Owner: <b><?=e($pet['owner_name'])?></b></p><div class="pet-detail-chips"><span><?=e($pet['species'])?></span><span><?=e($pet['breed'])?></span><span><?=e($pet['sex'])?></span><span><?=e(pet_age($pet['birth_date']))?></span><span><?=e($pet['weight'])?> kg</span></div><div class="pet-mini-details"><p><b>Color:</b> <?=e($pet['color']?:'Not recorded')?></p><p><b>Allergies:</b> <?=e($pet['allergies']?:'None recorded')?></p><p><b>Critical notes:</b> <?=e($pet['critical_notes']?:'None recorded')?></p></div></div></div>
            <footer class="pet-review-actions"><span class="pet-record-date">Added <?=date('M d, Y',strtotime($pet['created_at']))?></span><div><button class="button-secondary" type="button" data-record-detail='<?=e(json_encode($petDetail))?>'>View details</button><a class="button-secondary" href="<?=app_url('staff/appointments.php?q='.urlencode($pet['name']))?>">View schedule</a></div></footer>
        </article>
    <?php endwhile;?>
    </div>
    <?=render_pagination($page,$perPage,$totalFiltered,['status'=>$status,'q'=>$q,'species'=>$species_filter,'sex'=>$sex_filter,'per_page'=>per_page_value($perPage),'_anchor'=>'staffPetCollection'])?>
</section>
</div>
</main></div>
<?php include "../includes/footer.php"; ?>
