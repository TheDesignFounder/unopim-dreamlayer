(() => {
'use strict';
let cfg=window.DreamLayerConfig, labels=cfg.labels, storageKey='dreamlayer-studio:'+cfg.sessionKey;
const $=id=>document.getElementById(id), t=key=>labels[key]||key;
const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
const button=(text,action,cls='outline')=>{const n=el('button',cls,text);n.type='button';n.addEventListener('click',action);return n;};
const uid=()=>crypto.randomUUID(), fresh=()=>({id:uid(),title:t('new-chat'),turns:[],selected:[],referenceIds:{},nextReference:1,selectedResult:null,message:'',operation:'image_to_image',upload:null,retryResult:null,updated_at:new Date().toISOString(),saved:false});
let threads={},active=fresh(),sending=false,expired=false,contextProduct=null,expanded=false,bookmarks={},polling=false,historyTab='chats',historyPage=null,historyItems=[],historyEpoch=0,pickerEpoch=0,pickerSelection=[],catalogPage=null,catalogProduct=null,confirmAction=null,uploading=false,followLatest=true;
const resultCache=new Map(), resultNodes=new Map();
let pickerInsertion=null, insertingMention=false;
const referencePattern=()=>/(?<![\p{L}\p{N}_])@(Asset-[1-9][0-9]*)(?![\p{L}\p{N}_-])/gu;
function assignReferences(images){
    active.referenceIds??={};
    if(active.referenceTurns!==active.turns.length){active.nextReference=Math.max(active.nextReference||1,1,...active.turns.flatMap(turn=>mentionedReferences(turn.message||'').map(ref=>Number(ref.slice(6))+1)));active.referenceTurns=active.turns.length;}
    let next=Math.max(active.nextReference||1,1,...Object.values(active.referenceIds).map(ref=>Number(ref.slice(6))+1));
    for(const image of images){const key=sourceKey(image);active.referenceIds[key]??='Asset-'+next++;image.reference=active.referenceIds[key];}
    active.nextReference=next;return images;
}
function mentionedReferences(text=$('message').value){return [...text.matchAll(referencePattern())].map(match=>match[1]);}
function knownReferences(){return new Set([...active.selected.map(image=>image.reference),...(active.retryResult?active.retryReferences||[]:[])]);}
function invalidReference(text=$('message').value){return [...text.matchAll(/(?<![\p{L}\p{N}_])@(Asset-[\p{L}\p{N}_-]*)/gu)].some(match=>!knownReferences().has(match[1]));}
function highlightReferences(node,text,known=null){
    node.replaceChildren();let end=0;
    for(const match of text.matchAll(referencePattern())){node.append(document.createTextNode(text.slice(end,match.index)));node.append(el('span',known&&!known.has(match[1])?'image-reference missing-reference':'image-reference',match[0]));end=match.index+match[0].length;}
    node.append(document.createTextNode(text.slice(end)));
}
function renderMentionHighlight(){
    assignReferences(active.selected);
    highlightReferences($('message-highlight'),$('message').value,knownReferences());
    $('message-highlight').append(document.createTextNode('\u200b'));
    $('message-highlight').style.width=$('message').clientWidth?$('message').clientWidth+'px':'';
    $('message-highlight').scrollTop=$('message').scrollTop;
}
function insertReferences(images,range=null){
    const input=$('message'),start=range?.start??input.selectionStart,end=range?.end??input.selectionEnd;
    const before=input.value.slice(0,start),after=input.value.slice(end);
    const text=(before&&!/\s$/.test(before)?' ':'')+images.map(image=>'@'+image.reference).join(' ')+(!after||!/^\s/.test(after)?' ':'');
    if(input.value.length-(end-start)+text.length>input.maxLength){input.focus();return;}
    input.focus();input.setSelectionRange(start,end);
    if(!document.execCommand('insertText',false,text))input.setRangeText(text,start,end,'end');
    active.message=input.value;active.pending=null;
    persist();autoResize();updateComposer();input.focus();
}
function removeReferences(images){
    const removed=new Set(images.map(image=>image.reference).filter(Boolean));
    const input=$('message');let start=input.selectionStart,end=input.selectionEnd;
    const original=input.value;
    input.value=original.replace(referencePattern(),(text,ref,offset)=>{
        if(!removed.has(ref))return text;
        start-=Math.min(text.length,Math.max(0,input.selectionStart-offset));
        end-=Math.min(text.length,Math.max(0,input.selectionEnd-offset));return '';
    });
    input.setSelectionRange(Math.max(0,start),Math.max(0,end));active.message=input.value;
}
function clearCatalogSelection(){removeReferences([...active.selected,...(active.retryReferences||[]).map(reference=>({reference}))]);active.selected=[];active.retryReferences=[];}
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
async function api(path,options={},failureKey='request-error'){
    let response;try{response=await fetch(cfg.base+path,{credentials:'same-origin',cache:'no-store',...options,headers:{Accept:'application/json',...(options.body instanceof FormData?{}:{'Content-Type':'application/json'}),'X-CSRF-TOKEN':cfg.csrf,...options.headers}});}catch(e){throw Error(t(failureKey));}
    if([401,419].includes(response.status)||response.redirected&&!response.url.startsWith(cfg.base+'/')){
        expired=true;sessionStorage.removeItem(storageKey);threads={};active=fresh();resultCache.clear();render();post('expired');throw Error(t('session-ended'));
    }
    let data;try{data=await response.json();}catch(e){throw Error(t('load-error'));}
    if(!response.ok){const message=data.message||data.description||Object.values(data.errors||{}).flat().find(v=>typeof v==='string')||data.error;const failure=Error(typeof message==='string'&&message?message:t(failureKey));failure.status=response.status;throw failure;}
    return data;
}
async function refreshConfig(){
    try{
        const data=await api('/studio/config');
        if(data.sessionKey!==cfg.sessionKey){sessionStorage.removeItem(storageKey);expired=true;threads={};active=fresh();resultCache.clear();render();post('expired');return;}
        cfg=data;labels=data.labels;updateComposer();
    }catch(e){error(e.message);}
}
function historyMessages(){return active.turns.flatMap(turn=>[{role:'user',content:turn.message},...(turn.reply?[{role:'assistant',content:turn.reply}]:[])]).filter(m=>m.content).slice(-20);}
function updateComposer(){
    renderMentionHighlight();
    const blocked=expired||!cfg.connected;
    $('connection').hidden=!blocked;$('connection').classList.toggle('reviewing',blocked&&active.turns.length>0);
    if(blocked){$('connection').replaceChildren();const box=el('div','connection-message');box.append(el('h2','',expired?t('session-ended'):cfg.linked?t('setting-enabled'):t('connect')),el('p','',cfg.linked?t('setting-enabled-help'):t('connection-help')));if(cfg.permissions.settings)box.append(button(t('settings-link'),()=>post('navigate',{url:cfg.base+'/settings'}),'primary'));$('connection').append(box);}
    document.querySelectorAll('[data-action=settings]').forEach(n=>n.hidden=!cfg.permissions.settings);
    document.querySelector('[data-action=upload]').hidden=!cfg.permissions.upload;
    $('composer').hidden=blocked;$('conversation').hidden=expired||(blocked&&!active.turns.length);
    $('message').disabled=sending||uploading||active.readonly;$('operation').disabled=sending||active.readonly;
    const sourceReady=!!active.upload||!!active.retryResult||active.selected.length>0||(active.selectedResult&&resultCache.get(active.selectedResult)?.status==='completed');
    const hasMessage=!!$('message').value.trim()||active.operation==='background_remove';
    const unbound=invalidReference();
    error(unbound?t('message-268f960b4a'):null,'mention-error');
    $('send').disabled=sending||uploading||blocked||active.readonly||!hasMessage||unbound||(active.operation!=='text_to_image'&&!sourceReady);
    $('send').textContent=uploading?t('uploading'):sending?t('sending'):t('create-preview')+' ↑';
    $('task-help').textContent=active.readonly?t('read-only-result'):active.operation==='text_to_image'?t('generate-help'):active.operation==='background_remove'?t('remove-help'):'';
    $('task-help').hidden=!$('task-help').textContent;
    document.querySelector('[data-action=new]').disabled=sending||uploading;
    document.querySelector('[data-action=upload]').disabled=sending||uploading||active.readonly;
    document.querySelector('[data-action=pick]').disabled=sending||uploading||active.readonly;
    document.querySelectorAll('[data-refine], [data-retry]').forEach(node=>{node.disabled=sending||uploading||active.readonly||blocked;});
    document.querySelector('[data-action=expand]').setAttribute('aria-label',expanded?t('collapse'):t('expand'));
    document.querySelector('[data-action=expand]').title=expanded?t('collapse'):t('expand');
}
function sourceKey(image){return image.image_url;}
function renderSources(){
    assignReferences(active.selected);
    $('sources').replaceChildren();
    const sources=active.selected.map(image=>({image,label:'@'+image.reference+' · '+image.sku,insert:()=>insertReferences([image]),remove:()=>{removeReferences([image]);active.selected=active.selected.filter(i=>sourceKey(i)!==sourceKey(image));persist();renderSources();updateComposer();autoResize();}}));
    if(active.selectedResult){const result=resultCache.get(active.selectedResult);sources.push({image:{image_url:result?.image_url},label:t('source-result')+(result?.target?.sku?' · '+result.target.sku:''),remove:()=>{active.selectedResult=null;persist();renderSources();updateComposer();}});}
    if(active.upload)sources.push({image:active.upload,label:active.upload.name,remove:()=>{active.upload=null;persist();renderSources();updateComposer();}});
    if(active.retryResult)sources.push({image:{},label:t(active.operation==='text_to_image'?'generate':'original-source'),remove:()=>{removeReferences((active.retryReferences||[]).map(reference=>({reference})));active.retryResult=null;active.retryReferences=[];persist();renderSources();updateComposer();autoResize();}});
    for(const source of sources){const chip=el('div','source-chip');if(source.image.image_url){const img=el('img');img.src=source.image.image_url;img.alt='';chip.append(img);}const label=source.insert?button(source.label,source.insert,'source-reference'):el('span','',source.label);if(source.insert){label.title=source.image.name+' · '+source.image.attribute+' · '+(source.image.index+1);label.disabled=sending||uploading;}chip.append(label);const remove=button('×',source.remove,'');remove.setAttribute('aria-label',t('clear-source'));remove.disabled=sending;chip.append(remove);$('sources').append(chip);}
}
function nearBottom(){const n=$('conversation');return n.scrollHeight-n.scrollTop-n.clientHeight<100;}
function scrollBottom(){followLatest=true;requestAnimationFrame(()=>{$('conversation').scrollTo({top:$('conversation').scrollHeight,behavior:'instant'});$('jump-latest').hidden=true;});}
function autoResize(){const n=$('message');n.style.height='auto';n.style.height=Math.min(n.scrollHeight,160)+'px';renderMentionHighlight();}
function showImage(url){$('full-image').src=url;$('image-dialog').showModal();}
async function copyText(text,control){try{await navigator.clipboard.writeText(text);control.textContent=t('copied');setTimeout(()=>control.textContent=t('copy'),1600);}catch(e){error(t('copy-error'));}}
function reuseResult(id,data){if(sending||uploading||active.readonly||expired||!cfg.connected)return;clearCatalogSelection();active.selectedResult=null;active.upload=null;active.retryResult=id;active.operation=data.retry.operation;active.message=data.retry.instruction||t('remove-background');active.retryReferences=mentionedReferences(active.message);active.pending=null;$('message').value=active.message;$('operation').value=active.operation;persist();renderSources();updateComposer();autoResize();$('message').focus();}
async function uploadImage(files){
    if(sending||uploading||active.readonly||expired||!cfg.connected||!cfg.permissions.upload||!files?.length)return;
    if(files.length!==1){error(t('upload-one'));return;}
    const file=files[0];if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>cfg.maxUploadBytes){error(t('upload-invalid'));return;}
    const thread=active;uploading=true;error(null);updateComposer();
    try{const form=new FormData();form.append('conversation_id',thread.id);form.append('image',file);const source=await api('/studio/uploads',{method:'POST',body:form},'upload-error');clearCatalogSelection();thread.upload=source;thread.selected=[];thread.selectedResult=null;thread.retryResult=null;thread.operation='image_to_image';thread.pending=null;persist();$('operation').value=thread.operation;renderSources();}
    catch(e){error(e.message);}finally{uploading=false;$('upload-file').value='';updateComposer();}
}
function openTasks(){if(sending||uploading||expired||!cfg.connected)return;const list=$('task-list');list.replaceChildren();for(const [key,operation] of [['edit','image_to_image'],['remove-background','background_remove'],['generate','text_to_image']])list.append(button(t(key),()=>{active.operation=operation;$('operation').value=operation;$('tasks-dialog').close();if(operation==='text_to_image'){clearCatalogSelection();active.selectedResult=null;active.upload=null;active.retryResult=null;}if(operation==='background_remove'){$('message').value='';active.message='';}persist();renderSources();updateComposer();if(operation!=='text_to_image'&&!active.selected.length&&!active.selectedResult&&!active.upload&&!active.retryResult)openPicker();else $('message').focus();}));list.append(button(t('add-images'),()=>{$('tasks-dialog').close();openPicker();}));if(cfg.permissions.upload)list.append(button(t('upload-image'),()=>{$('tasks-dialog').close();$('upload-file').click();}));$('tasks-dialog').showModal();}
$('conversation').addEventListener('scroll',()=>{followLatest=nearBottom();$('jump-latest').hidden=followLatest;});
$('jump-latest').addEventListener('click',scrollBottom);
new ResizeObserver(()=>{if(followLatest)scrollBottom();}).observe($('messages'));
new ResizeObserver(renderMentionHighlight).observe(document.querySelector('.message-editor'));

function render(){
    resultNodes.clear();$('messages').replaceChildren();
    $('message').value=active.message||'';$('operation').value=active.operation||'image_to_image';
    if(!active.turns.length){const welcome=el('div','welcome');welcome.append(el('h1','',t('welcome-title')),el('p','',t('welcome-copy')));const suggestions=el('div','suggestions');suggestions.append(button(t('add-images'),openPicker),button(t('try-blue'),()=>preset('prompt-blue')),button(t('try-scene'),()=>preset('prompt-scene')));welcome.append(suggestions);$('messages').append(welcome);}
    if(active.omitted)$('messages').append(el('p','fine',t('unsaved-gap')));
    for(const turn of active.turns){const row=el('section','turn');if(turn.message){const message=el('div','user-message');highlightReferences(message,turn.message);row.append(message);const tools=el('div','message-actions');const copy=button(t('copy'),()=>copyText(turn.message,copy),'text-button');tools.append(copy);row.append(tools);}for(const id of turn.workflow_ids||[]){const card=el('article','result');card.dataset.workflow=id;card.setAttribute('aria-label',t('image-result'));row.append(card);resultNodes.set(id,card);renderResult(id,resultCache.get(id));}$('messages').append(row);}
    renderSources();updateComposer();autoResize();poll();
}
function preset(key){active.message=t(key);active.operation='image_to_image';$('message').value=active.message;$('operation').value=active.operation;persist();updateComposer();if(!active.selected.length&&!active.selectedResult)openPicker();else $('message').focus();}
function renderResult(id,data){
    const card=resultNodes.get(id);if(!card)return;
    const signature=JSON.stringify(data||null);if(card.dataset.signature===signature)return;card.dataset.signature=signature;card.replaceChildren();
    const wasFollowing=followLatest;
    const status=data?.status||'queued';card.classList.toggle('processing',['queued','running'].includes(status));
    const statusLine=el('div','result-status');statusLine.append(el('span','status-dot'),el('span','',data?.loadError?t('progress-error'):data?.delayed?t('waiting'):t('status-'+status)));card.append(statusLine);
    if(data?.image_url){const img=el('img','result-image');img.src=data.catalog_image_url||data.image_url;img.alt=data.target?.sku?t('image-result')+' · '+data.target.sku:t('image-result');img.tabIndex=0;img.setAttribute('role','button');img.setAttribute('aria-label',t('view-image'));img.addEventListener('click',()=>showImage(data.catalog_image_url||data.image_url));img.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();showImage(data.catalog_image_url||data.image_url);}});img.addEventListener('load',()=>{if(followLatest)scrollBottom();});card.append(img);}
    if(data?.catalog_notice)card.append(el('p','fine catalog-notice',data.catalog_notice));
    if(data?.error)card.append(el('p','error',data.error));
    if(data?.loadError)card.append(button(t('retry'),()=>{resultCache.delete(id);poll();},'outline'));
    if(data?.billing_notice)card.append(el('p','fine',data.billing_notice));
    if(data?.billing_url){const billing=el('a','',t('manage-billing'));billing.href=data.billing_url;billing.target='_blank';billing.rel='noopener noreferrer';card.append(billing);}
    if(data?.image_url){
        const target=data.target, desc=el('p','result-description');desc.append(el('strong','result-label',target?target.sku+' · '+target.attribute+' · '+(Number(target.index)+1):t('download-only')));if(target)desc.append(el('br'),document.createTextNode(target.channel+' / '+target.locale));card.append(desc);
        const actions=el('div','result-actions');
        const refine=button(t('refine-this'),()=>{if(sending||uploading||active.readonly||expired||!cfg.connected)return;clearCatalogSelection();active.upload=null;active.retryResult=null;active.selectedResult=id;active.operation='image_to_image';$('operation').value=active.operation;persist();renderSources();updateComposer();$('message').focus();});refine.dataset.refine=id;refine.disabled=sending||active.readonly;actions.append(refine);
        const dl=el('a','',t('download-png'));dl.textContent=t('download');if(labels['download-png'])dl.textContent=labels['download-png'];dl.href=data.image_url+'?download=1';dl.setAttribute('download','dreamlayer-'+id+'.png');if(data.catalog_image_url)dl.textContent=t('download-original');actions.append(dl);
        if(target&&cfg.permissions.apply){const apply=button(data.applied?t('applied'):t('apply'),async()=>{
            apply.disabled=true;error(null);
            try{const next=await api('/workflows/'+id+'/apply',{method:'POST',body:JSON.stringify({catalog_token:data.catalog_token})},'apply-error');resultCache.set(id,next);renderResult(id,next);}
            catch(e){const notice=card.querySelector('.apply-error')||el('p','error apply-error');notice.setAttribute('role','alert');notice.textContent=e.message;card.append(notice);apply.disabled=false;}
        },data.applied?'applied':'');apply.disabled=data.applied;actions.append(apply);}
        card.append(actions);
        const feedback=el('div','message-actions');feedback.hidden=!cfg.permissions.feedback;for(const [value,label]of[['helpful','helpful'],['not_helpful','not-helpful']]){const vote=button(t(label),async()=>{vote.disabled=true;try{const next=await api('/workflows/'+id+'/feedback',{method:'PATCH',body:JSON.stringify({feedback:data.feedback===value?null:value})});resultCache.set(id,next);renderResult(id,next);}catch(e){error(e.message);vote.disabled=false;}},'text-button');vote.setAttribute('aria-pressed',String(data.feedback===value));feedback.append(vote);}card.append(feedback);
        if(target&&!data.applied)card.append(el('p','fine result-description',t('approval-help')));
    }
    if(data?.retry&&!active.readonly){const again=button(t('try-again'),()=>reuseResult(id,data),'text-button');again.dataset.retry=id;again.disabled=sending;again.title=t('retry-help');card.append(again);}
    if(data?.created_at){const time=el('p','fine elapsed');time.dataset.started=data.created_at;time.dataset.finished=['completed','failed'].includes(status)?data.updated_at:'';card.append(time);updateTimes();}
    if(data?.references){const details=el('details');details.append(el('summary','',t('request-details')));const list=el('dl');for(const [key,value] of Object.entries(data.references)){if(value)list.append(el('dt','',t(key)),el('dd','',String(value)));}details.append(list);card.append(details);}
    if(wasFollowing&&followLatest&&data?.image_url)scrollBottom();
}
async function poll(){
    if(polling||expired||document.hidden)return;polling=true;
    try{const ids=active.turns.flatMap(turn=>turn.workflow_ids||[]).filter(id=>!resultCache.has(id)||['queued','running'].includes(resultCache.get(id).status));await Promise.all(ids.map(async id=>{try{const data=await api('/workflows/'+id);resultCache.set(id,data);renderResult(id,data);}catch(e){const data={status:'failed',error:e.message,loadError:true};resultCache.set(id,data);renderResult(id,data);}}));renderSources();updateComposer();}
    finally{polling=false;}
}
setInterval(poll,2500);
setInterval(()=>{if(!document.hidden&&!expired)refreshConfig();},15000);
function updateTimes(){document.querySelectorAll('.elapsed').forEach(n=>{const seconds=Math.max(0,Math.floor(((n.dataset.finished?new Date(n.dataset.finished):Date.now())-new Date(n.dataset.started))/1000));n.textContent=t(n.dataset.finished?'completed-in':'elapsed').replace(':time',Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0'));});}
setInterval(updateTimes,1000);
async function send(event){
    event.preventDefault();if(sending||uploading||expired||!cfg.connected||active.readonly)return;error(null);
    active.message=$('message').value.trim();active.operation=$('operation').value;
    if(active.operation!=='text_to_image'&&!active.selected.length&&!active.selectedResult&&!active.upload&&!active.retryResult){error(t('source-required'));return;}
    const references=mentionedReferences(active.message),selectedCount=references.length?active.selected.filter(image=>references.includes(image.reference)).length:active.selected.length;
    if(active.operation==='text_to_image'&&(selectedCount>1||active.selectedResult||active.upload)){error(t('choose-one'));return;}
    if(!active.message&&active.operation==='background_remove')active.message=t('remove-background');
    if(!active.message)return;
    if(invalidReference(active.message)){error(t('message-268f960b4a'));return;}
    const input={conversation_id:active.id,message:active.message,operation:active.operation,images:active.selected.map(i=>({token:i.token,reference:i.reference})),selected_result:active.selectedResult,upload_token:active.upload?.token||null,retry_result:active.retryResult||null,channel:cfg.channel,locale:cfg.locale,history:historyMessages()};
    const fingerprint=JSON.stringify(input);if(!active.pending||active.pending.fingerprint!==fingerprint)active.pending={fingerprint,request_id:uid()};persist();
    sending=true;updateComposer();renderSources();
    try{
        const response=await api('/editor/messages',{method:'POST',body:JSON.stringify({...input,request_id:active.pending.request_id})});
        active.turns.push({id:active.pending.request_id,message:active.message,created_at:new Date().toISOString(),...response});if(active.turns.length===1)active.title=active.message.slice(0,90);
        active.updated_at=new Date().toISOString();active.saved=active.saved||cfg.history;active.message='';active.retryReferences=[];active.pending=null;active.selected=[];active.upload=null;active.retryResult=null;active.selectedResult=response.workflow_ids.length===1?response.workflow_ids[0]:null;active.operation='image_to_image';
        persist();render();scrollBottom();
    }catch(e){error(e.message);}
    finally{sending=false;updateComposer();renderSources();}
}
function newChat(){if(sending||uploading)return;followLatest=true;persist();active=fresh();error(null);render();persist();$('message').focus();}
function pickerCount(){ $('selected-count').textContent=t('image-count').replace(':count',pickerSelection.length);document.querySelector('[data-action=use-selection]').disabled=!pickerSelection.length;}
function openPicker(range=null){
    if(sending||uploading||expired||!cfg.connected)return;
    insertingMention=!!range?.mention;pickerInsertion=range?.mention?range:{start:$('message').selectionStart,end:$('message').selectionEnd};
    assignReferences(active.selected);pickerSelection=insertingMention?[]:[...active.selected];catalogProduct=null;
    $('mention-options').replaceChildren();
    if(insertingMention)for(const image of active.selected){const choose=button('@'+image.reference+' · '+image.sku,()=>{$('picker-dialog').close();insertReferences([image],pickerInsertion);},'outline');$('mention-options').append(choose);}
    $('sku').value='';$('current-product').hidden=!contextProduct;$('picker-dialog').showModal();pickerCount();loadCatalog(1);$('sku').focus();
}
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
function useSelection(){
    const picked=assignReferences([...pickerSelection]);
    const merged=insertingMention?[...new Map([...active.selected,...picked].map(image=>[sourceKey(image),image])).values()]:picked;
    if(merged.length>5){error(t('selection-limit'),'picker-error');return;}
    let range=pickerInsertion;
    if(active.retryResult){removeReferences((active.retryReferences||[]).map(reference=>({reference})));range={start:$('message').selectionStart,end:$('message').selectionEnd};active.retryReferences=[];}
    if(!insertingMention){const removed=active.selected.filter(image=>!merged.some(next=>sourceKey(next)===sourceKey(image)));if(removed.length){removeReferences(removed);range={start:$('message').selectionStart,end:$('message').selectionEnd};}}
    active.upload=null;active.retryResult=null;active.selected=merged;active.selectedResult=null;active.pending=null;
    const insert=insertingMention?picked:picked.filter(image=>!mentionedReferences().includes(image.reference));
    $('picker-dialog').close();if(insert.length)insertReferences(insert,range);persist();renderSources();updateComposer();$('message').focus();
}
function dateHeading(date){const value=new Date(date);return isNaN(value)?'':value.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'});}
async function openHistory(tab='chats'){
    if(sending||uploading)return;historyTab=tab;historyItems=[];historyPage=null;
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
    for(const thread of Object.values(threads)){if(!thread.turns?.length||thread.readonly)continue;const saved=combined.get(thread.id);if(saved)continue;if(historyTab==='bookmarks'&&!bookmarks[thread.id])continue;if(thread.saved)continue;combined.set(thread.id,{...thread,bookmarked:!!bookmarks[thread.id],saved:false});}
    const rows=[...combined.values()].sort((a,b)=>new Date(b.updated_at)-new Date(a.updated_at));
    if(!rows.length)list.append(el('p','empty-list',t(historyTab==='bookmarks'?'empty-bookmarks':'empty-history')));
    let lastDate='';for(const thread of rows){const date=dateHeading(thread.updated_at);if(date!==lastDate){list.append(el('h3','date-heading',date));lastDate=date;}const row=el('div','thread-row');const open=button('',()=>openThread(thread),'thread-open');open.append(el('strong','',thread.title||t('conversation')),el('small','',thread.saved?t('saved-label'):t('temporary')));row.append(open);const actions=el('div','thread-actions');const mark=button(thread.bookmarked?'★':'☆',async()=>{try{if(thread.saved)await api('/studio/threads/'+thread.id,{method:'PATCH',body:JSON.stringify({bookmarked:!thread.bookmarked})});else{bookmarks[thread.id]=!thread.bookmarked;persist();}openHistory(historyTab);}catch(e){error(e.message,'history-error');}});mark.setAttribute('aria-label',t(thread.bookmarked?'unbookmark':'bookmark'));mark.title=mark.getAttribute('aria-label');const rename=button('✎',()=>editThread(thread,'rename'));rename.setAttribute('aria-label',t('rename'));rename.title=t('rename');const del=button('×',()=>editThread(thread,'delete'));del.setAttribute('aria-label',t('delete'));del.title=t('delete');actions.append(mark,rename,del);row.append(actions);list.append(row);}
}
async function openThread(thread){
    if(sending||uploading)return;error(null,'history-error');
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
    if(sending||uploading)return;
    try{
        const data=await api('/workflows/'+item.id);persist();
        // A result can always be reviewed without restoring a deleted conversation.
        active={...fresh(),id:item.conversation_id,title:item.sku||t('image-result'),turns:[{id:'result-'+item.id,message:'',workflow_ids:[item.id]}],selectedResult:item.id};
        if(threads[item.conversation_id])active=threads[item.conversation_id];
        $('message').value=active.message||'';clearCatalogSelection();active.upload=null;active.retryResult=null;active.selectedResult=item.id;active.readonly=!item.can_refine;resultCache.set(item.id,data);$('history-dialog').close();render();persist();scrollBottom();
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
$('message').addEventListener('input',event=>{autoResize();active.message=$('message').value;persist();updateComposer();const end=$('message').selectionStart;if(event.data==='@'&&!event.isComposing&&(end===1||!/[\p{L}\p{N}_]/u.test($('message').value[end-2]))){openPicker({start:end-1,end,mention:true});}});
$('message').addEventListener('scroll',()=>{$('message-highlight').scrollTop=$('message').scrollTop;});
$('message').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&!$('send').disabled){e.preventDefault();$('send-form').requestSubmit();}});
$('upload-file').addEventListener('change',e=>uploadImage(e.target.files));
$('message').addEventListener('paste',e=>{if(e.clipboardData?.files?.length){e.preventDefault();uploadImage(e.clipboardData.files);}});
$('studio').addEventListener('dragover',e=>{if(e.dataTransfer.types.includes('Files')){e.preventDefault();$('send-form').classList.add('dragging');}});
$('studio').addEventListener('dragleave',()=>{$('send-form').classList.remove('dragging');});
$('studio').addEventListener('drop',e=>{if(e.dataTransfer.files?.length){e.preventDefault();$('send-form').classList.remove('dragging');uploadImage(e.dataTransfer.files);}});
$('operation').addEventListener('change',()=>{active.operation=$('operation').value;active.pending=null;persist();updateComposer();});
$('search-form').addEventListener('submit',e=>{e.preventDefault();catalogProduct=null;loadCatalog(1);});
$('catalog-more').addEventListener('click',()=>loadCatalog(catalogPage));
$('history-more').addEventListener('click',()=>loadHistory(historyPage));
$('current-product').addEventListener('click',()=>{catalogProduct=contextProduct;$('sku').value='';loadCatalog(1);});
const actions={new:newChat,history:()=>openHistory(),expand:()=>post(expanded?'collapse':'expand'),close:()=>post('close'),upload:()=>$('upload-file').click(),tasks:openTasks,settings:()=>post('navigate',{url:cfg.base+'/settings'}),pick:openPicker,'use-selection':useSelection};
document.addEventListener('click',event=>{const node=event.target.closest('[data-action]');if(!node)return;const action=node.dataset.action;if(action==='close-dialog')node.closest('dialog')?.close();else actions[action]?.();});
document.querySelectorAll('[data-tab]').forEach(tab=>tab.addEventListener('click',()=>openHistory(tab.dataset.tab)));
document.querySelector('.tabs').addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;e.preventDefault();const tabs=[...document.querySelectorAll('[data-tab]')],i=tabs.indexOf(document.activeElement),next=e.key==='Home'?0:e.key==='End'?2:(i+(e.key==='ArrowRight'?1:2))%3;tabs[next].focus();openHistory(tabs[next].dataset.tab);});
window.addEventListener('message',event=>{if(event.origin!==location.origin||event.source!==parent||!event.data?.dreamlayer)return;const data=event.data;if(data.action==='context'){contextProduct=data.productId||null;expanded=!!data.expanded;document.documentElement.classList.toggle('docked',!expanded);document.documentElement.classList.toggle('dark',!!data.dark);updateComposer();if(data.refresh)refreshConfig();}if(data.action==='logout'){sessionStorage.removeItem(storageKey);threads={};active=fresh();expired=true;resultCache.clear();render();}});
window.addEventListener('beforeunload',()=>{if(!expired)persist();});
document.addEventListener('visibilitychange',()=>{if(!document.hidden){refreshConfig();poll();}});
render();post('ready');
})();
