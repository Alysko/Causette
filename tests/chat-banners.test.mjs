import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createContext, runInContext } from 'node:vm';

const source = readFileSync(new URL('../public_html/assets/js/chat.js', import.meta.url), 'utf8');

function node(tagName) {
    const classes = new Set();
    return {
        tagName,
        dataset: {},
        style: {},
        children: [],
        value: '',
        get textContent() {
            return this.value + this.children.map((child) => child.textContent ?? child).join('');
        },
        set textContent(value) {
            this.value = value;
            this.children = [];
        },
        set innerHTML(_) {
            assert.fail('Banner content must be added through DOM nodes');
        },
        classList: {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
            toggle: (name, force) => force ? classes.add(name) : classes.delete(name),
        },
        appendChild(child) {
            this.children.push(child);
        },
        append(...children) {
            this.children.push(...children);
        },
        getAttribute(name) {
            return this[name];
        },
        querySelector() {
            return null;
        },
        replaceChildren(...children) {
            this.value = '';
            this.children = children;
        },
        setAttribute(name, value) {
            this[name] = value;
        },
        removeAttribute(name) {
            delete this[name];
        },
    };
}

function fixture(i18n = {}) {
    const elements = new Map();
    const element = (id) => {
        if (!elements.has(id)) elements.set(id, node('div'));
        return elements.get(id);
    };
    const context = createContext({
        URL,
        appPath: (path) => path,
        window: { CAUSETTE_CHAT_I18N: i18n, CAUSETTE_POLL_LIMITS: { minOptions: 2, maxOptions: 10 } },
        currentChat: { id: 1, type: 'group', is_group_member: true },
        currentUserId: 7,
        document: {
            getElementById: element,
            createDocumentFragment: () => node('#fragment'),
            createElement: node,
            createTextNode: (text) => text,
        },
        getProfileUrlByUserNumber: (number) => '/u/' + number,
        escapeHtml: (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
        formatCompactMessageTimestamp: (value) => value,
        formatTimelineTimestamp: (value) => value,
        normalizeChatType: (type) => type,
    });
    runInContext(source, context);
    context.canCurrentUserPinMessages = () => true;
    context.findOpenMojiForGrapheme = (value) => value === '☕' ? { url: '/emojis/2615.svg' } : null;
    return { context, element };
}

test('renaming a group and removing a pin update the view without duplicate success toasts', async () => {
    const { context, element } = fixture();
    element('rename-chat-input').value = 'Nouveau groupe';
    const updates = [];
    const notices = [];
    context.getCsrfToken = () => 'fixture';
    context.uiText = (key) => key;
    context.uiActionError = (_error, fallback) => fallback;
    context.showToast = (text, type) => notices.push([text, type]);
    context.reloadCurrentView = async () => updates.push('title');
    context.renderPinnedMessageBanner = (pin) => updates.push(pin);
    context.postForm = async () => ({ success: true });
    await context.renameCurrentChat();
    await context.unpinCurrentChatMessage();
    assert.deepEqual(updates, ['title', null]);
    assert.deepEqual(notices, []);

    context.postForm = async () => ({ success: false });
    await context.renameCurrentChat();
    assert.deepEqual(notices, [['unable_to_rename_chat', 'error']]);
});

test('sending previews the draft immediately, delays the status, and keeps the draft on failure', async () => {
    const { context, element } = fixture();
    const timeline = element('messages');
    const input = element('message-input');
    input.value = '   Hello & goodbye   ';
    input.readOnly = false;
    context.pendingAttachments = [];
    context.getCsrfToken = () => 'fixture';
    context.uiText = (key) => key;
    context.uiActionError = (_error, fallback) => fallback;
    context.isCurrentChatView = () => true;
    context.document.createElement = (name) => Object.assign(node(name), {
        remove() { this.removed = true; },
    });
    const notices = [];
    let finish;
    let requests = 0;
    const timers = new Map();
    const fired = [];
    context.setTimeout = (callback, delay) => { timers.set(delay, callback); return delay; };
    context.clearTimeout = (timer) => timers.delete(timer);
    const advance = (ms) => {
        for (const [delay, callback] of timers) {
            if (delay > ms) continue;
            timers.delete(delay);
            fired.push(delay);
            callback();
        }
    };
    context.showToast = (text, type) => notices.push([text, type]);
    context.postForm = async () => { requests++; return new Promise(resolve => { finish = resolve; }); };
    const sending = context.submitChatMessage(element('message-form'));
    assert.equal(timeline.children[0].textContent, 'Hello & goodbye');
    advance(999);
    assert.equal(timeline.children[0].textContent, 'Hello & goodbye');
    assert.equal(input.readOnly, true);
    await context.submitChatMessage(element('message-form'));
    assert.equal(requests, 1);
    advance(1000);
    assert.deepEqual(fired, [1000]);
    assert.equal(timeline.children[0].textContent, 'Hello & goodbyemessage_sending');
    finish({ success: true, raw: '<title>Sign in</title>' });
    await sending;
    assert.equal(timeline.children[0].removed, true);
    assert.equal(input.value, '   Hello & goodbye   ');
    assert.deepEqual(notices, [['failed_to_send_message', 'error']]);

    context.postForm = async (_route, _data, options) => {
        assert.equal(options?.timeoutMs, 15000);
        throw Object.assign(new Error('Synthetic timeout'), { name: 'TimeoutError' });
    };
    await context.submitChatMessage(element('message-form'));
    const alert = element('message-send-error');
    assert.equal(alert.textContent, 'message_send_timeout');
    assert.equal(alert.classList.contains('message-send-timeout'), true);
    assert.equal(alert.classList.contains('hidden'), false);
    assert.equal(input.value, '   Hello & goodbye   ');
    assert.equal(input.readOnly, false);
    assert.deepEqual(notices, [['failed_to_send_message', 'error']]);
    assert.equal(timers.size, 0);
});

test('pinned messages use text nodes and preserve supported rich content', () => {
    const { context, element } = fixture();
    context.renderPinnedMessageBanner({
        id: 4,
        chat_id: 1,
        content: '<mark>Thé</mark> & https://example.test/tea\n☕ @[0000000000000007|tester]',
    });
    const content = element('pinned-message-content');
    const children = content.children[0].children;
    assert.equal(content.textContent, '<mark>Thé</mark> & https://example.test/tea @tester');
    assert.deepEqual(Array.from(children.filter((child) => child.tagName).map((child) => child.tagName)),
        ['a', 'br', 'img', 'a']);
    assert.equal(children.find((child) => child.tagName === 'img').src, '/emojis/2615.svg');
    assert.equal(children.find((child) => child.tagName === 'a').rel, 'noopener noreferrer');
    context.renderPinnedMessageBanner(null);
    assert.equal(content.textContent, '');
    assert.equal(element('pinned-message-goto').disabled, true);
});

test('ArrowUp in the empty composer opens the newest eligible own message', () => {
    const { context, element } = fixture();
    const composer = element('message-input');
    const messages = element('messages');
    const listeners = {};
    const opened = [];
    let editing = false;
    const message = (id, user, { bot = false, editable = true } = {}) => {
        const button = { click() { opened.push(id); } };
        return {
            dataset: { messageUserId: String(user) },
            querySelector: (selector) => ({
                '[data-bot-badge]': bot ? {} : null,
                '.js-edit-link': editable ? button : null,
            })[selector],
        };
    };
    const rows = [message(1, 7), message(2, 7), message(3, 8), message(4, 7, { bot: true })];
    messages.querySelectorAll = () => rows;
    messages.querySelector = () => editing ? {} : null;
    messages.addEventListener = () => {};
    composer.addEventListener = (name, handler) => { listeners[name] = handler; };
    context.bindMessageEditAndDelete();
    const press = (options = {}) => {
        let prevented = false;
        listeners.keydown?.({
            key: 'ArrowUp', preventDefault() { prevented = true; }, ...options,
        });
        return prevented;
    };

    assert.equal(press(), true);
    assert.deepEqual(opened, [2]);
    for (const option of ['isComposing', 'shiftKey', 'ctrlKey', 'altKey', 'metaKey', 'repeat', 'defaultPrevented']) {
        assert.equal(press({ [option]: true }), false, option);
    }
    composer.value = 'Brouillon';
    assert.equal(press(), false);
    composer.value = '';
    editing = true;
    assert.equal(press(), false);
    editing = false;
    rows.push(message(5, 7, { editable: false }));
    assert.equal(press(), false, 'Do not silently edit an older message when the last one is locked');
    assert.deepEqual(opened, [2]);
});

test('the unread sidebar filter persists and tracks updated unread counts for all chat types', () => {
    const { context, element } = fixture();
    const list = element('chat-sidebar-list');
    let stored = 'unread';
    context.window.localStorage = {
        getItem: () => stored,
        setItem: (_, value) => { stored = value; },
    };
    list.contains = () => false;
    const toggle = element('chat-sidebar-mode-toggle');
    const unreadButton = node('button');
    unreadButton.dataset.chatSidebarMode = 'unread';
    toggle.querySelectorAll = () => [unreadButton];
    context.HTMLElement = Object;
    context.buildSidebarChatNode = (chat) => String(chat.chat_number);
    context.uiText = (key) => ({ no_unread_chats: 'Aucune conversation non lue' })[key] ?? key;
    runInContext(`
        sidebarMode = getStoredSidebarMode();
        sidebarChatsCache = [
            { chat_number: 1, type: 'group', unread_count: 0 },
            { chat_number: 2, type: 'personal', unread_count: '2' },
            { chat_number: 3, type: 'group', unread_count: 1 },
        ];
        renderSidebarList();
    `, context);
    assert.equal(unreadButton['aria-pressed'], 'true');
    assert.deepEqual(Array.from(list.children), ['2', '3']);

    runInContext(`
        sidebarChatsCache[1].unread_count = 0;
        renderSidebarList();
    `, context);
    assert.deepEqual(Array.from(list.children), ['3']);
    runInContext(`
        sidebarChatsCache[2].unread_count = 0;
        setStoredSidebarMode(sidebarMode);
        renderSidebarList();
    `, context);
    assert.equal(stored, 'unread');
    assert.equal(list.textContent, 'Aucune conversation non lue');
});

test('a sidebar refresh keeps existing group links until the displayed list changes', () => {
    const { context, element } = fixture();
    const list = element('chat-sidebar-list');
    list.contains = () => false;
    element('chat-sidebar-mode-toggle').querySelectorAll = () => [];
    context.uiText = String;
    context.buildSidebarChatNode = chat => ({ number: chat.chat_number, unread: chat.unread_count });

    const render = (unread) => {
        runInContext(`sidebarChatsCache = [{ chat_number: '1', type: 'group', unread_count: ${unread} }]; renderSidebarList();`, context);
        return list.children[0];
    };

    const firstLink = render(0);
    assert.equal(render(0), firstLink, 'An unchanged response must not detach a group link');
    const updatedLink = render(1);
    assert.notEqual(updatedLink, firstLink);
    assert.equal(updatedLink.unread, 1);
});

test('sidebar filters label conversation types only when the list can contain both', () => {
    const { context, element } = fixture();
    const list = element('chat-sidebar-list');
    list.contains = () => false;
    element('chat-sidebar-mode-toggle').querySelectorAll = () => [];
    context.window.location = { pathname: '/' };
    context.formatNumber = String;
    context.uiText = (key) => ({sidebar_group: 'Groupe', sidebar_direct: 'MP'})[key] ?? key;
    runInContext(`sidebarChatsCache = [
        { chat_number: 1, chat_title: 'Alpha', type: 'group' },
        { chat_number: 2, chat_title: 'Camille', type: 'personal' },
    ]; renderSidebarList();`, context);
    assert.match(list.textContent, /AlphaGroupe/);
    assert.match(list.textContent, /CamilleMP/);
    runInContext("sidebarMode = 'group'; renderSidebarList();", context);
    assert.equal(list.children.length, 1);
    assert.doesNotMatch(list.textContent, /Groupe/);
    runInContext("sidebarMode = 'pm'; renderSidebarList();", context);
    assert.equal(list.children.length, 1);
    assert.doesNotMatch(list.textContent, /MP/);
});

test('sidebar and live typing text follow the selected language', () => {
    const { context, element } = fixture();
    context.window.location = { pathname: '/' };
    context.formatNumber = Number;
    context.uiLanguage = () => 'fr';
    context.uiText = (key, values = {}) => ({
        you: 'Vous',
        sidebar_chat_number: `Conversation ${values.number}`,
        sidebar_custom_empty: `Aucune conversation dans « ${values.category} »`,
        typing_one: `${values.name} écrit`,
        typing_many: `${values.names} écrivent`,
    })[key] ?? key;
    const row = context.buildSidebarChatNode({
        chat_number: '42', type: 'personal', last_message: 'Hello!', last_message_user_id: 7,
    });
    assert.match(row.textContent, /Conversation 42.*Vous: Hello!/);

    const list = element('chat-sidebar-list');
    list.contains = () => false;
    element('chat-sidebar-mode-toggle').querySelectorAll = () => [];
    runInContext("sidebarMode = 'custom'; sidebarCustomLabel = 'Équipe'; renderSidebarList();", context);
    assert.equal(list.textContent, 'Aucune conversation dans « Équipe »');

    context.renderTypingIndicator([{ username: 'Lina' }]);
    assert.equal(element('typing-indicator').textContent, 'Lina écrit...');
    context.renderTypingIndicator([{ username: 'Lina' }, { username: 'Sam <3' }]);
    assert.equal(element('typing-indicator').textContent, 'Lina et Sam <3 écrivent...');
});

test('a bot message is labeled even next to messages by its owner', () => {
    const { context, element } = fixture();
    const container = element('messages');
    context.document.createRange = () => ({ createContextualFragment: (markup) => markup });
    context.MESSAGE_SCROLL_BOTTOM_THRESHOLD_PX = 72;
    context.getProfileUrlForUser = () => '/u/0000-0000-0000-0007';
    context.renderAvatarMarkup = () => '<span>Member avatar</span>';
    context.renderMessageAttachments = () => '';
    context.uiText = (key, { name } = {}) => ({ bot: 'Bot', bot_name: `Bot: ${name}` })[key] ?? key;
    const owner = { user_id: 7, user_number: '0000000000000007', username: 'member', content: 'Fixture message' };

    context.renderMessages([
        { ...owner, id: 1 },
        { ...owner, id: 2, username: '<b>member</b>', bot_name: '<b>member</b>' },
        { ...owner, id: 3 }
    ], { scrollMode: 'bottom' });

    const [markup] = container.children;
    const bot = markup.split('data-message-id="2"')[1].split('data-message-id="3"')[0];
    assert.match(bot, /data-bot-badge[^>]*>Bot<\/span>/);
    assert.match(bot, /&lt;b&gt;member&lt;\/b&gt;/);
    assert.doesNotMatch(bot, /href="\/u\/|Member avatar|<b>member<\/b>/);
    assert.equal((markup.match(/href="\/u\//g) || []).length, 2);
});

test('combined system events use singular and plural labels in the selected language', () => {
    const { context, element } = fixture({
        labels: {
            system_events_show_one: 'Afficher {count} autre événement',
            system_events_show_other: 'Afficher {count} autres événements',
            system_events_hide_one: 'Masquer {count} événement',
            system_events_hide_other: 'Masquer {count} événements',
        },
        language: 'fr',
    });
    const container = element('messages');
    context.document.createRange = () => ({ createContextualFragment: (markup) => markup });
    context.MESSAGE_SCROLL_BOTTOM_THRESHOLD_PX = 72;
    const event = (id) => ({ id, is_system_event: true, event_type: 'group_created', content: 'Événement' });
    const events = [event(1), event(2), event(3), event(4)];

    context.renderMessages(events.slice(0, 3), { scrollMode: 'bottom' });
    assert.match(container.children[0], /Afficher 1 autre événement/);
    context.renderMessages(events, { scrollMode: 'bottom' });
    assert.match(container.children[0], /Afficher 2 autres événements/);

    const groupKey = context.buildSystemEventGroupKey(1, events);
    context.setSystemEventGroupExpanded(1, groupKey, true);
    context.renderMessages(events, { scrollMode: 'bottom' });
    assert.match(container.children[0], /Masquer 2 événements/);
});

test('an attachment response from a previous conversation leaves the current draft alone', async () => {
    const { context } = fixture();
    const originatingChat = context.currentChat;
    context.FormData = FormData;
    context.getCsrfToken = () => 'fixture-csrf';
    context.pendingAttachments = [];
    context.normalizeAttachment = (attachment) => attachment;
    context.renderPendingAttachmentList = () => {};
    let resolveUpload;
    context.fetch = () => new Promise((resolve) => { resolveUpload = resolve; });

    const sourceInput = { isConnected: true, closest: () => ({ dataset: { chatId: '1' } }) };
    const upload = context.uploadAttachmentFile(new Blob(['fixture']), originatingChat, sourceInput);
    context.currentChat = { id: 8, type: 'group', is_group_member: true };
    resolveUpload({ ok: true, text: async () => JSON.stringify({ success: true, attachment: { id: 13 } }) });
    await upload;

    assert.deepEqual(context.pendingAttachments, []);
});

test('leaving the conversation view ignores the pending attachment response', async () => {
    const { context } = fixture();
    const sourceInput = { isConnected: true, closest: () => ({ dataset: { chatId: '1' } }) };
    context.FormData = FormData;
    context.getCsrfToken = () => 'fixture-csrf';
    context.pendingAttachments = [];
    context.normalizeAttachment = (attachment) => attachment;
    context.renderPendingAttachmentList = () => {};
    let resolveUpload;
    context.fetch = () => new Promise((resolve) => { resolveUpload = resolve; });

    const upload = context.uploadAttachmentFile(new Blob(['fixture']), context.currentChat, sourceInput);
    sourceInput.isConnected = false;
    resolveUpload({ ok: true, text: async () => JSON.stringify({ success: true, attachment: { id: 13 } }) });
    await upload;

    assert.deepEqual(context.pendingAttachments, []);
});

test('an active call in another conversation cannot redirect an attachment', async () => {
    const { context } = fixture();
    context.FormData = FormData;
    context.getCsrfToken = () => 'fixture-csrf';
    context.pendingAttachments = [];
    context.normalizeAttachment = (attachment) => attachment;
    context.renderPendingAttachmentList = () => {};
    const sourceInput = { isConnected: true, closest: () => ({ dataset: { chatId: '8' } }) };
    let requests = 0;
    context.fetch = async () => {
        requests++;
        return { ok: true, text: async () => JSON.stringify({ success: true, attachment: { id: 13 } }) };
    };
    await context.uploadAttachmentFile(new Blob(['fixture']), context.currentChat, sourceInput);
    assert.equal(requests, 0);
});

test('poll labels and tooltips are literal and malformed values cannot enable actions', () => {
    const { context, element } = fixture();
    const labels = ['<mark>Thé</mark>', 'Café & lait'];
    const poll = {
        id: 12,
        chat_id: 1,
        question: 'Thé < 40° ?',
        status: 'active',
        creator_username: 'tester',
        options: labels.map((option_text, index) => ({ id: index + 1, option_text, voters: ['<b>peer</b>'] })),
    };
    context.renderActivePollBanner(poll);
    assert.equal(element('active-poll-question').textContent, poll.question);
    const [first, second] = element('active-poll-options').children;
    assert.equal(first.children[0].children[0].textContent, labels[0]);
    assert.equal(first.title, '<b>peer</b>');
    assert.equal(second.disabled, false);

    assert.doesNotThrow(() => context.renderActivePollBanner({
        ...poll,
        total_votes: { toString: null },
        options: [{ id: { toString: null }, option_text: 'Invalid option' }, poll.options[0]],
    }));
    assert.equal(element('active-poll-banner').dataset.activePollId, '0');
    assert.equal(element('active-poll-options').children.length, 0);
    assert.equal(element('active-poll-expire').disabled, true);
});

test('live poll metadata and accessible option labels use the selected language', () => {
    const { context, element } = fixture({
        language: 'fr',
        labels: {
            poll_expires_at: 'Se termine le {time}',
            poll_meta: '{expiry} · {votes} · par {creator}',
            vote_count_one: '{count} vote',
            vote_count_other: '{count} votes',
            option_vote_label: '{option} ({votes})',
            no_votes: 'Aucun vote pour le moment',
        }
    });
    const poll = {
        id: 12,
        status: 'active',
        question: 'Thé < 40° ?',
        creator_username: 'Porte-parole {votes}',
        expires_at: '15 novembre',
        total_votes: 2,
        options: [
            { id: 1, option_text: '<b>Café</b> {votes}', vote_count: 0 },
            { id: 2, option_text: 'Thé', vote_count: 2 },
        ],
    };
    context.renderActivePollBanner(poll);
    const [first, second] = element('active-poll-options').children;
    assert.equal(element('active-poll-meta').textContent, 'Se termine le 15 novembre · 2 votes · par Porte-parole {votes}');
    assert.equal(first['aria-label'], '<b>Café</b> {votes} (0 vote)');
    assert.equal(first.title, 'Aucun vote pour le moment');
    assert.equal(second['aria-label'], 'Thé (2 votes)');
});

test('the poll banner accepts ten options and rejects an oversized list', () => {
    const { context, element } = fixture();
    const options = Array.from({ length: 11 }, (_, index) => ({ id: index + 1, option_text: `Option ${index + 1}` }));
    const poll = { id: 10, status: 'active', question: 'Many options' };

    context.renderActivePollBanner({ ...poll, options: options.slice(0, 10) });
    assert.equal(element('active-poll-options').children.length, 10);
    context.renderActivePollBanner({ ...poll, options });
    assert.equal(element('active-poll-options').children.length, 0);
    assert.equal(element('active-poll-expire').disabled, true);
});

test('a completed refresh cannot update a conversation opened after it started', async () => {
    const { context } = fixture();
    const updated = [];
    context.renderActivePollBanner = (value) => updated.push(value);
    context.renderPinnedMessageBanner = (value) => updated.push(value);
    context.renderMessages = () => {};
    context.updatePersonalChatHeaderStatusFromMessages = () => {};
    context.renderTypingIndicator = () => {};
    context.refreshChatCallStatusBar = () => {};
    let resolveRequest;
    context.fetch = () => new Promise((resolve) => { resolveRequest = resolve; });
    const refresh = context.pollMessages();
    context.currentChat = { id: 2, type: 'group', is_group_member: true };
    resolveRequest({ ok: true, json: async () => ({ messages: [], pinned_message: { id: 4, chat_id: 1 } }) });
    await refresh;
    assert.deepEqual(updated, []);
    assert.equal(context.currentChat.pinned_message, undefined);
});

test('background refreshes pass the rendered timeline revision and still apply live typing', async () => {
    const { context, element } = fixture();
    const revision = 'a'.repeat(64);
    const changed = 'b'.repeat(64);
    const rendered = [];
    const typing = [];
    let editing = false;
    element('messages').querySelector = () => editing ? {} : null;
    context.renderMessages = (messages) => rendered.push(messages[0].content);
    context.renderPinnedMessageBanner = () => {};
    context.renderActivePollBanner = () => {};
    context.updatePersonalChatHeaderStatusFromMessages = () => {};
    context.renderTypingIndicator = (users) => typing.push(users[0]?.username);
    context.refreshChatCallStatusBar = () => {};
    const responses = [
        [undefined, { timeline_revision: revision, messages: [{ id: 8, content: 'First text' }] }],
        [revision, { timeline_revision: revision, typing_users: [{ username: 'Lina' }] }],
        [revision, { timeline_revision: changed, messages: [{ id: 8, content: 'Changed text' }] }],
        [revision, { timeline_revision: changed, messages: [{ id: 8, content: 'Changed text' }] }],
        [undefined, { timeline_revision: changed, messages: [{ id: 8, content: 'Changed text' }] }],
    ];
    context.fetch = async (path, options) => {
        assert.equal(path, '/api/messages/1');
        const [expectedRevision, data] = responses.shift();
        assert.equal(options?.headers?.['X-Causette-Timeline'], expectedRevision);
        return { ok: true, json: async () => data };
    };

    await context.pollMessages({ background: true });
    await context.pollMessages({ background: true });
    assert.deepEqual(typing, [undefined, 'Lina']);
    assert.deepEqual(rendered, ['First text']);
    editing = true;
    await context.pollMessages({ background: true });
    assert.deepEqual(rendered, ['First text']);
    editing = false;
    await context.pollMessages({ background: true });
    await context.pollMessages({ forceRender: true });
    assert.deepEqual(rendered, ['First text', 'Changed text', 'Changed text']);
    assert.equal(responses.length, 0);
});

test('an incoming message does not discard an active edit', async () => {
    const { context, element } = fixture();
    let editing = true;
    const rendered = [];
    element('messages').querySelector = (selector) => selector === '.js-edit-input' && editing ? {} : null;
    context.fetch = async () => ({ ok: true, json: async () => ({ messages: [{ id: 4, content: 'New message' }] }) });
    context.renderMessages = (messages) => rendered.push(messages[0].id);
    context.renderPinnedMessageBanner = () => {};
    context.renderActivePollBanner = () => {};
    context.updatePersonalChatHeaderStatusFromMessages = () => {};
    context.renderTypingIndicator = () => {};
    context.refreshChatCallStatusBar = () => {};

    await context.pollMessages();
    assert.deepEqual(rendered, []);
    editing = false;
    await context.pollMessages();
    assert.deepEqual(rendered, [4]);
});

test('a successful edit closes the editor before refreshing the message', async () => {
    const { context } = fixture();
    const input = { value: ' Nouveau texte ', isConnected: true };
    const body = { dataset: { originalContent: '' } };
    context.document.querySelector = () => ({
        querySelector: (selector) => selector === '.js-edit-input' ? input : body,
    });
    context.getCsrfToken = () => 'fixture';
    context.postForm = async (path, payload) => {
        assert.equal(path, '/api/messages/edit');
        assert.equal(payload.message_id, 4);
        assert.equal(payload.content, 'Nouveau texte');
        assert.equal(payload.csrf_token, 'fixture');
        return { success: true };
    };
    let refreshed = false;
    context.pollMessages = async () => {
        assert.equal(body.dataset.originalContent, undefined);
        refreshed = true;
    };
    await context.submitEditMessage(4);
    assert.equal(refreshed, true);
});

test('a failed edit preserves the draft and allows one retry at a time', async () => {
    const { context } = fixture();
    let focused = false;
    const input = { value: 'Nouvelle phrase', isConnected: true, focus() { focused = true; } };
    const body = { dataset: { originalContent: 'Old text' } };
    context.document.querySelector = () => ({
        querySelector: (selector) => selector === '.js-edit-input' ? input : body,
    });
    context.getCsrfToken = () => 'fixture';
    context.uiText = () => 'Impossible d’enregistrer';
    const errors = [];
    context.showToast = (message) => errors.push(message);
    context.pollMessages = () => assert.fail('A failed edit must keep the editor open');
    for (const failure of [
        () => { throw new Error('Offline'); },
        () => ({ error: 'Le délai est dépassé' }),
        () => ({ success: false }),
        () => ({ success: true, raw: '<p>Sign-in page</p>' }),
    ]) {
        let resolveRequest;
        const network = new Promise((resolve) => { resolveRequest = resolve; });
        let requests = 0;
        context.postForm = async () => {
            requests++;
            await network;
            return failure();
        };
        const pending = context.submitEditMessage(4);
        const readOnlyWhileSaving = input.readOnly;
        const duplicate = context.submitEditMessage(4);
        resolveRequest();
        await Promise.all([pending, duplicate]);
        assert.equal(body.dataset.originalContent, 'Old text');
        assert.equal(input.value, 'Nouvelle phrase');
        assert.equal(readOnlyWhileSaving, true);
        assert.equal(input.readOnly, false);
        assert.equal(requests, 1);
        assert.equal(focused, true);
    }
    assert.deepEqual(errors, ['Impossible d’enregistrer', 'Le délai est dépassé', 'Impossible d’enregistrer', 'Impossible d’enregistrer']);
});
