import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createContext, runInContext } from 'node:vm';

const source = readFileSync(new URL('../public_html/assets/js/chat.js', import.meta.url), 'utf8');

function fixture(language, response) {
    const requests = [];
    function element(...initialClasses) {
        const handlers = new Map();
        const classes = new Set(initialClasses);
        return {
            isConnected: true,
            value: '',
            innerHTML: '',
            classList: {
                contains: (key) => classes.has(key),
                toggle: (key, value) => value ? classes.add(key) : classes.delete(key),
            },
            setAttribute() {},
            focus() {},
            addEventListener(name, handler) { handlers.set(name, handler); },
            emit(name) { handlers.get(name)({ preventDefault() {} }); },
        };
    }
    const grid = element();
    const toggle = element();
    const search = element();
    const controls = new Map([
        ['emoji-grid', grid], ['emoji-toggle', toggle], ['emoji-search', search],
        ['emoji-drawer', element('hidden')], ['message-input', element()],
    ]);
    const localizedUrl = language === 'fr' ? '/community/assets/locales/emoji-fr.json' : null;
    const context = createContext({
        document: { getElementById: (id) => controls.get(id), addEventListener() {}, createElement: () => ({}) },
        window: {
            CAUSETTE_EMOJI_ANNOTATIONS_URL: localizedUrl,
            CAUSETTE_REACTION_LABELS: { 2615: 'Coffee' },
            CAUSETTE_REACTION_GROUPS: { common: ['2615'], hands: ['1F44B'] },
            CAUSETTE_EMOJI_ARTWORK: { '2615': '2615', '1F44B': '1F44B' },
            OPENMOJI_FILES: ['2615.svg', '1F44B.svg', '1F44B-1F3FB.svg', 'E001.svg', '002D.svg', '0031-FE0F-20E3.svg'],
            OPENMOJI_METADATA: {
                '2615': { annotation: 'hot beverage', tags: 'coffee,cup' },
                '1F44B': { annotation: 'waving hand' },
            },
        },
        openMojiCatalog: [], openMojiMetadataByKey: null, openMojiByKey: new Map(),
        DEFAULT_EMOJI_KEYS: ['2615', '1F44B'], EMOJI_KEYWORD_RULES: [],
        appPath: (path) => '/community' + path,
        escapeHtml: (value) => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;'),
        uiText: (key, parameters) => {
            if (key === 'emoji_catalog_fallback') return `${language === 'fr' ? 'Émoji' : 'Emoji'} ${parameters.code}`;
            if (key === 'no_emoji_found') return 'Aucun émoji trouvé';
            assert.fail(`Unknown fixture label ${key}`);
        },
        fetch: async (path) => {
            requests.push(path);
            return response;
        },
    });
    runInContext(source, context);
    context.bindEmojiDrawer();
    return { grid, toggle, search, requests, context };
}

