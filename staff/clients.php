<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("staff");
$canViewPrivateClientData = true;

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf_or_fail();
    $fullName=trim($_POST['full_name'] ?? '');
    $email=strtolower(trim($_POST['email'] ?? ''));
    $phone=trim($_POST['phone'] ?? '');
    $address=trim($_POST['address'] ?? '');
    $password=(string)($_POST['password'] ?? '');
    $passwordErrors=password_policy_errors($password);
    if($fullName==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)){
        flash('error','Enter a full name and valid email address.');
        redirect_to('staff/clients.php');
    }
    if($passwordErrors){
        flash('error',implode(' ',$passwordErrors));
        redirect_to('staff/clients.php');
    }
    $hash=password_hash($password, PASSWORD_DEFAULT);
    $role='client';
    $source='staff_created';
    $created_by=current_user_id();
    $status='pending';
    $stmt=$conn->prepare("INSERT INTO users(full_name,email,phone,address,password,role,account_source,created_by,status) VALUES(?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("sssssssis",$fullName,$email,$phone,$address,$hash,$role,$source,$created_by,$status);
    if(!$stmt->execute()){
        flash('error',$conn->errno===1062?'That email address is already registered.':'The client account could not be created.');
        redirect_to('staff/clients.php');
    }
    $new_id=$conn->insert_id;
    $admins=$conn->query("SELECT id FROM users WHERE role='admin' AND status='active'");
    while($admin=$admins->fetch_assoc()) notify_user($conn,(int)$admin['id'],'Client Account Review Needed',$fullName.' was created by clinic staff and is waiting for approval.','system','admin/clients.php?client_id='.$new_id);
    log_action($conn,'Staff created client account','user',$new_id,'Clinic-assisted account is pending administrator approval and OTP verification.');
    flash('success','Client account created and sent for administrator approval. The client must verify the emailed OTP after approval.');
    redirect_to('staff/clients.php');
}

$filter=strtolower($_GET['filter'] ?? 'all');
$allowedFilters=['all','pending','approved','active','inactive','rejected'];
if(!in_array($filter,$allowedFilters,true)) $filter='all';
$q=trim($_GET['client_search'] ?? ($_GET['q'] ?? ''));
$initialView=(($_GET['view'] ?? '')==='grid')?'grid':'list';
[$page,$perPage,$offset]=pagination_values(4,16,[4,8,12,16,'full']);
$where=["u.role='client'","u.deleted_at IS NULL"];
if($filter!=='all') $where[]="u.status='".$conn->real_escape_string($filter)."'";
if($q!==''){
    $safe=$conn->real_escape_string($q);
    $where[]="(u.full_name LIKE '%$safe%' OR u.email LIKE '%$safe%' OR u.phone LIKE '%$safe%' OR u.address LIKE '%$safe%' OR u.account_source LIKE '%$safe%')";
}
$whereSql='WHERE '.implode(' AND ',$where);
$total=(int)$conn->query("SELECT COUNT(*) c FROM users u $whereSql")->fetch_assoc()['c'];
$rows=$conn->query("SELECT u.id,u.full_name,u.email,u.phone,u.address,u.profile_photo,u.account_source,u.status,u.created_at,u.created_by,u.deleted_at,creator.full_name created_by_name,COALESCE(pc.pet_count,0) pet_count FROM users u LEFT JOIN users creator ON u.created_by=creator.id LEFT JOIN (SELECT owner_id,COUNT(*) pet_count FROM pets GROUP BY owner_id) pc ON pc.owner_id=u.id $whereSql ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset")->fetch_all(MYSQLI_ASSOC);
$counts=$conn->query("SELECT COUNT(*) total,SUM(status='pending' AND deleted_at IS NULL) pending,SUM(status='approved' AND deleted_at IS NULL) approved,SUM(status='active' AND deleted_at IS NULL) active,SUM(status='inactive' AND deleted_at IS NULL) inactive,SUM(status='rejected' AND deleted_at IS NULL) rejected FROM users WHERE role='client'")->fetch_assoc();

