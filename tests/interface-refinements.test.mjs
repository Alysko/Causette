import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createContext, runInContext } from 'node:vm';

const asset = (name) => readFileSync(new URL('../public_html/assets/js/' + name, import.meta.url), 'utf8');

test('MP4 and WebM attachments render a player in live messages', () => {
    const source = asset('app.js');
    const context = createContext({ escapeHtml: String, uiText: String, formatFileSize: String });
    runInContext(source.slice(source.indexOf('function getAttachmentCategory('), source.indexOf('function avatarColorClasses(')), context);
    const fixture = { id: 1, file_name: 'sample', url: '/attachments/1/sample', original_name: 'Sample' };
    for (const file_extension of ['MP4', 'webm']) {
        assert.match(context.renderMessageAttachments([{ ...fixture, file_extension }]), /<video controls playsinline preload="none"/);
    }
    assert.doesNotMatch(context.renderMessageAttachments([{ ...fixture, file_extension: 'pdf' }]), /<video/);
});

test('push registration requires a real subscription response from the server', async () => {
    const source = asset('notification.js');
    let result = { success: true, raw: '<title>Sign in</title>' };
    const context = createContext({
        getCsrfToken: () => 'fixture',
        uiText: String,
        window: {},
        postForm: async (route, data) => {
            assert.equal(route, '/api/push/subscribe');
            assert.equal(data.endpoint, 'fixture');
            return result;
        },
    });
    runInContext(source.slice(source.indexOf('async function syncPushSubscriptionWithServer('),
        source.indexOf('async function unsubscribePushSubscriptionOnServer(')), context);
    const subscription = { endpoint: 'fixture', toJSON: () => ({ keys: { p256dh: 'key', auth: 'proof' } }) };
    await assert.rejects(context.syncPushSubscriptionWithServer(subscription), /push_subscription_save_failed/);
    result = { success: false, error: 'Provider disabled' };
    await assert.rejects(context.syncPushSubscriptionWithServer(subscription), /Provider disabled/);
    result = { success: true, csrf_token: 'renewed' };
    await context.syncPushSubscriptionWithServer(subscription);
    assert.equal(context.window.CSRF_TOKEN, 'renewed');
});

function element() {
    const classes = new Set();
    return {
        dataset: {}, style: {}, events: {},
        classList: {
            toggle(name, on) { on ? classes.add(name) : classes.delete(name); },
            add: (name) => classes.add(name),
            remove: (name) => classes.delete(name),
            contains: (name) => classes.has(name),
        },
        setAttribute() {},
        querySelectorAll: () => [],
        contains: () => false,
        addEventListener(name, listener) { this.events[name] = listener; },
    };
}

test('the empty notification rail stays hidden and waits two seconds after the final entry', () => {
    const elements = new Map();
    const timers = new Map();
    let time = 0;
    let timerId = 0;
    const tick = (milliseconds) => {
        time += milliseconds;
        for (const [id, timer] of Array.from(timers)) {
            if (timer.at > time) continue;
            timers.delete(id);
            timer.callback();
        }
    };
    const lookup = (id) => {
        if (!elements.has(id)) elements.set(id, element());
        return elements.get(id);
    };
    const context = createContext({
        document: { getElementById: lookup },
        window: { innerWidth: 1200, matchMedia: () => ({ matches: false, addEventListener() {} }) },
        toastHistory: [],
        toastExpirySweepTimeoutId: null,
        unreadNotificationCount: 0,
        clearTimeout: (id) => timers.delete(id),
        setTimeout(callback, delay) {
            const id = ++timerId;
            timers.set(id, { at: time + delay, callback });
            return id;
        },
        escapeHtml: String,
        uiText: String,
        syncMobileOverlayBackdrop() {},
    });
    runInContext(asset('notification.js'), context);
    context.formatToastTime = () => '';
    const panel = lookup('notification-history-panel');
    const hidden = () => panel.classList.contains('notification-history-empty');
    const setEntries = (entries) => { context.toastHistory = entries; context.renderToastHistory(); };
    const entry = { id: 1, message: 'Synthetic notification', kind: 'info' };

    context.bindNotificationHistory();
    assert.equal(hidden(), true);
    assert.equal(panel.inert, true, 'A hidden rail cannot be tabbed into');
    setEntries([entry]);
    assert.equal(hidden(), false);
    assert.equal(panel.inert, false);
    assert.equal(timers.size, 0, 'Persistent entries have no expiry timer');
    setEntries([]);
    tick(1999);
    assert.equal(hidden(), false);
    setEntries([entry]);
    tick(1);
    assert.equal(hidden(), false, 'A new entry cancels the pending hide');
    setEntries([]);
    tick(2000);
    assert.equal(hidden(), true);
    assert.equal(panel.inert, true, 'The rail is noninteractive as soon as it starts sliding out');
});

