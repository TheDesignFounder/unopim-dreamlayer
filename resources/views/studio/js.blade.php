(() => {
'use strict';
let cfg=window.DreamLayerConfig, labels=cfg.labels, storageKey='dreamlayer-studio:'+cfg.sessionKey;
const $=id=>document.getElementById(id), t=key=>labels[key]||key;
const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
const button=(text,action,cls='outline')=>{const n=el('button',cls,text);n.type='button';n.addEventListener('click',action);return n;};
const uid=()=>crypto.randomUUID(), fresh=()=>({id:uid(),title:t('new-chat'),turns:[],selected:[],selectedResult:null,message:'',operation:'image_to_image',updated_at:new Date().toISOString(),saved:false});
let threads={},active=fresh(),sending=false,expired=false,contextProduct=null,expanded=false,bookmarks={},polling=false,historyTab='chats',historyPage=null,historyItems=[],historyEpoch=0,pickerEpoch=0,pickerSelection=[],catalogPage=null,catalogProduct=null,confirmAction=null;
const resultCache=new Map(), resultNodes=new Map();
function error(text,id='error'){const n=$(id);n.textContent=text||'';n.hidden=!text;}
function persist(){
    threads[active.id]=active;
    try{sessionStorage.setItem(storageKey,JSON.stringify({active:active.id,threads,bookmarks}));}catch(e){error(t('storage-error'));}
}
function restore(){
    try{
        for(let i=sessionStorage.length-1;i>=0;i--){const k=sessionStorage.key(i);if(k.startsWith('dreamlayer-studio:')&&k!==storageKey)sessionStorage.removeItem(k);}
        const data=JSON.parse(sessionStorage.getItem(storageKey)||'null');
        if(data&&data.threads&&data.threads[data.active]){threads=data.threads;active=threads[data.active];bookmarks=data.bookmarks||{};}
    }catch(e){/* Storage is optional; the authenticated server remains authoritative. */}
}
restore();
function post(action,extra={}){if(parent!==window)parent.postMessage({dreamlayer:true,action,...extra},location.origin);}
async function api(path,options={}){
    const response=await fetch(cfg.base+path,{credentials:'same-origin',cache:'no-store',...options,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':cfg.csrf,...options.headers}});
    if([401,419].includes(response.status)||response.redirected&&!response.url.startsWith(cfg.base+'/')){
        expired=true;sessionStorage.removeItem(storageKey);threads={};active=fresh();resultCache.clear();render();post('expired');throw Error(t('session-ended'));
    }
    let data;try{data=await response.json();}catch(e){throw Error(t('load-error'));}
    if(!response.ok){const failure=Error(data.message||t('request-error'));failure.status=response.status;throw failure;}
    return data;
}
async function refreshConfig(){
    try{
        const data=await api('/studio/config');
        if(data.sessionKey!==cfg.sessionKey){sessionStorage.removeItem(storageKey);location.reload();return;}
        cfg=data;labels=data.labels;updateComposer();
    }catch(e){error(e.message);}
}
function historyMessages(){return active.turns.flatMap(turn=>[{role:'user',content:turn.message},...(turn.reply?[{role:'assistant',content:turn.reply}]:[])]).filter(m=>m.content).slice(-20);}
function updateComposer(){
    const blocked=expired||!cfg.connected;
    $('connection').hidden=!blocked;
    if(blocked){$('connection').replaceChildren();const box=el('div','connection-message');box.append(el('h2','',expired?t('session-ended'):t('connect')),el('p','',t('connection-help')),button(t('settings-link'),()=>post('navigate',{url:cfg.base+'/settings'}),'primary'));$('connection').append(box);}
    $('composer').hidden=blocked;$('conversation').hidden=blocked;
    $('message').disabled=sending||active.readonly;$('operation').disabled=sending||active.readonly;
    const sourceReady=active.selected.length>0||(active.selectedResult&&resultCache.get(active.selectedResult)?.status==='completed');
    const hasMessage=!!$('message').value.trim()||active.operation==='background_remove';
    $('send').disabled=sending||blocked||active.readonly||!hasMessage||(active.operation!=='text_to_image'&&!sourceReady);
    $('send').textContent=sending?t('sending'):t('create-preview')+' ↑';
    $('task-help').textContent=active.readonly?t('read-only-result'):active.operation==='text_to_image'?t('generate-help'):active.operation==='background_remove'?t('remove-help'):t('approval-help');
    $('history-state').textContent=cfg.history?t('history-on'):t('history-off');
    document.querySelector('[data-action=new]').disabled=sending;
    document.querySelector('[data-action=pick]').disabled=sending||active.readonly;
    document.querySelector('[data-action=expand]').setAttribute('aria-label',expanded?t('collapse'):t('expand'));
    document.querySelector('[data-action=expand]').title=expanded?t('collapse'):t('expand');
}
function sourceKey(image){return image.image_url;}
function renderSources(){
    $('sources').replaceChildren();
    const sources=active.selected.map(image=>({image,label:image.sku+' · '+image.attribute,remove:()=>{active.selected=active.selected.filter(i=>sourceKey(i)!==sourceKey(image));persist();renderSources();updateComposer();}}));
    if(active.selectedResult){const result=resultCache.get(active.selectedResult);sources.push({image:{image_url:result?.image_url},label:t('source-result')+(result?.target?.sku?' · '+result.target.sku:''),remove:()=>{active.selectedResult=null;persist();renderSources();updateComposer();}});}
    for(const source of sources){const chip=el('div','source-chip');if(source.image.image_url){const img=el('img');img.src=source.image.image_url;img.alt='';chip.append(img);}chip.append(el('span','',source.label));const remove=button('×',source.remove,'');remove.setAttribute('aria-label',t('clear-source'));remove.disabled=sending;chip.append(remove);$('sources').append(chip);}
}
function scrollBottom(){requestAnimationFrame(()=>$('conversation').scrollTo({top:$('conversation').scrollHeight,behavior:'smooth'}));}
function render(){
    resultNodes.clear();$('messages').replaceChildren();
    $('message').value=active.message||'';$('operation').value=active.operation||'image_to_image';
    if(!active.turns.length){const welcome=el('div','welcome');welcome.append(el('h1','',t('welcome-title')),el('p','',t('welcome-copy')));const suggestions=el('div','suggestions');suggestions.append(button(t('add-images'),openPicker),button(t('try-blue'),()=>preset('prompt-blue')),button(t('try-scene'),()=>preset('prompt-scene')));welcome.append(suggestions);$('messages').append(welcome);}
    if(active.omitted)$('messages').append(el('p','fine',t('unsaved-gap')));
    for(const turn of active.turns){const row=el('section','turn');if(turn.message)row.append(el('div','user-message',turn.message));for(const id of turn.workflow_ids||[]){const card=el('article','result');card.dataset.workflow=id;card.setAttribute('aria-label',t('image-result'));row.append(card);resultNodes.set(id,card);renderResult(id,resultCache.get(id));}$('messages').append(row);}
    renderSources();updateComposer();poll();
}
function preset(key){active.message=t(key);active.operation='image_to_image';$('message').value=active.message;$('operation').value=active.operation;persist();updateComposer();if(!active.selected.length&&!active.selectedResult)openPicker();else $('message').focus();}
function renderResult(id,data){
    const card=resultNodes.get(id);if(!card)return;
    const signature=JSON.stringify(data||null);if(card.dataset.signature===signature)return;card.dataset.signature=signature;card.replaceChildren();
    const nearBottom=$('conversation').scrollHeight-$('conversation').scrollTop-$('conversation').clientHeight<100;
    const status=data?.status||'queued';card.classList.toggle('processing',['queued','running'].includes(status));
    const statusLine=el('div','result-status');statusLine.append(el('span','status-dot'),el('span','',data?.delayed?t('waiting'):t('status-'+status)));card.append(statusLine);
    if(data?.image_url){const img=el('img','result-image');img.src=data.image_url;img.alt=data.target?.sku?t('image-result')+' · '+data.target.sku:t('image-result');img.addEventListener('load',()=>{if(nearBottom)scrollBottom();});card.append(img);}
    if(data?.error)card.append(el('p','error',data.error));
    if(data?.loadError)card.append(button(t('retry'),()=>{resultCache.delete(id);poll();},'outline'));
    if(data?.billing_notice)card.append(el('p','fine',data.billing_notice));
    if(data?.billing_url){const billing=el('a','',t('manage-billing'));billing.href=data.billing_url;billing.target='_blank';billing.rel='noopener noreferrer';card.append(billing);}
    if(data?.image_url){
        const target=data.target, desc=el('p','result-description');desc.append(el('strong','result-label',target?target.sku+' · '+target.attribute+' · '+(Number(target.index)+1):t('download-only')));if(target)desc.append(el('br'),document.createTextNode(target.channel+' / '+target.locale));card.append(desc);
        const actions=el('div','result-actions');
        const refine=button(t('refine-this'),()=>{if(sending||active.readonly)return;active.selected=[];active.selectedResult=id;active.operation='image_to_image';$('operation').value=active.operation;persist();renderSources();updateComposer();$('message').focus();});refine.disabled=sending||active.readonly;actions.append(refine);
        const dl=el('a','',t('download-png'));dl.textContent=t('download');if(labels['download-png'])dl.textContent=labels['download-png'];dl.href=data.image_url+'?download=1';dl.setAttribute('download','dreamlayer-'+id+'.png');actions.append(dl);
        if(target){const apply=button(data.applied?t('applied'):t('apply'),async()=>{
            apply.disabled=true;error(null);
            try{const next=await api('/workflows/'+id+'/apply',{method:'POST',body:'{}'});resultCache.set(id,next);renderResult(id,next);}
            catch(e){error(e.message);apply.disabled=false;}
        },data.applied?'applied':'');apply.disabled=data.applied;actions.append(apply);}
        card.append(actions);if(target&&!data.applied)card.append(el('p','fine result-description',t('approval-help')));
    }
    if(data?.references){const details=el('details');details.append(el('summary','',t('request-details')));const list=el('dl');for(const [key,value] of Object.entries(data.references)){if(value)list.append(el('dt','',t(key)),el('dd','',String(value)));}details.append(list);card.append(details);}
    if(nearBottom&&data?.image_url)scrollBottom();
}
async function poll(){
    if(polling||expired||document.hidden)return;polling=true;
    try{const ids=active.turns.flatMap(turn=>turn.workflow_ids||[]).filter(id=>!resultCache.has(id)||['queued','running'].includes(resultCache.get(id).status));await Promise.all(ids.map(async id=>{try{const data=await api('/workflows/'+id);resultCache.set(id,data);renderResult(id,data);}catch(e){const data={status:'failed',error:e.message,loadError:true};resultCache.set(id,data);renderResult(id,data);}}));renderSources();updateComposer();}
    finally{polling=false;}
}
setInterval(poll,2500);
async function send(event){
    event.preventDefault();if(sending||expired||active.readonly)return;error(null);
    active.message=$('message').value.trim();active.operation=$('operation').value;
    if(active.operation!=='text_to_image'&&!active.selected.length&&!active.selectedResult){error(t('source-required'));return;}
    if(active.operation==='text_to_image'&&(active.selected.length>1||active.selectedResult)){error(t('choose-one'));return;}
    if(!active.message&&active.operation==='background_remove')active.message=t('remove-background');
    if(!active.message)return;
    const input={conversation_id:active.id,message:active.message,operation:active.operation,images:active.selected.map(i=>({token:i.token})),selected_result:active.selectedResult,channel:cfg.channel,locale:cfg.locale,history:historyMessages()};
    const fingerprint=JSON.stringify(input);if(!active.pending||active.pending.fingerprint!==fingerprint)active.pending={fingerprint,request_id:uid()};persist();
    sending=true;updateComposer();renderSources();
    try{
        const response=await api('/editor/messages',{method:'POST',body:JSON.stringify({...input,request_id:active.pending.request_id})});
        active.turns.push({id:active.pending.request_id,message:active.message,created_at:new Date().toISOString(),...response});if(active.turns.length===1)active.title=active.message.slice(0,90);
        active.updated_at=new Date().toISOString();active.saved=active.saved||cfg.history;active.message='';active.pending=null;active.selected=[];active.selectedResult=response.workflow_ids.length===1?response.workflow_ids[0]:null;
        persist();render();scrollBottom();
    }catch(e){error(e.message);}
    finally{sending=false;updateComposer();renderSources();}
}
function newChat(){if(sending)return;persist();active=fresh();error(null);render();persist();$('message').focus();}
function pickerCount(){ $('selected-count').textContent=t('image-count').replace(':count',pickerSelection.length);document.querySelector('[data-action=use-selection]').disabled=!pickerSelection.length;}
function openPicker(){if(sending)return;pickerSelection=[...active.selected];catalogProduct=null;$('sku').value='';$('current-product').hidden=!contextProduct;$('picker-dialog').showModal();pickerCount();loadCatalog(1);}
async function loadCatalog(page){
    const epoch=++pickerEpoch;error(null,'picker-error');$('catalog-more').disabled=true;
    if(page===1)$('products').replaceChildren(el('p','fine',t('loading')));
    try{
        const params=new URLSearchParams({q:$('sku').value,page,channel:cfg.channel,locale:cfg.locale});if(catalogProduct)params.set('product_id',catalogProduct);
        const data=await api('/catalog?'+params);if(epoch!==pickerEpoch)return;if(page===1)$('products').replaceChildren();
        for(const product of data.products){const card=el('article','product-card');card.append(el('h3','',product.name),el('p','',product.sku));const images=el('div','product-images');for(const image of product.images){const choice=button('',()=>{
            const index=pickerSelection.findIndex(i=>sourceKey(i)===sourceKey(image));if(index>=0)pickerSelection.splice(index,1);else if(pickerSelection.length<5){pickerSelection.push(image);error(null,'picker-error');}else error(t('selection-limit'),'picker-error');
            choice.setAttribute('aria-pressed',String(pickerSelection.some(i=>sourceKey(i)===sourceKey(image))));pickerCount();
        },'image-choice');choice.setAttribute('aria-label',t('select')+' '+product.sku+' '+image.attribute+' '+(image.index+1));choice.setAttribute('aria-pressed',String(pickerSelection.some(i=>sourceKey(i)===sourceKey(image))));const img=el('img');img.src=image.image_url;img.alt=product.name;choice.append(img,el('span','',image.attribute+' · '+(image.index+1)));images.append(choice);}if(!product.images.length)images.append(el('p','fine',t('no-products')));card.append(images);$('products').append(card);}
        if(page===1&&!data.products.length)$('products').append(el('p','fine',t('no-products')));
        catalogPage=data.next_page;$('catalog-more').hidden=!catalogPage;
    }catch(e){if(epoch===pickerEpoch)error(e.message,'picker-error');}finally{if(epoch===pickerEpoch)$('catalog-more').disabled=false;}
}
function useSelection(){active.selected=[...pickerSelection];active.selectedResult=null;active.pending=null;persist();renderSources();updateComposer();$('picker-dialog').close();$('message').focus();}
function dateHeading(date){const value=new Date(date);return isNaN(value)?'':value.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'});}
async function openHistory(tab='chats'){
    if(sending)return;historyTab=tab;historyItems=[];historyPage=null;
    if(!$('history-dialog').open)$('history-dialog').showModal();
    document.querySelectorAll('[data-tab]').forEach(n=>{n.setAttribute('aria-selected',String(n.dataset.tab===tab));n.tabIndex=n.dataset.tab===tab?0:-1;});
    await loadHistory(1);
}
async function loadHistory(page){
    const epoch=++historyEpoch;error(null,'history-error');$('history-more').disabled=true;
    if(page===1)$('history-list').replaceChildren(el('p','empty-list',t('loading')));
    try{
        const data=await api(historyTab==='images'?'/studio/images?page='+page:'/studio/threads?page='+page+(historyTab==='bookmarks'?'&bookmarked=1':''));
        if(epoch!==historyEpoch)return;historyItems=page===1?data.items:[...historyItems,...data.items];historyPage=data.next_page;renderHistory();$('history-more').hidden=!historyPage;
    }catch(e){if(epoch===historyEpoch){if(page===1){historyItems=[];renderHistory();}error(e.message,'history-error');}}finally{if(epoch===historyEpoch)$('history-more').disabled=false;}
}
function renderHistory(){
    const list=$('history-list');list.replaceChildren();
    if(historyTab==='images'){
        if(!historyItems.length){list.append(el('p','empty-list',t('empty-images')));return;}
        const grid=el('div','image-library');for(const item of historyItems){const b=button('',()=>reviewImage(item));b.setAttribute('aria-label',t('view-result')+(item.sku?' '+item.sku:''));const img=el('img');img.src=item.image_url;img.alt=item.sku||t('image-result');img.loading='lazy';b.append(img,el('span','',item.sku||t('download-only')),el('span','fine',dateHeading(item.created_at)+(item.applied?' · '+t('applied'):'')));grid.append(b);}list.append(grid);return;
    }
    const combined=new Map(historyItems.map(item=>[item.id,{...item,saved:true}]));
    for(const thread of Object.values(threads)){if(!thread.turns?.length)continue;const saved=combined.get(thread.id);if(saved)continue;if(historyTab==='bookmarks'&&!bookmarks[thread.id])continue;if(thread.saved)continue;combined.set(thread.id,{...thread,bookmarked:!!bookmarks[thread.id],saved:false});}
    const rows=[...combined.values()].sort((a,b)=>new Date(b.updated_at)-new Date(a.updated_at));
    if(!rows.length)list.append(el('p','empty-list',t(historyTab==='bookmarks'?'empty-bookmarks':'empty-history')));
    let lastDate='';for(const thread of rows){const date=dateHeading(thread.updated_at);if(date!==lastDate){list.append(el('h3','date-heading',date));lastDate=date;}const row=el('div','thread-row');const open=button('',()=>openThread(thread),'thread-open');open.append(el('strong','',thread.title||t('conversation')),el('small','',thread.saved?t('saved-label'):t('temporary')));row.append(open);const actions=el('div','thread-actions');const mark=button(thread.bookmarked?'★':'☆',async()=>{try{if(thread.saved)await api('/studio/threads/'+thread.id,{method:'PATCH',body:JSON.stringify({bookmarked:!thread.bookmarked})});else{bookmarks[thread.id]=!thread.bookmarked;persist();}openHistory(historyTab);}catch(e){error(e.message,'history-error');}});mark.setAttribute('aria-label',t(thread.bookmarked?'unbookmark':'bookmark'));mark.title=mark.getAttribute('aria-label');const rename=button('✎',()=>editThread(thread,'rename'));rename.setAttribute('aria-label',t('rename'));rename.title=t('rename');const del=button('×',()=>editThread(thread,'delete'));del.setAttribute('aria-label',t('delete'));del.title=t('delete');actions.append(mark,rename,del);row.append(actions);list.append(row);}
}
async function openThread(thread){
    if(sending)return;error(null,'history-error');
    try{
        // Verify ownership/deletion on the server even when a tab has cached messages.
        const saved=thread.saved?await api('/studio/threads/'+thread.id):null;
        persist();const local=threads[thread.id];active=local||{...fresh(),...saved,id:thread.id,selected:[],selectedResult:null,message:'',operation:'image_to_image'};
        if(saved){const turns=new Map(saved.turns.map(turn=>[turn.id,turn]));for(const turn of local?.turns||[])if(!turns.has(turn.id))turns.set(turn.id,turn);active.turns=[...turns.values()].sort((a,b)=>new Date(a.created_at||0)-new Date(b.created_at||0));active.omitted=saved.omitted;active.saved=true;active.title=thread.title;}
        const ids=active.turns.flatMap(turn=>turn.workflow_ids||[]);if(!active.selected.length&&!active.selectedResult&&ids.length)active.selectedResult=ids.at(-1);
        persist();$('history-dialog').close();error(null);render();scrollBottom();
    }catch(e){error(e.message,'history-error');}
}
async function reviewImage(item){
    if(sending)return;
    try{
        const data=await api('/workflows/'+item.id);persist();
        // A result can always be reviewed without restoring a deleted conversation.
        active={...fresh(),id:item.conversation_id,title:item.sku||t('image-result'),turns:[{id:'result-'+item.id,message:'',workflow_ids:[item.id]}],selectedResult:item.id};
        if(threads[item.conversation_id])active=threads[item.conversation_id];
        active.selected=[];active.selectedResult=item.id;active.readonly=!item.can_refine;resultCache.set(item.id,data);$('history-dialog').close();render();persist();scrollBottom();
    }catch(e){error(e.message,'history-error');}
}
function editThread(thread,action){
    $('confirm-title').textContent=t(action==='delete'?'delete-title':'rename');$('confirm-help').textContent=action==='delete'?t('delete-help'):'';$('rename-label').hidden=$('rename-title').hidden=action==='delete';$('rename-title').value=thread.title;$('confirm-button').textContent=t(action==='delete'?'delete':'save-title');error(null,'confirm-error');
    confirmAction=async()=>{
        const title=$('rename-title').value.trim();if(action==='rename'&&!title){$('rename-title').focus();return;}
        $('confirm-button').disabled=true;
        try{
            if(thread.saved)await api('/studio/threads/'+thread.id,{method:action==='delete'?'DELETE':'PATCH',body:action==='delete'?'{}':JSON.stringify({title})});
            if(action==='delete'){delete threads[thread.id];delete bookmarks[thread.id];if(active.id===thread.id){active=fresh();render();}}else if(threads[thread.id]){threads[thread.id].title=title;if(active.id===thread.id)active.title=title;}
            persist();$('confirm-dialog').close();await openHistory(historyTab);
        }catch(e){error(e.message,'confirm-error');}finally{$('confirm-button').disabled=false;}
    };
    $('confirm-dialog').showModal();if(action==='rename')$('rename-title').focus();
}
$('confirm-button').addEventListener('click',()=>confirmAction?.());
$('send-form').addEventListener('submit',send);
$('message').addEventListener('input',()=>{active.message=$('message').value;persist();updateComposer();});
$('message').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&!$('send').disabled){e.preventDefault();$('send-form').requestSubmit();}});
$('operation').addEventListener('change',()=>{active.operation=$('operation').value;active.pending=null;persist();updateComposer();});
$('search-form').addEventListener('submit',e=>{e.preventDefault();catalogProduct=null;loadCatalog(1);});
$('catalog-more').addEventListener('click',()=>loadCatalog(catalogPage));
$('history-more').addEventListener('click',()=>loadHistory(historyPage));
$('current-product').addEventListener('click',()=>{catalogProduct=contextProduct;$('sku').value='';loadCatalog(1);});
const actions={new:newChat,history:()=>openHistory(),expand:()=>post(expanded?'collapse':'expand'),close:()=>post('close'),settings:()=>post('navigate',{url:cfg.base+'/settings'}),pick:openPicker,'use-selection':useSelection};
document.addEventListener('click',event=>{const node=event.target.closest('[data-action]');if(!node)return;const action=node.dataset.action;if(action==='close-dialog')node.closest('dialog')?.close();else actions[action]?.();});
document.querySelectorAll('[data-tab]').forEach(tab=>tab.addEventListener('click',()=>openHistory(tab.dataset.tab)));
document.querySelector('.tabs').addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;e.preventDefault();const tabs=[...document.querySelectorAll('[data-tab]')],i=tabs.indexOf(document.activeElement),next=e.key==='Home'?0:e.key==='End'?2:(i+(e.key==='ArrowRight'?1:2))%3;tabs[next].focus();openHistory(tabs[next].dataset.tab);});
window.addEventListener('message',event=>{if(event.origin!==location.origin||event.source!==parent||!event.data?.dreamlayer)return;const data=event.data;if(data.action==='context'){contextProduct=data.productId||null;expanded=!!data.expanded;document.documentElement.classList.toggle('docked',!expanded);document.documentElement.classList.toggle('dark',!!data.dark);updateComposer();if(data.refresh)refreshConfig();}if(data.action==='logout'){sessionStorage.removeItem(storageKey);threads={};active=fresh();expired=true;resultCache.clear();render();}});
window.addEventListener('beforeunload',()=>{if(!expired)persist();});
document.addEventListener('visibilitychange',()=>{if(!document.hidden){refreshConfig();poll();}});
render();post('ready');
})();
