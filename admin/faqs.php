<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role(['admin','veterinarian','staff']);
$role = $_SESSION['role'] ?? 'staff';
$title = $role === 'admin' ? "FAQ and Access Guide" : "Frequently Asked Questions";
include "../includes/header.php";
include "../includes/navbar.php";
$sidebar = $role === 'admin' ? 'admin_sidebar.php' : ($role === 'veterinarian' ? 'vet_sidebar.php' : 'staff_sidebar.php');
$faqs = [
    'admin' => [
        ['What should I review first after logging in?', 'Start with Dashboard action items. Clear urgent appointments, pending approvals, edit requests, and stock warnings before reports.'],
        ['How do I approve a new client?', 'Open Clients, choose the pending account, then approve and issue OTP. The client must complete OTP verification before using the account.'],
        ['Where do I fix an appointment?', 'Open Appointments to assign the veterinarian, set the schedule, add notes, and update the appointment status.'],
        ['Why will a schedule not save?', 'The selected time may overlap an appointment, unavailable period, or clinic schedule block. Choose another time or resolve the conflict first.'],
        ['What happens when I deactivate an account?', 'The user cannot sign in, but linked clinic records stay saved. Restore the account later when access should return.'],
        ['What does a notification popup mean?', 'The alert has no exact record attached. Read the popup details instead of opening a broad list with unrelated records.'],
    ],
    'veterinarian' => [
        ['Which appointments can I see?', 'You can see appointments assigned to you and other appointment information allowed by your veterinarian account.'],
        ['How do I add a medical record?', 'Open the completed appointment or the Medical Records page, choose the pet, then enter the diagnosis, treatment, prescription, and notes.'],
        ['How do I mark myself unavailable?', 'Open Calendar, choose Staff and Vets, then choose Unavailable. Select the date and time, add the reason, and submit it.'],
        ['How do I review allergies and critical notes?', 'Open Health Monitoring or a pet profile. Profiles with submitted notes show the latest approved health information and pending edits when review is allowed.'],
        ['What should I do when a case is not assigned to me?', 'Use Appointments or Calendar to confirm assignment first. If the case is unassigned and visible to you, open it from the queue before adding clinical notes.'],
        ['Where do I see my clinic schedule?', 'Open Calendar, choose Staff and Vets, then Clinic Schedule or Available. Your scheduled blocks and approved unavailable periods appear there.'],
    ],
    'staff' => [
        ['Can I approve a client account?', 'No. Staff can help enter client details, but an administrator approves the account and issues the OTP.'],
        ['What can I do with appointments?', 'You can review requests, help set schedules, assign a veterinarian when allowed, and update appointment details available to staff.'],
        ['How do I use a pet QR code?', 'Open QR Token and enter the secure token, then view the pet information allowed for front-desk or clinic assistance.'],
        ['Can I change diagnosis or treatment?', 'No. Diagnosis, treatment, prescription, and vaccination entries are handled by veterinarians.'],
        ['How do I record a stock movement?', 'Open Inventory Updates, choose the item, choose stock in or stock out, enter the quantity, and add a reason or reference.'],
        ['Where do I check today’s schedule?', 'Open Calendar or the Staff Dashboard. The schedule queue shows recent appointments and links to the full appointment list.'],
    ],
];
$quickGuides = [
    'admin' => ['Daily admin workflow', ['Start with the dashboard action queue and clear urgent items first.', 'Approve clients, pet profiles, edit requests, and appointment schedules before routine reporting.', 'Use Notifications for follow-ups and Activity Logs only when you need an audit trail.']],
    'veterinarian' => ['Clinical workflow', ['Start with Clinical Queue for today, pending, and critical notes.', 'Use Consultations to complete care records after the appointment.', 'Submit unavailability from Calendar so administrators can approve it.']],
    'staff' => ['Staff workflow', ['Start with Action Queue for pending appointments and inventory attention.', 'Use Client Assistance before creating walk-in pet profiles.', 'Record POS sales only after confirming the cart and client details.']],
];
$access = [
    ['Appointments', true, true, true],
    ['Clinic calendar', true, true, true],
    ['Pet profiles', true, true, true],
    ['Medical record editing', false, true, false],
    ['Client account approval and OTP', true, false, false],
    ['POS processing', 'Monitor', false, true],
    ['Inventory changes', true, false, true],
    ['Reports and activity logs', true, false, false],
    ['Manual notifications', true, false, false],
];
?>
<div class="layout"><?php include "../includes/$sidebar"; ?><main class="content faqs-page <?=e($role)?>-faqs-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Help and permissions</span><h1><?=$role==='admin'?'FAQ and Access Guide':'Frequently Asked Questions'?></h1></div></header>
<section class="surface-card access-faq-search-card">
<form class="access-faq-search" id="accessFaqSearchForm" role="search">
<input class="form-control" type="search" id="accessFaqSearch" placeholder="Search FAQ, workflow, or access area" aria-label="Search FAQ and access guide">
<button class="button-primary" type="submit">Search</button>
<button class="button-secondary" type="button" id="accessFaqClear">Clear</button>
</form>
</section>
<div class="faq-layout <?=$role==='admin'?'faq-admin-layout':''?> <?=$role==='veterinarian'?'faq-vet-layout':''?>">
<section class="surface-card faq-card">
<div class="section-heading"><div><span class="eyebrow"><?=$role==='admin'?'Admin FAQ':'Common questions'?></span><h2><?=$role==='admin'?'Workflow help':e(role_label($role)).' FAQ'?></h2></div></div>
<div class="faq-list" data-faq-search-group>
<?php foreach($faqs[$role] as $index => [$question,$answer]): ?>
<details class="faq-item"><summary><span><?=ui_icon('help-circle')?></span><b><?=e($question)?></b><?=ui_icon('chevron-down')?></summary><p><?=e($answer)?></p></details>
<?php endforeach; ?>
</div>
</section>
<?php if($role==='admin'): ?>
<section class="surface-card access-guide-card faq-helper-card admin-direct-faq-card">
<div class="section-heading"><div><span class="eyebrow">Admin access</span><h2>Handover questions</h2></div></div>
<div class="admin-direct-faq-list admin-handover-faq-list" data-faq-search-group>
<details class="faq-item admin-handover-faq"><summary><span><?=ui_icon('help-circle')?></span><b>Why can a client still not use the account?</b><?=ui_icon('chevron-down')?></summary><p>The account needs admin approval and OTP verification. Check Clients for the account status and OTP state.</p></details>
<details class="faq-item admin-handover-faq"><summary><span><?=ui_icon('help-circle')?></span><b>Why can a client not book for a pet?</b><?=ui_icon('chevron-down')?></summary><p>The pet must be approved. Pending, rejected, or in-person-confirmation pets should not be used for booking.</p></details>
<details class="faq-item admin-handover-faq"><summary><span><?=ui_icon('help-circle')?></span><b>When should I use Calendar instead of Appointments?</b><?=ui_icon('chevron-down')?></summary><p>Use Calendar for day workload, clinic blocks, workforce availability, and schedule conflicts. Use Appointments to edit one request.</p></details>
<details class="faq-item admin-handover-faq"><summary><span><?=ui_icon('help-circle')?></span><b>Who should enter clinical information?</b><?=ui_icon('chevron-down')?></summary><p>Veterinarians should enter diagnosis, treatment, prescription, medical record, and vaccination details.</p></details>
<details class="faq-item admin-handover-faq"><summary><span><?=ui_icon('help-circle')?></span><b>Where can I check who changed a record?</b><?=ui_icon('chevron-down')?></summary><p>Use Activity Logs. It shows the actor, role, record type, time, and saved details.</p></details>
<details class="faq-item admin-handover-faq"><summary><span><?=ui_icon('help-circle')?></span><b>What should I do with a broad notification?</b><?=ui_icon('chevron-down')?></summary><p>Read the popup details. Only notifications with an exact record ID should open a specific record page.</p></details>
</div>
</section>
<?php else: $guide=$quickGuides[$role] ?? $quickGuides['staff']; ?>
<?php if($role==='veterinarian'): ?>
<section class="surface-card access-guide-card faq-helper-card vet-direct-faq-card">
<div class="section-heading"><div><span class="eyebrow">Vet FAQs</span><h2>Vetrix questions from clinic users</h2><p>Simple answers for common non-technical veterinarian questions.</p></div></div>
<div class="admin-direct-faq-list" data-faq-search-group>
<article><b>Where should I start each day?</b><p>Open the Veterinarian Dashboard, then review Clinical Queue, today’s appointments, and critical pet notes.</p></article>
<article><b>Why can I not finish an appointment?</b><p>The appointment may still need admin approval, a schedule, or assignment. Open Appointments first and check the status.</p></article>
<article><b>Where do I write diagnosis and treatment?</b><p>Use Medical Records after the visit. Add diagnosis, treatment, prescription, notes, and the correct visit date.</p></article>
<article><b>How do I check allergies?</b><p>Open Health Monitoring or Pet Profiles. Allergy edit consultations appear in Allergy Edit Reviews.</p></article>
<article><b>Can I change pet details?</b><p>Use Pet Profiles, choose Edit, then save. The owner is notified and the change is kept in the audit log.</p></article>
<article><b>How do I request unavailable time?</b><p>Open Calendar, add an unavailable entry, then wait for admin approval before it blocks scheduling.</p></article>
</div>
</section>
<?php else: ?>
<section class="surface-card access-guide-card faq-helper-card">
<div class="section-heading"><div><span class="eyebrow">Quick guide</span><h2><?=e($guide[0])?></h2><p>Use these notes to choose the right page faster.</p></div></div>
<div class="faq-tip-list"><?php foreach($guide[1] as $tip): ?><article><span><?=ui_icon('check')?></span><p><?=e($tip)?></p></article><?php endforeach; ?></div>
</section>
<?php endif; ?>
<?php endif; ?>
</div>

