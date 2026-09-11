<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("admin");

$todayAppointments = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE DATE(COALESCE(scheduled_date, requested_date))=CURDATE()")->fetch_assoc()['c'];
$pendingAppointments = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE status='pending'")->fetch_assoc()['c'];
$pendingPets = (int)$conn->query("SELECT COUNT(*) c FROM pets WHERE verification_status='pending'")->fetch_assoc()['c'];
$pendingUsers = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='client' AND status='pending'")->fetch_assoc()['c'];
$pendingEditRequests = (int)$conn->query("SELECT COUNT(*) c FROM edit_requests WHERE status='pending'")->fetch_assoc()['c'];
$lowStock = (int)$conn->query("SELECT COUNT(*) c FROM inventory_items WHERE status IN ('low_stock','out_of_stock')")->fetch_assoc()['c'];
$dueSoon = (int)$conn->query("SELECT COUNT(*) c FROM vaccinations WHERE next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetch_assoc()['c'];
$attentionAppointments = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE (status='pending' AND requested_date<NOW()) OR (status='approved' AND (scheduled_date IS NULL OR assigned_vet_id IS NULL OR scheduled_date<NOW()))")->fetch_assoc()['c'];
$pendingLeaveRequests = (int)$conn->query("SELECT COUNT(*) c FROM staff_availability WHERE event_kind='unavailable' AND reason LIKE '[Pending]%' AND ends_at>starts_at")->fetch_assoc()['c'];
$revenue = (float)$conn->query("SELECT COALESCE(SUM(total_amount),0) c FROM pos_transactions WHERE payment_status='paid' AND DATE(transaction_date)=CURDATE()")->fetch_assoc()['c'];

function dashboard_rows(mysqli $conn, string $sql): array {
    $rows = [];
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}

$todayAppointmentRows = dashboard_rows($conn, "SELECT a.id,a.status,a.reason,COALESCE(a.scheduled_date,a.requested_date) visit_time,u.full_name client_name,p.name pet_name,p.species,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id WHERE DATE(COALESCE(a.scheduled_date,a.requested_date))=CURDATE() ORDER BY visit_time,a.id");
$pendingAppointmentRows = dashboard_rows($conn, "SELECT a.id,a.reason,COALESCE(a.scheduled_date,a.requested_date) visit_time,u.full_name client_name,p.name pet_name,p.species FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id WHERE a.status='pending' ORDER BY visit_time,a.id");
$attentionAppointmentRows = dashboard_rows($conn, "SELECT a.id,a.status,a.reason,COALESCE(a.scheduled_date,a.requested_date) visit_time,u.full_name client_name,p.name pet_name,p.species,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id WHERE (a.status='pending' AND a.requested_date<NOW()) OR (a.status='approved' AND (a.scheduled_date IS NULL OR a.assigned_vet_id IS NULL OR a.scheduled_date<NOW())) ORDER BY visit_time,a.id");
$pendingLeaveRows = dashboard_rows($conn, "SELECT sa.id,sa.user_id,sa.starts_at,sa.ends_at,sa.reason,u.full_name,u.role FROM staff_availability sa JOIN users u ON sa.user_id=u.id WHERE sa.event_kind='unavailable' AND sa.reason LIKE '[Pending]%' AND sa.ends_at>sa.starts_at ORDER BY sa.starts_at,sa.id");
$dueVaccinationRows = dashboard_rows($conn, "SELECT v.id,v.vaccine_name,v.next_due_date,p.name pet_name,p.species,u.full_name client_name,vet.full_name vet_name FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id LEFT JOIN users vet ON v.administered_by_id=vet.id WHERE v.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) ORDER BY v.next_due_date,v.id");
$todaySalesRows = dashboard_rows($conn, "SELECT t.id,t.total_amount,t.payment_status,t.transaction_date,COALESCE(c.full_name,'Walk-in') client_name,COALESCE(h.full_name,'Clinic staff') handled_by_name,COUNT(i.id) line_count,COALESCE(SUM(i.quantity),0) item_count FROM pos_transactions t LEFT JOIN users c ON t.client_id=c.id LEFT JOIN users h ON t.handled_by=h.id LEFT JOIN pos_transaction_items i ON i.transaction_id=t.id WHERE t.payment_status='paid' AND DATE(t.transaction_date)=CURDATE() GROUP BY t.id ORDER BY t.transaction_date DESC,t.id DESC");
$overviewTab = $_GET['overview'] ?? 'appointments';
$overviewAllowed = ['appointments','activity','sales','inventory','notifications'];
if (!in_array($overviewTab, $overviewAllowed, true)) $overviewTab = 'appointments';

function dashboard_list_html(array $rows, callable $renderer, string $empty): string {
    if (!$rows) return '<div class="empty-state compact"><span class="empty-icon">' . ui_icon('paw') . '</span><p>' . e($empty) . '</p></div>';
    $html = '<div class="dashboard-detail-list">';
    foreach ($rows as $row) $html .= $renderer($row);
    return $html.'</div>';
}

$todayAppointmentHtml = dashboard_list_html($todayAppointmentRows, function(array $r): string {
    $when = date('M d, Y h:i A', strtotime($r['visit_time']));
    return '<a class="dashboard-detail-entry" href="'.e(app_url('admin/appointments.php?appointment_id='.$r['id'])).'"><i class="dashboard-detail-icon">'.ui_icon('paw').'</i><span><b>'.e($r['pet_name']).' · '.e($r['client_name']).'</b><small>'.e($when).' · '.e(ucfirst($r['status'])).'</small><em>'.e($r['reason'] ?: 'No reason provided').'</em></span><strong>Open</strong></a>';
}, 'No appointments are scheduled or requested today.');
$pendingAppointmentHtml = dashboard_list_html($pendingAppointmentRows, function(array $r): string {
    $when = date('M d, Y h:i A', strtotime($r['visit_time']));
    return '<a class="dashboard-detail-entry" href="'.e(app_url('admin/appointments.php?appointment_id='.$r['id'])).'"><i class="dashboard-detail-icon">'.ui_icon('paw').'</i><span><b>'.e($r['pet_name']).' · '.e($r['client_name']).'</b><small>'.e($when).' · Pending</small><em>'.e($r['reason'] ?: 'No reason provided').'</em></span><strong>Open</strong></a>';
}, 'No pending appointments need review.');
$attentionAppointmentHtml = dashboard_list_html($attentionAppointmentRows, function(array $r): string {
    $when = date('M d, Y h:i A', strtotime($r['visit_time']));
    $return = rawurlencode('admin/dashboard.php?queue=appointments#actionQueue');
    return '<a class="dashboard-detail-entry" href="'.e(app_url('admin/appointments.php?appointment_id='.$r['id'].'&return_to='.$return)).'"><i class="dashboard-detail-icon">'.ui_icon('paw').'</i><span><b>'.e($r['pet_name']).' · '.e($r['client_name']).'</b><small>'.e($when).' · '.e(ucfirst($r['status'])).'</small><em>'.e($r['reason'] ?: 'No reason provided').' · '.e($r['vet_name'] ?: 'Veterinarian not assigned').'</em></span><strong>Open</strong></a>';
}, 'No appointments currently need attention.');
$pendingLeaveHtml = dashboard_list_html($pendingLeaveRows, function(array $r): string {
    $reason = trim(preg_replace('/^\[Pending\]\s*/', '', (string)$r['reason']));
    $return = rawurlencode('admin/dashboard.php?queue=leave#actionQueue');
    return '<a class="dashboard-detail-entry" href="'.e(app_url('admin/calendar.php?scope=workforce&workforce=unavailable&availability_id='.$r['id'].'&return_to='.$return)).'"><i class="dashboard-detail-icon">'.ui_icon('paw').'</i><span><b>'.e($r['full_name']).' · '.e(ucfirst($r['role'])).'</b><small>'.e(date('M d, Y h:i A',strtotime($r['starts_at']))).' to '.e(date('M d, Y h:i A',strtotime($r['ends_at']))).'</small><em>'.e($reason ?: 'No reason provided').'</em></span><strong>Open</strong></a>';
}, 'No pending leave requests need review.');
$dueVaccinationHtml = dashboard_list_html($dueVaccinationRows, function(array $r): string {
    return '<a class="dashboard-detail-entry" href="'.e(app_url('admin/vaccinations.php?vaccination_id='.$r['id'])).'"><i class="dashboard-detail-icon">'.ui_icon('paw').'</i><span><b>'.e($r['pet_name']).' · '.e($r['vaccine_name']).'</b><small>Due '.e(date('M d, Y', strtotime($r['next_due_date']))).' · '.e($r['client_name']).'</small><em>'.e($r['vet_name'] ?: 'Veterinarian not recorded').'</em></span><strong>Open</strong></a>';
}, 'No vaccinations are due within the next 60 days.');
$todaySalesHtml = dashboard_list_html($todaySalesRows, function(array $r): string {
    return '<a class="dashboard-detail-entry" href="'.e(app_url('admin/pos.php?transaction_id='.$r['id'])).'"><i class="dashboard-detail-icon">'.ui_icon('paw').'</i><span><b>Sale #'.intval($r['id']).' · ₱'.number_format((float)$r['total_amount'],2).'</b><small>'.e($r['client_name']).' · '.e(date('h:i A', strtotime($r['transaction_date']))).'</small><em>'.intval($r['item_count']).' item'.((int)$r['item_count']===1?'':'s').' across '.intval($r['line_count']).' line'.((int)$r['line_count']===1?'':'s').' · '.e($r['handled_by_name']).'</em></span><strong>Open</strong></a>';
}, 'No paid sales were recorded today.');

$diagnosisPeriod = $_GET['diagnosis_period'] ?? 'monthly';
$diagnosisPeriods = ['monthly'=>'Monthly','quarterly'=>'Quarterly','half_year'=>'Half-year','annual'=>'Annual'];
if (!isset($diagnosisPeriods[$diagnosisPeriod])) $diagnosisPeriod = 'monthly';
$today = new DateTimeImmutable('today');
if ($diagnosisPeriod === 'quarterly') {
    $quarterMonth = ((int)floor(((int)$today->format('n') - 1) / 3) * 3) + 1;
    $periodStart = $today->setDate((int)$today->format('Y'), $quarterMonth, 1);
    $periodEnd = $periodStart->modify('+3 months');
} elseif ($diagnosisPeriod === 'half_year') {
    $periodStart = $today->setDate((int)$today->format('Y'), (int)$today->format('n') <= 6 ? 1 : 7, 1);
    $periodEnd = $periodStart->modify('+6 months');
} elseif ($diagnosisPeriod === 'annual') {
    $periodStart = $today->setDate((int)$today->format('Y'), 1, 1);
    $periodEnd = $periodStart->modify('+1 year');
} else {
    $periodStart = $today->modify('first day of this month');
    $periodEnd = $periodStart->modify('+1 month');
}
$periodStartSql=$periodStart->format('Y-m-d');$periodEndSql=$periodEnd->format('Y-m-d');
$diagnosisStmt = $conn->prepare("SELECT TRIM(COALESCE(diagnosis,'')) diagnosis,visit_date FROM medical_records WHERE visit_date>=? AND visit_date<? ORDER BY visit_date DESC,id DESC");
$diagnosisStmt->bind_param('ss',$periodStartSql,$periodEndSql);$diagnosisStmt->execute();$topDiagnosis=$diagnosisStmt->get_result();
$diagnosisGroups=[];
while($topDiagnosis && $d=$topDiagnosis->fetch_assoc()){
    $label=preg_replace('/\s+/u',' ',trim((string)$d['diagnosis']));
    if($label==='')$label='Unspecified';
    $key=mb_strtolower($label);
    if(!isset($diagnosisGroups[$key]))$diagnosisGroups[$key]=['diagnosis'=>$label,'c'=>0,'latest_visit'=>$d['visit_date']];
    $diagnosisGroups[$key]['c']++;
    if(strtotime($d['visit_date'])>strtotime($diagnosisGroups[$key]['latest_visit']))$diagnosisGroups[$key]['latest_visit']=$d['visit_date'];
}
$diagnosisRows=array_values($diagnosisGroups);
usort($diagnosisRows,fn($a,$b)=>[(int)$b['c'],strtotime($b['latest_visit']),$a['diagnosis']]<=>[(int)$a['c'],strtotime($a['latest_visit']),$b['diagnosis']]);
$diagnosisRows=array_slice($diagnosisRows,0,3);$diagnosisMax=1;
foreach($diagnosisRows as $d)$diagnosisMax=max($diagnosisMax,(int)$d['c']);
$periodEndDisplay=$periodEnd->modify('-1 day');

$queue = [
 ['Inventory alerts',$lowStock,'Review low and unavailable stock in Quick overview.','admin/dashboard.php?overview=inventory#quickOverview','package','danger',100],
 ['Appointments needing attention',$attentionAppointments,'Review client appointments with scheduling or status issues.','admin/calendar.php?scope=appointments&status=attention','alert','danger',95,['title'=>'Appointments needing attention','eyebrow'=>'Action queue','html'=>$attentionAppointmentHtml]],
 ['Pending appointments',$pendingAppointments,'Review scheduling requests.','admin/appointments.php?status=pending','calendar','warning',80],
 ['Pet verification',$pendingPets,'Verify submitted pet profiles.','admin/pets.php?verification=pending','paw','info',65],
 ['Pet edit requests',$pendingEditRequests,'Review owner profile changes.','admin/pet_edit_requests.php?status=pending','edit','purple',60],
 ['Pending clients',$pendingUsers,'Review new client accounts in grid view.','admin/clients.php?filter=pending&view=grid','user-check','warning',55],
 ['Leave requests',$pendingLeaveRequests,'Review staff and veterinarian unavailability requests.','admin/calendar.php?scope=workforce&workforce=unavailable','calendar-days','warning',54,['title'=>'Pending leave requests','eyebrow'=>'Workforce review','html'=>$pendingLeaveHtml]],
 ['Vaccinations due',$dueSoon,'Review upcoming follow-ups.','admin/vaccinations.php?status=overdue','syringe','info',45],
];
usort($queue, fn($a,$b)=>($b[6]<=>$a[6]) ?: ($b[1]<=>$a[1]));

$appointments=[];$res=$conn->query("SELECT a.id,a.status,a.requested_date,a.scheduled_date,a.reason,a.confirmation_code,u.full_name client_name,p.name pet_name,p.species,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id ORDER BY COALESCE(a.scheduled_date,a.requested_date) DESC,a.id DESC LIMIT 5");while($r=$res->fetch_assoc())$appointments[]=$r;
$logs=[];$res=$conn->query("SELECT l.*,u.full_name FROM audit_logs l LEFT JOIN users u ON l.actor_user_id=u.id ORDER BY l.created_at DESC LIMIT 5");while($r=$res->fetch_assoc())$logs[]=$r;
$sales=[];$res=$conn->query("SELECT t.*,c.full_name client_name,h.full_name handled_by_name FROM pos_transactions t LEFT JOIN users c ON t.client_id=c.id LEFT JOIN users h ON t.handled_by=h.id ORDER BY t.transaction_date DESC,t.id DESC LIMIT 5");while($r=$res->fetch_assoc())$sales[]=$r;
$inventory=[];$res=$conn->query("SELECT * FROM inventory_items ORDER BY FIELD(status,'out_of_stock','low_stock','available','inactive'),updated_at DESC,created_at DESC LIMIT 5");while($r=$res->fetch_assoc())$inventory[]=$r;
$notifications=[];$res=$conn->query("SELECT n.*,u.full_name FROM notifications n JOIN users u ON n.user_id=u.id WHERE n.title NOT LIKE '[Deleted] %' ORDER BY n.status='unread' DESC,n.created_at DESC LIMIT 5");while($r=$res->fetch_assoc())$notifications[]=$r;

$title = "Admin Dashboard";
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/admin_sidebar.php"; ?>
<main class="content admin-dashboard-page" id="mainContent">
<header class="page-heading dashboard-heading vetrix-dashboard-hero">
  <div class="dashboard-hero-copy">
    <span class="eyebrow">Welcome back</span>
    <h1><?=e($_SESSION['full_name'] ?? 'Administrator')?>!</h1>
    <p>Here is what is happening across the clinic today.</p>
  </div>
  <div class="heading-actions"><a class="button-secondary" href="<?=app_url('admin/calendar.php')?>"><?=ui_icon('calendar-days')?>Open calendar</a><a class="button-primary" href="<?=app_url('admin/appointments.php?status=pending')?>"><?=ui_icon('clipboard')?>Review pending</a></div>
  <div class="dashboard-hero-art" aria-hidden="true"><img src="<?=e(app_url('assets/images/vetrix-dashboard-dog.png'))?>" alt=""></div>
</header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?>

<section class="metric-grid dashboard-metrics" aria-label="Clinic totals">
  <button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Appointments today','eyebrow'=>'Today\'s clinic schedule','html'=>$todayAppointmentHtml]))?>'><span class="metric-icon"><?=ui_icon('calendar-days')?></span><div><small>Appointments today</small><strong><?=$todayAppointments?></strong><p>Open today’s appointment list.</p></div></button>
  <button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Pending appointments','eyebrow'=>'Scheduling review','html'=>$pendingAppointmentHtml]))?>'><span class="metric-icon warning"><?=ui_icon('clock')?></span><div><small>Pending appointments</small><strong><?=$pendingAppointments?></strong><p>Review requests awaiting a decision.</p></div></button>
  <button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Vaccinations due soon','eyebrow'=>'Next 60 days','html'=>$dueVaccinationHtml]))?>'><span class="metric-icon info"><?=ui_icon('syringe')?></span><div><small>Vaccinations due soon</small><strong><?=$dueSoon?></strong><p>Open upcoming vaccination follow-ups.</p></div></button>
  <button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Paid sales today','eyebrow'=>'Sales summary','html'=>$todaySalesHtml,'action_url'=>app_url('admin/pos.php'),'action_label'=>'Open POS']))?>'><span class="metric-icon success"><?=ui_icon('coins')?></span><div><small>Paid sales today</small><strong>₱<?=number_format($revenue,2)?></strong><p>Review today’s paid transactions.</p></div></button>
