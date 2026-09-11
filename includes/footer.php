<?php if (is_logged_in()):
$floatRole = $_SESSION['role'] ?? 'staff';
$floatSuggestions = match ($floatRole) {
    'admin' => [
        ['Which appointments are pending?', 'Pending appointments'],
        ['Which inventory items are low in stock?', 'Low stock'],
        ['Which client accounts need approval?', 'Client approvals'],
        ['Which vaccinations are due soon?', 'Vaccinations due'],
    ],
    'staff' => [
        ['Which appointment requests need review right now?', 'Pending requests'],
        ['What appointments are scheduled today?', 'Today schedule'],
        ['Which inventory items need attention?', 'Inventory attention'],
        ['How do I assist a walk-in client?', 'Walk-in client'],
    ],
    'veterinarian' => [
        ['What appointments are assigned to me today?', 'Today cases'],
        ['What is my schedule tomorrow?', 'Tomorrow schedule'],
        ['Which appointments need completion or rescheduling?', 'Needs attention'],
        ['Which vaccinations are due soon?', 'Due vaccines'],
    ],
    default => [
        ['How do appointment approvals work?', 'How appointments work'],
        ['What should I do if my pet is vomiting?', 'Pet is vomiting'],
        ['How do I view my pet QR token?', 'Pet QR token'],
        ['Which vaccinations are due soon?', 'Vaccination reminders'],
    ],
};
?>
<div class="floating-ai" id="floatingAi">
    <button class="floating-ai-button" type="button" onclick="toggleFloatingChat()" aria-label="Open Vetrix assistant" title="Open Vetrix assistant">
        <span class="floating-ai-brand-mark" aria-hidden="true"><img src="<?= e(app_url(vetrix_brand_logo_path())) ?>" alt=""></span>
    </button>
    <section class="floating-ai-panel" id="floatingAiPanel" aria-label="Vetrix assistant" aria-live="polite" aria-hidden="true">
        <header class="floating-ai-head">
            <div class="floating-ai-title">
                <span class="floating-ai-logo"><span class="floating-ai-brand-mark" aria-hidden="true"><img src="<?= e(app_url(vetrix_brand_logo_path())) ?>" alt=""></span></span>
                <div><b>Vetrix Assistant</b></div>
            </div>
            <div class="floating-ai-window-actions">
                <button type="button" onclick="toggleFloatingChatMaximize()" aria-label="Maximize assistant" title="Maximize assistant" data-ai-maximize><?= ui_icon('maximize') ?></button>
                <button type="button" onclick="toggleFloatingChat(false)" aria-label="Close assistant" title="Close assistant"><?= ui_icon('x') ?></button>
            </div>
        </header>
        <div class="floating-ai-body" id="floatingAiBody" role="log" aria-label="Conversation with Vetrix" aria-relevant="additions text">
            <div class="float-msg bot"><b>How can I help?</b><span>Ask about records, schedules, clinic tasks, or general pet care available to your account.</span></div>
        </div>
        <div class="floating-ai-suggestions" id="floatingAiSuggestions" aria-label="Suggested questions">
            <?php foreach ($floatSuggestions as [$prompt, $label]): ?>
                <button type="button" onclick="askFloatingAI(<?= e(json_encode($prompt)) ?>)"><?= e($label) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="floating-ai-input">
            <textarea id="floatingAiInput" aria-label="Ask Vetrix assistant" maxlength="1000" rows="1" placeholder="Ask Vetrix" onkeydown="handleFloatingAiKey(event)"></textarea>
            <button type="button" onclick="sendFloatingAI()" aria-label="Send question"><?= ui_icon('send') ?></button>
        </div>
        <small class="floating-ai-note">For urgent or severe symptoms, contact the clinic directly.</small>
    </section>
</div>
<?php endif; ?>

<section class="app-dialog" id="globalConfirmDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="globalConfirmTitle">
    <div class="app-dialog-scrim" data-global-confirm-cancel></div>
    <div class="app-dialog-card app-confirm-card">
        <header><span class="dialog-icon"><?= ui_icon('check') ?></span><div><span class="eyebrow">Confirm action</span><h2 id="globalConfirmTitle">Confirm changes</h2></div></header>
        <p id="globalConfirmMessage">Review the information before continuing.</p>
        <div class="app-dialog-actions"><button class="button-secondary" type="button" data-global-confirm-cancel>Go back</button><button class="button-primary" type="button" id="globalConfirmProceed">Confirm and continue</button></div>
    </div>
</section>
<section class="app-dialog" id="globalDetailDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="globalDetailTitle">
    <div class="app-dialog-scrim" data-global-detail-close></div>
    <div class="app-dialog-card app-detail-card">
        <header><div><span class="eyebrow" id="globalDetailEyebrow">Record details</span><h2 id="globalDetailTitle">Details</h2></div><button class="icon-button" type="button" data-global-detail-close aria-label="Close details"><?= ui_icon('x') ?></button></header>
        <div class="app-detail-content" id="globalDetailContent"></div>
        <div class="app-dialog-actions" id="globalDetailActions"><button class="button-secondary" type="button" data-global-detail-close>Close</button></div>
    </div>
