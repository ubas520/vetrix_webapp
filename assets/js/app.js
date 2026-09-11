function fillChatPrompt(text){
    const input=document.getElementById('chatInput');
    if(input){
        input.value=text;
        input.focus();
    }
}
function escapeHtml(t){
    const d=document.createElement('div');
    d.textContent=t ?? '';
    return d.innerHTML;
}
function decodeHtmlEntities(text){
    const textarea=document.createElement('textarea');
    textarea.innerHTML=text || '';
    return textarea.value;
}
function normalizeChatAnswer(html){
    if(!html) return '';
    return decodeHtmlEntities(String(html)
        .replace(/<\s*br\s*\/?>/gi,'\n')
        .replace(/<\s*\/p\s*>/gi,'\n')
        .replace(/<\s*\/div\s*>/gi,'\n')
        .replace(/<\s*li\s*>/gi,'\n• ')
        .replace(/<\s*\/li\s*>/gi,'\n')
        .replace(/<[^>]*>/g,'')
        .replace(/\r/g,'')
        .replace(/[ \t]+\n/g,'\n')
        .replace(/\n{3,}/g,'\n\n')
        .trim());
}
function getLineType(line){
    if(/^Triage level:/i.test(line)) return 'highlight';
    if(/^Pet context found:/i.test(line)) return 'context';
    if(/^Follow-up questions:/i.test(line) || /^General guidance:/i.test(line) || /^Recent records:/i.test(line)) return 'section';
    if(/^Diagnosis:/i.test(line) || /^Treatment:/i.test(line)) return 'subline';
    if(/^•/.test(line)) return 'bullet';
    if(/^\d+\./.test(line)) return 'step';
    return 'line';
}
function formatVetrixAnswer(html){
    const text = normalizeChatAnswer(html);
    const parts = text.split(/\n+/).map(line=>line.trim()).filter(Boolean);
    let out = '<div class="vetrix-answer">';
    parts.forEach(line=>{
        const clean = escapeHtml(line);
        const type = getLineType(line);
        if(type === 'highlight'){
            out += `<div class="vetrix-line vetrix-highlight">${clean}</div>`;
        }else if(type === 'context'){
            out += `<div class="vetrix-line vetrix-context">${clean}</div>`;
        }else if(type === 'section'){
            out += `<div class="vetrix-section-title">${clean}</div>`;
        }else if(type === 'bullet'){
            out += `<div class="vetrix-bullet">${clean}</div>`;
        }else if(type === 'step'){
            out += `<div class="vetrix-step">${clean}</div>`;
        }else if(type === 'subline'){
            out += `<div class="vetrix-subline">${clean}</div>`;
        }else{
            out += `<div class="vetrix-line">${clean}</div>`;
        }
    });
    out += '</div>';
    return out;
}
function appendChatMessage(container, type, html, id){
    const msg=document.createElement('div');
    msg.className=`msg ${type}`;
    if(id) msg.id=id;
    msg.innerHTML=html;
    container.appendChild(msg);
    container.scrollTop=container.scrollHeight;
    return msg;
}
async function requestVetrixAnswer(message){
    const controller=new AbortController();
    const timeout=setTimeout(()=>controller.abort(),20000);
    try{
        const base=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'');
        const res=await fetch(base+'/api/chatbot.php',{
            method:'POST',
            headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':window.VETRIX_CSRF||document.querySelector('meta[name="csrf-token"]')?.content||''},
            body:JSON.stringify({message}),signal:controller.signal
        });
        const data=await res.json().catch(()=>null);
        if(!res.ok) throw new Error(data?.answer||'The assistant is temporarily unavailable. Please try again.');
        if(typeof data?.answer!=='string'||!data.answer.trim()) throw new Error('The assistant returned an empty reply. Please try again.');
        return data;
    }catch(error){
        if(error.name==='AbortError') throw new Error('The reply took too long. Please try again.');
        if(error instanceof TypeError) throw new Error('Could not connect. Check your connection and try again.');
        throw error;
    }finally{clearTimeout(timeout);}
}
function setChatBusy(input,body,busy){
    input.dataset.sending=busy?'true':'false';
    body.setAttribute('aria-busy',String(busy));
    const button=input.parentElement.querySelector('button');
    if(button)button.disabled=busy;
}
function renderChatSuggestions(container,items,onSelect){
    if(!container||!Array.isArray(items))return;
    container.replaceChildren();
    items.filter(item=>typeof item==='string').slice(0,6).forEach(item=>{
        const button=document.createElement('button');
        button.type='button';button.textContent=item;
        button.addEventListener('click',()=>onSelect(item));
        container.appendChild(button);
    });
    container.classList.toggle('show',container.children.length>0);
}
async function sendChat(){
    const input=document.getElementById('chatInput');
    const chatBox=document.getElementById('chatBox');
    if(!input||!chatBox||input.dataset.sending==='true') return;
    const message=input.value.trim();
    if(!message||!input.reportValidity()) return;
    setChatBusy(input,chatBox,true);

    appendChatMessage(chatBox, 'user', escapeHtml(message));
    input.value='';

    const id='typing'+Date.now();
    appendChatMessage(chatBox, 'bot typing-message', '<span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-text">Preparing clear guidance...</span>', id);

    try{
        const data=await requestVetrixAnswer(message);
        const typing=document.getElementById(id);
        if(typing) typing.remove();

        let html=`<div class="vetrix-name">Vetrix</div>${formatVetrixAnswer(data.answer)}`;
        appendChatMessage(chatBox, 'bot', html);
        renderChatSuggestions(document.getElementById('chatSuggestions'),data.suggestions,fillChatPrompt);
    }catch(e){
        const el=document.getElementById(id);
        if(el){el.classList.remove('typing-message');el.textContent=e.message;}
        if(!input.value)input.value=message;
    }finally{setChatBusy(input,chatBox,false);}
    chatBox.scrollTop=chatBox.scrollHeight;
}

function switchMobileView(name, btn){
    document.querySelectorAll('.mobile-view').forEach(view=>view.classList.remove('active'));
    const activeView=document.getElementById('view-'+name);
    if(activeView) activeView.classList.add('active');
    document.querySelectorAll('.phone-nav button').forEach(button=>button.classList.remove('active'));
    if(btn) btn.classList.add('active');
    toggleMobileNotifications(null, false);
}
function toggleMobileNotifications(event, force){
    if(event){
        event.preventDefault();
        event.stopPropagation();
    }
    const panel=document.getElementById('mobileNotificationPanel');
    const button=document.getElementById('mobileBellBtn');
    const badge=button ? button.querySelector('.mobile-bell-badge') : null;
    if(!panel) return;
    const shouldOpen = typeof force === 'boolean' ? force : !panel.classList.contains('show');
    panel.classList.toggle('show', shouldOpen);
    if(button) button.classList.toggle('active', shouldOpen);
    if(shouldOpen && badge) badge.classList.add('is-hidden');
}

document.addEventListener('click', function(event){
    const panel=document.getElementById('mobileNotificationPanel');
    const button=document.getElementById('mobileBellBtn');
    if(!panel || !button || !panel.classList.contains('show')) return;
    if(panel.contains(event.target) || button.contains(event.target)) return;
    panel.classList.remove('show');
    button.classList.remove('active');
});
function showMobileToast(message){
    const toast=document.getElementById('mobileToast');
    if(!toast) return;
    toast.textContent=message;
    toast.classList.add('show');
    setTimeout(()=>toast.classList.remove('show'),2200);
}
function simulateMobileAI(message){
    const chat=document.getElementById('mobileAiChat');
    if(!chat) return;
    chat.innerHTML += `<div class="ai-bubble user">${escapeHtml(message)}</div>`;
    let reply='This is only a simulation. The real client app connects to the Vetrix chatbot for pet guidance and safety reminders.';
    if(message.toLowerCase().includes('vomit')) reply='For vomiting, monitor hydration and appetite. If your pet is weak, repeatedly vomiting, or not eating, visit the clinic.';
    if(message.toLowerCase().includes('sneez')) reply='For sneezing, observe breathing, appetite, and discharge. Persistent symptoms should be checked by the veterinarian.';
    if(message.toLowerCase().includes('vaccine')) reply='Mild tiredness can happen after vaccines, but swelling, breathing problems, or severe weakness need urgent care.';
    chat.innerHTML += `<div class="ai-bubble bot">${escapeHtml(reply)}</div>`;
    chat.scrollTop=chat.scrollHeight;
}

async function loadMobileApiSummary(){
    const box=document.getElementById('mobileApiResult');
    if(!box) return;
    box.className='api-result mt-3';
    box.textContent='Checking live client data...';
    try{
        const res=await fetch('../api/mobile_summary.php',{headers:{'Accept':'application/json'}});
        const data=await res.json();
        if(!data.success){throw new Error(data.message||'Unable to read summary.');}
        const s=data.summary;
        let next='No upcoming appointment yet.';
        if(data.next_appointment){
            const n=data.next_appointment;
            next=`Next: ${n.pet} is ${n.status} on ${n.scheduled_date || n.requested_date}.`;
        }
        box.className='api-result ok mt-3';
        box.innerHTML=`Connected<br><b>${s.pets}</b> pets, <b>${s.appointments}</b> appointments, <b>${s.unread}</b> unread alerts.<br>${escapeHtml(next)}`;
    }catch(e){
        box.className='api-result bad mt-3';
        box.textContent='API check failed. Make sure you are logged in as client and Apache/MySQL are running.';
    }
}

// Whole-system UI helpers
function isMobileSidebar(){ return window.matchMedia('(max-width: 1023px)').matches; }
function clearDesktopSidebarState(){
    const sidebar=document.getElementById('appSidebar');
    document.body.classList.remove('sidebar-collapsed');
    if(sidebar) sidebar.classList.remove('is-collapsed');
}
function setSidebarExpandedState(expanded){
    const sidebar=document.getElementById('appSidebar');
    if(!sidebar) return;
    document.body.classList.toggle('sidebar-collapsed', !expanded);
    sidebar.classList.toggle('is-collapsed', !expanded);
    document.querySelectorAll('.sidebar-collapse-control').forEach(control=>{
        control.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        control.setAttribute('title', expanded ? 'Collapse navigation' : 'Expand navigation');
        control.setAttribute('aria-label', expanded ? 'Collapse navigation' : 'Expand navigation');
    });
    try{localStorage.setItem('vetrix.sidebar.expanded', expanded ? '1' : '0');}catch(e){}
}
function toggleSidebarCollapse(force){
    if(isMobileSidebar()){ toggleSidebar(typeof force==='boolean'?force:undefined); return; }
    const expanded=typeof force==='boolean'?force:document.body.classList.contains('sidebar-collapsed');
    setSidebarExpandedState(expanded);
}
function toggleSidebar(force){
    const sidebar=document.getElementById('appSidebar');
    const backdrop=document.querySelector('.sidebar-backdrop');
    if(!sidebar) return;
    if(!isMobileSidebar()){ toggleSidebarCollapse(typeof force==='boolean'?force:undefined); return; }
    clearDesktopSidebarState();
    const shouldOpen=typeof force==='boolean'?force:!sidebar.classList.contains('open');
    sidebar.classList.toggle('open',shouldOpen);
    document.body.classList.toggle('sidebar-mobile-open',shouldOpen);
    if(backdrop) backdrop.classList.toggle('show',shouldOpen);
}
function initializeVetrixShell(){
    const sidebar=document.getElementById('appSidebar');
    const main=document.querySelector('main');
    if(main&&!main.id) main.id='mainContent';
    if(sidebar){
        sidebar.querySelectorAll('.vetrix-nav-group-toggle').forEach(summary=>{
            summary.addEventListener('click',event=>{
                if(!isMobileSidebar() && document.body.classList.contains('sidebar-collapsed')){
                    event.preventDefault();
                    setSidebarExpandedState(true);
                    summary.parentElement.open=true;
                }
            });
        });
        if(isMobileSidebar()){
            clearDesktopSidebarState();
            toggleSidebar(false);
        }
        else{
            let expanded=true;
            try{const saved=localStorage.getItem('vetrix.sidebar.expanded'); expanded=saved===null?true:saved==='1';}catch(e){}
            setSidebarExpandedState(expanded);
        }
        const active=sidebar.querySelector('.vetrix-nav-link.active');
        if(active) active.scrollIntoView({block:'nearest'});
    }
    bindAccountMenu();
    bindNotificationCenter();
    bindViewToggles();
    bindPasswordRules();
    bindModalFixes();
}
window.addEventListener('resize',()=>{
    const sidebar=document.getElementById('appSidebar');
    if(!sidebar) return;
    if(isMobileSidebar()){
        clearDesktopSidebarState();
        toggleSidebar(false);
    }
    else{
        sidebar.classList.remove('open');
        document.body.classList.remove('sidebar-mobile-open');
        let expanded=true;
        try{const saved=localStorage.getItem('vetrix.sidebar.expanded'); expanded=saved===null?true:saved==='1';}catch(e){}
        setSidebarExpandedState(expanded);
    }
});