</section>

<?php include '../includes/dashboard_schedule.php'; ?>
<div class="dashboard-main-grid dashboard-equal-grid">
<section class="surface-card workflow-queue-card" id="actionQueue">
  <div class="section-heading"><div><span class="eyebrow">Action queue</span><h2>Items requiring review</h2></div></div>
  <?php $queueColumns = array_chunk($queue, max(1, (int)ceil(count($queue) / 2))); ?>
  <div class="workflow-queue-list">
  <?php foreach($queueColumns as $queueColumn): ?>
    <div class="workflow-queue-column">
    <?php foreach($queueColumn as $queueItem): [$label,$count,$note,$url,$icon,$tone,$priority]=$queueItem; $detail=$queueItem[7]??null; ?>
      <?php if($detail):?><button type="button" class="workflow-queue-item queue-<?=e($tone)?> <?=$count > 0 ? 'queue-has-items' : 'queue-empty'?>" data-queue-key="<?=$label==='Appointments needing attention'?'appointments':'leave'?>" data-record-detail='<?=e(json_encode($detail))?>'><?php else:?><a class="workflow-queue-item queue-<?=e($tone)?> <?=$count > 0 ? 'queue-has-items' : 'queue-empty'?>" href="<?=app_url($url)?>"><?php endif;?><span class="queue-icon"><?=ui_icon($icon)?></span><div><b><?=e($label)?></b><p><?=e($note)?></p></div><span class="queue-count-wrap"><strong class="queue-count"><?=intval($count)?></strong><?php if($count>0 && $priority>=80):?><span class="queue-urgency" aria-label="Urgent">!</span><?php endif;?></span><span class="queue-arrow"><?=ui_icon('chevron-right')?></span><?php if($detail):?></button><?php else:?></a><?php endif;?>
    <?php endforeach;?>
    </div>
  <?php endforeach;?>
  </div>
