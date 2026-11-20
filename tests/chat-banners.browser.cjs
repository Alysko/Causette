const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const origin = 'https://fixture.example.test:9443';
const firstChat = '/c/1010-2020-3030-4040';
const secondChat = '/c/1010-2020-3030-4041';
const question = 'Thé <mark>chaud</mark> & café ?';
const labels = ['<mark>Thé</mark>', 'Café & lait'];
const savedQuestion = 'Sondage sauvegardé <mark>Thé</mark> ?';
const adminPassword = 'fixture-only-password';
const operatorToken = 'fixture-only-operator-token-2026-fixture-only-operator-token';

async function newPage(browser, errors, externalRequests = null) {
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        locale: 'en-US',
        serviceWorkers: 'block',
    });
    // The test server is reachable over loopback even when Chromium sees no network interface.
    await context.addInitScript(() => Object.defineProperty(navigator, 'onLine', { get: () => true }));
    await context.route('**/*', (route) => {
        const url = new URL(route.request().url());
        if (url.origin === origin) return route.continue();
        if (externalRequests) {
            externalRequests.push(url.origin);
            return route.abort();
        }
        if (url.origin === 'https://cdn.tailwindcss.com') {
            return route.fulfill({
                contentType: 'application/javascript',
                body: 'window.tailwind = {}; const css = document.createElement("style");'
                    + 'css.textContent = ".hidden{display:none!important}"; document.head.append(css);',
            });
        }
        return route.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', (error) => {
        errors.push(error.message);
        console.error(error.stack);
    });
    page.setDefaultTimeout(18000);
    return page;
}

async function submitForm(page, path, button) {
    const [response] = await Promise.all([
        page.waitForResponse((result) => {
            return new URL(result.url()).pathname === path && result.request().method() === 'POST';
        }),
        button.click(),
    ]);
    return response.status();
}

async function installPackage(browser, errors) {
    const english = await newPage(browser, errors);
    const setupPath = '/install';
    const installerForm = (page, stage) => page.locator(`form:has(input[name="stage"][value="${stage}"])`);
    const submitSetup = (page, stage) => submitForm(page, setupPath, installerForm(page, stage).locator('button[type=submit]'));

    assert.equal((await english.goto(origin + setupPath + '?lang=en')).status(), 200);
    assert.equal(await english.locator('html').getAttribute('lang'), 'en');
    assert.equal(await english.getByRole('heading', { name: 'Causette setup' }).count(), 1);
    assert.equal(await installerForm(english, 'install').count(), 0);
    await english.locator('#bootstrap-token').fill('fixture-invalid-operator-token');
    assert.equal(await submitSetup(english, 'authorize'), 403);
    assert.match(await english.getByRole('alert').textContent(), /The operator token was not accepted/);
    assert.equal(await installerForm(english, 'install').count(), 0);

    const french = await newPage(browser, errors);
    assert.equal((await french.goto(origin + setupPath + '?lang=fr')).status(), 200);
    assert.equal(await french.locator('html').getAttribute('lang'), 'fr');
    assert.equal(await french.getByRole('heading', { name: 'Installation de Causette' }).count(), 1);
    await french.getByRole('link', { name: 'English' }).click();
    assert.equal(await french.locator('html').getAttribute('lang'), 'en');
    await french.getByRole('link', { name: 'Français' }).click();
    assert.equal(await french.locator('html').getAttribute('lang'), 'fr');
    await french.getByLabel('Jeton d’installation').fill('fixture-invalid-operator-token');
    assert.equal(await submitSetup(french, 'authorize'), 403);
    const denial = await french.getByRole('alert').textContent();
    assert.match(denial, /Le jeton d’installation n’a pas été accepté/);
    assert.equal(await french.locator('html').getAttribute('lang'), 'fr');
    assert.match(denial, /Vérifiez les informations ci-dessous/);

    await french.getByLabel('Jeton d’installation').fill(operatorToken);
    assert.equal(await submitSetup(french, 'authorize'), 200);
    const fillAdmin = async (username) => {
        await french.getByLabel('Nom d’utilisateur de l’administrateur').fill(username);
        await french.locator('#admin-email').fill('admin@example.test');
        await french.locator('#admin-password').fill(adminPassword);
        assert.equal(await french.getByRole('button', { name: 'Installer Causette' }).count(), 1);
    };
    await fillAdmin('fixture_admin');
    await installerForm(french, 'install').evaluate((form) => { form.noValidate = true; });
    assert.equal(await submitSetup(french, 'install'), 422);
    assert.match(await french.getByRole('alert').textContent(), /Le nom d’utilisateur doit contenir/);
    assert.equal(await french.locator('#admin-username').getAttribute('aria-invalid'), 'true');
    await fillAdmin('fixtureadmin');
    assert.equal(await submitSetup(french, 'install'), 200);
    await french.getByText('Causette est installé.', { exact: false }).waitFor();
    assert.equal(await french.locator('html').getAttribute('lang'), 'fr');
    assert.match(await french.locator('main').textContent(), /MariaDB/);
    await french.getByRole('link', { name: 'Se connecter' }).click();
    assert.equal(await french.locator('html').getAttribute('lang'), 'fr');
    await french.getByRole('button', { name: 'Se connecter' }).waitFor();
    console.log('The packaged browser installer rejected invalid authorization and completed French setup over HTTPS.');

    assert.equal((await english.goto(origin + setupPath)).status(), 404);
    assert.equal(await english.locator('form').count(), 0);
    for (const path of ['/storage/f01-fixture.txt', '/app/install/schema-v1.sql']) {
        const response = await english.goto(origin + path);
        assert.ok([403, 404].includes(response.status()), path);
        assert.doesNotMatch(await response.text(), /fixture-private-storage-canary|CREATE TABLE/i);
    }
    console.log('The completed installer, private storage canary and packaged schema were denied over HTTPS.');
}