document.addEventListener('keydown',event=>{
    if(event.key==='Escape'){
        toggleSidebar(false);
        toggleFloatingChat(false);
        closeNotificationCenter();
        closeAccountMenu();
        closeAllNotifications();
    }
});
document.addEventListener('DOMContentLoaded',initializeVetrixShell);

function bindAccountMenu(){
    const button=document.getElementById('accountMenuButton');
    const menu=document.getElementById('accountMenu');
    if(!button||!menu) return;
    button.addEventListener('click',event=>{
        event.stopPropagation();
        const open=!menu.classList.contains('open');
        closeNotificationCenter();
        menu.classList.toggle('open',open);
        menu.setAttribute('aria-hidden',open?'false':'true');
        button.setAttribute('aria-expanded',open?'true':'false');
    });
    document.addEventListener('click',event=>{if(!menu.contains(event.target)&&!button.contains(event.target)) closeAccountMenu();});
}
function closeAccountMenu(){
    const menu=document.getElementById('accountMenu');
    const button=document.getElementById('accountMenuButton');
    if(menu){menu.classList.remove('open');menu.setAttribute('aria-hidden','true');}
    if(button)button.setAttribute('aria-expanded','false');
}

function bindNotificationCenter(){
    const button=document.getElementById('notificationButton');
    if(!button) return;
    button.addEventListener('click',()=>openNotificationCenter());
    document.querySelectorAll('[data-close-notifications]').forEach(el=>el.addEventListener('click',closeNotificationCenter));
    document.getElementById('uiScrim')?.addEventListener('click',closeNotificationCenter);
    document.getElementById('markAllNotificationsRead')?.addEventListener('click',markAllNotificationsRead);
    document.getElementById('markAllNotificationsReadArchive')?.addEventListener('click',async()=>{await markAllNotificationsRead();await loadAllNotifications();});
    document.getElementById('openAllNotifications')?.addEventListener('click',openAllNotifications);
    document.querySelectorAll('[data-close-all-notifications]').forEach(el=>el.addEventListener('click',closeAllNotifications));
}
async function openNotificationCenter(){
    closeAccountMenu();
    const panel=document.getElementById('notificationPopover');
    const scrim=document.getElementById('uiScrim');
    const button=document.getElementById('notificationButton');
    if(!panel) return;
    panel.classList.add('open'); panel.setAttribute('aria-hidden','false');
    document.body.classList.add('notification-overlay-open');
    if(scrim){scrim.hidden=false;requestAnimationFrame(()=>scrim.classList.add('show'));}
    if(button)button.setAttribute('aria-expanded','true');
    await loadNotificationPreview();
}
function closeNotificationCenter(){
    const panel=document.getElementById('notificationPopover');
    const scrim=document.getElementById('uiScrim');
    const button=document.getElementById('notificationButton');
    if(panel){panel.classList.remove('open');panel.setAttribute('aria-hidden','true');}
    if(scrim){scrim.classList.remove('show');setTimeout(()=>{if(!scrim.classList.contains('show'))scrim.hidden=true;},180);}
    if(button)button.setAttribute('aria-expanded','false');
    if(!document.getElementById('allNotificationsDialog')?.classList.contains('open')) document.body.classList.remove('notification-overlay-open');
}
async function loadNotificationPreview(){
    const list=document.getElementById('notificationPreviewList');
    if(!list)return;
    list.innerHTML='<div class="notification-loading"><span class="loading-ring"></span><p>Loading notifications</p></div>';
    try{
        const res=await fetch((window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'')+'/api/notifications.php?action=list',{headers:{Accept:'application/json'}});
        const data=await res.json();
        if(!data.ok) throw new Error(data.message||'Unable to load notifications.');
        const unreadNotifications=(data.notifications||[]).filter(n=>n.status==='unread');
        const markButton=document.getElementById('markAllNotificationsRead');
        if(markButton)markButton.disabled=unreadNotifications.length===0;
        if(!unreadNotifications.length){list.innerHTML='<div class="notification-empty"><b>You’re up to date.</b><p>No unread notifications.</p></div>';updateNotificationBadge(0);return;}
        list.innerHTML=unreadNotifications.map(n=>notificationButtonMarkup(n,false)).join('');
        list.querySelectorAll('[data-notification-id]').forEach(item=>item.addEventListener('click',()=>handleNotificationClick(item)));
        updateNotificationBadge(unreadNotifications.length);
    }catch(error){list.innerHTML='<div class="notification-empty"><b>Could not load notifications</b><p>Try again after checking the server connection.</p></div>';}
}
function notificationIcon(type){
    const paths={
        appointment:'<path d="M8 2v4M16 2v4M3 9h18"/><rect x="3" y="4" width="18" height="17" rx="3"/><path d="M8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01"/>',
        vaccine:'<path d="m18 2 4 4M17 7l3-3M19 9 2-2M3 21l6.5-6.5M6 18l-2-2M10.5 15.5 5-5M8 13l3 3M12 9l3 3"/><path d="m14 3 7 7-8.5 8.5a2.1 2.1 0 0 1-3 0l-4-4a2.1 2.1 0 0 1 0-3Z"/>',
        record:'<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4a3 3 0 0 1 6 0v2H9Z"/><path d="M9 12h6M9 16h5"/>',
        qr:'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM14 20h2M20 14h1"/>',
        feedback:'<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/><path d="M8 9h8M8 13h5"/>',
        system:'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>'
    };
    return `<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[type]||paths.system}</svg>`;
}
function formatNotificationTime(value){
    const d=new Date(String(value).replace(' ','T'));
    return Number.isNaN(d.getTime())?'':d.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
}
async function notificationPost(action,payload={}){
    const body=new URLSearchParams({action,...payload,csrf_token:window.VETRIX_CSRF||''});
    const res=await fetch((window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'')+'/api/notifications.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':window.VETRIX_CSRF||''},body});
    return res.json();
}
function isSafeInternalNotificationAction(action){
    const value=String(action||'').trim();
    return !!value && !value.includes('..') && !/^[a-z]+:/i.test(value) && !value.startsWith('//');
}
function notificationActionHasExactTarget(action){
    if(!isSafeInternalNotificationAction(action)) return false;
    try{
        const base=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'')+'/';
        const url=new URL(String(action).replace(/^\/+/,''), location.origin+base.replace(/^\/+/,''));
        const params=url.searchParams;
        const exactKeys=['appointment_id','pet_id','client_id','feedback_id','vaccination_id','transaction_id','record_id','request_id','edit_request_id','notification_id','order_id','token'];
        if(exactKeys.some(key=>params.has(key)&&String(params.get(key)||'').trim()!=='')) return true;
        return /(?:verify_account\.php|download_app\.php|profile\.php)$/i.test(url.pathname);
    }catch(_){return false;}
}
function notificationFallbackLinks(type,title){
    const base=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'');
    const role=(document.body?.dataset?.userRole||'client').toLowerCase();
    const root=role==='veterinarian'?'vet':(role==='admin'?'admin':'staff');
    const text=`${type||''} ${title||''}`.toLowerCase();
    const links=[];
    const push=(label,path)=>links.push({label,path:base+'/'+path.replace(/^\/+/, '')});
    if(text.includes('appointment')||text.includes('past')||text.includes('review')) push('Open appointments',`${root}/appointments.php`);
    if(text.includes('vaccin')||text.includes('vaccine')) push('Open vaccinations',root==='admin'?'admin/vaccinations.php':root==='vet'?'vet/vaccinations.php':'staff/appointments.php');
    if(text.includes('record')||text.includes('pet')||text.includes('profile')) push('Open pets',root==='admin'?'admin/pets.php':`${root}/pets.php`);
    if(text.includes('calendar')||text.includes('schedule')||text.includes('tomorrow')||text.includes('unavailability')) push('Open calendar',`${root}/calendar.php`);
    if(text.includes('feedback')) push(root==='admin'?'Open feedback':'Open dashboard',root==='admin'?'admin/feedback.php':`${root}/dashboard.php`);
    if(!links.length) push(root==='admin'?'Open notifications':'Open dashboard',root==='admin'?'admin/notifications.php':`${root}/dashboard.php`);
    return links.slice(0,3);
}
async function refreshNotificationDetail(item){
    const id=Number(item.dataset.notificationId||0)||0;
    if(!id) return null;
    try{
        const base=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'');
        const res=await fetch(`${base}/api/notifications.php?action=detail&id=${encodeURIComponent(id)}`,{headers:{Accept:'application/json'},cache:'no-store'});
        const data=await res.json();
        if(!data.ok||!data.notification) return null;
        const n=data.notification;
        item.dataset.exactTarget=String(Number(n.exact_target)||0);
        item.dataset.actionUrl=n.action_url||item.dataset.actionUrl||'';
        item.dataset.notificationDetailHtml=n.detail_html||'';
        item.dataset.notificationDetailCount=String(Number(n.detail_count)||0);
        item.dataset.notificationDetailEyebrow=n.detail_eyebrow||'';
        item.dataset.notificationMessage=n.message||item.dataset.notificationMessage||'';
        item.dataset.notificationTitle=n.title||item.dataset.notificationTitle||'';
        item.dataset.notificationType=n.type||item.dataset.notificationType||'system';
        return n;
    }catch(_){
        return null;
    }
}
function initNotificationDetailPagination(){
    const root=document.getElementById('globalDetailContent');
    const list=root?.querySelector('[data-notification-paginated]');
    const pager=root?.querySelector('[data-notification-inline-pager]');
    if(!list||!pager)return;
    const items=[...list.querySelectorAll('[data-notification-page-item]')],size=Math.max(1,Number(list.dataset.pageSize)||5),max=Math.max(1,Number(pager.dataset.totalPages)||Math.ceil(items.length/size));
    let page=1;const label=pager.querySelector('[data-inline-page-label]'),nav=pager.querySelector('[data-inline-nav]');
    const go=value=>{const wanted=Math.trunc(Number(value));if(!Number.isFinite(wanted)||wanted<1||wanted>max)return;page=wanted;draw();};
    const control=(text,target,disabled,current=false,step=false)=>{const el=document.createElement(disabled||current?'span':'button');el.className=(step?'page-step':'page-number')+(current?' current':'')+(disabled?' disabled':'');el.textContent=text;if(disabled){el.setAttribute('aria-disabled','true')}else if(current){el.setAttribute('aria-current','page')}else{el.type='button';el.addEventListener('click',()=>go(target));}return el;};
    const draw=()=>{
      items.forEach((el,i)=>el.hidden=!(i>=(page-1)*size&&i<page*size));
      if(label){
        label.innerHTML=`Page <input class="pagination-page-input" type="number" min="1" max="${max}" value="${page}" inputmode="numeric" aria-label="Current page"> <span>of ${max}</span>`;
        const input=label.querySelector('.pagination-page-input');
        input?.addEventListener('change',()=>{const wanted=Math.trunc(Number(input.value));if(!Number.isFinite(wanted)||wanted<1||wanted>max){input.value=page;showToast(`Enter a page from 1 to ${max}.`,'warning');return;}go(wanted);});
        input?.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();input.blur();}});
      }
      if(nav){
        nav.replaceChildren();
        nav.append(control('Previous',page-1,page<=1,false,true));
        const pages=document.createElement('span');pages.className='pagination-pages';
        [page-1,page,page+1].filter(n=>n>=1&&n<=max).forEach(n=>pages.append(control(String(n),n,false,n===page)));
        nav.append(pages,control('Next',page+1,page>=max,false,true));
      }
    };
    draw();
}

