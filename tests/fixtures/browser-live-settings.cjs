// Render the actual status component with the existing UnoPIM Vue bundle.
// All network traffic is fulfilled locally; no application/account is opened.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || '@playwright/test');
const host = process.env.UNOPIM_HOST_ROOT;
if (!host) throw Error('Set UNOPIM_HOST_ROOT to the isolated engineering host.');
const {compileModules} = require(path.join(host, 'packages/Webkul/AiAgent/tests/JavaScript/widget-harness.cjs'));
const view = path.resolve(__dirname, '../../resources/views/settings.blade.php');
const {source, javascript} = compileModules(view, {"@json(route('dreamlayer.settings.status'))": '/status'});
const templates = [...source.matchAll(/<script type="text\/x-template"[\s\S]*?<\/script>/g)].map(m => m[0].replaceAll('@{{', '{{')).join('\n');
const assets = path.join(host, 'public/themes/admin/default/build/assets');
const app = fs.readdirSync(assets).find(name => /^app-.*\.js$/.test(name));
assert.ok(app, 'Existing UnoPIM admin bundle is required; this test installs nothing.');
let state = {status: 'connected', image_tools_enabled: true, chat_enabled: false};
let reads = 0;
const html = '<html lang="en"><head><meta charset="UTF-8"></head><body><div id="app"><v-dreamlayer-connection-status :initial=\'' +
    JSON.stringify(state) + '\'></v-dreamlayer-connection-status></div>' + templates +
    '<script type="module">import "/assets/' + app + '";\n' + javascript + '</script></body></html>';

(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROME_PATH, headless: true});
    try {
        const context = await browser.newContext();
        const errors = [], unexpected = [];
        await context.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.origin !== 'https://unopim.test' || route.request().method() !== 'GET') {
                unexpected.push(route.request().method() + ' ' + url.origin + url.pathname);
                return route.abort();
            }
            if (url.pathname === '/') return route.fulfill({contentType: 'text/html', body: html});
            if (url.pathname === '/status') { reads++; return route.fulfill({json: state}); }
            if (url.pathname.startsWith('/assets/')) {
                const name = path.basename(url.pathname);
                const file = path.join(assets, name);
                if (fs.existsSync(file)) return route.fulfill({path: file, contentType: name.endsWith('.js') ? 'text/javascript' : 'text/css'});
            }
            unexpected.push(url.pathname);
            return route.abort();
        });
        const page = await context.newPage();
        await page.clock.install();
        page.on('pageerror', e => errors.push(e.stack));
        await page.goto('https://unopim.test/');
        await page.getByText('DreamLayer – Images only', {exact: true}).waitFor({timeout: 5000}).catch(async error => { throw Error(error.message + '\n' + JSON.stringify({errors, unexpected, body: await page.locator('body').innerText()})); });
        for (const [images, chat, label] of [
            [true, true, 'DreamLayer – Chat & Images'],
            [false, true, 'DreamLayer – Tools disabled'],
            [true, false, 'DreamLayer – Images only'],
        ]) {
            state = {...state, image_tools_enabled: images, chat_enabled: chat};
            await page.clock.fastForward(5100);
            await page.evaluate(() => window.dispatchEvent(new Event('focus')));
            await page.getByText(label, {exact: true}).waitFor();
        }
        state = {...state, status: 'unauthorized'};
        await page.clock.fastForward(5100);
        await page.evaluate(() => window.dispatchEvent(new Event('focus')));
        await page.getByRole('alert').waitFor();
        assert.equal(await page.getByText('DreamLayer – Images only', {exact: true}).count(), 0);
        assert.ok(reads >= 4);
        assert.deepEqual(errors, []);
        assert.deepEqual(unexpected, []);
        console.log('PASS live saved-state labels update without reload; unauthorized state hides labels; all browser requests fulfilled locally.');
    } finally { await browser.close(); }
})().catch(e => {console.error(e.stack); process.exitCode = 1;});
