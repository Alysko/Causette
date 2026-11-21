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
        window: { CAUSETTE_CHAT_I18N: i18n, CAUSETTE_POLL_LIMITS: { minOptions: 2, maxOptions: 10 } },
        currentChat: { id: 1, type: 'group', is_group_member: true },
        currentUserId: 7,
        document: {
            getElementById: element,
            createDocumentFragment: () => node('#fragment'),
            createElement: node,
        },
        getProfileUrlByUserNumber: (number) => '/u/' + number,
        escapeHtml: (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
        formatCompactMessageTimestamp: (value) => value,
        normalizeChatType: (type) => type,
    });
    runInContext(source, context);
    context.canCurrentUserPinMessages = () => true;
    context.findOpenMojiForGrapheme = (value) => value === '☕' ? { url: '/emojis/2615.svg' } : null;
    return { context, element };
}

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
