<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");
$title = "Vet FAQs";
include "../includes/header.php";
include "../includes/navbar.php";
$faqs = [
    ['Where should I start each day?', 'Open the Veterinarian Dashboard first. Review Clinical Queue, upcoming cases, and critical notes before opening routine records.'],
    ['Why can I not mark an appointment completed?', 'The appointment must be approved and visible to your veterinarian account before it can be completed. If it is still pending or unscheduled, ask an administrator to finalize it first.'],
    ['Where do I add diagnosis, treatment, and prescription?', 'Open Medical Records after the consultation. Choose the pet, enter the visit date, diagnosis, treatment, prescription, and notes, then save.'],
    ['How do I check allergies or critical notes?', 'Open Health Monitoring for health-focused information. Use Pet Profiles for broader pet details and Allergy Edit Reviews for owner-submitted allergy changes.'],
    ['Can I edit pet details myself?', 'Yes, open Pet Profiles and choose Edit. Vetrix saves the change, notifies the owner, and records the update in the audit log.'],
    ['Where do I see vaccination follow-ups?', 'Open Vaccinations. Use the due-soon, overdue, and scheduled filters to check which pets need preventive care updates.'],
    ['How do I request unavailable time?', 'Open Calendar and add an unavailable period. It remains pending until an administrator approves it.'],
    ['Why does a notification open a popup instead of a page?', 'Some alerts summarize multiple records. Vetrix opens a popup so you can read the exact matching details instead of sending you to a broad page.'],
];
?>
<div class="layout"><?php include "../includes/vet_sidebar.php"; ?><main class="content vet-faqs-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Help</span><h1>Vet FAQs</h1></div><a class="button-secondary" href="<?=app_url('vet/dashboard.php')?>"><?=ui_icon('dashboard')?>Dashboard</a></header>
<section class="surface-card access-faq-search-card vet-faq-search-card">
<form class="access-faq-search" id="vetFaqSearchForm" role="search"><input class="form-control" type="search" id="vetFaqSearch" placeholder="Search veterinarian FAQ or workflow" aria-label="Search veterinarian FAQ"><button class="button-primary" type="submit">Search</button><button class="button-secondary" type="button" id="vetFaqClear">Clear</button></form>
</section>
<section class="surface-card faq-card vet-faq-card vet-faq-two-column-card">
<div class="section-heading"><div><span class="eyebrow">Vet FAQs</span><h2>Common veterinarian questions</h2></div></div>
<div class="faq-list vet-faq-two-column-list" id="vetFaqList"><?php foreach($faqs as $index=>[$question,$answer]): ?><details class="faq-item"><summary><span><?=ui_icon('help-circle')?></span><b><?=e($question)?></b><?=ui_icon('chevron-down')?></summary><p><?=e($answer)?></p></details><?php endforeach; ?></div>
</section>
<style>.vet-faq-search-card,.vet-faq-card{width:100%!important;max-width:none!important}.access-faq-search-card{margin-bottom:10px;padding:12px 14px!important}.access-faq-search{display:grid;grid-template-columns:minmax(260px,1fr) max-content max-content;gap:8px;align-items:center}.faq-list{display:grid;gap:8px}.vet-faq-two-column-card .vet-faq-two-column-list{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important;align-items:start!important;align-content:start!important}.faq-item{border:1px solid var(--vx-border);border-radius:14px;background:#fff;overflow:hidden;min-width:0!important}.faq-item summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:28px 1fr 18px;align-items:center;gap:8px;padding:10px 12px}.faq-item summary::-webkit-details-marker{display:none}.faq-item summary>span{width:30px;height:30px;border-radius:10px;background:#eef5ff;color:#275ca9;display:grid;place-items:center}.faq-item summary>.ui-icon{transition:transform .18s}.faq-item[open] summary>.ui-icon{transform:rotate(180deg)}.faq-item p{margin:0;padding:0 12px 11px 48px;color:#586a7e;line-height:1.38}.faq-search-hidden{display:none!important}@media(max-width:860px){.vet-faq-two-column-card .vet-faq-two-column-list{grid-template-columns:1fr!important}}@media(max-width:680px){.access-faq-search{grid-template-columns:1fr}.access-faq-search button{width:100%}}</style>
<script>(()=>{const form=document.getElementById('vetFaqSearchForm');const input=document.getElementById('vetFaqSearch');const clear=document.getElementById('vetFaqClear');const items=[...document.querySelectorAll('#vetFaqList .faq-item')];const apply=()=>{const q=(input?.value||'').trim().toLowerCase();items.forEach(item=>item.classList.toggle('faq-search-hidden',q&&!item.textContent.toLowerCase().includes(q)));};form?.addEventListener('submit',e=>{e.preventDefault();apply();});clear?.addEventListener('click',()=>{input.value='';apply();input.focus();});})();</script>
</main></div><?php include "../includes/footer.php"; ?>
