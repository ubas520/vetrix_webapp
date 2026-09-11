<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role('admin');
$roles=['staff'=>'Clinic Staff','veterinarian'=>'Veterinarian','admin'=>'Administrator'];
$roleFilter=$_GET['role']??'staff';if(!isset($roles[$roleFilter]))$roleFilter='staff';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf_or_fail();
    $action=$_POST['action']??'';
    $role=$_POST['role']??$roleFilter;if(!isset($roles[$role]))$role=$roleFilter;
    if($action==='create_user'){
        $name=trim($_POST['full_name']??'');$email=strtolower(trim($_POST['email']??''));$phone=trim($_POST['phone']??'');$address=trim($_POST['address']??'');$password=(string)($_POST['password']??'');$photoPath=null;if(!empty($_FILES['profile_photo']['name'])){$upload=upload_image_file($_FILES['profile_photo'],'uploads/profiles','clinic_user_new');if(!$upload['ok']){flash('error',$upload['error']);redirect_to('admin/users.php?role='.$roleFilter);}$photoPath=$upload['path'];}
        if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)){flash('error','Enter a full name and valid email.');redirect_to('admin/users.php?role='.$roleFilter);}
        if($errors=password_policy_errors($password)){flash('error',implode(' ',$errors));redirect_to('admin/users.php?role='.$roleFilter);}
        $hash=password_hash($password,PASSWORD_DEFAULT);$admin=(int)current_user_id();$source='admin_created';$status='active';$now=date('Y-m-d H:i:s');
        $stmt=$conn->prepare("INSERT INTO users(full_name,email,phone,address,password,role,account_source,created_by,status,approved_by,approved_at,otp_verified_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssssisiss',$name,$email,$phone,$address,$hash,$role,$source,$admin,$status,$admin,$now,$now);
        if(!$stmt->execute()){flash('error',$conn->errno===1062?'That email address is already registered.':'The account could not be created.');redirect_to('admin/users.php?role='.$roleFilter);}$newId=(int)$stmt->insert_id;if($photoPath){$photoStmt=$conn->prepare("UPDATE users SET profile_photo=? WHERE id=?");$photoStmt->bind_param('si',$photoPath,$newId);$photoStmt->execute();}
        log_action($conn,'Created clinic user','user',$newId,'Role: '.$role);flash('success',$roles[$role].' account created.');$roleFilter=$role;
    } else {
        $id=(int)($_POST['id']??0);
        $recordStmt=$conn->prepare("SELECT * FROM users WHERE id=? AND role IN ('staff','veterinarian','admin') LIMIT 1");$recordStmt->bind_param('i',$id);$recordStmt->execute();$record=$recordStmt->get_result()->fetch_assoc();
        if(!$record){flash('error','Clinic account not found.');redirect_to('admin/users.php?role='.$roleFilter);}
        if($id===(int)current_user_id() && in_array($action,['deactivate_user','delete_user'],true)){flash('error','You cannot deactivate or delete your current administrator account.');redirect_to('admin/users.php?role=admin');}
        if($action==='update_user'){
            if($record['status']!=='active' || !empty($record['deleted_at'])){flash('error','Only an active clinic account can be edited.');redirect_to('admin/users.php?role='.$roleFilter);}
            $name=trim($_POST['full_name']??'');$email=strtolower(trim($_POST['email']??''));$phone=trim($_POST['phone']??'');$address=trim($_POST['address']??'');$photoPath=null;if(!empty($_FILES['profile_photo']['name'])){$upload=upload_image_file($_FILES['profile_photo'],'uploads/profiles','clinic_user_'.$id);if(!$upload['ok']){flash('error',$upload['error']);redirect_to('admin/users.php?role='.$roleFilter);}$photoPath=$upload['path'];}
            if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)){flash('error','Enter a full name and valid email.');redirect_to('admin/users.php?role='.$roleFilter);}
            if($id===(int)current_user_id()&&$role!=='admin'){flash('error','You cannot change the role of your current administrator account.');redirect_to('admin/users.php?role=admin');}
            $stmt=$conn->prepare("UPDATE users SET full_name=?,email=?,phone=?,address=?,role=? WHERE id=? AND role IN ('staff','veterinarian','admin')");$stmt->bind_param('sssssi',$name,$email,$phone,$address,$role,$id);
            if(!$stmt->execute()){flash('error',$conn->errno===1062?'That email address is already used.':'The account could not be updated.');redirect_to('admin/users.php?role='.$roleFilter);}if($photoPath){$photoStmt=$conn->prepare("UPDATE users SET profile_photo=? WHERE id=?");$photoStmt->bind_param('si',$photoPath,$id);$photoStmt->execute();}
            log_action($conn,'Updated clinic user','user',$id,'Contact details, role, or photo updated.');flash('success','Clinic account updated.');$roleFilter=$role;
        }elseif($action==='deactivate_user'){
            if($record['status']!=='active' || !empty($record['deleted_at'])){flash('error','Only an active clinic account can be deactivated.');redirect_to('admin/users.php?role='.$roleFilter);}
            $stmt=$conn->prepare("UPDATE users SET status='inactive' WHERE id=? AND status='active' AND deleted_at IS NULL");$stmt->bind_param('i',$id);$stmt->execute();log_action($conn,'Deactivated clinic user','user',$id,'Access disabled.');flash('success','Clinic account deactivated.');
        }elseif($action==='restore_user'){
            if($record['status']!=='inactive' || !empty($record['deleted_at'])){
                flash('error','Only a deactivated account can be restored.');
            }else{
                $stmt=$conn->prepare("UPDATE users SET status='active' WHERE id=? AND status='inactive' AND deleted_at IS NULL");$stmt->bind_param('i',$id);$stmt->execute();log_action($conn,'Restored clinic user','user',$id,'Deactivated account restored and activated.');flash('success','Clinic account restored.');
            }
        }elseif($action==='delete_user'){
            if($record['status']!=='active' || !empty($record['deleted_at'])){flash('error','Only an active clinic account can be deleted.');redirect_to('admin/users.php?role='.$roleFilter);}
            $stmt=$conn->prepare("UPDATE users SET status='inactive',deleted_at=NOW() WHERE id=? AND status='active' AND deleted_at IS NULL");$stmt->bind_param('i',$id);$stmt->execute();log_action($conn,'Deleted clinic user','user',$id,'Account access removed while linked records were retained.');flash('success','Clinic account deleted.');
        }
    }
    redirect_to('admin/users.php?role='.urlencode($roleFilter));
}

