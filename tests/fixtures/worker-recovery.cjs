// Isolated fixture only: kills/restarts the named test worker, never the demo.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'@playwright/test');
const {execFileSync,spawn}=require('node:child_process');const {randomUUID}=require('node:crypto');const assert=require('node:assert/strict');
const docker=(...args)=>execFileSync('docker',args,{encoding:'utf8'}).trim();
const wait=ms=>new Promise(r=>setTimeout(r,ms));
let worker,browser;
const start=()=>{worker=spawn('docker',['exec','-e','DREAMLAYER_FIXTURE_HOST=1','dreamlayer-extension-php','sh','-c','echo $$ > /tmp/dreamlayer-review-worker.pid; exec php artisan queue:work dreamlayer --queue=dreamlayer --sleep=1 --timeout=110 --tries=0 --max-time=180'],{stdio:'ignore'});};
const stop=()=>{docker('exec','dreamlayer-extension-php','sh','-c','kill -9 $(cat /tmp/dreamlayer-review-worker.pid)');};
(async()=>{
 browser=await chromium.launch({executablePath:process.env.CHROME_PATH,headless:true});const ctx=await browser.newContext();const page=await ctx.newPage();await page.goto('http://localhost:8099/__fixture/login');
 const token=await page.locator('input[name="_token"]').first().getAttribute('value');
 start();
 for(const mode of ['workflow_v1','execute_v1']){
  docker('exec','dreamlayer-extension-php','sh','-c',mode==='execute_v1'?'touch storage/app/fixture-legacy-mode':'rm -f storage/app/fixture-legacy-mode');
  const response=await ctx.request.post('http://localhost:8099/admin/ai-agent/chat',{headers:{'X-CSRF-TOKEN':token,Accept:'application/json'},data:{message:'Generate restart fixture',request_id:randomUUID(),conversation_id:'recovery_'+randomUUID().replaceAll('-','')}});
  assert.equal(response.status(),200);const result=await response.json();const id=result.presentation.id;assert.match(id,/^[a-f0-9-]{36}$/);
  let record;
  for(let i=0;i<80;i++){record=docker('exec','dreamlayer-extension-pg','psql','-U','extension','-d','unopim_extension_test','-Atc',"select status || '|' || execution_path || '|' || coalesce(upstream_workflow_id::text,upstream_execution_id::text,'') from dreamlayer_workflows where id='"+id+"'");if(record.split('|')[2])break;await wait(250);}
  const [status,path,upstream]=record.split('|');assert.equal(status,'running');assert.equal(path,mode);assert.ok(upstream);
  stop();console.log('Killed test worker after durable admission',mode,id,upstream);await wait(500);start();
  let state;
  for(let i=0;i<110;i++){state=await (await ctx.request.get('http://localhost:8099/admin/dreamlayer/workflows/'+id)).json();if(state.status==='completed')break;await wait(2000);}
  assert.equal(state.status,'completed');assert.equal(state.detailed_progress,mode==='workflow_v1');assert.equal((await ctx.request.get(state.image_url)).status(),200);
  const after=docker('exec','dreamlayer-extension-pg','psql','-U','extension','-d','unopim_extension_test','-Atc',"select coalesce(upstream_workflow_id::text,upstream_execution_id::text) from dreamlayer_workflows where id='"+id+"'");assert.equal(after,upstream);
  console.log('PASS same upstream identity, accessible result after worker SIGKILL/restart',mode);
 }
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();try{stop();}catch{}docker('exec','dreamlayer-extension-php','rm','-f','storage/app/fixture-legacy-mode');});
