<?php
$chartToday=$conn->query('SELECT CURDATE() today')->fetch_assoc()['today'];
$chartEnd=(new DateTimeImmutable($chartToday))->modify('+1 day');
$chartStart=(new DateTimeImmutable($chartToday))->modify('-13 days');
$chartStatuses=['approved'=>'Confirmed','completed'=>'Completed','pending'=>'Pending','cancelled'=>'Cancelled','rejected'=>'Rejected'];
$chartColors=['approved'=>'#3569d4','completed'=>'#0d8879','pending'=>'#bd790b','cancelled'=>'#718096','rejected'=>'#bc455b'];
$chartDays=[];
for($day=$chartStart;$day<$chartEnd;$day=$day->modify('+1 day')) $chartDays[$day->format('Y-m-d')]=array_fill_keys(array_keys($chartStatuses),0);
$chartStmt=$conn->prepare("SELECT DATE(COALESCE(scheduled_date,requested_date)) visit_day,status,COUNT(*) total FROM appointments WHERE COALESCE(scheduled_date,requested_date)>=? AND COALESCE(scheduled_date,requested_date)<? GROUP BY visit_day,status");
$chartStartSql=$chartStart->format('Y-m-d');$chartEndSql=$chartEnd->format('Y-m-d');
$chartFailed=!$chartStmt || !$chartStmt->bind_param('ss',$chartStartSql,$chartEndSql) || !$chartStmt->execute();
if(!$chartFailed){$result=$chartStmt->get_result();while($row=$result->fetch_assoc()) if(isset($chartDays[$row['visit_day']][$row['status']])) $chartDays[$row['visit_day']][$row['status']]=(int)$row['total'];}
$chartTotals=array_fill_keys(array_keys($chartStatuses),0);
foreach($chartDays as $counts)foreach($counts as $status=>$count)$chartTotals[$status]+=$count;
$chartPayload=['labels'=>array_map(static fn($date)=>(new DateTimeImmutable($date))->format('M j'),array_keys($chartDays)),'datasets'=>[]];
foreach($chartStatuses as $status=>$label)$chartPayload['datasets'][]=['label'=>$label,'data'=>array_column(array_values($chartDays),$status),'backgroundColor'=>$chartColors[$status],'borderRadius'=>3,'maxBarThickness'=>30];
?>
<section class="surface-card dashboard-visit-trend" aria-labelledby="visitTrendTitle">
    <div class="visit-trend-heading"><div><span class="eyebrow">Clinic activity</span><h2 id="visitTrendTitle">Appointments at a glance</h2><p><?=e($chartStart->format('M j').' – '.(new DateTimeImmutable($chartToday))->format('M j, Y'))?> &middot; Last 14 days</p></div><a class="button-secondary" href="<?=app_url('admin/reports.php')?>">View reports <?=ui_icon('chevron-right')?></a></div>
    <?php if($chartFailed): ?><p class="alert alert-warning" role="alert">Appointment trends could not be loaded. Refresh to try again.</p><?php else: ?>
    <div class="visit-trend-totals" aria-label="Appointment totals for this period"><span><b><?=array_sum($chartTotals)?></b> Total visits</span><span><b><?=$chartTotals['completed']?></b> Completed</span><span><b><?=$chartTotals['pending']?></b> Pending</span></div>
    <p class="field-help">Grouped by scheduled date, or requested date when unscheduled. Colors show each visit's current status.</p>
    <?php if(array_sum($chartTotals)===0): ?><p class="visit-trend-empty">No appointments fall in this period. The chart will populate as visits are recorded.</p><?php endif; ?>
    <div class="visit-trend-canvas" hidden><canvas id="dashboardVisitChart" role="img" aria-label="Daily appointment counts for the last 14 days, grouped by status. Exact values are available in the table below."></canvas></div>
    <details class="visit-trend-data" open><summary>View daily counts</summary><div class="table-scroll-only"><table class="data-table"><caption>Appointments by visit date and current status</caption><thead><tr><th scope="col">Date</th><?php foreach($chartStatuses as $label): ?><th scope="col"><?=e($label)?></th><?php endforeach; ?><th scope="col">Total</th></tr></thead><tbody><?php foreach($chartDays as $date=>$counts): ?><tr><th scope="row"><?=e((new DateTimeImmutable($date))->format('M j, Y'))?></th><?php foreach($counts as $count): ?><td><?=$count?></td><?php endforeach; ?><td><?=array_sum($counts)?></td></tr><?php endforeach; ?></tbody></table></div></details>
    <script type="application/json" id="dashboardVisitChartData"><?=json_encode($chartPayload,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
    <script src="<?=e(app_url('assets/js/chart.umd.min.js'))?>"></script>
    <script src="<?=e(app_url('assets/js/dashboard-visit-chart.js'))?>?v=<?=filemtime(__DIR__.'/../assets/js/dashboard-visit-chart.js')?>"></script>
    <?php endif; ?>
</section>