function openNotificationDetailPopup(item, action){
    const title=item.dataset.notificationTitle||item.querySelector('b')?.textContent?.trim()||'Notification';
    const message=item.dataset.notificationMessage||item.querySelector('p')?.textContent?.trim()||'No additional details were saved for this alert.';
    const type=item.dataset.notificationType||'system';
    const detailHtml=String(item.dataset.notificationDetailHtml||'').trim();
    const detailCount=Number(item.dataset.notificationDetailCount||0)||0;
    const eyebrow=item.dataset.notificationDetailEyebrow||'Notification detail';
    const hasLiveDetail=detailHtml.length>0;
    const hasRecords=detailCount>0 && hasLiveDetail && !/empty-state/i.test(detailHtml);
    const links=hasLiveDetail?'':notificationFallbackLinks(type,title).map(link=>`<a class="button-secondary" href="${escapeHtml(link.path)}">${escapeHtml(link.label)}</a>`).join('');
    const detailFn=window.openVetrixDetail||window.openRecordDetail;
    closeNotificationCenter();
    if(detailFn){
        const records=hasLiveDetail?`<div class="notification-specific-wrap"><h3>${detailCount} matching record${detailCount===1?'':'s'}</h3>${detailHtml}</div>`:`<div class="notification-specific-wrap"><h3>Notification</h3><article class="dashboard-detail-entry"><span><b>${escapeHtml(title)}</b><small>${escapeHtml(type)}</small><em>${escapeHtml(message)}</em></span></article></div>`;
        const html=`<div class="notification-detail-popup">${records}${links?`<div class="notification-detail-actions">${links}</div>`:''}</div>`;
        detailFn({title,eyebrow,html});
        setTimeout(initNotificationDetailPagination,0);
    }else{
        showToast(message,'info');
    }
}

async function handleNotificationClick(item){
    if(item.classList.contains('unread')){
        await notificationPost('mark_read',{id:item.dataset.notificationId});
        item.classList.remove('unread');
    }
    let action=String(item.dataset.actionUrl||'').trim();
    if(item.dataset.exactTarget!=='1'){
        await refreshNotificationDetail(item);
        action=String(item.dataset.actionUrl||'').trim();
    }
    if(item.dataset.exactTarget==='1' && notificationActionHasExactTarget(action)){
        const base=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'');
        window.location.href=base+'/'+action.replace(/^\/+/, '');
        return;
    }
    openNotificationDetailPopup(item, action);
}
async function markAllNotificationsRead(){const button=document.getElementById('markAllNotificationsRead');if(button?.disabled)return;button.disabled=true;const result=await notificationPost('mark_all');if(!result?.ok){button.disabled=false;showToast(result?.message||'Unable to mark notifications as read.','warning');return;}const preview=document.getElementById('notificationPreviewList');if(preview)preview.innerHTML='<div class="notification-empty"><b>You’re up to date.</b><p>No unread notifications.</p></div>';document.querySelectorAll('#allNotificationList .notification-preview.unread').forEach(item=>item.classList.remove('unread'));const archive=document.getElementById('markAllNotificationsReadArchive');if(archive)archive.disabled=true;updateNotificationBadge(0);}
function updateNotificationBadge(count){
    const button=document.getElementById('notificationButton');
    let badge=document.getElementById('notificationBadge');
    count=Number(count)||0;
    if(!button)return;
    if(count<=0){if(badge)badge.remove();return;}
    if(!badge){badge=document.createElement('span');badge.id='notificationBadge';badge.className='notif-count-badge';button.appendChild(badge);}
    badge.textContent=count>99?'99+':String(count);
}


function notificationDateLabel(value){
    const date=new Date(String(value||'').replace(' ','T'));
    if(Number.isNaN(date.getTime())) return 'Unknown date';
    const today=new Date(), yesterday=new Date(); yesterday.setDate(today.getDate()-1);
    const same=(a,b)=>a.getFullYear()===b.getFullYear()&&a.getMonth()===b.getMonth()&&a.getDate()===b.getDate();
    if(same(date,today)) return 'Today';
    if(same(date,yesterday)) return 'Yesterday';
    return date.toLocaleDateString([], {month:'long',day:'numeric',year:'numeric'});
}
function notificationButtonMarkup(n, archive=false){
    const liveTitle=n.display_title||n.title||'Notification';
    const liveMessage=n.display_message||n.message||n.preview||'';
    return `<button class="notification-preview ${archive?'notification-archive-item':''} ${n.status==='unread'?'unread':''}" type="button" data-notification-id="${Number(n.id)||0}" data-action-url="${escapeHtml(n.action_url||'')}" data-exact-target="${Number(n.exact_target)||0}" data-notification-type="${escapeHtml(n.type||'system')}" data-notification-title="${escapeHtml(liveTitle)}" data-notification-message="${escapeHtml(liveMessage)}" data-notification-detail-html="${escapeHtml(n.detail_html||'')}" data-notification-detail-count="${Number(n.detail_count)||0}" data-notification-detail-eyebrow="${escapeHtml(n.detail_eyebrow||'')}"><span class="notification-type-icon">${notificationIcon(n.type)}</span><span><span class="notification-archive-title"><b>${escapeHtml(liveTitle)}</b></span><p>${escapeHtml(n.preview||'')}</p><small>${formatNotificationTime(n.created_at)}</small></span></button>`;
}
async function openAllNotifications(){
    closeNotificationCenter();
    const dialog=document.getElementById('allNotificationsDialog');
    if(!dialog) return;
    dialog.classList.add('open');dialog.setAttribute('aria-hidden','false');
    document.body.classList.add('notification-overlay-open');
    await loadAllNotifications();
}
function closeAllNotifications(){
    const dialog=document.getElementById('allNotificationsDialog');
    if(dialog){dialog.classList.remove('open');dialog.setAttribute('aria-hidden','true');}
    document.body.classList.remove('notification-overlay-open');
}
async function loadAllNotifications(){
    const list=document.getElementById('allNotificationList');if(!list)return;
    list.innerHTML='<div class="notification-loading"><span class="loading-ring"></span><p>Loading notifications</p></div>';
    try{
        const base=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'');
        const res=await fetch(base+'/api/notifications.php?action=all',{headers:{Accept:'application/json'},cache:'no-store'});
        const data=await res.json();if(!data.ok)throw new Error(data.message||'Unable to load notifications.');
        const archiveMark=document.getElementById('markAllNotificationsReadArchive');if(archiveMark)archiveMark.disabled=!data.notifications.length||Number(data.unread||0)<=0;
        if(!data.notifications.length){list.innerHTML='<div class="notification-empty"><b>You’re up to date.</b><p>No recent notifications.</p></div>';updateNotificationBadge(0);return;}
        const groups=new Map();data.notifications.forEach(n=>{const label=notificationDateLabel(n.created_at);if(!groups.has(label))groups.set(label,[]);groups.get(label).push(n)});
        list.innerHTML=[...groups.entries()].map(([label,items])=>`<section class="notification-date-group"><header><h3>${escapeHtml(label)}</h3></header><div>${items.map(n=>notificationButtonMarkup(n,true)).join('')}</div></section>`).join('');
        list.querySelectorAll('[data-notification-id]').forEach(item=>item.addEventListener('click',()=>handleNotificationClick(item)));
        updateNotificationBadge(data.unread);
    }catch(_){list.innerHTML='<div class="notification-empty"><b>Could not load notifications</b><p>Try again after checking the server connection.</p></div>';}
}
function toggleFloatingChat(force){
    const box=document.getElementById('floatingAi');
    const panel=document.getElementById('floatingAiPanel');
    const input=document.getElementById('floatingAiInput');
    if(!box)return;
    const shouldOpen=typeof force==='boolean'?force:!box.classList.contains('open');
    box.classList.remove('is-scrolling');
    box.classList.toggle('open',shouldOpen);
    if(panel)panel.setAttribute('aria-hidden',shouldOpen?'false':'true');
    if(shouldOpen&&input)setTimeout(()=>{input.focus();updateFloatingSuggestions((input.value||'').trim());},100);
    if(!shouldOpen)setFloatingChatMaximized(false);
}
let floatingAssistantScrollTimer=null;
function compactFloatingAssistantWhileScrolling(){
    const box=document.getElementById('floatingAi');
    if(!box||box.classList.contains('open')||box.classList.contains('maximized'))return;
    box.classList.add('is-scrolling');
    clearTimeout(floatingAssistantScrollTimer);
    floatingAssistantScrollTimer=setTimeout(()=>box.classList.remove('is-scrolling'),280);
}
window.addEventListener('scroll',compactFloatingAssistantWhileScrolling,{passive:true});
document.addEventListener('scroll',compactFloatingAssistantWhileScrolling,{passive:true,capture:true});
function syncFloatingChatMaximizedBounds(){
    const box=document.getElementById('floatingAi');
    if(!box?.classList.contains('maximized'))return;
    box.style.removeProperty('--floating-ai-left');
    box.style.removeProperty('--floating-ai-top');
}
function setFloatingChatMaximized(maximized){
    const box=document.getElementById('floatingAi');
    if(!box)return;
    box.classList.toggle('maximized',!!maximized);
    document.body.classList.toggle('assistant-maximized',!!maximized);
    if(maximized) requestAnimationFrame(syncFloatingChatMaximizedBounds);
    else {box.style.removeProperty('--floating-ai-left');box.style.removeProperty('--floating-ai-top');}
    const input=document.getElementById('floatingAiInput');
    if(box.classList.contains('open')) updateFloatingSuggestions((input?.value||'').trim());
    const button=box.querySelector('[data-ai-maximize]');
    if(button){button.innerHTML=maximized?'<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 3v6H3M15 3v6h6M9 21v-6H3M15 21v-6h6"/></svg>':'<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/></svg>';button.setAttribute('aria-label',maximized?'Restore compact assistant':'Maximize assistant');}
}
function toggleFloatingChatMaximize(){const box=document.getElementById('floatingAi');if(box)setFloatingChatMaximized(!box.classList.contains('maximized'));}
window.addEventListener('resize',()=>{if(document.getElementById('floatingAi')?.classList.contains('maximized'))syncFloatingChatMaximizedBounds();},{passive:true});
let floatingSuggestionTimer=null;
let floatingSuggestionVersion=0;
function showFloatingSuggestions(show){
    const el=document.getElementById('floatingAiSuggestions');
    if(el)el.classList.toggle('show',!!show);
}
async function updateFloatingSuggestions(query=''){
    const el=document.getElementById('floatingAiSuggestions');if(!el)return;
    const version=++floatingSuggestionVersion;
    if(document.getElementById('floatingAiInput')?.dataset.sending==='true')return;
    try{
        const base=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'');
        const res=await fetch(base+'/api/chatbot.php?action=suggestions&q='+encodeURIComponent(query),{headers:{Accept:'application/json'},cache:'no-store'});
        const data=await res.json();
        if(version!==floatingSuggestionVersion||document.getElementById('floatingAiInput')?.dataset.sending==='true')return;
        if(!res.ok){showFloatingSuggestions(false);return;}
        if(!Array.isArray(data.suggestions)||!data.suggestions.length){showFloatingSuggestions(false);return;}
        el.innerHTML=data.suggestions.slice(0,6).map(item=>`<button type="button" data-ai-suggestion="${escapeHtml(item)}">${escapeHtml(item)}</button>`).join('');
        el.querySelectorAll('[data-ai-suggestion]').forEach(button=>button.addEventListener('click',()=>askFloatingAI(button.dataset.aiSuggestion)));
        showFloatingSuggestions(true);
    }catch(_){if(version===floatingSuggestionVersion)showFloatingSuggestions(!!el.children.length);}
}
function handleFloatingAiInput(input){
    clearTimeout(floatingSuggestionTimer);
    ++floatingSuggestionVersion;
    const query=(input?.value||'').trim();
    floatingSuggestionTimer=setTimeout(()=>updateFloatingSuggestions(query),120);
}
function handleFloatingAiKey(event){
    if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){event.preventDefault();sendFloatingAI();}
}
function askFloatingAI(text){const input=document.getElementById('floatingAiInput');if(input&&input.dataset.sending!=='true'){input.value=text;showFloatingSuggestions(false);sendFloatingAI();}}
async function sendFloatingAI(){
    const input=document.getElementById('floatingAiInput');
    const body=document.getElementById('floatingAiBody');
    if(!input||!body||input.dataset.sending==='true')return;
    const message=input.value.trim();
    if(!message||!input.reportValidity())return;
    setChatBusy(input,body,true);
    clearTimeout(floatingSuggestionTimer);++floatingSuggestionVersion;
    body.insertAdjacentHTML('beforeend',`<div class="float-msg user">${escapeHtml(message)}</div>`);
    input.value='';showFloatingSuggestions(false);
    const id='floatTyping'+Date.now();
    body.insertAdjacentHTML('beforeend',`<div class="float-msg bot typing-message" id="${id}"><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span><span>Preparing guidance</span></div>`);
    body.scrollTop=body.scrollHeight;
    try{
        const data=await requestVetrixAnswer(message);
        document.getElementById(id)?.remove();
        const answer=normalizeChatAnswer(data.answer||'I could not prepare an answer.');
        body.insertAdjacentHTML('beforeend',`<div class="float-msg bot"><b>Vetrix</b><div class="floating-answer-text">${escapeHtml(answer).replace(/\n/g,'<br>')}</div></div>`);
        if(Array.isArray(data.suggestions)){const suggestions=document.getElementById('floatingAiSuggestions');if(suggestions){suggestions.innerHTML=data.suggestions.slice(0,5).map(item=>`<button type="button" data-ai-suggestion="${escapeHtml(item)}">${escapeHtml(item)}</button>`).join('');suggestions.querySelectorAll('[data-ai-suggestion]').forEach(button=>button.addEventListener('click',()=>askFloatingAI(button.dataset.aiSuggestion)));showFloatingSuggestions(data.suggestions.length>0);}}
    }catch(e){
        const typing=document.getElementById(id);
        if(typing){typing.classList.remove('typing-message');typing.textContent=e.message;}
        if(!input.value)input.value=message;
    }finally{setChatBusy(input,body,false);}
    body.scrollTop=body.scrollHeight;
}

