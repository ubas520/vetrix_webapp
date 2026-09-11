<?php
// Show only remaining confirmed visits today. Veterinarians see their own cases.
$scheduleRole = $_SESSION['role'] ?? '';
$schedulePath = match ($scheduleRole) {
    'admin' => 'admin',
    'staff' => 'staff',
    'veterinarian' => 'vet',
    default => null,
};
if ($schedulePath === null) return;
$scheduleDay = $conn->query('SELECT CURDATE() AS today')->fetch_assoc()['today'];
$scheduleVetCondition = $scheduleRole === 'veterinarian' ? ' AND a.assigned_vet_id='.(int)current_user_id() : '';
$scheduleResult = $conn->query("SELECT a.id,a.scheduled_date,a.status,p.name pet_name,u.full_name owner_name
    FROM appointments a JOIN pets p ON p.id=a.pet_id JOIN users u ON u.id=a.owner_id
    WHERE a.status='approved' AND a.scheduled_date>=NOW() AND a.scheduled_date<CURDATE()+INTERVAL 1 DAY
    $scheduleVetCondition ORDER BY a.scheduled_date,a.id LIMIT 5");
?>
<section class="surface-card dashboard-schedule" aria-labelledby="todayScheduleTitle">
    <div class="section-heading">
        <div><h2 id="todayScheduleTitle">Today's schedule</h2><p><?=e(date('l, F j', strtotime($scheduleDay)))?> &middot; Next confirmed visits</p></div>
        <a class="button-secondary" href="<?=app_url($schedulePath.'/calendar.php')?>">View calendar <?=ui_icon('chevron-right')?></a>
    </div>
    <?php if(!$scheduleResult->num_rows): ?>
        <div class="schedule-empty"><span class="schedule-empty-icon" aria-hidden="true"><?=ui_icon('calendar-days')?></span><div><b>No upcoming appointments today</b><p>New confirmed visits will appear here.</p></div></div>
    <?php else: ?>
        <ul class="schedule-list">
        <?php while($visit=$scheduleResult->fetch_assoc()): ?>
            <li><a class="schedule-visit" href="<?=app_url($schedulePath.'/appointments.php?appointment_id='.(int)$visit['id'])?>" aria-label="Open <?=e($visit['pet_name'])?> appointment at <?=e(date('h:i A',strtotime($visit['scheduled_date'])))?>">
                <time datetime="<?=e(date('Y-m-d\TH:i',strtotime($visit['scheduled_date'])))?>"><?=e(date('h:i A',strtotime($visit['scheduled_date'])))?></time>
                <div class="schedule-pet"><b><?=e($visit['pet_name'])?></b><span><?=e($visit['owner_name'])?></span></div>
                <?=badge($visit['status'])?><span class="schedule-arrow" aria-hidden="true"><?=ui_icon('chevron-right')?></span>
            </a></li>
        <?php endwhile; ?>
        </ul>
    <?php endif; ?>
</section>
