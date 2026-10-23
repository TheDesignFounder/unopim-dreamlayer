// Isolated 8190 only. The provider intentionally injects the reported bad rewrite.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process'), { createHash } = require('node:crypto');
const base = 'http://127.0.0.1:8190', root = path.resolve(__dirname, '../..');
const validation = process.env.DREAMLAYER_VALIDATION_ROOT || '/Users/user/unopim-marketplace-validation-20261022';
const out = process.env.DREAMLAYER_BROWSER_EVIDENCE || root + '/qa/refinement-20261023/browser';
fs.mkdirSync(out, { recursive: true });
const inspect = id => JSON.parse(execFileSync('docker', ['--context', 'colima', 'compose', '-f', validation + '/compose.yaml', '-f', validation + '/studio.compose.yaml', 'exec', '-T', 'php', 'php', '/extension/tests/fixtures/inspect-studio.php', id || ''], { encoding: 'utf8' }));
let browser, context, page;
const errors = [], checks = [], timings = [];
const f = () => page.frameLocator('#dreamlayer-shell iframe');
const pass = name => { checks.push(name); console.log('PASS', name); };
const attach = async () => {
    page = await context.newPage(); page.setDefaultTimeout(45000);
    page.on('pageerror', e => errors.push(e.message));
    page.on('requestfinished', request => {
        if (request.method() === 'GET' && /\/workflows\/[a-f0-9-]+$/.test(request.url())) {
            timings.push({ id: request.url().split('/').at(-1), ms: request.timing().responseEnd });
        }
    });
};
const send = async text => {
    await f().locator('#message').fill(text);
    const pending = page.waitForResponse(r => r.url().endsWith('/editor/messages') && r.request().method() === 'POST');
    await f().locator('#send').click(); const response = await pending;
    assert.equal(response.status(), 200, await response.text());
    return { input: response.request().postDataJSON(), data: await response.json() };
};
const ready = async count => { await f().locator('.result-image').nth(count - 1).waitFor({ timeout: 180000 }); };
(async () => {
    browser = await chromium.launch({ headless: true }); context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await attach(); await page.goto(base + '/admin/login');
    await page.locator('[name=email]').fill('marketplace-test@example.test'); await page.locator('[name=password]').fill('Local-Test-2026');
    await page.getByRole('button', { name: 'Sign In', exact: true }).click(); await page.waitForURL('**/dashboard');
    const before = inspect(); assert(before.settings.enabled && before.settings.managed_chat && before.settings.save_chat_history, 'Fixture must have editing, assistance and History on');
    await page.goto(base + '/admin/dreamlayer/choose'); await f().locator('#message').press('@');
    await f().getByLabel('Search by name or SKU').fill('ABLO-00'); await f().getByRole('button', { name: 'Search', exact: true }).click();
    for (const sku of ['ABLO-001', 'ABLO-002']) await f().getByRole('button', { name: 'Select ' + sku + ' image 1', exact: true }).click();
    await f().getByRole('button', { name: 'Use selected images' }).click();
    const title = 'Refinement scope ' + Date.now();
    const batch = await send(title + ': Use a blue background for @Asset-1 and a white background for @Asset-2.');
    assert.equal(batch.data.workflow_ids.length, 2); await ready(2);
    const state = inspect(batch.input.conversation_id);
    for (const w of state.workflows) { assert.equal(w.catalog_preview_files, 1); assert.match(w.instruction, /The supplied image is @Asset-/); }
    pass('Two referenced products finish with fitted catalog artifacts and correct per-image instructions');
    const storageState = await context.storageState(); await context.close();
    context = await browser.newContext({ storageState, viewport: { width: 1440, height: 1000 } }); await attach();
    await page.goto(base + '/admin/dreamlayer/choose'); await f().getByRole('button', { name: 'History', exact: true }).click();
    const start = timings.length;
    await f().locator('.thread-open').filter({ hasText: title }).click(); await ready(2);
    assert.deepEqual(await f().locator('.result').evaluateAll(nodes => nodes.map(n => n.dataset.workflow)), batch.data.workflow_ids);
    assert.equal(await f().getByText(/Some image requests were made with saved History off/).count(), 0);
    const reopened = timings.slice(start); assert.equal(reopened.length, 2); assert(reopened.every(t => t.ms > 0 && t.ms < 8000), JSON.stringify(reopened));
    pass('Fresh-context batch reopening restores both cards without tens of seconds of preview fitting');
    await f().locator('[data-refine]').first().click();
    const message = 'Add a soft shadow under the car. Keep everything else unchanged.';
    const refinement = await send(message); assert.equal(refinement.input.selected_result, batch.data.workflow_ids[0]);
    assert.equal(refinement.data.workflow_ids.length, 1); await ready(3);
    const refinedState = inspect(batch.input.conversation_id), w = refinedState.workflows.find(w => w.id === refinement.data.workflow_ids[0]);
    assert.equal(w.instruction, message, 'Injected historical references and both backgrounds must never reach the image workflow');
    assert.equal(w.source_sha256, state.workflows.find(w => w.id === batch.data.workflow_ids[0]).result_sha256);
    assert.equal(w.target.sku, 'ABLO-001');
    pass('Reference-free refinement rejects the injected bad rewrite, preserves the original instruction, and uses only the selected result');
    const card = f().locator('.result').last();
    const reviewed = await (await context.request.get(await card.locator('.result-image').getAttribute('src'))).body();
    assert(reviewed.length <= 2 * 1024 * 1024);
    const original = await (await context.request.get(base + '/admin/dreamlayer/workflows/' + w.id + '/image')).body();
    assert(original.length > 2 * 1024 * 1024); assert.equal(createHash('sha256').update(original).digest('hex'), w.result_sha256);
    await card.getByRole('button', { name: 'Apply to product', exact: true }).click(); await card.getByRole('button', { name: 'Applied to product', exact: true }).waitFor();
    const after = inspect(batch.input.conversation_id);
    assert.equal(after.products['ABLO-001'].sha256, createHash('sha256').update(reviewed).digest('hex'));
    assert.equal(after.products['ABLO-002'].sha256, before.products['ABLO-002'].sha256);
    pass('Fitted preview still applies byte-exactly to one product while the original download and sibling remain intact');
    for (const [width, height, dark] of [[1440, 1000, false], [390, 844, true]]) {
        await page.setViewportSize({ width, height }); await page.evaluate(d => document.documentElement.classList.toggle('dark', d), dark); await page.waitForTimeout(200);
        assert(await f().locator('body').evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
        await page.screenshot({ path: `${out}/refinement-${width}-${dark ? 'dark' : 'light'}.png` });
    }
    assert.deepEqual(errors, []);
    fs.writeFileSync(out + '/results.json', JSON.stringify({ checks, errors, reopened, timings, conversation_id: batch.input.conversation_id, workflow_ids: [...batch.data.workflow_ids, w.id], instruction: w.instruction, mode: 'Real isolated HTTP/database/queues/UI; recorded image responses and deliberately polluted chat response; no paid requests' }, null, 2));
})().catch(async e => { console.error(e); await page?.screenshot({ path: out + '/failure.png' }).catch(() => {}); process.exitCode = 1; }).finally(async () => { await browser?.close(); });
