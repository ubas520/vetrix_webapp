<?php
// Shared summary; each role keeps its own appointment actions in the detail panel.
$appointmentModalFunction = $appointmentModalOverride ?? match ($_SESSION['role'] ?? '') {
    'admin' => 'openApptModal',
    'staff' => 'openStaffApptModal',
    default => 'openVetApptModal',
};
$appointmentToday = $appointmentToday ?? $conn->query('SELECT CURDATE() AS today')->fetch_assoc()['today'];
$appointmentTomorrow = (new DateTimeImmutable($appointmentToday))->modify('+1 day')->format('Y-m-d');
$scheduledTimestamp = !empty($r['scheduled_date']) ? strtotime($r['scheduled_date']) : false;
$scheduledDay = $scheduledTimestamp!==false ? date('Y-m-d',$scheduledTimestamp) : '';
$scheduledDayLabel = $scheduledDay===$appointmentToday ? 'Today' : ($scheduledDay===$appointmentTomorrow ? 'Tomorrow' : ($scheduledTimestamp!==false ? date(substr($scheduledDay,0,4)===substr($appointmentToday,0,4)?'M j':'M j, Y',$scheduledTimestamp) : ''));
?>
<article id="appointment<?=(int)$r['id']?>" class="appt-record appointment-row">
    <div class="appointment-cell appointment-pet">
        <?=pet_avatar_markup($r, 'appt-avatar')?>
        <div><span class="appointment-cell-label">Pet</span><h3><?=e($r['pet_name'])?></h3></div>
    </div>
    <div class="appointment-cell appointment-owner"><span class="appointment-cell-label">Owner</span><span><?=e($r['full_name'])?></span></div>
    <div class="appointment-cell appointment-time">
        <span class="appointment-cell-label">Scheduled time</span>
        <?php if($scheduledTimestamp!==false): ?>
            <time datetime="<?=e(date('Y-m-d\TH:i',$scheduledTimestamp))?>" title="<?=e(date('M j, Y, g:i A',$scheduledTimestamp))?>"><b><?=e($scheduledDayLabel.', '.date('g:i A',$scheduledTimestamp))?></b><small><?=e(date('D, M j, Y',$scheduledTimestamp))?></small></time>
        <?php else: ?><span class="appointment-unscheduled">Not scheduled</span><?php endif; ?>
    </div>
    <div class="appointment-cell appointment-veterinarian"><span class="appointment-cell-label">Veterinarian</span><span><?=e($r['vet_name'] ?: 'Not assigned')?></span></div>
    <div class="appointment-cell appointment-status"><div class="appointment-status-line"><span class="appointment-status-label">Visit</span><?=badge($r['status'])?></div><?php if(array_key_exists('consultation_id',$r) && $r['status']==='completed'): ?><div class="appointment-status-line"><span class="appointment-status-label">Record</span><span class="badge text-bg-<?=$r['consultation_id']?'success':'warning'?>"><?=$r['consultation_id']?'Saved':'Needed'?></span></div><?php endif; ?></div>
    <div class="appointment-cell appointment-actions"><button type="button" class="button-secondary" onclick="<?=$appointmentModalFunction?>('<?=e($modalId)?>')" aria-label="View appointment for <?=e($r['pet_name'])?>" aria-haspopup="dialog" aria-controls="<?=e($modalId)?>">View appointment</button></div>
</article>
