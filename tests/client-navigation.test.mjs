import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { setImmediate } from 'node:timers/promises';
import { createContext, runInContext } from 'node:vm';

const app = readFileSync(new URL('../public_html/assets/js/app.js', import.meta.url), 'utf8');
const origin = 'https://fixture.example.test';
const firstChat = '/community/c/0000-0000-0000-0001';
const secondChat = '/community/c/0000-0000-0000-0002';

function navigationFixture({ withGroup = false } = {}) {
    const requests = [];
    const rendered = [];
    const toasts = [];
    const attributes = new Map();
    let currentLocation = new URL(`${origin}/community/`);
    const location = {
        get href() { return currentLocation.href; },
        set href(value) { currentLocation = new URL(value, currentLocation); },
        get origin() { return currentLocation.origin; },
        get pathname() { return currentLocation.pathname; },
        get search() { return currentLocation.search; },
        get hash() { return currentLocation.hash; },
    };
    const content = [];
    const main = {
        setAttribute: (key, value) => attributes.set(key, value),
        removeAttribute: (key) => attributes.delete(key),
        classList: { add() {}, remove() {} },
        append(node) {
            node.parentElement = main;
            content.push(node);
        },
    };
    const chat = withGroup ? {
        get dataset() {
            return {
                chatType: 'group', isGroupMember: '1', currentUserId: '1',
                chatNumber: location.pathname.split('/').at(-1).replaceAll('-', ''),
            };
        },
        querySelector(selector) {
            if (selector === '#chat-title') return { textContent: 'QA group' };
            return { querySelectorAll: () => [{
                querySelector: selector => selector === '.js-message-content'
                    ? { dataset: { rawContent: 'A message in ' + location.pathname } }
                    : null,
            }] };
        },
    } : null;
    const status = { textContent: '', dataset: { chatLabel: 'Opening conversation…', pageLabel: 'Loading…', errorLabel: 'Unable to open this page' } };
    const context = createContext({
        window: {
            location, CAUSETTE_BASE_PATH: '/community', CURRENT_USER_ID: 1,
            history: { replaceState: (_state, _title, url) => { location.href = url; } },
        },
        document: {
            addEventListener() {},
            dispatchEvent() {},
            getElementById: id => ({ 'app-main-content': main, 'app-route-status': status, 'chat-view': chat })[id] ?? null,
            createElement: tagName => ({
                tagName, children: [], append(...nodes) { this.children.push(...nodes); },
                remove() { content.splice(content.indexOf(this), 1); },
            }),
        },
        CustomEvent: class {},
        AbortController, URL, setTimeout, clearTimeout, clearInterval,
        showToast: (...arguments_) => toasts.push(arguments_),
        decodeStoredMentionsToPlainText: String,
        updateSidebarCurrentPage() {},
        fetch: (url, options) => new Promise(resolve => {
            requests.push({ url, signal: options.signal, headers: options.headers, resolve });
        }),
    });
    runInContext(app.slice(0, app.indexOf('window.sendFriendRequest =')), context);
    context.replaceMainContentFromHtml = (html, _policy, target, options) => {
        options.beforeReplace?.();
        rendered.push(html);
        if (options.updateHistory) location.href = target.href;
        return true;
    };
    context.init = () => {};
    const reply = (request, text = async () => request.url) => request.resolve({
        ok: true,
        headers: { get: () => null },
        text,
    });
    return { context, requests, rendered, attributes, location, status, reply, toasts, content };
}

test('a group click acknowledges loading before the server responds', async () => {
    const { context, requests, attributes, status, reply } = navigationFixture();
    const pending = context.navigateApp(firstChat);
    const duringRequest = { label: status.textContent, busy: attributes.get('aria-busy') };
    reply(requests[0]);
    await pending;
    assert.deepEqual(duringRequest, { label: 'Opening conversation…', busy: 'true' });
    assert.equal(status.textContent, '');
    assert.equal(attributes.has('aria-busy'), false);
});

test('conversation navigation requests the main fragment under the installed base path', async () => {
    const { context, requests, reply } = navigationFixture();
    const opening = context.navigateApp(firstChat);
    const chatRequest = requests[0];
    reply(chatRequest);
    await opening;
    const settings = context.navigateApp('/community/settings');
    const settingsRequest = requests[1];
    reply(settingsRequest);
    await settings;
    assert.equal(chatRequest.headers['X-Causette-View'], 'main');
    assert.equal(settingsRequest.headers['X-Causette-View'], undefined);
});

test('the latest requested group wins even when the previous response arrives afterward', async () => {
    const { context, requests, rendered, location, status, reply } = navigationFixture();
    const first = context.navigateApp(firstChat);
    const second = context.navigateApp(secondChat);
    if (requests.length !== 2) {
        reply(requests[0]);
        await Promise.all([first, second]);
        assert.fail('The second click did not start a request');
    }
    assert.equal(requests[0].signal.aborted, true);
    reply(requests[1]);
    await second;
    reply(requests[0]);
    await first;
    assert.deepEqual(rendered, [secondChat]);
    assert.equal(location.pathname, secondChat);
    assert.equal(status.textContent, '');
});

