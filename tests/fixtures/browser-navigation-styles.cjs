// Opt-in browser regression against a running review installation. Connection
// requests are intercepted: this check never issues codes, approves or runs edits.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '@playwright/test');

const base = process.env.UNOPIM_TEST_URL;
const credentialsFile = process.env.UNOPIM_TEST_CREDENTIALS_FILE;
if (!base || !credentialsFile) throw Error('Set UNOPIM_TEST_URL and UNOPIM_TEST_CREDENTIALS_FILE for an isolated review installation.');
const origin = new URL(base).origin;
const admin = process.env.UNOPIM_ADMIN_PATH || '/admin';
const output = process.env.UNOPIM_TEST_OUTPUT;
const credentials = fs.readFileSync(credentialsFile, 'utf8');
const email = credentials.match(/^email:\s*(.+)$/m)?.[1];
const password = credentials.match(/^password:\s*(.+)$/m)?.[1];
assert.ok(email && password, 'Credentials file must contain email: and password: lines.');

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
    const checks = [];
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        context.setDefaultTimeout(60000);
        const errors = [];
        const unexpected = [];
        await context.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin !== origin) {
                unexpected.push(request.method() + ' ' + url.origin + url.pathname);
                return route.abort();
            }
            if (url.pathname === admin + '/dreamlayer/connect') return route.fulfill({ json: {
                user_code: 'TEST-0000', verification_uri: origin + '/not-used',
                attempt_id: 'browser-style-fixture', expires_in: 600, interval: 60, poll_after: 60
            } });
            if (url.pathname === admin + '/dreamlayer/connect/poll') return route.fulfill({ status: 202, json: { status: 'authorization_pending', interval: 60 } });
            if (request.method() !== 'GET' && url.pathname !== admin + '/login') {
                unexpected.push(request.method() + ' ' + url.pathname);
                return route.abort();
            }
            return route.continue();
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        try {
            await page.goto(origin + admin + '/catalog/products', { waitUntil: 'domcontentloaded' });
            await page.locator('input[name="email"]').fill(email);
            await page.locator('input[name="password"]').fill(password);
            await page.getByRole('button', { name: 'Sign In', exact: true }).click();
            await page.waitForURL(url => url.pathname !== admin + '/login', { waitUntil: 'domcontentloaded' });

            async function clickVisit(suffix) {
                const before = await page.evaluate(() => window.__styleNavVisits);
                await page.locator('a:visible[href="' + origin + admin + suffix + '"]').first().click();
                await page.waitForFunction(previous => window.__styleNavVisits > previous, before);
                assert.equal(new URL(page.url()).pathname, admin + suffix);
                assert.equal(await page.evaluate(() => window.__styleDocument), 'same-document', 'Navigation must exercise the AJAX path, not a reload fallback.');
            }

            async function checkStyles(label) {
                const styles = await page.locator('.dl-page').evaluate(element => {
                    const css = getComputedStyle(element);
                    const heading = getComputedStyle(element.querySelector('h1'));
                    const button = element.querySelector('.primary-button');
                    return { padding: css.paddingTop, maxWidth: css.maxWidth, heading: heading.fontSize,
                        buttonDisplay: button && getComputedStyle(button).display,
                        buttonWidth: button && button.getBoundingClientRect().width };
                });
                checks.push({ label, ...styles });
                assert.equal(styles.padding, '32px', label + ': page spacing');
                assert.equal(styles.maxWidth, '1040px', label + ': readable content width');
                assert.equal(styles.heading, '24px', label + ': heading size');
                assert.ok(styles.buttonWidth < 400, label + ': action must not fill the page');
                if (await page.locator('.dl-settings').count()) {
                    // Hidden form inputs must stay inside the scrolling panel.
                    // Before this fix, focusing consent could scroll the whole
                    // document and leave a blank area below the admin shell.
                    const layout = await page.locator('input[name="privacy_consent"]').evaluate(input => {
                        input.scrollIntoView({ block: 'center' });
                        return { height: document.documentElement.scrollHeight,
                            viewport: innerHeight, windowY: scrollY };
                    });
                    assert.equal(layout.height, layout.viewport, label + ': no document overflow');
                    assert.equal(layout.windowY, 0, label + ': header must stay in place');
                }
                console.error('PASS ' + label);
            }

            for (const theme of ['light', 'dark']) {
                await context.addCookies([{ name: 'dark_mode', value: theme, url: origin }]);
                await page.goto(origin + admin + '/catalog/products', { waitUntil: 'domcontentloaded' });
                await page.waitForFunction(() => typeof window.unopim?.visit === 'function');
                await page.evaluate(() => {
                    window.__styleDocument = 'same-document';
                    window.__styleNavVisits = 0;
                    document.addEventListener('unopim:navigate:success', () => window.__styleNavVisits++);
                });
                for (let visit = 0; visit < 2; visit++) {
                    await clickVisit('/dreamlayer/settings');
                    await page.locator('.dl-settings input[name="enabled"]').waitFor({ state: 'attached' });
                    await checkStyles(theme + ': settings from catalog ' + visit);
                    await clickVisit('/dreamlayer/workflows');
                    await checkStyles(theme + ': saved results ' + visit);
                    await clickVisit('/dreamlayer/choose');
                    await checkStyles(theme + ': chat entry ' + visit);
                    const before = await page.evaluate(() => window.__styleNavVisits);
                    await page.evaluate(() => history.back());
                    await page.waitForFunction(previous => window.__styleNavVisits > previous, before);
                    await checkStyles(theme + ': back to results ' + visit);
                    await clickVisit('/catalog/products');
                }
                await page.goto(origin + admin + '/dreamlayer/settings', { waitUntil: 'domcontentloaded' });
                await page.locator('.dl-settings input[name="enabled"]').waitFor({ state: 'attached' });
                await checkStyles(theme + ': direct load');
                if (output) {
                    fs.mkdirSync(output, { recursive: true });
                    await page.screenshot({ path: path.join(output, 'settings-' + theme + '.png') });
                }
            }
            // Let the host's delayed initialization callbacks settle after
            // rapid navigation. They must not access an unmounted settings form.
            await page.waitForTimeout(800);
            assert.deepEqual(errors, []);
            assert.deepEqual(unexpected, []);
            console.log(JSON.stringify({ passed: true, checks, pageErrors: errors, unexpectedRequests: unexpected }, null, 2));
        } catch (error) {
            console.error(JSON.stringify({ checks, pageErrors: errors, unexpectedRequests: unexpected }, null, 2));
            if (output) {
                fs.mkdirSync(output, { recursive: true });
                await page.screenshot({ path: path.join(output, 'failure.png'), timeout: 5000 }).catch(() => {});
            }
            throw error;
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