async function login(browser, username, password, errors, language) {
    const page = await newPage(browser, errors);
    await page.goto(origin + '/login?lang=' + language);
    await page.locator('input[name=identifier]').fill(username);
    await page.locator('input[name=password]').fill(password);
    assert.equal(await submitForm(page, '/login', page.locator('form:has(input[name="identifier"]) button[type=submit]')), 302);
    await page.goto(origin + firstChat);
    await page.locator('#chat-view[data-chat-id="8"]').waitFor();
    assert.equal(await page.locator('html').getAttribute('lang'), language);
    return page;
}

async function waitForText(page, selector, expected) {
    await page.waitForFunction(({ selector, expected }) => document.querySelector(selector)?.textContent.includes(expected),
        { selector, expected });
}

async function post(page, path, action) {
    const [response] = await Promise.all([
        page.waitForResponse((response) => response.url() === origin + path && response.request().method() === 'POST'),
        action(),
    ]);
    assert.equal(response.status(), 200, path);
    assert.equal((await response.json()).success, true, path);
}

async function checkSavedPoll(owner, member) {
    await Promise.all([owner, member].map(async (page) => {
        assert.equal((await page.goto(origin + secondChat)).status(), 200);
        await waitForText(page, '#pinned-message-content', 'Other room');
        await waitForText(page, '#active-poll-question', savedQuestion);
        assert.equal(await page.locator('#active-poll-banner').getAttribute('data-active-poll-id'), '71');
        assert.equal(await page.locator('#active-poll-banner mark').count(), 0);
        assert.equal(await page.locator('#active-poll-question').textContent(), savedQuestion);
        await waitForText(page, '#active-poll-meta', '1 vote');
        const optionText = page.locator('#active-poll-options [data-poll-vote-option-id="171"]');
        assert.equal(await optionText.getAttribute('title'), 'fixturepeer');
        assert.equal(await optionText.getAttribute('aria-label'), labels[0] + ' (1 vote)');
        assert.equal(await optionText.isDisabled(), page === member);
    }));
    const vote = await member.evaluate(async () => {
        const response = await fetch('/api/messages/9');
        if (!response.ok) throw new Error('Could not read the saved poll');
        const { active_poll: poll } = await response.json();
        return { choice: Number(poll.user_voted_option_id), count: Number(poll.total_votes) };
    });
    assert.deepEqual(vote, { choice: 171, count: 1 });
    await Promise.all([owner, member].map(async (page) => {
        assert.equal((await page.goto(origin + firstChat)).status(), 200);
        await page.locator('#chat-view[data-chat-id="8"]').waitFor();
    }));
    console.log('The existing poll, voter selection and tally survived and rendered in both languages.');
}