function bindViewToggles(){
    document.querySelectorAll('[data-view-toggle]').forEach(group=>{
        const target=document.querySelector(group.dataset.target||'');
        if(!target)return;
        group.querySelectorAll('button[data-view]').forEach(button=>button.addEventListener('click',()=>{
            const view=button.dataset.view;
            target.classList.toggle('view-grid',view==='grid');
            target.classList.toggle('view-list',view==='list');
            group.querySelectorAll('button').forEach(b=>b.classList.toggle('active',b===button));
            try{localStorage.setItem('vetrix.view.'+(group.dataset.key||'default'),view);}catch(e){}
        }));
        let saved='list';try{saved=localStorage.getItem('vetrix.view.'+(group.dataset.key||'default'))||group.dataset.default||'list';}catch(e){}
        group.querySelector(`button[data-view="${saved}"]`)?.click();
    });
}
function bindPasswordRules(){
    document.querySelectorAll('[data-password-input]').forEach(input=>{
        const form=input.closest('form');
        const update=()=>{
            const value=input.value;
            const rules={length:value.length>=10,upper:/[A-Z]/.test(value),lower:/[a-z]/.test(value),number:/\d/.test(value),symbol:/[^A-Za-z0-9]/.test(value)};
            Object.entries(rules).forEach(([key,ok])=>form?.querySelector(`[data-rule="${key}"]`)?.classList.toggle('met',ok));
        };
        input.addEventListener('input',update);update();
    });
}
function bindModalFixes(){
    document.querySelectorAll('.appt-modal').forEach(modal=>{
        let wasOpen=false;
        let returnFocus=null;
        const syncFocus=()=>{
            const isOpen=modal.classList.contains('show');
            if(isOpen===wasOpen)return;
            wasOpen=isOpen;
            if(isOpen){
                returnFocus=document.activeElement;
                modal.querySelector('.appt-close')?.focus({preventScroll:true});
            }else if(returnFocus?.isConnected){
                returnFocus.focus({preventScroll:true});
            }
        };
        new MutationObserver(syncFocus).observe(modal,{attributes:true,attributeFilter:['class']});
        syncFocus();
        modal.addEventListener('keydown',event=>{
            if(event.key!=='Tab' || document.querySelector('.app-dialog.open'))return;
            const controls=[...modal.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]')].filter(el=>el.getClientRects().length);
            const first=controls[0],last=controls[controls.length-1];
            if(event.shiftKey && document.activeElement===first){event.preventDefault();last?.focus();}
            else if(!event.shiftKey && document.activeElement===last){event.preventDefault();first?.focus();}
        });
    });
    document.addEventListener('show.bs.modal',()=>document.body.classList.add('modal-open-fixed'));
    document.addEventListener('hidden.bs.modal',()=>document.body.classList.remove('modal-open-fixed'));
}
function showToast(message,type='info'){
    const stack=document.getElementById('toastStack');if(!stack)return;
    const toast=document.createElement('div');toast.className=`app-toast ${type}`;toast.innerHTML=`<span>${escapeHtml(message)}</span><button type="button" aria-label="Dismiss"><svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>`;
    toast.querySelector('button').addEventListener('click',()=>toast.remove());stack.appendChild(toast);setTimeout(()=>toast.remove(),4500);
}
(() => {
  'use strict';
  const qs = (s, root=document) => root.querySelector(s);
  const qsa = (s, root=document) => [...root.querySelectorAll(s)];
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));

  function openDialog(dialog){
    if(!dialog) return;
    dialog.classList.add('open');
    dialog.setAttribute('aria-hidden','false');
    document.body.classList.add('overlay-open');
    const focusable=dialog.querySelector('button,[href],input,select,textarea');
    setTimeout(()=>focusable?.focus(),30);
  }
  function closeDialog(dialog){
    if(!dialog) return;
    dialog.classList.remove('open');
    dialog.setAttribute('aria-hidden','true');
    if(!document.querySelector('.app-dialog.open,.calendar-dialog.open,.appt-modal.show')) document.body.classList.remove('overlay-open');
    if(dialog.id==='globalDetailDialog'){
      const params=new URLSearchParams(location.search);
      const returnTo=params.get('return_to');
      if(returnTo && !/^(?:[a-z]+:|\/\/)/i.test(returnTo) && !returnTo.includes('..') && /^[A-Za-z0-9_\/-]+\.php(?:\?.*)?$/.test(returnTo)){
        const base=(window.VETRIX_BASE||'/vetrix/').replace(/\/$/,'');
        location.href=base+'/'+returnTo.replace(/^\//,'');
      }
    }
  }

  let confirmResolver=null;
  function confirmAction(message='Review the information before continuing.', title='Confirm changes'){
    const dialog=qs('#globalConfirmDialog');
    if(!dialog) return Promise.resolve(false);
    qs('#globalConfirmTitle').textContent=title;
    qs('#globalConfirmMessage').textContent=message;
    openDialog(dialog);
    return new Promise(resolve=>{confirmResolver=resolve;});
  }
  function settleConfirm(value){
    closeDialog(qs('#globalConfirmDialog'));
    if(confirmResolver){const resolve=confirmResolver;confirmResolver=null;resolve(value);}
  }
  window.vetrixConfirm=confirmAction;

  function detailMarkup(data){
    if(typeof data==='string') return `<p>${esc(data)}</p>`;
    const fields=data.fields || data;
    return `<div class="detail-grid">${Object.entries(fields).filter(([k,v])=>!String(k).startsWith('_')&&v!==null&&v!==undefined&&v!=='').map(([key,value])=>{
      const label=key.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
      const full=String(value).length>80?' full':'';
      return `<div class="detail-field${full}"><small>${esc(label)}</small><span>${esc(value)}</span></div>`;
    }).join('')}</div>`;
  }
  function openDetail(payload){
    const dialog=qs('#globalDetailDialog'); if(!dialog) return;
    const data=typeof payload==='string'?JSON.parse(payload):payload;
    dialog.classList.toggle('action-queue-detail',data.eyebrow==='Action queue');
    dialog.classList.toggle('staff-action-queue-detail',data.eyebrow==='Staff action queue');
    dialog.classList.toggle('clinical-queue-detail',data.eyebrow==='Clinical queue');
    ['inventory-category-detail','inventory-low-stock-detail','inventory-out-stock-detail'].forEach(name=>dialog.classList.toggle(name,data.dialog_class===name));
    qs('#globalDetailTitle').textContent=data.title || 'Record details';
    qs('#globalDetailEyebrow').textContent=data.eyebrow || 'Record details';
    qs('#globalDetailContent').innerHTML=data.html || detailMarkup(data.fields || data);
    const actions=qs('#globalDetailActions');
    actions.hidden=Boolean(data.hide_footer);
    actions.innerHTML=data.hide_footer?'':'<button class="button-secondary" type="button" data-global-detail-close>Close</button>'+(data.action_url?`<a class="button-primary" href="${esc(data.action_url)}">${esc(data.action_label||'Open record')}</a>`:'');
    actions.querySelector('[data-global-detail-close]')?.addEventListener('click',()=>closeDialog(dialog));
    openDialog(dialog);
  }
  window.openVetrixDetail=openDetail;

  function bindDialogs(){
    qsa('[data-global-confirm-cancel]').forEach(el=>el.addEventListener('click',()=>settleConfirm(false)));
    qs('#globalConfirmProceed')?.addEventListener('click',()=>settleConfirm(true));
    qsa('[data-global-detail-close]').forEach(el=>el.addEventListener('click',()=>closeDialog(qs('#globalDetailDialog'))));
    document.addEventListener('keydown',e=>{
      if(e.key!=='Escape')return;
      if(qs('#globalConfirmDialog.open'))settleConfirm(false);
      closeDialog(qs('#globalDetailDialog.open'));
    });
  }

  function formActionLabel(form, submitter){
    const button=submitter || form.querySelector('button[type="submit"],button:not([type]),input[type="submit"]');
    const text=(button?.textContent || button?.value || '').trim().replace(/\s+/g,' ');
    return text || 'save these changes';
  }
  function bindFormConfirmations(){
    document.addEventListener('submit', async event=>{
      const form=event.target;
      if(!(form instanceof HTMLFormElement))return;
      if((form.method||'get').toLowerCase()!=='post' || form.dataset.confirmSkip==='true' || form.dataset.confirmed==='true')return;
      const submitter=event.submitter || form.querySelector('button[type="submit"],input[type="submit"]');
      const explicit=form.hasAttribute('data-confirm-message') || form.hasAttribute('data-confirm-title') || submitter?.hasAttribute('data-confirm-message');
      const destructive=Boolean(submitter?.classList.contains('button-danger') || submitter?.classList.contains('danger') || /delete|reject|deactivate|archive|remove|cancel/i.test((submitter?.textContent||submitter?.value||'').trim()));
      if(!explicit && !destructive)return;
      event.preventDefault();
      event.stopImmediatePropagation();
      const action=formActionLabel(form,submitter);
      const message=submitter?.dataset.confirmMessage || form.dataset.confirmMessage || `Confirm that you want to ${action.toLowerCase()}.`;
      const parentDetail=form.closest('#globalDetailDialog.open');
      if(parentDetail) closeDialog(parentDetail);
      const ok=await confirmAction(message,form.dataset.confirmTitle||'Confirm changes');
      if(!ok)return;
      form.dataset.confirmed='true';
      if(event.submitter?.name){
        let hidden=form.querySelector(`input[type="hidden"][data-submitter-copy="${CSS.escape(event.submitter.name)}"]`);
        if(!hidden){hidden=document.createElement('input');hidden.type='hidden';hidden.name=event.submitter.name;hidden.dataset.submitterCopy=event.submitter.name;form.appendChild(hidden);}
        hidden.value=event.submitter.value || '1';
      }
      form.requestSubmit(submitter || undefined);
    },true);
  }

  function bindDetailTriggers(){
    document.addEventListener('click',event=>{
      const trigger=event.target.closest('[data-record-detail],[data-detail-json]');
      if(!trigger)return;
      if(event.target.closest('a[data-detail-bypass]')) return;
      const interactive=event.target.closest('button,input,select,textarea,label,form');
      if(interactive && interactive!==trigger)return;
      const raw=trigger.dataset.recordDetail || trigger.dataset.detailJson;
      if(!raw)return;
      if(trigger.matches('a') || trigger.closest('a')===trigger){event.preventDefault();}
      event.stopPropagation();
      try{openDetail(JSON.parse(raw));}catch(_){openDetail({title:'Record details',fields:{details:raw}});}
    });
    qsa('[data-record-detail],[data-detail-json]').forEach(el=>{
      el.classList.add('clickable-record');
      if(!['BUTTON','A'].includes(el.tagName))el.setAttribute('role','button');
      if(!el.hasAttribute('tabindex'))el.tabIndex=0;
      el.addEventListener('keydown',e=>{
        if(e.key!=='Enter'&&e.key!==' ')return;
        const interactive=e.target.closest('a,button,input,select,textarea,label,form');
        if(e.target.closest('a[data-detail-bypass]')||(interactive&&interactive!==el))return;
        e.preventDefault();
        try{openDetail(JSON.parse(el.dataset.recordDetail||el.dataset.detailJson));}catch(_){ }
      });
    });
  }


  function bindDashboardRowDetails(){
    document.addEventListener('click',event=>{
      const row=event.target.closest('.dashboard-table-card tbody tr');
      if(!row || row.matches('[data-record-detail],[data-detail-json]'))return;
      if(event.target.closest('a,button,input,select,textarea,label,form'))return;
      const table=row.closest('table'); const headers=qsa('thead th',table).map(h=>h.textContent.trim()).filter(Boolean);
      const cells=qsa('td',row).map(td=>td.textContent.replace(/\s+/g,' ').trim()).filter(Boolean);
      if(!cells.length)return;
      const fields={}; cells.forEach((value,index)=>{fields[headers[index]||('Detail '+(index+1))]=value;});
      const section=row.closest('.dashboard-table-card'); const action=section?.querySelector('.section-heading a[href]');
      openDetail({title:cells[0]||'Record details',eyebrow:section?.querySelector('h2,h3')?.textContent?.trim()||'Overview',fields,action_url:action?.href||'',action_label:action?'Open related page':''});
    });
  }

  function bindPerPage(){
    qsa('.entries-select select,select[name="per_page"]').filter(select=>{const form=select.closest('form');return !select.matches('[data-pos-admin-show]')&&!(form&&form.querySelector('input[type="search"]'));}).forEach(select=>{
      const pageKey='vetrix.perPage.'+location.pathname;
      let saved='';try{saved=localStorage.getItem(pageKey)||'';}catch(_){ }
      const requested=new URL(location.href).searchParams.get('per_page') || saved || select.value || '5';
      const available=[...select.options].map(option=>option.value);
      const current=available.includes(requested)?requested:(select.value||available[0]||'5');
      select.value=current;
      select.removeAttribute('onchange');
      if(select.dataset.vxBound)return;select.dataset.vxBound='1';
      select.addEventListener('change',async()=>{
        if(select.value==='full'){
          const ok=await confirmAction('Showing the full result set can take longer on large records. Continue?','Show all records');
          if(!ok){select.value=current;return;}
        }
        try{localStorage.setItem(pageKey,select.value);}catch(_){ }
        const url=new URL(location.href);url.searchParams.set('per_page',select.value);url.searchParams.delete('page');location.href=url.toString();
      });
    });
    const selected=qsa('.entries-select select,select[name="per_page"]').find(select=>{const form=select.closest('form');return !select.matches('[data-pos-admin-show]')&&!(form&&form.querySelector('input[type="search"]'));})?.value || new URL(location.href).searchParams.get('per_page');
    if(selected){
      qsa('.filter-tab,.admin-filter-pill,.appt-filter,.calendar-filter,.role-filter-tab').forEach(link=>{
        if(!(link instanceof HTMLAnchorElement))return;
        try{const url=new URL(link.href,location.href);if(url.origin===location.origin){url.searchParams.set('per_page',selected);link.href=url.toString();}}catch(_){ }
      });
    }
  }

  function bindPaginationPosition(){
    qsa('.data-pagination .pagination-page-input').forEach(input=>{
      const pager=input.closest('.data-pagination');
      const max=Math.max(1,Number(input.max||pager?.dataset.paginationPages||1));
      const current=Math.max(1,Number(pager?.dataset.paginationCurrent||input.value||1));
      const go=()=>{
        const wanted=Math.trunc(Number(input.value));
        if(!Number.isFinite(wanted)||wanted<1||wanted>max){
          input.value=String(current);
          showToast(`Enter a page from 1 to ${max}.`,'warning');
          return;
        }
        const url=new URL(location.href);
        url.searchParams.set('page',String(wanted));
        const anchor=pager?.dataset.paginationAnchor||'';
        if(anchor)url.hash=anchor;
        document.documentElement.classList.add('vetrix-navigating');
        location.href=url.toString();
      };
      input.addEventListener('change',go);
      input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();go();}});
    });
    qsa('.data-pagination a').forEach(link=>link.addEventListener('click',()=>{
      const section=link.closest('section,[id]') || link.parentElement;
      let id=section?.id;
      if(section&&!id){id='pagination-'+Math.random().toString(36).slice(2,8);section.id=id;}
      try{sessionStorage.setItem('vetrix.pagination.return',JSON.stringify({path:location.pathname,id,top:section?.getBoundingClientRect().top||0}));}catch(_){ }
    }));
    try{
      const saved=JSON.parse(sessionStorage.getItem('vetrix.pagination.return')||'null');
      if(saved?.path===location.pathname&&saved.id){sessionStorage.removeItem('vetrix.pagination.return');setTimeout(()=>document.getElementById(saved.id)?.scrollIntoView({block:'start'}),40);}
    }catch(_){ }
  }

  function bindSmartSearch(){
    qsa('input[type="search"]').forEach((input,index)=>{
      if(input.list||input.matches('[data-client-search-input]'))return;
      const candidates=qsa('.entity-card h2,.entity-card h3,.entity-card p,.user-review-card h4,.appt-record h3,.notification-admin-item h3').map(n=>n.textContent.trim()).filter(v=>v.length>1&&v.length<100);
      if(!candidates.length)return;
      const id='vxSearchSuggestions'+index;
      const list=document.createElement('datalist');list.id=id;
      [...new Set(candidates)].slice(0,40).forEach(value=>{const option=document.createElement('option');option.value=value;list.appendChild(option);});
      input.setAttribute('list',id);input.insertAdjacentElement('afterend',list);
    });
  }

  function bindAiSuggestions(){
    const input=qs('#floatingAiInput');if(!input)return;
    input.addEventListener('input',()=>handleFloatingAiInput(input));
    input.addEventListener('focus',()=>handleFloatingAiInput(input));
  }

  function bindPrintActions(){
    const printPage=qs('.print-page');
    window.printVetrixReport=()=>window.saveVetrixReportPdf?.();
    window.saveVetrixReportPdf=()=>{
      if(!printPage){window.print();return;}
      const styles=qsa('link[rel="stylesheet"]').map(link=>`<link rel="stylesheet" href="${link.href}">`).join('');
      const inline=qsa('style').map(style=>`<style>${style.textContent||''}</style>`).join('');
      const html=`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vetrix</title>${styles}${inline}<style>@page{size:auto;margin:0!important}html,body{margin:0!important;padding:0!important;background:#fff!important}.print-page{margin:0!important;padding:12mm!important;box-shadow:none!important;border:0!important;max-width:none!important;border-radius:0!important}.no-print,.no-print-actions,.report-action-buttons,.vetrix-topbar,.vetrix-sidebar,.floating-ai,.toast-stack,.skip-link{display:none!important}a[href]::after,abbr[title]::after,.print-source,.report-source-url,.report-generated-url,.no-print-actions a[href]::after{content:none!important;display:none!important}</style></head><body class="${String(document.body.className||'').replace(/"/g,'')} report-printing">${printPage.outerHTML}</body></html>`;
      const writeAndPrint=(targetWindow,cleanup)=>{
        const doc=targetWindow?.document;
        if(!doc){window.print();return;}
        doc.open();doc.write(html);doc.close();
        setTimeout(()=>{try{targetWindow.focus();targetWindow.print();}catch(_){window.print();}if(typeof cleanup==='function')setTimeout(cleanup,2500);},500);
      };
      const popup=window.open('','Vetrix','popup=yes,width=900,height=1200');
      if(popup){writeAndPrint(popup,()=>{try{popup.close();}catch(_){}});return;}
      const frame=document.createElement('iframe');
            frame.setAttribute('aria-hidden','true');
      Object.assign(frame.style,{position:'fixed',left:'-10000px',top:'0',width:'794px',height:'1123px',border:'0',opacity:'0',pointerEvents:'none'});
      document.body.appendChild(frame);
      const printFrame=()=>writeAndPrint(frame.contentWindow,()=>frame.remove());
      if(frame.contentDocument) printFrame(); else frame.addEventListener('load',printFrame,{once:true});
    };
  }

  function init(){
    bindDialogs();bindFormConfirmations();bindDetailTriggers();bindDashboardRowDetails();bindPerPage();bindPaginationPosition();bindSmartSearch();bindAiSuggestions();bindPrintActions();
  }
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();

