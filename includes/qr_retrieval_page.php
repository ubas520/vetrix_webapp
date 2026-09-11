<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/pet_qr.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$qrRole = $qrRole ?? 'staff';
require_role($qrRole);
$canViewOwnerPrivate = true;
$result = null;
$error = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) verify_csrf_or_fail();
$token = pet_qr_token($isPost ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
if ($isPost || isset($_GET['token'])) {
    $result = pet_qr_record($conn, $token, $qrRole, (int) current_user_id());
    if (!$result) {
        $error = 'The QR token is invalid, inactive, expired, or linked to an unapproved pet.';
        if (!$isPost) http_response_code(404);
    } else {
        log_action($conn, 'Retrieved emergency QR record', 'pet', $result['pet_id'], 'QR token used by ' . $qrRole . '.');
    }
}
$allowQrCamera = true;
$title='QR Token Retrieval';include __DIR__.'/header.php';include __DIR__.'/navbar.php';$sidebar=['admin'=>'admin_sidebar.php','staff'=>'staff_sidebar.php','veterinarian'=>'vet_sidebar.php'][$qrRole];
?>
<div class="layout"><?php include __DIR__.'/'.$sidebar;?><main class="content <?=e($qrRole)?>-qr-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Emergency access</span><h1>QR Token Retrieval</h1></div></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif;?>
<div class="qr-retrieval-shell"><section class="surface-card qr-retrieval-card qr-camera-card"><div class="qr-token-visual"><span><?=ui_icon('qr')?></span><div><h2>Scan or enter QR token</h2><p>Scan the pet QR with your laptop camera, or enter its token or web link.</p></div></div><?php include __DIR__ . '/qr_camera.php'; ?><form method="POST" class="qr-token-form" id="qrTokenForm"><?=csrf_field()?><input type="hidden" name="action" value="retrieve_record"><label for="qrTokenInput">Secure QR token</label><div><input class="form-control" id="qrTokenInput" name="token" value="<?=e($token)?>" placeholder="Example: VX-PET-7K4M9Q" autocomplete="off" required><button class="button-primary" type="submit"><?=ui_icon('search')?>Retrieve record</button></div></form></section><aside class="surface-card qr-retrieval-guide"><span class="eyebrow">Retrieval guide</span><h2>Emergency record access</h2><div class="qr-guide-list"><div><b>1</b><span>Select Scan with camera, allow camera access, and hold the pet QR in front of the webcam.</span></div><div><b>2</b><span>Confirm the pet identity before using clinical or emergency notes.</span></div><div><b>3</b><span>Every successful retrieval is recorded in the clinic activity log.</span></div></div></aside></div>
<?php if($result):?>
<div class="qr-result-grid"><section class="surface-card qr-pet-summary"><header><?=pet_avatar_markup($result,'qr-pet-avatar')?><div><span class="eyebrow">Verified pet</span><h2><?=e($result['name'])?></h2><p><?=e($result['species'])?> · <?=e($result['breed'])?></p></div></header><dl><div><dt>Owner</dt><dd><?=e($result['full_name'])?></dd></div><div><dt>Phone</dt><dd><?=e($canViewOwnerPrivate?($result['phone']?:'Not provided'):'Restricted by administrator')?></dd></div><div><dt>Email</dt><dd><?=e($canViewOwnerPrivate?($result['email']?:'Not provided'):'Restricted by administrator')?></dd></div><div><dt>Address</dt><dd><?=e($canViewOwnerPrivate?($result['address']?:'Not provided'):'Restricted by administrator')?></dd></div></dl><div class="emergency-note"><b>Known allergies</b><p><?=e($result['allergies']?:'None recorded')?></p></div><div class="emergency-note critical"><b>Critical notes</b><p><?=e($result['critical_notes']?:'None recorded')?></p></div></section>
<section class="surface-card management-table-card"><div class="section-heading"><div><span class="eyebrow">Clinical history</span><h2>Recent medical records</h2><p>Latest five records for emergency review.</p></div></div><div class="table-scroll-only"><table class="data-table"><thead><tr><th>Date</th><th>Symptoms</th><th>Diagnosis</th><th>Treatment</th></tr></thead><tbody><?php $pid=(int)$result['pet_id'];$recs=$conn->query("SELECT * FROM medical_records WHERE pet_id=$pid ORDER BY visit_date DESC,id DESC LIMIT 5");if(!$recs->num_rows):?><tr><td colspan="4" class="empty-cell">No medical records found.</td></tr><?php endif;while($r=$recs->fetch_assoc()):?><tr><td><?=date('M d, Y',strtotime($r['visit_date']))?></td><td><?=e($r['symptoms'])?></td><td><?=e($r['diagnosis'])?></td><td><?=e($r['treatment'])?></td></tr><?php endwhile;?></tbody></table></div></section></div>
<?php endif;?>
</main></div><?php include __DIR__.'/footer.php';?>