test('settings actions appear in accessible lists with an icon before each label', () => {
    const settings = readFileSync(new URL('../public_html/app/views/settings.php', import.meta.url), 'utf8');
    const lists = Array.from(settings.matchAll(/<ul\b[^>]*>([\s\S]*?)<\/ul>/g))
        .filter(match => /data-modal-open|base_url\(/.test(match[1]));
    assert.equal(lists.length, 4);
    for (const [, list] of lists) {
        const rows = Array.from(list.matchAll(/<li>\s*<(a|button)\b.*?>\s*<i\b[^>]*aria-hidden="true"[^>]*>/gs));
        assert.equal(rows.length, (list.match(/<li>/g) ?? []).length);
    }
});

test('only slow searches of messages and posts show the green progress indicator', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] });
    const bar = element();
    let respond;
    const origin = 'https://fixture.example.test';
    const context = createContext({
        window: {
            CAUSETTE_BASE_PATH: '/community',
            location: { origin, href: origin + '/community/' },
            fetch: () => new Promise(resolve => { respond = resolve; }),
        },
        document: {
            addEventListener() {},
            getElementById: (id) => id === 'app-top-progress' ? bar : element(),
        },
        URL, setTimeout, clearTimeout, setInterval, clearInterval,
    });
    const app = asset('app.js');
    runInContext(app.slice(0, app.indexOf('window.sendFriendRequest =')), context);
    context.handleServerConnectionSuccess = () => {};
    const finish = () => respond({ headers: { get: () => null } });
    const fetching = (path) => context.window.fetch(path);

    for (const path of ['/community/c/1234', '/community/notifications', '/community/api/users/search?q=a']) {
        const pending = fetching(path);
        t.mock.timers.tick(700);
        assert.notEqual(bar.dataset.loading, '1', path);
        finish();
        await pending;
    }

    const fast = fetching('/community/api/posts/search?q=fast');
    finish();
    await fast;
    t.mock.timers.tick(700);
    assert.notEqual(bar.dataset.loading, '1', 'A fast search does not flash');

    const slow = fetching('/community/api/messages/search?q=slow');
    t.mock.timers.tick(399);
    assert.notEqual(bar.dataset.loading, '1');
    t.mock.timers.tick(1);
    assert.equal(bar.dataset.loading, '1');
    finish();
    await slow;
    t.mock.timers.tick(180);
    assert.equal(bar.dataset.loading, '0');
});

test('changing poll units converts the current duration and keeps it in range', () => {
    const form = element();
    const unit = { ...element(), value: 'h' };
    const duration = { value: '24', max: '24', get valueAsNumber() { return Number(this.value); } };
    form.elements = { duration, duration_unit: unit };
    form.querySelector = () => element();
    const elements = Object.fromEntries(['modal', 'cancel', 'submit'].map(s => ['create-poll-' + s, element()]));
    elements['create-poll-form'] = form;
    const context = createContext({
        window: { CAUSETTE_POLL_LIMITS: { minOptions: 2, maxOptions: 10, maxHours: 24, maxDays: 60 } },
        document: { getElementById: (id) => elements[id] },
    });
    runInContext(asset('chat.js'), context);
    context.bindCreatePollModal();

    unit.value = 'd';
    unit.events.change();
    assert.deepEqual([duration.value, duration.max], ['1', '60']);
    duration.value = '60';
    unit.value = 'h';
    unit.events.change();
    assert.deepEqual([duration.value, duration.max], ['24', '24']);
});

test('group creation is available inside the shared desktop and mobile sidebar', () => {
    const layout = readFileSync(new URL('../public_html/app/views/layouts/main.php', import.meta.url), 'utf8');
    const sidebarStart = layout.indexOf('id="app-sidebar"');
    const sidebar = layout.slice(sidebarStart, layout.indexOf('</aside>', sidebarStart));
    assert.ok(/<button[^>]+data-app-action="create-group-chat"/.test(sidebar));
});

test('repeated activation of group creation submits one request', async () => {
    let calls = 0;
    let complete;
    const navigations = [];
    const context = createContext({
        window: {},
        postForm() {
            calls++;
            return new Promise(resolve => { complete = resolve; });
        },
        getCsrfToken: () => 'fixture',
        formatNumber: () => '1234-5678-9012-3456',
    });
    runInContext(asset('chat.js'), context);
    context.navigateWithFallback = (path) => navigations.push(path);
    const first = context.createGroupChat();
    const repeated = context.createGroupChat();
    const count = calls;
    complete({ success: true, chat_number: '1234567890123456' });
    await repeated;
    if (count === 1) await first;
    assert.equal(count, 1);
    assert.deepEqual(navigations, ['/c/1234-5678-9012-3456?prompt_group_name=1']);
});
