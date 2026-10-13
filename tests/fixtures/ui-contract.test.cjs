const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const host = process.env.UNOPIM_HOST_ROOT;
if (!host) throw Error('Set UNOPIM_HOST_ROOT to the engineering checkout with the patched core.');
const {loadComponents, instance, widget} = require(path.join(host, 'packages/Webkul/AiAgent/tests/JavaScript/widget-harness.cjs'));
const root = path.resolve(__dirname, '../..');
const cases = require('./mention-cases.json');

test('image card follows queued, delayed, running, and terminal state without resubmitting', async () => {
    const labels = {queued:'Image queued', queued_help:'Waiting to start.', delayed:'Image work delayed', delayed_help:'Your request is saved.', processing:'Processing image…', editing:'Running your edits…', complete:'Edits complete', failed:'Image work could not finish'};
    const {components} = loadComponents(path.join(root, 'resources/views/workflow.blade.php'), {}, {"@json(trans('dreamlayer::app'))": labels});
    const component = components['v-dreamlayer-workflow'];
    const card = instance(component, {descriptor:{id:'saved-workflow'}, visible:true});
    let reads = 0;
    for(const [state, title, help] of [
        [{status:'queued'}, labels.queued, labels.queued_help],
        [{status:'queued', delayed:true}, labels.delayed, labels.delayed_help],
        [{status:'running'}, labels.processing, ''],
        [{status:'running', detailed_progress:true}, labels.editing, ''],
        [{status:'running', detailed_progress:true, delayed:true}, labels.delayed, labels.delayed_help],
        [{status:'completed', delayed:true}, labels.complete, ''],
        [{status:'failed', delayed:true}, labels.failed, ''],
        [{status:'failed', error_code:402}, 'Out of image credits', ''],
    ]) {
        card.$axios = {get:async () => {reads++; return {data:state};}, post:async () => {throw Error('Status refresh must not resubmit work');}};
        await card.poll();
        assert.equal(card.title, title);
        assert.equal(card.statusHelp, help);
    }
    assert.equal(reads, 8);
    component.beforeUnmount.call(card);
    await card.poll();
    assert.equal(reads, 8);
});

function picker() {
    const {components} = loadComponents(path.join(root, 'resources/views/picker.blade.php'));
    const component = components['v-dreamlayer-picker'];
    const p = instance(component, {conversationId: 'fixture', draft: '', cursor: 0});
    p.options = {batch_supported: true};
    p.$emit = () => {};
    return {p, component};
}

for (const fixture of cases) test('picker/highlight shared server case: ' + fixture.name, () => {
    const {p} = picker();
    p.selected = fixture.labels.map((mention, i) => ({mention, reference: 'Asset-' + (i + 1)}));
    const expected = fixture.indices.map(i => 'Asset-' + (i + 1));
    assert.deepEqual(Array.from(p.references(fixture.message)), expected);
    const {widget: w} = widget();
    w.inputText = fixture.message;
    w.extensionContext = {dreamlayer: {references: fixture.labels.map(n => '@' + n)}};
    assert.deepEqual(Array.from(w.composerSegments.filter(part => part.reference), part => part.text),
        fixture.indices.map(i => '@' + fixture.labels[i]));
});

test('name limit preserves an emoji at the 180th character and duplicate suffixes', () => {
    const {p} = picker();
    const name = 'a'.repeat(179) + '😀extra';
    const first = p.add({name, sku: 'one', image_url: '/one', token: 'one'});
    const second = p.add({name, sku: 'two', image_url: '/two', token: 'two'});
    assert.equal(first.mention, 'a'.repeat(179) + '😀');
    assert.equal(Array.from(first.mention).length, 180);
    assert.equal(second.mention, first.mention + ' (2)');
    assert.equal(JSON.stringify(first).includes('\\ud83d'), false, 'No split surrogate escapes');
});

test('removing a mention preserves an embedded lookalike and another destination', () => {
    const {p, component} = picker();
    p.selected = [{mention: 'Serum', reference: 'Asset-1', token: 'one'}, {mention: 'Other', reference: 'Asset-2', token: 'two'}];
    p.draft = 'x@Serum @Serum @Other';
    let replacement;
    p.$emit = (event, value) => { if (event === 'replace-draft') replacement = value; };
    p.remove(0);
    assert.equal(replacement.text, 'x@Serum  @Other');
    assert.deepEqual(Array.from(p.selected, i => i.token), ['two']);
    p.findMention = () => {};
    component.watch.draft.call(p, 'x@Other', '@Other');
    assert.equal(p.selected.length, 0, 'An embedded lookalike must not keep a removed attachment');
});

