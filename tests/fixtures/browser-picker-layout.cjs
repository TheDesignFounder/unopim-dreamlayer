// Exercise the actual picker and core composer methods with UnoPIM's Vue bundle.
// Catalog images are synthetic; every request is fulfilled locally. No account or AI calls.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium, expect} = require(process.env.PLAYWRIGHT_MODULE || '@playwright/test');
const host = process.env.UNOPIM_HOST_ROOT;
if (!host) throw Error('Set UNOPIM_HOST_ROOT to the patched test host.');
const {compileModules} = require(path.join(host, 'packages/Webkul/AiAgent/tests/JavaScript/widget-harness.cjs'));
const picker = compileModules(process.env.PICKER_TEST_VIEW || path.resolve(__dirname, '../../resources/views/picker.blade.php'), {
    "@json(route('dreamlayer.catalog.options'))": '/options',
    "@json(route('dreamlayer.catalog'))": 'https://unopim.test/catalog',
});
const core = compileModules(process.env.UNOPIM_TEST_WIDGET_FILE || path.join(host, 'packages/Webkul/AiAgent/Resources/views/components/chat-widget.blade.php'));
const assets = path.join(host, 'public/themes/admin/default/build/assets');
const bundle = fs.readdirSync(assets).find(name => /^app-.*\.js$/.test(name));
const templates = [...picker.source.matchAll(/<script type="text\/x-template"[\s\S]*?<\/script>/g)].map(m => m[0].replace(/\{\{--[\s\S]*?--\}\}/g, '').replaceAll('@{{', '{{')).join('\n');
const styles = [...picker.source.matchAll(/<style>[\s\S]*?<\/style>/g)].map(m => m[0]).join('\n');
const html = `<html lang="en"><head><meta charset="UTF-8">${styles}<style>body{font:14px sans-serif}button{font:inherit;border:0;background:none;box-sizing:border-box}#fixture{width:400px;position:absolute;top:450px}textarea{width:100%}</style></head><body>
<div id="app"><v-picker-fixture></v-picker-fixture></div>${templates}
<script type="module">import '/assets/${bundle}';
${core.javascript}
${picker.javascript}
const methods = app.component('v-agenting-pim').methods;
app.component('v-picker-fixture', {
 template: '<div id="fixture"><v-dreamlayer-picker ref="picker" :value="selection" conversation-id="fixture" :draft="inputText" :cursor="composerCursor" :disabled="false" @replace-draft="replaceComposerDraft" @change="selection=$event"></v-dreamlayer-picker><textarea ref="textInput" v-model="inputText" @input="composerSelection" @keydown="onKey"></textarea></div>',
 data:()=>({inputText:'',composerCursor:0,isLoading:false,selection:{images:[]}}),
 methods:{replaceComposerDraft:methods.replaceComposerDraft,composerSelection:methods.composerSelection,autoResize(){},onKey(e){this.$refs.picker.handleKey(e);}},
});</script></body></html>`;
const products = ['Tall bottle', 'Wide box'].map((name,index) => ({images:[{
    name, sku:'DEMO-'+index, image_url:'https://unopim.test/image/'+index,
    token:'fixture-'+index, attribute:'image', index:0, channel:'default', locale:'en_US',
}]}));
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH,headless:true});
 const errors=[], unexpected=[], checks=[];
 try {
  const context=await browser.newContext({viewport:{width:800,height:800}});
  await context.route('**/*', route=>{
   const request=route.request(), url=new URL(request.url());
   if(url.origin!=='https://unopim.test'||request.method()!=='GET'){unexpected.push(request.method()+' '+url.pathname);return route.abort();}
   if(url.pathname==='/') return route.fulfill({contentType:'text/html',body:html});
   if(url.pathname==='/options') return route.fulfill({json:{batch_supported:true}});
   if(url.pathname==='/catalog') return route.fulfill({json:{products,next_page:null}});
   if(url.pathname.startsWith('/image/')) {
    const wide=url.pathname.endsWith('1');
    return route.fulfill({contentType:'image/svg+xml',body:`<svg xmlns="http://www.w3.org/2000/svg" width="${wide?600:120}" height="${wide?120:600}"><rect width="100%" height="100%" fill="purple"/></svg>`});
   }
   if(url.pathname.startsWith('/assets/')) {const file=path.join(assets,path.basename(url.pathname));if(fs.existsSync(file)) return route.fulfill({path:file,contentType:'text/javascript'});}
   unexpected.push(url.pathname);return route.abort();
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.stack));
  await page.goto('https://unopim.test/');
  await page.getByRole('button',{name:'Select Product Image'}).click();
  const option=page.getByRole('option').filter({hasText:'Wide box'});
  await expect(option).toBeEnabled();
  await page.locator('.dl-mention-preview img').evaluate(img=>img.decode());
  const before=await option.boundingBox();
  await page.mouse.move(before.x+before.width/2,before.y+before.height/2);
  await expect(page.locator('.dl-mention-preview img')).toHaveAttribute('src','https://unopim.test/image/1');
  await page.locator('.dl-mention-preview img').evaluate(img=>img.decode());
  const after=await option.boundingBox();
  const positions=await option.evaluate(async element=>{const ys=[];for(let i=0;i<12;i++){await new Promise(requestAnimationFrame);ys.push(element.getBoundingClientRect().y);}return ys;});
  checks.push({before,after,positions});
  assert.ok(Math.max(before.y,...positions)-Math.min(before.y,...positions)<1,'Hovering a different image must not move the option under the pointer');
  await option.click();
  await expect(page.locator('textarea')).toHaveValue('@Wide box ');
  await page.getByRole('button',{name:'Remove Wide box',exact:true}).waitFor();
  await page.getByRole('button',{name:'Remove Wide box',exact:true}).click();
  await page.locator('textarea').fill('@Tall');
  await expect(page.getByRole('option').first()).toBeEnabled();
  await page.locator('textarea').press('Enter');
  await expect(page.locator('textarea')).toHaveValue('@Tall bottle ');
  assert.deepEqual(errors,[]);assert.deepEqual(unexpected,[]);
  console.log(JSON.stringify({passed:true,checks,paid_calls:0,mouse_and_keyboard:true}));
 } catch(e) {console.error(JSON.stringify({passed:false,error:e.message,checks,errors,unexpected,paid_calls:0}));process.exitCode=1;}
 finally {await browser.close();}
})();