async function checkLocalization(browser, errors) {
    const owner = await login(browser, 'fixtureadmin', adminPassword, errors, 'fr');
    const voter = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, 'en');
    await owner.goto(origin + secondChat);
    await voter.goto(origin + secondChat);
    await owner.locator('#chat-header-menu-toggle').click();
    await owner.locator('[data-chat-action="create-poll"]').click();
    await owner.locator('#create-poll-question').fill('Pause café ou thé ?');
    await owner.locator('#create-poll-option-1').fill('Café & lait');
    await owner.locator('#create-poll-option-2').fill('Thé & citron');
    await post(owner, '/api/chats/group/poll/create', () => owner.locator('#create-poll-submit').click());
    const option = voter.locator('#active-poll-options button').first();
    await post(voter, '/api/chats/group/poll/vote', () => option.click());

    for (const [page, language] of [[owner, 'fr'], [voter, 'en']]) {
        await waitForText(page, '#active-poll-meta', '1 vote');
        const poll = await page.locator('#active-poll-meta').textContent();
        if (language === 'fr') {
            assert.match(poll, /^Se termine le \d{1,2} \p{L}/u, 'The poll expiry should use a French date');
            assert.match(poll, / · 1 vote · par fixtureadmin$/);
        } else {
            assert.match(poll, /^Expires \p{L}+ \d{1,2},/u);
            assert.match(poll, / · 1 vote · by fixtureadmin$/);
        }
        assert.equal(await page.locator('#active-poll-options button').first().getAttribute('aria-label'),
            'Café & lait (1 vote)');
        await page.reload();
        await waitForText(page, '#active-poll-meta', poll);

        // A newly installed page must not load a cached script from the preceding version.
        const script = await page.locator('script[src*="/assets/js/chat.js"]').getAttribute('src');
        assert.ok(new URL(script, origin).searchParams.get('v'), 'The chat script must have a cache version');

        await page.goto(origin + '/');
        await page.getByRole('heading', { name: language === 'fr' ? 'Tableau de bord' : 'Dashboard', exact: true }).waitFor();
        await page.goto(origin + '/settings');
        await page.getByRole('heading', { name: language === 'fr' ? 'Paramètres' : 'Settings', exact: true }).waitFor();
        await page.goto(origin + '/apikeys/docs');
        await page.getByRole('heading', {
            name: language === 'fr' ? 'Documentation de l’API des robots' : 'Bot API Documentation',
            exact: true,
        }).waitFor();
        await page.context().close();
    }
    const page = await newPage(browser, errors);
    for (const [path, title] of [
        ['/forgot-password', 'Mot de passe oublié ?'],
        ['/register', 'Créer un compte'],
    ]) {
        assert.equal((await page.goto(origin + path + '?lang=fr')).status(), 200);
        await page.getByRole('heading', { name: title, exact: true }).waitFor();
    }
    console.log('Both languages render localized poll expiry and one vote, and French applies to the dashboard and account forms.');
}

async function checkLocalAssets(browser, errors) {
    const externalRequests = [];
    const page = await newPage(browser, errors, externalRequests);
    await page.goto(origin + '/login?lang=en');
    await page.locator('input[name=identifier]').fill('fixtureadmin');
    await page.locator('input[name=password]').fill(adminPassword);
    assert.equal(await submitForm(page, '/login', page.locator('form:has(input[name="identifier"]) button[type=submit]')), 302);
    await page.getByRole('heading', { name: 'Dashboard', exact: true }).waitFor();
    await page.evaluate(() => document.fonts.ready);
    assert.deepEqual([...new Set(externalRequests)], [], 'Ordinary layouts must not request external assets');
    const visuals = await page.evaluate(() => {
        const hidden = document.querySelector('#server-connection-overlay');
        const icon = document.querySelector('main .fa-solid');
        return {
            hidden: getComputedStyle(hidden).display,
            overlayClass: hidden.className,
            overlayAria: hidden.getAttribute('aria-hidden'),
            localStylesheets: Array.from(document.styleSheets, (sheet) => ({
                name: sheet.href ? new URL(sheet.href).pathname : 'inline',
                rules: sheet.cssRules.length,
            })),
            icon: icon ? getComputedStyle(icon, '::before').content : 'none',
            fontLoaded: Array.from(document.fonts).some((font) =>
                font.status === 'loaded' && font.family.includes('Font Awesome')),
        };
    });
    assert.equal(visuals.hidden, 'none',
        'The compiled utility styles hide the inactive overlay: ' + JSON.stringify(visuals));
    assert.notEqual(visuals.icon, 'none', 'A local icon font supplies the dashboard icon');
    assert.equal(visuals.fontLoaded, true, 'The Font Awesome web font loaded');
    console.log('The login and dashboard use local CSS and icons with all external requests blocked.');
}