$title="Clients";
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/staff_sidebar.php"; ?><main class="content staff-clients-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Client assistance</span><h1>Client Accounts</h1></div><button class="button-primary" type="button" data-open-staff-client-create><?=ui_icon('plus')?>Create client</button></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>

<section class="surface-card data-toolbar client-toolbar"><div class="filter-tabs">
<?php foreach(['all'=>'All','pending'=>'Pending','approved'=>'Approved','active'=>'Active','inactive'=>'Inactive','rejected'=>'Rejected'] as $key=>$label):?>
<a class="filter-tab filter-<?=$key?> <?=$filter===$key?'active':''?>" href="?filter=<?=$key?>&q=<?=urlencode($q)?>&per_page=<?=e(per_page_value($perPage))?>"><?=e($label)?><b><?=intval($counts[$key==='all'?'total':$key]??0)?></b></a>
<?php endforeach;?>
</div><form class="client-category-controls" method="GET"><input type="hidden" name="filter" value="<?=e($filter)?>"><input type="hidden" name="q" value="<?=e($q)?>"><label class="entries-select">Show<select class="unified-show-select" name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[4,8,12,16,'full'])?></select></label><div class="view-toggle" data-view-toggle data-target="#clientCollection" data-key="staff-clients" data-default="<?=e($initialView)?>"><button type="button" data-view="list" aria-label="List view"><?=ui_icon('list')?></button><button type="button" data-view="grid" aria-label="Grid view"><?=ui_icon('grid')?></button></div></form></section>
<form class="surface-card data-search-bar staff-client-search-row" method="GET" autocomplete="off"><input type="hidden" name="filter" value="<?=e($filter)?>"><input type="hidden" name="view" value="<?=e($initialView)?>"><input type="search" value="<?=e($q)?>" placeholder="Search clients" autocomplete="new-password" aria-autocomplete="none" autocapitalize="none" autocorrect="off" spellcheck="false" data-client-search-input data-lpignore="true" data-1p-ignore="true" data-form-type="other" inputmode="search" readonly><input type="hidden" name="client_search" value="<?=e($q)?>" data-client-search-value><button class="button-primary" type="submit">Search</button><a class="button-secondary" href="?filter=<?=e($filter)?>&per_page=<?=e(per_page_value($perPage))?>">Clear</a></form>

<div class="entity-collection view-<?=e($initialView)?>" id="clientCollection">
<?php if(!$rows):?><div class="empty-state surface-card"><span><?=ui_icon('users')?></span><h2>No client accounts found</h2><p>Try another filter or search term.</p></div><?php endif;?>
<?php foreach($rows as $r): $detail=['title'=>$r['full_name'],'eyebrow'=>'Client account','fields'=>['Email'=>$canViewPrivateClientData?$r['email']:'Restricted','Phone'=>$canViewPrivateClientData?($r['phone']?:'Not provided'):'Restricted','Address'=>$canViewPrivateClientData?($r['address']?:'Not provided'):'Restricted','Linked pets'=>(int)$r['pet_count'],'Account source'=>ucwords(str_replace('_',' ',$r['account_source']?:'self_registered')),'Account status'=>ucwords(str_replace('_',' ',$r['status'])),'Created by'=>$r['created_by_name']?:'Owner/system','Created'=>date('M d, Y',strtotime($r['created_at']))]];?>
<article class="entity-card surface-card"><header><?=user_avatar_markup($r,'entity-avatar')?><div><div class="title-with-status"><h2><?=e($r['full_name'])?></h2><?=badge($r['status'])?></div><p><?=e($canViewPrivateClientData?$r['email']:'Private contact restricted')?></p></div></header><div class="entity-facts"><span><small>Phone</small><b><?=e($canViewPrivateClientData?($r['phone']?:'Not provided'):'Restricted')?></b></span><span><small>Linked pets</small><b><?=intval($r['pet_count'])?></b></span><span class="entity-source-fact"><small>Source</small><b><?=e(ucwords(str_replace('_',' ',$r['account_source']?:'self registered')))?></b></span><span><small>Created</small><b><?=date('M d, Y',strtotime($r['created_at']))?></b></span></div><div class="entity-address"><b>Address</b><p><?=e($canViewPrivateClientData?($r['address']?:'No address saved'):'Private address restricted')?></p></div><footer><small><?=e($r['created_by_name']?'Created by '.$r['created_by_name']:'Owner/system account')?></small><button class="button-secondary" type="button" data-record-detail='<?=e(json_encode($detail))?>'>View details</button></footer></article>
<?php endforeach;?>
</div>
<?=render_pagination($page,$perPage,$total,['filter'=>$filter,'q'=>$q,'view'=>$initialView,'per_page'=>per_page_value($perPage),'_anchor'=>'clientCollection'])?>

