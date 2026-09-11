<?php
// Shared summary; each role keeps its own appointment actions in the detail panel.
$appointmentModalFunction = match ($_SESSION['role'] ?? '') {
    'admin' => 'openApptModal',
    'staff' => 'openStaffApptModal',
    default => 'openVetApptModal',
};
?>
<article id="appointment<?=(int)$r['id']?>" class="appt-record appointment-row">
    <div class="appointment-cell appointment-pet">
        <?=pet_avatar_markup($r, 'appt-avatar')?>
        <div><span class="appointment-cell-label">Pet</span><h3><?=e($r['pet_name'])?></h3></div>
    </div>
    <div class="appointment-cell"><span class="appointment-cell-label">Owner</span><span><?=e($r['full_name'])?></span></div>
    <div class="appointment-cell appointment-time">
        <span class="appointment-cell-label">Scheduled time</span>
        <?php if($r['scheduled_date']): ?>
            <time datetime="<?=e(date('Y-m-d\TH:i', strtotime($r['scheduled_date'])))?>"><b><?=e(date('h:i A', strtotime($r['scheduled_date'])))?></b><small><?=e(date('M d, Y', strtotime($r['scheduled_date'])))?></small></time>
        <?php else: ?><span class="appointment-unscheduled">Not scheduled</span><?php endif; ?>
    </div>
    <div class="appointment-cell"><span class="appointment-cell-label">Veterinarian</span><span><?=e($r['vet_name'] ?: 'Not assigned')?></span></div>
    <div class="appointment-cell appointment-status"><span class="appointment-cell-label">Status</span><?=badge($r['status'])?></div>
    <div class="appointment-cell appointment-actions"><button type="button" class="button-secondary" onclick="<?=$appointmentModalFunction?>('<?=e($modalId)?>')" aria-label="Manage appointment for <?=e($r['pet_name'])?>" aria-haspopup="dialog" aria-controls="<?=e($modalId)?>">Manage</button></div>
</article>