test('settings label follows refreshed saved state without a page reload', async () => {
    const {components, source} = loadComponents(path.join(root, 'resources/views/settings.blade.php'));
    const component = components['v-dreamlayer-connection-status'];
    const s = instance(component, {initial: {status: 'connected', image_tools_enabled: true, chat_enabled: false}});
    assert.match(source, /v-text="connectionLabel"/);
    assert.equal(s.connectionLabel, 'DreamLayer – Images only');
    for (const [images, chat, label] of [
        [true, true, 'DreamLayer – Chat & Images'],
        [false, true, 'DreamLayer – Tools disabled'],
        [true, false, 'DreamLayer – Images only'],
    ]) {
        s.$axios = {get: async () => ({data: {status: 'connected', image_tools_enabled: images, chat_enabled: chat}})};
        s.nextRefreshAt = 0;
        await s.refresh();
        assert.equal(s.connectionLabel, label);
        assert.equal(s.reading, false);
    }
    s.$axios = {get: async () => { throw Error('fixture outage'); }};
    s.nextRefreshAt = 0;
    await s.refresh();
    assert.equal(s.state.status, 'connected');
    assert.match(s.refreshError, /couldn’t refresh/);
    component.beforeUnmount.call(s);
    s.$axios = {get: async () => { throw Error('Must not poll after unmount'); }};
    await s.refresh();
});

function connection(overrides = {}) {
    const globals = {setInterval: () => 1, clearInterval() {}, ...overrides};
    const {components} = loadComponents(path.join(root, 'resources/views/settings.blade.php'), globals);
    const component = components['v-dreamlayer-connect'];
    return {c: instance(component, {automatic: true, $emit() {}}), component};
}

test('connection obtains one code, keeps it during validity, and replaces it only at expiry', async () => {
    const {c} = connection();
    let starts = 0;
    c.$axios = {post: async () => {starts++; return {data: {user_code: 'ABCD-1234', verification_uri: 'https://dreamlayer.test/console/connect', expires_in: 600, interval: 5, attempt_id: 'attempt-1'}};}};
    await c.connect(false);
    c.tick(); c.tick();
    assert.equal(starts, 1);
    assert.equal(c.code, 'ABCD-1234');
    c.deadline = Date.now() - 1000;
    c.tick();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(starts, 2);
    assert.equal(c.busy, false);
});

test('code creation and pending approval stay disconnected; only approval starts waiting', async () => {
    const events = [];
    let reloads = 0;
    const {c, component} = connection({window: {location: {reload() {reloads++;}}}});
    c.$emit = (event, waiting) => {assert.equal(event, 'pending-change'); events.push(waiting);};
    c.$axios = {post: async () => ({data: {user_code: 'ABCD-1234', expires_in: 600, interval: 5, verification_uri: 'https://dreamlayer.test/console/connect', attempt_id: 'attempt-1'}})};
    await c.connect(); component.watch.code.call(c);
    assert.deepEqual(events, [false]);
    for (const status of ['authorization_pending', 'approved']) {
        c.nextPollAt = 0;
        c.$axios = {post: async () => ({data: {status, interval:5}})};
        await c.poll();
    }
    assert.deepEqual(events, [false, false, true]);
    assert.equal(reloads, 0, 'Approval alone is not a completed connection');
    assert.ok(c.nextPollAt <= Date.now(), 'Finalize immediately after approval');
    c.$axios = {post: async () => ({data: {status: 'connected'}})};
    await c.poll();
    assert.equal(reloads, 1);
});

test('denial, expiry and a new code clear the waiting state', async () => {
    for (const status of ['access_denied', 'expired_token']) {
        const {c} = connection();
        c.code = 'ABCD-1234'; c.deadline = Date.now() + 300000;
        let waiting = true;
        c.$emit = (_, value) => {waiting = value;};
        c.$axios = {post: async () => {throw {response: {status: 400, data: {status}}};}};
        await c.poll();
        assert.equal(waiting, false);
    }
    const {c} = connection();
    const events = [];
    c.$emit = (_, value) => events.push(value);
    c.$axios = {post: async () => ({data: {user_code: 'ABCD-1234', expires_in:600, interval:5, approved:true}})};
    await c.connect();
    c.$axios = {post: async () => ({data: {user_code: 'EFGH-5678', expires_in:600, interval:5, approved:false}})};
    await c.connect(true);
    assert.deepEqual(events, [true, false]);
});