(() => {
  const trigger = document.getElementById('globalSearchButton');
  const dialog = document.getElementById('globalSearchDialog');
  const input = document.getElementById('globalSearchInput');
  const results = document.getElementById('globalSearchResults');
  if (!trigger || !dialog || !input || !results) return;

  const pages = [...document.querySelectorAll('.vetrix-nav-link[href]')].map(link => ({
    href: link.href,
    label: (link.querySelector('.vetrix-nav-label')?.textContent || link.textContent || '').trim(),
    group: (link.closest('.vetrix-nav-section')?.querySelector('.vetrix-nav-section-title')?.textContent || 'Clinic tools').trim(),
    icon: link.querySelector('.vetrix-nav-icon')?.cloneNode(true),
    active: link.matches('.active,[aria-current="page"]')
  }));
  let previousFocus = null;

  const visibleResults = () => [...results.querySelectorAll('.vetrix-search-result:not([hidden])')];
  const render = value => {
    const query = String(value || '').trim().toLocaleLowerCase();
    const matches = pages.filter(page => !query || `${page.label} ${page.group}`.toLocaleLowerCase().includes(query));
    results.replaceChildren();
    if (!matches.length) {
      const empty = document.createElement('div');
      empty.className = 'vetrix-search-empty';
      empty.innerHTML = '<b>No matching page</b><span>Try a page name such as Appointments, Pets, or Inventory.</span>';
      results.append(empty);
      return;
    }
    matches.forEach((page, index) => {
      const link = document.createElement('a');
      link.className = 'vetrix-search-result';
      link.href = page.href;
      link.setAttribute('role', 'option');
      link.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
      if (page.icon) {
        page.icon.classList.add('vetrix-search-result-icon');
        page.icon.setAttribute('aria-hidden', 'true');
        link.append(page.icon);
      }
      const copy = document.createElement('span');
      const name = document.createElement('b');
      const group = document.createElement('small');
      name.textContent = page.label;
      group.textContent = page.active ? `${page.group} · Current page` : page.group;
      copy.append(name, group);
      link.append(copy);
      link.addEventListener('click', close);
      results.append(link);
    });
  };
  const selectResult = delta => {
    const links = visibleResults();
    if (!links.length) return;
    let index = links.findIndex(link => link.getAttribute('aria-selected') === 'true');
    index = (index + delta + links.length) % links.length;
    links.forEach((link, itemIndex) => link.setAttribute('aria-selected', itemIndex === index ? 'true' : 'false'));
    links[index].scrollIntoView({block: 'nearest'});
  };
  function open() {
    previousFocus = document.activeElement;
    dialog.classList.add('open');
    dialog.setAttribute('aria-hidden', 'false');
    trigger.setAttribute('aria-expanded', 'true');
    document.body.classList.add('overlay-open');
    input.value = '';
    render('');
    requestAnimationFrame(() => input.focus());
  }
  function close() {
    dialog.classList.remove('open');
    dialog.setAttribute('aria-hidden', 'true');
    trigger.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('overlay-open');
    if (previousFocus instanceof HTMLElement) previousFocus.focus();
  }

  trigger.setAttribute('aria-expanded', 'false');
  trigger.addEventListener('click', open);
  document.querySelectorAll('[data-global-search-close]').forEach(control => control.addEventListener('click', close));
  input.addEventListener('input', () => render(input.value));
  input.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      selectResult(event.key === 'ArrowDown' ? 1 : -1);
    }
    if (event.key === 'Enter') {
      const selected = results.querySelector('.vetrix-search-result[aria-selected="true"]');
      if (selected) {
        event.preventDefault();
        selected.click();
      }
    }
  });
  document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLocaleLowerCase() === 'k') {
      event.preventDefault();
      dialog.classList.contains('open') ? close() : open();
    } else if (event.key === 'Escape' && dialog.classList.contains('open')) {
      event.preventDefault();
      close();
    }
  });
})();

