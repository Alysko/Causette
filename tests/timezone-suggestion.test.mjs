import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createContext, runInContext } from 'node:vm';

const source = readFileSync(new URL('../public_html/assets/js/app.js', import.meta.url), 'utf8');

test('the timeline shows local calendar days and suppresses only closely spaced times on the same day', () => {
    const context = createContext({
        window: {
            USER_TIMEZONE: 'UTC+2',
            CAUSETTE_UI: { language: 'fr', dates: {
                today: 'aujourd’hui', yesterday: 'hier', weekday_1: 'lundi', weekday_4: 'jeudi',
                compact_date_time: '{date} à {time}',
            } },
        },
        document: { documentElement: { dataset: { dateFormat: 'dmy', timeFormat: '24h' } } },
    });
    runInContext(source.slice(source.indexOf('const ONE_MINUTE_IN_MS'), source.indexOf('function getUtcTimestampTargets')), context);
    const now = Date.parse('2026-11-25T12:00:00Z');
    const format = (value, previous) => context.formatTimelineTimestamp(value, previous, now);
    assert.equal(format('2026-11-25 08:15:00'), 'aujourd’hui à 10:15');
    assert.equal(format('2026-11-24 08:15:00'), 'hier à 10:15');
    assert.equal(format('2026-11-23 08:15:00'), 'lundi à 10:15');
    assert.equal(format('2026-11-19 08:15:00'), 'jeudi à 10:15');
    assert.equal(format('2026-11-18 08:15:00'), '18/11/2026 10:15');
    assert.equal(format('2026-11-25 08:16:00', '2026-11-25 08:15:00'), '');
    assert.equal(format('2026-11-25 08:16:01', '2026-11-25 08:15:00'), 'aujourd’hui à 10:16');
    assert.equal(format('2026-11-24 22:00:10', '2026-11-24 21:59:50'), 'aujourd’hui à 00:00');
});

function element({ hidden = false, attributes = {} } = {}) {
    const classes = new Set(hidden ? ['hidden'] : []);
    const handlers = new Map();

    return {
        classList: {
            add: name => classes.add(name),
            remove: name => classes.delete(name),
            contains: name => classes.has(name),
        },
        dataset: {},
        disabled: false,
        textContent: '',
        getAttribute: name => attributes[name] ?? null,
        setAttribute: (name, value) => { attributes[name] = value; },
        addEventListener: (name, handler) => handlers.set(name, handler),
        click: () => handlers.get('click')?.(),
        focus() {},
    };
}

function openSuggestion(respond) {
    const timestamps = [{ dataset: { utc: '2025-11-19 12:00:00' }, textContent: '' }];
    const modal = element({ hidden: true, attributes: { 'aria-hidden': 'true' } });
    const value = element();
    const status = element();
    const accept = element();
    const decline = element();
    const ids = new Map([
        ['timezone-suggest-modal', modal],
        ['timezone-suggest-value', value],
        ['timezone-suggest-status', status],
        ['timezone-suggest-accept', accept],
        ['timezone-suggest-decline', decline],
    ]);
    class FixtureDocument {
        readyState = 'loading';

        addEventListener() {}

        getElementById(id) { return ids.get(id) ?? null; }

        querySelector() { return null; }

        querySelectorAll(selector) { return selector === '[data-utc]' ? timestamps : []; }
    }
    class EuropeanBrowserDate extends Date {
        getTimezoneOffset() { return -60; }
    }

    const window = {
        CAUSETTE_UI: {
            language: 'en',
            labels: {
                saving_time_zone: 'Saving time zone…',
                could_not_save_the_time_zone_please_try: 'Could not save the time zone. Please try again.',
            },
        },
        CURRENT_USER_ID: 7,
        USER_TIMEZONE: 'UTC+0',
        CSRF_TOKEN: 'fixture-csrf',
        sessionStorage: { getItem: () => null, setItem() {} },
    };
    const requests = [];
    const toasts = [];
    const context = createContext({
        window,
        document: new FixtureDocument(),
        Document: FixtureDocument,
        Element: class {},
        Date: EuropeanBrowserDate,
        URLSearchParams,
        fetch: (url, options) => {
            requests.push({ url, options });
            return respond();
        },
        showToast: (message, kind) => toasts.push({ message, kind }),
    });
    // The layout loads sibling scripts before this one. Their actions are outside this fixture.
    for (const [, name] of source.matchAll(/^window\.(\w+) = \1;\r?$/gm)) {
        context[name] = () => { throw new Error(`Unexpected call to ${name}`); };
    }
    runInContext(source, context, { timeout: 1000 });
    runInContext('refreshUtcTimestamps(); bindTimezoneSuggestionModal();', context, { timeout: 1000 });

    return { window, modal, value, status, accept, decline, timestamps, requests, toasts };
}

test('an accepted time zone updates the timestamp without a reload', async () => {
    const ui = openSuggestion(async () => ({ ok: true, text: async () => '{"success":true,"timezone":"UTC+1:00"}' }));

    assert.match(ui.timestamps[0].textContent, /12:00$/);
    await ui.accept.click();

    assert.equal(ui.requests.length, 1);
    const [{ url, options }] = ui.requests;
    assert.equal(url, '/settings/timezone');
    assert.equal(options.method, 'POST');
    assert.equal(new URLSearchParams(options.body).get('csrf_token'), 'fixture-csrf');
    assert.equal(new URLSearchParams(options.body).get('timezone'), 'UTC+1:00');
    assert.equal(ui.window.USER_TIMEZONE, 'UTC+1:00');
    assert.match(ui.timestamps[0].textContent, /13:00$/);
    assert.equal(ui.modal.classList.contains('hidden'), true);
    assert.equal(ui.toasts[0].kind, 'success');
});

test('a login page response leaves the time zone unchanged with a visible retry message', async () => {
    const ui = openSuggestion(async () => ({ ok: true, text: async () => '<form>Log in</form>' }));
    await ui.accept.click();

    assert.equal(ui.window.USER_TIMEZONE, 'UTC+0');
    assert.equal(ui.modal.classList.contains('hidden'), false);
    assert.equal(ui.accept.disabled, false);
    assert.match(ui.status.textContent, /try again/i);
    assert.ok(ui.toasts.every(toast => toast.kind !== 'success'));
});

test('the dialog is accessible and displays feedback while saving and after a network failure', async () => {
    let failRequest;
    const ui = openSuggestion(() => new Promise((resolve, reject) => { failRequest = reject; }));

    assert.equal(ui.modal.getAttribute('aria-hidden'), 'false');
    assert.equal(ui.value.textContent, 'UTC+1:00');
    const saving = ui.accept.click();
    assert.equal(ui.accept.disabled, true);
    assert.equal(ui.decline.disabled, true);
    assert.match(ui.status.textContent, /saving/i);
    failRequest(new Error('Connection closed'));
    await saving;

    assert.equal(ui.window.USER_TIMEZONE, 'UTC+0');
    assert.equal(ui.accept.disabled, false);
    assert.match(ui.status.textContent, /try again/i);
});
