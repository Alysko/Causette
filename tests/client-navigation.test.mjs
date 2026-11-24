import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createContext, runInContext } from 'node:vm';

const app = readFileSync(new URL('../public_html/assets/js/app.js', import.meta.url), 'utf8');
const origin = 'https://fixture.example.test';
const firstChat = '/community/c/0000-0000-0000-0001';
const secondChat = '/community/c/0000-0000-0000-0002';

function navigationFixture() {
    const requests = [];
    const rendered = [];
    const attributes = new Map();
    const location = new URL(`${origin}/community/`);
    const main = {
        setAttribute: (key, value) => attributes.set(key, value),
        removeAttribute: (key) => attributes.delete(key),
    };
    const status = { textContent: '', dataset: { chatLabel: 'Opening conversation…', pageLabel: 'Loading…' } };
    const context = createContext({
        window: { location, CAUSETTE_BASE_PATH: '/community', CURRENT_USER_ID: 1 },
        document: {
            addEventListener() {},
            dispatchEvent() {},
            getElementById: id => ({ 'app-main-content': main, 'app-route-status': status })[id] ?? null,
        },
        CustomEvent: class {},
        AbortController, URL, setTimeout, clearTimeout, clearInterval,
        fetch: (url, options) => new Promise(resolve => {
            requests.push({ url, signal: options.signal, resolve });
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
    return { context, requests, rendered, attributes, location, status, reply };
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
