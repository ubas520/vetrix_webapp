<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role('admin');

function admin_client_record($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND role='client' LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function client_effective_status($client) {
    if (!empty($client['deleted_at'])) return 'deleted';
    if (($client['status'] ?? '') === 'approved' && empty($client['otp_verified_at'])) return 'otp_needed';
    return $client['status'] ?? 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_client') {
        $name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
        $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
        $emergency = trim($emergencyName . ($emergencyName !== '' && $emergencyPhone !== '' ? ' | ' : '') . $emergencyPhone);
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a full name and valid email address.');
            redirect_to('admin/clients.php');
        }
        if ($errors = password_policy_errors($password)) {
            flash('error', implode(' ', $errors));
            redirect_to('admin/clients.php');
        }
        $photoPath = null;
        if (!empty($_FILES['profile_photo']['name'])) {
            $upload = upload_image_file($_FILES['profile_photo'], 'uploads/profiles', 'client_new');
            if (!$upload['ok']) { flash('error', $upload['error']); redirect_to('admin/clients.php'); }
            $photoPath = $upload['path'];
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $admin = (int)current_user_id();
        $source = 'admin_created';
        $status = 'pending';
        $stmt = $conn->prepare("INSERT INTO users(full_name,email,phone,address,emergency_contact,emergency_contact_name,emergency_contact_phone,password,role,account_source,created_by,status) VALUES(?,?,?,?,?,?,?,?,'client',?,?,?)");
        $stmt->bind_param('sssssssssis', $name, $email, $phone, $address, $emergency, $emergencyName, $emergencyPhone, $hash, $source, $admin, $status);
        if (!$stmt->execute()) {
            flash('error', $conn->errno === 1062 ? 'That email address is already registered.' : 'The client account could not be created.');
            redirect_to('admin/clients.php');
        }
        $newClientId = (int)$stmt->insert_id;
        if ($photoPath) {
            $photoStmt = $conn->prepare("UPDATE users SET profile_photo=? WHERE id=? AND role='client'");
            $photoStmt->bind_param('si', $photoPath, $newClientId);
            $photoStmt->execute();
        }
        log_action($conn, 'Created client account', 'user', $newClientId, 'Administrator created a pending client account.');
        flash('success', 'Client account created and placed in pending review.');
        redirect_to('admin/clients.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $client = admin_client_record($conn, $id);
    if (!$client) {
        flash('error', 'Client account not found.');
        redirect_to('admin/clients.php');
    }

    if ($action === 'update_client') {
        $effectiveStatus = client_effective_status($client);
        if (!in_array($effectiveStatus, ['pending','otp_needed','active'], true)) {
            flash('error', 'Client details can only be edited while the account is pending, awaiting OTP, or active.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        $name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
        $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
        $emergency = trim($emergencyName . ($emergencyName !== '' && $emergencyPhone !== '' ? ' | ' : '') . $emergencyPhone);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Review the client name and email address.');
            redirect_to('admin/clients.php');
        }
        $photoPath = null;
        if (!empty($_FILES['profile_photo']['name'])) {
            $upload = upload_image_file($_FILES['profile_photo'], 'uploads/profiles', 'client_' . $id);
            if (!$upload['ok']) { flash('error', $upload['error']); redirect_to('admin/clients.php?client_id=' . $id); }
            $photoPath = $upload['path'];
        }
        $stmt = $conn->prepare("UPDATE users SET full_name=?,email=?,phone=?,address=?,emergency_contact=?,emergency_contact_name=?,emergency_contact_phone=? WHERE id=? AND role='client'");
        $stmt->bind_param('sssssssi', $name, $email, $phone, $address, $emergency, $emergencyName, $emergencyPhone, $id);
        if (!$stmt->execute()) {
            flash('error', $conn->errno === 1062 ? 'That email address is already used.' : 'Client changes could not be saved.');
            redirect_to('admin/clients.php');
        }
        if ($photoPath) {
            $photoStmt = $conn->prepare("UPDATE users SET profile_photo=? WHERE id=? AND role='client'");
            $photoStmt->bind_param('si', $photoPath, $id);
            $photoStmt->execute();
        }
        log_action($conn, 'Updated client account', 'user', $id, 'Administrator updated client contact details or photo.');
        flash('success', 'Client details updated.');
    } elseif ($action === 'approve_client') {
        if ($client['status'] !== 'pending' || !empty($client['deleted_at'])) {
            flash('error', 'Only a pending client can be approved and issued an OTP.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        $mailPreflightError = null;
        if (!mail_delivery_ready($mailPreflightError)) {
            log_mail_delivery_failure('client_approval_preflight:user:' . $id, $mailPreflightError);
            flash('error', 'Client approval was not completed because email delivery is unavailable. Configure the mail service and try again.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        if (!$conn->begin_transaction()) {
            error_log('Vetrix client approval transaction could not start for user ' . $id . ': ' . $conn->error);
            flash('error', 'Client approval could not be started. Please try again.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        $admin = (int)current_user_id();
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE users SET status='approved',approved_by=?,approved_at=?,rejected_at=NULL,otp_verified_at=NULL,deleted_at=NULL WHERE id=? AND role='client' AND status='pending' AND deleted_at IS NULL");
        if (!$stmt || !$stmt->bind_param('isi', $admin, $now, $id) || !$stmt->execute() || (int)$stmt->affected_rows !== 1) {
            error_log('Vetrix client approval update failed for user ' . $id . ': ' . $conn->error);
            $conn->rollback();
            flash('error', 'Client approval could not be saved. The account and OTP were not changed.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        $client = admin_client_record($conn, $id);
        if (!$client) {
            error_log('Vetrix client approval could not reload user ' . $id . ' inside the transaction.');
            $conn->rollback();
            flash('error', 'Client approval could not be saved. The account and OTP were not changed.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        $otp = create_client_otp($conn, $id);
        if ($otp === false) {
            error_log('Vetrix client approval OTP creation failed for user ' . $id . ': ' . $conn->error);
            $conn->rollback();
            flash('error', 'Client approval could not be saved. The account and OTP were not changed.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        if (!notify_user($conn, $id, 'Account approved', 'Your account was approved. Check your registered email for the one-time OTP.', 'system', null)) {
            error_log('Vetrix client approval notification failed for user ' . $id . ': ' . $conn->error);
            $conn->rollback();
            flash('error', 'Client approval could not be saved. The account and OTP were not changed.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        log_action($conn, 'Approved client account and issued OTP', 'user', $id, 'OTP emailed.');
        $mail = send_client_approval_email($conn, $client, $otp);
        if (!$mail) {
            $conn->rollback();
            flash_mail_result('', 'Client approval was not completed because');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        if (!$conn->commit()) {
            error_log('Vetrix client approval commit failed for user ' . $id . ': ' . $conn->error);
            $conn->rollback();
            flash('error', 'The OTP email was sent, but client approval could not be saved. Check the account before trying again.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        flash_mail_result('Client approved and OTP sent.', 'Client approved and OTP generated, but');
    } elseif ($action === 'reject_client') {
        $effectiveStatus = client_effective_status($client);
        if (!in_array($effectiveStatus, ['pending','otp_needed'], true)) {
            flash('error', 'Only pending or OTP-needed clients can be rejected.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE users SET status='rejected',rejected_at=?,otp_verified_at=NULL,deleted_at=NULL WHERE id=? AND role='client'");
        $stmt->bind_param('si', $now, $id);
        $stmt->execute();
        $client = admin_client_record($conn, $id);
        $mail = send_client_rejection_email($conn, $client);
        log_action($conn, 'Rejected client account', 'user', $id, $mail ? 'Rejection emailed.' : 'Rejection email failed.');
        flash_mail_result('Client account rejected and email sent.', 'Client account rejected, but');
    } elseif ($action === 'resend_otp') {
        if ($client['status'] === 'pending' && empty($client['deleted_at'])) {
            flash('error', 'This client does not have an OTP yet. Use Approve and issue OTP first.');
        } elseif ($client['status'] === 'approved' && empty($client['otp_verified_at']) && empty($client['deleted_at'])) {
            $mailPreflightError = null;
            if (!mail_delivery_ready($mailPreflightError)) {
                log_mail_delivery_failure('client_otp_resend_preflight:user:' . $id, $mailPreflightError);
                flash('error', 'The OTP was not changed because email delivery is unavailable. Configure the mail service and try again.');
            } else {
                if (!$conn->begin_transaction()) {
                    error_log('Vetrix OTP resend transaction could not start for user ' . $id . ': ' . $conn->error);
                    flash('error', 'The OTP could not be resent. Please try again.');
                } else {
                    $lock = $conn->prepare("SELECT id FROM users WHERE id=? AND role='client' AND status='approved' AND otp_verified_at IS NULL AND deleted_at IS NULL LIMIT 1 FOR UPDATE");
                    if (!$lock || !$lock->bind_param('i', $id) || !$lock->execute() || $lock->get_result()->num_rows !== 1) {
                        error_log('Vetrix OTP resend eligibility lock failed for user ' . $id . ': ' . $conn->error);
                        $conn->rollback();
                        flash('error', 'The OTP could not be resent because the account is no longer eligible.');
                    } else {
                        $actor=(int)current_user_id();
                        $limit=$conn->prepare("SELECT COUNT(*) c FROM audit_logs WHERE actor_user_id=? AND action='Resent client OTP' AND entity_type='user' AND entity_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
                        if (!$limit || !$limit->bind_param('ii',$actor,$id) || !$limit->execute()) {
                            error_log('Vetrix OTP resend rate-limit check failed for user ' . $id . ': ' . $conn->error);
                            $conn->rollback();
                            flash('error', 'The OTP resend limit could not be checked. Please try again.');
                        } elseif ((int)($limit->get_result()->fetch_assoc()['c']??0)>=3) {
                            $conn->rollback();
                            flash('error','The OTP resend limit was reached for this client. Try again after 10 minutes.');
                        } else {
                            $otp = create_client_otp($conn, $id);
                            if ($otp === false) {
                                error_log('Vetrix OTP resend token creation failed for user ' . $id . ': ' . $conn->error);
                                $conn->rollback();
                                flash('error', 'The OTP could not be resent. The current OTP was not changed.');
                            } else {
                                log_action($conn, 'Resent client OTP', 'user', $id, 'OTP emailed.');
                                $mail = send_client_approval_email($conn, $client, $otp);
                                if (!$mail) {
                                    $conn->rollback();
                                    flash_mail_result('', 'The OTP was not changed because');
                                } elseif (!$conn->commit()) {
                                    error_log('Vetrix OTP resend commit failed for user ' . $id . ': ' . $conn->error);
                                    $conn->rollback();
                                    flash('error', 'The OTP email was sent, but the new OTP could not be saved. Check the account before trying again.');
                                } else {
                                    flash_mail_result('A new OTP was sent.', 'A new OTP was generated, but');
                                }
                            }
                        }
                    }
                }
            }
        } else {
            flash('error', 'OTP can only be resent to an approved client who has not verified the current account.');
        }
    } elseif ($action === 'deactivate_client') {
        if (client_effective_status($client) !== 'active') {
            flash('error', 'Only an active client account can be deactivated.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        $stmt = $conn->prepare("UPDATE users SET status='inactive' WHERE id=? AND role='client' AND status='active' AND deleted_at IS NULL");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_action($conn, 'Deactivated client account', 'user', $id, 'Access was disabled without deleting records.');
        flash('success', 'Client account deactivated.');
    } elseif ($action === 'restore_client') {
        if ($client['status'] !== 'inactive' || !empty($client['deleted_at'])) {
            flash('error', 'Only a deactivated account can be restored.');
        } else {
            $nextStatus = !empty($client['otp_verified_at']) ? 'active' : 'approved';
            $stmt = $conn->prepare("UPDATE users SET status=? WHERE id=? AND role='client' AND status='inactive' AND deleted_at IS NULL");
            $stmt->bind_param('si', $nextStatus, $id);
            $stmt->execute();
            log_action($conn, 'Restored client account', 'user', $id, 'Deactivated account restored to ' . $nextStatus . '.');
            flash('success', 'Client account restored.');
        }
    } elseif ($action === 'delete_client') {
        if (client_effective_status($client) !== 'active') {
            flash('error', 'Only an active client account can be deleted.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        $stmt = $conn->prepare("UPDATE users SET status='inactive',deleted_at=NOW() WHERE id=? AND role='client' AND status='active' AND deleted_at IS NULL");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_action($conn, 'Deleted client account', 'user', $id, 'Account hidden while linked clinic records were retained.');
        flash('success', 'Client account moved to deleted accounts.');
    } elseif ($action === 'remove_photo') {
        $photo = trim((string)($client['profile_photo'] ?? ''));
        if ($photo === '') {
            flash('error', 'This client does not have a profile photo to remove.');
            redirect_to('admin/clients.php?client_id=' . $id);
        }
        if ($photo !== '' && str_starts_with($photo, 'uploads/profiles/')) {
            $absolute = dirname(__DIR__) . '/' . $photo;
            if (is_file($absolute)) @unlink($absolute);
        }
        $stmt = $conn->prepare("UPDATE users SET profile_photo=NULL WHERE id=? AND role='client'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_action($conn, 'Removed client profile photo', 'user', $id, 'Administrator removed the profile photo.');
        flash('success', 'Client profile photo removed.');
    }
    redirect_to('admin/clients.php');
}

$clientFocusId = max(0, (int)($_GET['client_id'] ?? 0));
$filter = strtolower($_GET['filter'] ?? 'all');
$allowedFilters = ['all','pending','otp_needed','active','inactive','rejected','deleted'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'all';
$q = trim($_GET['q'] ?? '');
$requestedView = $_GET['view'] ?? 'grid';
$initialView = $requestedView === 'list' ? 'list' : 'grid';
[$page, $perPage, $offset] = pagination_values(4, 16, [4,8,12,16,'full']);
$where = ["u.role='client'"];
$types = '';
$params = [];
if ($clientFocusId > 0) {
    $where[] = 'u.id=?';
    $types = 'i';
    $params[] = $clientFocusId;
    $filter = 'all';
    $q = '';
    $page = 1;
    $offset = 0;
} else {
    if ($filter === 'deleted') {
        $where[] = 'u.deleted_at IS NOT NULL';
    } else {
        $where[] = 'u.deleted_at IS NULL';
        if ($filter === 'otp_needed') $where[] = "u.status='approved' AND u.otp_verified_at IS NULL";
        elseif ($filter !== 'all') { $where[] = 'u.status=?'; $types .= 's'; $params[] = $filter; }
    }
    if ($q !== '') {
        $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.address LIKE ? OR u.emergency_contact LIKE ? OR u.emergency_contact_name LIKE ? OR u.emergency_contact_phone LIKE ?)";
        $like = '%' . $q . '%';
        $types .= 'sssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$count = $conn->prepare("SELECT COUNT(*) c FROM users u $whereSql");
if ($types) $count->bind_param($types, ...$params);
$count->execute();
$total = (int)$count->get_result()->fetch_assoc()['c'];
$sql = "SELECT u.*,creator.full_name created_by_name,approver.full_name approved_by_name,(SELECT COUNT(*) FROM pets p WHERE p.owner_id=u.id) pet_count FROM users u LEFT JOIN users creator ON u.created_by=creator.id LEFT JOIN users approver ON u.approved_by=approver.id $whereSql ORDER BY u.deleted_at IS NOT NULL,FIELD(u.status,'pending','approved','active','inactive','rejected'),u.created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$clientRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$counts = $conn->query("SELECT COUNT(*) total,SUM(deleted_at IS NULL AND status='pending') pending,SUM(deleted_at IS NULL AND status='approved' AND otp_verified_at IS NULL) otp_needed,SUM(deleted_at IS NULL AND status='active') active,SUM(deleted_at IS NULL AND status='inactive') inactive,SUM(deleted_at IS NULL AND status='rejected') rejected,SUM(deleted_at IS NOT NULL) deleted FROM users WHERE role='client'")->fetch_assoc();
$suggestions = $conn->query("SELECT full_name,email,phone FROM users WHERE role='client' AND deleted_at IS NULL ORDER BY full_name LIMIT 100");
$title = 'Client Accounts';
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/admin_sidebar.php"; ?><main class="content admin-clients-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Client directory</span><h1>Client Accounts</h1></div><button class="button-primary" type="button" data-open-client-create><?=ui_icon('plus')?>Create client</button></header>
<?php if ($m=flash('success')): ?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif; ?>
<?php if ($m=flash('error')): ?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif; ?>

<?php include '../includes/admin_password_reset_requests.php'; ?>
<section class="surface-card data-toolbar client-toolbar"><div class="filter-tabs">
<?php foreach (['all'=>'All','pending'=>'Pending','otp_needed'=>'OTP needed','active'=>'Active','inactive'=>'Inactive','rejected'=>'Rejected','deleted'=>'Deleted'] as $key=>$label): ?>
<a class="filter-tab filter-<?=$key?> <?=$filter===$key?'active':''?>" href="?filter=<?=$key?>&q=<?=urlencode($q)?>&per_page=<?=e(per_page_value($perPage))?>"><?=e($label)?><b><?=intval($counts[$key==='all'?'total':$key] ?? 0)?></b></a>
<?php endforeach; ?>
</div><div class="toolbar-actions"><label class="entries-select">Show<select name="per_page"><?=render_per_page_options($perPage,[4,8,12,16,'full'])?></select></label><div class="view-toggle" data-view-toggle data-target="#clientCollection" data-key="admin-clients" data-default="<?=e($initialView)?>"><button type="button" data-view="list" aria-label="List view"><?=ui_icon('list')?></button><button type="button" data-view="grid" aria-label="Grid view"><?=ui_icon('grid')?></button></div></div></section>

<form class="surface-card data-search-bar" method="GET"><input type="hidden" name="view" value="<?=e($initialView)?>"><input type="hidden" name="filter" value="<?=e($filter)?>"><input type="hidden" name="per_page" value="<?=e(per_page_value($perPage))?>"><input type="search" name="q" list="clientSearchSuggestions" value="<?=e($q)?>" placeholder="Search client, email, phone, emergency contact, or address"><datalist id="clientSearchSuggestions"><?php while ($s=$suggestions->fetch_assoc()): ?><option value="<?=e($s['full_name'])?>"><?=e($s['email'].' · '.($s['phone'] ?: 'No phone'))?></option><?php endwhile; ?></datalist><button class="button-primary" type="submit">Search</button><?php if ($q!==''): ?><a class="button-secondary" href="?filter=<?=e($filter)?>&per_page=<?=e(per_page_value($perPage))?>">Clear</a><?php endif; ?></form>

<div class="entity-collection view-<?=e($initialView)?>" id="clientCollection">
<?php if (!$clientRows): ?><div class="empty-state surface-card"><span><?=ui_icon('users')?></span><h2>No client accounts found</h2><p>Try another filter or search term.</p></div><?php endif; ?>
<?php foreach ($clientRows as $r): $effective=client_effective_status($r); $detail=['title'=>$r['full_name'],'eyebrow'=>'Client account','fields'=>['Email'=>$r['email'],'Phone'=>$r['phone'] ?: 'Not provided','Address'=>$r['address'] ?: 'Not provided','Emergency contact'=>emergency_contact_display($r) ?: 'Not provided','Linked pets'=>$r['pet_count'],'Account source'=>ucwords(str_replace('_',' ',$r['account_source'] ?: 'self_registered')),'Account status'=>ucwords(str_replace('_',' ',$effective)),'OTP'=>!empty($r['otp_verified_at']) ? 'Verified' : ($r['status']==='approved' ? 'Verification required' : 'Not issued'),'Created'=>date('M d, Y',strtotime($r['created_at']))]]; ?>
<article class="entity-card surface-card" data-record-detail='<?=e(json_encode($detail))?>'><header><?=user_avatar_markup($r,'entity-avatar')?><div><div class="title-with-status"><h2><?=e($r['full_name'])?></h2><?=badge($effective)?></div><p><?=e($r['email'])?></p></div></header><div class="entity-facts"><span><small>Phone</small><b><?=e($r['phone'] ?: 'Not provided')?></b></span><span><small>Linked pets</small><b><?=intval($r['pet_count'])?></b></span><span class="entity-source-fact"><small>Source</small><b><?=e(ucwords(str_replace('_',' ',$r['account_source'] ?: 'self registered')))?></b></span><span><small>Created</small><b><?=date('M d, Y',strtotime($r['created_at']))?></b></span></div><div class="entity-address"><b>Emergency contact</b><p><?=e(emergency_contact_display($r) ?: 'No emergency contact saved')?></p></div><footer><small>Created <?=date('M d, Y',strtotime($r['created_at']))?></small><button class="button-secondary" type="button" data-edit-client='<?=e(json_encode($r))?>'>Open account</button></footer></article>
<?php endforeach; ?>
</div>
<?=render_pagination($page,$perPage,$total,['filter'=>$filter,'q'=>$q,'view'=>$initialView,'per_page'=>per_page_value($perPage),'_anchor'=>'clientCollection'])?>

<section class="calendar-dialog" id="clientCreateDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="clientCreateTitle">
    <div class="dialog-scrim" data-close-client-create aria-hidden="true"></div>
    <div class="dialog-card client-create-dialog">
        <header>
            <div><span class="eyebrow">Client account</span><h2 id="clientCreateTitle" tabindex="-1">Create client</h2><p>The account will be sent for administrator approval.</p></div>
            <button class="icon-button" type="button" data-close-client-create aria-label="Close create client"><?=ui_icon('x')?></button>
        </header>
        <form method="POST" enctype="multipart/form-data" class="form-stack client-create-form">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="create_client">
            <div class="client-create-fields">
                <label>Full name<input class="form-control" name="full_name" autocomplete="name" required></label>
                <label>Email<input class="form-control" type="email" name="email" autocomplete="email" required></label>
                <label>Phone<input class="form-control" type="tel" name="phone" autocomplete="tel"></label>
                <div class="form-grid-two">
                    <label>Emergency contact name<input class="form-control" name="emergency_contact_name"></label>
                    <label>Emergency contact number<input class="form-control" type="tel" name="emergency_contact_phone"></label>
                </div>
                <label>Address<input class="form-control" name="address" autocomplete="street-address"></label>
                <label class="client-photo-upload">
                    <span class="field-label-row">Profile photo <span class="optional-label">Optional</span></span>
                    <input class="form-control" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
                    <small>JPG, PNG, or WEBP. Up to 3 MB.</small>
                </label>
                <label>Temporary password<input class="form-control" type="password" name="password" autocomplete="new-password" data-password-input required minlength="10" placeholder="Create a temporary password" aria-describedby="clientCreatePasswordRules"></label>
                <div class="password-requirements" id="clientCreatePasswordRules" data-password-requirements>
                    <span data-rule="length">At least 10 characters</span><span data-rule="upper">One uppercase letter</span><span data-rule="lower">One lowercase letter</span><span data-rule="number">One number</span><span data-rule="symbol">One symbol</span>
                </div>
            </div>
            <div class="form-actions">
                <button class="button-secondary" type="button" data-close-client-create>Cancel</button>
                <button class="button-primary" type="submit">Create pending client</button>
            </div>
        </form>
    </div>
</section>

<section class="calendar-dialog" id="clientEditDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="clientEditTitle"><div class="dialog-scrim" data-close-client-edit aria-hidden="true"></div><div class="dialog-card client-detail-dialog shell-ui-no-scroll"><header><div class="client-dialog-heading"><span id="clientEditAvatar" aria-hidden="true"></span><div><span class="eyebrow">Client record · Client</span><div class="client-dialog-title-row"><h2 id="clientEditTitle" tabindex="-1">Edit client</h2><div id="clientEditStatusBadge" aria-live="polite"></div></div></div></div><button class="icon-button" type="button" data-close-client-edit aria-label="Close client account window"><?=ui_icon('x')?></button></header><form method="POST" enctype="multipart/form-data" class="form-stack client-account-form" id="clientEditForm"><?=csrf_field()?><input type="hidden" name="action" id="clientEditAction" value="update_client"><input type="hidden" name="id" id="clientEditId"><div class="client-account-main-grid account-window-columns"><div class="account-window-column"><label>Full name<input class="form-control client-name-box" name="full_name" id="clientEditName" required></label><label>Email<input class="form-control" type="email" name="email" id="clientEditEmail" required></label><label>Phone<input class="form-control" name="phone" id="clientEditPhone"></label></div><div class="account-window-column"><label class="client-address-field">Address<textarea class="form-control" name="address" id="clientEditAddress" rows="4"></textarea></label><div class="client-emergency-grid"><label>Emergency contact name<input class="form-control" name="emergency_contact_name" id="clientEditEmergencyName"></label><label>Emergency contact number<input class="form-control" name="emergency_contact_phone" id="clientEditEmergencyPhone"></label></div><div class="client-photo-upload"><span>Upload or replace photo</span><div class="client-upload-row"><label class="button-secondary client-upload-button" for="clientEditPhoto"><?=ui_icon('upload')?>Upload photo</label><span class="client-upload-name" id="clientEditPhotoName">No file selected</span></div><input class="visually-hidden" id="clientEditPhoto" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" data-file-name-output="clientEditPhotoName"><small>Leave blank to keep the current photo.</small><button class="button-secondary" type="submit" formnovalidate data-client-action="remove_photo" data-action-visible="photo" data-confirm-message="Remove this profile photo?">Remove photo</button></div></div>
<details class="client-account-management" id="clientAccountManagement">
<summary>Account access <span>Approval, sign-in and account removal</span></summary>
<p id="clientAccountActionHelp"></p>
<div class="client-account-management-buttons"><button class="button-secondary" type="submit" formnovalidate data-client-action="approve_client" data-action-visible="approve">Approve and issue OTP</button>
<button class="button-secondary" type="submit" formnovalidate data-client-action="resend_otp" data-action-visible="resend">Resend OTP</button>
<button class="button-danger" type="submit" formnovalidate data-client-action="reject_client" data-action-visible="reject" data-confirm-message="Reject this client registration?">Reject account</button>
<button class="button-secondary" type="submit" formnovalidate data-client-action="deactivate_client" data-action-visible="deactivate" data-confirm-message="Deactivate this client account?">Deactivate</button>
<button class="button-secondary" type="submit" formnovalidate data-client-action="restore_client" data-action-visible="restore">Restore account</button>
<button class="button-danger" type="submit" formnovalidate data-client-action="delete_client" data-action-visible="delete" data-confirm-message="Delete this client account from active use? Linked clinic records will remain.">Delete</button></div>
<small>These actions are separate from saving client details.</small>
</details></div><div class="client-account-actions" aria-label="Save or close client record"><button class="button-secondary" type="button" data-close-client-edit>Close</button><button class="button-primary" type="submit" data-client-action="update_client" data-action-visible="save">Save client details</button></div></form></div></section>
<script>
(()=>{
 const create=document.getElementById('clientCreateDialog');
 const edit=document.getElementById('clientEditDialog');
 const form=document.getElementById('clientEditForm');
 const actionInput=document.getElementById('clientEditAction');
 const photo=document.getElementById('clientEditPhoto');
 const photoName=document.getElementById('clientEditPhotoName');
 const returnTo=new URLSearchParams(location.search).get('return_to');
 const openers=new WeakMap();
 const localDialogs=[create,edit].filter(Boolean);
 const syncBodyLock=()=>document.body.classList.toggle('overlay-open',Boolean(document.querySelector('.app-dialog.open,.calendar-dialog.open,.appt-modal.show')));
 const safeReturnPath=value=>value&&!/^(?:[a-z]+:|\/\/)/i.test(value)&&!value.includes('..')&&/^[A-Za-z0-9_\/-]+\.php(?:\?[^#]*)?(?:#.*)?$/.test(value);
 const focusable=dialog=>[...dialog.querySelectorAll('button:not([disabled]),[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(el=>!el.hidden&&el.getClientRects().length);
 const open=(dialog,trigger)=>{
   if(!dialog)return;
   localDialogs.forEach(other=>{if(other!==dialog){other.classList.remove('open');other.setAttribute('aria-hidden','true')}});
   openers.set(dialog,trigger||document.activeElement);
   dialog.classList.add('open');dialog.setAttribute('aria-hidden','false');syncBodyLock();
   const fields=dialog.querySelector('.client-create-fields');if(fields)fields.scrollTop=0;
   requestAnimationFrame(()=>{const initial=document.getElementById(dialog.getAttribute('aria-labelledby'))||focusable(dialog)[0];initial?.focus()});
 };
 const close=(dialog,{restoreFocus=true}={})=>{
   if(!dialog||!dialog.classList.contains('open'))return;
   dialog.classList.remove('open');dialog.setAttribute('aria-hidden','true');syncBodyLock();
   if(restoreFocus){const opener=openers.get(dialog);if(opener?.isConnected)setTimeout(()=>opener.focus(),0)}
   if(dialog===edit&&safeReturnPath(returnTo))location.href=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'')+'/'+returnTo.replace(/^\/+/, '');
 };
 const renderAvatar=client=>{
   const host=document.getElementById('clientEditAvatar');if(!host)return;
   const avatar=document.createElement('span');avatar.className='user-avatar settings-avatar';
   const path=String(client.profile_photo||'');
   if(/^uploads\/profiles\/[A-Za-z0-9._\/-]+$/.test(path)){
     const image=document.createElement('img');image.src='../'+path;image.alt=String(client.full_name||'Client')+' profile photo';avatar.appendChild(image);
   }else{
     avatar.textContent=String(client.full_name||'V').trim().split(/\s+/).slice(0,2).map(part=>part.charAt(0)).join('').toUpperCase()||'V';
   }
   host.replaceChildren(avatar);
 };
 const renderStatus=effective=>{
   const host=document.getElementById('clientEditStatusBadge');if(!host)return;
   const status=document.createElement('span');
   const tone=effective==='active'?'success':(['pending','otp_needed'].includes(effective)?'warning':(['rejected','deleted'].includes(effective)?'danger':'secondary'));
   status.className='badge text-bg-'+tone;status.textContent=effective.replaceAll('_',' ');host.replaceChildren(status);
 };
 document.querySelector('[data-open-client-create]')?.addEventListener('click',event=>open(create,event.currentTarget));
 document.querySelectorAll('[data-close-client-create]').forEach(button=>button.addEventListener('click',()=>close(create)));
 document.querySelectorAll('[data-close-client-edit]').forEach(button=>button.addEventListener('click',()=>close(edit)));
 document.querySelectorAll('[data-client-action]').forEach(button=>button.addEventListener('click',()=>{actionInput.value=button.dataset.clientAction}));
 document.querySelectorAll('[data-edit-client]').forEach(button=>button.addEventListener('click',event=>{
   event.stopPropagation();let c;try{c=JSON.parse(button.dataset.editClient)}catch(_){if(typeof showToast==='function')showToast('This client account could not be opened.','error');return}
   const effective=c.deleted_at?'deleted':(c.status==='approved'&&!c.otp_verified_at?'otp_needed':c.status);
   const editable=['pending','otp_needed','active'].includes(effective);
   document.getElementById('clientEditTitle').textContent=c.full_name||'Client account';if(photo){photo.value='';photoName.textContent='No file selected'}
   document.getElementById('clientEditId').value=c.id;
   document.getElementById('clientEditName').value=c.full_name||'';
   document.getElementById('clientEditEmail').value=c.email||'';
   document.getElementById('clientEditPhone').value=c.phone||'';
   document.getElementById('clientEditAddress').value=c.address||'';
   let eName=c.emergency_contact_name||'', ePhone=c.emergency_contact_phone||'';
   if(!eName&&!ePhone&&c.emergency_contact){const parts=c.emergency_contact.split(' | ');eName=parts[0]||'';ePhone=parts[1]||'';}
   document.getElementById('clientEditEmergencyName').value=eName;
   document.getElementById('clientEditEmergencyPhone').value=ePhone;
   renderStatus(effective);renderAvatar(c);
   form.querySelectorAll('.client-account-main-grid input:not([type="hidden"]),.client-account-main-grid textarea,.client-account-main-grid select').forEach(control=>{control.disabled=!editable});
   form.classList.toggle('account-window-readonly',!editable);
   const visible={save:editable,approve:effective==='pending',resend:effective==='otp_needed',reject:['pending','otp_needed'].includes(effective),deactivate:effective==='active',restore:effective==='inactive',delete:effective==='active',photo:!!c.profile_photo};
   form.querySelectorAll('[data-action-visible]').forEach(el=>{el.hidden=!visible[el.dataset.actionVisible];el.disabled=el.hidden});
   const management=document.getElementById('clientAccountManagement');
   management.hidden=!['approve','resend','reject','deactivate','restore','delete'].some(key=>visible[key]);
   management.open=['pending','otp_needed','inactive'].includes(effective);
   document.getElementById('clientAccountActionHelp').textContent={
     pending:'This registration is waiting for approval. Approve it to email a one-time verification code, or reject the registration.',
     otp_needed:'The account is approved and waiting for email verification. Resend the code if the client needs it.',
     active:'This client can sign in. Deactivate to pause access, or delete to remove the account from active use. Linked clinic records are kept.',
     inactive:'Sign-in is disabled. Restore the account to allow access again.'
   }[effective]||'';
   actionInput.value='update_client';open(edit,button);
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
 <?php if($clientFocusId):?>document.querySelectorAll('[data-edit-client]').forEach(button=>{try{const client=JSON.parse(button.dataset.editClient);if(Number(client.id)===<?=$clientFocusId?>)setTimeout(()=>button.click(),80)}catch(_){}});<?php endif;?>
})();
</script>
</main></div><?php include "../includes/footer.php"; ?>