</section>
<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>


<script>
window.VETRIX_BASE = document.body?.dataset?.appBase || '/vetrix/';
window.VETRIX_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

(function(){
  const previousKey='vetrix.previousPage';
  const lastKey='vetrix.lastPage';
  const current=location.pathname+location.search+location.hash;
  const currentRoute=location.pathname+location.search;
  const isStaff=document.body?.dataset?.userRole==='staff';
  const safeSession=()=>{try{return window.sessionStorage}catch(_){return null}};
  const store=safeSession();
  const normalize=url=>{try{const u=new URL(url,location.href);return u.origin===location.origin?u.pathname+u.search+u.hash:''}catch(_){return ''}};
  const rememberPrevious=()=>{
    if(!store) return;
    const last=store.getItem(lastKey)||'';
    if(last && last!==current) store.setItem(previousKey,last);
    store.setItem(lastKey,current);
  };
  rememberPrevious();
  window.addEventListener('pageshow', rememberPrevious);
  const isBackLink=a=>{
    const text=(a?.textContent||a?.getAttribute('aria-label')||'').replace(/\s+/g,' ').trim();
    return /^back\b/i.test(text) || /\bgo back\b/i.test(text);
  };
  document.addEventListener('click', event=>{
    const a=event.target.closest('a[href]');
    if(!a || a.target || a.hasAttribute('download') || a.dataset.smartBack!==undefined || isBackLink(a)) return;
    const href=a.getAttribute('href')||'';
    if(!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
    const next=normalize(href);
    if(!next || next===current) return;
    store?.setItem(previousKey,current);
    store?.setItem('vetrix.sourceFor.'+next,current);
    if(isStaff){
      const nextRoute=next.split('#')[0];
      const nextPath=nextRoute.split('?')[0];
      store?.setItem('vetrix.staff.sourceFor.'+nextRoute,current);
      store?.setItem('vetrix.staff.sourcePathFor.'+nextPath,current);
    }
  }, true);
  document.addEventListener('submit', event=>{
    const form=event.target;
    if(!(form instanceof HTMLFormElement)) return;
    const action=form.getAttribute('action') || location.href;
    const next=normalize(action);
    if(next && next!==current){
      store?.setItem('vetrix.sourceFor.'+next,current);
      if(isStaff){
        const nextRoute=next.split('#')[0];
        store?.setItem('vetrix.staff.sourceFor.'+nextRoute,current);
        store?.setItem('vetrix.staff.sourcePathFor.'+nextRoute.split('?')[0],current);
      }
    }
  }, true);
  window.vetrixSmartBack=function(fallback){
    const fallbackPath=normalize(fallback||'') || normalize(document.body?.dataset?.appBase||'/') || '/';
    const ref=normalize(document.referrer||'');
    const stored=store?.getItem(previousKey)||'';
    const source=store?.getItem('vetrix.sourceFor.'+current)||'';
    const staffSource=isStaff?(store?.getItem('vetrix.staff.sourceFor.'+currentRoute)||''):'';
    const staffPathSource=isStaff?(store?.getItem('vetrix.staff.sourcePathFor.'+location.pathname)||''):'';
    if(isStaff && ref && ref!==current && history.length>1){
      history.back();
      return;
    }
    const target=(staffSource && staffSource!==current) ? staffSource : ((staffPathSource && staffPathSource!==current) ? staffPathSource : ((source && source!==current) ? source : ((ref && ref!==current) ? ref : (stored && stored!==current ? stored : fallbackPath))));
    if(isStaff && staffSource) store?.removeItem('vetrix.staff.sourceFor.'+currentRoute);
    if(isStaff && staffPathSource) store?.removeItem('vetrix.staff.sourcePathFor.'+location.pathname);
    location.href=target;
  };
  document.addEventListener('click', event=>{
    const explicit=event.target.closest('[data-smart-back]');
    const plain=event.target.closest('a[href]');
    const back=explicit || (plain && !plain.target && !plain.hasAttribute('download') && isBackLink(plain) ? plain : null);
    if(!back) return;
    event.preventDefault();
    window.vetrixSmartBack(back.getAttribute('href')||'');
  });
})();
(function(){
  try{
    if(/\/vet\/records\.php$/i.test(location.pathname)) localStorage.removeItem('vetrix.view.vet-records');
  }catch(_){}
})();
</script>
<script src="<?= app_url('assets/js/app.js') ?>?v=<?= e(file_exists(__DIR__ . '/../assets/js/app.js') ? filemtime(__DIR__ . '/../assets/js/app.js') : time()) ?>"></script>
<?php if (($_SESSION['role'] ?? '') === 'staff'): ?><script src="<?= e(app_url('assets/js/product-order-notifications.js')) ?>?v=<?= filemtime(__DIR__ . '/../assets/js/product-order-notifications.js') ?>"></script><?php endif; ?>
</body>
</html>
