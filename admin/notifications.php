<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['create','update'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $titleText = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $type = $_POST['type'] ?? 'system';
        $status = $_POST['status'] ?? 'unread';
        if (!in_array($type, ['system','appointment','vaccine','record','qr','feedback'], true)) $type = 'system';
        if (!in_array($status, ['unread','read'], true)) $status = 'unread';
        $targetStmt = $conn->prepare("SELECT id FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $targetStmt->bind_param('i', $userId);
        $targetStmt->execute();
        $target = $targetStmt->get_result()->fetch_assoc();
        if (!$target || $titleText === '' || $message === '') {
            flash('error', 'Recipient, title, and message are required.');
        } elseif ($action === 'create') {
            $actor = (int)current_user_id();
            $stmt = $conn->prepare("INSERT INTO notifications(user_id,title,message,type,status,created_by) VALUES(?,?,?,?,?,?)");
            $stmt->bind_param('issssi', $userId, $titleText, $message, $type, $status, $actor);
            $stmt->execute();
            log_action($conn, 'Created notification', 'notification', $stmt->insert_id, 'Sent to user ' . $userId . '.');
            flash('success', 'Notification created.');
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET user_id=?,title=?,message=?,type=?,status=?,updated_at=NOW() WHERE id=?");
            $stmt->bind_param('issssi', $userId, $titleText, $message, $type, $status, $id);
            $stmt->execute();
            log_action($conn, 'Updated notification', 'notification', $id, 'Notification content or status updated.');
            flash('success', 'Notification updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE notifications SET title=CONCAT('[Deleted] ',title),status='read',updated_at=NOW() WHERE id=? AND title NOT LIKE '[Deleted] %'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_action($conn, 'Deleted notification', 'notification', $id, 'Notification was removed from active feeds while its stored details were retained.');
        flash('success', 'Notification deleted.');
    } elseif ($action === 'resend') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT user_id,title,message,type,action_url FROM notifications WHERE id=? AND title NOT LIKE '[Deleted] %' LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $source = $stmt->get_result()->fetch_assoc();
        if ($source) {
            $actor = (int)current_user_id();
            $limit=$conn->prepare("SELECT COUNT(*) c FROM audit_logs WHERE actor_user_id=? AND action='Resent notification' AND entity_type='notification' AND entity_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
            $limit->bind_param('ii',$actor,$id);$limit->execute();
            if((int)($limit->get_result()->fetch_assoc()['c']??0)>=3){
                flash('error','This notification has already been resent three times within the last 10 minutes.');
            } else {
                $insert = $conn->prepare("INSERT INTO notifications(user_id,title,message,type,status,action_url,created_by) VALUES(?,?,?,?,'unread',?,?)");
                $insert->bind_param('issssi', $source['user_id'], $source['title'], $source['message'], $source['type'], $source['action_url'], $actor);
                $insert->execute();
                log_action($conn, 'Resent notification', 'notification', $id, 'Created new notification ID ' . $insert->insert_id . '.');
                flash('success', 'Notification resent as a new unread alert.');
            }
        } else {
            flash('error', 'Notification not found.');
        }
    }
    redirect_to('admin/notifications.php');
}

$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
[$page,$perPage,$offset] = pagination_values(6,24,[6,12,18,24,'full']);
$conditions = ["n.title NOT LIKE '[Deleted] %'"];
if (in_array($statusFilter,['read','unread'],true)) $conditions[] = "n.status='".$conn->real_escape_string($statusFilter)."'";
if (in_array($typeFilter,['system','appointment','vaccine','record','qr','feedback'],true)) $conditions[] = "n.type='".$conn->real_escape_string($typeFilter)."'";
if ($q !== '') {
    $safe = $conn->real_escape_string($q);
    $conditions[] = "(n.title LIKE '%$safe%' OR n.message LIKE '%$safe%' OR u.full_name LIKE '%$safe%' OR u.email LIKE '%$safe%')";
}
$where = $conditions ? 'WHERE '.implode(' AND ',$conditions) : '';
$total = (int)$conn->query("SELECT COUNT(*) c FROM notifications n JOIN users u ON n.user_id=u.id $where")->fetch_assoc()['c'];
$rows = $conn->query("SELECT n.*,u.full_name,u.email,u.role FROM notifications n JOIN users u ON n.user_id=u.id $where ORDER BY n.status='unread' DESC,n.created_at DESC LIMIT $perPage OFFSET $offset")->fetch_all(MYSQLI_ASSOC);
$summary = $conn->query("SELECT COUNT(*) total,SUM(status='unread') unread,SUM(DATE(created_at)=CURDATE()) today FROM notifications WHERE title NOT LIKE '[Deleted] %'")->fetch_assoc();
$todayTypeRows = $conn->query("SELECT type,COUNT(*) c,SUM(status='unread') unread FROM notifications WHERE title NOT LIKE '[Deleted] %' AND DATE(created_at)=CURDATE() GROUP BY type ORDER BY c DESC,type")->fetch_all(MYSQLI_ASSOC);
$todayRecentRows = $conn->query("SELECT n.title,n.type,n.status,n.created_at,u.full_name FROM notifications n JOIN users u ON n.user_id=u.id WHERE n.title NOT LIKE '[Deleted] %' AND DATE(n.created_at)=CURDATE() ORDER BY n.created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$todayHtml = '<div class="notification-today-overview"><div class="notification-today-overview-grid">';
if ($todayTypeRows) {
    foreach ($todayTypeRows as $todayType) $todayHtml .= '<div><small>'.e(ucfirst($todayType['type'])).'</small><b>'.(int)$todayType['c'].' created</b><span>'.(int)$todayType['unread'].' unread</span></div>';
} else {
    $todayHtml .= '<div><small>Today</small><b>No notifications</b><span>No alerts have been created yet.</span></div>';
}
$todayHtml .= '</div><div class="notification-today-recent"><b>Latest today</b>';
if ($todayRecentRows) {
    foreach ($todayRecentRows as $todayRow) $todayHtml .= '<div><span><b>'.e($todayRow['title']).'</b><small>'.e($todayRow['full_name']).' · '.e(ucfirst($todayRow['type'])).'</small></span><time>'.date('h:i A',strtotime($todayRow['created_at'])).'</time></div>';
} else $todayHtml .= '<p>No notification activity today.</p>';
$todayHtml .= '</div></div>';
$unreadRows = $conn->query("SELECT n.*,u.full_name,u.email,u.role FROM notifications n JOIN users u ON n.user_id=u.id WHERE n.status='unread' AND n.title NOT LIKE '[Deleted] %' ORDER BY n.created_at DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
$users = $conn->query("SELECT id,full_name,email,role FROM users WHERE status IN ('active','approved') AND deleted_at IS NULL ORDER BY role,full_name")->fetch_all(MYSQLI_ASSOC);
$title = 'Notification Management';
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/admin_sidebar.php"; ?><main class="content admin-notifications-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Communication</span><h1>Notification Management</h1></div><button class="button-primary" type="button" data-open-notification-editor><?=ui_icon('plus')?>New notification</button></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>

<section class="metric-grid dashboard-metrics notification-summary-grid" aria-label="Notification totals">
<button class="metric-card" type="button" data-record-detail='<?=e(json_encode(['title'=>'Total notifications','eyebrow'=>'All in-system alerts','fields'=>['Total'=>(int)$summary['total'],'Unread'=>(int)$summary['unread'],'Created today'=>(int)$summary['today']]]))?>'><span class="metric-icon"><?=ui_icon('bell')?></span><div><small>Total notifications</small><strong><?=(int)$summary['total']?></strong><p>All in-system alerts.</p></div></button>
<button class="metric-card" type="button" data-open-unread><span class="metric-icon warning"><?=ui_icon('alert')?></span><div><small>Unread</small><strong><?=(int)$summary['unread']?></strong><p>Open unread alerts.</p></div></button>
<article class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Notifications created today','eyebrow'=>date('F j, Y'),'html'=>$todayHtml]))?>'><span class="metric-icon info"><?=ui_icon('clock')?></span><div><small>Created today</small><strong><?=(int)$summary['today']?></strong><p>Review today’s alerts.</p></div></article>
<article class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Current feed results','eyebrow'=>'Applied filters','fields'=>['Visible results'=>$total,'Status'=>ucfirst($statusFilter),'Type'=>ucfirst($typeFilter),'Search'=>$q ?: 'None']]))?>'><span class="metric-icon success"><?=ui_icon('eye')?></span><div><small>Visible results</small><strong><?=$total?></strong><p>Current filter output.</p></div></article>
</section>

<section class="surface-card notification-management-card" id="notificationFeedCard">
<div class="section-heading"><div><h2>Notification feed</h2></div></div>
<form class="data-search-bar notification-feed-search notification-admin-filter" method="GET"><input type="search" name="q" value="<?=e($q)?>" placeholder="Search recipient, title, or message"><select class="form-select" name="status"><option value="all">All statuses</option><option value="unread" <?=$statusFilter==='unread'?'selected':''?>>Unread</option><option value="read" <?=$statusFilter==='read'?'selected':''?>>Read</option></select><input type="hidden" name="type" value="<?=e($typeFilter)?>"><input type="hidden" name="per_page" value="<?=e(per_page_value($perPage))?>"><button class="button-primary" type="submit">Search</button><a class="button-secondary" href="notifications.php?per_page=<?=e(per_page_value($perPage))?>">Clear</a></form>
<div class="notification-category-toolbar"><nav class="filter-tabs compact notification-category-tabs" aria-label="Notification categories"><?php foreach(['all'=>'All','system'=>'System','appointment'=>'Appointment','vaccine'=>'Vaccination','record'=>'Record','qr'=>'QR token','feedback'=>'Feedback'] as $categoryKey=>$categoryLabel):?><a class="filter-tab status-<?=e($categoryKey)?> <?=$typeFilter===$categoryKey?'active':''?>" href="?<?=e(http_build_query(['q'=>$q,'status'=>$statusFilter,'type'=>$categoryKey,'per_page'=>per_page_value($perPage)]))?>#notificationFeedCard"><?=e($categoryLabel)?></a><?php endforeach;?></nav><form class="notification-show-control" method="GET"><input type="hidden" name="q" value="<?=e($q)?>"><input type="hidden" name="status" value="<?=e($statusFilter)?>"><input type="hidden" name="type" value="<?=e($typeFilter)?>"><label class="entries-select">Show<select class="unified-show-select" name="per_page" onchange="this.form.submit()"><?=render_per_page_options($perPage,[6,12,18,24,'full'])?></select></label></form></div>
<div class="notification-feed-controls"><span class="notification-match-count"><b><?=$total?></b> matching notification<?=$total===1?'':'s'?></span></div>
<div class="notification-admin-list condensed">
<?php if(!$rows):?><div class="empty-state"><span><?=ui_icon('bell')?></span><h3>No notifications found</h3><p>Adjust the filters or create a new notification.</p></div><?php endif;?>
<?php foreach($rows as $r): $payload=['id'=>$r['id'],'user_id'=>$r['user_id'],'title'=>$r['title'],'message'=>$r['message'],'type'=>$r['type'],'status'=>$r['status'],'action_url'=>$r['action_url']??'','full_name'=>$r['full_name'],'email'=>$r['email'],'role'=>$r['role'],'created_at'=>$r['created_at']]; ?>
<article class="notification-admin-item notification-type-<?=e($r['type'])?> <?=$r['status']==='unread'?'is-unread':''?>" data-notification-row><button class="notification-main" type="button" data-view-notification='<?=e(json_encode($payload))?>'><span class="notification-type-icon"><?=ui_icon(notification_icon_name($r['type']))?></span><span class="notification-copy"><span class="notification-title-row"><b><?=e($r['title'])?></b><?=badge($r['status'])?></span><small><?=e($r['full_name'])?> · <?=e(role_label($r['role']))?> · <?=date('M d, Y h:i A',strtotime($r['created_at']))?></small><p><?=e(mb_strimwidth($r['message'],0,160,'…'))?></p></span></button><div class="notification-row-actions"><button class="icon-button" type="button" title="Edit notification" data-edit-notification='<?=e(json_encode($payload))?>'><?=ui_icon('edit')?></button><form method="POST"><?=csrf_field()?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="icon-button" type="submit" title="Resend notification"><?=ui_icon('refresh')?></button></form><form method="POST" data-confirm-message="Delete this notification?"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="icon-button danger" type="submit" title="Delete notification"><?=ui_icon('trash')?></button></form></div></article>
<?php endforeach;?>
</div>
<?=render_pagination($page,$perPage,$total,['q'=>$q,'status'=>$statusFilter,'type'=>$typeFilter,'per_page'=>per_page_value($perPage),'_anchor'=>'notificationFeedCard'])?>
</section>

<section class="calendar-dialog" id="notificationEditor" aria-hidden="true"><div class="dialog-scrim" data-close-notification-editor></div><div class="dialog-card"><header><div><span class="eyebrow">In-system alert</span><h2 id="notificationEditorTitle">New notification</h2></div><button class="icon-button" type="button" data-close-notification-editor><?=ui_icon('x')?></button></header><form method="POST" class="form-stack" id="notificationEditorForm"><?=csrf_field()?><input type="hidden" name="action" id="notificationAction" value="create"><input type="hidden" name="id" id="notificationId"><label>Recipient<select class="form-select" name="user_id" id="notificationUser" required><option value="">Choose account</option><?php foreach($users as $u):?><option value="<?=$u['id']?>"><?=e($u['full_name'].' · '.role_label($u['role']).' · '.$u['email'])?></option><?php endforeach;?></select></label><div class="form-grid-two"><label>Type<select class="form-select" name="type" id="notificationType"><?php foreach(['system','appointment','vaccine','record','qr','feedback'] as $type):?><option value="<?=$type?>"><?=ucfirst($type)?></option><?php endforeach;?></select></label><label>Status<select class="form-select" name="status" id="notificationStatus"><option value="unread">Unread</option><option value="read">Read</option></select></label></div><label>Title<input class="form-control" name="title" id="notificationTitle" maxlength="160" required></label><label>Message<textarea class="form-control" name="message" id="notificationMessage" rows="6" required></textarea></label><div class="inline-notice"><?=ui_icon('bell')?>This sends an in-system notification to the selected account.</div><div class="form-actions"><button class="button-secondary" type="button" data-close-notification-editor>Cancel</button><button class="button-primary" type="submit">Save notification</button></div></form></div></section>

<section class="calendar-dialog" id="notificationViewDialog" aria-hidden="true"><div class="dialog-scrim" data-close-notification-view></div><div class="dialog-card"><header><div><span class="eyebrow" id="notificationViewEyebrow">Notification details</span><h2 id="notificationViewTitle"></h2></div><button class="icon-button" type="button" data-close-notification-view><?=ui_icon('x')?></button></header><div class="notification-detail-body"><div class="detail-list" id="notificationViewMeta"></div><div class="notification-message-full" id="notificationViewMessage"></div><form method="POST" id="notificationResendForm"><?=csrf_field()?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" id="notificationResendId"><button class="button-primary" type="submit"><?=ui_icon('refresh')?>Resend</button></form></div></div></section>

<section class="calendar-dialog" id="unreadDialog" aria-hidden="true"><div class="dialog-scrim" data-close-unread></div><div class="dialog-card unread-notification-dialog"><header><div><span class="eyebrow">Unread notification details</span><h2>Recipients awaiting alerts</h2></div><button class="icon-button" type="button" data-close-unread><?=ui_icon('x')?></button></header><div class="unread-detail-list"><?php if(!$unreadRows):?><div class="empty-state"><p>No unread notifications.</p></div><?php endif;?><?php foreach($unreadRows as $r):$payload=['id'=>$r['id'],'title'=>$r['title'],'message'=>$r['message'],'type'=>$r['type'],'status'=>$r['status'],'action_url'=>$r['action_url']??'','full_name'=>$r['full_name'],'email'=>$r['email'],'role'=>$r['role'],'created_at'=>$r['created_at']];?><button type="button" class="unread-detail-item" data-view-notification='<?=e(json_encode($payload))?>'><span><?=ui_icon(notification_icon_name($r['type']))?></span><span><b><?=e($r['full_name'])?></b><small><?=e($r['title'])?> · <?=date('M d, h:i A',strtotime($r['created_at']))?></small><p><?=e(mb_strimwidth($r['message'],0,120,'…'))?></p></span></button><?php endforeach;?></div></div></section>

<script>
(()=>{
 const dialogs={editor:document.getElementById('notificationEditor'),view:document.getElementById('notificationViewDialog'),unread:document.getElementById('unreadDialog')};
 const open=d=>{d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open')};
 const close=d=>{d.classList.remove('open');d.setAttribute('aria-hidden','true');if(!document.querySelector('.calendar-dialog.open'))document.body.classList.remove('overlay-open')};
 document.querySelectorAll('[data-close-notification-editor]').forEach(x=>x.addEventListener('click',()=>close(dialogs.editor)));
 document.querySelectorAll('[data-close-notification-view]').forEach(x=>x.addEventListener('click',()=>close(dialogs.view)));
 document.querySelectorAll('[data-close-unread]').forEach(x=>x.addEventListener('click',()=>close(dialogs.unread)));
 const fillEditor=n=>{document.getElementById('notificationEditorTitle').textContent=n?'Edit notification':'New notification';document.getElementById('notificationAction').value=n?'update':'create';document.getElementById('notificationId').value=n?.id||'';document.getElementById('notificationUser').value=n?.user_id||'';document.getElementById('notificationType').value=n?.type||'system';document.getElementById('notificationStatus').value=n?.status||'unread';document.getElementById('notificationTitle').value=n?.title||'';document.getElementById('notificationMessage').value=n?.message||'';open(dialogs.editor)};
 document.querySelector('[data-open-notification-editor]')?.addEventListener('click',()=>fillEditor(null));
 document.querySelectorAll('[data-edit-notification]').forEach(b=>b.addEventListener('click',()=>fillEditor(JSON.parse(b.dataset.editNotification))));
 const view=n=>{document.getElementById('notificationViewTitle').textContent=n.title;document.getElementById('notificationViewEyebrow').textContent=`${n.type} notification`;document.getElementById('notificationViewMeta').innerHTML=`<div><dt>Recipient</dt><dd>${n.full_name}</dd></div><div><dt>Account</dt><dd>${n.role} · ${n.email||''}</dd></div><div><dt>Sent</dt><dd>${new Date(n.created_at.replace(' ','T')).toLocaleString()}</dd></div><div><dt>Status</dt><dd>${n.status}</dd></div>`;document.getElementById('notificationViewMessage').textContent=n.message;document.getElementById('notificationResendId').value=n.id;close(dialogs.unread);open(dialogs.view)};
 document.querySelectorAll('[data-view-notification]').forEach(b=>b.addEventListener('click',()=>view(JSON.parse(b.dataset.viewNotification))));
 document.querySelector('[data-open-unread]')?.addEventListener('click',()=>open(dialogs.unread));
})();
</script>
</main></div><?php include "../includes/footer.php"; ?>
