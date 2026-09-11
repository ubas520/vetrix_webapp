<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("staff");
$title = "Staff FAQs";
include "../includes/header.php";
include "../includes/navbar.php";
$faqs = [
    ['Where should I start each day?', 'Open the Staff Dashboard first. Review pending appointments, today’s schedule, inventory attention, and client assistance tasks.'],
    ['Can I approve a client account?', 'No. Staff can enter client details, but an administrator approves the account and issues the OTP.'],
    ['What can I do with appointments?', 'You can review requests, set available schedules, assign an active veterinarian, and update the appointment details available to staff.'],
    ['How do I use a pet QR code?', 'Open QR Token and enter the secure token, then view the pet information allowed for clinic assistance.'],
    ['Can I change diagnosis or treatment?', 'No. Diagnosis, treatment, prescription, medical record, and vaccination changes remain veterinarian functions.'],
    ['How do I record a stock movement?', 'Open Inventory Updates, choose an item, select stock in or stock out, enter the quantity, and add a reason or reference.'],
    ['How do I process a POS sale?', 'Open Point of Sale, add available products to the cart, confirm the client and payment details, then save the sale. Stock is deducted automatically.'],
    ['Why does a notification open a popup?', 'Some alerts summarize one or more matching records. The popup shows the relevant details without opening an unrelated broad page.'],
];
?>
<div class="layout"><?php include "../includes/staff_sidebar.php"; ?><main class="content staff-faqs-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Help</span><h1>Staff FAQs</h1></div><a class="button-secondary" href="<?=app_url('staff/dashboard.php')?>"><?=ui_icon('dashboard')?>Dashboard</a></header>
<section class="surface-card access-faq-search-card staff-faq-search-card">
<form class="access-faq-search" id="staffFaqSearchForm" role="search"><input class="form-control" type="search" id="staffFaqSearch" placeholder="Search staff FAQ or workflow" aria-label="Search staff FAQ"><button class="button-primary" type="submit">Search</button><button class="button-secondary" type="button" id="staffFaqClear">Clear</button></form>
</section>
<section class="surface-card faq-card staff-faq-card staff-faq-two-column-card">
<div class="section-heading"><div><span class="eyebrow">Staff FAQs</span><h2>Common clinic staff questions</h2><p>Open a question to view the answer.</p></div></div>
<div class="faq-list staff-faq-two-column-list" id="staffFaqList"><?php foreach($faqs as $index=>[$question,$answer]): ?><details class="faq-item"><summary><span><?=ui_icon('help-circle')?></span><b><?=e($question)?></b><?=ui_icon('chevron-down')?></summary><p><?=e($answer)?></p></details><?php endforeach; ?></div>
</section>
<style>.staff-faq-search-card,.staff-faq-card{width:100%!important;max-width:none!important}.access-faq-search-card{margin-bottom:10px;padding:12px 14px!important}.access-faq-search{display:grid;grid-template-columns:minmax(260px,1fr) max-content max-content;gap:8px;align-items:center}.faq-list{display:grid;gap:8px}.staff-faq-two-column-card .staff-faq-two-column-list{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important;align-items:start!important;align-content:start!important}.faq-item{border:1px solid var(--vx-border);border-radius:14px;background:#fff;overflow:hidden;min-width:0!important}.faq-item summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:28px 1fr 18px;align-items:center;gap:8px;padding:10px 12px}.faq-item summary::-webkit-details-marker{display:none}.faq-item summary>span{width:30px;height:30px;border-radius:10px;background:#eef5ff;color:#275ca9;display:grid;place-items:center}.faq-item summary>.ui-icon{transition:transform .18s}.faq-item[open] summary>.ui-icon{transform:rotate(180deg)}.faq-item p{margin:0;padding:0 12px 11px 48px;color:#586a7e;line-height:1.38}.faq-search-hidden{display:none!important}@media(max-width:860px){.staff-faq-two-column-card .staff-faq-two-column-list{grid-template-columns:1fr!important}}@media(max-width:680px){.access-faq-search{grid-template-columns:1fr}.access-faq-search button{width:100%}}</style>
<script>(()=>{const form=document.getElementById('staffFaqSearchForm');const input=document.getElementById('staffFaqSearch');const clear=document.getElementById('staffFaqClear');const items=[...document.querySelectorAll('#staffFaqList .faq-item')];const apply=()=>{const q=(input?.value||'').trim().toLowerCase();items.forEach(item=>item.classList.toggle('faq-search-hidden',q&&!item.textContent.toLowerCase().includes(q)));};form?.addEventListener('submit',e=>{e.preventDefault();apply();});clear?.addEventListener('click',()=>{input.value='';apply();input.focus();});})();</script>
</main></div><?php include "../includes/footer.php"; ?>
