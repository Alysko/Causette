import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createContext, runInContext } from 'node:vm';

const notifications = readFileSync(new URL('../public_html/assets/js/notification.js', import.meta.url), 'utf8');
const users = readFileSync(new URL('../public_html/assets/js/user.js', import.meta.url), 'utf8');

test('permission badges use the account language without changing their state', () => {
    const labels = {
        permission_allowed: 'Autorisé',
        permission_ask_first: 'Demander d’abord',
        permission_blocked: 'Bloqué',
        permission_unknown: 'Inconnu',
    };
    const context = createContext({ window: {}, uiText: (key) => labels[key] });
    runInContext(notifications, context);

    const cases = [
        ['granted', 'granted', labels.permission_allowed],
        ['prompt', 'prompt', labels.permission_ask_first],
        ['denied', 'denied', labels.permission_blocked],
        [null, 'info', labels.permission_unknown],
    ];
    for (const [state, kind, label] of cases) {
        const badge = context.mapPermissionStateToBadge(state);
        assert.equal(badge.kind, kind);
        assert.equal(badge.label, label);
    }
});

test('a permissions test recovers the localized button label when the prior label is empty', async () => {
    let onClick;
    const button = {
        disabled: false,
        textContent: '',
        dataset: { browserPermissionTest: 'camera' },
        addEventListener(name, handler) { if (name === 'click') onClick = handler; },
    };
    const context = createContext({
        document: {
            getElementById: () => ({}),
            querySelectorAll: () => [button],
        },
        window: {},
        uiText: (key) => ({ testing: 'Test en cours…', test_permission: 'Tester' })[key],
    });
    runInContext(notifications, context);
    context.testBrowserPermission = async () => {};
    context.updateBrowserPermissionsStatuses = async () => {};
    context.bindBrowserPermissionChecks();
    await onClick();
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Tester');
});

test('native username search validation agrees with the localized feedback', async () => {
    const validation = [];
    const feedback = [];
    const label = 'Utilisez uniquement des lettres, des chiffres et des tirets.';
    const input = {
        value: 'nom invalide',
        setCustomValidity: (message) => validation.push(message),
        reportValidity() {},
    };
    const context = createContext({
        document: {
            getElementById: (id) => ({
                'user-search-input': input,
                'user-search-results': {},
            })[id],
        },
        uiText: (key) => key === 'use_only_letters_numbers_and_dashes' ? label : key,
        showToast: (message) => feedback.push(message),
    });
    runInContext(users, context);

    await context.searchUsers({ preventDefault() {} });

    assert.deepEqual(validation, [label]);
    assert.deepEqual(feedback, [label]);
});

test('post and message searches use localized progress and empty results', async () => {
    const results = { innerHTML: '', isConnected: true };
    const labels = {
        search_pending: 'Recherche en cours…',
        search_no_posts: 'Aucune publication trouvée.',
        search_no_messages: 'Aucun message trouvé.',
    };
    const context = createContext({
        document: { getElementById: () => results },
        AbortController, URLSearchParams,
        fetch: async () => ({ ok: true, json: async () => ({}) }),
        appPath: (path) => path,
        uiText: (key) => labels[key],
        escapeHtml: (label) => label,
    });
    runInContext(users, context);

    for (const [method, label] of [
        [context.searchPostsPage, labels.search_no_posts],
        [context.searchMessagesPage, labels.search_no_messages],
    ]) {
        const pending = method('test', 1);
        assert.match(results.innerHTML, new RegExp(labels.search_pending));
        await pending;
        assert.match(results.innerHTML, new RegExp(label));
    }
});

test('the newest search keeps its results when an older response arrives late', async () => {
    const results = { innerHTML: '', isConnected: true };
    const requests = [];
    const context = createContext({
        document: { getElementById: () => results },
        AbortController, URLSearchParams,
        fetch: (url, {signal}) => new Promise(resolve => requests.push({url, signal, resolve})),
        appPath: path => path,
        uiText: key => key,
        escapeHtml: text => text,
    });
    runInContext(users, context);
    const older = context.searchMessagesPage('release', 1, true);
    const latest = context.searchMessagesPage('', 1, true);
    assert.match(requests[1].url, /[?&]saved=1/);
    assert.ok(requests[0].signal.aborted);
    requests[1].resolve({ok: true, json: async () => ({messages: []})});
    await latest;
    requests[0].resolve({ok: true, json: async () => ({messages: []})});
    await older;
    assert.match(results.innerHTML, /no_saved_messages/);
});

test('user search shows the appropriate localized direct message or friend action', () => {
    const labels = {
        search_open_direct_message: 'Message direct',
        search_add_friend: 'Ajouter comme ami',
    };
    const context = createContext({
        appPath: (path) => `/discussion${path}`,
        uiText: (key) => labels[key],
        escapeHtml: (label) => label,
    });
    runInContext(users, context);

    const user = { formatted_user_number: '0000-0000-0000-0007' };
    assert.match(context.renderSearchResultAction(user), /Ajouter comme ami/);
    const direct = context.renderSearchResultAction({
        ...user,
        friendship_status: 'accepted',
        personal_chat_number: '0000-0000-0000-0008',
    });
    assert.match(direct, /Message direct/);
    assert.match(direct, /href="\/discussion\/c\/0000-0000-0000-0008"/);
});
