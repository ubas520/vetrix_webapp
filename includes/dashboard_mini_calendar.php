<?php
// Admin-only include; the dashboard has already checked the role.
$miniToday=$conn->query('SELECT CURDATE() today')->fetch_assoc()['today'];
$miniMonthInput=$_GET['mini_month']??substr($miniToday,0,7);
$miniMonth=is_string($miniMonthInput) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$miniMonthInput)?DateTimeImmutable::createFromFormat('!Y-m',$miniMonthInput):false;
if(!$miniMonth || $miniMonth->format('Y-m')!==$miniMonthInput || (int)$miniMonth->format('Y')<1000 || (int)$miniMonth->format('Y')>9998) $miniMonth=new DateTimeImmutable(substr($miniToday,0,7).'-01');
$miniStart=$miniMonth->format('Y-m-d 00:00:00');
$miniEnd=$miniMonth->modify('+1 month')->format('Y-m-d 00:00:00');
$miniStmt=$conn->prepare("SELECT a.id,a.status,COALESCE(a.scheduled_date,a.requested_date) visit_time,a.scheduled_date,p.name pet_name,u.full_name owner_name,v.full_name vet_name FROM appointments a JOIN pets p ON p.id=a.pet_id JOIN users u ON u.id=a.owner_id LEFT JOIN users v ON v.id=a.assigned_vet_id WHERE COALESCE(a.scheduled_date,a.requested_date)>=? AND COALESCE(a.scheduled_date,a.requested_date)<? ORDER BY visit_time,a.id");
$miniVisits=[];$miniFailed=false;
if(!$miniStmt || !$miniStmt->bind_param('ss',$miniStart,$miniEnd) || !$miniStmt->execute()) $miniFailed=true;
else { $miniResult=$miniStmt->get_result();while($miniVisit=$miniResult->fetch_assoc()) $miniVisits[substr($miniVisit['visit_time'],0,10)][]=$miniVisit; }
$miniLink=static function(string $month) use($overviewTab,$diagnosisPeriod): string {
    return app_url('admin/dashboard.php?'.http_build_query(['mini_month'=>$month,'overview'=>$overviewTab,'diagnosis_period'=>$diagnosisPeriod]).'#appointmentMiniCalendar');
};
$miniTotal=array_sum(array_map('count',$miniVisits));
?>
<section class="surface-card dashboard-mini-calendar" id="appointmentMiniCalendar" aria-labelledby="miniCalendarTitle">
    <div class="mini-calendar-heading"><div><span class="eyebrow">At a glance</span><h2 id="miniCalendarTitle">Appointment calendar</h2></div><a class="button-secondary" href="<?=e($miniLink(substr($miniToday,0,7)))?>">Today</a></div>
    <nav class="mini-month-nav" aria-label="Appointment calendar month">
        <a class="icon-button" href="<?=e($miniLink($miniMonth->modify('-1 month')->format('Y-m')))?>" aria-label="Previous month"><?=ui_icon('chevron-left')?></a>
        <h3><?=$miniMonth->format('F Y')?></h3>
        <a class="icon-button" href="<?=e($miniLink($miniMonth->modify('+1 month')->format('Y-m')))?>" aria-label="Next month"><?=ui_icon('chevron-right')?></a>
    </nav>
    <p class="mini-calendar-help">Select a date to view its appointments. Counts include all statuses.</p>
    <?php if($miniFailed): ?><p class="alert alert-warning" role="alert">Appointments could not be loaded. Refresh to try again.</p><?php else: ?>
    <div class="mini-weekdays" aria-hidden="true"><?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $miniWeekday): ?><span><?=$miniWeekday?></span><?php endforeach; ?></div>
    <div class="mini-days" role="group" aria-label="<?=$miniMonth->format('F Y')?> appointment dates">
        <?php for($miniBlank=0;$miniBlank<(int)$miniMonth->format('w');$miniBlank++): ?><span aria-hidden="true"></span><?php endfor; ?>
        <?php for($miniDay=1;$miniDay<=(int)$miniMonth->format('t');$miniDay++):
            $miniDate=$miniMonth->format('Y-m-').str_pad((string)$miniDay,2,'0',STR_PAD_LEFT);
            $miniDayVisits=$miniVisits[$miniDate]??[];$miniCount=count($miniDayVisits);
            $miniDateTitle=(new DateTimeImmutable($miniDate))->format('l, F j, Y');
            $miniHtml='<div class="dashboard-detail-list">';
            foreach($miniDayVisits as $visit){
                $miniReturn='admin/dashboard.php?'.http_build_query(['mini_month'=>$miniMonth->format('Y-m'),'overview'=>$overviewTab,'diagnosis_period'=>$diagnosisPeriod]).'#appointmentMiniCalendar';
                $miniUrl=app_url('admin/appointments.php?appointment_id='.(int)$visit['id'].'&return_to='.rawurlencode($miniReturn));
                $miniHtml.='<a class="dashboard-detail-entry" href="'.e($miniUrl).'"><span><b>'.e($visit['pet_name']).' &middot; '.e($visit['owner_name']).'</b><small>'.e(date('g:i A',strtotime($visit['visit_time']))).' &middot; '.e($visit['scheduled_date']?'Scheduled':'Requested').' &middot; '.e(ucfirst($visit['status'])).'</small><em>'.e($visit['vet_name']?:'Veterinarian not assigned').'</em></span><strong>View</strong></a>';
            }
            $miniHtml.=$miniCount?'':'<p class="empty-copy">No appointments scheduled or requested for this date.</p>';
            $miniHtml.='</div>';
        ?>
        <button type="button" class="mini-day <?=$miniCount?'has-visits':''?> <?=$miniDate===$miniToday?'is-today':''?>" <?=$miniDate===$miniToday?'aria-current="date"':''?> aria-label="<?=e($miniDateTitle.', '.$miniCount.' appointment'.($miniCount===1?'':'s'))?>" data-record-detail='<?=e(json_encode(['title'=>$miniDateTitle,'eyebrow'=>$miniCount.' appointment'.($miniCount===1?'':'s').' · All statuses','html'=>$miniHtml]))?>'><span><?=$miniDay?></span><?php if($miniCount): ?><small aria-hidden="true"><?=$miniCount?></small><?php endif; ?></button>
        <?php endfor; ?>
    </div>
    <p class="mini-calendar-summary"><b><?=$miniTotal?></b> appointment<?=$miniTotal===1?'':'s'?> this month <span>&middot; Outlined date is today</span></p>
    <?php endif; ?>
    <a class="mini-calendar-full" href="<?=e(app_url('admin/calendar.php?month='.$miniMonth->format('Y-m').'&scope=appointments'))?>">Open full calendar <?=ui_icon('chevron-right')?></a>
</section>
