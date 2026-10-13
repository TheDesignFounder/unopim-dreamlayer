// Presentation regression on the local manual host. Connection writes are intercepted;
// the existing credential, grants, account and provider jobs are never changed.
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || '@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
    const base = process.env.UNOPIM_URL || 'http://localhost:8100';
    assert.equal(new URL(base).hostname, 'localhost');
    const out = process.env.EVIDENCE_DIR;
    const browser = await chromium.launch({executablePath: process.env.CHROME_PATH, headless: true});
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    await context.addCookies([{name:'dark_mode', value:'dark', url:base}]);
    const page = await context.newPage();
    page.setDefaultTimeout(90000);
    page.setDefaultNavigationTimeout(120000);
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    let terminal = 'access_denied';
    let state = null;
    let unexpectedWrites = 0;
    await page.route('**/admin/dreamlayer/**', async route => {
        const r = route.request(); const pathname = new URL(r.url()).pathname;
        if (pathname.endsWith('/settings/status') && state) {
            return route.fulfill({status:state === 'outage' ? 503 : 200, json:state === 'outage' ? {} : state});
        }
        if (r.method() === 'POST') {
            if (pathname.endsWith('/connect')) return route.fulfill({json:{user_code:'TEST-1234',verification_uri:'http://localhost:8102/console/connect',interval:1}});
            if (pathname.endsWith('/connect/poll')) return route.fulfill({status:400,json:{status:terminal}});
            unexpectedWrites++; return route.abort();
        }
        return route.continue();
    });
    await page.goto(base + '/admin/dreamlayer/settings', {waitUntil:'domcontentloaded'});
    if (page.url().includes('/login')) {
        const login = JSON.parse(fs.readFileSync(process.env.UNOPIM_LOGIN_FILE, 'utf8'));
        await page.locator('input[name="email"]').fill(login.email);
        await page.locator('input[name="password"]').fill(login.password);
        await Promise.all([page.waitForURL(u => !u.pathname.includes('/login')), page.getByRole('button',{name:'Sign In',exact:true}).click()]);
        await page.goto(base + '/admin/dreamlayer/settings', {waitUntil:'domcontentloaded'});
    }
    await page.getByText(/Connected · DreamLayer account/).waitFor();
    assert.match(await page.locator('html').getAttribute('class'), /dark/);
    if (out) await page.screenshot({path:path.join(out,'connected-dark.png')});
    console.log('PASS real read-only settings: connected, account method, dark mode');

    state = {status:'unauthorized',method:'DreamLayer account',balances:null};
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.getByRole('alert').filter({hasText:'Connection no longer authorized'}).waitFor();
    assert.equal(await page.getByText(/API credits:/).count(), 0);
    assert.equal(await page.getByText(/Connected ·/).count(), 0);
    if (out) await page.screenshot({path:path.join(out,'remote-revocation-dark.png')});
    console.log('PASS mocked remote rejection: no connected claim or stale balance');

    state = 'outage';
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.getByRole('status').filter({hasText:'saved credential is unchanged'}).waitFor();
    console.log('PASS mocked outage: distinct unavailable state');

    for (const error of ['access_denied','expired_token']) {
        terminal = error;
        await page.getByRole('button',{name:'Connect DreamLayer',exact:true}).click();
        await page.getByText('TEST-1234',{exact:true}).waitFor();
        await page.getByRole('alert').filter({hasText:error === 'access_denied' ? 'Connection denied' : 'Connection code expired'}).waitFor();
        assert.equal(await page.getByText('TEST-1234',{exact:true}).count(),0);
        assert.equal(await page.getByRole('button',{name:'Connect DreamLayer',exact:true}).isEnabled(),true);
        console.log('PASS mocked ' + error + ': dead code cleared and retry enabled');
    }
    state = null;
    await context.addCookies([{name:'dark_mode',value:'light',url:base}]);
    await page.reload({waitUntil:'domcontentloaded'});
    await page.getByText(/Connected · DreamLayer account/).waitFor();
    assert.doesNotMatch(await page.locator('html').getAttribute('class') || '', /dark/);
    if (out) await page.screenshot({path:path.join(out,'connected-light.png')});
    assert.equal(unexpectedWrites,0);
    assert.deepEqual(errors,[]);
    console.log('PASS light mode, no browser exceptions, no connection mutations');
    await browser.close();
})().catch(e => {console.error(e.stack); process.exit(1);});