<section class="calendar-dialog" id="staffClientCreateDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="staffClientCreateTitle">
    <div class="dialog-scrim" data-close-staff-client-create aria-hidden="true"></div>
    <div class="dialog-card client-create-dialog">
        <header>
            <div><span class="eyebrow">Client account</span><h2 id="staffClientCreateTitle" tabindex="-1">Create client</h2><p>The account will be sent for administrator approval.</p></div>
            <button class="icon-button" type="button" data-close-staff-client-create aria-label="Close create client"><?=ui_icon('x')?></button>
        </header>
        <form method="POST" class="form-stack client-create-form">
            <?=csrf_field()?>
            <div class="client-create-fields">
                <label>Full name<input class="form-control" name="full_name" autocomplete="name" required></label>
                <label>Email<input class="form-control" type="email" name="email" autocomplete="email" required></label>
                <label>Phone<input class="form-control" type="tel" name="phone" autocomplete="tel"></label>
                
                <label>Address<input class="form-control" name="address" autocomplete="street-address"></label>
                
                <label>Temporary password<input class="form-control" type="password" name="password" autocomplete="new-password" data-password-input required minlength="10" placeholder="Create a temporary password" aria-describedby="staffClientCreatePasswordRules"></label>
                <div class="password-requirements" id="staffClientCreatePasswordRules" data-password-requirements>
                    <span data-rule="length">At least 10 characters</span><span data-rule="upper">One uppercase letter</span><span data-rule="lower">One lowercase letter</span><span data-rule="number">One number</span><span data-rule="symbol">One symbol</span>
                </div>
            </div>
            <div class="form-actions">
                <button class="button-secondary" type="button" data-close-staff-client-create>Cancel</button>
                <button class="button-primary" type="submit">Create pending client</button>
            </div>
        </form>
    </div>
</section>
<script>(()=>{const dialog=document.getElementById('staffClientCreateDialog');const open=()=>{dialog.classList.add('open');dialog.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open');dialog.querySelector('.client-create-fields').scrollTop=0;document.getElementById('staffClientCreateTitle').focus()};const close=()=>{dialog.classList.remove('open');dialog.setAttribute('aria-hidden','true');document.body.classList.remove('overlay-open')};document.querySelector('[data-open-staff-client-create]')?.addEventListener('click',open);document.querySelectorAll('[data-close-staff-client-create]').forEach(button=>button.addEventListener('click',close));})();</script>
<script>(()=>{const form=document.querySelector('.staff-client-search-row'),visible=form?.querySelector('[data-client-search-input]'),hidden=form?.querySelector('[data-client-search-value]');if(!form||!visible||!hidden)return;visible.removeAttribute('list');visible.setAttribute('autocomplete','new-password');visible.setAttribute('aria-autocomplete','none');visible.addEventListener('focus',()=>{setTimeout(()=>{visible.readOnly=false;},100)},{once:true});form.addEventListener('submit',()=>{hidden.value=visible.value.trim()});})();</script>
</main></div><?php include "../includes/footer.php"; ?>
