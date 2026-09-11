<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role(['admin','veterinarian','staff']);
$uid=(int)current_user_id();
$role=$_SESSION['role']??'';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf_or_fail();
    $fullName=trim($_POST['full_name']??'');$email=strtolower(trim($_POST['email']??''));$phone=trim($_POST['phone']??'');$emergencyName=trim($_POST['emergency_contact_name']??'');$emergencyPhone=trim($_POST['emergency_contact_phone']??'');$emergency=trim($emergencyName.($emergencyName!==''&&$emergencyPhone!==''?' | ':'').$emergencyPhone);$address=trim($_POST['address']??'');
    if($fullName==='') flash('error','Full name is required.');
    elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)) flash('error','Enter a valid email address.');
    else{
        $emailCheck=$conn->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");$emailCheck->bind_param('si',$email,$uid);$emailCheck->execute();if($emailCheck->get_result()->num_rows){flash('error','That email address is already used by another account.');redirect_to('account/profile.php');}
        $photoPath=null;
        if(!empty($_FILES['profile_photo']['name'])){$upload=upload_image_file($_FILES['profile_photo'],'uploads/profiles','profile_'.$uid);if(!$upload['ok']){flash('error',$upload['error']);redirect_to('account/profile.php');}$photoPath=$upload['path'];}
        if($photoPath){$stmt=$conn->prepare("UPDATE users SET full_name=?,email=?,phone=?,emergency_contact=?,emergency_contact_name=?,emergency_contact_phone=?,address=?,profile_photo=? WHERE id=?");$stmt->bind_param('ssssssssi',$fullName,$email,$phone,$emergency,$emergencyName,$emergencyPhone,$address,$photoPath,$uid);}else{$stmt=$conn->prepare("UPDATE users SET full_name=?,email=?,phone=?,emergency_contact=?,emergency_contact_name=?,emergency_contact_phone=?,address=? WHERE id=?");$stmt->bind_param('sssssssi',$fullName,$email,$phone,$emergency,$emergencyName,$emergencyPhone,$address,$uid);}
        $stmt->execute();$_SESSION['full_name']=$fullName;log_action($conn,'Updated account profile','user',$uid,'Contact details or profile photo updated.');flash('success','Profile updated.');
    }
    redirect_to('account/profile.php');
}
$user=current_user_record($conn);$title='Profile';include "../includes/header.php";include "../includes/navbar.php";
$sidebar=$role==='admin'?'admin_sidebar.php':($role==='veterinarian'?'vet_sidebar.php':'staff_sidebar.php');
?>
<div class="layout"><?php include "../includes/$sidebar";?><main class="content" id="mainContent">
<header class="page-heading page-heading-profile"><div><span class="eyebrow">Account</span><h1>Profile</h1></div><div class="page-heading-avatar"><?=user_avatar_markup($user,'profile-hero-avatar')?><span><b><?=e($user['full_name'])?></b><small><?=e(role_label($role))?></small></span></div></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>
<section class="surface-card profile-only-card"><div class="section-heading"><div><h2>Personal information</h2></div></div><form method="POST" enctype="multipart/form-data" class="form-stack profile-two-column-form"><?=csrf_field()?><div class="photo-upload-row"><?=user_avatar_markup($user,'settings-avatar')?><label class="button-secondary" for="profilePhoto"><?=ui_icon('upload')?>Upload photo</label><input id="profilePhoto" class="visually-hidden" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><small>Accepted: JPG, PNG, or WEBP. Maximum file size: 3 MB.</small></div><div class="form-grid-two profile-contact-grid"><label class="profile-name-field">Full name<input class="form-control" name="full_name" value="<?=e($user['full_name'])?>" required></label><label class="profile-phone-field">Phone number<input class="form-control" name="phone" value="<?=e($user['phone'])?>"></label><label class="profile-email-field">Email address<input class="form-control" type="email" name="email" value="<?=e($user['email'])?>" required></label><label class="profile-emergency-name-field"><span>Emergency contact name</span><input class="form-control" name="emergency_contact_name" value="<?=e($user['emergency_contact_name']?:split_emergency_contact($user['emergency_contact'])[0])?>"></label><label class="profile-address-field">Address<textarea class="form-control" name="address" rows="3"><?=e($user['address'])?></textarea></label><label class="profile-emergency-phone-field"><span>Emergency contact number</span><input class="form-control profile-emergency-phone-input" name="emergency_contact_phone" value="<?=e($user['emergency_contact_phone']?:split_emergency_contact($user['emergency_contact'])[1])?>"></label></div><div class="form-actions"><button class="button-primary" type="submit"><?=ui_icon('check')?>Save profile</button></div></form></section>
</main></div><?php include "../includes/footer.php";?>