test('status rate limits retain the displayed code state and focus events respect retry-after', async () => {
    const {components} = loadComponents(path.join(root, 'resources/views/settings.blade.php'));
    const s = instance(components['v-dreamlayer-connection-status'], {initial: {status: 'disconnected'}});
    let reads = 0;
    s.$axios = {get: async () => {reads++; throw {response: {status: 429, headers: {'retry-after': '45'}}};}};
    const before = Date.now();
    await s.refresh();
    assert.equal(s.state.status, 'disconnected', 'Keep the code component mounted');
    assert.ok(s.nextRefreshAt >= before + 45000);
    s.visible(); s.visible(); await s.refresh();
    assert.equal(reads, 1);
});

test('returning from approval resumes an overdue poll immediately and only once', async () => {
    const {c} = connection();
    c.code = 'ABCD-1234'; c.deadline = Date.now() + 300000; c.nextPollAt = Date.now() - 1000;
    let polls = 0, finish;
    c.$axios = {post: () => {polls++; return new Promise(resolve => {finish = resolve;});}};
    c.visible(); c.visible();
    assert.equal(polls, 1);
    finish({data: {status: 'authorization_pending', interval:5}});
    await new Promise(resolve => setImmediate(resolve));
    c.visible();
    assert.equal(polls, 1, 'Do not violate the interval on another focus event');
});

test('a new return tab checks an overdue code immediately and preserves an existing backoff', async () => {
    for (const [pollAfter, approved] of [[0, false], [3, false], [20, true]]) {
        const {c} = connection();
        c.$axios = {post: async () => ({data: {user_code: 'ABCD-1234', expires_in:600, interval:5, poll_after:pollAfter, approved}})};
        const before = Date.now();
        await c.connect(false);
        assert.ok(c.nextPollAt >= before + pollAfter * 1000);
        assert.ok(c.nextPollAt <= Date.now() + pollAfter * 1000, 'Use the remaining wait, not a new interval');
        assert.equal(c.interval, 5, 'The steady polling interval is unchanged');
    }
});

test('polling backs off for the server retry-after without removing the valid code', async () => {
    const {c} = connection();
    c.code = 'ABCD-1234'; c.deadline = Date.now() + 300000;
    c.$axios = {post: async () => {throw {response: {status:429, headers:{'retry-after':'42'}}};}};
    const before = Date.now();
    await c.poll();
    assert.equal(c.code, 'ABCD-1234');
    assert.ok(c.nextPollAt >= before + 42000);
});

test('copy confirms the exact code and never marks a replacement code as copied', async () => {
    let copied, finish;
    const {c, component} = connection({navigator: {clipboard: {writeText: code => {copied = code; return new Promise(resolve => {finish = resolve;});}}}});
    c.code = 'ABCD-1234'; c.remaining = 600;
    const first = c.copyCode(); finish(); await first;
    assert.equal(copied, 'ABCD-1234'); assert.equal(c.copied, true);
    const second = c.copyCode();
    c.code = 'EFGH-5678'; component.watch.code.call(c); finish(); await second;
    assert.equal(c.copied, false);
    c.$axios = null;
});

test('connection retries temporary polling failures without replacing its valid code', async () => {
    const {c} = connection();
    c.code = 'ABCD-1234'; c.attempt = 'attempt-1'; c.deadline = Date.now() + 300000;
    c.$axios = {post: async (_, data) => {assert.equal(data.attempt_id, 'attempt-1'); throw {response: {status: 503}};}};
    await c.poll();
    assert.equal(c.code, 'ABCD-1234');
    assert.ok(c.error.includes('try again'));
    assert.equal(c.busy, false);
});

test('denial stops automatic generation and a stale reply cannot revive an unmounted connection', async () => {
    const {c, component} = connection();
    c.code = 'ABCD-1234'; c.deadline = Date.now() + 300000;
    c.$axios = {post: async () => {throw {response: {status: 400, data: {status: 'access_denied'}}};}};
    await c.poll();
    assert.equal(c.code, null);
    c.tick();
    assert.ok(c.error.includes('denied'));
    let finish;
    c.$axios = {post: () => new Promise(resolve => {finish = resolve;})};
    const pending = c.connect();
    component.beforeUnmount.call(c);
    finish({data: {user_code: 'EFGH-5678', expires_in:600}});
    await pending;
    assert.equal(c.code, null);
});
