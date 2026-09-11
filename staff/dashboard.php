<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_role('staff');

function staff_dashboard_map($result, $callback){
    $items=[];
    while($result && $row=$result->fetch_assoc()){
        $items[]=$callback($row);
    }
    return $items;
}
function staff_dashboard_list($items, $icon){
    if(!$items) return '<div class="empty-state compact"><span class="empty-icon">'.ui_icon($icon).'</span><p>No matching records found.</p></div>';
    $html='<div class="dashboard-ui-overview-list clinical-queue-popup-list">';
    foreach($items as $item){
        $tag=!empty($item[3])?'a':'article';$href=!empty($item[3])?' href="'.e(app_url($item[3])).'"':'';$extra=!empty($item[4])?' '.e($item[4]):'';$html.='<'.$tag.' class="dashboard-ui-overview-entry'.$extra.'"'.$href.'><span>'.ui_icon($icon).'</span><div><b>'.e($item[0]).'</b><small>'.e($item[1]).'</small><em>'.e($item[2]).'</em></div><strong>Open</strong></'.$tag.'>';
    }
    return $html.'</div>';
}
function staff_dashboard_detail($title,$eyebrow,$html,$url='',$label=''){
    $detail=['title'=>$title,'eyebrow'=>$eyebrow,'html'=>$html];
    if(trim((string)$url)!==''){$detail['action_url']=app_url($url);$detail['action_label']=$label!==''?$label:'Open';}
    return e(json_encode($detail, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

$pending=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE status='pending'")->fetch_assoc()['c'];
$today=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE DATE(COALESCE(scheduled_date,requested_date))=CURDATE()")->fetch_assoc()['c'];
$low=(int)$conn->query("SELECT COUNT(*) c FROM inventory_items WHERE status IN ('low_stock','out_of_stock')")->fetch_assoc()['c'];
$clients=(int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='client'")->fetch_assoc()['c'];
$attention=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE (status='pending' AND requested_date<NOW()) OR (status='approved' AND (scheduled_date IS NULL OR assigned_vet_id IS NULL OR scheduled_date<NOW()))")->fetch_assoc()['c'];
$leavePending=(int)$conn->query("SELECT COUNT(*) c FROM staff_availability WHERE user_id=".(int)current_user_id()." AND event_kind='unavailable' AND reason LIKE '[Pending]%' AND ends_at>=NOW()")->fetch_assoc()['c'];

$pendingItems=staff_dashboard_map($conn->query("SELECT a.*,u.full_name owner_name,p.name pet_name,p.species FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id WHERE a.status='pending' ORDER BY a.requested_date ASC"), function($r){return [$r['pet_name'], $r['species'], $r['owner_name'].' · '.date('M d, Y h:i A',strtotime($r['requested_date'])).' · '.($r['reason'] ?: 'No concern recorded'),'staff/appointments.php?appointment_id='.$r['id']];});
$todayItems=staff_dashboard_map($conn->query("SELECT a.*,u.full_name owner_name,p.name pet_name,p.species,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id WHERE DATE(COALESCE(a.scheduled_date,a.requested_date))=CURDATE() ORDER BY COALESCE(a.scheduled_date,a.requested_date) ASC"), function($r){return [$r['pet_name'], $r['species'], $r['owner_name'].' · '.date('h:i A',strtotime($r['scheduled_date']?:$r['requested_date'])).' · '.ucfirst($r['status']).' · '.($r['vet_name'] ?: 'Veterinarian not assigned'),'staff/appointments.php?appointment_id='.$r['id']];});
$inventoryItems=staff_dashboard_map($conn->query("SELECT id,item_name,sku,stock_qty,unit,status FROM inventory_items WHERE status IN ('low_stock','out_of_stock') ORDER BY FIELD(status,'out_of_stock','low_stock'),stock_qty ASC"), function($r){return [$r['item_name'], strtoupper(str_replace('_',' ',$r['status'])).' · '.$r['stock_qty'].' '.$r['unit'], $r['sku'] ?: 'No SKU','staff/inventory.php?edit_item='.$r['id'].'#currentInventory'];});
$clientItems=staff_dashboard_map($conn->query("SELECT id,full_name,email,phone,status,created_at FROM users WHERE role='client' ORDER BY created_at DESC,id DESC"), function($r){return [$r['full_name'], ucwords(str_replace('_',' ',$r['status'])).' · '.($r['phone'] ?: 'No phone'), $r['email'] ?: 'No email','staff/clients.php?client_id='.$r['id'],'staff-client-account-entry'];});
$leaveItems=staff_dashboard_map($conn->query("SELECT id,starts_at,ends_at,reason FROM staff_availability WHERE user_id=".(int)current_user_id()." AND event_kind='unavailable' AND reason LIKE '[Pending]%' AND ends_at>=NOW() ORDER BY starts_at ASC"), function($r){return [date('M d, Y h:i A',strtotime($r['starts_at'])), 'Pending unavailability request', preg_replace('/^\[Pending\]\s*/','',$r['reason']) ?: 'No reason recorded','staff/calendar.php?scope=workforce&workforce=unavailable&availability_id='.$r['id']];});

$pendingHtml=staff_dashboard_list($pendingItems,'calendar-days');
$todayHtml=staff_dashboard_list($todayItems,'clock');
$inventoryHtml=staff_dashboard_list($inventoryItems,'inventory');
$clientHtml='<div class="dashboard-ui-overview-list staff-client-account-grid">'.preg_replace('#^<div class="dashboard-ui-overview-list">|</div>$#','',staff_dashboard_list($clientItems,'users')).'</div>';
$leaveHtml=staff_dashboard_list($leaveItems,'clock');

$appointments=$conn->query("SELECT a.*,u.full_name owner_name,p.name pet_name,p.species,p.pet_photo,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id ORDER BY FIELD(a.status,'pending','approved','completed','rejected','cancelled'),COALESCE(a.scheduled_date,a.requested_date) ASC LIMIT 5");
$sales=$conn->query("SELECT t.*,c.full_name client_name FROM pos_transactions t LEFT JOIN users c ON t.client_id=c.id ORDER BY t.transaction_date DESC LIMIT 5");
$queue=[
    ['Inventory attention',$low,'Items at low or zero stock.','staff/inventory.php','inventory','danger',1,$inventoryHtml],
    ['Pending appointments',$pending,'Review schedule requests and assign a veterinarian.','staff/appointments.php?status=pending','calendar-days','warning',2,$pendingHtml],
    ['Today schedule',$today,'Appointments scheduled or requested today.','staff/calendar.php','clock','info',3,$todayHtml],
    ['My leave requests',$leavePending,'Pending unavailability requests awaiting administrator review.','staff/calendar.php?scope=workforce&workforce=unavailable','clock','info',4,$leaveHtml],
    ['Client accounts',$clients,'Client profiles available for staff assistance.','staff/clients.php','users','success',5,$clientHtml],
];
usort($queue,function($a,$b){return [$a[6],-$a[1]] <=> [$b[6],-$b[1]];});
$title='Staff Dashboard';include '../includes/header.php';include '../includes/navbar.php';
?>
<div class="layout"><?php include '../includes/staff_sidebar.php';?><main class="content staff-dashboard-page" id="mainContent">
<header class="page-heading dashboard-heading vetrix-dashboard-hero">
  <div class="dashboard-hero-copy">
    <span class="eyebrow">Welcome back</span>
    <h1><?=e($_SESSION['full_name'] ?? 'Clinic Staff')?>!</h1>
    <p>Here is your clinic operations overview for today.</p>
  </div>
  <div class="heading-actions"><a class="button-secondary" href="<?=app_url('staff/calendar.php')?>"><?=ui_icon('calendar-days')?>Calendar</a><a class="button-primary" href="<?=app_url('staff/pos.php')?>"><?=ui_icon('cart')?>Open POS</a></div>
  <div class="dashboard-hero-art" aria-hidden="true"><img src="<?=e(app_url('assets/images/vetrix-dashboard-dog.png'))?>" alt=""></div>
</header>
<section class="metric-grid"><button type="button" class="metric-card" data-record-detail='<?=staff_dashboard_detail('Pending appointments','Clinic staff overview',$pendingHtml,'staff/appointments.php?status=pending','Open appointments')?>'><span class="metric-icon warning"><?=ui_icon('clock')?></span><div><small>Pending appointments</small><strong><?=$pending?></strong><p>Requests waiting for staff action.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=staff_dashboard_detail('Appointments today','Clinic staff overview',$todayHtml,'staff/calendar.php','Open calendar')?>'><span class="metric-icon info"><?=ui_icon('calendar-days')?></span><div><small>Appointments today</small><strong><?=$today?></strong><p>Schedules and requests dated today.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=staff_dashboard_detail('Inventory attention','Clinic staff overview',$inventoryHtml,'staff/inventory.php','Open inventory')?>'><span class="metric-icon danger"><?=ui_icon('inventory')?></span><div><small>Inventory attention</small><strong><?=$low?></strong><p>Items at low stock or out of stock.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=staff_dashboard_detail('Client accounts','Clinic staff overview',$clientHtml,'staff/clients.php','Open clients')?>'><span class="metric-icon success"><?=ui_icon('users')?></span><div><small>Client accounts</small><strong><?=$clients?></strong><p>Client profiles available for assistance.</p></div></button></section>
<?php include '../includes/dashboard_schedule.php'; ?>
<div class="dashboard-main-grid dashboard-ui-equal"><section class="surface-card workflow-queue-card"><div class="section-heading"><div><span class="eyebrow">Action queue</span><h2>Clinic staff tasks</h2></div></div><?php $queueColumns=array_chunk($queue,max(1,(int)ceil(count($queue)/2))); ?><div class="workflow-queue-list"><?php foreach($queueColumns as $queueColumn):?><div class="workflow-queue-column"><?php foreach($queueColumn as [$label,$count,$note,$url,$icon,$color,$priority,$html]):?><a class="workflow-queue-item queue-<?=e($color)?> <?=$count > 0 ? 'queue-has-items' : 'queue-empty'?>" href="<?=app_url($url)?>" data-record-detail='<?=staff_dashboard_detail($label,'Action queue','<div class="clinical-queue-popup-list">'.$html.'</div>','','')?>'><span class="queue-icon"><?=ui_icon($icon)?></span><div><b><?=e($label)?></b><p><?=e($note)?></p></div><span class="queue-count-wrap"><strong class="queue-count"><?=intval($count)?></strong><?php if($count>0 && $priority<=2):?><span class="queue-urgency" aria-label="Needs attention">!</span><?php endif;?></span><span class="queue-arrow"><?=ui_icon('chevron-right')?></span></a><?php endforeach;?></div><?php endforeach;?></div></section><aside class="surface-card quick-link-card"><div class="section-heading compact"><div><span class="eyebrow">Common tools</span><h2>Quick access</h2></div></div><div class="quick-link-list"><a href="<?=app_url('staff/appointments.php')?>" data-record-detail='<?=staff_dashboard_detail('Appointments','Common tool',$pendingHtml,'staff/appointments.php','Open appointments')?>'><?=ui_icon('calendar')?>Appointments<span>Review requests and update schedules</span></a><a href="<?=app_url('staff/clients.php')?>" data-record-detail='<?=staff_dashboard_detail('Client assistance','Common tool',$clientHtml,'staff/clients.php','Open clients')?>'><?=ui_icon('users')?>Client assistance<span>Create and review client accounts</span></a><a href="<?=app_url('staff/qr.php')?>" data-record-detail='<?=staff_dashboard_detail('QR token retrieval','Common tool','<p class="empty-copy">Enter an approved pet QR token to retrieve emergency details. Use this when a pet is presented at the clinic.</p>','staff/qr.php','Open QR token retrieval')?>'><?=ui_icon('qr')?>QR token retrieval<span>Retrieve approved emergency pet details</span></a><a href="<?=app_url('staff/pets.php')?>" data-record-detail='<?=staff_dashboard_detail('Pet encoding','Common tool','<p class="empty-copy">Create or update pet profiles for walk-in and registered clients.</p>','staff/pets.php','Open pet encoding')?>'><?=ui_icon('paw')?>Pet encoding<span>Create or update pet profiles for clients</span></a></div></aside></div>
<div class="dashboard-two-tables"><section class="surface-card dashboard-table-card"><div class="section-heading"><div><span class="eyebrow">Schedule queue</span><h2>Recent appointments</h2></div><a href="<?=app_url('staff/appointments.php')?>">View all</a></div><div class="table-scroll-only"><table class="data-table"><thead><tr><th>Pet</th><th>Client</th><th>Schedule</th><th>Veterinarian</th><th>Status</th></tr></thead><tbody><?php if(!$appointments->num_rows):?><tr><td colspan="5" class="empty-cell">No appointments found.</td></tr><?php endif;while($a=$appointments->fetch_assoc()):$rowDetail=['title'=>$a['pet_name'],'eyebrow'=>'Schedule queue','fields'=>['Pet'=>$a['pet_name'],'Animal type'=>$a['species'],'Client'=>$a['owner_name'],'Schedule'=>date('M d, Y h:i A',strtotime($a['scheduled_date']?:$a['requested_date'])),'Veterinarian'=>$a['vet_name']?:'Not assigned','Status'=>ucfirst($a['status'])]];?><tr data-record-detail='<?=e(json_encode($rowDetail))?>'><td><div class="table-identity"><?=pet_avatar_markup($a,'small')?><div class="staff-recent-pet-copy"><b><?=e($a['pet_name'])?></b><small><?=e($a['species'])?></small></div></div></td><td><?=e($a['owner_name'])?></td><td><?=date('M d, Y h:i A',strtotime($a['scheduled_date']?:$a['requested_date']))?></td><td><?=e($a['vet_name']?:'Not assigned')?></td><td><?=badge($a['status'])?></td></tr><?php endwhile;?></tbody></table></div></section><section class="surface-card dashboard-table-card"><div class="section-heading"><div><span class="eyebrow">Cashier history</span><h2>Recent POS sales</h2></div><a href="<?=app_url('staff/pos.php')?>">Open POS</a></div><div class="table-scroll-only"><table class="data-table"><thead><tr><th>Date</th><th>Client</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php if(!$sales->num_rows):?><tr><td colspan="4" class="empty-cell">No POS transactions found.</td></tr><?php endif;while($s=$sales->fetch_assoc()):?><tr><td><?=date('M d, Y h:i A',strtotime($s['transaction_date']))?></td><td><?=e($s['client_name']?:'Walk-in')?></td><td>₱<?=number_format((float)$s['total_amount'],2)?></td><td><?=badge($s['payment_status'])?></td></tr><?php endwhile;?></tbody></table></div></section></div>
</main></div><?php include '../includes/footer.php';?>