(() => {
  const workbench = document.querySelector('[data-inventory-workbench]');
  if (!workbench) return;
  const openers = [...document.querySelectorAll('[data-inventory-workbench-open]')];
  const panes = [...workbench.querySelectorAll('[data-inventory-pane]')];
  const closeButtons = [...workbench.querySelectorAll('[data-inventory-workbench-close]')];
  const setOpen = (name, shouldScroll = true) => {
    panes.forEach(pane => { pane.hidden = pane.dataset.inventoryPane !== name; });
    openers.forEach(button => {
      const active = button.dataset.inventoryWorkbenchOpen === name;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-expanded', active ? 'true' : 'false');
    });
    workbench.classList.add('is-open');
    if (shouldScroll) workbench.scrollIntoView({behavior: 'smooth', block: 'start'});
    const firstField = workbench.querySelector(`[data-inventory-pane="${CSS.escape(name)}"] input:not([type="hidden"]), [data-inventory-pane="${CSS.escape(name)}"] select`);
    if (shouldScroll && firstField) setTimeout(() => firstField.focus({preventScroll: true}), 280);
  };
  const setClosed = () => {
    const activeOpener = openers.find(button => button.classList.contains('is-active')) || openers[0];
    workbench.classList.remove('is-open');
    panes.forEach(pane => { pane.hidden = false; });
    openers.forEach(button => {
      button.classList.remove('is-active');
      button.setAttribute('aria-expanded', 'false');
    });
    activeOpener?.focus({preventScroll: true});
  };
  workbench.classList.add('is-ready');
  openers.forEach(button => {
    button.setAttribute('aria-controls', workbench.id || 'inventoryWorkbench');
    button.setAttribute('aria-expanded', 'false');
    button.addEventListener('click', () => setOpen(button.dataset.inventoryWorkbenchOpen));
  });
  closeButtons.forEach(button => button.addEventListener('click', setClosed));
  const requestedMovement = new URL(location.href).searchParams.has('move_item');
  if (requestedMovement) setOpen('move', false);
})();
/* Shared application behavior layer. */


