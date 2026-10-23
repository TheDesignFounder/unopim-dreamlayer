// Run only against the isolated browser fixture (recorded provider responses).
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const base = process.env.DREAMLAYER_BROWSER_URL || 'http://127.0.0.1:8190';
assert.equal(new URL(base).port, '8190', 'Use the isolated fixture, never a paid host');
const out = process.env.DREAMLAYER_BROWSER_EVIDENCE || 'qa/batch-history';
fs.mkdirSync(out, { recursive: true });
let browser, page;
const errors = [], checks = [];
const frame = () => page.frameLocator('#dreamlayer-shell iframe');
const check = name => { checks.push(name); console.log('PASS', name); };
const send = async message => {
    await frame().locator('#message').fill(message);
    const pending = page.waitForResponse(r => r.url().endsWith('/editor/messages') && r.request().method() === 'POST');
    await frame().getByRole('button', { name: 'Create preview', exact: true }).click();
    const response = await pending;
    assert.equal(response.status(), 200, await response.text());
    return { body: response.request().postDataJSON(), data: await response.json() };
};
(async () => {
    browser = await chromium.launch({ headless: true });
    let context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const attach = async () => {
        page = await context.newPage();
        page.setDefaultTimeout(60000);
        page.on('pageerror', e => errors.push(e.message));
    };
    await attach();
    await page.goto(base + '/admin/login');
    await page.locator('[name=email]').fill('marketplace-test@example.test');
    await page.locator('[name=password]').fill('Local-Test-2026');
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await page.waitForURL('**/dashboard');
    await page.goto(base + '/admin/dreamlayer/choose');
    await frame().getByRole('button', { name: 'Add product images', exact: true }).first().click();
    await frame().getByLabel('Search by name or SKU').fill('ABLO-00');
    await frame().getByRole('button', { name: 'Search', exact: true }).click();
    for (const sku of ['ABLO-001', 'ABLO-002']) {
        await frame().getByRole('button', { name: `Select ${sku} image 1`, exact: true }).click();
    }
    await frame().getByRole('button', { name: 'Use selected images' }).click();
    const title = 'Batch history regression ' + Date.now();
    const batch = await send(title + ': give both cars a light gray background.');
    assert.equal(batch.data.workflow_ids.length, 2);
    await frame().locator('.result-image').nth(1).waitFor({ timeout: 180000 });
    check('New two-car request completes with two result cards');
    const reopen = async ids => {
        const storageState = await context.storageState();
        await context.close();
        context = await browser.newContext({ storageState, viewport: { width: 1440, height: 1000 } });
        await attach();
        const response = await context.request.get(base + '/admin/dreamlayer/studio/threads/' + batch.body.conversation_id);
        assert.equal(response.status(), 200);
        const saved = await response.json();
        assert.equal(saved.omitted, false);
        assert.deepEqual(saved.turns.flatMap(t => t.workflow_ids), ids);
        await page.goto(base + '/admin/dreamlayer/choose');
        await frame().getByRole('button', { name: 'History', exact: true }).click();
        await frame().locator('.thread-open').filter({ hasText: title }).click();
        await frame().locator('.result-image').nth(ids.length - 1).waitFor();
        assert.deepEqual(await frame().locator('.result').evaluateAll(nodes => nodes.map(n => n.dataset.workflow)), ids);
        assert.equal(await frame().getByText(/Some image requests were made with saved History off/).count(), 0);
    };
    await reopen(batch.data.workflow_ids);
    check('Fresh browser context restores both saved cards in selection order without a false history warning');
    for (const [i, sku] of ['ABLO-001', 'ABLO-002'].entries()) {
        assert.match(await frame().locator('.result').nth(i).innerText(), new RegExp(sku));
    }
    await frame().locator('[data-refine]').first().click();
    const follow = await send('Give the first car warmer lighting.');
    assert.equal(follow.body.selected_result, batch.data.workflow_ids[0]);
    assert.equal(follow.body.conversation_id, batch.body.conversation_id);
    await frame().locator('.result-image').nth(2).waitFor({ timeout: 180000 });
    await reopen([...batch.data.workflow_ids, ...follow.data.workflow_ids]);
    check('Refine from a reopened batch uses the selected car; mixed batch and single history reopens all three cards');
    for (const [width, height, dark] of [[1440, 1000, false], [390, 844, true]]) {
        await page.setViewportSize({ width, height });
        await page.evaluate(value => document.documentElement.classList.toggle('dark', value), dark);
        await page.waitForTimeout(200);
        assert(await frame().locator('body').evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
        await page.screenshot({ path: `${out}/reopened-${width}-${dark ? 'dark' : 'light'}.png` });
    }
    check('Reopened conversation fits actual Playwright desktop and 390px viewports');
    assert.deepEqual(errors, []);
    fs.writeFileSync(out + '/results.json', JSON.stringify({ checks, errors, mode: 'Real isolated host HTTP/database/UI; recorded provider responses; no paid requests', conversation_id: batch.body.conversation_id }, null, 2));
})().catch(async error => {
    console.error(error);
    await page?.screenshot({ path: out + '/failure.png' }).catch(() => {});
    process.exitCode = 1;
}).finally(async () => { await browser?.close(); });