test('the composer, conversation and reactions use one 3D set on a mounted site', () => {
    const { grid, toggle, search, context } = fixture('en');
    toggle.emit('click');
    const artwork = '/community/assets/img/emoji-3d/2615.webp';
    assert.ok(grid.innerHTML.includes(artwork));
    assert.match(grid.innerHTML, /\/community\/assets\/img\/emoji-3d\/1F44B\.webp/);
    assert.doesNotMatch(grid.innerHTML, /\/assets\/img\/reactions\/|\/emojis\//);
    assert.equal(context.getReactionImageUrlByCode('2615'), artwork);
    const elements = [];
    context.appendTextWithEmoji({ append: child => elements.push(child) }, '☕ 👋🏻 \uE001');
    assert.equal(elements[0]?.src, artwork);
    assert.equal(elements[2]?.src, '/community/emojis/1F44B-1F3FB.svg');
    assert.equal(elements[4]?.src, '/community/emojis/E001.svg', 'A legacy custom picture remains readable in history');
    assert.equal(context.getReactionImageUrlByCode('1F44B-1F3FB'), '/community/emojis/1F44B-1F3FB.svg');
    for (const value of ['skin tone', '1F44B-1F3FB', '👋🏻']) {
        search.value = value;
        search.emit('input');
        assert.doesNotMatch(grid.innerHTML, /data-emoji=/, value);
    }
});

test('historical keycap emoji retain their image and ordinary ASCII stays text', () => {
    const { context } = fixture('en');
    const elements = [];
    context.appendTextWithEmoji({ append: child => elements.push(child) }, '1 - 1️⃣');
    assert.equal(elements[0], '1 - ');
    assert.equal(elements[1]?.src, '/community/emojis/0031-FE0F-20E3.svg');
});

test('the closed composer leaves the emoji grid empty until first use', () => {
    const { grid, toggle } = fixture('en');
    assert.equal(grid.innerHTML, '');
    toggle.emit('click');
    assert.match(grid.innerHTML, /data-emoji="☕"/);
});

test('the French emoji drawer loads local names lazily and accepts unaccented and existing searches', async () => {
    const annotations = {
        '2615': { name: 'boisson chaude', keywords: ['café', 'tasse'] },
        '1F44B': { name: 'signe de la main', keywords: ['bonjour'] },
    };
    const { grid, toggle, search, requests } = fixture('fr', { ok: true, json: async () => ({ annotations }) });
    assert.deepEqual(requests, []);
    toggle.emit('click');
    await new Promise(setImmediate);
    assert.deepEqual(requests, ['/community/assets/locales/emoji-fr.json']);
    assert.match(grid.innerHTML, /aria-label="boisson chaude"/);
    assert.match(grid.innerHTML, /title="signe de la main"/);
    assert.doesNotMatch(grid.innerHTML, /Émoji E001/);
    assert.doesNotMatch(grid.innerHTML, /OpenMoji exclusive illustration/);

    for (const query of ['cafe', 'café', 'coffee', '☕', '2615']) {
        search.value = query;
        search.emit('input');
        assert.match(grid.innerHTML, /aria-label="boisson chaude"/, query);
    }
    toggle.emit('click');
    toggle.emit('click');
    await new Promise(setImmediate);
    assert.equal(requests.length, 1);
});

test('English labels and selection work without a French request', () => {
    const { grid, toggle, requests } = fixture('en');
    toggle.emit('click');
    assert.deepEqual(requests, []);
    assert.match(grid.innerHTML, /aria-label="hot beverage"/);
    assert.match(grid.innerHTML, /data-emoji="☕"/);
});

test('annotations which arrive while the drawer is closed appear when it reopens', async () => {
    let resolveAnnotations;
    const delayed = new Promise((resolve) => { resolveAnnotations = resolve; });
    const { grid, toggle, requests } = fixture('fr', { ok: true, json: () => delayed });
    toggle.emit('click');
    toggle.emit('click');
    resolveAnnotations({ annotations: { '2615': { name: 'boisson chaude', keywords: ['café'] } } });
    await new Promise(setImmediate);
    assert.doesNotMatch(grid.innerHTML, /boisson chaude/);
    toggle.emit('click');
    await new Promise(setImmediate);
    assert.match(grid.innerHTML, /aria-label="boisson chaude"/);
    assert.equal(requests.length, 1);
});

test('shipped French annotations cover the shortlist and include the Unicode notice', () => {
    const data = JSON.parse(readFileSync(new URL('../public_html/assets/locales/emoji-fr.json', import.meta.url), 'utf8'));
    assert.equal(data.locale, 'fr');
    assert.equal(data.license, 'Unicode-3.0');
    assert.match(data.sourceUrl, /4d06be52b51bb2f75688d0abe55c52a66afed790$/);
    assert.match(data.licenseNotice, /UNICODE LICENSE V3/);
    const catalog = JSON.parse(readFileSync(new URL('../public_html/assets/img/emoji-3d/catalog.json', import.meta.url), 'utf8'));
    assert.deepEqual(Object.keys(data.annotations).sort(), Object.keys(catalog).sort());
    assert.ok(data.annotations['2615'].keywords.includes('café'));
    assert.match(data.annotations['1F44B'].name, /main/);
});
