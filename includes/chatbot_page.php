<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/functions.php';
$chatRole=$chatRole??($_SESSION['role']??'');require_role(['admin','veterinarian','staff']);if(!in_array($chatRole,['admin','veterinarian','staff'],true))$chatRole=$_SESSION['role'];$prefill=trim($_GET['q']??'');$title='Vetrix Assistant';include __DIR__.'/header.php';include __DIR__.'/navbar.php';$sidebar=$chatRole==='admin'?'admin_sidebar.php':($chatRole==='veterinarian'?'vet_sidebar.php':'staff_sidebar.php');
$commonSuggestions = match ($chatRole) {
    'admin' => ['Which client accounts need approval?', 'Which appointments are pending?', 'Which inventory items are low in stock?', 'Which vaccinations are due soon?', 'How many unread notifications are there?'],
    'staff' => ['Which appointments are pending?', 'Which inventory items are low in stock?', 'How do I assist a walk-in client?', 'How do I retrieve a pet record using a QR token?', 'What client information can staff access?'],
    'veterinarian' => ['Which appointments are assigned to me next?', 'Which appointments need attention?', 'How do I add unavailable time?', 'What pet records can I update?', 'Which vaccinations are due soon?'],
    default => ['Which appointments are pending?', 'Which inventory items need attention?', 'Which vaccinations are due soon?'],
};
?>
<div class="layout"><?php include __DIR__.'/'.$sidebar;?><main class="content assistant-page" id="mainContent">
<header class="page-heading assistant-page-heading"><div><span class="eyebrow">Your clinic helper</span><h1>Vetrix Assistant</h1></div><button class="button-secondary" type="button" data-smart-back onclick="if(window.vetrixSmartBack){event.preventDefault();vetrixSmartBack();}else history.back();"><?=ui_icon('arrow-left')?>Back</button></header>
<section class="assistant-workspace surface-card">
<header class="assistant-workspace-head"><div class="assistant-orbit-mark"><?=ui_icon('bot')?></div><div><h2>How can I help today?</h2><p>Ask about appointments, records, clinic tasks, or general pet care.</p></div><span class="assistant-status">Clinic assistant</span></header>
<div class="assistant-page-messages" id="chatBox" role="log" aria-label="Conversation with Vetrix" aria-live="polite" aria-relevant="additions text"><div class="msg bot"><div class="vetrix-name">Vetrix</div><p>Choose a question below or tell me what you need. I can check clinic information available to your account and explain common workflows. For pet-care questions, include the pet's species, age, symptoms, and when they started.</p></div></div>
<div class="assistant-page-suggestions" id="chatSuggestions" aria-label="Suggested questions"><?php foreach($commonSuggestions as $suggestion):?><button type="button" onclick="fillChatPrompt(<?=e(json_encode($suggestion))?>)"><?=e($suggestion)?></button><?php endforeach;?></div>
<div class="assistant-page-composer"><textarea id="chatInput" aria-label="Ask Vetrix assistant" aria-describedby="chatInputHelp" rows="2" maxlength="1000" placeholder="Ask about your clinic or a pet…"><?=e(mb_substr($prefill,0,1000))?></textarea><button type="button" onclick="sendChat()" aria-label="Send message"><?=ui_icon('send')?></button></div><p class="assistant-disclaimer" id="chatInputHelp">Enter to send · Shift + Enter for a new line · Maximum 1,000 characters</p><p class="assistant-disclaimer">For emergencies, contact the clinic or the nearest emergency veterinary service immediately.</p>
</section>
<script>document.getElementById('chatInput')?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();sendChat()}});document.getElementById('chatSuggestions')?.classList.add('show');</script>
</main></div><?php include __DIR__.'/footer.php';?>