$q=trim($_GET['q']??'');$statusFilter=$_GET['status']??'all';if(!in_array($statusFilter,['all','active','inactive','deleted'],true))$statusFilter='all';[$page,$perPage,$offset]=pagination_values(4,16,[4,8,12,16,'full']);
$where=['u.role=?'];$types='s';$params=[$roleFilter];
if($statusFilter==='deleted')$where[]='u.deleted_at IS NOT NULL';else{$where[]='u.deleted_at IS NULL';if($statusFilter!=='all'){$where[]='u.status=?';$types.='s';$params[]=$statusFilter;}}
if($q!==''){$like='%'.$q.'%';$where[]='(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.address LIKE ?)';$types.='ssss';array_push($params,$like,$like,$like,$like);}
$whereSql='WHERE '.implode(' AND ',$where);
$count=$conn->prepare("SELECT COUNT(*) c FROM users u $whereSql");$count->bind_param($types,...$params);$count->execute();$total=(int)$count->get_result()->fetch_assoc()['c'];
$stmt=$conn->prepare("SELECT u.*,creator.full_name created_by_name FROM users u LEFT JOIN users creator ON u.created_by=creator.id $whereSql ORDER BY u.deleted_at IS NOT NULL,FIELD(u.status,'active','inactive'),u.full_name LIMIT $perPage OFFSET $offset");$stmt->bind_param($types,...$params);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$roleCounts=[];$rc=$conn->query("SELECT role,COUNT(*) c FROM users WHERE role IN ('staff','veterinarian','admin') AND deleted_at IS NULL GROUP BY role");while($r=$rc->fetch_assoc())$roleCounts[$r['role']]=$r['c'];
$statusCounts=$conn->query("SELECT SUM(deleted_at IS NULL AND status='active') active,SUM(deleted_at IS NULL AND status='inactive') inactive,SUM(deleted_at IS NOT NULL) deleted FROM users WHERE role='".$conn->real_escape_string($roleFilter)."'")->fetch_assoc();
$title=$roles[$roleFilter].' Accounts';include "../includes/header.php";include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/admin_sidebar.php";?><main class="content admin-users-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Clinic users</span><h1><?=e($roles[$roleFilter])?> Accounts</h1></div><button class="button-primary" type="button" data-open-user-create><?=ui_icon('plus')?>Create <?=e($roles[$roleFilter])?></button></header>
<?php if($m=flash('success')):?><div class="alert alert-success"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger"><?=e($m)?></div><?php endif;?>
<?php include '../includes/admin_password_reset_requests.php'; ?>
<section class="surface-card data-toolbar users-toolbar"><div class="role-filter-tabs"><?php foreach($roles as $key=>$label):?><a class="role-filter-tab role-<?=e($key)?> <?=$roleFilter===$key?'active':''?>" href="?role=<?=$key?>&per_page=<?=e(per_page_value($perPage))?>"><?=e($label)?><b><?=intval($roleCounts[$key]??0)?></b></a><?php endforeach;?></div><div class="users-status-row"><div class="filter-tabs"><?php foreach(['all'=>'All','active'=>'Active','inactive'=>'Inactive','deleted'=>'Deleted'] as $key=>$label):?><a class="filter-tab filter-<?=$key?> <?=$statusFilter===$key?'active':''?>" href="?role=<?=e($roleFilter)?>&status=<?=$key?>&per_page=<?=e(per_page_value($perPage))?>"><?=e($label)?><b><?=$key==='all'?intval(($statusCounts['active']??0)+($statusCounts['inactive']??0)):intval($statusCounts[$key]??0)?></b></a><?php endforeach;?></div></div><div class="toolbar-actions"><label class="entries-select">Show<select name="per_page"><?=render_per_page_options($perPage,[4,8,12,16,'full'])?></select></label><div class="view-toggle" data-view-toggle data-target="#clinicUserCollection" data-key="clinic-users-<?=e($roleFilter)?>" data-default="grid"><button type="button" data-view="list"><?=ui_icon('list')?></button><button type="button" data-view="grid"><?=ui_icon('grid')?></button></div></div></section>
<form class="surface-card data-search-bar" method="GET"><input type="hidden" name="role" value="<?=e($roleFilter)?>"><input type="hidden" name="status" value="<?=e($statusFilter)?>"><input type="hidden" name="per_page" value="<?=e(per_page_value($perPage))?>"><input type="search" name="q" value="<?=e($q)?>" placeholder="Search name, email, phone, or address"><button class="button-primary">Search</button><?php if($q!==''):?><a class="button-secondary" href="?role=<?=e($roleFilter)?>&status=<?=e($statusFilter)?>&per_page=<?=e(per_page_value($perPage))?>">Clear</a><?php endif;?></form>
<div class="user-card-list entity-collection view-grid" id="clinicUserCollection"><?php if(!$rows):?><div class="empty-state surface-card"><h2>No accounts found</h2><p>Try another search or create an account.</p></div><?php endif;foreach($rows as $u):$effective=!empty($u['deleted_at'])?'deleted':$u['status'];$detailFields=['Email'=>$u['email'],'Phone'=>$u['phone']?:'Not provided','Address'=>$u['address']?:'Not provided','Status'=>ucfirst($effective),'Last login'=>$u['last_login_at']?date('M d, Y h:i A',strtotime($u['last_login_at'])):'Never','Created by'=>$u['created_by_name']?:'System','Created'=>date('M d, Y',strtotime($u['created_at']))];$detail=['title'=>$u['full_name'],'eyebrow'=>$roles[$u['role']].' account','fields'=>$detailFields];?><article class="entity-card surface-card" data-record-detail='<?=e(json_encode($detail))?>'><header><?=user_avatar_markup($u,'entity-avatar')?><div><div class="title-with-status"><h2 data-fit-name><?=e($u['full_name'])?></h2><?=badge($effective)?></div><p><?=e($u['email'])?></p></div></header><div class="entity-facts"><span><small>Role</small><b><?=e($roles[$u['role']])?></b></span><span><small>Phone</small><b><?=e($u['phone']?:'Not provided')?></b></span><span><small>Last login</small><b><?=$u['last_login_at']?date('M d, Y',strtotime($u['last_login_at'])):'Never'?></b></span><span><small>Created</small><b><?=date('M d, Y',strtotime($u['created_at']))?></b></span></div><footer><small><?=e($u['address']?:'No address saved')?></small><button class="button-secondary" type="button" data-edit-user='<?=e(json_encode($u))?>'>Open account</button></footer></article><?php endforeach;?></div>
<?=render_pagination($page,$perPage,$total,['role'=>$roleFilter,'status'=>$statusFilter,'q'=>$q,'per_page'=>per_page_value($perPage),'_anchor'=>'clinicUserCollection'])?>
<section class="calendar-dialog" id="userCreateDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="userCreateTitle">
    <div class="dialog-scrim" data-close-user-create aria-hidden="true"></div>
    <div class="dialog-card user-create-dialog">
        <header>
            <div><span class="eyebrow">Clinic account</span><h2 id="userCreateTitle" tabindex="-1">Create account</h2></div>
            <button class="icon-button" type="button" data-close-user-create aria-label="Close create account window"><?=ui_icon('x')?></button>
        </header>
        <form method="POST" enctype="multipart/form-data" class="form-stack user-create-form" id="userCreateForm" novalidate>
            <?=csrf_field()?><input type="hidden" name="action" value="create_user">
            <div class="user-create-fields">
                <label for="userCreateName">Full name<input class="form-control" id="userCreateName" name="full_name" autocomplete="name" aria-describedby="userCreateNameError" required><small class="user-create-error" id="userCreateNameError" aria-live="polite" hidden></small></label>
                <div class="form-grid-two">
                    <label for="userCreateEmail">Email<input class="form-control" id="userCreateEmail" type="email" name="email" autocomplete="email" aria-describedby="userCreateEmailError" required><small class="user-create-error" id="userCreateEmailError" aria-live="polite" hidden></small></label>
                    <label for="userCreatePhone">Phone<input class="form-control" id="userCreatePhone" name="phone" autocomplete="tel"></label>
                </div>
                <label for="userCreateAddress">Address<input class="form-control" id="userCreateAddress" name="address" autocomplete="street-address"></label>
                <div class="user-photo-upload">
                    <div class="field-label-row"><span>Profile photo</span><span class="optional-label">Optional</span></div>
                    <input class="form-control" id="userCreatePhoto" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" aria-describedby="userCreatePhotoHint">
                    <small id="userCreatePhotoHint">JPG, PNG, or WEBP. Up to 3 MB.</small>
                </div>
                <label for="userCreateRole">Role<select class="form-select" id="userCreateRole" name="role"><?php foreach($roles as $key=>$label):?><option value="<?=$key?>" <?=$roleFilter===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
                <label for="userCreatePassword">Temporary password<input class="form-control" id="userCreatePassword" type="password" name="password" autocomplete="new-password" minlength="10" data-password-input aria-describedby="userCreatePasswordRules userCreatePasswordError" required><small class="user-create-error" id="userCreatePasswordError" aria-live="polite" hidden></small></label>
                <div class="password-requirements" id="userCreatePasswordRules" data-password-requirements>
                    <span data-rule="length">At least 10 characters</span><span data-rule="upper">One uppercase letter</span><span data-rule="lower">One lowercase letter</span><span data-rule="number">One number</span><span data-rule="symbol">One symbol</span>
                </div>
            </div>
            <div class="form-actions user-create-actions">
                <button class="button-secondary" type="button" data-close-user-create>Cancel</button>
                <button class="button-primary" type="submit">Create account</button>
            </div>
        </form>
    </div>