test('an automatic view refresh cannot replace an explicit navigation', async () => {
    const { context, requests, rendered, reply } = navigationFixture();
    const opening = context.navigateApp(firstChat);
    const refresh = context.reloadAppView({ source: 'chat-settings' });
    reply(requests[0]);
    assert.equal(await refresh, true, 'The caller should not fall back to a full reload');
    await opening;
    assert.equal(requests.length, 1);
    assert.deepEqual(rendered, [firstChat]);
});

test('a previous response that is already reading its body cannot replace the new group', async () => {
    const { context, requests, rendered, attributes, reply } = navigationFixture();
    const first = context.navigateApp(firstChat);
    let finishBody;
    await new Promise(bodyStarted => {
        reply(requests[0], () => new Promise(resolve => {
            finishBody = resolve;
            bodyStarted();
        }));
    });
    const second = context.navigateApp(secondChat);
    finishBody(firstChat);
    await first;
    assert.deepEqual(rendered, [], 'The superseded body cannot be displayed');
    assert.equal(attributes.get('aria-busy'), 'true');
    reply(requests[1]);
    await second;
    assert.deepEqual(rendered, [secondChat]);
});

test('temporary throttling keeps the current group and allows a new attempt', async () => {
    const { context, requests, rendered, location, status, reply, toasts } = navigationFixture();
    const first = context.navigateApp(firstChat);
    reply(requests[0]);
    await first;

    const throttled = context.navigateApp(secondChat, { source: 'click' });
    requests[1].resolve({ ok: false, status: 429, redirected: false });
    assert.equal(await throttled, false);
    assert.equal(location.pathname, firstChat);
    assert.deepEqual(rendered, [firstChat]);
    assert.equal(status.textContent, '');
    assert.deepEqual(toasts, [['Unable to open this page', 'error']]);

    const retried = context.navigateApp(secondChat, { source: 'click' });
    reply(requests[2]);
    assert.equal(await retried, true);
    assert.equal(location.pathname, secondChat);
});

test('expired sessions still leave the current view through normal navigation', async () => {
    const { context, requests, location, toasts } = navigationFixture();
    const navigation = context.navigateApp(firstChat);
    requests[0].resolve({ ok: true, status: 200, redirected: true });
    assert.equal(await navigation, true);
    assert.equal(location.pathname, firstChat);
    assert.deepEqual(toasts, []);
});

test('a failed history navigation keeps the address aligned with the displayed group', async () => {
    const { context, requests, rendered, location, reply } = navigationFixture();
    for (const path of [firstChat, secondChat]) {
        const navigation = context.navigateApp(path);
        reply(requests.at(-1));
        await navigation;
    }
    location.href = firstChat;
    const back = context.navigateApp(firstChat, { source: 'popstate', updateHistory: false, force: true });
    requests[2].resolve({ ok: false, status: 503, redirected: false });
    assert.equal(await back, false);
    assert.equal(location.pathname, secondChat);
    assert.deepEqual(rendered, [firstChat, secondChat]);
});

async function revisitFirstGroup() {
    const fixture = navigationFixture({ withGroup: true });
    for (const path of [firstChat, secondChat]) {
        const navigation = fixture.context.navigateApp(path);
        fixture.reply(fixture.requests.at(-1));
        await navigation;
    }
    const navigation = fixture.context.navigateApp(firstChat);
    const [accessRequest, mainRequest] = fixture.requests.slice(2);
    assert.equal(accessRequest.headers['X-Causette-View'], 'group-access');
    assert.equal(mainRequest.headers['X-Causette-View'], 'main');
    return { ...fixture, navigation, accessRequest, mainRequest };
}

test('a revisited group paints its cached messages before the fresh view only with a fresh grant', async () => {
    const fixture = await revisitFirstGroup();
    assert.equal(fixture.content.length, 0);
    fixture.accessRequest.resolve({ status: 204, headers: { get: () => '1' } });
    await setImmediate();
    const [preview] = fixture.content;
    assert.equal(preview.children[1].children[0].children[0].textContent, 'A message in ' + firstChat);
    fixture.reply(fixture.mainRequest, async () => 'Fresh group');
    await fixture.navigation;
    assert.deepEqual(fixture.content, []);
    assert.equal(fixture.rendered.at(-1), 'Fresh group');
});

test('a denied grant or a grant for another account cannot paint cached messages', async () => {
    for (const grant of [{ status: 404, viewer: null }, { status: 204, viewer: '2' }]) {
        const fixture = await revisitFirstGroup();
        fixture.accessRequest.resolve({ status: grant.status, headers: { get: () => grant.viewer } });
        await setImmediate();
        assert.equal(fixture.content.length, 0);
        fixture.mainRequest.resolve({ ok: false, status: 404, redirected: false });
        await fixture.navigation;
        assert.equal(fixture.content.length, 0);
    }
});

test('a late grant cannot replace the group the user has returned to', async () => {
    const fixture = await revisitFirstGroup();
    assert.equal(await fixture.context.navigateApp(secondChat), true);
    assert.equal(fixture.accessRequest.signal.aborted, true);
    fixture.accessRequest.resolve({ status: 204, headers: { get: () => '1' } });
    fixture.reply(fixture.mainRequest);
    await fixture.navigation;
    assert.deepEqual(fixture.content, []);
    assert.equal(fixture.location.pathname, secondChat);
});