async function checkReactions(browser, errors) {
    const page = await login(browser, 'fixtureadmin', adminPassword, errors, 'fr');
    const message = page.locator('#messages [data-message-id="50"]').first();
    const messageBadge = code => message.locator(`.js-reaction-badge[data-reaction-code="${code}"]`);
    await messageBadge('1F44D').waitFor();
    await message.hover();
    await message.locator('.js-react-link').click();
    const options = message.locator('.js-reaction-option');
    assert.equal(await options.count(), 21, 'The message reaction picker should offer all 21 choices');
    const party = options.filter({ has: page.locator('img[src$="/emojis/1F389.svg"]') });
    assert.equal(await party.getAttribute('aria-label'), 'Célébrer');
    assert.equal(await page.evaluate(async () => (await fetch('/emojis/1F389.svg')).status), 200);
    await post(page, '/api/messages/react', () => party.click());
    await messageBadge('1F389').waitFor();
    await page.reload();
    await messageBadge('1F389').waitFor();
    await messageBadge('1F44D').waitFor();
    await post(page, '/api/messages/react', () => messageBadge('1F389').click());
    await messageBadge('1F389').waitFor({ state: 'hidden' });

    await page.goto(origin + '/posts?scope=server');
    const postBadge = code => page.locator(`.js-profile-post-reaction-badge[data-post-id="50"][data-reaction-code="${code}"]`);
    await postBadge('2665').waitFor();
    await page.locator('.js-profile-post-react-link[data-post-id="50"]').click();
    const postOptions = page.locator('.js-profile-post-reaction-option[data-post-id="50"]');
    assert.equal(await postOptions.count(), 21);
    await post(page, '/api/posts/react', () => postOptions.and(page.locator('[data-reaction-code="1F525"]')).click());
    await postBadge('1F525').waitFor();
    await page.goto(origin + '/u/0111-2222-3333-4444');
    await postBadge('1F525').waitFor();
    await postBadge('2665').waitFor();
    const recipient = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, 'en');
    const notifications = await recipient.evaluate(async () => {
        const response = await fetch('/notifications', { headers: { Accept: 'application/json' } });
        return (await response.json()).notifications;
    });
    assert.ok(notifications.some(item => item.message.includes('🔥 Fire')));
    console.log('Both reaction pickers offer 21 local emoji choices. Existing reactions remain visible after voting and reload.');
}