</section>
<section class="calendar-dialog" id="userEditDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="userEditTitle"><div class="dialog-scrim" data-close-user-edit aria-hidden="true"></div><div class="dialog-card user-edit-dialog shell-ui-no-scroll"><header><div class="client-dialog-heading"><span id="userEditAvatar" aria-hidden="true"></span><div><span class="eyebrow">Clinic account</span><div class="client-dialog-title-row"><h2 id="userEditTitle" tabindex="-1">Edit account</h2><div id="userEditStatusBadge" aria-live="polite"></div></div></div></div><button class="icon-button" type="button" data-close-user-edit aria-label="Close clinic account window"><?=ui_icon('x')?></button></header><form method="POST" enctype="multipart/form-data" class="form-stack" id="userEditForm"><?=csrf_field()?><input type="hidden" name="action" id="userEditAction" value="update_user"><input type="hidden" name="id" id="userEditId"><div class="user-edit-account-grid account-window-columns"><div class="account-window-column"><label>Full name<input class="form-control" name="full_name" id="userEditName" required></label><label>Email<input class="form-control" type="email" name="email" id="userEditEmail" required></label><label>Phone<input class="form-control" name="phone" id="userEditPhone"></label><label>Role<select class="form-select" name="role" id="userEditRole"><?php foreach($roles as $key=>$label):?><option value="<?=$key?>"><?=e($label)?></option><?php endforeach;?></select></label></div><div class="account-window-column user-account-secondary"><label>Address<textarea class="form-control" name="address" id="userEditAddress" rows="6"></textarea></label><div class="user-edit-photo-card"><span>Upload or replace photo</span><div class="user-edit-upload-row"><label class="button-secondary user-edit-upload-button" for="userEditPhoto"><?=ui_icon('upload')?>Upload photo</label><span class="user-edit-upload-name" id="userEditPhotoName">No file selected</span></div><input class="visually-hidden" id="userEditPhoto" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" data-file-name-output="userEditPhotoName"><small>Leave blank to keep the current photo.</small></div></div></div><div class="form-actions" aria-label="Clinic account actions"><button class="button-primary" type="submit" data-user-action="update_user" data-show-user="save">Save account</button><button class="button-secondary" type="submit" formnovalidate data-user-action="restore_user" data-show-user="restore">Restore account</button><button class="button-secondary" type="submit" formnovalidate data-user-action="deactivate_user" data-show-user="deactivate" data-confirm-message="Deactivate this clinic account?">Deactivate</button><button class="button-danger user-delete-rounded" type="submit" formnovalidate data-user-action="delete_user" data-show-user="delete" data-confirm-message="Delete this account? Linked clinic records will remain available in the database.">Delete</button></div></form></div></section>
<script>
(()=>{
 const create=document.getElementById('userCreateDialog');
 const createForm=document.getElementById('userCreateForm');
 const edit=document.getElementById('userEditDialog');
 const form=document.getElementById('userEditForm');
 const actionInput=document.getElementById('userEditAction');
 const photo=document.getElementById('userEditPhoto');
 const photoName=document.getElementById('userEditPhotoName');
 const currentUserId=<?=intval(current_user_id())?>;
 const createValidationFields=['userCreateName','userCreateEmail','userCreatePassword'].map(id=>document.getElementById(id)).filter(Boolean);
 const createValidationMessage=input=>{
   const value=input.value.trim();
   if(input.id==='userCreateName')return value?'':'Enter the account holder’s full name.';
   if(input.id==='userCreateEmail')return !value?'Enter an email address.':input.validity.typeMismatch?'Enter a valid email address.':'';
   if(input.id==='userCreatePassword')return !value?'Create a temporary password.':(/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{10,}$/.test(input.value)?'':'Use 10+ characters with uppercase, lowercase, a number, and a symbol.');
   return '';
 };
 const validateCreateField=input=>{
   const message=createValidationMessage(input);
   const error=document.getElementById(input.id+'Error');
   if(message)input.setAttribute('aria-invalid','true');else input.removeAttribute('aria-invalid');
   if(error){error.textContent=message;error.hidden=!message;}
   return !message;
 };
 createValidationFields.forEach(input=>{
   input.addEventListener('input',()=>{if(input.hasAttribute('aria-invalid'))validateCreateField(input)});
   input.addEventListener('blur',()=>{if(input.value.trim()||input.hasAttribute('aria-invalid'))validateCreateField(input)});
 });
 createForm?.addEventListener('submit',event=>{
   const invalid=createValidationFields.filter(input=>!validateCreateField(input));
   if(!invalid.length)return;
   event.preventDefault();
   const first=invalid[0];
   first.scrollIntoView({block:'center'});
   requestAnimationFrame(()=>first.focus());
 });
 const openers=new WeakMap();
 const localDialogs=[create,edit].filter(Boolean);
 const syncBodyLock=()=>document.body.classList.toggle('overlay-open',Boolean(document.querySelector('.app-dialog.open,.calendar-dialog.open,.appt-modal.show')));
 const focusable=dialog=>[...dialog.querySelectorAll('button:not([disabled]),[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(el=>!el.hidden&&el.getClientRects().length);
 const open=(dialog,trigger)=>{
   if(!dialog)return;
   localDialogs.forEach(other=>{if(other!==dialog){other.classList.remove('open');other.setAttribute('aria-hidden','true')}});
   openers.set(dialog,trigger||document.activeElement);
   dialog.classList.add('open');dialog.setAttribute('aria-hidden','false');syncBodyLock();
   requestAnimationFrame(()=>{const initial=document.getElementById(dialog.getAttribute('aria-labelledby'))||focusable(dialog)[0];initial?.focus()});
 };
 const close=dialog=>{
   if(!dialog||!dialog.classList.contains('open'))return;
   dialog.classList.remove('open');dialog.setAttribute('aria-hidden','true');syncBodyLock();
   const opener=openers.get(dialog);if(opener?.isConnected)setTimeout(()=>opener.focus(),0);
 };
 const renderAvatar=user=>{
   const host=document.getElementById('userEditAvatar');if(!host)return;
   const avatar=document.createElement('span');avatar.className='user-avatar settings-avatar';
   const path=String(user.profile_photo||'');
   if(/^uploads\/profiles\/[A-Za-z0-9._\/-]+$/.test(path)){
     const image=document.createElement('img');image.src='../'+path;image.alt=String(user.full_name||'User')+' profile photo';avatar.appendChild(image);
   }else{
     avatar.textContent=String(user.full_name||'V').trim().split(/\s+/).slice(0,2).map(part=>part.charAt(0)).join('').toUpperCase()||'V';
   }
   host.replaceChildren(avatar);
 };
 const renderStatus=effective=>{
   const host=document.getElementById('userEditStatusBadge');if(!host)return;
   const status=document.createElement('span');status.className='badge text-bg-'+(effective==='active'?'success':effective==='deleted'?'danger':'secondary');status.textContent=effective;host.replaceChildren(status);
 };
 document.querySelector('[data-open-user-create]')?.addEventListener('click',event=>open(create,event.currentTarget));
 document.querySelectorAll('[data-close-user-create]').forEach(button=>button.addEventListener('click',()=>close(create)));
 document.querySelectorAll('[data-close-user-edit]').forEach(button=>button.addEventListener('click',()=>close(edit)));
 document.querySelectorAll('[data-user-action]').forEach(button=>button.addEventListener('click',()=>{actionInput.value=button.dataset.userAction}));
 document.querySelectorAll('[data-edit-user]').forEach(button=>button.addEventListener('click',event=>{
   event.stopPropagation();let user;try{user=JSON.parse(button.dataset.editUser)}catch(_){if(typeof showToast==='function')showToast('This clinic account could not be opened.','error');return}
   const effective=user.deleted_at?'deleted':user.status;
   const editable=effective==='active';
   const isCurrentUser=Number(user.id)===currentUserId;
   document.getElementById('userEditTitle').textContent=user.full_name||'Clinic account';
   if(photo){photo.value='';photoName.textContent='No file selected'}
   document.getElementById('userEditId').value=user.id;
   document.getElementById('userEditName').value=user.full_name||'';
   document.getElementById('userEditEmail').value=user.email||'';
   document.getElementById('userEditPhone').value=user.phone||'';
   document.getElementById('userEditAddress').value=user.address||'';
   document.getElementById('userEditRole').value=user.role;
   renderStatus(effective);renderAvatar(user);
   form.querySelectorAll('.user-edit-account-grid input:not([type="hidden"]),.user-edit-account-grid textarea,.user-edit-account-grid select').forEach(control=>{control.disabled=!editable});
   document.getElementById('userEditRole').disabled=!editable||isCurrentUser;
   form.classList.toggle('account-window-readonly',!editable);
   const visible={save:editable,restore:effective==='inactive',deactivate:editable&&!isCurrentUser,delete:editable&&!isCurrentUser};
   form.querySelectorAll('[data-show-user]').forEach(control=>{control.hidden=!visible[control.dataset.showUser]});
   form.querySelector(':scope>.form-actions').hidden=!Object.values(visible).some(Boolean);
   actionInput.value='update_user';open(edit,button);
 }));
 document.addEventListener('keydown',event=>{
   if(document.querySelector('.app-dialog.open'))return;
   const dialog=localDialogs.find(item=>item.classList.contains('open'));if(!dialog)return;
   if(event.key==='Escape'){event.preventDefault();close(dialog);return}
   if(event.key!=='Tab')return;
   const items=focusable(dialog);if(!items.length){event.preventDefault();return}
   const first=items[0],last=items[items.length-1];
   if(event.shiftKey&&(document.activeElement===first||document.activeElement===document.getElementById(dialog.getAttribute('aria-labelledby'))||!dialog.contains(document.activeElement))){event.preventDefault();last.focus()}
   else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
 });
})();
</script>
</main></div><?php include "../includes/footer.php";?>