</section>

<section class="surface-card diagnosis-card top-diagnosis-card" id="clinicalTrend">
  <div class="section-heading diagnosis-heading"><div class="section-copy"><span class="eyebrow">Clinical trend</span><h2>Top 3 diagnoses</h2><small class="diagnosis-period-caption"><?=e($periodStart->format('M d, Y'))?> to <?=e($periodEndDisplay->format('M d, Y'))?></small><form method="GET" action="dashboard.php" class="clinical-trend-form"><label for="diagnosisPeriod">Date range</label><select class="form-select" id="diagnosisPeriod" name="diagnosis_period" onchange="try{sessionStorage.setItem('vetrix.clinicalTrendScroll',String(window.scrollY));}catch(_e){} this.form.submit()"><?php foreach($diagnosisPeriods as $key=>$label):?><option value="<?=e($key)?>" <?=$diagnosisPeriod===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></form></div></div>
  <div class="diagnosis-chart" aria-label="Top diagnosis counts">
  <?php if($diagnosisRows):foreach($diagnosisRows as $d):$pct=max(8,round(((int)$d['c']/$diagnosisMax)*100));?>
    <button type="button" class="diagnosis-row" data-record-detail='<?=e(json_encode(['title'=>$d['diagnosis'],'eyebrow'=>'Diagnosis summary','fields'=>['Records'=>(int)$d['c'],'Latest visit'=>date('M d, Y',strtotime($d['latest_visit'])),'Period'=>$diagnosisPeriods[$diagnosisPeriod],'Date range'=>$periodStart->format('M d, Y').' to '.$periodEndDisplay->format('M d, Y')]]))?>'><span><b><?=e($d['diagnosis'])?></b><small>Latest <?=date('M d, Y',strtotime($d['latest_visit']))?></small></span><i><em style="width:<?=$pct?>%"></em></i><strong><?=intval($d['c'])?></strong></button>
  <?php endforeach;else:?><div class="empty-state compact"><h3>No diagnosis data</h3><p>No medical records were entered for this period.</p></div><?php endif;?>
  </div>
