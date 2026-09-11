<div class="appt-modal-details">
    <div class="appt-box"><small>Status</small><span><?=badge($r['status'])?></span></div>
    <div class="appt-box"><small>Pet</small><b><?=e($r['pet_name'])?> &middot; <?=e($r['species'])?></b></div>
    <div class="appt-box"><small>Owner</small><b><?=e($r['full_name'])?></b></div>
    <div class="appt-box"><small>Phone</small><b><?=e($r['phone'] ?: 'No phone saved')?></b></div>
    <div class="appt-box full"><small>Email</small><span><?=e($r['email'] ?: 'No email saved')?></span></div>
    <div class="appt-box full"><small>Owner address</small><span><?=e($r['address'] ?: 'No address saved')?></span></div>
    <div class="appt-box"><small>Requested date</small><b><?=e(date('M d, Y h:i A', strtotime($r['requested_date'])))?></b></div>
    <div class="appt-box"><small>Clinic schedule</small><b><?=$r['scheduled_date'] ? e(date('M d, Y h:i A', strtotime($r['scheduled_date']))) : 'Not scheduled'?></b></div>
    <div class="appt-box"><small>Assigned veterinarian</small><b><?=e($r['vet_name'] ?: 'Not assigned')?></b></div>
    <div class="appt-box"><small>Confirmation code</small><b><?=e($r['confirmation_code'] ?: 'Not generated')?></b></div>
    <div class="appt-box full"><small>Reason / Concern</small><span><?=e($r['reason'] ?: 'No reason provided.')?></span></div>
    <div class="appt-box full"><small>Current clinic note</small><span><?=e($r['admin_notes'] ?: 'No clinic note yet.')?></span></div>
    <?php if(($_SESSION['role'] ?? '') === 'veterinarian'): ?>
    <div class="appt-box"><small>Breed</small><span><?=e($r['breed'] ?: 'Not set')?></span></div>
    <div class="appt-box"><small>Age</small><span><?=e(pet_age($r['birth_date']))?></span></div>
    <div class="appt-box full"><small>Allergies</small><span><?=e($r['allergies'] ?: 'No allergies saved')?></span></div>
    <div class="appt-box full"><small>Critical notes</small><span><?=e($r['critical_notes'] ?: 'No critical notes saved')?></span></div>
    <?php endif; ?>
</div>