<?php if($role==='admin'): ?>
<section class="surface-card access-guide-card full-width">
<div class="section-heading"><div><span class="eyebrow">Role boundaries</span><h2>Access reference</h2></div></div>
<div class="table-scroll-only" data-faq-search-group><table class="data-table"><thead><tr><th>Area</th><th>Admin</th><th>Veterinarian</th><th>Staff</th></tr></thead><tbody>
<?php foreach($access as $row): ?><tr><?php foreach($row as $i=>$value): ?><<?= $i===0?'th':'td' ?>><?php if(is_bool($value)): ?><span class="access-mark <?=$value?'yes':'no'?>"><?=$value?'Allowed':'Restricted'?></span><?php else: ?><span class="access-mark info"><?=e($value)?></span><?php endif; ?></<?= $i===0?'th':'td' ?>><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
</section>
<?php endif; ?>
<style>
.access-faq-search-card{margin-bottom:10px;padding:12px 14px!important}.access-faq-search{display:grid;grid-template-columns:minmax(260px,1fr) max-content max-content;gap:8px;align-items:center}.access-faq-search .form-control{height:42px}.access-faq-search-note{margin:7px 0 0;color:#586a7e;font-size:.82rem!important}.faq-layout{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(400px,.95fr);gap:12px}.faq-admin-layout{grid-template-columns:minmax(0,1fr) minmax(400px,1fr);align-items:stretch}.faq-admin-layout>.surface-card{height:100%}.faq-list{display:grid;gap:8px}.faq-item{border:1px solid var(--vx-border);border-radius:14px;background:#fff;overflow:hidden}.faq-item summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:28px 1fr 18px;align-items:center;gap:8px;padding:10px 12px}.faq-item summary::-webkit-details-marker{display:none}.faq-item summary>span{width:30px;height:30px;border-radius:10px;background:#eef5ff;color:#275ca9;display:grid;place-items:center}.faq-item summary>.ui-icon{transition:transform .18s}.faq-item[open] summary>.ui-icon{transform:rotate(180deg)}.faq-item p{margin:0;padding:0 12px 11px 48px;color:#586a7e;line-height:1.38}.access-mark{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:750}.access-mark.yes{background:#eaf8ee;color:#176333}.access-mark.no{background:#f2f4f6;color:#667180}.access-mark.info{background:#eef5ff;color:#315d8c}.faq-tip-list,.admin-direct-faq-list{display:grid;gap:10px}.faq-tip-list article{display:grid;grid-template-columns:34px 1fr;gap:10px;align-items:start;padding:12px;border:1px solid var(--vx-border);border-radius:14px;background:#fff}.faq-tip-list span{width:34px;height:34px;border-radius:12px;background:#eef5ff;color:#275ca9;display:grid;place-items:center}.faq-tip-list p{margin:0;color:#586a7e;line-height:1.4}.admin-direct-faq-card{display:flex;flex-direction:column}.admin-direct-faq-list{flex:1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-content:stretch}.faq-admin-layout .faq-card .faq-list{grid-template-columns:repeat(2,minmax(0,1fr))}.faq-vet-layout{grid-template-columns:1fr}.faq-vet-layout>.surface-card{width:100%}.faq-vet-layout .vet-direct-faq-card .admin-direct-faq-list{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-direct-faq-list article{padding:10px 11px;border:1px solid var(--vx-border);border-radius:13px;background:#fff;min-height:78px}.admin-direct-faq-list b{display:block;margin-bottom:4px;color:#143963}.admin-direct-faq-list p{margin:0;color:#586a7e;line-height:1.38}.faq-search-hidden{display:none!important}@media(max-width:1000px){.faq-layout,.faq-admin-layout,.faq-vet-layout{grid-template-columns:1fr}.faq-admin-layout .faq-card .faq-list,.admin-direct-faq-list,.faq-vet-layout .vet-direct-faq-card .admin-direct-faq-list{grid-template-columns:1fr}}@media(max-width:680px){.access-faq-search{grid-template-columns:1fr}.access-faq-search button{width:100%}}
</style>
<script>
(()=>{
 const faqForm=document.getElementById('accessFaqSearchForm');
 const input=document.getElementById('accessFaqSearch');
 const clear=document.getElementById('accessFaqClear');
  const groups=[...document.querySelectorAll('[data-faq-search-group]')];
 const items=[...document.querySelectorAll('.faq-item,.admin-direct-faq-list article,.faq-tip-list article,.data-table tbody tr')];
 const apply=()=>{
   const q=(input?.value||'').trim().toLowerCase();
   let shown=0;
   items.forEach(item=>{
     const hit=!q || item.textContent.toLowerCase().includes(q);
     item.classList.toggle('faq-search-hidden',!hit);
     if(hit) shown++;
   });
   groups.forEach(group=>{
     const visible=[...group.querySelectorAll('.faq-item,.admin-direct-faq-list article,.faq-tip-list article,.data-table tbody tr')].some(item=>!item.classList.contains('faq-search-hidden'));
     group.classList.toggle('faq-search-hidden',!visible);
   });
    };
 faqForm?.addEventListener('submit',event=>{event.preventDefault();apply();});
 clear?.addEventListener('click',()=>{if(input)input.value='';apply();input?.focus();});
 apply();
})();
</script>
</main></div><?php include "../includes/footer.php"; ?>