</section>
<script>document.addEventListener('DOMContentLoaded',()=>{try{const y=sessionStorage.getItem('vetrix.clinicalTrendScroll');if(y!==null){sessionStorage.removeItem('vetrix.clinicalTrendScroll');requestAnimationFrame(()=>window.scrollTo({top:Number(y)||0,left:0,behavior:'auto'}));}}catch(_e){}});</script>
</div>

<section class="surface-card dashboard-overview-card" id="quickOverview">
  <div class="section-heading"><div><span class="eyebrow">Quick overview</span><h2>Recent clinic activity</h2></div></div>
  <div class="overview-tabs" role="tablist" aria-label="Dashboard overview categories">
    <button class="overview-tab tab-appointments <?=$overviewTab==='appointments'?'active':''?>" type="button" data-overview-tab="appointments"><?=ui_icon('calendar-days')?>Appointments</button>
    <button class="overview-tab tab-activity <?=$overviewTab==='activity'?'active':''?>" type="button" data-overview-tab="activity"><?=ui_icon('activity')?>Activity log</button>
    <button class="overview-tab tab-sales <?=$overviewTab==='sales'?'active':''?>" type="button" data-overview-tab="sales"><?=ui_icon('receipt')?>Billing and POS</button>
    <button class="overview-tab tab-inventory <?=$overviewTab==='inventory'?'active':''?>" type="button" data-overview-tab="inventory"><?=ui_icon('package')?>Inventory</button>
    <button class="overview-tab tab-notifications <?=$overviewTab==='notifications'?'active':''?>" type="button" data-overview-tab="notifications"><?=ui_icon('bell')?>Notifications</button>
  </div>
  <div class="overview-panels">
    <div class="overview-panel <?=$overviewTab==='appointments'?'active':''?>" data-overview-panel="appointments">
      <?php if(!$appointments):?><p class="empty-copy">No appointments found.</p><?php endif;foreach($appointments as $r):$when=$r['scheduled_date']?:$r['requested_date'];$detail=['title'=>$r['pet_name'].' appointment','eyebrow'=>'Recent appointment','fields'=>['Client'=>$r['client_name'],'Pet'=>$r['pet_name'].' · '.$r['species'],'Date'=>date('M d, Y h:i A',strtotime($when)),'Status'=>ucfirst($r['status']),'Veterinarian'=>$r['vet_name']?:'Not assigned','Reason'=>$r['reason']?:'Not provided','Confirmation code'=>$r['confirmation_code']?:'Not generated'],'action_url'=>app_url('admin/appointments.php?appointment_id='.$r['id']),'action_label'=>'Manage appointment'];?><button class="overview-item" type="button" data-record-detail='<?=e(json_encode($detail))?>'><span><?=ui_icon('calendar-days')?></span><div><b><?=e($r['pet_name'])?> · <?=e($r['client_name'])?></b><small><?=date('M d, Y h:i A',strtotime($when))?></small></div><?=badge($r['status'])?></button><?php endforeach;?>
    </div>
    <div class="overview-panel <?=$overviewTab==='activity'?'active':''?>" data-overview-panel="activity">
      <?php if(!$logs):?><p class="empty-copy">No activity found.</p><?php endif;foreach($logs as $r):$detail=['title'=>$r['action'],'eyebrow'=>'Activity log entry','fields'=>['Actor'=>$r['full_name']?:'System','Entity'=>ucwords(str_replace('_',' ',$r['entity_type']?:'System')),'Entity ID'=>$r['entity_id']?:'Not applicable','Details'=>$r['details']?:'No details','Date'=>date('M d, Y h:i A',strtotime($r['created_at']))]];?><button class="overview-item" type="button" data-record-detail='<?=e(json_encode($detail))?>'><span><?=ui_icon('activity')?></span><div><b><?=e($r['action'])?></b><small><?=e($r['full_name']?:'System')?> · <?=date('M d, h:i A',strtotime($r['created_at']))?></small></div><i><?=ui_icon('chevron-right')?></i></button><?php endforeach;?>
    </div>
    <div class="overview-panel <?=$overviewTab==='sales'?'active':''?>" data-overview-panel="sales">
      <?php if(!$sales):?><p class="empty-copy">No POS transactions found.</p><?php endif;foreach($sales as $r):$detail=['title'=>'POS transaction #'.$r['id'],'eyebrow'=>'Billing and POS','fields'=>['Client'=>$r['client_name']?:'Walk-in','Amount'=>'₱'.number_format((float)$r['total_amount'],2),'Payment status'=>ucfirst($r['payment_status']),'Handled by'=>$r['handled_by_name']?:'Clinic staff','Notes'=>$r['notes']?:'No note','Date'=>date('M d, Y h:i A',strtotime($r['transaction_date']))]];?><button class="overview-item" type="button" data-record-detail='<?=e(json_encode($detail))?>'><span><?=ui_icon('receipt')?></span><div><b><?=e($r['client_name']?:'Walk-in')?> · ₱<?=number_format((float)$r['total_amount'],2)?></b><small><?=date('M d, Y h:i A',strtotime($r['transaction_date']))?></small></div><?=badge($r['payment_status'])?></button><?php endforeach;?>
    </div>
    <div class="overview-panel inventory-overview-panel <?=$overviewTab==='inventory'?'active':''?>" data-overview-panel="inventory">
      <?php if(!$inventory):?><p class="empty-copy">No inventory items found.</p><?php else:?><div class="inventory-overview-table" role="table" aria-label="Current inventory overview"><div class="inventory-overview-head" role="row"><span>Item</span><span>Category</span><span>Price</span><span>Stock</span><span>Restock when stock is</span><span>Status</span></div><?php foreach($inventory as $r):$detail=['title'=>$r['item_name'],'eyebrow'=>'Inventory item','fields'=>['SKU'=>$r['sku']?:'Not assigned','Category'=>$r['category']?:'Uncategorized','Stock'=>$r['stock_qty'],'Restock when stock is'=>(int)$r['reorder_level'].' or fewer','Status'=>ucwords(str_replace('_',' ',$r['status'])),'POS price'=>'₱'.number_format((float)$r['sale_price'],2)]];?><button class="inventory-overview-row stock-<?=e($r['status'])?>" type="button" role="row" data-record-detail='<?=e(json_encode($detail))?>'><span class="inventory-overview-item"><i><?=ui_icon('package')?></i><em><b><?=e($r['item_name'])?></b><small><?=e($r['sku']?:'No SKU')?></small></em></span><span><?=e($r['category']?:'Uncategorized')?></span><span><b>₱<?=number_format((float)$r['sale_price'],2)?></b></span><span><?=intval($r['stock_qty'])?></span><span><?=intval($r['reorder_level'])?> or fewer</span><span><?=badge($r['status'])?></span></button><?php endforeach;?></div><?php endif;?>
    </div>
    <div class="overview-panel <?=$overviewTab==='notifications'?'active':''?>" data-overview-panel="notifications">
      <?php if(!$notifications):?><p class="empty-copy">No notifications found.</p><?php endif;foreach($notifications as $r):$detail=['title'=>$r['title'],'eyebrow'=>'Notification for '.$r['full_name'],'fields'=>['Recipient'=>$r['full_name'],'Type'=>ucfirst($r['type']),'Status'=>ucfirst($r['status']),'Message'=>$r['message'],'Sent'=>date('M d, Y h:i A',strtotime($r['created_at']))]];?><button class="overview-item" type="button" data-record-detail='<?=e(json_encode($detail))?>'><span><?=ui_icon(notification_icon_name($r['type']))?></span><div><b><?=e($r['title'])?></b><small><?=e($r['full_name'])?> · <?=date('M d, h:i A',strtotime($r['created_at']))?></small></div><?=badge($r['status'])?></button><?php endforeach;?>
    </div>
  </div>
