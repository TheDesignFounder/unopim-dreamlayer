const fs=require('node:fs');const assert=require('node:assert/strict');
const root=process.env.UNOPIM_HOST_ROOT;
if (!root) throw Error('Set UNOPIM_HOST_ROOT to an isolated patched UnoPIM checkout.');
const source=fs.readFileSync(root+'/packages/Webkul/AiAgent/Resources/views/components/chat-widget.blade.php','utf8');
const range=(a,b)=>source.slice(source.indexOf('        '+a),source.indexOf('        '+b));
const methods=eval('({'+range('saveCurrentSession(persistToDb','confirmDeleteSession(sessionId)')+'})');
global.window={location:{origin:'http://localhost:8099'}};
const native={role:'assistant',message_id:'native_result',content:'Export ready',result:{rows:10},product_url:'/admin/catalog/products/edit/1',download_url:'/admin/exports/example',_confirmed:true,action:'agent_response'};
function context(remote,local=[native]){return {...methods,messages:[],activeSessionId:null,sessions:[{id:'native_chat',dbId:1,messages:local}],$axios:{get:async()=>({data:{conversation:{client_id:'native_chat',id:1,title:'Native chat'},messages:remote}})},capabilities:[],saveState(){},persistSessions(){},$nextTick(){}};}
(async()=>{
 const narrow={role:native.role,message_id:native.message_id,content:native.content,tool_calls:null,tokens_used:0};
 let c=context([narrow]);await c.switchToSession('native_chat');
 for(const key of ['result','product_url','download_url','_confirmed','action'])assert.deepEqual(c.messages[0][key],native[key]);
 console.log('PASS actual switchToSession preserves local display fields when old server records lack them');
 c=context([native],[]);await c.switchToSession('native_chat');assert.deepEqual(c.messages[0].result,{rows:10});assert.equal(c.messages[0]._confirmed,true);
 console.log('PASS actual switchToSession restores server display fields without local storage');
 const unsaved={role:'user',content:'unsaved',message_id:'unsaved1'};
 const merged=methods.mergeHistory.call(methods,[{...native,_confirmed:false}],[native,unsaved]);assert.equal(merged[0]._confirmed,true);assert.equal(merged[1].message_id,'unsaved1');
 const posts=[];c={...context([]),activeSessionId:'native_chat',messages:[native],trans:{sessionDefaultName:'Chat'},$axios:{post:async(url,payload)=>{posts.push(payload);return {data:{id:1}};}}};
 await c.saveCurrentSession();for(const key of ['result','product_url','download_url','_confirmed','action'])assert.deepEqual(posts[0].messages[0][key],native[key]);
 console.log('PASS actual saveCurrentSession sends native display fields; confirmation merge is monotonic and retains unsaved messages');
 for(const unsafe of ['javascript:alert(1)','//evil.invalid','/%5cevil.invalid','https://user:pass@example.invalid'])assert.equal(methods.historyLink(unsafe),null);
 console.log('PASS local history cannot restore unsafe links');
})().catch(e=>{console.error(e);process.exit(1)});
