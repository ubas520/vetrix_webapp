<?php
require_once "../config/database.php";require_once "../includes/functions.php";require_role('admin');$uid=(int)current_user_id();
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf_or_fail();$action=$_POST['action']??'upload';
 if($action==='default'){
   $pointer=dirname(__DIR__).'/uploads/profiles/vetrix-brand-logo.txt';$old=vetrix_brand_logo_path();
   if($old!=='assets/images/vetrix-logo.svg'){$oldAbsolute=dirname(__DIR__).'/'.$old;if(is_file($oldAbsolute))@unlink($oldAbsolute);}if(is_file($pointer))@unlink($pointer);
   log_action($conn,'Restored default Vetrix brand logo','user',$uid,'Brand logo reset to the bundled default.');flash('success','Default logo restored.');
 } elseif(empty($_FILES['brand_logo']['name'])) flash('error','Choose a JPG, PNG, or WEBP logo first.');
 else{$upload=save_vetrix_brand_logo($_FILES['brand_logo']);if(!empty($upload['ok'])){log_action($conn,'Updated Vetrix brand logo','user',$uid,'Brand logo updated.');flash('success','Logo updated on the sidebar, login, and registration pages.');}else flash('error',$upload['error']??'The logo could not be uploaded.');}
 redirect_to('account/logo.php');
}
$currentBrandLogo=vetrix_brand_logo_path();$hasCustomBrandLogo=$currentBrandLogo!=='assets/images/vetrix-logo.svg';
$title='Logo';include "../includes/header.php";include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/admin_sidebar.php";?><main class="content account-logo-page" id="mainContent"><header class="page-heading"><div><span class="eyebrow">Brand</span><h1>Logo</h1></div></header><?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?><section class="surface-card brand-logo-settings"><form method="POST" enctype="multipart/form-data" class="form-stack" id="brandLogoForm"><?=csrf_field()?><input type="hidden" name="action" value="upload"><div class="brand-logo-upload-row"><span class="brand-logo-preview"><img src="<?=e(app_url(vetrix_brand_logo_path()))?>" alt="Current logo"></span><label class="button-secondary" for="brandLogoPhoto"><?=ui_icon('upload')?>Upload logo</label><input id="brandLogoPhoto" class="visually-hidden" type="file" name="brand_logo" accept="image/jpeg,image/png,image/webp"></div><small>JPG, PNG, or WEBP uploads are supported.</small><div class="form-actions"><button class="button-primary" type="submit" id="saveBrandLogo" disabled>Save logo</button></div></form><?php if($hasCustomBrandLogo):?><form method="POST" class="form-stack logo-default-form"><?=csrf_field()?><input type="hidden" name="action" value="default"><button class="button-secondary" type="submit">Change to default photo</button></form><?php endif;?></section><script>(()=>{const input=document.getElementById('brandLogoPhoto'),save=document.getElementById('saveBrandLogo');if(!input||!save)return;const sync=()=>{save.disabled=!(input.files&&input.files.length);};input.addEventListener('change',sync);sync();})();</script></main></div><?php include "../includes/footer.php";?>