async function run() {
    const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP fixture.example.test 127.0.0.1'] });
    const errors = [];
    try {
        if (process.argv.includes('--install')) {
            console.log(`Chromium ${browser.version()}`);
            await installPackage(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--localization')) {
            await checkLocalization(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--local-assets')) {
            await checkLocalAssets(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--reactions')) {
            await checkReactions(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        const memberLanguage = process.argv.includes('--french-member') ? 'fr' : 'en';
        const owner = await login(browser, 'fixtureadmin', adminPassword, errors, 'en');
        const member = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, memberLanguage);
        if (process.argv.includes('--preserved-poll')) await checkSavedPoll(owner, member);
        await waitForText(owner, '#pinned-message-content', '<mark>Thé</mark> &');
        assert.equal(await owner.locator('#pinned-message-content mark').count(), 0);
        assert.equal(await owner.locator('#pinned-message-content a').first().getAttribute('href'), 'https://example.test/tea');
        assert.equal(await owner.locator('#pinned-message-content a').first().getAttribute('rel'), 'noopener noreferrer');
        assert.match(await owner.locator('#pinned-message-content a').last().getAttribute('href'), /\/u\/0111-2222-3333-4444$/);
        assert.equal(await owner.locator('#pinned-message-content img[alt="☕"]').getAttribute('src'), '/emojis/2615.svg');
        const emoji = await owner.evaluate(async () => {
            const response = await fetch('/emojis/2615.svg');
            return { status: response.status, svg: (await response.text()).includes('<svg') };
        });
        assert.deepEqual(emoji, { status: 200, svg: true });
        assert.equal(await owner.locator('#pinned-message-content br').count(), 1);
        assert.equal(await member.locator('#pinned-message-unpin').isHidden(), true);
        if (memberLanguage === 'fr') {
            assert.equal(await member.locator('#pinned-message-banner').getByText('Message épinglé', { exact: true }).count(), 1);
            assert.equal(await member.locator('#pinned-message-goto').getAttribute('aria-label'), 'Aller au message');
        }
        await owner.locator('#pinned-message-banner > .group').hover();
        await owner.locator('#pinned-message-goto').click();
        assert.match(await owner.locator('#messages [data-message-id="50"]').first().getAttribute('style'), /background-color/);
        console.log('Initial pin preserved literal text, links, mention, line break, emoji and navigation.');

        await owner.locator('#messages [data-message-id="52"]').first().hover();
        await owner.locator('[data-pin-message-id="52"]').click();
        await post(owner, '/api/messages/pin', () => owner.locator('#pin-replace-submit').click());
        await waitForText(owner, '#pinned-message-content', 'Replacement <mark>note</mark>');
        await waitForText(member, '#pinned-message-content', 'Replacement <mark>note</mark>');
        assert.equal(await member.locator('#pinned-message-content mark').count(), 0);
        console.log('Pin replacement propagated through polling to the other account.');

        await owner.locator('#chat-header-menu-toggle').click();
        await owner.locator('[data-chat-action="create-poll"]').click();
        await owner.locator('#create-poll-question').fill(question);
        await owner.locator('#create-poll-option-1').fill(labels[0]);
        await owner.locator('#create-poll-option-2').fill(labels[1]);
        await post(owner, '/api/chats/group/poll/create', () => owner.locator('#create-poll-submit').click());
        await waitForText(owner, '#active-poll-question', question);
        await waitForText(member, '#active-poll-question', question);
        assert.equal(await member.locator('#active-poll-banner mark').count(), 0);

        await owner.reload();
        await waitForText(owner, '#active-poll-question', question);
        const vote = member.locator('#active-poll-options button').first();
        assert.equal(await vote.getAttribute('aria-label'), labels[0] + (memberLanguage === 'fr' ? ' (0 vote)' : ' (0 votes)'));
        if (memberLanguage === 'fr') {
            await waitForText(member, '#active-poll-banner', 'Sondage en cours');
            await waitForText(member, '#active-poll-meta', 'Se termine le');
        }
        assert.equal(await member.locator('#active-poll-expire').isHidden(), true);
        await post(member, '/api/chats/group/poll/vote', () => vote.click());
        await member.waitForFunction(() => document.querySelector('#active-poll-options button').disabled);
        await waitForText(owner, '#active-poll-meta', '1 vote');
        assert.equal(await owner.locator('#active-poll-options button').first().getAttribute('title'), 'fixturepeer');
        await post(owner, '/api/chats/group/poll/expire', () => owner.locator('#active-poll-expire').click());
        await owner.locator('#active-poll-banner').waitFor({ state: 'hidden' });
        await member.locator('#active-poll-banner').waitFor({ state: 'hidden' });
        assert.equal(await member.locator('#active-poll-options button').count(), 0);
        console.log('Poll creation, reload, member vote, result refresh, and expiry worked across both accounts.');

        let reportSnapshot;
        const snapshot = new Promise((resolve) => { reportSnapshot = resolve; });
        let releaseSnapshot;
        const hold = new Promise((resolve) => { releaseSnapshot = resolve; });
        await owner.route(origin + '/api/messages/8', async (route) => {
            reportSnapshot();
            await hold;
            await route.continue();
        }, { times: 1 });
        try {
            await Promise.race([
                snapshot,
                owner.waitForTimeout(12000).then(() => { throw new Error('No scheduled refresh for the first chat'); }),
            ]);
            await owner.evaluate((path) => navigateApp(path), secondChat);
            await waitForText(owner, '#pinned-message-content', 'Other room');
            await owner.evaluate(() => {
                const banner = document.getElementById('pinned-message-content');
                window.previousChatDisplayed = false;
                new MutationObserver(() => {
                    if (banner.textContent.includes('Replacement')) window.previousChatDisplayed = true;
                }).observe(banner, { childList: true, subtree: true, characterData: true });
            });
            const received = owner.waitForResponse(origin + '/api/messages/8');
            releaseSnapshot();
            await (await received).finished();
            await owner.waitForResponse(origin + '/api/messages/9');
            assert.equal(await owner.evaluate(() => window.previousChatDisplayed), false);
            assert.equal((await owner.locator('#pinned-message-content').textContent()).trim(), 'Other room');
        } finally {
            releaseSnapshot();
        }
        await owner.evaluate((path) => navigateApp(path), firstChat);
        await waitForText(owner, '#pinned-message-content', 'Replacement');
        await owner.locator('#pinned-message-banner > .group').hover();
        await post(owner, '/api/messages/unpin', () => owner.locator('#pinned-message-unpin').click());
        await owner.locator('#pinned-message-banner').waitFor({ state: 'hidden' });
        await member.locator('#pinned-message-banner').waitFor({ state: 'hidden' });
        assert.equal(await member.locator('#pinned-message-content').textContent(), '');
        console.log('Navigation rejected a delayed refresh, and unpinning cleared both browsers.');
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
}

run().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