(() => {
  'use strict';
  const qsa=(s,r=document)=>[...r.querySelectorAll(s)];

  function addSearchControls(){
    qsa('form').forEach(form=>{
      const search=form.querySelector('input[type="search"]');
      if(!search || form.dataset.searchBound==='1') return;
      form.dataset.searchBound='1';
      form.classList.add('shell-ui-search-form');
      form.querySelectorAll('select').forEach(select=>{
        select.removeAttribute('onchange');
        select.dataset.manualSearchFilter='1';
      });
      if((form.method||'get').toLowerCase()==='get'){
        form.addEventListener('submit',()=>{
          try{
            sessionStorage.setItem('vetrixSearchScroll',JSON.stringify({
              path:location.pathname,
              y:window.scrollY,
              at:Date.now()
            }));
          }catch(_){}
        });
      }
      let submit=form.querySelector('button[type="submit"],input[type="submit"]');
      if(!submit){
        submit=document.createElement('button');
        submit.type='submit';submit.className='button-primary';submit.textContent='Search';
        form.appendChild(submit);
      }
      const hasClear=[...form.querySelectorAll('a,button')].some(el=>/clear/i.test(el.textContent||''));
      if(!hasClear){
        const clear=document.createElement('button');
        clear.type='button';clear.className='button-secondary shell-ui-search-clear';clear.textContent='Clear';
        clear.addEventListener('click',()=>{
          search.value='';
          form.querySelectorAll('select').forEach(select=>{const all=[...select.options].find(o=>/^(all|all statuses|all types|all categories|all animal types|all sexes)$/i.test(o.textContent.trim()));if(all)select.value=all.value;});
          const url=new URL(location.href);
          url.searchParams.delete(search.name||'q');
          form.querySelectorAll('select[name]').forEach(select=>url.searchParams.delete(select.name));
          url.searchParams.delete('page');
          location.href=url.toString();
        });
        form.appendChild(clear);
      }
    });
  }

  function restoreSearchPosition(){
    let saved=null;
    try{saved=JSON.parse(sessionStorage.getItem('vetrixSearchScroll')||'null');}catch(_){}
    if(!saved || saved.path!==location.pathname || Date.now()-Number(saved.at||0)>15000)return;
    const y=Math.max(0,Number(saved.y)||0);
    const restore=()=>window.scrollTo({top:y,left:0,behavior:'auto'});
    requestAnimationFrame(()=>requestAnimationFrame(restore));
    setTimeout(restore,100);
    setTimeout(restore,280);
    setTimeout(restore,620);
    if(document.readyState!=='complete') window.addEventListener('load',()=>setTimeout(restore,20),{once:true});
    setTimeout(()=>{try{sessionStorage.removeItem('vetrixSearchScroll');}catch(_){}},900);
  }

  function init(){addSearchControls();restoreSearchPosition();}
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();


(() => {
  'use strict';
  const qsa=(selector,root=document)=>Array.from(root.querySelectorAll(selector));

  function removeDuplicateSearchButtons(){
    qsa('form').forEach(form=>{
      const controls=qsa('button,a',form).filter(el=>/^(search|apply|filter)$/i.test((el.textContent||'').trim()));
      const seen=new Set();
      controls.forEach(control=>{
        const key=(control.textContent||'').trim().toLowerCase();
        if(seen.has(key)) control.remove(); else seen.add(key);
      });
    });
  }


  function markInventoryOverviewStates(){
    const panel=document.querySelector('[data-overview-panel="inventory"]');
    if(!panel)return;
    qsa('.overview-item',panel).forEach(item=>{
      const text=(item.textContent||'').toLowerCase();
      if(text.includes('out of stock')) item.dataset.stockState='out_of_stock';
      else if(text.includes('low stock')) item.dataset.stockState='low_stock';
    });
  }

  function normalizeDialogScrolling(root=document){
    const selector='.calendar-dialog .dialog-card,.app-dialog-card,.appt-modal-card,.appointment-modal-card';
    const cards=root.matches?.(selector)?[root]:qsa(selector,root);
    cards.forEach(card=>{
      requestAnimationFrame(()=>requestAnimationFrame(()=>{
        if(!card.isConnected || card.offsetParent===null) return;
        const available=card.clientHeight;
        const needsScroll=available>0 && card.scrollHeight>available+2;
        card.classList.toggle('dialog-ui-needs-scroll',needsScroll);
      }));
    });
  }

  function watchDialogs(){
    qsa('.calendar-dialog,.app-dialog,.appt-modal,.appointment-modal-backdrop').forEach(dialog=>{
      new MutationObserver(()=>normalizeDialogScrolling(dialog)).observe(dialog,{attributes:true,attributeFilter:['class','aria-hidden']});
    });
    document.addEventListener('click',()=>setTimeout(()=>normalizeDialogScrolling(),0));
  }

  function init(){
    removeDuplicateSearchButtons();
    markInventoryOverviewStates();
    normalizeDialogScrolling();
    watchDialogs();
    window.addEventListener('resize',()=>normalizeDialogScrolling(),{passive:true});
  }
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();


(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));

  function closeGlobalDetailBeforeNavigation() {
    document.addEventListener('click', event => {
      const link = event.target.closest('#globalDetailDialog a[href]');
      if (!link) return;
      const dialog = document.getElementById('globalDetailDialog');
      dialog?.classList.remove('open');
      dialog?.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('overlay-open');
    }, true);
  }

  function preserveReturnPath() {
    const params = new URLSearchParams(location.search);
    const returnTo = params.get('return_to');
    if (!returnTo || /^(?:[a-z]+:|\/\/)/i.test(returnTo) || returnTo.includes('..')) return;
    document.querySelectorAll('[data-return-on-close]').forEach(button => button.addEventListener('click', () => {
      const base = (window.VETRIX_BASE || '/vetrix/').replace(/\/$/, '');
      location.href = base + '/' + returnTo.replace(/^\/+/, '');
    }));
  }

  function normalizeDialogLayers() {
    document.querySelectorAll('.calendar-dialog,.app-dialog').forEach(dialog => {
      if (dialog.parentElement !== document.body) document.body.appendChild(dialog);
    });
  }

  function fitAssistantInput() {
    const input = document.getElementById('floatingAiInput');
    if (!input) return;
    const resize = () => {
      input.style.height = 'auto';
      input.style.height = Math.min(116, Math.max(42, input.scrollHeight)) + 'px';
    };
    input.addEventListener('input', resize);
    resize();
  }

  document.addEventListener('DOMContentLoaded', () => {
    closeGlobalDetailBeforeNavigation();
    preserveReturnPath();
    normalizeDialogLayers();
    fitAssistantInput();
  });
})();


(() => {
  'use strict';

  const uploadIcon = '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/></svg>';

  function fitText(element, min = 11, max = 15) {
    if (!element) return;
    element.style.fontSize = `${max}px`;
    element.style.letterSpacing = '';
    let size = max;
    while (size > min && element.scrollWidth > element.clientWidth) {
      size -= 0.5;
      element.style.fontSize = `${size}px`;
    }
    if (element.scrollWidth > element.clientWidth) element.style.letterSpacing = '-0.035em';
  }

  function fitNames() {
    document.querySelectorAll('[data-fit-name],.title-with-status h2,.entity-card header h2,.client-record-title h2,.page-heading-avatar b').forEach(el => fitText(el, 10.5, el.matches('[data-fit-name]') ? 14 : 18));
  }

  function uploadLabel(input) {
    const key = `${input.name || ''} ${input.id || ''}`.toLowerCase();
    if (key.includes('logo')) return 'Upload logo';
    if (key.includes('photo') || key.includes('image')) return 'Upload photo';
    if (key.includes('proof')) return 'Upload proof';
    return 'Upload file';
  }

  function enhanceFileInput(input, index) {
    if (input.dataset.fileEnhanced) return;
    input.dataset.fileEnhanced = '1';
    if (!input.id) input.id = `uploadFile${index}`;
    input.classList.add('control-ui-native-file');

    let trigger = document.querySelector(`label[for="${CSS.escape(input.id)}"]`);
    const existingButton = trigger && (trigger.classList.contains('button-secondary') || trigger.classList.contains('button-primary'));
    if (existingButton) {
      trigger.classList.add('control-ui-upload-button');
      trigger.innerHTML = `${uploadIcon}<span>${uploadLabel(input)}</span>`;
    } else {
      const parentLabel = input.closest('label');
      const picker = document.createElement('span');
      picker.className = 'control-ui-file-picker';
      if (parentLabel) {
        picker.innerHTML = `<span class="button-secondary control-ui-upload-button" role="button" tabindex="0">${uploadIcon}<span>${uploadLabel(input)}</span></span><span class="control-ui-file-name" aria-live="polite">No file selected</span>`;
        parentLabel.insertBefore(picker, input);
        trigger = picker.querySelector('.control-ui-upload-button');
        trigger.addEventListener('keydown', event => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            input.click();
          }
        });
      } else {
        picker.innerHTML = `<label class="button-secondary control-ui-upload-button" for="${input.id}">${uploadIcon}<span>${uploadLabel(input)}</span></label><span class="control-ui-file-name" aria-live="polite">No file selected</span>`;
        input.insertAdjacentElement('beforebegin', picker);
        trigger = picker.querySelector('label');
      }
    }

    let nameOutput = input.dataset.fileNameOutput ? document.getElementById(input.dataset.fileNameOutput) : null;
    if (!nameOutput) nameOutput = input.closest('.photo-upload-row,.brand-logo-upload-row,.control-ui-file-picker')?.querySelector('.control-ui-file-name');
    if (!nameOutput && existingButton) {
      nameOutput = document.createElement('span');
      nameOutput.className = 'control-ui-file-name';
      trigger.insertAdjacentElement('afterend', nameOutput);
    }
    if (nameOutput && !nameOutput.textContent.trim()) nameOutput.textContent = 'No file selected';

    input.addEventListener('change', () => {
      const file = input.files?.[0];
      if (nameOutput) nameOutput.textContent = file?.name || 'No file selected';
      const label = input.closest('label') || document.querySelector(`label[for="${CSS.escape(input.id)}"]`);
      if (label && file) label.title = file.name;
    });
  }

  function enhanceFileInputs() {
    document.querySelectorAll('input[type="file"]').forEach(enhanceFileInput);
  }

  function bindAssistantBackdrop() {
    const assistant = document.getElementById('floatingAi');
    if (!assistant) return;
    assistant.addEventListener('click', event => {
      if (assistant.classList.contains('maximized') && event.target === assistant) toggleFloatingChatMaximize();
    });
  }

  function init() {
    enhanceFileInputs();
    fitNames();
    bindAssistantBackdrop();
    window.addEventListener('resize', fitNames, { passive: true });
    if (document.fonts?.ready) document.fonts.ready.then(fitNames);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();


(function(){
  const qs=(s,r=document)=>r.querySelector(s); const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const esc=s=>String(s??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  document.addEventListener('DOMContentLoaded',()=>{
    document.body.classList.toggle('calendar-status-all', new URLSearchParams(location.search).get('status') === 'all' || !new URLSearchParams(location.search).has('status'));
    const base=(window.VETRIX_BASE||'/').replace(/\/$/,'');

    // Dialog close/back: return to the page the dialog was opened from. This avoids staying on an intermediate return_to URL.
    let modalStack=[];
    const rememberOpen=(dialog)=>{ if(!dialog || dialog.dataset.dialogRemembered) return; dialog.dataset.dialogRemembered='1'; modalStack.push(location.href); };
    const markOpenDialogs=()=>qsa('.app-dialog.open,.calendar-dialog.open').forEach(rememberOpen);
    const obs=new MutationObserver(markOpenDialogs); obs.observe(document.documentElement,{subtree:true,attributes:true,attributeFilter:['class']}); markOpenDialogs();
    document.addEventListener('click',event=>{
      const closeBtn=event.target.closest('[data-global-detail-close],[data-global-confirm-cancel],[data-close-calendar-detail],[data-close-day-action],[data-close-schedule],[data-close-calendar-appointment],[data-close-client-edit],[data-close-client-create],[data-close-user-edit],[data-close-user-create],.dialog-scrim,.app-dialog-scrim');
      if(!closeBtn) return;
      setTimeout(()=>{ const still=qsa('.app-dialog.open,.calendar-dialog.open').length; if(!still && modalStack.length>1){modalStack.pop();} },40);
    },true);
    window.addEventListener('popstate',()=>{const open=qsa('.app-dialog.open,.calendar-dialog.open'); if(open.length){open.forEach(d=>{d.classList.remove('open');d.setAttribute('aria-hidden','true')}); document.body.classList.remove('overlay-open');}});

    // Show a detail popup only when a notification has no direct target.
    const roleRoot=()=>{const role=(document.body?.dataset?.userRole||'staff').toLowerCase();return role==='veterinarian'?'vet':(role==='admin'?'admin':'staff')};
    const notificationFallbackLinks=(type,title)=>{
      const root=roleRoot(); const t=`${type} ${title}`.toLowerCase(); const links=[];
      const push=(label,path)=>links.push({label,path:`${base}/${path.replace(/^\/+/, '')}`});
      if(t.includes('appointment')||t.includes('past')||t.includes('review')) push('Open appointments',`${root}/appointments.php`);
      if(t.includes('vaccin')||t.includes('vaccine')) push('Open vaccinations',root==='admin'?'admin/vaccinations.php':root==='vet'?'vet/vaccinations.php':'staff/appointments.php');
      if(t.includes('record')||t.includes('pet')||t.includes('profile')) push('Open pet records',root==='admin'?'admin/pets.php':`${root}/pets.php`);
      if(t.includes('feedback')) push(root==='admin'?'Open feedback':'Open dashboard',root==='admin'?'admin/feedback.php':`${root}/dashboard.php`);
      if(t.includes('qr')) push('Open QR tools',root==='admin'?'admin/qr.php':`${root}/appointments.php`);
      if(t.includes('calendar')||t.includes('schedule')||t.includes('unavailability')) push('Open calendar',`${root}/calendar.php`);
      if(!links.length) push(root==='admin'?'Open notifications':'Open dashboard',root==='admin'?'admin/notifications.php':`${root}/dashboard.php`);
      return links.slice(0,3);
    };
    document.addEventListener('click',event=>{
      const item=event.target.closest('.notification-preview[data-notification-id]'); if(!item || item.dataset.notificationHandled) return;
      const action=String(item.dataset.actionUrl||'').trim();
      if(action || String(item.dataset.notificationDetailHtml||'').trim()) return;
      const title=item.dataset.notificationTitle||item.querySelector('b')?.textContent?.trim()||'Notification';
      const text=item.dataset.notificationMessage||item.querySelector('p')?.textContent?.trim()||'';
      const type=item.dataset.notificationType||'system';
      const detailFn=window.openVetrixDetail||window.openRecordDetail;
      if(detailFn){
        event.preventDefault(); event.stopImmediatePropagation(); item.dataset.notificationHandled='1';
        const links=notificationFallbackLinks(type,title);
        const html=`<div class="dashboard-ui-overview-list"><article class="dashboard-ui-overview-entry"><span>${calendarIcon()}</span><div><b>${esc(title)}</b><small>${esc(text)}</small><em>This alert has no exact record target, so its saved details are shown here.</em></div></article></div>`;
        detailFn({title,eyebrow:'Notification detail',html});
        if(item.classList.contains('unread') && typeof notificationPost === 'function'){
          notificationPost('mark_read',{id:item.dataset.notificationId}).then(()=>item.classList.remove('unread')).catch(()=>{});
        }
        setTimeout(()=>{item.dataset.notificationHandled=''},300);
      }
    },true);

    // Date filter for clinic schedule detail popup.
    document.addEventListener('input',event=>{
      const input=event.target.closest('[data-schedule-filter]'); if(!input) return;
      const value=input.value; qsa('[data-schedule-row]').forEach(row=>{row.hidden=!!value && row.dataset.scheduleRow!==value});
    });

    function calendarIcon(){return '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4M16 2v4M3 9h18"/><rect x="3" y="4" width="18" height="17" rx="3"/><path d="M8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01"/></svg>'}
  });
})();


(function(){
  const splitName = (text)=>{
    const parts=String(text||'').trim().split(/\s+/).filter(Boolean);
    if(parts.length<2) return null;
    return [parts[0], parts.slice(1).join(' ')];
  };
  const esc=s=>String(s??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const applyNameLines=()=>{
    document.querySelectorAll('.entity-card .title-with-status h2').forEach(h=>{
      if(h.dataset.recordNameLines==='1') return;
      const lines=splitName(h.textContent);
      if(!lines) return;
      h.dataset.recordNameLines='1';
      h.innerHTML=`<span class="review-ui-name-lines"><span class="name-line name-line-primary">${esc(lines[0])}</span><span class="name-line name-line-secondary">${esc(lines[1])}</span></span>`;
      h.title=`${lines[0]} ${lines[1]}`;
    });
  };
  const patchNotificationCondense=()=>{
    document.querySelector('.notification-admin-list')?.classList.add('condensed');
  };
  const patchReportPrint=()=>{
    const printPage=document.querySelector('.print-page');
    if(!printPage) return;
    const buildPrintHtml=()=>{
      const styles=Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(link=>`<link rel="stylesheet" href="${link.href}">`).join('');
      const inline=Array.from(document.querySelectorAll('style')).map(style=>`<style>${style.textContent||''}</style>`).join('');
      const bodyClass=String(document.body.className||'').replace(/"/g,'');
      return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vetrix</title>${styles}${inline}<style>@page{size:auto;margin:0!important}html,body{margin:0!important;padding:0!important;background:#fff!important}.print-page{margin:0!important;padding:12mm!important;box-shadow:none!important;border:0!important;max-width:none!important;border-radius:0!important}.no-print,.no-print-actions,.report-action-buttons,.vetrix-topbar,.vetrix-sidebar,.floating-ai,.toast-stack,.skip-link{display:none!important}a[href]::after,abbr[title]::after,.print-source,.report-source-url,.report-generated-url,.no-print-actions a[href]::after{content:none!important;display:none!important}</style></head><body class="${bodyClass} report-printing">${printPage.outerHTML}</body></html>`;
    };
    const writeAndPrint=(targetWindow,cleanup)=>{
      const doc=targetWindow?.document;
      if(!doc){window.print();return;}
      doc.open();
      doc.write(buildPrintHtml());
      doc.close();
      setTimeout(()=>{
        try{targetWindow.focus();targetWindow.print();}catch(_){window.print();}
        if(typeof cleanup==='function') setTimeout(cleanup,2500);
      },500);
    };
    window.saveVetrixReportPdf=()=>{
      const popup=window.open('','Vetrix','popup=yes,width=900,height=1200');
      if(popup){
        writeAndPrint(popup,()=>{try{popup.close();}catch(_){}});
        return;
      }
      const frame=document.createElement('iframe');
      frame.setAttribute('aria-hidden','true');
      frame.setAttribute('title','Vetrix report print frame');
            Object.assign(frame.style,{position:'fixed',left:'-10000px',top:'0',width:'794px',height:'1123px',border:'0',opacity:'0',pointerEvents:'none'});
      document.body.appendChild(frame);
      const printFrame=()=>writeAndPrint(frame.contentWindow,()=>frame.remove());
      if(frame.contentDocument) printFrame(); else frame.addEventListener('load',printFrame,{once:true});
    };
    window.printVetrixReport=window.saveVetrixReportPdf;
  };
  const fitSearchPlaceholders=()=>{
    const inputs=Array.from(document.querySelectorAll('input[type="search"][placeholder]'));
    if(!inputs.length) return;
    const canvas=fitSearchPlaceholders._canvas || (fitSearchPlaceholders._canvas=document.createElement('canvas'));
    const ctx=canvas.getContext && canvas.getContext('2d');
    if(!ctx) return;
    inputs.forEach(input=>{
      const text=input.getAttribute('placeholder')||'';
      if(!text) return;
      const cs=getComputedStyle(input);
      const baseSize=parseFloat(cs.fontSize)||14;
      const subtitle=document.querySelector('.page-heading p,.hero p,.admin-hero-banner p,.client-hero-banner p,.staff-command-hero p,.reference-hero p,.ai-top p');
      const subtitleSize=subtitle ? parseFloat(getComputedStyle(subtitle).fontSize)||13 : 13;
      const minSize=Math.max(16,subtitleSize);
      let size=Math.max(minSize,Math.min(Math.max(baseSize,16),16));
      const family=cs.fontFamily || 'Inter';
      const weight=cs.fontWeight || '400';
      const pad=(parseFloat(cs.paddingLeft)||0)+(parseFloat(cs.paddingRight)||0)+10;
      const available=Math.max(180,(input.clientWidth||input.offsetWidth||360)-pad);
      while(size>minSize){
        ctx.font=`${weight} ${size}px ${family}`;
        if(ctx.measureText(text).width<=available) break;
        size-=0.4;
      }
      input.style.setProperty('--vx-search-placeholder-size',`${Math.max(minSize,size).toFixed(1)}px`);
    });
  };
  const syncActiveCategoryChips=()=>{
    const normalizeStatusAll=(url,current)=>{
      ['status','filter','verification','rating','role','scope','workforce'].forEach(key=>{
        if(!url.searchParams.has(key)&&current.searchParams.get(key)==='all') url.searchParams.set(key,'all');
        if(!current.searchParams.has(key)&&url.searchParams.get(key)==='all') current.searchParams.set(key,'all');
      });
    };
    const selector='.filter-tab,.reference-filter-chip,.status-summary-chip,.appt-filter,.admin-filter-pill,.role-filter-tab,.overview-tab';
    document.querySelectorAll(selector).forEach(chip=>{
      if(chip.classList.contains('active')||chip.classList.contains('is-active')||chip.getAttribute('aria-current')==='page'||chip.getAttribute('aria-selected')==='true'||chip.getAttribute('aria-pressed')==='true'){
        chip.classList.add('active','is-active');chip.dataset.activeChip='1';
        if(chip.matches('a')&&chip.getAttribute('aria-current')!=='page')chip.setAttribute('aria-current','page');
      }
    });
    document.querySelectorAll('a.filter-tab,a.reference-filter-chip,a.status-summary-chip,a.appt-filter,a.admin-filter-pill,a.role-filter-tab,a.overview-tab').forEach(chip=>{
      try{
        const url=new URL(chip.href,location.href);if(url.origin!==location.origin||url.pathname!==location.pathname)return;
        const ignore=new Set(['page','per_page','view']);const current=new URL(location.href);normalizeStatusAll(url,current);
        const clean=sp=>{const out=[];sp.forEach((v,k)=>{if(!ignore.has(k))out.push(`${k}=${v}`)});return out.sort().join('&')};
        if(clean(url.searchParams)===clean(current.searchParams)){chip.classList.add('active','is-active');chip.dataset.activeChip='1';chip.setAttribute('aria-current','page');}
      }catch(_){}
    });
  };
  const init=()=>{applyNameLines();patchNotificationCondense();patchReportPrint();syncActiveCategoryChips();fitSearchPlaceholders();};
  document.addEventListener('DOMContentLoaded',init);
  if(document.readyState!=='loading') init();
  let searchFitTimer=0;window.addEventListener('resize',()=>{clearTimeout(searchFitTimer);searchFitTimer=setTimeout(fitSearchPlaceholders,120);});const observer=new MutationObserver(()=>{applyNameLines();patchNotificationCondense();syncActiveCategoryChips();fitSearchPlaceholders();});
  observer.observe(document.documentElement,{childList:true,subtree:true});
})();


(() => {
  const revealShell = () => requestAnimationFrame(() => requestAnimationFrame(() => {
    document.body?.classList.remove('vetrix-shell-prepaint');
    document.documentElement.classList.remove('vetrix-precollapsed');
  }));
  const finishPrepaint = () => {
    let done=false; const reveal=()=>{if(done)return;done=true;revealShell();};
    if(document.fonts?.ready) document.fonts.ready.then(reveal).catch(reveal);
    setTimeout(reveal,1200);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', finishPrepaint, {once:true}); else finishPrepaint();

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link || link.target || link.hasAttribute('download')) return;
    const href = link.getAttribute('href') || '';
    if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
    try {
      const url = new URL(href, location.href);
      if (url.origin === location.origin) document.documentElement.classList.add('vetrix-navigating');
    } catch (_) {}
  }, true);
  window.addEventListener('pageshow', () => document.documentElement.classList.remove('vetrix-navigating'));

  const root = document.querySelector('.admin-pos-page');
  if (root) {
    const cards = [...root.querySelectorAll('[data-pos-admin-product]')];
    const grid = root.querySelector('.pos-product-admin-grid');
    const search = root.querySelector('[data-pos-admin-search]');
    const category = root.querySelector('[data-pos-admin-category]');
    const sort = root.querySelector('[data-pos-admin-sort]');
    const show = root.querySelector('[data-pos-admin-show]');
    const pager = root.querySelector('[data-pos-admin-pager]');
    let page = 1;
    const effectiveStatus = card => card.dataset.status || '';
    const sold = card => Number(card.dataset.sold || 0);
    const name = card => (card.dataset.name || '').toLocaleLowerCase();
    function filtered(){
      const q=(search?.value||'').trim().toLocaleLowerCase(), c=(category?.value||'all').toLocaleLowerCase();
      const list=cards.filter(card => {
        const hay=`${card.dataset.name||''} ${card.dataset.sku||''}`.toLocaleLowerCase();
        return (!q || hay.includes(q)) && (c==='all' || (card.dataset.category||'').toLocaleLowerCase()===c);
      });
      const mode=sort?.value||'priority';
      list.sort((a,b)=>{
        if(mode==='az') return name(a).localeCompare(name(b));
        if(mode==='za') return name(b).localeCompare(name(a));
        const rank={out_of_stock:0,low_stock:1,available:2,inactive:3};
        const sr=(rank[effectiveStatus(a)]??4)-(rank[effectiveStatus(b)]??4);
        if(sr) return sr;
        return sold(b)-sold(a) || name(a).localeCompare(name(b));
      });
      return list;
    }
    function render(){
      if(!grid) return;
      const list=filtered(), per=Math.max(1,Number(show?.value||5)), pages=Math.max(1,Math.ceil(list.length/per));
      page=Math.min(Math.max(1,page),pages);
      cards.forEach(card=>card.hidden=true);
      list.slice((page-1)*per,page*per).forEach(card=>{card.hidden=false;grid.appendChild(card)});
      if(pager){
        pager.innerHTML='';
        if(pages>1){
          pager.classList.add('natural-pagination');
          const currentWrap=document.createElement('div');currentWrap.className='pagination-current-page';currentWrap.innerHTML=`<label>Page <input class="pagination-page-input" type="number" min="1" max="${pages}" value="${page}" inputmode="numeric" aria-label="Current page"> <span>of ${pages}</span></label>`;
          const pageInput=currentWrap.querySelector('.pagination-page-input');
          pageInput?.addEventListener('change',()=>{const wanted=Math.trunc(Number(pageInput.value));if(!Number.isFinite(wanted)||wanted<1||wanted>pages){pageInput.value=page;showToast(`Enter a page from 1 to ${pages}.`,'warning');return;}page=wanted;render();root.querySelector('.pos-product-browser')?.scrollIntoView({block:'start'});});
          pageInput?.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();pageInput.blur();}});
          const nav=document.createElement('div');nav.className='pagination-nav';
          const button=(label,target,disabled,current=false,step=false)=>{const el=document.createElement(disabled?'span':'button');el.className=(step?'page-step':'page-number')+(current?' current':'');el.textContent=label;if(disabled){el.classList.add('disabled');el.setAttribute('aria-disabled','true')}else{el.type='button';el.addEventListener('click',()=>{page=target;render();root.querySelector('.pos-product-browser')?.scrollIntoView({block:'start'});});}return el};
          nav.append(button('Previous',page-1,page===1,false,true));
          const pagesWrap=document.createElement('span');pagesWrap.className='pagination-pages';
          [page-1,page,page+1].filter(n=>n>=1&&n<=pages).forEach(n=>pagesWrap.append(button(String(n),n,false,n===page)));
          nav.append(pagesWrap,button('Next',page+1,page===pages,false,true));
          pager.append(currentWrap,nav);
        }
      }
    }
    root.querySelector('[data-pos-admin-apply]')?.addEventListener('click',()=>{page=1;render()});
    root.querySelector('[data-pos-admin-clear]')?.addEventListener('click',()=>{if(search)search.value='';if(category)category.value='all';if(sort)sort.value='priority';if(show)show.value='5';page=1;render()});
    render();
  }
})();