</section>
<style>
.workflow-queue-item[type=button]{width:100%;text-align:left}.workflow-queue-item[type=button] div{text-align:left}.dashboard-equal-grid{grid-template-columns:minmax(0,1.15fr) minmax(360px,.85fr)!important}.dashboard-equal-grid>section{height:100%}.workflow-queue-card,.top-diagnosis-card{display:flex;flex-direction:column}.workflow-queue-list,.diagnosis-chart{flex:1}.queue-warning .queue-icon{background:#fff4df;color:#9a5b05}.queue-info .queue-icon{background:#eaf3ff;color:#2462a9}.queue-purple .queue-icon{background:#f1ebff;color:#6d45b8}.queue-danger .queue-icon{background:#ffeded;color:#b42318}.queue-danger strong{color:#b42318}.diagnosis-heading{align-items:flex-start;flex-direction:column}.diagnosis-period-form{min-width:150px}.diagnosis-period-form label{display:block;font-size:11px;font-weight:800;color:var(--vx-muted);margin-bottom:4px}.diagnosis-chart{display:grid;gap:9px}.diagnosis-row{display:grid;grid-template-columns:minmax(130px,1fr) minmax(80px,1.2fr) 32px;align-items:center;gap:10px;width:100%;padding:9px 10px;border:1px solid var(--vx-border);border-radius:13px;background:#fff;text-align:left}.diagnosis-row span{display:flex;flex-direction:column;min-width:0}.diagnosis-row span b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.82rem}.diagnosis-row span small{color:var(--vx-muted)}.diagnosis-row i{height:8px;border-radius:999px;background:#edf2f7;overflow:hidden}.diagnosis-row em{display:block;height:100%;border-radius:inherit;background:#4f86d9}.diagnosis-row strong{text-align:right}.dashboard-overview-card{margin-top:14px}.overview-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px}.overview-tabs button{--overview-color:#245f9f;--overview-bg:#eaf3ff;display:inline-flex;align-items:center;gap:7px;border:1px solid color-mix(in srgb,var(--overview-color) 35%,#fff);border-radius:999px;background:var(--overview-bg);padding:7px 12px;font-size:.78rem;font-weight:800;color:var(--overview-color)}.overview-tabs .tab-appointments{--overview-color:#245f9f;--overview-bg:#eaf3ff;--chip-color:#245f9f;--chip-bg:#eaf3ff}.overview-tabs .tab-activity{--overview-color:#5b6470;--overview-bg:#f2f4f6;--chip-color:#5b6470;--chip-bg:#f2f4f6}.overview-tabs .tab-sales{--overview-color:#07764b;--overview-bg:#eaf8f0;--chip-color:#07764b;--chip-bg:#eaf8f0}.overview-tabs .tab-inventory{--overview-color:#a35a00;--overview-bg:#fff4df;--chip-color:#a35a00;--chip-bg:#fff4df}.overview-tabs .tab-notifications{--overview-color:#6d45b8;--overview-bg:#f1ebff;--chip-color:#6d45b8;--chip-bg:#f1ebff}.overview-tabs button:hover{border-color:var(--overview-color)}.overview-tabs button.active{background:var(--overview-color);color:#fff;border-color:var(--overview-color)}.overview-tabs button .ui-icon{width:15px;height:15px}.overview-panel{display:none;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:9px}.overview-panel.active{display:grid}.overview-item{display:grid;grid-template-columns:38px minmax(0,1fr) auto;align-items:center;gap:9px;padding:10px;border:1px solid var(--vx-border);border-radius:13px;background:#fff;text-align:left;min-width:0}.overview-item>span:first-child{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:#eef4fb;color:#275f9e}.overview-panel[data-overview-panel="appointments"] .overview-item>span:first-child{background:#eaf3ff;color:#245f9f}.overview-panel[data-overview-panel="activity"] .overview-item>span:first-child{background:#f2f4f6;color:#5b6470}.overview-panel[data-overview-panel="sales"] .overview-item>span:first-child{background:#eaf8f0;color:#07764b}.overview-panel[data-overview-panel="notifications"] .overview-item>span:first-child{background:#f1ebff;color:#6d45b8}.inventory-overview-item i{background:#fff4df!important;color:#a35a00!important}.overview-item div{display:flex;flex-direction:column;min-width:0}.overview-item div b{font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.overview-item div small{color:var(--vx-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.overview-item.item-attention{border-color:#efc2c2;background:#fff8f8}.overview-item>i{color:#718096}@media(max-width:1050px){.workflow-queue-item[type=button]{width:100%;text-align:left}.workflow-queue-item[type=button] div{text-align:left}.dashboard-equal-grid{grid-template-columns:1fr!important}.diagnosis-heading{align-items:flex-start}}@media(max-width:680px){.diagnosis-row{grid-template-columns:1fr 40px}.diagnosis-row i{grid-column:1/-1;grid-row:2}.overview-panel.active{grid-template-columns:1fr}}
</style>
<script>(()=>{const queueButtons=[...document.querySelectorAll('[data-queue-key]')],detailDialog=document.getElementById('globalDetailDialog');const setQueueUrl=button=>{const url=new URL(location.href);url.searchParams.set('queue',button.dataset.queueKey);url.hash='actionQueue';history.replaceState(history.state,'',url);detailDialog?.classList.add('action-queue-detail')};document.querySelectorAll('[data-record-detail]:not([data-queue-key])').forEach(item=>item.addEventListener('click',()=>detailDialog?.classList.remove('action-queue-detail'),{capture:true}));queueButtons.forEach(button=>button.addEventListener('click',()=>setQueueUrl(button),{capture:true}));const requestedQueue=new URL(location.href).searchParams.get('queue');if(requestedQueue){const button=queueButtons.find(item=>item.dataset.queueKey===requestedQueue);if(button)setTimeout(()=>{button.click();document.getElementById('actionQueue')?.scrollIntoView({block:'start'})},80)}})();</script>
<script>(()=>{const activate=name=>{const button=document.querySelector(`[data-overview-tab="${name}"]`);if(!button)return;document.querySelectorAll('[data-overview-tab]').forEach(b=>{const on=b===button;b.classList.toggle('active',on);b.classList.toggle('is-active',on);if(on){b.dataset.activeChip='1';b.setAttribute('aria-selected','true');b.setAttribute('aria-pressed','true');}else{delete b.dataset.activeChip;b.removeAttribute('aria-current');b.setAttribute('aria-selected','false');b.setAttribute('aria-pressed','false');}});document.querySelectorAll('[data-overview-panel]').forEach(panel=>panel.classList.toggle('active',panel.dataset.overviewPanel===name));};document.querySelectorAll('[data-overview-tab]').forEach(button=>button.addEventListener('click',()=>activate(button.dataset.overviewTab)));activate('<?=e($overviewTab)?>');const requested=new URLSearchParams(location.search).get('overview');if(requested){activate(requested);setTimeout(()=>document.getElementById('quickOverview')?.scrollIntoView({block:'start'}),30);}})();</script>
</main></div>
<?php include "../includes/footer.php"; ?>
