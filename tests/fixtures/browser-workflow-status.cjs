// Actual Vue card and UnoPIM assets; all HTTP is fulfilled locally, with no paid work.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium, expect} = require(process.env.PLAYWRIGHT_MODULE || '@playwright/test');
const host = process.env.UNOPIM_HOST_ROOT;
if (!host) throw Error('Set UNOPIM_HOST_ROOT to the engineering host.');
const root = path.resolve(__dirname, '../..');
const {compileModules} = require(path.join(host, 'packages/Webkul/AiAgent/tests/JavaScript/widget-harness.cjs'));
const labels = Object.fromEntries([...fs.readFileSync(path.join(root, 'resources/lang/en_US/app.php'), 'utf8').matchAll(/'([a-z_]+)'\s*=>\s*'([^']*)'/g)].map(m => [m[1], m[2]]));
const {source, javascript} = compileModules(path.join(root, 'resources/views/workflow.blade.php'), {
    "@json(trans('dreamlayer::app'))": labels,
    "@json(url(config('app.admin_url').'/dreamlayer/workflows'))": '/workflows',
});
const templates = [...source.matchAll(/<script type="text\/x-template"[\s\S]*?<\/script>/g)].map(m => m[0].replaceAll('@{{', '{{').replace(/@lang\('dreamlayer::app\.([a-z_]+)'\)/g, (_, key) => labels[key]).replace(/\{\{ route\('dreamlayer.results'\) \}\}/g, '/results')).join('\n');
const assets = path.join(host, 'public/themes/admin/default/build/assets');
const files = fs.readdirSync(assets);
const app = files.find(name => /^app-.*\.js$/.test(name));
const css = files.filter(name => /^app-.*\.css$/.test(name)).map(name => `<link rel="stylesheet" href="/assets/${name}">`).join('');
const style = fs.readFileSync(path.join(root, 'resources/views/styles.blade.php'), 'utf8').match(/<style>[\s\S]*?<\/style>/)[0];
const base = {id:'saved-request', kind:'edit', steps:[], target:null, error:null, image_url:null, delayed:false};

(async () => {
    const browser = await chromium.launch({executablePath:process.env.CHROME_PATH, headless:true});
    try {
        for (const dark of [false, true]) {
            let state = {...base, status:'queued'};
            const errors = [], unexpected = [];
            const context = await browser.newContext({viewport:dark ? {width:390,height:844} : {width:900,height:800}});
            await context.route('**/*', async route => {
                const req = route.request(), url = new URL(req.url());
                if (url.origin !== 'https://unopim.test' || req.method() !== 'GET') {unexpected.push(req.method()+' '+url.pathname); return route.abort();}
                if (url.pathname === '/') return route.fulfill({contentType:'text/html', body:`<html lang="en" class="${dark?'dark':''}"><head><meta charset="UTF-8">${css}${style}</head><body style="padding:20px;background:${dark?'#201c31':'#fff'}"><div id="app"><v-dreamlayer-workflow :descriptor="{id:'saved-request'}"></v-dreamlayer-workflow></div>${templates}<script type="module">import '/assets/${app}';\n${javascript}</script></body></html>`});
                if (url.pathname === '/workflows/saved-request') return route.fulfill({json:state});
                if (url.pathname.startsWith('/assets/')) {
                    const file = path.join(assets, path.basename(url.pathname));
                    if(fs.existsSync(file)) return route.fulfill({path:file});
                }
                unexpected.push(url.pathname); return route.abort();
            });
            const page = await context.newPage();
            page.on('pageerror', e => errors.push(e.message));
            await page.goto('https://unopim.test/');
            await expect(page.getByText(labels.queued, {exact:true})).toBeVisible();
            await expect(page.getByText(labels.queued_help, {exact:true})).toBeVisible();
            state = {...base, status:'queued', delayed:true};
            await expect(page.getByText(labels.delayed, {exact:true})).toBeVisible();
            await expect(page.getByText(labels.delayed_help, {exact:true})).toBeVisible();
            if(process.env.OUTPUT_DIR) await page.screenshot({path:path.join(process.env.OUTPUT_DIR, `delayed-${dark?'mobile-dark':'desktop-light'}.png`)});
            state = {...base, status:'running'};
            await expect(page.getByText(labels.processing, {exact:true})).toBeVisible();
            await expect(page.getByText(labels.delayed_help, {exact:true})).toHaveCount(0);
            await expect(page.getByRole('button', {name:labels.apply, exact:true})).toHaveCount(0);
            for (const phase of ['waiting', 'downloading', 'retrying_delivery']) {
                state = {...base, status:'running', phase, delayed:phase==='retrying_delivery'};
                await expect(page.getByText(labels[phase], {exact:true})).toBeVisible();
                await expect(page.getByText(labels.delayed, {exact:true})).toHaveCount(0);
                await expect(page.getByRole('button', {name:labels.apply, exact:true})).toHaveCount(0);
            }
            state = {...base, status:'failed', error:'Fixture service unavailable.'};
            await expect(page.getByText(labels.failed, {exact:true})).toBeVisible();
            await expect(page.getByRole('alert')).toHaveText(state.error);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
            await context.close();
        }
        console.log('PASS queued → delayed → processing → failed; desktop/light and mobile/dark; no resubmission or premature Apply.');
    } finally {await browser.close();}
})().catch(e => {console.error(e); process.exitCode = 1;});
