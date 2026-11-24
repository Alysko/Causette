import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createContext, runInContext, runInNewContext } from 'node:vm';

const app = readFileSync(new URL('../public_html/assets/js/app.js', import.meta.url), 'utf8');
const notifications = readFileSync(new URL('../public_html/assets/js/notification.js', import.meta.url), 'utf8');
const worker = readFileSync(new URL('../public_html/service-worker.js', import.meta.url), 'utf8');
const origin = 'https://fixture.example.test';

function browserContext(basePath, serviceWorker = {}) {
    const requests = [];
    const context = createContext({
        window: { CAUSETTE_BASE_PATH: basePath, location: { origin } },
        document: { addEventListener() {} },
        navigator: { serviceWorker },
        URL,
        URLSearchParams,
        setTimeout,
        clearTimeout,
        fetch: async (url) => {
            requests.push(url);
            return { text: async () => '{"success":true}' };
        },
    });
    // The layout loads feature scripts before the final global bindings.
    runInContext(notifications, context);
    runInContext(app.slice(0, app.indexOf('window.sendFriendRequest =')), context);
    return { context, requests };
}

test('logical API paths receive the mount prefix before fetch and URL handling', async () => {
    const { context, requests } = browserContext('/api');
    await context.postForm('/api/chats/sidebar-custom-label', { label: 'Fixture' });
    assert.deepEqual(requests, ['/api/api/chats/sidebar-custom-label']);
    assert.equal(context.appRoute('/api/api/chats'), '/api/chats');
    assert.equal(context.appRoute('/api-sibling/api/chats'), null);
    assert.equal(context.appPath('/c/0000-0000-0000-0008?msg=8#message-8'), '/api/c/0000-0000-0000-0008?msg=8#message-8');
    const root = browserContext('').context;
    assert.equal(root.appPath('/api/chats'), '/api/chats');
});

test('localized group application notifications link to the mounted group or dashboard', () => {
    const { context } = browserContext('/community');
    for (const [type, link] of [
        ['group_join_request', '/c/0000-0000-0000-0008'],
        ['group_join_approved', '/c/0000-0000-0000-0008'],
        ['group_join_denied', '/'],
    ]) {
        assert.equal(context.getNotificationAction({ metadata: { type, title: 'Demande d’adhésion', link } })?.href, `/community${link}`);
    }
});

test('browser registers and waits for the worker belonging to the configured mount', async () => {
    const calls = [];
    const registration = { active: { state: 'activated' } };
    const { context } = browserContext('/api', {
        register(script, options) {
            calls.push([script, options?.scope]);
            return Promise.resolve(registration);
        },
        // An old root worker can still be ready while the mounted worker is installing.
        ready: Promise.resolve({ scope: `${origin}/` }),
    });
    assert.equal(await context.getServiceWorkerRegistration(), registration);
    assert.deepEqual(calls, [['/api/service-worker.js', '/api/']]);
});

test('a ready worker for another scope cannot make a newly installed worker active', async () => {
    let onStateChange;
    const installing = {
        state: 'installing',
        addEventListener: (_event, callback) => { onStateChange = callback; },
        removeEventListener: () => { onStateChange = null; },
    };
    const registration = { active: null, installing };
    const { context } = browserContext('/community', {
        register: async () => registration,
        ready: Promise.resolve({ scope: `${origin}/` }),
    });
    let completed = false;
    const result = context.getServiceWorkerRegistration().then((worker) => { completed = true; return worker; });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(completed, false);
    installing.state = 'activated';
    registration.active = installing;
    onStateChange();
    assert.equal(await result, registration);
    assert.equal(onStateChange, null);
});

function workerFixture({ basePath = '/community/', language = 'fr-CA', clients = [], response = null } = {}) {
    const handlers = new Map();
    const notifications = [];
    const requests = [];
    const opened = [];
    const self = {
        navigator: { language },
        location: { origin },
        registration: {
            scope: `${origin}${basePath}`,
            showNotification: async (title, options) => notifications.push({ title, options }),
        },
        addEventListener: (name, listener) => handlers.set(name, listener),
        clients: {
            matchAll: async () => clients,
            openWindow: async (url) => opened.push(url),
        },
    };
    runInNewContext(worker, {
        self,
        URL,
        fetch: async (url) => {
            requests.push(url);
            return { ok: response !== null, json: async () => response };
        },
    });

    async function dispatch(name, event = {}) {
        let completion;
        handlers.get(name)({ ...event, waitUntil: (promise) => { completion = promise; } });
        await completion;
    }

    return { dispatch, notifications, requests, opened };
}

test('mounted worker fetches notifications and displays local icons inside its scope', async () => {
    const result = workerFixture({
        basePath: '/api/',
        response: { notifications: [{ title: 'Message du groupe', message: 'Bonjour', read: 0, link: '/c/8', type: 'message' }] },
    });
    await result.dispatch('push');
    assert.deepEqual(result.requests, [`${origin}/api/notifications`]);
    assert.equal(result.notifications[0].title, 'Message du groupe');
    assert.equal(result.notifications[0].options.icon, `${origin}/api/assets/img/favicon.png`);
    assert.equal(result.notifications[0].options.badge, `${origin}/api/assets/img/favicon.png`);
});

test('background fallback follows the browser language when there is no active account to supply text', async () => {
    const result = workerFixture();
    await result.dispatch('push');
    assert.equal(result.notifications[0].title, 'Nouveau message');
    assert.equal(result.notifications[0].options.body, 'Vous avez une nouvelle notification dans Causette.');
});

test('background delivery recovers when the push contains a JSON null payload', async () => {
    const result = workerFixture();
    await result.dispatch('push', { data: { json: () => null } });
    assert.equal(result.notifications[0].title, 'Nouveau message');
});

test('notification clicks preserve the mounted query and only reuse a Causette window within the scope', async () => {
    const actions = [];
    const windowClient = (name, url) => ({
        url,
        async navigate(target) { actions.push([name, 'navigate', target]); return this; },
        focus: async () => actions.push([name, 'focus']),
    });
    const result = workerFixture({
        clients: [windowClient('sibling', `${origin}/community-other/`), windowClient('app', `${origin}/community/`)],
    });
    await result.dispatch('notificationclick', {
        notification: { close() {}, data: { type: 'poke', link: '/c/8?call_id=4#call' } },
    });
    assert.deepEqual(actions, [
        ['app', 'navigate', `${origin}/community/c/8?call_id=4&join_call=1&join_source=poke#call`],
        ['app', 'focus'],
    ]);
    assert.deepEqual(result.opened, []);
});

test('worker provides a scoped landing page for notices without an app URL', async () => {
    const result = workerFixture();
    await result.dispatch('notificationclick', {
        notification: { close() {}, data: { link: 'https://not-the-app.example/notice' } },
    });
    assert.deepEqual(result.opened, [`${origin}/community/`]);
});
